#!/bin/bash
# Set NOAA Weather Radio frequency and restart capture service.
# Called from PHP admin UI via sudo. Only accepts the 7 official NWR channels.
set -eu
FREQ="${1:-}"
case "$FREQ" in
  162.400|162.425|162.450|162.475|162.500|162.525|162.550) ;;
  *) echo "Invalid frequency: $FREQ" >&2; exit 2 ;;
esac
CONF=/etc/noosphere/weather.conf
[ -f "$CONF" ] || { echo "Missing $CONF" >&2; exit 3; }
sed -i "s/^FREQUENCY=.*/FREQUENCY=${FREQ}M/" "$CONF"
grep -q '^FREQUENCY=' "$CONF" || echo "FREQUENCY=${FREQ}M" >> "$CONF"
systemctl restart noaa-weather.service
echo "Set NWR frequency to ${FREQ}M and restarted noaa-weather.service"
