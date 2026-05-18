#!/bin/bash
# install-to-disk.sh — Install Noosphere directly to an internal drive.
#
# What it does:
#   1. Shows target drive info and requires explicit confirmation before touching anything
#   2. Partitions the drive (GPT + EFI, or MBR + BIOS boot — auto-detected)
#   3. Formats partitions and installs a minimal Debian 13 (trixie) base via debootstrap
#   4. Installs and configures GRUB bootloader
#   5. Drops noosphere-provision.sh into the new system as a first-boot service
#
# After first boot from the new drive, noosphere-provision.sh runs automatically
# and installs LAMP, Nextcloud, Kiwix, and all Noosphere modules.
#
# Usage:
#   bash install-to-disk.sh /dev/sda
#   bash install-to-disk.sh /dev/sda --efi     # force EFI
#   bash install-to-disk.sh /dev/sda --bios    # force BIOS/MBR
#
# Requirements:
#   - Run as root from the Noosphere boot USB (or any live Linux system)
#   - debootstrap must be installed: apt-get install debootstrap
#   - Internet access (for debootstrap and apt)
#   - Target drive: 32 GB minimum (64 GB+ recommended for ZIM files)

set -euo pipefail

##############################################################################
# Config
##############################################################################
DEBIAN_RELEASE="trixie"
DEBIAN_MIRROR="https://deb.debian.org/debian"
HOSTNAME="noosphere"
MOUNT="/mnt/noosphere-install"
REPO_URL="https://github.com/joshburbrink/noosphere.git"
PROVISION_SCRIPT="noosphere-provision.sh"

##############################################################################
# Helpers
##############################################################################
die()  { echo "ERROR: $*" >&2; exit 1; }
info() { echo -e "\033[1;36m>>> $*\033[0m"; }
warn() { echo -e "\033[1;33mWARN: $*\033[0m"; }
ok()   { echo -e "\033[1;32m  ✓ $*\033[0m"; }

require() {
    command -v "$1" &>/dev/null || die "$1 is not installed. Run: apt-get install $2"
}

##############################################################################
# Args
##############################################################################
DEVICE="${1:-}"
FORCE_MODE="${2:-}"

usage() {
    cat <<EOF
Usage: $0 <device> [--efi|--bios]

  device     Target block device, e.g. /dev/sda or /dev/nvme0n1
  --efi      Force EFI/GPT partition layout (default if /sys/firmware/efi exists)
  --bios     Force BIOS/MBR partition layout

Examples:
  $0 /dev/sda             # Auto-detect EFI vs BIOS
  $0 /dev/nvme0n1 --efi   # Force EFI on NVMe
  $0 /dev/sdb --bios      # Force BIOS/MBR
EOF
    exit 1
}

[[ -z "$DEVICE" ]] && usage

##############################################################################
# Safety checks
##############################################################################
[[ "$EUID" -ne 0 ]] && die "Must run as root."
[[ -b "$DEVICE" ]] || die "$DEVICE is not a block device."

# Refuse to wipe the currently running root device
CURRENT_ROOT=$(findmnt -n -o SOURCE / 2>/dev/null | sed 's/p\?[0-9]*$//' || true)
[[ "$DEVICE" == "$CURRENT_ROOT" ]] && die "$DEVICE is the current root device. Boot from a live USB first."

require debootstrap debootstrap
require sgdisk gdisk
require mkfs.ext4 e2fsprogs
require grub-install grub-common

##############################################################################
# Auto-detect firmware type
##############################################################################
if [[ "$FORCE_MODE" == "--efi" ]]; then
    LAYOUT="efi"
elif [[ "$FORCE_MODE" == "--bios" ]]; then
    LAYOUT="bios"
elif [[ -d /sys/firmware/efi ]]; then
    LAYOUT="efi"
else
    LAYOUT="bios"
fi

##############################################################################
# Show drive info and confirm
##############################################################################
echo
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║          NOOSPHERE — INSTALL TO DISK                        ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo
info "Target device: $DEVICE"
echo

