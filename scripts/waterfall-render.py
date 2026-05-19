#!/usr/bin/env python3
"""Read rtl_power CSV from stdin and continuously render a waterfall PNG.

rtl_power format per line:
  YYYY-MM-DD, HH:MM:SS, Hz_low, Hz_high, Hz_step, samples, dB, dB, dB, ...

We accumulate rows (one per scan sweep) into a rolling buffer and re-render
the PNG every PNG_INTERVAL seconds. Old rows scroll off the top.
"""
import os, sys, time, struct
from PIL import Image

OUT_PATH = os.environ.get("WATERFALL_PNG", "/var/www/noosphere/radio/scanner/waterfall.png")
TMP_PATH = OUT_PATH + ".tmp"
META_PATH = os.environ.get("WATERFALL_META", "/var/www/noosphere/radio/scanner/waterfall.json")
HEIGHT = int(os.environ.get("WATERFALL_ROWS", "240"))
RENDER_EVERY = float(os.environ.get("WATERFALL_INTERVAL", "1.0"))
DB_MIN = float(os.environ.get("WATERFALL_DB_MIN", "-50"))
DB_MAX = float(os.environ.get("WATERFALL_DB_MAX", "-15"))

# Viridis-ish colormap (8 stops, linear interp)
COLORMAP = [
    (68,   1,  84),
    (72,  35, 116),
    (64,  67, 135),
    (52,  94, 141),
    (41, 120, 142),
    (32, 144, 140),
    (34, 167, 132),
    (68, 190, 112),
    (121,209,  81),
    (189,222,  38),
    (253,231,  36),
]

def lerp_color(t):
    """t in [0,1] -> (r,g,b) sampled from COLORMAP."""
    if t <= 0: return COLORMAP[0]
    if t >= 1: return COLORMAP[-1]
    fi = t * (len(COLORMAP) - 1)
    i = int(fi)
    f = fi - i
    a, b = COLORMAP[i], COLORMAP[i + 1]
    return (int(a[0] + (b[0]-a[0])*f), int(a[1] + (b[1]-a[1])*f), int(a[2] + (b[2]-a[2])*f))

def db_to_rgb(db):
    t = (db - DB_MIN) / (DB_MAX - DB_MIN)
    return lerp_color(t)

# Each scan sweep may take multiple CSV rows to cover the full band  -  rtl_power
# emits one row per "chunk" of the band when the requested band exceeds the
# dongle's instantaneous bandwidth. We aggregate by timestamp.
def main():
    rows = []  # newest at end; each row = list of (freq_center, db)
    sweep = {}  # ts_key -> list of (freq_center, db)
    last_ts = None
    last_render = 0.0
    width = None
    freq_axis = None
    f_low_global = None
    f_high_global = None
    f_step_global = None

    for line in sys.stdin:
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        parts = [p.strip() for p in line.split(",")]
        if len(parts) < 7:
            continue
        try:
            ts_key = parts[0] + "T" + parts[1]
            f_low = float(parts[2])
            f_high = float(parts[3])
            f_step = float(parts[4])
            powers = [float(p) for p in parts[6:]]
        except ValueError:
            continue

        if f_low_global is None or f_low < f_low_global: f_low_global = f_low
        if f_high_global is None or f_high > f_high_global: f_high_global = f_high
        f_step_global = f_step

        # Append (freq, dB) pairs to current sweep bucket
        sweep.setdefault(ts_key, []).extend(
            (f_low + i * f_step + f_step/2, db) for i, db in enumerate(powers)
        )

        # When we get a new timestamp, flush the previous one as a complete row
        if last_ts is not None and ts_key != last_ts:
            row_data = sweep.pop(last_ts, [])
            row_data.sort()
            rows.append(row_data)
            if len(rows) > HEIGHT:
                rows = rows[-HEIGHT:]
        last_ts = ts_key

        now = time.time()
        if now - last_render >= RENDER_EVERY and rows and f_step_global:
            # Build the image: width = number of freq bins across the full band
            total_bw = f_high_global - f_low_global
            w = max(64, int(total_bw / f_step_global))
            h = len(rows)
            img = Image.new("RGB", (w, h), (0, 0, 0))
            px = img.load()
            for y, row in enumerate(rows):
                # Map each (freq, db) to x bucket
                for freq, db in row:
                    x = int((freq - f_low_global) / total_bw * (w - 1))
                    if 0 <= x < w:
                        px[x, y] = db_to_rgb(db)
            # Scale up vertically a bit for visibility
            scale = max(1, 480 // max(h, 1))
            if scale > 1:
                img = img.resize((w, h * scale), Image.NEAREST)
            img.save(TMP_PATH, "PNG")
            os.replace(TMP_PATH, OUT_PATH)
            # Write meta JSON for the web UI
            latest_vals = [db for _, db in rows[-1]] if rows else []
            peak_db = round(max(latest_vals), 1) if latest_vals else None
            mean_db = round(sum(latest_vals) / len(latest_vals), 1) if latest_vals else None
            peak_str = str(peak_db) if peak_db is not None else "null"
            mean_str = str(mean_db) if mean_db is not None else "null"
            with open(META_PATH + ".tmp", "w") as f:
                f.write(f'{{"f_low":{f_low_global},"f_high":{f_high_global},"f_step":{f_step_global},"rows":{h},"updated":{int(now)},"db_min":{DB_MIN},"db_max":{DB_MAX},"peak_db":{peak_str},"mean_db":{mean_str}}}\n')
            os.replace(META_PATH + ".tmp", META_PATH)
            last_render = now

if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        sys.exit(0)
