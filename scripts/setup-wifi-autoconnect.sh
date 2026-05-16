#!/bin/bash
# Fixes WiFi auto-reconnect on Debian systems using ifupdown + wpa_supplicant.
# Run as root on the noosphere server after initial OS install.
set -e

INTERFACES=/etc/network/interfaces
SERVICE_SRC="$(dirname "$0")/../systemd/wifi-reconnect.service"
WATCHDOG_SRC="$(dirname "$0")/wifi-watchdog.sh"

WIFI=$(ip link show | awk -F': ' '/^ *[0-9]+: wl/{gsub(/@.*/, "", $2); print $2; exit}')
if [ -z "$WIFI" ]; then
    echo "ERROR: No wireless interface found."
    exit 1
fi
echo "Detected wireless interface: $WIFI"

# Change allow-hotplug to auto so the interface comes up on boot unconditionally
if grep -q "allow-hotplug $WIFI" "$INTERFACES"; then
    sed -i "s/allow-hotplug $WIFI/auto $WIFI/" "$INTERFACES"
    echo "Updated $INTERFACES: allow-hotplug -> auto for $WIFI"
elif grep -q "auto $WIFI" "$INTERFACES"; then
    echo "$INTERFACES already has 'auto $WIFI', no change needed."
else
    echo "WARNING: $WIFI not found in $INTERFACES — add it manually."
fi

# Install watchdog script
install -m 755 "$WATCHDOG_SRC" /usr/local/bin/wifi-watchdog.sh
echo "Installed /usr/local/bin/wifi-watchdog.sh"

# Install and enable systemd service
install -m 644 "$SERVICE_SRC" /etc/systemd/system/wifi-reconnect.service
systemctl daemon-reload
systemctl enable --now wifi-reconnect.service
echo "wifi-reconnect.service enabled and started."

echo ""
echo "Done. Reboot to verify WiFi comes up automatically."