# Show device details
lsblk -o NAME,SIZE,TYPE,LABEL,MOUNTPOINT "$DEVICE" 2>/dev/null || true
echo
echo "  Model : $(cat /sys/block/$(basename $DEVICE)/device/model 2>/dev/null | xargs || echo 'unknown')"
echo "  Size  : $(lsblk -b -n -o SIZE "$DEVICE" | head -1 | numfmt --to=iec)"
echo "  Layout: $LAYOUT ($([ "$LAYOUT" = "efi" ] && echo "GPT + EFI" || echo "MBR + BIOS boot"))"
echo
echo "  Debian : $DEBIAN_RELEASE"
echo "  Mirror : $DEBIAN_MIRROR"
echo

warn "ALL DATA ON $DEVICE WILL BE PERMANENTLY ERASED."
echo
read -rp "  Type the device path again to confirm (e.g. $DEVICE): " confirm_dev
[[ "$confirm_dev" == "$DEVICE" ]] || { echo "Aborted."; exit 1; }
read -rp "  Type YES to proceed: " confirm_yes
[[ "$confirm_yes" == "YES" ]] || { echo "Aborted."; exit 1; }

echo

##############################################################################
# Unmount any existing mounts on target
##############################################################################
info "Unmounting any existing mounts on $DEVICE..."
for part in $(lsblk -ln -o NAME "$DEVICE" | tail -n +2 | sort -r); do
    umount "/dev/$part" 2>/dev/null && echo "  unmounted /dev/$part" || true
done
swapoff -a 2>/dev/null || true

##############################################################################
# Partition
##############################################################################
info "Partitioning $DEVICE ($LAYOUT)..."

if [[ "$LAYOUT" == "efi" ]]; then
    # GPT: 512MB EFI + rest root
    sgdisk --zap-all "$DEVICE"
    sgdisk -n 1:0:+512M -t 1:ef00 -c 1:"EFI"  "$DEVICE"
    sgdisk -n 2:0:0     -t 2:8300 -c 2:"root" "$DEVICE"
    partprobe "$DEVICE"
    sleep 2
    # Figure out partition naming (sda1/sda2 vs nvme0n1p1/nvme0n1p2)
    if [[ "$DEVICE" =~ nvme|mmcblk ]]; then
        EFI_PART="${DEVICE}p1"
        ROOT_PART="${DEVICE}p2"
    else
        EFI_PART="${DEVICE}1"
        ROOT_PART="${DEVICE}2"
    fi
    mkfs.vfat -F32 -n EFI "$EFI_PART"
    ok "EFI partition: $EFI_PART"
else
    # MBR: 1MB BIOS boot gap + rest root
    sgdisk --zap-all "$DEVICE" 2>/dev/null || true
    parted -s "$DEVICE" mklabel msdos
    parted -s "$DEVICE" mkpart primary ext4 1MiB 100%
    parted -s "$DEVICE" set 1 boot on
    partprobe "$DEVICE"
    sleep 2
    if [[ "$DEVICE" =~ nvme|mmcblk ]]; then
        ROOT_PART="${DEVICE}p1"
    else
        ROOT_PART="${DEVICE}1"
    fi
    EFI_PART=""
fi

##############################################################################
# Format root
##############################################################################
info "Formatting root partition..."
mkfs.ext4 -L noosphere -F "$ROOT_PART"
ok "Root: $ROOT_PART (ext4)"

##############################################################################
# Mount
##############################################################################
info "Mounting..."
mkdir -p "$MOUNT"
mount "$ROOT_PART" "$MOUNT"
if [[ -n "$EFI_PART" ]]; then
    mkdir -p "$MOUNT/boot/efi"
    mount "$EFI_PART" "$MOUNT/boot/efi"
fi

##############################################################################
# Debootstrap
##############################################################################
info "Installing Debian $DEBIAN_RELEASE base (this takes 5–15 minutes)..."
debootstrap --arch=amd64 "$DEBIAN_RELEASE" "$MOUNT" "$DEBIAN_MIRROR"
ok "Debootstrap complete."

