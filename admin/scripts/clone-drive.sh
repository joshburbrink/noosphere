#!/bin/bash
echo "=== Available Block Devices ==="
lsblk -d -o NAME,SIZE,MODEL,TRAN | grep -v loop
echo ""
BOOT_DEV=$(findmnt -n -o SOURCE / | sed 's/p\?[0-9]*$//')
echo "Current boot device: $BOOT_DEV"
echo ""
read -rp "Clone FROM device (e.g. sdb, not sdb1): " SRC
read -rp "Clone TO device   (e.g. sdc, not sdc1): " DST
SRC="/dev/$SRC"
DST="/dev/$DST"
if [ ! -b "$SRC" ]; then echo "ERROR: $SRC not found."; exit 1; fi
if [ ! -b "$DST" ]; then echo "ERROR: $DST not found."; exit 1; fi
if [ "$SRC" = "$DST" ]; then echo "ERROR: Source and destination are the same."; exit 1; fi
SRC_SIZE=$(lsblk -d -o SIZE "$SRC" | tail -1 | tr -d ' ')
DST_SIZE=$(lsblk -d -o SIZE "$DST" | tail -1 | tr -d ' ')
echo ""
echo "  Source : $SRC  ($SRC_SIZE)"
echo "  Dest   : $DST  ($DST_SIZE)"
echo ""
echo "WARNING: ALL DATA ON $DST WILL BE DESTROYED."
read -rp "Type YES to confirm: " CONFIRM
if [ "$CONFIRM" != "YES" ]; then echo "Aborted."; exit 1; fi
echo "Cloning — may take 20-40 minutes for a 64GB drive..."
dd if="$SRC" of="$DST" bs=4M status=progress conv=fsync
sync
echo ""
echo "Clone complete."
