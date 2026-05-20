#!/bin/bash
# Noosphere  -  PXE / network boot installer setup
# Usage: setup-pxe.sh {configure|enable|disable|status|refresh}
#
# configure  -  install tftpd-hpa + pxelinux + syslinux + ipxe, stage boot files
#               (kernel/initrd, menus, preseed, provision script). Uses the
#               offline pkg-cache from cache-pxe-boot.sh when present.
# enable     -  start tftpd-hpa, add dnsmasq PXE/dhcp-boot options, restart dnsmasq.
# disable    -  remove dnsmasq PXE options, stop tftpd-hpa.
# status     -  show tftpd state, staged boot files, machines served.
# refresh    -  re-download the Debian installer kernel + initrd (needs internet).
#
# Target machines boot over the network and get a menu:
#   [Install Noosphere]  [Boot local drive]  [Rescue]
# Install runs an automated Debian install + noosphere-provision.sh on first boot.
#
# NOTE: PXE only works when THIS box hands out DHCP (hostapd AP mode, or a direct
# ethernet link where dnsmasq serves DHCP). In external-router mode the GL.iNet
# router is the DHCP server and would need its own PXE options - connect the
# target to the Noosphere AP instead.

set -e

TFTP_ROOT="/srv/tftp"
NETBOOT_WEB="/var/lib/noosphere/netboot"
DNSMASQ_DROP="/etc/dnsmasq.d/noosphere-pxe.conf"
PXE_CONF="/etc/noosphere/pxe.conf"
NET_CONF="/etc/noosphere/network.conf"
TFTPD_DEFAULT="/etc/default/tftpd-hpa"
PKG_CACHE="/var/lib/noosphere/pkg-cache/pxe"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_SCRIPTS="/var/www/noosphere/scripts"
# Templates + provision script live in the repo. When this script is installed to
# /usr/local/bin its own dir has no templates, so fall back to the repo path.
if [ -d "$SCRIPT_DIR/pxe" ]; then
    TPL_DIR="$SCRIPT_DIR/pxe"
else
    TPL_DIR="$REPO_SCRIPTS/pxe"
fi
if [ -f "$SCRIPT_DIR/noosphere-provision.sh" ]; then
    PROVISION_SRC="$SCRIPT_DIR/noosphere-provision.sh"
else
    PROVISION_SRC="$REPO_SCRIPTS/noosphere-provision.sh"
fi

# Debian trixie amd64 netboot installer kernel + initrd
NETBOOT_BASE="https://deb.debian.org/debian/dists/trixie/main/installer-amd64/current/images/netboot/debian-installer/amd64"
KERNEL_URL="$NETBOOT_BASE/linux"
INITRD_URL="$NETBOOT_BASE/initrd.gz"

err()  { echo "ERROR: $*" >&2; exit 1; }
info() { echo "  $*"; }

[ "$EUID" -eq 0 ] || err "Must run as root."
mkdir -p /etc/noosphere

# ── server IP: where targets fetch preseed/provision over HTTP ─────────────────
detect_server_ip() {
    # 1) explicit override in pxe.conf
    if [ -f "$PXE_CONF" ]; then
        local s; s=$(grep -E '^SERVER_IP=' "$PXE_CONF" 2>/dev/null | head -1 | cut -d= -f2)
        if [ -n "$s" ]; then echo "$s"; return; fi
    fi
    # 2) hostapd AP mode -> AP IP (this box is the DHCP/boot server)
    if [ -f "$NET_CONF" ]; then
        # shellcheck disable=SC1090
        source "$NET_CONF"
        if [ "${NETWORK_MODE:-}" = "hostapd" ] && [ -n "${AP_IP:-}" ]; then
            echo "$AP_IP"; return
        fi
    fi
    # 3) external router default
    echo "192.168.8.2"
}

