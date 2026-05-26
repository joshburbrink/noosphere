#!/opt/noosphere-meshtastic/bin/python3
"""
noosphere-meshtastic.py - long-running bridge between a serial Meshtastic node
and the Noosphere /mesh/ module.

Reads incoming text messages + node-db updates into /var/lib/noosphere/mesh.db,
and drains /var/lib/noosphere/mesh.db `mesh_outbox` rows by sending them on
the configured channel.

Runs under systemd as `noosphere-meshtastic.service`. Restarts on radio loss.
"""

import json
import logging
import os
import signal
import sqlite3
import sys
import time
from pathlib import Path

import meshtastic
import meshtastic.serial_interface
from pubsub import pub

# ──────────────────────────────────────────────────────────────────────────────
# Config (env-overridable)
# ──────────────────────────────────────────────────────────────────────────────
SERIAL_PORT = os.environ.get("MESH_PORT", "/dev/ttyUSB0")
DB_PATH     = os.environ.get("MESH_DB",   "/var/lib/noosphere/mesh.db")
OUTBOX_POLL = float(os.environ.get("MESH_OUTBOX_POLL", "2.0"))
LOG_LEVEL   = os.environ.get("MESH_LOG", "INFO").upper()

logging.basicConfig(
    level=LOG_LEVEL,
    format="%(asctime)s %(levelname)s %(message)s",
)
log = logging.getLogger("mesh")

iface = None  # global so signal handlers can close it
_should_run = True

# ──────────────────────────────────────────────────────────────────────────────
# DB schema
# ──────────────────────────────────────────────────────────────────────────────
SCHEMA = """
CREATE TABLE IF NOT EXISTS mesh_messages (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    direction   TEXT NOT NULL,           -- 'in' | 'out'
    from_id     TEXT,                    -- '!aabbccdd'
    from_short  TEXT,
    to_id       TEXT,                    -- '!aabbccdd' or '^all'
    channel     INTEGER NOT NULL DEFAULT 0,
    body        TEXT NOT NULL,
    rssi        INTEGER,
    snr         REAL,
    hop_limit   INTEGER,
    hop_start   INTEGER,
    pkt_id      INTEGER,
    received_at INTEGER NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_msg_recv ON mesh_messages(received_at DESC);

CREATE TABLE IF NOT EXISTS mesh_nodes (
    node_id     TEXT PRIMARY KEY,        -- '!aabbccdd'
    node_num    INTEGER,
    long_name   TEXT,
    short_name  TEXT,
    hw_model    TEXT,
    role        TEXT,
    last_heard  INTEGER,
    battery_pct INTEGER,
    voltage     REAL,
    snr         REAL,
    rssi        INTEGER,
    hops_away   INTEGER,
    latitude    REAL,
    longitude   REAL,
    altitude    INTEGER,
    is_self     INTEGER DEFAULT 0,
    updated_at  INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS mesh_outbox (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    to_id       TEXT,                    -- NULL or '^all' = broadcast
    channel     INTEGER NOT NULL DEFAULT 0,
    body        TEXT NOT NULL,
    queued_by   TEXT,                    -- registry user, 'system', etc.
    queued_at   INTEGER NOT NULL,
    sent_at     INTEGER,
    error       TEXT
);
CREATE INDEX IF NOT EXISTS idx_outbox_pending ON mesh_outbox(sent_at) WHERE sent_at IS NULL;

CREATE TABLE IF NOT EXISTS mesh_state (
    key   TEXT PRIMARY KEY,
    value TEXT
);
"""

def db_open():
    Path(DB_PATH).parent.mkdir(parents=True, exist_ok=True)
    conn = sqlite3.connect(DB_PATH, timeout=5.0)
    conn.row_factory = sqlite3.Row
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("PRAGMA busy_timeout=2000")
    conn.executescript(SCHEMA)
    # Make sure www-data can write (daemon runs as root, web reads/queues as www-data)
    try:
        os.chmod(DB_PATH, 0o664)
        wal = DB_PATH + "-wal"; shm = DB_PATH + "-shm"
        for p in (wal, shm):
            if os.path.exists(p):
                os.chmod(p, 0o664)
    except OSError:
        pass
    return conn

