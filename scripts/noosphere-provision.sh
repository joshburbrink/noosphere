#!/bin/bash
# noosphere-provision.sh  -  Full stack provisioning for a fresh Debian 13 (trixie) install.
#
# Called automatically on first boot by the noosphere-firstboot.service
# (which is registered by install-to-disk.sh).  Can also be run manually.
#
# What it installs:
#   - nginx + PHP 8.4-FPM + MariaDB
#   - Noosphere repo -> /var/www/noosphere/
#   - Kiwix 3.7.0 (offline library server)
#   - mbtileserver (offline tile server)
#   - Nextcloud 33 (optional  -  skipped if --no-nextcloud passed)
#   - dnsmasq (captive portal DNS)
#   - iptables (captive portal redirect)
#   - All systemd services
#   - SQLite databases + default settings
#   - sudo rules for www-data scripts
#
# Usage:
#   bash noosphere-provision.sh              # interactive
#   bash noosphere-provision.sh --unattended # non-interactive (first-boot service)
#   bash noosphere-provision.sh --no-nextcloud

set -euo pipefail

##############################################################################
# Config
##############################################################################
REPO_URL="https://github.com/joshburbrink/noosphere.git"
NOOSPHERE_DIR="/var/www/noosphere"
DATA_DIR="/var/lib/noosphere"
# ── Architecture detection (amd64 = x86 servers, arm64 = Raspberry Pi 5 etc.) ──
# Kiwix uses the kernel arch string (x86_64 / aarch64); mbtileserver uses the
# Debian arch string (amd64 / arm64).
DEB_ARCH="$(dpkg --print-architecture)"
case "$DEB_ARCH" in
    amd64) KIWIX_ARCH="x86_64"  ; MBTILES_ARCH="amd64" ;;
    arm64) KIWIX_ARCH="aarch64" ; MBTILES_ARCH="arm64" ;;
    armhf) KIWIX_ARCH="armhf"   ; MBTILES_ARCH="arm"   ;;
    *)     KIWIX_ARCH="x86_64"  ; MBTILES_ARCH="amd64"
           echo "WARN: unrecognized arch '$DEB_ARCH' - defaulting to amd64 binaries" >&2 ;;
esac

KIWIX_VERSION="3.7.0"
KIWIX_BIN_URL="https://download.kiwix.org/release/kiwix-tools/kiwix-tools_linux-${KIWIX_ARCH}-${KIWIX_VERSION}.tar.gz"
MBTILES_VERSION="0.11.0"
MBTILES_URL="https://github.com/consbio/mbtileserver/releases/download/v${MBTILES_VERSION}/mbtileserver_linux_${MBTILES_ARCH}"
NEXTCLOUD_VERSION="33.0.3"
NEXTCLOUD_URL="https://download.nextcloud.com/server/releases/nextcloud-${NEXTCLOUD_VERSION}.zip"

HOSTNAME_VAL="noosphere"
PORTAL_DOMAIN="noosphere.net"
SERVER_IP="192.168.2.166"   # Default  -  can be overridden

UNATTENDED=0
SKIP_NEXTCLOUD=0

##############################################################################
# Helpers
##############################################################################
die()  { echo "ERROR: $*" >&2; exit 1; }
info() { echo -e "\n\033[1;36m>>> $*\033[0m"; }
ok()   { echo -e "\033[1;32m  ✓ $*\033[0m"; }
warn() { echo -e "\033[1;33mWARN: $*\033[0m"; }
skip() { echo -e "\033[0;37m  - $* (skipped)\033[0m"; }

##############################################################################
# Args
##############################################################################
for arg in "$@"; do
    case "$arg" in
        --unattended)    UNATTENDED=1 ;;
        --no-nextcloud)  SKIP_NEXTCLOUD=1 ;;
    esac
done

##############################################################################
# Root check
##############################################################################
[[ "$EUID" -ne 0 ]] && die "Must run as root."

##############################################################################
# Banner
##############################################################################
echo
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║          NOOSPHERE  -  SYSTEM PROVISIONING                     ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo
[[ "$UNATTENDED" -eq 1 ]] && info "Running in unattended mode  -  all prompts skipped."

##############################################################################
# Apt sources  -  ensure trixie + non-free are present
##############################################################################
info "Configuring apt sources..."
cat > /etc/apt/sources.list <<EOF
deb https://deb.debian.org/debian trixie main contrib non-free non-free-firmware
deb https://deb.debian.org/debian trixie-updates main contrib non-free non-free-firmware
deb https://security.debian.org/debian-security trixie-security main contrib non-free non-free-firmware
EOF

