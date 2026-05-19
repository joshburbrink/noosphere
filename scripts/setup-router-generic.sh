#!/bin/bash
# setup-router-generic.sh -- Configure any WiFi router as a Noosphere captive portal.
# Works with OpenWrt, DD-WRT, GL.iNet, or as a fallback using the built-in AP mode.
# (#10  -  router-agnostic captive portal setup)

set -e

NOOSPHERE_IP="${NOOSPHERE_IP:-192.168.8.2}"
NOOSPHERE_SSID="${NOOSPHERE_SSID:-Noosphere}"
ROUTER_IP="${ROUTER_IP:-192.168.8.1}"
ROUTER_USER="${ROUTER_USER:-root}"

usage() {
    cat <<EOF
Usage: $0 <mode> [options]

Modes:
  openwrt   Configure an OpenWrt router via SSH (most flexible)
  ap        Use the built-in AP mode (no external router needed)
  manual    Print manual setup instructions for any router
  check     Verify captive portal is working

Options (for openwrt mode):
  ROUTER_IP=<ip>          Router LAN IP (default: $ROUTER_IP)
  ROUTER_USER=<user>      SSH user (default: $ROUTER_USER)
  NOOSPHERE_IP=<ip>       Noosphere server IP (default: $NOOSPHERE_IP)
  NOOSPHERE_SSID=<name>   WiFi network name (default: $NOOSPHERE_SSID)

Examples:
  $0 ap                               # Use built-in 8812AU AP  -  no router needed
  ROUTER_IP=192.168.1.1 $0 openwrt   # Configure OpenWrt router
  $0 manual                           # Print instructions for stock routers
  $0 check                            # Test captive portal detection endpoints
EOF
    exit 1
}

mode="${1:-}"
[ -z "$mode" ] && usage

# ── AP mode (built-in, no external router) ───────────────────────────────────
if [ "$mode" = "ap" ]; then
    echo "=== Built-in AP Mode ==="
    echo "Uses the RTL8812AU USB WiFi adapter (wlx... interface) as an access point."
    echo "Clients connect to SSID 'NET', get DHCP from 192.168.4.0/24,"
    echo "and are redirected to http://192.168.4.1/ via captive portal."
    echo
    if ! command -v hostapd &>/dev/null; then
        echo "ERROR: hostapd not installed. Run: apt-get install hostapd"
        exit 1
    fi
    /usr/local/bin/setup-hostapd.sh configure && \
    /usr/local/bin/setup-hostapd.sh enable
    echo "AP mode enabled. Connect to SSID 'NET' to test."
    exit 0
fi

# ── OpenWrt via SSH ───────────────────────────────────────────────────────────
if [ "$mode" = "openwrt" ]; then
    echo "=== OpenWrt Captive Portal Setup ==="
    echo "Router: $ROUTER_USER@$ROUTER_IP"
    echo "Noosphere: $NOOSPHERE_IP"
    echo "SSID: $NOOSPHERE_SSID"
    echo

    # Test SSH access
    if ! ssh -o ConnectTimeout=5 -o BatchMode=yes "$ROUTER_USER@$ROUTER_IP" true 2>/dev/null; then
        echo "ERROR: Cannot SSH to $ROUTER_IP. Set up SSH key or use password:"
        echo "  ssh-copy-id $ROUTER_USER@$ROUTER_IP"
        exit 1
    fi

    echo "Configuring OpenWrt..."
    ssh "$ROUTER_USER@$ROUTER_IP" bash <<OPENWRT
set -e

# Set SSID and disable password
uci set wireless.@wifi-iface[0].ssid='$NOOSPHERE_SSID'
uci set wireless.@wifi-iface[0].encryption='none'
uci commit wireless

# Point DHCP DNS to Noosphere server
uci set dhcp.@dnsmasq[0].server='$NOOSPHERE_IP'
uci set dhcp.@dnsmasq[0].address='/#/$NOOSPHERE_IP'
uci commit dhcp

