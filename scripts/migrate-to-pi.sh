#!/bin/bash
# migrate-to-pi.sh  -  Build a ready-to-boot Noosphere drive for a Raspberry Pi 5.
#
# Runs on any Linux laptop/desktop where the NEW drive is plugged in.  It:
#   1. Flashes Raspberry Pi OS Lite (arm64) onto the target drive
#   2. Enables headless SSH + a login user
#   3. Drops the arm64-aware noosphere-provision.sh in as a first-boot service
#   4. Stages your existing Noosphere data onto the drive (optional)
#
# Then: plug the drive into the Pi 5 and power on.  On first boot it builds the
# whole stack natively (no x86->ARM emulation) and imports the staged data.
#
# Data source is one of:
#   --from-server <ip>     rsync data from the live server over SSH (it stays up)
#   --from-disk   <mount>  copy data from the old Noosphere drive mounted here
#   --no-data              fresh Pi, no migration
#
# Usage:
#   sudo ./migrate-to-pi.sh /dev/sdX --from-server 192.168.2.166
#   sudo ./migrate-to-pi.sh /dev/sdX --from-disk /mnt/old-noosphere
#   sudo ./migrate-to-pi.sh /dev/sdX --no-data --image ~/raspios-lite-arm64.img.xz
#
# Requires (on this machine): dd, lsblk, partprobe, rsync, xz; ssh for --from-server.

set -euo pipefail

##############################################################################
# Config / defaults
##############################################################################
# Canonical "latest" redirect for Raspberry Pi OS Lite arm64.  IMPORTANT: this
# must resolve to a Trixie-based image (PHP 8.4).  Override with --image if the
# latest is still Bookworm (PHP 8.2) - the stack requires 8.4.
IMAGE_URL="https://downloads.raspberrypi.com/raspios_lite_arm64_latest"

PI_USER="noosphere"
PI_PASS="noosphere"          # CHANGE on first login - warned about below
PI_HOSTNAME="noosphere"
DATA_SOURCE=""               # server|disk|none
SERVER_IP=""
DISK_MOUNT=""
IMAGE_PATH=""                # local image; if empty, download IMAGE_URL
ASSUME_YES=0

STAGE_REL="var/lib/noosphere-migrate"   # staging dir on the new rootfs

# rsync excludes for /var/lib/noosphere (transient / regenerable / dead)
NOOSPHERE_EXCLUDES=(
    "--exclude=pkg-cache/"
    "--exclude=netboot/"
    "--exclude=region-builds/"
    "--exclude=*-migrate/"
    "--exclude=downloads/tmp/"
    "--exclude=nextcloud-data/"   # Nextcloud was retired - don't carry it forward
)

##############################################################################
# Helpers
##############################################################################
die()  { echo -e "\033[1;31mERROR:\033[0m $*" >&2; exit 1; }
info() { echo -e "\n\033[1;36m>>> $*\033[0m"; }
ok()   { echo -e "\033[1;32m  ✓ $*\033[0m"; }
warn() { echo -e "\033[1;33mWARN:\033[0m $*" >&2; }

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"

# Mount points + temp, cleaned up on exit
BOOT_MNT=""; ROOT_MNT=""; IMG_TMP=""
cleanup() {
    [ -n "$BOOT_MNT" ] && mountpoint -q "$BOOT_MNT" 2>/dev/null && umount "$BOOT_MNT" 2>/dev/null || true
    [ -n "$ROOT_MNT" ] && mountpoint -q "$ROOT_MNT" 2>/dev/null && umount "$ROOT_MNT" 2>/dev/null || true
    [ -n "$BOOT_MNT" ] && rmdir "$BOOT_MNT" 2>/dev/null || true
    [ -n "$ROOT_MNT" ] && rmdir "$ROOT_MNT" 2>/dev/null || true
    [ -n "$IMG_TMP" ] && [ -f "$IMG_TMP" ] && rm -f "$IMG_TMP" 2>/dev/null || true
}
trap cleanup EXIT

# partition node for a device: /dev/sdb -> /dev/sdb1 ; /dev/mmcblk0 -> /dev/mmcblk0p1
part_node() {
    local dev="$1" num="$2"
    if [[ "$dev" =~ [0-9]$ ]]; then echo "${dev}p${num}"; else echo "${dev}${num}"; fi
}