export DEBIAN_FRONTEND=noninteractive
apt-get update -qq
ok "apt sources configured."

##############################################################################
# Core packages
##############################################################################
info "Installing core packages..."
apt-get install -y \
    nginx \
    php8.4-fpm php8.4-cli php8.4-sqlite3 php8.4-mbstring php8.4-xml \
    php8.4-curl php8.4-zip php8.4-gd php8.4-intl php8.4-bcmath \
    php8.4-mysql php8.4-imagick \
    mariadb-server \
    dnsmasq \
    iptables iptables-persistent \
    sqlite3 \
    curl wget git unzip \
    python3 python3-pip python3-venv \
    tmux vim jq \
    net-tools nmap iproute2 \
    htop ncdu smartmontools \
    parted dosfstools e2fsprogs \
    rtl-sdr sox ffmpeg \
    imagemagick
ok "Core packages installed."

##############################################################################
# PHP configuration
##############################################################################
info "Configuring PHP..."
PHP_INI="/etc/php/8.4/fpm/php.ini"
sed -i 's/^upload_max_filesize.*/upload_max_filesize = 100M/'  "$PHP_INI"
sed -i 's/^post_max_size.*/post_max_size = 100M/'              "$PHP_INI"
sed -i 's/^memory_limit.*/memory_limit = 512M/'                "$PHP_INI"
sed -i 's/^max_execution_time.*/max_execution_time = 300/'     "$PHP_INI"

# www-data needs to write to data dirs
usermod -aG audio,video,dialout www-data 2>/dev/null || true
ok "PHP configured."

##############################################################################
# Clone Noosphere repo
##############################################################################
info "Cloning Noosphere repo..."
if [[ -d "$NOOSPHERE_DIR/.git" ]]; then
    git -C "$NOOSPHERE_DIR" pull --ff-only
    ok "Repo updated."
else
    git clone "$REPO_URL" "$NOOSPHERE_DIR"
    ok "Repo cloned to $NOOSPHERE_DIR"
fi

chown -R www-data:www-data "$NOOSPHERE_DIR"
find "$NOOSPHERE_DIR" -type d -exec chmod 755 {} \;
find "$NOOSPHERE_DIR" -type f -exec chmod 644 {} \;
find "$NOOSPHERE_DIR/scripts" -name "*.sh" -exec chmod 755 {} \; 2>/dev/null || true
find "$NOOSPHERE_DIR/scripts" -name "*.py" -exec chmod 755 {} \; 2>/dev/null || true

##############################################################################
# Data directories
##############################################################################
info "Creating data directories..."
dirs=(
    "$DATA_DIR"
    "$DATA_DIR/files"
    "$DATA_DIR/registry_photos"
    "$DATA_DIR/marker_photos"
    "$DATA_DIR/incident_photos"
    "/var/lib/kiwix"
    "/var/lib/mbtiles"
    "/var/log/noosphere"
    "/etc/noosphere"
    "/opt/noosphere-whisper"
)
for d in "${dirs[@]}"; do
    mkdir -p "$d"
done
chown -R www-data:www-data "$DATA_DIR" /var/lib/kiwix /var/log/noosphere
ok "Data directories created."

##############################################################################
# SQLite settings DB  -  touch it so www-data can write
##############################################################################
info "Initializing settings database..."
touch "$DATA_DIR/settings.db"
chown www-data:www-data "$DATA_DIR/settings.db"
chmod 664 "$DATA_DIR/settings.db"
ok "Settings DB ready (defaults applied on first web request)."

##############################################################################
# nginx configuration
##############################################################################
info "Configuring nginx..."

# Security headers snippet
mkdir -p /etc/nginx/snippets
cp "$NOOSPHERE_DIR/nginx/security-headers.conf" /etc/nginx/snippets/security-headers.conf

# Main site config
cp "$NOOSPHERE_DIR/nginx/noosphere.conf" /etc/nginx/sites-available/noosphere.conf
ln -sf /etc/nginx/sites-available/noosphere.conf /etc/nginx/sites-enabled/noosphere.conf
rm -f /etc/nginx/sites-enabled/default

# Tune nginx worker settings
sed -i "s/worker_processes.*/worker_processes auto;/" /etc/nginx/nginx.conf

nginx -t
systemctl enable nginx
systemctl restart nginx
ok "nginx configured and started."

