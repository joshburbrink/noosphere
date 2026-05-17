#!/bin/bash
# Spectrum scanner: rtl_power -> waterfall PNG renderer
set -u
CONF=/etc/noosphere/scanner.conf
[ -f "$CONF" ] && . "$CONF"

LOW="${SCANNER_FREQ_LOW:-88M}"
HIGH="${SCANNER_FREQ_HIGH:-108M}"
STEP="${SCANNER_FREQ_STEP:-100k}"
INTERVAL="${SCANNER_INTERVAL:-1}"
GAIN="${SCANNER_GAIN:-40}"
PPM="${SCANNER_PPM:-0}"
LOG=/var/log/noosphere/scanner.log

export WATERFALL_PNG=/var/www/noosphere/radio/scanner/waterfall.png
export WATERFALL_META=/var/www/noosphere/radio/scanner/waterfall.json
export WATERFALL_DB_MIN="${SCANNER_DB_MIN:--50}"
export WATERFALL_DB_MAX="${SCANNER_DB_MAX:--15}"

mkdir -p "$(dirname "$WATERFALL_PNG")"
touch "$LOG"
echo "[scanner] $(date -Iseconds) starting $LOW-$HIGH step $STEP gain $GAIN" >> "$LOG"

rtl_power -f "${LOW}:${HIGH}:${STEP}" -g "$GAIN" -p "$PPM" -i "$INTERVAL" 2>>"$LOG" \
  | /usr/local/bin/waterfall-render.py 2>>"$LOG"
