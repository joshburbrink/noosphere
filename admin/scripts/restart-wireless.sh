#!/bin/bash
WIFI=$(ip link show | awk -F': ' '/^ *[0-9]+: wl/{gsub(/@.*/, "", $2); print $2; exit}')
if [ -z "$WIFI" ]; then
    echo "ERROR: No wireless interface found."
    exit 1
fi
echo "Wireless interface: $WIFI"
echo "Bringing down..."
ifdown "$WIFI" 2>/dev/null || ip link set "$WIFI" down
sleep 2
echo "Bringing up..."
ifup "$WIFI"
echo ""
ip addr show "$WIFI"