##############################################################################
# PHP-FPM service drop-in
##############################################################################
mkdir -p /etc/systemd/system/php8.4-fpm.service.d
if [[ -f "$NOOSPHERE_DIR/systemd/php8.4-fpm.service.d/override.conf" ]]; then
    cp "$NOOSPHERE_DIR/systemd/php8.4-fpm.service.d/override.conf" \
       /etc/systemd/system/php8.4-fpm.service.d/override.conf
fi
systemctl enable php8.4-fpm
systemctl restart php8.4-fpm
ok "PHP-FPM enabled."

##############################################################################
# MariaDB  -  secure install + Nextcloud database
##############################################################################
info "Configuring MariaDB..."
systemctl enable mariadb
systemctl start mariadb

# Generate a random password for the nextcloud DB user
NC_DB_PASS=$(tr -dc 'A-Za-z0-9!@#' </dev/urandom | head -c 24 || true)

mysql -u root <<SQL
CREATE DATABASE IF NOT EXISTS nextcloud CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE USER IF NOT EXISTS 'nextcloud'@'localhost' IDENTIFIED BY '${NC_DB_PASS}';
GRANT ALL PRIVILEGES ON nextcloud.* TO 'nextcloud'@'localhost';
FLUSH PRIVILEGES;
SQL

# Save credentials for later use
echo "NC_DB_PASS=${NC_DB_PASS}" > /etc/noosphere/db.conf
chmod 600 /etc/noosphere/db.conf
ok "MariaDB configured. Nextcloud DB password saved to /etc/noosphere/db.conf"

##############################################################################
# Kiwix
##############################################################################
info "Installing Kiwix ${KIWIX_VERSION}..."
if command -v kiwix-serve &>/dev/null; then
    skip "kiwix-serve already installed"
else
    TMP_KIWIX=$(mktemp -d)
    wget -q -O "$TMP_KIWIX/kiwix.tar.gz" "$KIWIX_BIN_URL"
    tar -xzf "$TMP_KIWIX/kiwix.tar.gz" -C "$TMP_KIWIX"
    install -m 755 "$TMP_KIWIX"/kiwix-tools_linux-${KIWIX_ARCH}-*/kiwix-serve /usr/bin/kiwix-serve
    install -m 755 "$TMP_KIWIX"/kiwix-tools_linux-${KIWIX_ARCH}-*/kiwix-manage /usr/bin/kiwix-manage 2>/dev/null || true
    rm -rf "$TMP_KIWIX"
    ok "kiwix-serve installed."
fi

# Kiwix library XML
touch /var/lib/kiwix/library.xml
chown www-data:www-data /var/lib/kiwix/library.xml

# Systemd service
cp "$NOOSPHERE_DIR/systemd/kiwix.service" /etc/systemd/system/kiwix.service
systemctl enable kiwix
systemctl start kiwix || warn "kiwix started (no ZIM files yet  -  normal)"
ok "Kiwix service installed."

##############################################################################
# mbtileserver
##############################################################################
info "Installing mbtileserver ${MBTILES_VERSION}..."
if command -v mbtileserver &>/dev/null; then
    skip "mbtileserver already installed"
else
    wget -q -O /usr/bin/mbtileserver "$MBTILES_URL"
    chmod 755 /usr/bin/mbtileserver
    ok "mbtileserver installed."
fi

# Systemd service
cat > /etc/systemd/system/mbtileserver.service <<'EOF'
[Unit]
Description=MBTiles Tile Server
After=network.target

[Service]
Type=simple
User=www-data
ExecStart=/usr/bin/mbtileserver --port 8889 --dir /var/lib/mbtiles --enable-reload-signal
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF
systemctl enable mbtileserver
systemctl start mbtileserver || warn "mbtileserver started (no MBTiles yet  -  normal)"
ok "mbtileserver service installed."

##############################################################################
# MapLibre + glyph fetch (assets are gitignored, so a fresh clone has none)
##############################################################################
info "Fetching MapLibre + Noto Sans glyphs for /maps/ ..."
if WEBROOT="$NOOSPHERE_DIR" bash "$NOOSPHERE_DIR/scripts/fetch-map-assets.sh"; then
    ok "Map assets fetched."
else
    warn "fetch-map-assets.sh failed  -  /maps/ may render blank until rerun."
fi

##############################################################################
# Kiwix-watch service (adds newly downloaded ZIMs automatically)
##############################################################################
cat > /etc/systemd/system/kiwix-watch.service <<'EOF'
[Unit]
Description=Kiwix ZIM Watch (auto-add new ZIM files)
After=kiwix.service

