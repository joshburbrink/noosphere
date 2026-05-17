#!/bin/bash
# setup-usb-ethernet.sh — manage USB ethernet adapters on Noosphere server
# Usage: setup-usb-ethernet.sh <detect|status|dhcp|static|ping|up|remove> [iface] [--persist]
set -e

STATIC_IP="192.168.8.2"
STATIC_MASK="255.255.255.0"
STATIC_PREFIX="24"
INTERFACES_D="/etc/network/interfaces.d"

# Find all USB ethernet interfaces (enx* with a USB device path)
find_usb_eth() {
    for path in /sys/class/net/enx*; do
        [ -e "$path" ] || continue
        iface=$(basename "$path")
        devpath=$(readlink -f "$path/device" 2>/dev/null || true)
        if echo "$devpath" | grep -q '/usb'; then
            echo "$iface"
        fi
    done
}

# Get interface: use $TARGET_IFACE if set (from CLI arg), else auto-detect first
TARGET_IFACE=""

get_iface() {
    if [ -n "$TARGET_IFACE" ]; then
        echo "$TARGET_IFACE"
    else
        find_usb_eth | head -1
    fi
}

cmd_detect() {
    ifaces=$(find_usb_eth)
    if [ -z "$ifaces" ]; then
        echo "No USB ethernet adapters found."
        echo "Check: dmesg | grep -i 'ax88\|r8152\|usb.*eth'"
        exit 1
    fi
    for iface in $ifaces; do
        mac=$(cat "/sys/class/net/$iface/address" 2>/dev/null || echo '?')
        driver=$(basename "$(readlink -f /sys/class/net/$iface/device/driver 2>/dev/null)" 2>/dev/null || echo 'unknown')
        speed=$(cat "/sys/class/net/$iface/speed" 2>/dev/null || echo '?')
        echo "Interface : $iface"
        echo "MAC       : $mac"
        echo "Driver    : $driver"
        echo "Speed cap : ${speed} Mbps"
        echo "Operstate : $(cat /sys/class/net/$iface/operstate 2>/dev/null || echo '?')"
        echo ""
    done
}

cmd_status() {
    ifaces=$(find_usb_eth)
    if [ -z "$ifaces" ]; then
        echo "No USB ethernet adapters detected."
        exit 0
    fi
    for iface in $ifaces; do
        operstate=$(cat "/sys/class/net/$iface/operstate" 2>/dev/null || echo 'unknown')
        ip4=$(ip -4 addr show "$iface" 2>/dev/null | awk '/inet /{print $2}' | head -1 || echo '')
        gw=$(ip route show dev "$iface" 2>/dev/null | awk '/default/{print $3}' | head -1 || echo '')
        has_persist=""
        [ -f "$INTERFACES_D/$iface" ] && has_persist="yes ($(grep 'inet' $INTERFACES_D/$iface | awk '{print $NF}'))"
        dhcpcd_running=$(pgrep -a dhcpcd 2>/dev/null | grep -c "$iface" || true)

        echo "=== $iface ==="
        echo "  Link state : $operstate"
        echo "  IP address : ${ip4:-none}"
        echo "  Gateway    : ${gw:-none}"
        echo "  Persistent : ${has_persist:-no (not in /etc/network/interfaces.d/)}"
        echo "  dhcpcd     : $([ "$dhcpcd_running" -gt 0 ] && echo running || echo not running)"
        echo ""
    done
}

cmd_dhcp() {
    iface=$(get_iface)
    if [ -z "$iface" ]; then echo "No USB ethernet adapter found."; exit 1; fi

    echo "Requesting DHCP on $iface..."
    # Bring up interface if needed
    ip link set "$iface" up 2>/dev/null || true
    sleep 1

    if ! dhcpcd "$iface" 2>&1; then
        echo "dhcpcd failed — trying to release and retry..."
        dhcpcd -k "$iface" 2>/dev/null || true
        sleep 1
        dhcpcd "$iface"
    fi

    echo ""
    ip addr show "$iface"

    if [ "${1:-}" = "--persist" ]; then
        persist_dhcp "$iface"
    fi
}