# ── package install (offline cache first, then apt) ───────────────────────────
ensure_packages() {
    local pkgs="tftpd-hpa pxelinux syslinux-common ipxe wget"
    local missing=0
    command -v in.tftpd >/dev/null 2>&1 || missing=1
    [ -f /usr/lib/PXELINUX/pxelinux.0 ] || missing=1
    [ -d /usr/lib/ipxe ] || missing=1
    [ "$missing" -eq 0 ] && { info "Boot packages already installed."; return; }

    info "Installing boot packages ($pkgs)..."
    if [ -d "$PKG_CACHE" ] && ls "$PKG_CACHE"/*.deb >/dev/null 2>&1; then
        info "Using offline package cache at $PKG_CACHE"
        if [ ! -f "$PKG_CACHE/Packages" ]; then
            ( cd "$PKG_CACHE" && command -v dpkg-scanpackages >/dev/null 2>&1 \
                && dpkg-scanpackages . /dev/null > Packages 2>/dev/null ) || true
        fi
        echo "deb [trusted=yes] file://$PKG_CACHE ./" > /etc/apt/sources.list.d/noosphere-pxe-cache.list
        apt-get update -o Dir::Etc::sourcelist="sources.list.d/noosphere-pxe-cache.list" \
            -o Dir::Etc::sourceparts="-" -o APT::Get::List-Cleanup="0" 2>/dev/null || true
    fi
    DEBIAN_FRONTEND=noninteractive apt-get install -y $pkgs \
        || err "Package install failed. Run scripts/cache-pxe-boot.sh while online first."
}

# ── copy a file from the first existing candidate path ────────────────────────
copy_first() {
    local dest="$1"; shift
    local c
    for c in "$@"; do
        if [ -f "$c" ]; then install -m 644 "$c" "$dest"; return 0; fi
    done
    return 1
}

# ── download kernel + initrd (offline cache first, then internet) ─────────────
stage_kernel_initrd() {
    mkdir -p "$TFTP_ROOT/noosphere"
    local got_k=0 got_i=0
    if [ -f "$PKG_CACHE/vmlinuz" ] && [ -f "$PKG_CACHE/initrd.gz" ]; then
        info "Staging kernel + initrd from offline cache..."
        install -m 644 "$PKG_CACHE/vmlinuz"  "$TFTP_ROOT/noosphere/vmlinuz"
        install -m 644 "$PKG_CACHE/initrd.gz" "$TFTP_ROOT/noosphere/initrd.gz"
        got_k=1; got_i=1
    else
        info "Downloading Debian installer kernel + initrd (needs internet)..."
        if wget -q -O "$TFTP_ROOT/noosphere/vmlinuz" "$KERNEL_URL"; then got_k=1; fi
        if wget -q -O "$TFTP_ROOT/noosphere/initrd.gz" "$INITRD_URL"; then got_i=1; fi
    fi
    if [ "$got_k" -ne 1 ] || [ "$got_i" -ne 1 ]; then
        rm -f "$TFTP_ROOT/noosphere/vmlinuz" "$TFTP_ROOT/noosphere/initrd.gz" 2>/dev/null || true
        err "Could not obtain kernel/initrd. Run scripts/cache-pxe-boot.sh while online, or retry with internet."
    fi
    # Mirror to the web netboot dir for iPXE (HTTP) installs
    mkdir -p "$NETBOOT_WEB"
    install -m 644 "$TFTP_ROOT/noosphere/vmlinuz"  "$NETBOOT_WEB/vmlinuz"
    install -m 644 "$TFTP_ROOT/noosphere/initrd.gz" "$NETBOOT_WEB/initrd.gz"
}

# ── stage a template with @@SERVER_IP@@ substituted ───────────────────────────
stage_template() {
    local src="$1" dest="$2" ip="$3"
    [ -f "$src" ] || err "Template not found: $src"
    sed "s|@@SERVER_IP@@|$ip|g" "$src" > "$dest"
}

# ── configure ─────────────────────────────────────────────────────────────────
cmd_configure() {
    echo ""
    echo "=== Noosphere PXE / Network Boot configuration ==="
    echo ""

    local ip; ip=$(detect_server_ip)
    info "Server IP (targets fetch installer over HTTP from here): $ip"

    ensure_packages

    info "Creating directories..."
    mkdir -p "$TFTP_ROOT/pxelinux.cfg" "$TFTP_ROOT/noosphere" "$NETBOOT_WEB"

    # Legacy BIOS boot loader + menu modules
    info "Staging BIOS boot loader (pxelinux)..."
    copy_first "$TFTP_ROOT/pxelinux.0" \
        /usr/lib/PXELINUX/pxelinux.0 /usr/lib/syslinux/pxelinux.0 \
        || err "pxelinux.0 not found (pxelinux package)."
    copy_first "$TFTP_ROOT/menu.c32" \
        /usr/lib/syslinux/modules/bios/menu.c32 /usr/lib/syslinux/menu.c32 \
        || info "WARN: menu.c32 not found - BIOS menu may not render."
    copy_first "$TFTP_ROOT/ldlinux.c32" \
        /usr/lib/syslinux/modules/bios/ldlinux.c32 \
        || info "WARN: ldlinux.c32 not found."
    copy_first "$TFTP_ROOT/libutil.c32" \
        /usr/lib/syslinux/modules/bios/libutil.c32 \
        || info "WARN: libutil.c32 not found."

    # UEFI boot loader (iPXE)
    info "Staging UEFI boot loader (iPXE)..."
    copy_first "$TFTP_ROOT/ipxe.efi" \
        /usr/lib/ipxe/ipxe.efi /usr/lib/ipxe/snponly.efi \
        || info "WARN: ipxe.efi not found (ipxe package) - UEFI boot disabled."

    # Kernel + initrd
    stage_kernel_initrd

    # Boot menus + preseed + provision script
    info "Staging boot menus and preseed (server IP $ip)..."
    stage_template "$TPL_DIR/pxelinux.cfg.default" "$TFTP_ROOT/pxelinux.cfg/default" "$ip"
    stage_template "$TPL_DIR/boot.ipxe"            "$NETBOOT_WEB/boot.ipxe"          "$ip"
    stage_template "$TPL_DIR/noosphere-preseed.cfg" "$NETBOOT_WEB/noosphere-preseed.cfg" "$ip"

    if [ -f "$PROVISION_SRC" ]; then
        install -m 644 "$PROVISION_SRC" "$NETBOOT_WEB/noosphere-provision.sh"
    else
        info "WARN: noosphere-provision.sh not found at $PROVISION_SRC - copy it into $NETBOOT_WEB manually."
    fi

    # Web dir readable by nginx
    chown -R www-data:www-data "$NETBOOT_WEB" 2>/dev/null || true

    # tftpd-hpa config
    info "Configuring tftpd-hpa..."
    cat > "$TFTPD_DEFAULT" <<EOF
# Noosphere PXE  -  managed by setup-pxe.sh
TFTP_USERNAME="tftp"
TFTP_DIRECTORY="$TFTP_ROOT"
TFTP_ADDRESS=":69"
TFTP_OPTIONS="--secure"
EOF

    # Persist state
    cat > "$PXE_CONF" <<EOF
SERVER_IP=$ip
CONFIGURED=1
PXE_ENABLED=${PXE_ENABLED:-0}
EOF

    echo ""
    info "PXE configured. Boot files staged in $TFTP_ROOT and $NETBOOT_WEB."
    echo ""
    echo "Next: setup-pxe.sh enable    to start serving network boot."
}

# ── enable ────────────────────────────────────────────────────────────────────
cmd_enable() {
    [ -f "$PXE_CONF" ] || err "Not configured. Run: setup-pxe.sh configure"
    # shellcheck disable=SC1090
    source "$PXE_CONF"
    [ "${CONFIGURED:-0}" = "1" ] || err "Not configured. Run: setup-pxe.sh configure"
    local ip="${SERVER_IP:-$(detect_server_ip)}"

    echo ""
    echo "=== Enabling PXE network boot ==="

    # dnsmasq drop-in: arch detection + boot files
    info "Writing dnsmasq PXE options..."
    cat > "$DNSMASQ_DROP" <<EOF
# Noosphere PXE  -  auto-generated by setup-pxe.sh
# Detect UEFI x86-64 (client-arch 7 and 9) vs legacy BIOS
dhcp-match=set:efi64,option:client-arch,7
dhcp-match=set:efi64,option:client-arch,9
# iPXE-capable clients announce themselves with option 175
dhcp-match=set:ipxe,175
# Legacy BIOS firmware  -> pxelinux over TFTP
dhcp-boot=tag:!efi64,tag:!ipxe,pxelinux.0
# Raw UEFI firmware  -> chainload iPXE over TFTP
dhcp-boot=tag:efi64,tag:!ipxe,ipxe.efi
# iPXE already running  -> fetch the HTTP boot menu
dhcp-boot=tag:ipxe,http://$ip/netboot/boot.ipxe
EOF

    info "Starting tftpd-hpa..."
    systemctl enable tftpd-hpa 2>/dev/null || true
    systemctl restart tftpd-hpa

    info "Restarting dnsmasq..."
    systemctl restart dnsmasq || info "WARN: dnsmasq restart failed - check 'dnsmasq -C /dev/null --test' and config."

    sed -i 's/^PXE_ENABLED=.*/PXE_ENABLED=1/' "$PXE_CONF" 2>/dev/null || echo "PXE_ENABLED=1" >> "$PXE_CONF"

    echo ""
    info "PXE enabled. Targets on the Noosphere LAN can now network-boot."
    # External-router caveat
    if [ -f "$NET_CONF" ]; then
        # shellcheck disable=SC1090
        source "$NET_CONF"
        if [ "${NETWORK_MODE:-}" = "external-router" ]; then
            echo ""
            echo "  NOTE: You are in EXTERNAL ROUTER mode. dnsmasq is not the DHCP"
            echo "        server for the router LAN, so PXE options may not reach"
            echo "        targets. Either configure the router's DHCP to point PXE"
            echo "        clients at $ip, or connect the target to the Noosphere AP."
        fi
    fi
    echo ""
    echo "On the target: enter BIOS/UEFI boot menu, pick 'Network / PXE boot'."
}