[Service]
Type=oneshot
User=www-data
ExecStart=/bin/bash -c 'for z in /var/lib/kiwix/*.zim; do [ -f "$z" ] || continue; kiwix-manage /var/lib/kiwix/library.xml add "$z" 2>/dev/null || true; done && systemctl restart kiwix'
EOF

cat > /etc/systemd/system/kiwix-watch.path <<'EOF'
[Unit]
Description=Watch for new ZIM files

[Path]
PathChanged=/var/lib/kiwix
MakeDirectory=yes

[Install]
WantedBy=multi-user.target
EOF
systemctl enable kiwix-watch.path
ok "kiwix-watch installed."

##############################################################################
# Noosphere-specific systemd services
##############################################################################
info "Installing Noosphere systemd services..."

for svc in noosphere-aprs.service noosphere-aprs-writer.service noosphere-rtl433.service wifi-reconnect.service; do
    src="$NOOSPHERE_DIR/systemd/$svc"
    [[ -f "$src" ]] && cp "$src" "/etc/systemd/system/$svc" || true
done

mkdir -p /etc/systemd/system/dnsmasq.service.d
if [[ -d "$NOOSPHERE_DIR/systemd/dnsmasq.service.d" ]]; then
    cp "$NOOSPHERE_DIR/systemd/dnsmasq.service.d/"* /etc/systemd/system/dnsmasq.service.d/ 2>/dev/null || true
fi

mkdir -p /etc/systemd/system/nginx.service.d
if [[ -d "$NOOSPHERE_DIR/systemd/nginx.service.d" ]]; then
    cp "$NOOSPHERE_DIR/systemd/nginx.service.d/"* /etc/systemd/system/nginx.service.d/ 2>/dev/null || true
fi

systemctl daemon-reload
ok "Noosphere services installed."

##############################################################################
# noaa-capture / scanner services
##############################################################################
SCRIPTS_DIR="$NOOSPHERE_DIR/scripts"

cat > /etc/systemd/system/noaa-weather.service <<EOF
[Unit]
Description=NOAA Weather Radio capture
After=network.target

[Service]
Type=simple
User=www-data
ExecStart=/bin/bash ${SCRIPTS_DIR}/noaa-capture.sh
Restart=on-failure
RestartSec=30
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF

cat > /etc/systemd/system/scanner-waterfall.service <<EOF
[Unit]
Description=Noosphere scanner waterfall renderer
After=network.target

[Service]
Type=simple
User=www-data
ExecStart=/bin/bash ${SCRIPTS_DIR}/scanner-capture.sh
Restart=on-failure
RestartSec=30
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF

# Weather transcription service
if [[ -f "${SCRIPTS_DIR}/noosphere-weather-transcribe.py" ]]; then
    install -m 755 "${SCRIPTS_DIR}/noosphere-weather-transcribe.py" \
        /usr/local/bin/noosphere-weather-transcribe.py
    cat > /etc/systemd/system/noosphere-weather-transcribe.service <<'EOF'
[Unit]
Description=Noosphere Weather Radio Transcription
After=network.target

[Service]
Type=simple
User=www-data
ExecStart=/usr/bin/python3 /usr/local/bin/noosphere-weather-transcribe.py
Restart=on-failure
RestartSec=15
StandardOutput=journal
StandardError=journal

[Install]
WantedBy=multi-user.target
EOF
fi

systemctl daemon-reload
ok "Weather and scanner services installed (enable manually when hardware attached)."

##############################################################################
# dnsmasq  -  captive portal DNS
##############################################################################
info "Configuring dnsmasq..."
cat > /etc/dnsmasq.conf <<EOF
# Noosphere captive portal DNS
interface=lo
no-resolv
domain-needed
bogus-priv
address=/${PORTAL_DOMAIN}/${SERVER_IP}
address=//#    # Catch-all: resolve everything to server IP (captive portal)
dhcp-range=192.168.2.100,192.168.2.200,12h
dhcp-option=option:router,192.168.2.1
dhcp-option=option:dns-server,${SERVER_IP}
log-dhcp
EOF
systemctl enable dnsmasq
systemctl restart dnsmasq || warn "dnsmasq restart failed  -  check config"
ok "dnsmasq configured."

