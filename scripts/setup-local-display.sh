#!/bin/bash
# setup-local-display.sh -- Set up a local monitor/touchscreen for Noosphere.
# Installs X11 + Chromium + matchbox-keyboard, creates the noosphere-display
# launcher and systemd service. Supports server-local (HDMI/VGA/touchscreen)
# and Raspberry Pi client scenarios.
# (#85 - 7" touchscreen support)

set -e

CONF_DIR="/etc/noosphere"
CONF_FILE="$CONF_DIR/display.conf"
LAUNCHER="/usr/local/bin/noosphere-display"
SERVICE="/etc/systemd/system/noosphere-display.service"
ROTATE="normal"

# ── Parse arguments ───────────────────────────────────────────────────────────
MODE="${1:-server}"   # server | pi
SERVER_URL="http://localhost"

usage() {
    cat <<EOF
Usage: $0 [server|pi] [options]

  server              Install on the Noosphere server itself (default)
  pi <noosphere-url>  Configure a Raspberry Pi client

Options:
  --rotate <normal|left|right|inverted>  Screen rotation (default: normal)

After running:
  systemctl enable --now noosphere-display
  noosphere-display-mode kiosk|command|admin|off
EOF
    exit 1
}

shift || true
while [[ $# -gt 0 ]]; do
    case "$1" in
        --rotate) ROTATE="$2"; shift 2 ;;
        http://*|https://*) SERVER_URL="$1"; shift ;;
        *) echo "Unknown option: $1" >&2; usage ;;
    esac
done

[[ "$MODE" != "server" && "$MODE" != "pi" ]] && usage
[[ "$MODE" == "pi" && "$SERVER_URL" == "http://localhost" ]] && { echo "ERROR: provide server URL for pi mode, e.g.: $0 pi http://192.168.4.1"; exit 1; }
[[ ! "$ROTATE" =~ ^(normal|left|right|inverted)$ ]] && { echo "ERROR: --rotate must be normal|left|right|inverted"; exit 1; }

echo "=== Noosphere Local Display Setup ==="
echo "Mode:     $MODE"
echo "URL:      $SERVER_URL"
echo "Rotation: $ROTATE"
echo

# ── Install packages ──────────────────────────────────────────────────────────
echo "--- Installing packages ---"
apt-get update -qq

PKGS=(
    chromium
    xorg
    openbox
    xdotool
    unclutter-xfixes
    matchbox-keyboard
    xinput
)

if grep -q "Raspberry Pi" /proc/cpuinfo 2>/dev/null; then
    echo "Raspberry Pi detected."
    PKGS+=(raspi-config)
fi

apt-get install -y "${PKGS[@]}" 2>&1 | grep -E '(installed|upgraded|already|ERROR)' || true
echo "Packages installed."

# ── Auto-detect screen resolution ────────────────────────────────────────────
DISPLAY_WIDTH=1920
DISPLAY_HEIGHT=1080

# Try to detect via connected monitor EDID (works without X running)
if command -v xrandr >/dev/null 2>&1 && [[ -n "$DISPLAY" ]]; then
    RES=$(xrandr --current 2>/dev/null | grep ' connected' | grep -oP '\d+x\d+' | head -1)
    if [[ -n "$RES" ]]; then
        DISPLAY_WIDTH="${RES%%x*}"
        DISPLAY_HEIGHT="${RES##*x}"
        echo "Detected resolution: ${DISPLAY_WIDTH}x${DISPLAY_HEIGHT}"
    fi
