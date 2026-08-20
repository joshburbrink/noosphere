#!/bin/bash
# Spectrum scanner: rtl_power -> waterfall PNG renderer
set -u
CONF=/etc/noosphere/scanner.conf
[ -f "$CONF" ] && . "$CONF"

# Two config generations exist in the field:
#   - SCANNER_FREQ_LOW / SCANNER_FREQ_HIGH / SCANNER_GAIN / SCANNER_PPM
#   - SCAN_START / SCAN_STOP (raw Hz) and GAIN / PPM, as written by
#     noosphere-radio-mode.sh and by hand-edited configs.
# Accept both so a stale conf silently falling back to defaults (or to
# gain 40 when the admin set something else) cannot happen again.
LOW="${SCANNER_FREQ_LOW:-${SCAN_START:-88M}}"
HIGH="${SCANNER_FREQ_HIGH:-${SCAN_STOP:-108M}}"
STEP="${SCANNER_FREQ_STEP:-${SCAN_STEP:-100k}}"
INTERVAL="${SCANNER_INTERVAL:-1}"
GAIN="${SCANNER_GAIN:-${GAIN:-40}}"
PPM="${SCANNER_PPM:-${PPM:-0}}"
LOG=/var/log/noosphere/scanner.log

export WATERFALL_PNG=/var/www/noosphere/radio/scanner/waterfall.png
export WATERFALL_META=/var/www/noosphere/radio/scanner/waterfall.json
export WATERFALL_DB_MIN="${SCANNER_DB_MIN:--50}"
export WATERFALL_DB_MAX="${SCANNER_DB_MAX:--15}"

# The renderer normally lives in /usr/local/bin, but the copy in the repo is
# the source of truth; fall back to it so a missing install step degrades to
# "works from the repo" instead of exit 127.
RENDER=/usr/local/bin/waterfall-render.py
[ -x "$RENDER" ] || [ -f "$RENDER" ] || RENDER=/var/www/noosphere/scripts/waterfall-render.py

mkdir -p "$(dirname "$WATERFALL_PNG")"
touch "$LOG"
echo "[scanner] $(date -Iseconds) starting $LOW-$HIGH step $STEP gain $GAIN via $RENDER" >> "$LOG"

if [ ! -f "$RENDER" ]; then
    echo "[scanner] FATAL: renderer not found at $RENDER" >> "$LOG"
    exit 1
fi

# Invoke through python3 explicitly: the exec bit on the deployed copy is not
# guaranteed, and a lost +x used to surface only as a bare 127.
set -o pipefail
rtl_power -f "${LOW}:${HIGH}:${STEP}" -g "$GAIN" -p "$PPM" -i "$INTERVAL" 2>>"$LOG" \
  | python3 "$RENDER" 2>>"$LOG"