# ──────────────────────────────────────────────────────────────────────────────
# Node DB sync
# ──────────────────────────────────────────────────────────────────────────────
def upsert_node(conn, node_dict, *, is_self=False):
    """Pull what we can out of a meshtastic node dict and upsert."""
    user = node_dict.get("user") or {}
    pos  = node_dict.get("position") or {}
    dev  = node_dict.get("deviceMetrics") or {}
    node_id = user.get("id") or node_dict.get("id")
    if not node_id:
        return
    row = {
        "node_id":     node_id,
        "node_num":    node_dict.get("num"),
        "long_name":   user.get("longName"),
        "short_name":  user.get("shortName"),
        "hw_model":    user.get("hwModel"),
        "role":        user.get("role"),
        "last_heard":  node_dict.get("lastHeard"),
        "battery_pct": dev.get("batteryLevel"),
        "voltage":     dev.get("voltage"),
        "snr":         node_dict.get("snr"),
        "rssi":        node_dict.get("rssi"),
        "hops_away":   node_dict.get("hopsAway"),
        "latitude":    pos.get("latitude"),
        "longitude":   pos.get("longitude"),
        "altitude":    pos.get("altitude"),
        "is_self":     1 if is_self else 0,
        "updated_at":  int(time.time()),
    }
    cols = ",".join(row.keys())
    placeholders = ",".join(f":{k}" for k in row.keys())
    updates = ",".join(
        f"{k}=COALESCE(excluded.{k}, mesh_nodes.{k})" for k in row.keys() if k != "node_id"
    )
    conn.execute(
        f"INSERT INTO mesh_nodes ({cols}) VALUES ({placeholders}) "
        f"ON CONFLICT(node_id) DO UPDATE SET {updates}",
        row,
    )
    conn.commit()

def sync_nodedb(conn):
    if not iface or not getattr(iface, "nodes", None):
        return
    my_num = getattr(iface, "myInfo", None)
    my_num = my_num.my_node_num if my_num else None
    for node_id, node in iface.nodes.items():
        upsert_node(conn, node, is_self=(node.get("num") == my_num))

# ──────────────────────────────────────────────────────────────────────────────
# pubsub handlers
# ──────────────────────────────────────────────────────────────────────────────
def on_connection_established(interface, topic=pub.AUTO_TOPIC):
    log.info("connected to radio: %s", interface.getMyUser().get("longName") if interface.getMyUser() else "?")
    try:
        with db_open() as conn:
            sync_nodedb(conn)
            conn.execute(
                "INSERT INTO mesh_state(key,value) VALUES('last_connect', ?) "
                "ON CONFLICT(key) DO UPDATE SET value=excluded.value",
                (str(int(time.time())),),
            )
            conn.commit()
    except Exception as e:
        log.exception("nodedb sync failed: %s", e)

def on_connection_lost(interface, topic=pub.AUTO_TOPIC):
    log.warning("connection lost; daemon will exit and systemd will restart")
    global _should_run
    _should_run = False

