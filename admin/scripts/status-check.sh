#!/bin/bash
echo "==============================="
echo "  Noosphere System Status"
echo "  $(date)"
echo "==============================="
echo ""
echo "--- Services ---"
for svc in nginx php8.4-fpm mariadb kiwix mbtileserver wifi-reconnect; do
    STATUS=$(systemctl is-active "$svc" 2>/dev/null)
    MARK=$( [ "$STATUS" = "active" ] && echo "OK" || echo "!!")
    printf "  [%s] %-20s %s\n" "$MARK" "$svc" "$STATUS"
done
echo ""
echo "--- Network Interfaces ---"
ip -br addr show | grep -v '^lo'
echo ""
echo "--- Disk Usage ---"
df -h / | awk 'NR==2{printf "  %s used of %s (%s full)\n", $3, $2, $5}'
echo ""
echo "--- ZIM Library ---"
ZIM_COUNT=$(ls /var/lib/kiwix/zim/*.zim 2>/dev/null | wc -l)
echo "  $ZIM_COUNT ZIM files"
ls -lh /var/lib/kiwix/zim/*.zim 2>/dev/null | awk '{printf "  %s (%s)\n", $9, $5}'
echo ""
echo "--- Registry ---"
sqlite3 /var/lib/noosphere/registry.db "SELECT COUNT(*) || ' entries'" 2>/dev/null || echo "  Not accessible"
echo ""
echo "--- Connected Devices ---"
ip neigh show | grep -c "lladdr" | tr -d '\n'
echo " devices seen via ARP"