##############################################################################
# Args
##############################################################################
TARGET=""
while [ $# -gt 0 ]; do
    case "$1" in
        --from-server) DATA_SOURCE="server"; SERVER_IP="${2:-}"; shift 2 ;;
        --from-disk)   DATA_SOURCE="disk";   DISK_MOUNT="${2:-}"; shift 2 ;;
        --no-data)     DATA_SOURCE="none";   shift ;;
        --image)       IMAGE_PATH="${2:-}";  shift 2 ;;
        --user)        PI_USER="${2:-}";     shift 2 ;;
        --password)    PI_PASS="${2:-}";     shift 2 ;;
        --hostname)    PI_HOSTNAME="${2:-}"; shift 2 ;;
        -y|--yes)      ASSUME_YES=1;         shift ;;
        -h|--help)     grep '^#' "$0" | sed 's/^# \?//'; exit 0 ;;
        -*)            die "Unknown option: $1" ;;
        *)             [ -z "$TARGET" ] && TARGET="$1" || die "Unexpected arg: $1"; shift ;;
    esac
done

[ "$(id -u)" -eq 0 ] || die "Must run as root (sudo) - flashing writes to a block device."
[ -n "$TARGET" ] || die "No target device given. Usage: $0 /dev/sdX --from-server <ip>"
[ -n "$DATA_SOURCE" ] || die "Pick a data source: --from-server <ip> | --from-disk <mount> | --no-data"
[ "$DATA_SOURCE" = "server" ] && [ -z "$SERVER_IP" ] && die "--from-server needs an IP"
[ "$DATA_SOURCE" = "disk" ]   && [ -z "$DISK_MOUNT" ] && die "--from-disk needs a mountpoint"
[ "$DATA_SOURCE" = "disk" ]   && [ ! -d "$DISK_MOUNT/var/lib/noosphere" ] && \
    die "$DISK_MOUNT/var/lib/noosphere not found - is the old drive mounted there?"

for t in dd lsblk rsync; do command -v "$t" >/dev/null || die "missing required tool: $t"; done
[ "$DATA_SOURCE" = "server" ] && { command -v ssh >/dev/null || die "missing ssh"; }

##############################################################################
# Safety: validate the target block device
##############################################################################
[ -b "$TARGET" ] || die "$TARGET is not a block device"

# Refuse the disk that hosts / (don't nuke the machine you're running on)
ROOT_SRC="$(findmnt -no SOURCE / || true)"
ROOT_DISK="$(lsblk -no PKNAME "$ROOT_SRC" 2>/dev/null | head -1 || true)"
TARGET_BASE="$(basename "$TARGET")"
[ -n "$ROOT_DISK" ] && [ "/dev/$ROOT_DISK" = "$TARGET" ] && die "$TARGET is this system's root disk - refusing."
[ -n "$ROOT_DISK" ] && [ "$ROOT_DISK" = "$TARGET_BASE" ] && die "$TARGET is this system's root disk - refusing."

# Refuse if any partition of the target is mounted at a system path
while read -r mp; do
    case "$mp" in /|/home|/boot|/boot/efi) die "$TARGET has a partition mounted at $mp - refusing." ;; esac
done < <(lsblk -nro MOUNTPOINT "$TARGET" 2>/dev/null | grep -v '^$' || true)

# Unmount any currently-mounted partitions of the target (idle data drive)
while read -r p; do
    [ -n "$p" ] && umount "/dev/$p" 2>/dev/null || true
done < <(lsblk -nro NAME "$TARGET" 2>/dev/null | tail -n +2)

echo ""
echo "=== Target drive ==="
lsblk -o NAME,SIZE,MODEL,TRAN,MOUNTPOINT "$TARGET" || true
REMOVABLE="$(cat "/sys/block/$TARGET_BASE/removable" 2>/dev/null || echo '?')"
[ "$REMOVABLE" = "1" ] || warn "Target does not report as removable (removable=$REMOVABLE). Double-check this is the right drive!"
echo ""
echo "This will ERASE ALL DATA on $TARGET and write a fresh Raspberry Pi OS image."
if [ "$ASSUME_YES" -ne 1 ]; then
    read -rp "Type the device path to confirm ($TARGET): " confirm
    [ "$confirm" = "$TARGET" ] || die "Confirmation did not match. Aborted."
fi