##############################################################################
# Bind mounts for chroot
##############################################################################
mount --bind /dev     "$MOUNT/dev"
mount --bind /dev/pts "$MOUNT/dev/pts"
mount --bind /proc    "$MOUNT/proc"
mount --bind /sys     "$MOUNT/sys"
[[ -d /sys/firmware/efi/efivars ]] && mount --bind /sys/firmware/efi/efivars "$MOUNT/sys/firmware/efi/efivars" || true
cp /etc/resolv.conf "$MOUNT/etc/resolv.conf"

cleanup() {
    info "Cleaning up mounts..."
    umount -lf "$MOUNT/sys/firmware/efi/efivars" 2>/dev/null || true
    umount -lf "$MOUNT/sys"   2>/dev/null || true
    umount -lf "$MOUNT/proc"  2>/dev/null || true
    umount -lf "$MOUNT/dev/pts" 2>/dev/null || true
    umount -lf "$MOUNT/dev"   2>/dev/null || true
    [[ -n "${EFI_PART:-}" ]] && umount -lf "$MOUNT/boot/efi" 2>/dev/null || true
    umount -lf "$MOUNT"       2>/dev/null || true
}
trap cleanup EXIT

##############################################################################
# Chroot: base system setup
##############################################################################
info "Configuring base system in chroot..."

# fstab
ROOT_UUID=$(blkid -s UUID -o value "$ROOT_PART")
{
    echo "UUID=$ROOT_UUID  /  ext4  errors=remount-ro  0  1"
    if [[ -n "$EFI_PART" ]]; then
        EFI_UUID=$(blkid -s UUID -o value "$EFI_PART")
        echo "UUID=$EFI_UUID  /boot/efi  vfat  umask=0077  0  1"
    fi
    echo "tmpfs  /tmp  tmpfs  defaults,size=256m  0  0"
} > "$MOUNT/etc/fstab"

# Hostname
echo "$HOSTNAME" > "$MOUNT/etc/hostname"
cat > "$MOUNT/etc/hosts" <<EOF
127.0.0.1   localhost
127.0.1.1   $HOSTNAME
::1         localhost ip6-localhost ip6-loopback
EOF

# Locale + timezone
chroot "$MOUNT" bash -c "
    export DEBIAN_FRONTEND=noninteractive
    apt-get update -qq
    apt-get install -y locales tzdata console-setup keyboard-configuration
    sed -i 's/# en_US.UTF-8/en_US.UTF-8/' /etc/locale.gen
    locale-gen
    update-locale LANG=en_US.UTF-8
    ln -sf /usr/share/zoneinfo/America/Indiana/Indianapolis /etc/localtime
    dpkg-reconfigure -f noninteractive tzdata
"
ok "Locale and timezone set."

# Kernel + essential packages
info "Installing kernel and core packages..."
chroot "$MOUNT" bash -c "
    export DEBIAN_FRONTEND=noninteractive
    apt-get install -y \
        linux-image-amd64 linux-headers-amd64 \
        openssh-server sudo curl wget git vim tmux \
        net-tools nmap tcpdump iproute2 iputils-ping \
        parted dosfstools e2fsprogs \
        htop ncdu smartmontools \
        firmware-linux firmware-linux-nonfree \
        firmware-iwlwifi firmware-realtek \
        wpasupplicant iw wireless-tools \
        network-manager \
        debootstrap
"
ok "Kernel and core packages installed."

# Root password
info "Setting temporary root password..."
chroot "$MOUNT" bash -c "echo 'root:noosphere' | chpasswd"
warn "Root password set to 'noosphere' — change it after first login with: passwd"

# SSH: allow root login temporarily
sed -i 's/^#\?PermitRootLogin.*/PermitRootLogin yes/' "$MOUNT/etc/ssh/sshd_config"

# apt sources
cat > "$MOUNT/etc/apt/sources.list" <<EOF
deb $DEBIAN_MIRROR $DEBIAN_RELEASE main contrib non-free non-free-firmware
deb $DEBIAN_MIRROR $DEBIAN_RELEASE-updates main contrib non-free non-free-firmware
deb https://security.debian.org/debian-security $DEBIAN_RELEASE-security main contrib non-free non-free-firmware
EOF

