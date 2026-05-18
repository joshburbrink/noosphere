#!/bin/bash
# setup-local-display.sh -- Set up a local monitor/touchscreen for Noosphere.
# Installs X11 + Chromium, creates the noosphere-display launcher and systemd
# service. Supports both server-local (HDMI/VGA) and Raspberry Pi scenarios.
# (#77 — local monitor / touchscreen display)

set -e

CONF_DIR="/etc/noosphere"
CONF_FILE="$CONF_DIR/display.conf"
LAUNCHER="/usr/local/bin/noosphere-display"
SERVICE="/etc/systemd/system/noosphere-display.service"

# ── Parse arguments ───────────────────────────────────────────────────────────
MODE="${1:-server}"   # server | pi
SERVER_URL="${2:-http://localhost}"  # for pi mode: http://<noosphere-ip>

usage() {
    cat <<EOF
Usage: $0 [server|pi] [noosphere-url]

  server          Install on the Noosphere server itself (default)
                  Uses http://localhost as the URL
  pi <url>        Configure a Raspberry Pi client pointing to the server
                  Example: $0 pi http://192.168.4.1

After running this script:
  - Enable auto-start:  systemctl enable --now noosphere-display
  - Check status:       systemctl status noosphere-display
  - Switch to command:  noosphere-display-mode command
  - Switch to kiosk:    noosphere-display-mode kiosk
  - Turn off display:   noosphere-display-mode off
EOF
    exit 1
}

[[ "$MODE" != "server" && "$MODE" != "pi" ]] && usage
[[ "$MODE" == "pi" && -z "$2" ]] && { echo "ERROR: provide server URL for pi mode"; usage; }
[[ "$MODE" == "pi" ]] && SERVER_URL="$2"

echo "=== Noosphere Local Display Setup ==="
echo "Mode: $MODE"
echo "Server URL: $SERVER_URL"
echo

# ── Install packages ──────────────────────────────────────────────────────────
echo "--- Installing packages ---"
apt-get update -qq

PKGS=(
    chromium
    xorg
    openbox
    xdotool          # optional: for touchscreen gesture handling
    unclutter-xfixes # hide cursor after inactivity
)

# On Pi, xserver-xorg-video-fbturbo or vc4 is used — xorg meta pulls what's needed
if grep -q "Raspberry Pi" /proc/cpuinfo 2>/dev/null; then
    echo "Raspberry Pi detected."
    PKGS+=(raspi-config)
fi

apt-get install -y "${PKGS[@]}" 2>&1 | grep -E '(installed|upgraded|already|ERROR)' || true
echo "Packages installed."

# ── Write display.conf ────────────────────────────────────────────────────────
mkdir -p "$CONF_DIR"
if [[ ! -f "$CONF_FILE" ]]; then
    cat > "$CONF_FILE" <<EOF
# Noosphere local display configuration
# DISPLAY_MODE: kiosk | command | off
DISPLAY_MODE=kiosk
SERVER_URL=${SERVER_URL}
EOF
    echo "Created $CONF_FILE"
else
    # Update SERVER_URL if it changed
    sed -i "s|^SERVER_URL=.*|SERVER_URL=${SERVER_URL}|" "$CONF_FILE"
    echo "Updated $CONF_FILE"
fi

# ── Write launcher script ─────────────────────────────────────────────────────
cat > "$LAUNCHER" <<'LAUNCHER_EOF'
#!/bin/bash
# noosphere-display -- launch Chromium in kiosk mode on the local display
# Reads /etc/noosphere/display.conf for mode and server URL.

CONF="/etc/noosphere/display.conf"
[[ -f "$CONF" ]] && source "$CONF"

DISPLAY_MODE="${DISPLAY_MODE:-kiosk}"
SERVER_URL="${SERVER_URL:-http://localhost}"

if [[ "$DISPLAY_MODE" == "off" ]]; then
    echo "Display mode is 'off'. Exiting."
    exit 0
fi

URL="${SERVER_URL}/${DISPLAY_MODE}/"
echo "Starting Noosphere display: $DISPLAY_MODE → $URL"

# Clean stale Chromium locks that prevent restart
rm -f /root/.config/chromium/SingletonLock
rm -f /root/.config/chromium/SingletonCookie
rm -f /root/.config/chromium/SingletonSocket

# Disable screen blanking and power saving
xset s off
xset -dpms
xset s noblank 2>/dev/null || true

# Hide cursor after 3 seconds of inactivity
unclutter-xfixes --timeout 3 &

# Start window manager (openbox keeps windows fullscreen)
openbox &

# Brief pause for WM to initialise
sleep 1

