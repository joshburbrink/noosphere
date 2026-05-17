#!/usr/bin/env python3
"""
Reads rtl_433 JSON from stdin and writes matching sensor readings to weather.db.
Respects sensor_id / sensor_model filters from /etc/noosphere/rtl433.conf.
Run as: rtl_433 -F json | noosphere-rtl433-bridge.py
"""
import sys, json, sqlite3, time

DB_PATH   = '/var/lib/noosphere/weather.db'
CONF_PATH = '/etc/noosphere/rtl433.conf'

CARDINAL = ['N','NNE','NE','ENE','E','ESE','SE','SSE','S','SSW','SW','WSW','W','WNW','NW','NNW']


def load_conf():
    conf = {'sensor_id': '', 'sensor_model': ''}
    try:
        with open(CONF_PATH) as f:
            for line in f:
                line = line.strip()
                if '=' in line and not line.startswith('#'):
                    k, v = line.split('=', 1)
                    conf[k.strip()] = v.strip().strip('"').strip("'")
    except FileNotFoundError:
        pass
    return conf


def ensure_schema(db):
    db.execute('''CREATE TABLE IF NOT EXISTS weather_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        logged_at INTEGER NOT NULL,
        temp_f REAL, conditions TEXT, wind_dir TEXT,
        wind_speed TEXT, humidity TEXT, notes TEXT, logged_by TEXT
    )''')
    try:
        db.execute('ALTER TABLE weather_log ADD COLUMN source TEXT')
    except Exception:
        pass
    db.commit()


def deg_to_cardinal(deg):
    return CARDINAL[round(float(deg) / 22.5) % 16]


for line in sys.stdin:
    line = line.strip()
    if not line:
        continue
    try:
        d = json.loads(line)
    except json.JSONDecodeError:
        continue

    conf = load_conf()

    sensor_id = str(d.get('id', ''))
    model     = str(d.get('model', ''))

    if conf['sensor_id']    and sensor_id != conf['sensor_id']:
        continue
    if conf['sensor_model'] and model.lower() != conf['sensor_model'].lower():
        continue

    # Temperature — prefer _C then _F
    temp_f = None
    if d.get('temperature_C') is not None:
        temp_f = round(float(d['temperature_C']) * 9 / 5 + 32, 1)
    elif d.get('temperature_F') is not None:
        temp_f = round(float(d['temperature_F']), 1)

    humidity   = str(int(d['humidity'])) if d.get('humidity') is not None else None
    wind_speed = None
    wind_dir   = None

    if d.get('wind_avg_km_h') is not None:
        wind_speed = str(round(float(d['wind_avg_km_h']) * 0.621371, 1)) + ' mph'
    elif d.get('wind_avg_mi_h') is not None:
        wind_speed = str(round(float(d['wind_avg_mi_h']), 1)) + ' mph'
    elif d.get('wind_speed_km_h') is not None:
        wind_speed = str(round(float(d['wind_speed_km_h']) * 0.621371, 1)) + ' mph'

    if d.get('wind_dir_deg') is not None:
        wind_dir = deg_to_cardinal(d['wind_dir_deg'])

    if temp_f is None and humidity is None:
        continue  # nothing useful decoded

    notes = f"sensor: {model} id={sensor_id}".strip()

    try:
        db = sqlite3.connect(DB_PATH, timeout=5)
        ensure_schema(db)
        db.execute(
            'INSERT INTO weather_log '
            '(logged_at,temp_f,humidity,wind_speed,wind_dir,notes,logged_by,source) '
            'VALUES (?,?,?,?,?,?,?,?)',
            (int(time.time()), temp_f, humidity, wind_speed, wind_dir, notes, 'rtl_433', 'rtl433')
        )
        db.commit()
        db.close()
    except Exception as e:
        print(f'DB error: {e}', file=sys.stderr)
