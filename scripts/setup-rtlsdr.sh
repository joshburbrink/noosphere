#!/bin/bash
# Install RTL-SDR support: blacklist conflicting DVB drivers, install
# rtl-433 + direwolf + multimon-ng, deploy admin scripts, and stage the
# NWR transcription pipeline. Idempotent - safe to re-run.
# Run as root on the Noosphere server.

set -e
REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"

echo "==> Blacklisting kernel DVB drivers (they grab the dongle on plug-in)"
cat > /etc/modprobe.d/rtlsdr-blacklist.conf <<'EOF'
# Prevent the kernel from binding the RTL2832U as a DVB-T device.
# Required for rtl-sdr / rtl_fm / rtl_power to open the dongle.
blacklist dvb_usb_rtl28xxu
blacklist rtl2832_sdr
blacklist rtl2832
blacklist dvb_usb_v2
blacklist dvb_core
EOF
for m in rtl2832_sdr dvb_usb_rtl28xxu rtl2832 dvb_usb_v2 dvb_core; do
    rmmod "$m" 2>/dev/null || true
done

echo "==> Installing rtl-433, direwolf, multimon-ng"
# multimon-ng is required by noaa-capture.sh for EAS / SAME decode.
apt-get install -y rtl-433 direwolf multimon-ng python3

echo "==> Adding www-data to plugdev (matches the udev rule below)"
usermod -aG plugdev www-data 2>/dev/null || true

echo "==> Deploying scripts"
install -m 755 "$REPO_DIR/scripts/rtl433-bridge.py"               /usr/local/bin/noosphere-rtl433-bridge.py
install -m 755 "$REPO_DIR/scripts/aprs-writer.py"                 /usr/local/bin/noosphere-aprs-writer.py
install -m 755 "$REPO_DIR/scripts/noosphere-radio-mode.sh"        /usr/local/bin/noosphere-radio-mode.sh
# Admin UI calls these by /usr/local/bin/ path - sudoers must match.
install -m 755 "$REPO_DIR/scripts/noosphere-set-nwr-freq.sh"      /usr/local/bin/noosphere-set-nwr-freq.sh
install -m 755 "$REPO_DIR/scripts/noosphere-scan-nwr.sh"          /usr/local/bin/noosphere-scan-nwr.sh
# NWR capture + SAME logger + transcription pipeline.
install -m 755 "$REPO_DIR/scripts/noaa-log-alert.py"              /usr/local/bin/noaa-log-alert.py
install -m 755 "$REPO_DIR/scripts/noosphere-weather-transcribe.py" /usr/local/bin/noosphere-weather-transcribe.py

echo "==> Writing default config files"
mkdir -p /etc/noosphere

[ -f /etc/noosphere/rtl433.conf ] || cat > /etc/noosphere/rtl433.conf <<'EOF'
# rtl_433 sensor filter  -  leave blank to accept all sensors on the air
# Find your sensor's id/model by running: rtl_433 -F json
sensor_id=
sensor_model=
EOF

[ -f /etc/noosphere/aprs.conf ] || cat > /etc/noosphere/aprs.conf <<'EOF'
# APRS receiver settings
APRS_FREQ=144.3900
EOF

echo "==> Installing udev rule (non-root USB access)"
cat > /etc/udev/rules.d/99-rtlsdr.rules <<'EOF'
SUBSYSTEMS=="usb", ATTRS{idVendor}=="0bda", ATTRS{idProduct}=="2832", MODE="0664", GROUP="plugdev"
SUBSYSTEMS=="usb", ATTRS{idVendor}=="0bda", ATTRS{idProduct}=="2838", MODE="0664", GROUP="plugdev"
SUBSYSTEMS=="usb", ATTRS{idVendor}=="0bda", ATTRS{idProduct}=="2840", MODE="0664", GROUP="plugdev"
SUBSYSTEMS=="usb", ATTRS{idVendor}=="0bda", ATTRS{idProduct}=="2841", MODE="0664", GROUP="plugdev"
SUBSYSTEMS=="usb", ATTRS{idVendor}=="0bda", ATTRS{idProduct}=="2846", MODE="0664", GROUP="plugdev"
EOF
udevadm control --reload-rules

echo "==> Deploying systemd units"
install -m 644 "$REPO_DIR/systemd/noosphere-rtl433.service"                /etc/systemd/system/
install -m 644 "$REPO_DIR/systemd/noosphere-aprs.service"                  /etc/systemd/system/
install -m 644 "$REPO_DIR/systemd/noosphere-aprs-writer.service"           /etc/systemd/system/
# NWR transcription timer (admin UI enables/disables; operator picks backend).
[ -f "$REPO_DIR/systemd/noosphere-weather-transcribe.service" ] && \
    install -m 644 "$REPO_DIR/systemd/noosphere-weather-transcribe.service" /etc/systemd/system/
[ -f "$REPO_DIR/systemd/noosphere-weather-transcribe.timer" ] && \
    install -m 644 "$REPO_DIR/systemd/noosphere-weather-transcribe.timer"   /etc/systemd/system/
systemctl daemon-reload
# Units are disabled by default; admin panel enables them when mode is set

echo "==> Creating APRS database directory"
mkdir -p /var/lib/noosphere
# aprs.db is created on first write by the APRS writer

echo ""
echo "setup-rtlsdr.sh complete."
echo "  - Plug in your RTL-SDR dongle, then enable a mode in Admin -> SDR Radio."
echo "  - rtl_433: verify sensor with: rtl_433 -F json (run as root or plugdev member)"
echo "  - APRS:    verify dongle with: rtl_fm -f 144390000 -s 24k | aplay -r 24000 -f S16_LE -t raw"
