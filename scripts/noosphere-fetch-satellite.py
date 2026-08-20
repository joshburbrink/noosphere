#!/usr/bin/env python3
"""noosphere-fetch-satellite.py - cache USGS aerial imagery into an MBTiles file.

A rewrite of scripts/download-satellite.py for statewide scale. That version is
single-threaded with a 30 ms sleep per tile, which is fine for the ~47k tiles of
one county but would take upwards of eleven hours for the ~800k tiles of a whole
state.

Changes:
  * A pool of fetch threads with ONE writer thread. SQLite is not safe for
    concurrent writers, so workers only fetch and hand bytes to a queue.
  * Resumable. Already-stored tiles are skipped without a request, so the job
    can be stopped and restarted, or its bbox narrowed later, with nothing
    wasted.
  * Reports rate and ETA, because "how long will this take" is the question
    that actually decides whether the scope is sane.

Row order: MBTiles stores tile_row in TMS (flipped) while the ArcGIS endpoint
is addressed in XYZ, hence the tms() conversion - same as the original.
"""
import argparse
import math
import os
import queue
import sqlite3
import sys
import threading
import time
import urllib.request

TILE_URL = ('https://basemap.nationalmap.gov/arcgis/rest/services/'
            'USGSImageryOnly/MapServer/tile/{z}/{y}/{x}')
UA = 'Noosphere/1.0 offline-hub github.com/joshburbrink/noosphere'


def lon2x(lon, z):
    return int((lon + 180) / 360 * 2 ** z)


def lat2y(lat, z):
    r = math.radians(lat)
    return int((1 - math.log(math.tan(r) + 1 / math.cos(r)) / math.pi) / 2 * 2 ** z)


def tms(y, z):
    return (2 ** z - 1) - y


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--bbox', required=True, help='south,west,north,east')
    ap.add_argument('--out', required=True)
    ap.add_argument('--minzoom', type=int, default=8)
    ap.add_argument('--maxzoom', type=int, default=16)
    ap.add_argument('--workers', type=int, default=8)
    ap.add_argument('--delay', type=float, default=0.02,
                    help='per-worker pause between requests, to stay polite')
    ap.add_argument('--name', default='USGS Imagery')
    a = ap.parse_args()

    s, w, n, e = [float(v) for v in a.bbox.split(',')]

    os.makedirs(os.path.dirname(a.out), exist_ok=True)
    # check_same_thread=False because the writer thread owns all writes.
    # Only ONE thread ever touches this handle, so serialisation is not at
    # risk; the default guard just does not know that.
    db = sqlite3.connect(a.out, check_same_thread=False)
    db.execute('PRAGMA journal_mode=WAL')
    db.execute('PRAGMA synchronous=NORMAL')
    db.execute('CREATE TABLE IF NOT EXISTS metadata (name TEXT PRIMARY KEY, value TEXT)')
    db.execute('CREATE TABLE IF NOT EXISTS tiles (zoom_level INT, tile_column INT,'
               ' tile_row INT, tile_data BLOB,'
               ' PRIMARY KEY (zoom_level, tile_column, tile_row))')
    db.execute('CREATE UNIQUE INDEX IF NOT EXISTS tile_index'
               ' ON tiles (zoom_level, tile_column, tile_row)')
    for k, v in [('name', a.name), ('type', 'overlay'), ('version', '1'),
                 ('description', 'USGS aerial imagery'), ('format', 'jpg'),
                 ('bounds', f'{w},{s},{e},{n}'),
                 ('minzoom', str(a.minzoom)), ('maxzoom', str(a.maxzoom))]:
        db.execute('INSERT OR REPLACE INTO metadata VALUES (?,?)', (k, v))
    db.commit()

    have = set()
    for z, x, y in db.execute('SELECT zoom_level, tile_column, tile_row FROM tiles'):
        have.add((z, x, y))
    print(f'resuming with {len(have):,} tiles already stored', flush=True)

    work = []
    for z in range(a.minzoom, a.maxzoom + 1):
        x0, x1 = lon2x(w, z), lon2x(e, z)
        y0, y1 = lat2y(n, z), lat2y(s, z)
        for x in range(x0, x1 + 1):
            for y in range(y0, y1 + 1):
                if (z, x, tms(y, z)) not in have:
                    work.append((z, x, y))
    total = len(work)
    print(f'{total:,} tiles to fetch across z{a.minzoom}-{a.maxzoom}', flush=True)
    if not total:
        print('nothing to do'); return 0

    jobs = queue.Queue()
    writes = queue.Queue(maxsize=2000)
    for t in work:
        jobs.put(t)

    stats = {'ok': 0, 'err': 0, 'start': time.time()}
    lock = threading.Lock()
    stop = threading.Event()

    def fetch_worker():
        opener = urllib.request.build_opener()
        opener.addheaders = [('User-Agent', UA)]
        while not stop.is_set():
            try:
                z, x, y = jobs.get_nowait()
            except queue.Empty:
                return
            data = None
            for attempt in range(3):
                try:
                    with opener.open(TILE_URL.format(z=z, y=y, x=x), timeout=20) as r:
                        data = r.read()
                    break
                except Exception:
                    if attempt == 2:
                        with lock:
                            stats['err'] += 1
                    else:
                        time.sleep(1 + attempt)
            if data:
                writes.put((z, x, tms(y, z), data))
            if a.delay:
                time.sleep(a.delay)

    def writer():
        pending = 0
        last = time.time()
        while True:
            try:
                item = writes.get(timeout=2)
            except queue.Empty:
                if stop.is_set() and writes.empty():
                    break
                continue
            if item is None:
                break
            db.execute('INSERT OR REPLACE INTO tiles VALUES (?,?,?,?)', item)
            pending += 1
            with lock:
                stats['ok'] += 1
            if pending >= 500:
                db.commit()
                pending = 0
            now = time.time()
            if now - last >= 30:
                last = now
                with lock:
                    ok, err = stats['ok'], stats['err']
                el = now - stats['start']
                rate = ok / el if el else 0
                left = (total - ok - err) / rate if rate else 0
                pct = (ok + err) * 100 // total
                print(f'  {ok:,}/{total:,} ({pct}%)  {rate:.0f} tiles/s  '
                      f'err {err:,}  ETA {left/3600:.1f}h  '
                      f'{os.path.getsize(a.out)/1e9:.2f} GB', flush=True)
        db.commit()

    wt = threading.Thread(target=writer, daemon=True)
    wt.start()
    pool = [threading.Thread(target=fetch_worker, daemon=True) for _ in range(a.workers)]
    for t in pool:
        t.start()
    try:
        for t in pool:
            t.join()
    except KeyboardInterrupt:
        print('interrupted; committing what we have', flush=True)
    stop.set()
    writes.put(None)
    wt.join(timeout=60)
    db.commit()
    db.close()

    el = time.time() - stats['start']
    print(f'\ndone: {stats["ok"]:,} fetched, {stats["err"]:,} errors, '
          f'{el/3600:.2f}h, {os.path.getsize(a.out)/1e9:.2f} GB', flush=True)
    return 0


if __name__ == '__main__':
    sys.exit(main())
