#!/usr/bin/env python3
"""
Download USGS NAIP satellite tiles for Bartholomew + Brown County, IN.
Saves to /var/www/noosphere/maps/satellite.mbtiles
Run: python3 /usr/local/bin/download-satellite.py
"""
import math, sqlite3, os, time, sys, urllib.request

TILE_URL = 'https://basemap.nationalmap.gov/arcgis/rest/services/USGSImageryOnly/MapServer/tile/{z}/{y}/{x}'
OUTPUT   = '/var/www/noosphere/maps/satellite.mbtiles'

MIN_LAT, MAX_LAT = 38.92, 39.52
MIN_LON, MAX_LON = -86.60, -85.55
MIN_ZOOM, MAX_ZOOM = 10, 16

def lon2x(lon, z): return int((lon + 180) / 360 * 2**z)
def lat2y(lat, z):
    r = math.radians(lat)
    return int((1 - math.log(math.tan(r) + 1/math.cos(r)) / math.pi) / 2 * 2**z)
def tms(y, z): return (2**z - 1) - y

def main():
    db = sqlite3.connect(OUTPUT)
    db.execute('CREATE TABLE IF NOT EXISTS metadata (name TEXT PRIMARY KEY, value TEXT)')
    db.execute('CREATE TABLE IF NOT EXISTS tiles (zoom_level INT, tile_column INT, tile_row INT, tile_data BLOB, PRIMARY KEY (zoom_level, tile_column, tile_row))')
    db.execute('CREATE UNIQUE INDEX IF NOT EXISTS tile_index ON tiles (zoom_level, tile_column, tile_row)')
    for k, v in [
        ('name',        'USGS NAIP — Bartholomew & Brown County IN'),
        ('type',        'overlay'),
        ('version',     '1'),
        ('description', 'USGS NAIP aerial imagery'),
        ('format',      'jpg'),
        ('bounds',      f'{MIN_LON},{MIN_LAT},{MAX_LON},{MAX_LAT}'),
        ('minzoom',     str(MIN_ZOOM)),
        ('maxzoom',     str(MAX_ZOOM)),
    ]:
        db.execute('INSERT OR REPLACE INTO metadata VALUES (?,?)', (k, v))
    db.commit()

    headers = {'User-Agent': 'Noosphere/1.0 offline-hub github.com/joshburbrink/noosphere'}
    total = skipped = errors = 0

    for z in range(MIN_ZOOM, MAX_ZOOM + 1):
        x0, x1 = lon2x(MIN_LON, z), lon2x(MAX_LON, z)
        y0, y1 = lat2y(MAX_LAT, z), lat2y(MIN_LAT, z)
        count = (x1 - x0 + 1) * (y1 - y0 + 1)
        done  = 0
        print(f'z{z}: {count} tiles ({x1-x0+1}×{y1-y0+1})', flush=True)
        for x in range(x0, x1 + 1):
            for y in range(y0, y1 + 1):
                if db.execute('SELECT 1 FROM tiles WHERE zoom_level=? AND tile_column=? AND tile_row=?',
                              (z, x, tms(y,z))).fetchone():
                    skipped += 1; done += 1; continue
                url = TILE_URL.format(z=z, y=y, x=x)
                for attempt in range(3):
                    try:
                        req = urllib.request.Request(url, headers=headers)
                        with urllib.request.urlopen(req, timeout=15) as r:
                            data = r.read()
                        db.execute('INSERT OR REPLACE INTO tiles VALUES (?,?,?,?)', (z, x, tms(y,z), data))
                        total += 1; done += 1
                        if total % 100 == 0: db.commit()
                        time.sleep(0.03)
                        break
                    except Exception as e:
                        if attempt == 2:
                            print(f'  ERR z{z}/{x}/{y}: {e}', file=sys.stderr)
                            errors += 1; done += 1
                        else:
                            time.sleep(1)
                if done % 100 == 0:
                    print(f'  {done}/{count} ({done*100//count}%)', flush=True)
        db.commit()
        print(f'  z{z} done', flush=True)

    db.close()
    size = os.path.getsize(OUTPUT) / 1024 / 1024
    print(f'\nFinished. {total} downloaded, {skipped} skipped, {errors} errors. {size:.1f} MB', flush=True)

if __name__ == '__main__':
    main()