elif [[ -d /sys/class/drm ]]; then
    for modes_file in /sys/class/drm/*/modes; do
        [[ -f "$modes_file" ]] || continue
        FIRST=$(head -1 "$modes_file" 2>/dev/null)
        if [[ "$FIRST" =~ ^([0-9]+)x([0-9]+) ]]; then
            DISPLAY_WIDTH="${BASH_REMATCH[1]}"
            DISPLAY_HEIGHT="${BASH_REMATCH[2]}"
            echo "Detected resolution from DRM: ${DISPLAY_WIDTH}x${DISPLAY_HEIGHT}"
            break
        fi
    done
fi

# 7" displays are commonly 800x480 - use a readable scale factor
SCALE_FACTOR="1.0"
if (( DISPLAY_WIDTH <= 800 && DISPLAY_HEIGHT <= 480 )); then
    echo "Small display detected (${DISPLAY_WIDTH}x${DISPLAY_HEIGHT}) - using scale factor 1.0"
fi

# ── Write display.conf ────────────────────────────────────────────────────────
mkdir -p "$CONF_DIR"
if [[ ! -f "$CONF_FILE" ]]; then
    cat > "$CONF_FILE" <<EOF
# Noosphere local display configuration
# DISPLAY_MODE: kiosk | command | admin | off
DISPLAY_MODE=kiosk
SERVER_URL=${SERVER_URL}
DISPLAY_WIDTH=${DISPLAY_WIDTH}
DISPLAY_HEIGHT=${DISPLAY_HEIGHT}
DISPLAY_ROTATE=${ROTATE}
EOF
    echo "Created $CONF_FILE"
else
    # Update fields without clobbering operator customizations
    sed -i "s|^SERVER_URL=.*|SERVER_URL=${SERVER_URL}|" "$CONF_FILE"
    # Add new fields if missing
    grep -q '^DISPLAY_WIDTH='  "$CONF_FILE" || echo "DISPLAY_WIDTH=${DISPLAY_WIDTH}"  >> "$CONF_FILE"
    grep -q '^DISPLAY_HEIGHT=' "$CONF_FILE" || echo "DISPLAY_HEIGHT=${DISPLAY_HEIGHT}" >> "$CONF_FILE"
    grep -q '^DISPLAY_ROTATE=' "$CONF_FILE" || echo "DISPLAY_ROTATE=${ROTATE}"         >> "$CONF_FILE"
    echo "Updated $CONF_FILE"
fi

# ── Write launcher script ─────────────────────────────────────────────────────
cat > "$LAUNCHER" <<'LAUNCHER_EOF'
#!/bin/bash
# noosphere-display -- launch Chromium in kiosk/command/admin/touch mode.

CONF="/etc/noosphere/display.conf"
[[ -f "$CONF" ]] && source "$CONF"

DISPLAY_MODE="${DISPLAY_MODE:-kiosk}"
SERVER_URL="${SERVER_URL:-http://localhost}"
DISPLAY_WIDTH="${DISPLAY_WIDTH:-1920}"
DISPLAY_HEIGHT="${DISPLAY_HEIGHT:-1080}"
DISPLAY_ROTATE="${DISPLAY_ROTATE:-normal}"

if [[ "$DISPLAY_MODE" == "off" ]]; then
    echo "Display mode is 'off'. Exiting."
    exit 0
fi

# Build URL based on mode
case "$DISPLAY_MODE" in
    kiosk)   URL="${SERVER_URL}/?ns_kiosk=1" ;;
    command) URL="${SERVER_URL}/command/?ns_kiosk=1" ;;
    admin)   URL="${SERVER_URL}/admin/" ;;
    *)       URL="${SERVER_URL}/" ;;
esac

echo "Noosphere display: mode=$DISPLAY_MODE  url=$URL  res=${DISPLAY_WIDTH}x${DISPLAY_HEIGHT}"

# Clean stale Chromium singleton locks
rm -f /root/.config/chromium/SingletonLock \
      /root/.config/chromium/SingletonCookie \
      /root/.config/chromium/SingletonSocket

# Screen rotation via xrandr
if command -v xrandr >/dev/null 2>&1 && [[ "$DISPLAY_ROTATE" != "normal" ]]; then
    xrandr --rotate "$DISPLAY_ROTATE" 2>/dev/null || true
fi

# Disable screen blanking and power saving
xset s off
xset -dpms
xset s noblank 2>/dev/null || true

# Hide cursor after 3 seconds of inactivity
unclutter-xfixes --timeout 3 &

# Start window manager
openbox &
sleep 1

# Launch matchbox-keyboard in background for text input
if command -v matchbox-keyboard >/dev/null 2>&1; then
    matchbox-keyboard --daemon 2>/dev/null &
fi

# Chromium flags
CHROME_FLAGS=(
    --kiosk
    --no-sandbox
    --disable-infobars
    --disable-session-crashed-bubble
    --disable-restore-session-state
    --disable-features=TranslateUI
    --disable-background-networking
    --noerrdialogs
    --check-for-update-interval=31536000
    --window-size="${DISPLAY_WIDTH},${DISPLAY_HEIGHT}"
    --start-fullscreen
    --touch-events=enabled
    --enable-pinch
    --force-device-scale-factor=1.0
    --disable-smooth-scrolling
    --overscroll-history-navigation=0
)

exec chromium "${CHROME_FLAGS[@]}" --app="$URL"
LAUNCHER_EOF
chmod +x "$LAUNCHER"
echo "Created $LAUNCHER"

# ── Write mode-switcher ───────────────────────────────────────────────────────
cat > /usr/local/bin/noosphere-display-mode <<'SWITCH_EOF'
#!/bin/bash
# noosphere-display-mode -- switch local display between kiosk, command, admin, off
MODE="${1:-}"
CONF="/etc/noosphere/display.conf"

usage() { echo "Usage: $0 kiosk|command|admin|off"; exit 1; }
[[ -z "$MODE" ]] && usage
[[ ! "$MODE" =~ ^(kiosk|command|admin|off)$ ]] && { echo "Invalid mode: $MODE"; usage; }

[[ ! -f "$CONF" ]] && { echo "ERROR: $CONF not found. Run setup-local-display.sh first."; exit 1; }

sed -i "s/^DISPLAY_MODE=.*/DISPLAY_MODE=${MODE}/" "$CONF"
echo "Display mode: $MODE"

