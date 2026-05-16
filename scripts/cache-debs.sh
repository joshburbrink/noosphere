#!/bin/bash
# Copy apt .deb cache to the external drive and build a local apt repo.
# Run while online. The result lets a new system install all packages offline.
set -e

EXT_DRIVE="/media/noosphere-ext"
CACHE_DIR="$EXT_DRIVE/apt-cache"
APT_CACHE="/var/cache/apt/archives"

if [ ! -d "$EXT_DRIVE" ]; then
    echo "ERROR: External drive not mounted at $EXT_DRIVE"
    exit 1
fi

echo "Syncing .deb files to $CACHE_DIR..."
mkdir -p "$CACHE_DIR"
rsync -av --include="*.deb" --exclude="*" "$APT_CACHE/" "$CACHE_DIR/"

echo ""
echo "Building local apt repository..."
if ! command -v dpkg-scanpackages &>/dev/null; then
    apt-get install -y dpkg-dev
fi
cd "$CACHE_DIR"
dpkg-scanpackages . /dev/null | gzip -9c > Packages.gz
dpkg-scanpackages . /dev/null > Packages

echo ""
echo "Done. $CACHE_DIR contains $(ls *.deb | wc -l) packages."
echo ""
echo "To use on a new system, mount the drive and run:"
echo "  echo 'deb [trusted=yes] file://$CACHE_DIR ./' > /etc/apt/sources.list.d/noosphere-local.list"
echo "  apt-get update"
echo "  apt-get install <package>"
