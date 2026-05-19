#!/bin/bash
# clone-usb.sh -- Create a byte-for-byte backup of the Noosphere boot USB drive.
# Run this on any Linux machine (not on the USB itself while booted).
# (#13  -  kit redundancy: backup boot drive)
#
# Kit redundancy checklist (see also admin wiki):
#   [x] Backup USB drive (this script)
#   [ ] Power: USB-C or barrel charger for the HP 3105m + USB power bank
#   [ ] Admin recovery: root password in sealed envelope with kit
#   [ ] Scheduled clone refresh: re-run this when major updates are pushed

set -e

usage() {
    cat <<EOF
Usage: $0 <source> <dest> [--verify]

  source    Source block device (the Noosphere USB)   e.g. /dev/sdb
  dest      Destination block device (backup USB)     e.g. /dev/sdc
  --verify  After clone, verify with SHA256 checksum

Examples:
  $0 /dev/sdb /dev/sdc              # Clone sdb -> sdc
  $0 /dev/sdb /dev/sdc --verify     # Clone and verify
  $0 /dev/sdb backup-$(date +%Y%m%d).img  # Clone to image file

WARNING: All data on <dest> will be overwritten.
EOF
    exit 1
}

SRC="${1:-}"
DST="${2:-}"
VERIFY="${3:-}"
[ -z "$SRC" ] || [ -z "$DST" ] && usage

# Refuse to run if source is the current root device
ROOT_DEV=$(findmnt -n -o SOURCE / | sed 's/[0-9]*$//' | sed 's/p[0-9]*$//')
if [ "$SRC" = "$ROOT_DEV" ]; then
    echo "ERROR: $SRC appears to be your current root device. Boot from a live USB first."
    exit 1
fi

# Check source exists
if [ ! -b "$SRC" ] && [ ! -f "$SRC" ]; then
    echo "ERROR: Source $SRC not found."
    exit 1
fi

# Check destination exists (block device or file path)
if [ -b "$DST" ]; then
    DST_SIZE=$(lsblk -b -n -o SIZE "$DST" 2>/dev/null | head -1)
    SRC_SIZE=$(lsblk -b -n -o SIZE "$SRC" 2>/dev/null | head -1 || stat -c%s "$SRC" 2>/dev/null || echo 0)
    if [ "${DST_SIZE:-0}" -lt "${SRC_SIZE:-0}" ] 2>/dev/null; then
        echo "WARNING: Destination ($DST) is smaller than source ($SRC)."
        echo "  Source: $(numfmt --to=iec ${SRC_SIZE})"
        echo "  Dest:   $(numfmt --to=iec ${DST_SIZE})"
        read -rp "Continue anyway? (yes/no): " confirm
        [ "$confirm" = "yes" ] || exit 1
    fi
    echo "=== Noosphere USB Clone ==="
    echo "Source: $SRC"
    echo "Dest:   $DST"
    echo
    echo "WARNING: All data on $DST will be permanently overwritten."
    read -rp "Type YES to confirm: " confirm
    [ "$confirm" = "YES" ] || { echo "Aborted."; exit 1; }
else
    echo "=== Noosphere USB -> Image File ==="
    echo "Source: $SRC"
    echo "Image:  $DST"
fi

# Unmount any mounted partitions on source/dest
for dev in "$SRC" "$DST"; do
    if [ -b "$dev" ]; then
        for part in $(lsblk -ln -o NAME "$dev" | tail -n +2); do
            umount "/dev/$part" 2>/dev/null || true
        done
    fi
done

# Determine size for progress
SRC_BYTES=$(lsblk -b -n -o SIZE "$SRC" 2>/dev/null | head -1 || stat -c%s "$SRC" 2>/dev/null || echo 0)

echo
echo "Cloning... (this takes several minutes for a 64GB drive)"
echo

# Use dd with status=progress; fall back to plain dd if pv isn't available
if command -v pv &>/dev/null; then
    pv -s "$SRC_BYTES" "$SRC" | dd of="$DST" bs=4M conv=fsync status=none
else
    dd if="$SRC" of="$DST" bs=4M conv=fsync status=progress
fi

echo
echo "Clone complete."

# Verify
if [ "$VERIFY" = "--verify" ] || [ "$VERIFY" = "-v" ]; then
    echo
    echo "Verifying (SHA256 checksum)..."
    SRC_SHA=$(dd if="$SRC" bs=4M status=none | sha256sum | awk '{print $1}')
    DST_SHA=$(dd if="$DST" bs=4M status=none | sha256sum | awk '{print $1}')
    if [ "$SRC_SHA" = "$DST_SHA" ]; then
        echo "✓ Checksums match: $SRC_SHA"
        echo "  Backup drive is a verified identical copy."
    else
        echo "✗ CHECKSUM MISMATCH  -  clone may be corrupt!"
        echo "  Source: $SRC_SHA"
        echo "  Dest:   $DST_SHA"
        exit 1
    fi
fi

echo
echo "=== Kit Redundancy Checklist ==="
echo "  ✓ Backup USB cloned: $DST"
echo "  - Label the backup drive: 'NOOSPHERE BACKUP $(date +%Y-%m-%d)'"
echo "  - Store separately from primary drive"
echo "  - Refresh this clone after each major update (git pull + re-run this script)"
echo "  - Power: ensure HP 3105m charger + a USB power bank are in the kit"
echo "  - Admin recovery: document the admin PIN in a sealed envelope with the kit"
echo "  - Test boot: verify the backup drive boots on the target hardware"
