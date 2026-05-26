#!/usr/bin/env python3
"""
noosphere-nwr-to-mesh.py - relay new local NWR (NOAA Weather Radio) SAME
alerts to the Meshtastic mesh as broadcast text. Runs every minute via cron.

Gated by the `mesh_nwr_relay` setting (Admin -> Network -> Meshtastic card).
RF airtime is scarce: we only relay alerts marked matched_local and post at
most one message per alert (tracked by alerts.id).

Schema notes:
- /var/lib/noosphere/weather/alerts.db has table 'alerts' with columns
  id, event, headline, matched_local, received_at (created by the NWR pipeline).
- /var/lib/noosphere/noosphere.db has key/value 'settings' table (PHP shared/settings.php).
- /var/lib/noosphere/mesh.db has mesh_outbox (queued by the bridge).
"""
import os, sqlite3, sys, time

SETTINGS_DB = "/var/lib/noosphere/noosphere.db"
ALERTS_DB   = "/var/lib/noosphere/weather/alerts.db"
MESH_DB     = "/var/lib/noosphere/mesh.db"
STATE_KEY   = "nwr_to_mesh_last_id"

def get_setting(key, default=""):
    try:
        with sqlite3.connect(SETTINGS_DB, timeout=3.0) as c:
            r = c.execute("SELECT value FROM settings WHERE key=?", (key,)).fetchone()
            return r[0] if r else default
    except Exception:
        return default

def get_state_last_id():
    try:
        with sqlite3.connect(MESH_DB, timeout=3.0) as c:
            r = c.execute("SELECT value FROM mesh_state WHERE key=?", (STATE_KEY,)).fetchone()
            return int(r[0]) if r and r[0] else 0
    except Exception:
        return 0

def set_state_last_id(n):
    with sqlite3.connect(MESH_DB, timeout=3.0) as c:
        c.execute(
            "INSERT INTO mesh_state(key,value) VALUES(?,?) "
            "ON CONFLICT(key) DO UPDATE SET value=excluded.value",
            (STATE_KEY, str(n)),
        )
        c.commit()

def enqueue(body):
    with sqlite3.connect(MESH_DB, timeout=3.0) as c:
        c.execute(
            "INSERT INTO mesh_outbox(to_id, channel, body, queued_by, queued_at) "
            "VALUES (NULL, 0, ?, 'nwr-bridge', ?)",
            (body[:200], int(time.time())),
        )
        c.commit()

def main():
    if get_setting("mesh_nwr_relay", "0") != "1":
        return 0
    if not os.path.exists(ALERTS_DB) or not os.path.exists(MESH_DB):
        return 0
    last_id = get_state_last_id()
    try:
        with sqlite3.connect(ALERTS_DB, timeout=3.0) as c:
            c.row_factory = sqlite3.Row
            rows = c.execute(
                "SELECT id, event, headline FROM alerts "
                "WHERE id > ? AND matched_local = 1 ORDER BY id ASC LIMIT 20",
                (last_id,),
            ).fetchall()
    except Exception as e:
        print(f"alerts.db read failed: {e}", file=sys.stderr)
        return 1
    max_id = last_id
    for r in rows:
        msg = f"NWR: {r['event']} - {r['headline'] or ''}".strip()
        try:
            enqueue(msg)
            max_id = max(max_id, r["id"])
        except Exception as e:
            print(f"enqueue failed for id={r['id']}: {e}", file=sys.stderr)
    if max_id > last_id:
        set_state_last_id(max_id)
    return 0

if __name__ == "__main__":
    sys.exit(main())
