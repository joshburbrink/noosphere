#!/bin/bash
DB="/var/lib/noosphere/registry.db"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BOOT_DEV=$(findmnt -n -o SOURCE / | sed 's/p\?[0-9]*$//')
TARGET_DEV=""
for dev in $(lsblk -d -o NAME,TRAN | awk '$2=="usb"{print $1}'); do
    if [ "/dev/$dev" != "$BOOT_DEV" ]; then
        TARGET_DEV="$dev"
        break
    fi
done
if [ -z "$TARGET_DEV" ]; then
    echo "No external USB drive detected. Plug one in and retry."
    exit 1
fi
PART=$(lsblk -o NAME,TYPE "/dev/$TARGET_DEV" | awk '$2=="part"{print $1; exit}' | tr -d ' └─├─')
mkdir -p /mnt/usb_backup
mount "/dev/$PART" /mnt/usb_backup 2>/dev/null || {
    echo "Could not mount /dev/$PART. May already be mounted or need formatting."
    exit 1
}
cp "$DB" "/mnt/usb_backup/registry_backup_$TIMESTAMP.db"
sync
umount /mnt/usb_backup
echo "Backed up to: registry_backup_$TIMESTAMP.db"
echo "USB can be safely removed."
