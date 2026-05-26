#!/bin/bash
# setup-storage.sh - external drive list/mount/unmount/format for Noosphere.
# Backs the admin Storage card (#91). Refuses to operate on the boot disk.
#
# Subcommands:
#   list                       JSON: all block devices + partitions + boot flag
#   mount <devnode> [<label>]  Mount partition; adds nofail fstab entry by UUID
#   unmount <devnode>          Unmount; removes its fstab entry
#   format <devnode>           Wipe + mkfs.ext4 -L noosphere-ext (DESTRUCTIVE)
#
# Devnode = a partition path like /dev/sda1. mkfs targets a partition, not the
# whole disk: an operator who needs to repartition can do it from a shell.

set -u
PATH=/usr/sbin:/usr/bin:/sbin:/bin

die()  { echo "{\"ok\":false,\"error\":$(printf '%s' "$*" | python3 -c 'import json,sys;print(json.dumps(sys.stdin.read()))')}" >&2; exit 1; }
ok()   { echo "{\"ok\":true,\"msg\":$(printf '%s' "$*" | python3 -c 'import json,sys;print(json.dumps(sys.stdin.read()))')}"; }

# Boot disk = parent kernel device backing /. Used to refuse destructive ops.
boot_part="$(findmnt -no SOURCE / 2>/dev/null)"
boot_disk=""
if [ -n "$boot_part" ]; then
    # /dev/sda2 -> /dev/sda; /dev/mmcblk0p2 -> /dev/mmcblk0; /dev/nvme0n1p2 -> /dev/nvme0n1
    name="$(basename "$boot_part")"
    parent="$(lsblk -no PKNAME "$boot_part" 2>/dev/null | head -1)"
    boot_disk="/dev/${parent:-$name}"
fi

is_boot() {
    local dev="$1"
    [ -z "$dev" ] && return 0
    [ "$dev" = "$boot_part" ] && return 0
    [ "$dev" = "$boot_disk" ] && return 0
    # any partition of the boot disk
    local p; p="$(lsblk -no PKNAME "$dev" 2>/dev/null | head -1)"
    [ -n "$p" ] && [ "/dev/$p" = "$boot_disk" ] && return 0
    return 1
}

validate_partition() {
    local dev="$1"
    [[ "$dev" =~ ^/dev/[a-zA-Z0-9]+$ ]] || die "invalid device path: $dev"
    [ -b "$dev" ] || die "not a block device: $dev"
    local type; type="$(lsblk -no TYPE "$dev" 2>/dev/null | head -1)"
    [ "$type" = "part" ] || die "not a partition (type=$type): $dev"
    is_boot "$dev" && die "refusing to touch boot disk: $dev"
}

case "${1:-}" in
list)
    # Emit JSON. lsblk -J is already JSON; we add a boot flag per node.
    export BOOT_PART="$boot_part" BOOT_DISK="$boot_disk"
    lsblk -J -b -o NAME,PATH,TYPE,SIZE,FSTYPE,LABEL,UUID,MOUNTPOINT,RM,MODEL \
        | python3 -c '
import json, sys, os
boot_part = os.environ.get("BOOT_PART","")
boot_disk = os.environ.get("BOOT_DISK","")
data = json.load(sys.stdin)
def walk(node, parent_is_boot=False):
    p = node.get("path","")
    is_boot = parent_is_boot or p == boot_part or p == boot_disk
    node["boot"] = is_boot
    for ch in node.get("children", []) or []:
        walk(ch, is_boot)
for n in data.get("blockdevices", []):
    walk(n)
print(json.dumps(data))
'
    ;;

mount)
    dev="${2:-}"; label="${3:-}"
    validate_partition "$dev"
    # mount point
    uuid="$(blkid -s UUID -o value "$dev" 2>/dev/null)"
    [ -z "$uuid" ] && die "no filesystem UUID on $dev (format it first)"
    fstype="$(blkid -s TYPE -o value "$dev" 2>/dev/null)"
    [ -z "$fstype" ] && die "no filesystem on $dev"
    # sanitize label for mountpoint name
    safe_label="$(printf '%s' "${label:-$uuid}" | tr -c 'A-Za-z0-9_-' '_' | cut -c1-32)"
    [ -z "$safe_label" ] && safe_label="$uuid"
    mp="/media/noosphere-${safe_label}"
    mkdir -p "$mp"
    # Already mounted somewhere?
    cur="$(findmnt -no TARGET "$dev" 2>/dev/null || true)"
    if [ -n "$cur" ] && [ "$cur" != "$mp" ]; then
        die "$dev already mounted at $cur"
    fi
    if ! mountpoint -q "$mp"; then
        mount -t "$fstype" -o "defaults,nofail" "$dev" "$mp" || die "mount failed"
    fi
    # fstab persistence (idempotent)
    fstab_line="UUID=$uuid $mp $fstype defaults,nofail 0 2  # noosphere-storage"
    if ! grep -q "^UUID=$uuid " /etc/fstab 2>/dev/null; then
        echo "$fstab_line" >> /etc/fstab
    fi
    chown www-data:www-data "$mp" 2>/dev/null || true
    ok "mounted $dev at $mp"
    ;;

unmount)
    dev="${2:-}"
    validate_partition "$dev"
    cur="$(findmnt -no TARGET "$dev" 2>/dev/null || true)"
    if [ -n "$cur" ]; then
        umount "$cur" || die "unmount failed (busy?)"
    fi
    # strip fstab line
    uuid="$(blkid -s UUID -o value "$dev" 2>/dev/null)"
    if [ -n "$uuid" ] && grep -q "^UUID=$uuid " /etc/fstab 2>/dev/null; then
        sed -i.bak "\|^UUID=$uuid .*# noosphere-storage$|d" /etc/fstab
    fi
    # try to remove the empty mountpoint
    [ -n "$cur" ] && rmdir "$cur" 2>/dev/null || true
    ok "unmounted $dev"
    ;;

format)
    dev="${2:-}"
    validate_partition "$dev"
    cur="$(findmnt -no TARGET "$dev" 2>/dev/null || true)"
    [ -n "$cur" ] && die "unmount $dev first (currently at $cur)"
    wipefs -a "$dev" >/dev/null 2>&1 || true
    mkfs.ext4 -F -L "noosphere-ext" "$dev" >/dev/null 2>&1 || die "mkfs.ext4 failed"
    ok "formatted $dev as ext4 (label=noosphere-ext)"
    ;;

*)
    echo "usage: $0 {list|mount <dev> [label]|unmount <dev>|format <dev>}" >&2
    exit 64
    ;;
esac
