#!/bin/bash
# Noosphere  -  pre-fetch everything setup-pxe.sh needs, so PXE can be configured
# OFFLINE later. Run this once while the server still has internet.
#
# Caches to /var/lib/noosphere/pkg-cache/pxe/:
#   - tftpd-hpa, pxelinux, syslinux-common, ipxe  (.deb + dependencies)
#   - Debian trixie amd64 netboot kernel (vmlinuz) + initrd.gz
#   - a Packages index so the dir works as a local apt repo
#
# setup-pxe.sh automatically prefers this cache when it exists.
set -e

PKG_CACHE="/var/lib/noosphere/pkg-cache/pxe"
PKGS="tftpd-hpa pxelinux syslinux-common ipxe wget"

NETBOOT_BASE="https://deb.debian.org/debian/dists/trixie/main/installer-amd64/current/images/netboot/debian-installer/amd64"
KERNEL_URL="$NETBOOT_BASE/linux"
INITRD_URL="$NETBOOT_BASE/initrd.gz"

err()  { echo "ERROR: $*" >&2; exit 1; }
info() { echo "  $*"; }

[ "$EUID" -eq 0 ] || err "Must run as root."

echo ""
echo "=== Caching PXE boot dependencies for offline use ==="
echo ""

mkdir -p "$PKG_CACHE"

# ── .deb packages (with dependencies) ─────────────────────────────────────────
info "Downloading packages + dependencies: $PKGS"
apt-get update -qq || info "WARN: apt-get update failed - continuing with current lists."
# Resolve full dependency closure and download every .deb into the cache dir.
DEBIAN_FRONTEND=noninteractive apt-get install -y --download-only \
    -o Dir::Cache::archives="$PKG_CACHE" $PKGS \
    || err "Package download failed (need internet)."
# apt leaves partial/ and lock files behind; clear them
rm -rf "$PKG_CACHE/partial" "$PKG_CACHE/lock" 2>/dev/null || true

# ── installer kernel + initrd ─────────────────────────────────────────────────
info "Downloading Debian installer kernel..."
wget -q -O "$PKG_CACHE/vmlinuz" "$KERNEL_URL"  || err "Kernel download failed."
info "Downloading Debian installer initrd..."
wget -q -O "$PKG_CACHE/initrd.gz" "$INITRD_URL" || err "initrd download failed."

# ── build local apt repo index ────────────────────────────────────────────────
info "Building local apt repo index..."
if ! command -v dpkg-scanpackages >/dev/null 2>&1; then
    apt-get install -y dpkg-dev || info "WARN: dpkg-dev unavailable - Packages index skipped."
fi
if command -v dpkg-scanpackages >/dev/null 2>&1; then
    ( cd "$PKG_CACHE" && dpkg-scanpackages . /dev/null > Packages 2>/dev/null \
        && gzip -9c Packages > Packages.gz ) || info "WARN: index build failed."
fi

echo ""
info "Done. Cached $(ls "$PKG_CACHE"/*.deb 2>/dev/null | wc -l) .deb(s) + kernel/initrd to:"
info "  $PKG_CACHE"
echo ""
echo "setup-pxe.sh configure will now work offline."
