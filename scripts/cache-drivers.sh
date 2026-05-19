#!/bin/bash
# cache-drivers.sh -- Download WiFi/ethernet driver packages for offline install on unknown hardware.
# Run this on a machine with internet access. Cached .deb files can be installed later
# on any hardware with: cd <CACHE_DIR> && dpkg -i *.deb
# (#9  -  preload broad driver support)

set -e

CACHE_DIR="${1:-/var/cache/noosphere/drivers}"
mkdir -p "$CACHE_DIR"
cd "$CACHE_DIR"

echo "=== Noosphere Driver Cache ==="
echo "Downloading to: $CACHE_DIR"
echo "Kernel: $(uname -r)"
echo

apt-get update -qq

# Firmware blobs (no DKMS needed  -  just firmware files)
FIRMWARE_PKGS=(
    firmware-realtek        # rtl8188ee, rtl8192ce, rtl8821ce, rtl8822be, rtl8723be, rtl8188eu
    firmware-iwlwifi        # Intel WiFi 3160/7260/7265/8265/9260/AX200/AX201/AX210
    firmware-atheros        # ath9k, ath10k (many Qualcomm/Atheros)
    firmware-brcm80211      # Broadcom BCM43xx
    firmware-ralink         # MediaTek/Ralink rt2800
    firmware-libertas       # Marvell 8xxx
    firmware-misc-nonfree   # misc catch-all
)

# Ethernet/USB drivers (in-kernel but may need firmware)
NIC_PKGS=(
    firmware-bnx2           # Broadcom NetXtreme II
    firmware-bnx2x          # Broadcom 10GbE
    firmware-qlogic         # QLogic NICs
    firmware-myricom        # Myri10GE
    r8168-dkms              # Realtek RTL8111/8168 (PCI ethernet)  -  DKMS
)

# Build dependencies for DKMS (RTL8812AU already installed but deps needed on new hw)
BUILD_PKGS=(
    dkms
    build-essential
    "linux-headers-$(uname -r)"
)

echo "--- Firmware packages ---"
for pkg in "${FIRMWARE_PKGS[@]}"; do
    apt-get download "$pkg" 2>/dev/null && echo "  ✓ $pkg" || echo "  ✗ $pkg (skipped)"
done

echo
echo "--- NIC driver packages ---"
for pkg in "${NIC_PKGS[@]}"; do
    apt-get download "$pkg" 2>/dev/null && echo "  ✓ $pkg" || echo "  ✗ $pkg (skipped)"
done

echo
echo "--- Build dependencies ---"
for pkg in "${BUILD_PKGS[@]}"; do
    apt-get download "$pkg" 2>/dev/null && echo "  ✓ $pkg" || echo "  ✗ $pkg (skipped)"
done

# Also cache RTL8812AU DKMS source (USB WiFi dongle used for AP mode)
RTL_SRC="/usr/src/8812au-5.6.4.2"
if [ -d "$RTL_SRC" ]; then
    echo
    echo "--- RTL8812AU DKMS source (AP dongle) ---"
    tar -czf "$CACHE_DIR/rtl8812au-dkms-src.tar.gz" -C /usr/src 8812au-5.6.4.2
    echo "  ✓ Archived to rtl8812au-dkms-src.tar.gz"
fi

echo
CACHED=$(ls "$CACHE_DIR"/*.deb 2>/dev/null | wc -l)
echo "=== Done: $CACHED .deb packages cached ==="
echo
echo "To install on new hardware:"
echo "  cd $CACHE_DIR"
echo "  dpkg -i *.deb"
echo "  update-initramfs -u"
echo "  # Reboot, then run setup-wifi-drivers.sh for RTL8812AU AP dongle"
