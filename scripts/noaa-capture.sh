#!/bin/bash
# NOAA Weather Radio capture + SAME alert decoder.
# Pipeline: rtl_fm (FM demod) -> multimon-ng (EAS decode) -> sqlite log
# Decoded alerts are logged to /var/lib/noosphere/weather/alerts.db
set -u
CONF=/etc/noosphere/weather.conf
[ -f "$CONF" ] && . "$CONF"

FREQUENCY="${FREQUENCY:-162.550M}"
# GAIN: 49.6 (max) causes R820T PLL lock failures and noise distortion on
# strong local repeaters. 40 dB is a safer default; operators can override.
GAIN="${GAIN:-40}"
PPM="${PPM:-0}"
CAPTURE_RATE="${CAPTURE_RATE:-200k}"
OUTPUT_RATE="${OUTPUT_RATE:-22050}"
# SQUELCH=100 mutes the audio between voice broadcasts, which starves ffmpeg
# and prevents HLS segments from being written - operators then hear nothing
# and assume the dongle is broken. 0 = always pass audio.
SQUELCH="${SQUELCH:-0}"
SAME_FIPS="${SAME_FIPS:-}"

DATA=/var/lib/noosphere/weather
DB="$DATA/alerts.db"
LIVE_WAV="$DATA/live.wav"
LOG=/var/log/noosphere/weather.log

mkdir -p "$DATA"
touch "$LOG"

# Initialize SQLite schema if needed
sqlite3 "$DB" <<SQL
CREATE TABLE IF NOT EXISTS alerts (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ts INTEGER NOT NULL,
  raw TEXT NOT NULL,
  originator TEXT,
  event_code TEXT,
  event_name TEXT,
  fips TEXT,
  duration TEXT,
  issued TEXT,
  station TEXT,
  matched_local INTEGER DEFAULT 0,
  audio_file TEXT
);
CREATE INDEX IF NOT EXISTS idx_alerts_ts ON alerts(ts);
CREATE TABLE IF NOT EXISTS status (
  key TEXT PRIMARY KEY,
  value TEXT
);
SQL

# Pre-clean: only keep last 24h of rolling audio
find "$DATA" -name "alert_*.wav" -mtime +7 -delete 2>/dev/null

# EAS event code -> friendly name (subset; full NWS list)
event_name() {
  case "$1" in
    TOR) echo "Tornado Warning";;
    SVR) echo "Severe Thunderstorm Warning";;
    FFW) echo "Flash Flood Warning";;
    FLW) echo "Flood Warning";;
    SVA) echo "Severe Thunderstorm Watch";;
    TOA) echo "Tornado Watch";;
    WSW) echo "Winter Storm Warning";;
    BZW) echo "Blizzard Warning";;
    BWW) echo "Beach Hazards Statement";;
    CFW) echo "Coastal Flood Warning";;
    DBW) echo "Dam Break Warning";;
    DEW) echo "Contagious Disease Warning";;
    EAN) echo "Emergency Action Notification";;
    EQW) echo "Earthquake Warning";;
    EVI) echo "Evacuation Immediate";;
    FRW) echo "Fire Warning";;
    HMW) echo "Hazardous Materials Warning";;
    HUW) echo "Hurricane Warning";;
    LEW) echo "Law Enforcement Warning";;
    NUW) echo "Nuclear Power Plant Warning";;
    RHW) echo "Radiological Hazard Warning";;
    RMT) echo "Required Monthly Test";;
    RWT) echo "Required Weekly Test";;
    SPS) echo "Special Weather Statement";;
    SPW) echo "Shelter In Place Warning";;
    TSW) echo "Tsunami Warning";;
    VOW) echo "Volcano Warning";;
    *)   echo "Unknown ($1)";;
  esac
}

# Update status row
status_set() {
  sqlite3 "$DB" "INSERT INTO status(key,value) VALUES('$1','$2') ON CONFLICT(key) DO UPDATE SET value='$2';"
}

status_set frequency "$FREQUENCY"
status_set started_at "$(date -Iseconds)"

echo "[noaa-capture] starting on $FREQUENCY gain=$GAIN ppm=$PPM" | tee -a "$LOG"


STREAM_DIR=/var/www/noosphere/weather/stream
mkdir -p "$STREAM_DIR"
find "$STREAM_DIR" -name "seg*.ts" -delete 2>/dev/null
rm -f "$STREAM_DIR/live.m3u8" 2>/dev/null

# Fanout: rtl_fm -> tee -> (ffmpeg HLS) + (multimon-ng SAME)
rtl_fm -f "$FREQUENCY" -M fm -s "$CAPTURE_RATE" -r "$OUTPUT_RATE" -g "$GAIN" -p "$PPM" -l "$SQUELCH" -A fast -E deemp -E dc 2>>"$LOG" \
  | tee >(ffmpeg -hide_banner -loglevel warning -f s16le -ar "$OUTPUT_RATE" -ac 1 -i - -c:a aac -b:a 32k -f hls -hls_time 2 -hls_list_size 60 -hls_flags delete_segments+omit_endlist+independent_segments -hls_segment_filename "$STREAM_DIR/seg%05d.ts" "$STREAM_DIR/live.m3u8" 2>>"$LOG") \
  | multimon-ng -t raw -a EAS -q - 2>>"$LOG" \
  | stdbuf -oL grep --line-buffered '^EAS:' | tee -a "$LOG" | /usr/local/bin/noaa-log-alert.py >> "$LOG" 2>&1