def on_receive(packet, interface=None):
    try:
        decoded = packet.get("decoded") or {}
        portnum = decoded.get("portnum")
        if portnum != "TEXT_MESSAGE_APP":
            # Still refresh nodedb on non-text receives (positions etc.)
            if portnum in ("POSITION_APP", "NODEINFO_APP", "TELEMETRY_APP"):
                try:
                    with db_open() as conn: sync_nodedb(conn)
                except Exception: pass
            return
        body = decoded.get("text") or ""
        with db_open() as conn:
            conn.execute(
                "INSERT INTO mesh_messages "
                "(direction, from_id, from_short, to_id, channel, body, rssi, snr, hop_limit, hop_start, pkt_id, received_at) "
                "VALUES ('in', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                (
                    packet.get("fromId"),
                    _short_name(conn, packet.get("fromId")),
                    packet.get("toId"),
                    packet.get("channel", 0) or 0,
                    body,
                    packet.get("rxRssi"),
                    packet.get("rxSnr"),
                    packet.get("hopLimit"),
                    packet.get("hopStart"),
                    packet.get("id"),
                    int(time.time()),
                ),
            )
            conn.commit()
        log.info("RX %s ch%s: %s", packet.get("fromId"), packet.get("channel", 0), body[:80])
    except Exception:
        log.exception("on_receive failed; packet=%s", json.dumps(packet, default=str)[:300])

def _short_name(conn, node_id):
    if not node_id:
        return None
    r = conn.execute("SELECT short_name FROM mesh_nodes WHERE node_id=?", (node_id,)).fetchone()
    return r["short_name"] if r else None

# ──────────────────────────────────────────────────────────────────────────────
# Outbox sender
# ──────────────────────────────────────────────────────────────────────────────
def drain_outbox(conn):
    rows = conn.execute(
        "SELECT id, to_id, channel, body FROM mesh_outbox "
        "WHERE sent_at IS NULL ORDER BY id ASC LIMIT 10"
    ).fetchall()
    for row in rows:
        try:
            kwargs = {"channelIndex": int(row["channel"] or 0)}
            if row["to_id"] and row["to_id"] not in ("^all", "all"):
                kwargs["destinationId"] = row["to_id"]
            iface.sendText(row["body"], **kwargs)
            conn.execute(
                "UPDATE mesh_outbox SET sent_at=?, error=NULL WHERE id=?",
                (int(time.time()), row["id"]),
            )
            # mirror into messages so it shows in the chat view
            conn.execute(
                "INSERT INTO mesh_messages (direction, from_id, to_id, channel, body, received_at) "
                "VALUES ('out', ?, ?, ?, ?, ?)",
                (
                    (iface.getMyUser() or {}).get("id"),
                    row["to_id"] or "^all",
                    row["channel"] or 0,
                    row["body"],
                    int(time.time()),
                ),
            )
            conn.commit()
            log.info("TX -> %s ch%s: %s", row["to_id"] or "^all", row["channel"] or 0, row["body"][:80])
        except Exception as e:
            log.exception("send failed for outbox %d", row["id"])
            conn.execute(
                "UPDATE mesh_outbox SET error=?, sent_at=? WHERE id=?",
                (str(e)[:300], int(time.time()), row["id"]),
            )
            conn.commit()

# ──────────────────────────────────────────────────────────────────────────────
# Main
# ──────────────────────────────────────────────────────────────────────────────
def _shutdown(signum, frame):
    global _should_run
    log.info("signal %d received; shutting down", signum)
    _should_run = False

def main():
    global iface
    signal.signal(signal.SIGTERM, _shutdown)
    signal.signal(signal.SIGINT,  _shutdown)

    pub.subscribe(on_connection_established, "meshtastic.connection.established")
    pub.subscribe(on_connection_lost,        "meshtastic.connection.lost")
    pub.subscribe(on_receive,                "meshtastic.receive")

    log.info("opening %s", SERIAL_PORT)
    iface = meshtastic.serial_interface.SerialInterface(SERIAL_PORT)

    last_nodedb_sync = 0
    try:
        while _should_run:
            now = time.time()
            try:
                with db_open() as conn:
                    drain_outbox(conn)
                    if now - last_nodedb_sync > 30:
                        sync_nodedb(conn)
                        last_nodedb_sync = now
            except Exception:
                log.exception("worker tick failed")
            time.sleep(OUTBOX_POLL)
    finally:
        try:
            iface.close()
        except Exception:
            pass

if __name__ == "__main__":
    sys.exit(main() or 0)
