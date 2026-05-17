#!/usr/bin/env python3
"""
Reads Direwolf decoded APRS packets from its AGW/KISS TCP port (localhost:8000)
and writes position packets to /var/lib/noosphere/aprs.db.
Run as a sidecar alongside noosphere-aprs.service via a separate service unit
or from noosphere-radio-mode.sh after Direwolf starts.
"""
import socket, sqlite3, time, re, sys

DB_PATH    = '/var/lib/noosphere/aprs.db'
AGW_HOST   = '127.0.0.1'
AGW_PORT   = 8000  # Direwolf AGW port


def ensure_schema(db):
    db.execute('''CREATE TABLE IF NOT EXISTS aprs_stations (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        callsign   TEXT NOT NULL,
        lat        REAL NOT NULL,
        lng        REAL NOT NULL,
        symbol     TEXT,
        comment    TEXT,
        last_heard INTEGER NOT NULL
    )''')
    db.execute('CREATE UNIQUE INDEX IF NOT EXISTS idx_aprs_call ON aprs_stations(callsign)')
    db.commit()


def parse_lat(raw):
    """Parse APRS uncompressed latitude DDMM.HH[NS]."""
    m = re.match(r'^(\d{2})(\d{2}\.\d+)([NS])$', raw.strip())
    if not m:
        return None
    deg = int(m.group(1)) + float(m.group(2)) / 60
    if m.group(3) == 'S':
        deg = -deg
    return round(deg, 6)


def parse_lng(raw):
    """Parse APRS uncompressed longitude DDDMM.HH[EW]."""
    m = re.match(r'^(\d{3})(\d{2}\.\d+)([EW])$', raw.strip())
    if not m:
        return None
    deg = int(m.group(1)) + float(m.group(2)) / 60
    if m.group(3) == 'W':
        deg = -deg
    return round(deg, 6)


def parse_aprs(callsign, payload):
    """Return (lat, lng, symbol, comment) or None for non-position packets."""
    if len(payload) < 1:
        return None
    dti = payload[0]

    # !=/@` — position types
    if dti in ('!', '=', '@', '/'):
        # Uncompressed: !DDMM.HH[NS]xDDDMM.HH[EW]x...
        m = re.search(r'(\d{4}\.\d{2}[NS])(.)(\d{5}\.\d{2}[EW])(.)(.*)', payload[1:])
        if m:
            lat = parse_lat(m.group(1))
            lng = parse_lng(m.group(3))
            if lat is not None and lng is not None:
                return lat, lng, m.group(4), m.group(5)[:80]

    return None


def upsert(db, callsign, lat, lng, symbol, comment):
    db.execute(
        '''INSERT INTO aprs_stations (callsign,lat,lng,symbol,comment,last_heard)
           VALUES (?,?,?,?,?,?)
           ON CONFLICT(callsign) DO UPDATE SET
             lat=excluded.lat, lng=excluded.lng,
             symbol=excluded.symbol, comment=excluded.comment,
             last_heard=excluded.last_heard''',
        (callsign, lat, lng, symbol, comment, int(time.time()))
    )
    db.commit()


# AGW framing constants
AGW_HEADER = 36  # bytes

def agw_recv(sock, n):
    buf = b''
    while len(buf) < n:
        chunk = sock.recv(n - len(buf))
        if not chunk:
            raise ConnectionError('AGW disconnected')
        buf += chunk
    return buf


try:
    db = sqlite3.connect(DB_PATH, timeout=5, check_same_thread=False)
    db.execute('PRAGMA journal_mode=WAL')
    ensure_schema(db)
except Exception as e:
    print(f'DB init error: {e}', file=sys.stderr)
    sys.exit(1)

while True:
    try:
        sock = socket.create_connection((AGW_HOST, AGW_PORT), timeout=10)
        # Register for all frames
        sock.sendall(b'\x00' * 4 + b'R' + b'\x00' * 31)
        print('Connected to Direwolf AGW port', flush=True)

        while True:
            header = agw_recv(sock, AGW_HEADER)
            data_len = int.from_bytes(header[28:32], 'little')
            callsign = header[8:18].rstrip(b'\x00').decode('ascii', errors='replace').split('-')[0]
            frame_type = chr(header[4])
            payload = b''
            if data_len > 0:
                payload = agw_recv(sock, data_len)

            if frame_type == 'U' or frame_type == 'I':
                text = payload.decode('ascii', errors='replace')
                result = parse_aprs(callsign, text)
                if result:
                    lat, lng, symbol, comment = result
                    upsert(db, callsign, lat, lng, symbol, comment)

    except (ConnectionError, OSError) as e:
        print(f'AGW connection error: {e} — retrying in 10s', file=sys.stderr)
        time.sleep(10)