##############################################################################
# GRUB
##############################################################################
info "Installing GRUB bootloader..."
if [[ "$LAYOUT" == "efi" ]]; then
    chroot "$MOUNT" apt-get install -y grub-efi-amd64
    chroot "$MOUNT" grub-install --target=x86_64-efi --efi-directory=/boot/efi --bootloader-id=noosphere --recheck
else
    chroot "$MOUNT" apt-get install -y grub-pc
    chroot "$MOUNT" grub-install --target=i386-pc --recheck "$DEVICE"
fi

# GRUB config
cat > "$MOUNT/etc/default/grub" <<'EOF'
GRUB_DEFAULT=0
GRUB_TIMEOUT=5
GRUB_DISTRIBUTOR="Noosphere"
GRUB_CMDLINE_LINUX_DEFAULT="quiet"
GRUB_CMDLINE_LINUX=""
GRUB_TERMINAL=console
EOF

chroot "$MOUNT" update-grub
ok "GRUB installed and configured."

##############################################################################
# Copy provision script + set up first-boot service
##############################################################################
info "Installing provisioning script for first boot..."

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [[ -f "$SCRIPT_DIR/$PROVISION_SCRIPT" ]]; then
    cp "$SCRIPT_DIR/$PROVISION_SCRIPT" "$MOUNT/root/$PROVISION_SCRIPT"
else
    warn "$PROVISION_SCRIPT not found alongside this script — you will need to run it manually."
    warn "Get it from: $REPO_URL"
fi

# Systemd first-boot service
cat > "$MOUNT/etc/systemd/system/noosphere-firstboot.service" <<'SVCEOF'
[Unit]
Description=Noosphere First-Boot Provisioning
After=network-online.target
Wants=network-online.target
ConditionPathExists=/root/noosphere-provision.sh

[Service]
Type=oneshot
ExecStart=/bin/bash /root/noosphere-provision.sh --unattended
ExecStartPost=/bin/systemctl disable noosphere-firstboot.service
StandardOutput=journal+console
RemainAfterExit=yes
TimeoutStartSec=3600

[Install]
WantedBy=multi-user.target
SVCEOF

chroot "$MOUNT" systemctl enable noosphere-firstboot.service
ok "First-boot service registered."

##############################################################################
# Unmount (trap will handle it, but do it cleanly now)
##############################################################################
info "Syncing and unmounting..."
sync
umount "$MOUNT/sys/firmware/efi/efivars" 2>/dev/null || true
umount "$MOUNT/sys"
umount "$MOUNT/proc"
umount "$MOUNT/dev/pts"
umount "$MOUNT/dev"
[[ -n "$EFI_PART" ]] && umount "$MOUNT/boot/efi" || true
umount "$MOUNT"
trap - EXIT
ok "Unmounted cleanly."

##############################################################################
# Done
##############################################################################
echo
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║  INSTALLATION COMPLETE                                       ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo
ok "Debian $DEBIAN_RELEASE base installed on $DEVICE"
ok "GRUB bootloader installed ($LAYOUT)"
ok "First-boot provisioning service registered"
echo
echo "Next steps:"
echo "  1. Remove the boot USB and power on the machine."
echo "  2. Noosphere provisioning starts automatically on first boot."
echo "     It will take 15–30 minutes (requires internet access)."
echo "  3. When complete, connect to the machine on port 80."
echo "  4. Change the root password: passwd"
echo "  5. Run: /var/www/noosphere/scripts/setup-credentials.sh"
echo "  6. Configure WiFi AP: /var/www/noosphere/scripts/setup-hostapd.sh configure"
echo "  7. Download ZIM files: /var/www/noosphere/scripts/download-zim.sh"
echo
echo "  SSH (first login): ssh root@<ip>  (password: noosphere)"
echo "  Admin panel: http://<ip>/admin/   (keyboard shortcut: aaa)"
echo
