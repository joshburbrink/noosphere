#!/bin/bash
# Runs as a systemd service. Detects the wireless interface and reconnects if it drops.
WIFI=$(ip link show | awk -F': ' '/^ *[0-9]+: wl/{gsub(/@.*/, "", $2); print $2; exit}')

if [ -z "$WIFI" ]; then
    echo "No wireless interface found, exiting."
    exit 1
fi

while true; do
    if ! ip link show "$WIFI" | grep -q "state UP"; then
        echo "$(date): $WIFI is down, attempting reconnect..."
        ifup "$WIFI" 2>/dev/null
    fi
    sleep 30
done