persist_dhcp() {
    iface=$1
    cat > "$INTERFACES_D/$iface" << EOF
auto $iface
iface $iface inet dhcp
EOF
    echo "Persistent DHCP config written to $INTERFACES_D/$iface"
}

cmd_static() {
    iface=$(get_iface)
    if [ -z "$iface" ]; then echo "No USB ethernet adapter found."; exit 1; fi

    echo "Configuring $iface as static $STATIC_IP/$STATIC_PREFIX..."

    # Release any DHCP lease
    dhcpcd -k "$iface" 2>/dev/null || true
    sleep 1

    # Flush existing addresses
    ip addr flush dev "$iface" 2>/dev/null || true

    # Set static address
    ip link set "$iface" up
    ip addr add "$STATIC_IP/$STATIC_PREFIX" dev "$iface"

    echo "Applied: $STATIC_IP/$STATIC_PREFIX on $iface"

    # Write persistent config
    cat > "$INTERFACES_D/$iface" << EOF
auto $iface
iface $iface inet static
    address $STATIC_IP
    netmask $STATIC_MASK
EOF
    echo "Persistent static config written to $INTERFACES_D/$iface"
    echo ""
    ip addr show "$iface"
}

cmd_ping() {
    iface=$(get_iface)
    if [ -z "$iface" ]; then echo "No USB ethernet adapter found."; exit 1; fi

    gw=$(ip route show dev "$iface" 2>/dev/null | awk '/default/{print $3}' | head -1 || echo '')
    ip4=$(ip -4 addr show "$iface" 2>/dev/null | awk '/inet /{print $2}' | head -1 || echo '')

    if [ -z "$ip4" ]; then
        echo "No IP address on $iface — cannot ping."
        exit 1
    fi

    target=${gw:-192.168.8.1}
    echo "Pinging $target via $iface ($ip4)..."
    ping -c 4 -I "$iface" "$target"
}

cmd_up() {
    iface=$(get_iface)
    if [ -z "$iface" ]; then echo "No USB ethernet adapter found."; exit 1; fi
    ip link set "$iface" up
    echo "$iface brought up. Link state: $(cat /sys/class/net/$iface/operstate 2>/dev/null || echo unknown)"
}

cmd_remove() {
    iface=$(get_iface)
    if [ -z "$iface" ]; then echo "No USB ethernet adapter found."; exit 1; fi

    dhcpcd -k "$iface" 2>/dev/null || true
    ip addr flush dev "$iface" 2>/dev/null || true
    ip link set "$iface" down 2>/dev/null || true

    if [ -f "$INTERFACES_D/$iface" ]; then
        rm -f "$INTERFACES_D/$iface"
        echo "Removed $INTERFACES_D/$iface"
    fi
    echo "$iface deconfigured."
}

# Parse args: cmd [iface_or_flag] [flag]
CMD="${1:-}"
ARG2="${2:-}"
ARG3="${3:-}"

# If $ARG2 looks like an interface name (enx*), use it
if echo "$ARG2" | grep -qE '^enx[0-9a-f]+$'; then
    TARGET_IFACE="$ARG2"
    EXTRA_ARG="$ARG3"
else
    EXTRA_ARG="$ARG2"
fi

case "$CMD" in
    detect)  cmd_detect ;;
    status)  cmd_status ;;
    dhcp)    cmd_dhcp "$EXTRA_ARG" ;;
    static)  cmd_static ;;
    ping)    cmd_ping ;;
    up)      cmd_up ;;
    remove)  cmd_remove ;;
    *)
        echo "Usage: setup-usb-ethernet.sh <detect|status|dhcp|static|ping|up|remove> [iface] [--persist]"
        echo ""
        echo "  detect              Show USB ethernet hardware info and driver"
        echo "  status              Show link state, IP, gateway, persist config"
        echo "  dhcp [iface]        Request DHCP address immediately"
        echo "  dhcp [iface] --persist  Also write /etc/network/interfaces.d/<iface>"
        echo "  static [iface]      Set static 192.168.8.2/24 (production/router mode)"
        echo "  ping [iface]        Ping gateway via USB ethernet interface"
        echo "  up [iface]          Bring interface up"
        echo "  remove [iface]      Release IP and remove persistent config"
        exit 1
        ;;
esac
