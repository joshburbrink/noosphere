#!/bin/bash
# Noosphere  -  out-of-tree WiFi driver installer
# Run while online, before going offline.
# Installs DKMS drivers for USB WiFi adapters not covered by mainline kernel.
#
# Issue #9: https://github.com/joshburbrink/noosphere/issues/9
#
# Tested adapters:
#   Realtek RTL8812AU / RTL8821AU (USB ID 0bda:0811, 0bda:0812)
#     Chipset: 802.11ac dual-band (2.4 + 5 GHz), AP mode supported
#     Confirmed on: Debian 13 (kernel 6.12.73), works as hostapd AP
#     Source: https://github.com/aircrack-ng/rtl8812au (v5.6.4.2)

set -e

KERNEL=$(uname -r)
DRIVER_NAME="realtek-rtl88xxau"
DRIVER_VERSION="5.6.4.2"
DRIVER_URL="https://codeload.github.com/aircrack-ng/rtl8812au/tar.gz/refs/heads/v${DRIVER_VERSION}"
DRIVER_DIR="/tmp/${DRIVER_NAME}-${DRIVER_VERSION}"
SRC_INSTALL_DIR="/usr/src/${DRIVER_NAME}-${DRIVER_VERSION}"

err()  { echo "ERROR: $*" >&2; exit 1; }
info() { echo "  $*"; }

echo ""
echo "=== Noosphere WiFi Driver Setup ==="
echo "  Kernel: $KERNEL"
echo ""

# ── Prerequisites ─────────────────────────────────────────────────────────────
DEB_ARCH="$(dpkg --print-architecture)"

# On a Raspberry Pi the onboard Broadcom WiFi can run the AP with no out-of-tree
# driver at all (see setup-hostapd.sh).  This script is only needed for an
# external RTL8812AU/8821AU USB adapter.
if grep -qi raspberry /proc/device-tree/model 2>/dev/null; then
    info "Raspberry Pi detected - onboard WiFi can run the AP without this USB"
    info "driver (setup-hostapd.sh configure).  Continuing in case you're using"
    info "an external RTL8812AU adapter."
    echo ""
fi

# Header package name varies: Debian uses linux-headers-<arch>; Raspberry Pi OS
# uses raspberrypi-kernel-headers.  Try the exact-kernel package first.
info "Installing build prerequisites..."
apt-get install -y dkms "linux-headers-${KERNEL}" 2>/dev/null || \
apt-get install -y dkms "linux-headers-${DEB_ARCH}" 2>/dev/null || \
apt-get install -y dkms raspberrypi-kernel-headers 2>/dev/null || \
apt-get install -y dkms linux-headers-generic 2>/dev/null || \
err "Could not install linux-headers  -  may need manual install: apt-get install linux-headers-\$(uname -r)"

HEADERS_PATH="/lib/modules/${KERNEL}/build"
[ -d "$HEADERS_PATH" ] || err "Kernel headers not found at $HEADERS_PATH after install"
info "Headers OK: $HEADERS_PATH"

# ── RTL8812AU / RTL8821AU driver ──────────────────────────────────────────────
echo ""
echo "--- RTL8812AU/8821AU (aircrack-ng/rtl8812au v${DRIVER_VERSION}) ---"

if dkms status "${DRIVER_NAME}/${DRIVER_VERSION}" 2>/dev/null | grep -q "installed"; then
    info "Driver already installed for kernel $KERNEL  -  skipping build"
else
    if [ ! -d "$SRC_INSTALL_DIR" ]; then
        info "Downloading driver source..."
        rm -rf "$DRIVER_DIR"
        mkdir -p "$DRIVER_DIR"
        curl -sL "$DRIVER_URL" | tar xz --strip-components=1 -C "$DRIVER_DIR" \
            || err "Download failed. Check internet connection."
        cp -r "$DRIVER_DIR" "$SRC_INSTALL_DIR"
        info "Source installed to $SRC_INSTALL_DIR"
    else
        info "Source already present at $SRC_INSTALL_DIR"
    fi

    info "Adding to DKMS..."
    dkms add "${DRIVER_NAME}/${DRIVER_VERSION}" 2>/dev/null || true

    info "Building (may take 1-2 minutes)..."
    dkms build "${DRIVER_NAME}/${DRIVER_VERSION}" \
        || err "Build failed  -  check: dkms status && journalctl -n 30"

    info "Installing module..."
    dkms install "${DRIVER_NAME}/${DRIVER_VERSION}"
    info "RTL8812AU driver installed."
fi

# ── Load module now if adapter is plugged in ──────────────────────────────────
echo ""
REALTEK_USB=$(lsusb | grep -i "0bda:0811\|0bda:0812\|0bda:a811\|0bda:b812\|2357:010\|0bda:0820" | head -1)
if [ -n "$REALTEK_USB" ]; then
    info "RTL8812AU adapter detected: $REALTEK_USB"
    modprobe 88XXau 2>/dev/null && info "Module loaded." || info "Already loaded."
    sleep 2
    NEW_IFACE=$(ip link show | awk -F': ' '/^ *[0-9]+: wlx/{gsub(/@.*/, "", $2); print $2}' | head -1)
    if [ -n "$NEW_IFACE" ]; then
        info "Interface ready: $NEW_IFACE"
    else
        info "Interface not yet visible  -  try: modprobe 88XXau && ip link show"
    fi
else
    info "No RTL8812AU detected via USB (adapter not plugged in  -  module will load automatically when connected)"
fi

# ── Summary ───────────────────────────────────────────────────────────────────
echo ""
echo "=== Done ==="
echo ""
echo "  RTL8812AU/8821AU: DKMS module installed, auto-loads on plugin"
echo ""
echo "  To set up as AP:  setup-hostapd.sh configure"
echo "  To enable AP:     setup-hostapd.sh enable"
echo ""
dkms status "${DRIVER_NAME}/${DRIVER_VERSION}" 2>/dev/null
