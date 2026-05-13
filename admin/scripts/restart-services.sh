#!/bin/bash
SERVICES="nginx php8.4-fpm mariadb kiwix mbtileserver"
for svc in $SERVICES; do
    printf "%-22s " "$svc"
    systemctl restart "$svc" 2>&1 && echo "restarted OK" || echo "FAILED"
done
echo ""
echo "=== Status ==="
for svc in $SERVICES; do
    printf "  %-20s %s\n" "$svc" "$(systemctl is-active $svc)"
done
