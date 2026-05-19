#!/bin/bash
# One-shot health check for the full noosphere stack.
# Exits 0 if all checks pass, 1 if any FAIL.
# Usage: noosphere-selftest.sh [--quiet]

QUIET=0
[[ "$1" == "--quiet" ]] && QUIET=1

PASS=0; WARN=0; FAIL=0
WIFI=$(ip link show | awk -F': ' '/^ *[0-9]+: wl/{gsub(/@.*/, "", $2); print $2; exit}')

_ok()   { PASS=$((PASS+1)); [[ $QUIET -eq 0 ]] && printf "  \e[32m[OK  ]\e[0m  %s\n" "$1"; }
_warn() { WARN=$((WARN+1)); printf "  \e[33m[WARN]\e[0m  %s\n" "$1"; }
_fail() { FAIL=$((FAIL+1)); printf "  \e[31m[FAIL]\e[0m  %s\n" "$1"; }

check_service() {
    local name=$1
    if systemctl is-active --quiet "$name"; then
        local restarts
        restarts=$(systemctl show "$name" --property=NRestarts --value 2>/dev/null)
        if [[ -n "$restarts" && "$restarts" -gt 5 ]]; then
            _warn "$name (active but restarted $restarts times  -  check logs)"
        else
            _ok "$name"
        fi
    else
        _fail "$name (not active)"
    fi
}

check_http() {
    local label=$1 url=$2 expected=${3:-200}
    local code
    code=$(curl -s -o /dev/null -w '%{http_code}' --max-time 3 "$url" 2>/dev/null)
    if [[ "$code" == "$expected" ]]; then
        _ok "$label (HTTP $code)"
    else
        _fail "$label (expected $expected, got $code)"
    fi
}

check_port() {
    local label=$1 host=$2 port=$3
    if ss -tlnp | grep -q ":${port} "; then
        _ok "$label (listening on :$port)"
    else
        _fail "$label (nothing on :$port)"
    fi
}

[[ $QUIET -eq 0 ]] && echo "" && echo "  Noosphere Self-Test  -  $(date)" && echo "  ─────────────────────────────────────"

# Services
[[ $QUIET -eq 0 ]] && echo "  Services"
for svc in nginx php8.4-fpm mariadb kiwix mbtileserver dnsmasq wifi-reconnect; do
    check_service "$svc"
done

# Optional SDR services  -  only warn if enabled but not running
SDR_MODE=""
if command -v sqlite3 &>/dev/null; then
    SDR_MODE=$(sqlite3 /var/lib/noosphere/settings.db "SELECT value FROM settings WHERE key='radio_mode' LIMIT 1" 2>/dev/null || echo "")
fi
if [[ -n "$SDR_MODE" && "$SDR_MODE" != "off" ]]; then
    [[ $QUIET -eq 0 ]] && echo "" && echo "  SDR (mode=$SDR_MODE)"
    case "$SDR_MODE" in
      nwr)     check_service "noaa-weather" ;;
      scanner) check_service "scanner-waterfall" ;;
      rtl433)  check_service "noosphere-rtl433" ;;
      aprs)    check_service "noosphere-aprs"; check_service "noosphere-aprs-writer" ;;
    esac
    # RTL-SDR dongle presence
    if command -v rtlsdr-detect.sh &>/dev/null; then
        if /usr/local/bin/rtlsdr-detect.sh 2>/dev/null | grep -q "Status:    OK"; then
            _ok "RTL-SDR dongle detected"
        else
            _warn "RTL-SDR dongle not detected  -  SDR mode=$SDR_MODE but dongle missing?"
        fi
    fi
fi

# Ports
[[ $QUIET -eq 0 ]] && echo "" && echo "  Ports"
check_port "nginx"        127.0.0.1 80
check_port "kiwix"        127.0.0.1 8888
check_port "mbtileserver" 127.0.0.1 8889

# HTTP endpoints
[[ $QUIET -eq 0 ]] && echo "" && echo "  Endpoints"
check_http "homepage"    "http://localhost/"
check_http "registry"    "http://localhost/registry/"
check_http "chat"        "http://localhost/chat/"
check_http "forum"       "http://localhost/forum/"
check_http "maps"        "http://localhost/maps/"
check_http "library"     "http://localhost/library/"
check_http "mbtileserver" "http://localhost/tiles/"

# ZIM library
zim_count=$(ls /var/lib/kiwix/zim/*.zim 2>/dev/null | wc -l)
if [[ "$zim_count" -gt 0 ]]; then
    _ok "ZIM library ($zim_count files)"
else
    _warn "ZIM library (no .zim files found)"
fi

# Disk usage
usage_pct=$(df / | awk 'NR==2{gsub(/%/,"",$5); print $5}')
if [[ "$usage_pct" -ge 90 ]]; then
    _fail "disk usage (${usage_pct}%  -  critically full)"
elif [[ "$usage_pct" -ge 80 ]]; then
    _warn "disk usage (${usage_pct}%)"
else
    _ok "disk usage (${usage_pct}%)"
fi

# WiFi
if [[ -n "$WIFI" ]] && ip link show "$WIFI" | grep -q "state UP"; then
    ip=$(ip -4 addr show "$WIFI" | grep -oP '(?<=inet )\S+')
    _ok "WiFi ($WIFI up, $ip)"
else
    _warn "WiFi (interface not UP  -  ethernet-only mode?)"
fi

# Summary
echo ""
if [[ $QUIET -eq 0 ]]; then
    echo "  ─────────────────────────────────────"
    printf "  %s OK   %s WARN   %s FAIL\n" "$PASS" "$WARN" "$FAIL"
    echo ""
fi

[[ $FAIL -eq 0 ]]