# ── disable ───────────────────────────────────────────────────────────────────
cmd_disable() {
    echo ""
    echo "=== Disabling PXE network boot ==="
    if [ -f "$DNSMASQ_DROP" ]; then
        info "Removing dnsmasq PXE options..."
        rm -f "$DNSMASQ_DROP"
    fi
    if systemctl is-active --quiet tftpd-hpa 2>/dev/null; then
        info "Stopping tftpd-hpa..."
        systemctl stop tftpd-hpa
    fi
    systemctl disable tftpd-hpa 2>/dev/null || true

    info "Restarting dnsmasq..."
    systemctl restart dnsmasq || info "WARN: dnsmasq restart failed."

    [ -f "$PXE_CONF" ] && { sed -i 's/^PXE_ENABLED=.*/PXE_ENABLED=0/' "$PXE_CONF" 2>/dev/null || true; }

    echo ""
    info "PXE disabled. tftpd-hpa stopped; boot files left staged for re-enable."
}

# ── refresh kernel/initrd ─────────────────────────────────────────────────────
cmd_refresh() {
    [ -f "$PXE_CONF" ] || err "Not configured. Run: setup-pxe.sh configure"
    echo ""
    echo "=== Refreshing installer kernel + initrd ==="
    # Force internet path by ignoring cache copies
    info "Downloading latest kernel..."
    wget -q -O "$TFTP_ROOT/noosphere/vmlinuz.new" "$KERNEL_URL" \
        || err "Kernel download failed (no internet?)."
    info "Downloading latest initrd..."
    wget -q -O "$TFTP_ROOT/noosphere/initrd.gz.new" "$INITRD_URL" \
        || { rm -f "$TFTP_ROOT/noosphere/vmlinuz.new"; err "initrd download failed (no internet?)."; }
    mv "$TFTP_ROOT/noosphere/vmlinuz.new"  "$TFTP_ROOT/noosphere/vmlinuz"
    mv "$TFTP_ROOT/noosphere/initrd.gz.new" "$TFTP_ROOT/noosphere/initrd.gz"
    mkdir -p "$NETBOOT_WEB"
    install -m 644 "$TFTP_ROOT/noosphere/vmlinuz"  "$NETBOOT_WEB/vmlinuz"
    install -m 644 "$TFTP_ROOT/noosphere/initrd.gz" "$NETBOOT_WEB/initrd.gz"
    chown -R www-data:www-data "$NETBOOT_WEB" 2>/dev/null || true
    echo ""
    info "Boot files refreshed."
}