##############################################################################
# iptables  -  captive portal redirect
##############################################################################
info "Configuring iptables..."
cat > /etc/iptables/rules.v4 <<EOF
*nat
:PREROUTING ACCEPT [0:0]
:INPUT ACCEPT [0:0]
:OUTPUT ACCEPT [0:0]
:POSTROUTING ACCEPT [0:0]
# Redirect all port-80 traffic not destined for us -> our nginx
-A PREROUTING -i wlan0 -p tcp --dport 80  ! -d ${SERVER_IP} -j DNAT --to-destination ${SERVER_IP}:80
# Redirect all port-443 traffic (we handle it with nginx fake-ssl)
-A PREROUTING -i wlan0 -p tcp --dport 443 ! -d ${SERVER_IP} -j DNAT --to-destination ${SERVER_IP}:443
COMMIT

*filter
:INPUT ACCEPT [0:0]
:FORWARD ACCEPT [0:0]
:OUTPUT ACCEPT [0:0]
# Allow established connections
-A INPUT -m state --state RELATED,ESTABLISHED -j ACCEPT
-A INPUT -i lo -j ACCEPT
# SSH
-A INPUT -p tcp --dport 22 -j ACCEPT
# HTTP / HTTPS from LAN
-A INPUT -p tcp --dport 80  -j ACCEPT
-A INPUT -p tcp --dport 443 -j ACCEPT
# DNS / DHCP
-A INPUT -p udp --dport 53  -j ACCEPT
-A INPUT -p tcp --dport 53  -j ACCEPT
-A INPUT -p udp --dport 67  -j ACCEPT
COMMIT
EOF
iptables-restore < /etc/iptables/rules.v4 || warn "iptables-restore failed  -  apply manually"
ok "iptables rules installed."

##############################################################################
# MOTD
##############################################################################
if [[ -f "$NOOSPHERE_DIR/scripts/motd-noosphere" ]]; then
    cp "$NOOSPHERE_DIR/scripts/motd-noosphere" /etc/update-motd.d/99-noosphere
    chmod 755 /etc/update-motd.d/99-noosphere
fi

##############################################################################
# noosphere helper commands in PATH
##############################################################################
for cmd in noosphere-help noosphere-chpasswd.sh setup-credentials.sh \
           setup-hostapd.sh setup-pxe.sh download-zim.sh; do
    src="$NOOSPHERE_DIR/scripts/$cmd"
    [[ -f "$src" ]] || continue
    dest="/usr/local/bin/${cmd}"
    cp "$src" "$dest"
    chmod 755 "$dest"
done
ok "Helper commands installed to /usr/local/bin/"

##############################################################################
# sudo rules  -  allow www-data to call specific admin scripts as root
##############################################################################
info "Installing sudo rules..."
cat > /etc/sudoers.d/noosphere-admin <<'EOF'
# Allow www-data (nginx/PHP) to run specific admin scripts as root
Defaults:www-data !requiretty
www-data ALL=(root) NOPASSWD: /usr/local/bin/setup-credentials.sh *
www-data ALL=(root) NOPASSWD: /usr/local/bin/setup-hostapd.sh *
www-data ALL=(root) NOPASSWD: /usr/local/bin/setup-pxe.sh *
www-data ALL=(root) NOPASSWD: /var/www/noosphere/scripts/sdr-diag.sh *
www-data ALL=(root) NOPASSWD: /var/www/noosphere/scripts/noosphere-set-nwr-freq.sh *
www-data ALL=(root) NOPASSWD: /var/www/noosphere/scripts/noosphere-radio-mode.sh *
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart noaa-weather.service
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart scanner-waterfall.service
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart noosphere-weather-transcribe.service
www-data ALL=(root) NOPASSWD: /usr/bin/systemctl restart kiwix.service
EOF
chmod 440 /etc/sudoers.d/noosphere-admin
ok "sudo rules installed."

##############################################################################
# Nextcloud (optional)
##############################################################################
if [[ "$SKIP_NEXTCLOUD" -eq 1 ]]; then
    skip "Nextcloud (--no-nextcloud passed)"
else
    info "Installing Nextcloud ${NEXTCLOUD_VERSION}..."
    if [[ -d /var/www/nextcloud ]]; then
        skip "Nextcloud already installed at /var/www/nextcloud"
    else
        TMP_NC=$(mktemp -d)
        wget -q -O "$TMP_NC/nextcloud.zip" "$NEXTCLOUD_URL"
        unzip -q "$TMP_NC/nextcloud.zip" -d /var/www/
        chown -R www-data:www-data /var/www/nextcloud
        rm -rf "$TMP_NC"

        # Nextcloud nginx location block (added to the main config via include)
        cat > /etc/nginx/snippets/nextcloud.conf <<'NCEOF'