##############################################################################
# 1. Obtain the image
##############################################################################
info "Preparing Raspberry Pi OS image..."
if [ -n "$IMAGE_PATH" ]; then
    [ -f "$IMAGE_PATH" ] || die "Image not found: $IMAGE_PATH"
    SRC_IMG="$IMAGE_PATH"
    ok "Using local image: $SRC_IMG"
else
    command -v curl >/dev/null || die "curl needed to download the image (or pass --image)"
    IMG_TMP="$(mktemp --suffix=.img.xz)"
    info "Downloading $IMAGE_URL ..."
    curl -fL --retry 3 -o "$IMG_TMP" "$IMAGE_URL" || die "Image download failed"
    SRC_IMG="$IMG_TMP"
    ok "Downloaded image."
fi

##############################################################################
# 2. Flash to the target
##############################################################################
info "Flashing image to $TARGET (this takes a while)..."
case "$SRC_IMG" in
    *.xz)  command -v xz >/dev/null || die "xz needed to decompress the image"
           xz -dc "$SRC_IMG" | dd of="$TARGET" bs=4M conv=fsync status=progress ;;
    *.img|*.iso) dd if="$SRC_IMG" of="$TARGET" bs=4M conv=fsync status=progress ;;
    *.gz)  zcat "$SRC_IMG" | dd of="$TARGET" bs=4M conv=fsync status=progress ;;
    *)     die "Unrecognized image format: $SRC_IMG (expected .img.xz/.img/.gz)" ;;
esac
sync
command -v partprobe >/dev/null && partprobe "$TARGET" || blockdev --rereadpt "$TARGET" 2>/dev/null || true
sleep 2
ok "Image written."

BOOT_PART="$(part_node "$TARGET" 1)"
ROOT_PART="$(part_node "$TARGET" 2)"
[ -b "$BOOT_PART" ] || die "boot partition $BOOT_PART not found after flash"
[ -b "$ROOT_PART" ] || die "root partition $ROOT_PART not found after flash"

##############################################################################
# 3. Mount boot + root
##############################################################################
info "Mounting partitions..."
BOOT_MNT="$(mktemp -d)"; ROOT_MNT="$(mktemp -d)"
mount "$BOOT_PART" "$BOOT_MNT" || die "could not mount boot partition"
mount "$ROOT_PART" "$ROOT_MNT" || die "could not mount root partition"
ok "Mounted bootfs + rootfs."

##############################################################################
# 4. Headless: enable SSH + user + hostname
##############################################################################
info "Configuring headless boot (SSH, user, hostname)..."
touch "$BOOT_MNT/ssh"

# Pi OS Bookworm+ has no default user; userconf.txt seeds one (user:hashed-pass)
if command -v openssl >/dev/null; then
    PHASH="$(echo "$PI_PASS" | openssl passwd -6 -stdin)"
    echo "${PI_USER}:${PHASH}" > "$BOOT_MNT/userconf.txt"
    ok "Login user '$PI_USER' set (password: '$PI_PASS' - CHANGE IT after first login)."
else
    warn "openssl missing - cannot preset user. You may need a monitor+keyboard for first login."
fi

# Hostname
echo "$PI_HOSTNAME" > "$ROOT_MNT/etc/hostname"
sed -i "s/127.0.1.1.*/127.0.1.1\t$PI_HOSTNAME/" "$ROOT_MNT/etc/hosts" 2>/dev/null || \
    echo -e "127.0.1.1\t$PI_HOSTNAME" >> "$ROOT_MNT/etc/hosts"

##############################################################################
# 5. Install provision + first-boot service (enabled via wants-symlink,
#    because we cannot run ARM systemctl from this x86 host)
##############################################################################
info "Installing first-boot provisioning..."
install -m 755 "$SCRIPT_DIR/noosphere-provision.sh"   "$ROOT_MNT/root/noosphere-provision.sh"
install -m 755 "$SCRIPT_DIR/migrate-import.sh"        "$ROOT_MNT/root/noosphere-migrate-import.sh"

cat > "$ROOT_MNT/etc/systemd/system/noosphere-firstboot.service" <<'SVCEOF'
[Unit]
Description=Noosphere First-Boot Provisioning (Raspberry Pi)
After=network-online.target
Wants=network-online.target
ConditionPathExists=/root/noosphere-provision.sh