# ── status ────────────────────────────────────────────────────────────────────
file_badge() { [ -f "$1" ] && echo "present" || echo "MISSING"; }

cmd_status() {
    echo ""
    echo "=== Noosphere PXE / Network Boot status ==="
    echo ""

    local configured=0 enabled=0 ip="(unknown)"
    if [ -f "$PXE_CONF" ]; then
        # shellcheck disable=SC1090
        source "$PXE_CONF"
        configured="${CONFIGURED:-0}"; enabled="${PXE_ENABLED:-0}"; ip="${SERVER_IP:-$(detect_server_ip)}"
    fi
    [ "$ip" = "(unknown)" ] && ip="$(detect_server_ip)"

    echo "  Configured:    $([ "$configured" = 1 ] && echo yes || echo no)"
    echo "  Enabled:       $([ "$enabled" = 1 ] && echo yes || echo no)"
    echo "  Server IP:     $ip"
    local tftpd_state; tftpd_state=$(systemctl is-active tftpd-hpa 2>/dev/null); [ -n "$tftpd_state" ] || tftpd_state=inactive
    echo "  tftpd-hpa:     $tftpd_state"
    echo "  dnsmasq drop:  $([ -f "$DNSMASQ_DROP" ] && echo present || echo absent)"
    echo ""
    echo "  Boot files:"
    echo "    pxelinux.0          $(file_badge "$TFTP_ROOT/pxelinux.0")"
    echo "    ipxe.efi            $(file_badge "$TFTP_ROOT/ipxe.efi")"
    echo "    kernel (vmlinuz)    $(file_badge "$TFTP_ROOT/noosphere/vmlinuz")"
    echo "    initrd.gz           $(file_badge "$TFTP_ROOT/noosphere/initrd.gz")"
    echo "    pxelinux menu       $(file_badge "$TFTP_ROOT/pxelinux.cfg/default")"
    echo "    boot.ipxe           $(file_badge "$NETBOOT_WEB/boot.ipxe")"
    echo "    preseed             $(file_badge "$NETBOOT_WEB/noosphere-preseed.cfg")"
    echo "    provision script    $(file_badge "$NETBOOT_WEB/noosphere-provision.sh")"
    echo ""

    # Machines served (best-effort, from tftpd journal)
    local served last
    served=$(journalctl -u tftpd-hpa --no-pager 2>/dev/null | grep -c "RRQ from" || echo 0)
    last=$(journalctl -u tftpd-hpa --no-pager 2>/dev/null | grep "RRQ from" | tail -1 \
           | grep -oE 'RRQ from [0-9.]+' | awk '{print $3}')
    echo "  TFTP requests served: ${served:-0}"
    [ -n "$last" ] && echo "  Last client served:   $last"
    echo ""
}

# ── dispatch ──────────────────────────────────────────────────────────────────
case "${1:-status}" in
    configure) cmd_configure ;;
    enable)    cmd_enable    ;;
    disable)   cmd_disable   ;;
    refresh)   cmd_refresh   ;;
    status)    cmd_status    ;;
    *) echo "Usage: $0 {configure|enable|disable|status|refresh}"; exit 1 ;;
esac