if systemctl is-active --quiet noosphere-display 2>/dev/null; then
    systemctl restart noosphere-display
    echo "Service restarted."
else
    echo "To start: systemctl start noosphere-display"
fi
SWITCH_EOF
chmod +x /usr/local/bin/noosphere-display-mode
echo "Created /usr/local/bin/noosphere-display-mode"

# ── Write systemd service ─────────────────────────────────────────────────────
cat > "$SERVICE" <<'SERVICE_EOF'
[Unit]
Description=Noosphere Local Display (Chromium Kiosk)
After=network.target local-fs.target nginx.service
Wants=nginx.service

[Service]
Type=simple
User=root
Environment=HOME=/root
Environment=XAUTHORITY=/root/.Xauthority

# Wait for USB filesystem + nginx to be fully ready (longer on USB boot)
ExecStartPre=/bin/sh -c 'for i in $(seq 1 60); do curl -sf http://localhost/ > /dev/null 2>&1 && break; echo "Waiting for nginx... ($i/60)"; sleep 2; done'

# Start X on vt7 and run the launcher inside it
ExecStart=/usr/bin/xinit /usr/local/bin/noosphere-display -- :0 vt7 -nolisten tcp

Restart=on-failure
RestartSec=8
TimeoutStartSec=180

[Install]
WantedBy=multi-user.target
SERVICE_EOF

systemctl daemon-reload
echo "Created $SERVICE"

# ── Touchscreen device permissions ───────────────────────────────────────────
# Allow X11 to access touchscreen input devices
if ! grep -q 'noosphere-touch' /etc/udev/rules.d/99-noosphere-touch.rules 2>/dev/null; then
    cat > /etc/udev/rules.d/99-noosphere-touch.rules <<'UDEV_EOF'
# Noosphere touchscreen - allow root/X11 access to touch input events
SUBSYSTEM=="input", ENV{ID_INPUT_TOUCHSCREEN}=="1", MODE="0664", GROUP="input"
UDEV_EOF
    udevadm control --reload-rules 2>/dev/null || true
    echo "Created udev touchscreen rule."
fi

# ── Raspberry Pi: autologin + startx ─────────────────────────────────────────
if [[ "$MODE" == "pi" ]]; then
    echo "--- Pi-specific setup ---"
    if [[ ! -f /etc/systemd/system/getty@tty1.service.d/autologin.conf ]]; then
        mkdir -p /etc/systemd/system/getty@tty1.service.d/
        cat > /etc/systemd/system/getty@tty1.service.d/autologin.conf <<EOF
[Service]
ExecStart=
ExecStart=-/sbin/agetty --autologin root --noclear %I \$TERM
EOF
        echo "Configured autologin on tty1."
    fi
    if ! grep -q 'startx' /root/.bash_profile 2>/dev/null; then
        cat >> /root/.bash_profile <<'EOF'
if [[ -z "$DISPLAY" && "$(tty)" == "/dev/tty1" ]]; then
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
echo "Config:     $CONF_FILE"
echo "Launcher:   $LAUNCHER"
echo "Service:    $SERVICE"
echo
echo "Start now:          systemctl start noosphere-display"
echo "Enable on boot:     systemctl enable noosphere-display"
echo
echo "Display modes:"
echo "  noosphere-display-mode kiosk    ← public touchscreen view"
echo "  noosphere-display-mode command  ← operator command dashboard"
echo "  noosphere-display-mode admin    ← admin panel (login required on-screen)"
echo "  noosphere-display-mode off      ← turn off display"
echo
df -h / | awk 'NR==2 {printf "Disk: %s used of %s (%s free)\n", $3, $2, $4}'