location ^~ /nextcloud {
    root /var/www;
    index index.php;
    client_max_body_size 100M;

    location ~ ^/nextcloud/(?:index|remote|public|cron|core/ajax/update|status|ocs/v[12]|updater/.+|oc[ms]-provider/.+)\.php(?:$|/) {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param PATH_INFO $fastcgi_path_info;
        include fastcgi_params;
    }

    location ~ ^/nextcloud/(?:build|tests|config|lib|3rdparty|templates|data)(?:$|/) { deny all; }
    location ~ ^/nextcloud/(?:\.|autotest|occ|issue|indie|db_|console)      { deny all; }
}
NCEOF

        # Inject include into nginx site config if not present
        if ! grep -q "nextcloud.conf" /etc/nginx/sites-available/noosphere.conf; then
            sed -i '/^server {$/a\    include snippets/nextcloud.conf;' \
                /etc/nginx/sites-available/noosphere.conf
        fi
        nginx -t && systemctl reload nginx

        # Nextcloud CLI install
        NC_ADMIN_PASS=$(tr -dc 'A-Za-z0-9!@#' </dev/urandom | head -c 20 || true)
        NC_DB_PASS_SAVED=$(grep NC_DB_PASS /etc/noosphere/db.conf | cut -d= -f2 || echo "")

        sudo -u www-data php /var/www/nextcloud/occ maintenance:install \
            --database=mysql \
            --database-name=nextcloud \
            --database-user=nextcloud \
            --database-pass="${NC_DB_PASS_SAVED}" \
            --admin-user=admin \
            --admin-pass="${NC_ADMIN_PASS}" \
            --data-dir=/var/lib/noosphere/nextcloud-data \
            2>&1 || warn "Nextcloud occ install failed  -  complete setup at /nextcloud in browser"

        echo "NC_ADMIN_PASS=${NC_ADMIN_PASS}" >> /etc/noosphere/db.conf

        sudo -u www-data php /var/www/nextcloud/occ config:system:set \
            trusted_domains 0 --value="${SERVER_IP}" 2>/dev/null || true
        sudo -u www-data php /var/www/nextcloud/occ config:system:set \
            overwrite.cli.url --value="http://${SERVER_IP}/nextcloud" 2>/dev/null || true

        ok "Nextcloud installed. Admin password saved to /etc/noosphere/db.conf"
    fi
fi

##############################################################################
# /etc/hosts  -  ensure portal domain resolves locally
##############################################################################
if ! grep -q "$PORTAL_DOMAIN" /etc/hosts; then
    echo "${SERVER_IP}  ${PORTAL_DOMAIN}" >> /etc/hosts
fi

##############################################################################
# Final service restart
##############################################################################
info "Final service restart..."
systemctl daemon-reload
systemctl restart php8.4-fpm nginx dnsmasq || true
ok "Services restarted."

##############################################################################
# Done
##############################################################################
echo
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║  NOOSPHERE PROVISIONING COMPLETE                             ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo
echo "Next steps:"
echo
echo "  1. Set a strong root password:         passwd"
echo "  2. Configure credentials:              /usr/local/bin/setup-credentials.sh"
echo "  3. Configure WiFi access point:        /usr/local/bin/setup-hostapd.sh configure"
echo "  4. Download ZIM files (offline library):"
echo "       /var/www/noosphere/scripts/download-zim.sh"
echo "  5. Download MBTile maps for offline use and place in /var/lib/mbtiles/"
echo "  6. Edit SERVER_IP in /etc/dnsmasq.conf and /etc/iptables/rules.v4 if needed"
if [[ "$SKIP_NEXTCLOUD" -eq 0 ]] && [[ -d /var/www/nextcloud ]]; then
    echo "  7. Nextcloud credentials saved to: /etc/noosphere/db.conf"
    echo "     Access at: http://<ip>/nextcloud"
fi
echo
echo "  Web portal:  http://${SERVER_IP}/"
echo "  Admin panel: http://${SERVER_IP}/admin/  (keyboard shortcut: aaa)"
echo "  SSH:         ssh root@${SERVER_IP}  (password: noosphere  -  CHANGE IT)"
echo
if [[ -f /etc/noosphere/db.conf ]]; then
    echo "  Saved credentials: /etc/noosphere/db.conf (chmod 600)"
    echo
fi
echo "  To enable RTL-SDR services after plugging in hardware:"
echo "    systemctl enable --now noaa-weather.service"
echo "    systemctl enable --now noosphere-weather-transcribe.service"
echo