[Service]
Type=oneshot
RemainAfterExit=yes
TimeoutStartSec=5400
StandardOutput=journal+console
StandardError=journal+console
ExecStart=/bin/bash /root/noosphere-provision.sh --unattended --no-nextcloud
ExecStartPost=/bin/bash /root/noosphere-migrate-import.sh
ExecStartPost=/bin/systemctl disable noosphere-firstboot.service

[Install]
WantedBy=multi-user.target
SVCEOF

mkdir -p "$ROOT_MNT/etc/systemd/system/multi-user.target.wants"
ln -sf ../noosphere-firstboot.service \
    "$ROOT_MNT/etc/systemd/system/multi-user.target.wants/noosphere-firstboot.service"
ok "First-boot service registered (will self-disable after running)."

##############################################################################
# 6. Stage data
##############################################################################
STAGE="$ROOT_MNT/$STAGE_REL"
if [ "$DATA_SOURCE" = "none" ]; then
    info "Skipping data migration (--no-data)."
else
    info "Staging Noosphere data ($DATA_SOURCE) onto the new drive..."
    mkdir -p "$STAGE/fs/var/lib/noosphere" \
             "$STAGE/fs/var/lib/kiwix" \
             "$STAGE/fs/var/lib/mbtiles" \
             "$STAGE/fs/var/www/noosphere/maps"

    if [ "$DATA_SOURCE" = "disk" ]; then
        M="$DISK_MOUNT"
        rsync -aH --info=progress2 "${NOOSPHERE_EXCLUDES[@]}" "$M/var/lib/noosphere/" "$STAGE/fs/var/lib/noosphere/"
        [ -d "$M/var/lib/kiwix" ]   && rsync -aH --info=progress2 "$M/var/lib/kiwix/"   "$STAGE/fs/var/lib/kiwix/"   || true
        [ -d "$M/var/lib/mbtiles" ] && rsync -aH --info=progress2 "$M/var/lib/mbtiles/" "$STAGE/fs/var/lib/mbtiles/" || true
        [ -d "$M/var/www/noosphere/maps" ] && rsync -aH --info=progress2 "$M/var/www/noosphere/maps/" "$STAGE/fs/var/www/noosphere/maps/" || true
    else
        S="root@$SERVER_IP"
        RSH="ssh -o StrictHostKeyChecking=accept-new"
        rsync -aH --info=progress2 -e "$RSH" "${NOOSPHERE_EXCLUDES[@]}" "$S:/var/lib/noosphere/" "$STAGE/fs/var/lib/noosphere/"
        rsync -aH --info=progress2 -e "$RSH" "$S:/var/lib/kiwix/"   "$STAGE/fs/var/lib/kiwix/"   || true
        rsync -aH --info=progress2 -e "$RSH" "$S:/var/lib/mbtiles/" "$STAGE/fs/var/lib/mbtiles/" || true
        rsync -aH --info=progress2 -e "$RSH" "$S:/var/www/noosphere/maps/" "$STAGE/fs/var/www/noosphere/maps/" || true
    fi
    STAGED_MB="$(du -sm "$STAGE" 2>/dev/null | cut -f1 || echo '?')"
    ok "Data staged (${STAGED_MB} MB). It will be imported on first Pi boot."
fi

##############################################################################
# 7. Done
##############################################################################
info "Finalizing..."
sync
umount "$BOOT_MNT"; umount "$ROOT_MNT"
BOOT_MNT=""; ROOT_MNT=""
ok "Drive prepared and unmounted."

echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║  NOOSPHERE PI DRIVE READY                                     ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""
echo "Next steps:"
echo "  1. Move $TARGET to the Raspberry Pi 5 and power on."
echo "     (Pi 5 must have USB/NVMe boot enabled in its bootloader - newer"
echo "      units do by default; if not, boot once from SD to update EEPROM.)"
echo "  2. First boot self-provisions: 15-40 min, NEEDS INTERNET (apt + Kiwix)."
echo "     Watch on a monitor, or SSH in: ssh ${PI_USER}@${PI_HOSTNAME}.local"
echo "  3. When done, the hub is on port 80. Set the AP up:"
echo "       sudo setup-hostapd.sh configure   # pick onboard wlan0"
echo "       sudo setup-hostapd.sh enable"
[ "$DATA_SOURCE" != "none" ] && \
echo "  4. Your data (DBs, photos, ZIMs, tiles, maps) is imported automatically."
echo ""
warn "Change the '$PI_USER' password after first login: passwd"