# Add captive portal redirect via nftables / iptables
# Redirect all HTTP to Noosphere
if command -v nft &>/dev/null; then
    nft add rule inet fw4 prerouting tcp dport 80 counter dnat ip to ${NOOSPHERE_IP}:80 2>/dev/null || true
else
    iptables -t nat -A PREROUTING -i br-lan -p tcp --dport 80 -j DNAT --to-destination ${NOOSPHERE_IP}:80 2>/dev/null || true
fi

# Restart services
/etc/init.d/network restart
/etc/init.d/dnsmasq restart
echo "OpenWrt configured."
OPENWRT
    echo
    echo "Done. Connect a device to '$NOOSPHERE_SSID' and open any HTTP page."
    exit 0
fi

# ── Manual instructions ───────────────────────────────────────────────────────
if [ "$mode" = "manual" ]; then
    cat <<EOF
=== Manual Captive Portal Setup ===
Works with any router that supports custom DNS settings.

STEP 1  -  Connect the router
  Plug an ethernet cable from the Noosphere server (eno1) into the router's WAN or LAN port.
  Server IP on that interface: $NOOSPHERE_IP

STEP 2  -  Set DNS in router DHCP settings
  Log into your router admin page (usually 192.168.1.1 or 192.168.0.1).
  Find: DHCP -> DNS Server (or Primary DNS)
  Set: $NOOSPHERE_IP

  This makes all devices ask Noosphere for DNS lookups. Noosphere's dnsmasq
  returns its own IP for every domain, triggering the captive portal.

STEP 3  -  Set WiFi to open (no password)
  SSID: $NOOSPHERE_SSID (or any name)
  Security: None / Open

STEP 4  -  (Optional) Redirect HTTP at the router
  If your router supports firewall/NAT rules, add:
    Redirect: TCP port 80 from LAN -> $NOOSPHERE_IP:80
  This forces all HTTP traffic to Noosphere even if the client ignores DNS.

STEP 5  -  Test
  Connect a phone to the WiFi. It should automatically show a captive portal
  notification, or navigate to any http:// URL and land on the Noosphere homepage.

FALLBACK  -  No router available
  Use the built-in AP mode instead: run  setup-router-generic.sh ap
  This uses the RTL8812AU USB adapter (SSID: NET) with no external router.

Tested router families:
  GL.iNet   -  use setup-router.sh (GL-SFT1200 specific) or this script's openwrt mode
  OpenWrt   -  use this script's openwrt mode
  DD-WRT    -  manual: Services -> DNSMasq -> Additional Options: address=/#/$NOOSPHERE_IP
  pfSense   -  DNS Resolver -> Host Overrides: *.* -> $NOOSPHERE_IP
  Stock     -  set DHCP DNS field to $NOOSPHERE_IP (varies by brand)
EOF
    exit 0
fi

# ── Check ─────────────────────────────────────────────────────────────────────
if [ "$mode" = "check" ]; then
    echo "=== Captive Portal Check ==="
    echo "Testing detection endpoints on $NOOSPHERE_IP..."
    for path in /generate_204 /hotspot-detect.html /ncsi.txt /connecttest.txt; do
        code=$(curl -s -o /dev/null -w "%{http_code}" --max-time 3 "http://$NOOSPHERE_IP$path" 2>/dev/null || echo "ERR")
        echo "  $path -> HTTP $code"
    done
    echo
    echo "Testing DNS wildcard (should resolve to $NOOSPHERE_IP)..."
    resolved=$(getent hosts example.com 2>/dev/null | awk '{print $1}')
    if [ "$resolved" = "$NOOSPHERE_IP" ]; then
        echo "  example.com -> $resolved ✓ (captive portal DNS active)"
    else
        echo "  example.com -> ${resolved:-no response} (DNS not redirecting  -  check dnsmasq)"
    fi
    exit 0
fi

usage
