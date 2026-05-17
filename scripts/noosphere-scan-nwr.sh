#!/bin/bash
# Scan all 7 NOAA NWR channels and emit one "freq=dB" line per channel
# on stdout. Stops noaa-weather.service for the duration of the scan,
# then restarts it (whatever channel it was on stays the same).
set -eu
GAIN=49.6
INTEG=3   # seconds
WAS_ACTIVE=0
if systemctl is-active --quiet noaa-weather.service; then
    WAS_ACTIVE=1
    systemctl stop noaa-weather.service
fi
sleep 1

TMP=$(mktemp /tmp/nwrscan.XXXXXX.csv)
trap 'rm -f "$TMP"' EXIT

# Sweep 162.395-162.555 MHz in 25 kHz bins, single shot, 3s integration.
# rtl_power exits on its own with -1.
rtl_power -f 162.395M:162.560M:25000 -g $GAIN -i $INTEG -1 "$TMP" >/dev/null 2>&1 || true

# rtl_power CSV columns: date, time, hz_low, hz_high, hz_step, samples, dB1, dB2 ...
# Each row covers one band; with a 165kHz span we get one row.
# We need the dB at each of the 7 official NWR center frequencies.
TMPCSV="$TMP" python3 <<'PY'
import csv, sys
channels = [162400000,162425000,162450000,162475000,162500000,162525000,162550000]
best = {f: -200 for f in channels}
with open(__import__("os").environ["TMPCSV"]) as fh:
    for row in csv.reader(fh):
        if len(row) < 7: continue
        try:
            lo = int(row[2].strip()); hi = int(row[3].strip())
            step = float(row[4].strip())
            dbs = [float(x) for x in row[6:]]
        except ValueError:
            continue
        for f in channels:
            if lo <= f < hi:
                idx = int(round((f - lo) / step))
                if 0 <= idx < len(dbs):
                    if dbs[idx] > best[f]:
                        best[f] = dbs[idx]
for f in channels:
    print(f"{f/1e6:.3f}={best[f]:.1f}")
PY

if [ "$WAS_ACTIVE" = 1 ]; then
    systemctl start noaa-weather.service
fi