# Launch Chromium in kiosk mode
exec chromium \
    --kiosk \
    --no-sandbox \
    --disable-infobars \
    --disable-session-crashed-bubble \
    --disable-restore-session-state \
    --disable-features=TranslateUI \
    --disable-background-networking \
    --noerrdialogs \
    --check-for-update-interval=31536000 \
    --window-size=1920,1080 \
    --start-fullscreen \
    --app="$URL"
LAUNCHER_EOF
chmod +x "$LAUNCHER"
echo "Created $LAUNCHER"

# ── Write mode-switcher helper ────────────────────────────────────────────────
cat > /usr/local/bin/noosphere-display-mode <<'SWITCH_EOF'
#!/bin/bash
# noosphere-display-mode -- switch local display between kiosk, command, off
MODE="${1:-}"
CONF="/etc/noosphere/display.conf"

usage() { echo "Usage: $0 kiosk|command|off"; exit 1; }
[[ -z "$MODE" ]] && usage
[[ "$MODE" != "kiosk" && "$MODE" != "command" && "$MODE" != "off" ]] && usage

[[ ! -f "$CONF" ]] && { echo "ERROR: $CONF not found. Run setup-local-display.sh first."; exit 1; }

sed -i "s/^DISPLAY_MODE=.*/DISPLAY_MODE=${MODE}/" "$CONF"
echo "Display mode set to: $MODE"

if systemctl is-active --quiet noosphere-display 2>/dev/null; then
    systemctl restart noosphere-display
    echo "Service restarted."
else
    echo "Service not running. Start with: systemctl start noosphere-display"
fi
SWITCH_EOF
chmod +x /usr/local/bin/noosphere-display-mode
echo "Created /usr/local/bin/noosphere-display-mode"

# ── Write systemd service ─────────────────────────────────────────────────────
cat > "$SERVICE" <<'SERVICE_EOF'
[Unit]
Description=Noosphere Local Display (Chromium Kiosk)
After=nginx.service
Requires=nginx.service

[Service]
Type=simple
User=root
Environment=HOME=/root
Environment=XAUTHORITY=/root/.Xauthority

# Wait until web server is up
ExecStartPre=/bin/sh -c 'for i in $(seq 1 30); do curl -sf http://localhost/ > /dev/null 2>&1 && break; sleep 2; done'

# Start X on vt7 and run the noosphere-display launcher inside it
ExecStart=/usr/bin/xinit /usr/local/bin/noosphere-display -- :0 vt7 -nolisten tcp

Restart=on-failure
RestartSec=5
TimeoutStartSec=60

[Install]
WantedBy=multi-user.target
SERVICE_EOF

systemctl daemon-reload
echo "Created $SERVICE"

# ── Raspberry Pi-specific: configure autostart ────────────────────────────────
if [[ "$MODE" == "pi" ]]; then
    echo
    echo "--- Pi-specific setup ---"

    # On Pi OS Lite, set up autologin on tty1 → startx
    if [[ -f /etc/systemd/system/getty@tty1.service.d/autologin.conf ]]; then
        echo "Autologin already configured."
    else
        mkdir -p /etc/systemd/system/getty@tty1.service.d/
        cat > /etc/systemd/system/getty@tty1.service.d/autologin.conf <<EOF
[Service]
ExecStart=
ExecStart=-/sbin/agetty --autologin root --noclear %I \$TERM
EOF
        echo "Configured autologin on tty1."
    fi

    # Set up .bash_profile to start X on login if not already running
    if ! grep -q 'startx' /root/.bash_profile 2>/dev/null; then
        cat >> /root/.bash_profile <<EOF

# Auto-start Noosphere display if on tty1
if [[ -z "\$DISPLAY" && "\$(tty)" == "/dev/tty1" ]]; then
    exec startx /usr/local/bin/noosphere-display
fi
EOF
        echo "Configured ~/.bash_profile for auto-startx."
    fi
fi

# ── Summary ───────────────────────────────────────────────────────────────────
echo
echo "=== Setup complete ==="
echo
echo "Config file:    $CONF_FILE"
echo "Launcher:       $LAUNCHER"
echo "Mode switcher:  /usr/local/bin/noosphere-display-mode"
echo "Service:        $SERVICE"
echo
echo "To start now:"
echo "  systemctl start noosphere-display"
echo
echo "To auto-start on boot:"
echo "  systemctl enable noosphere-display"
echo
echo "To switch display mode:"
echo "  noosphere-display-mode kiosk    ← public view"
echo "  noosphere-display-mode command  ← operator view"
echo "  noosphere-display-mode off      ← turn off display"
echo
df -h / | awk 'NR==2 {printf "Disk: %s used of %s (%s free)\n", $3, $2, $4}'
