#!/bin/bash
# Install system tools needed for noosphere server administration.
# Run as root while the system has internet access (before going offline).
set -e

PACKAGES=(
    # Session management — keeps work alive if SSH drops
    tmux

    # Version control — pull updates from GitHub when internet is available
    git

    # Editor
    vim

    # Network diagnostics
    nmap
    tcpdump
    net-tools

    # JSON parsing for scripts
    jq

    # Disk management
    ncdu
    smartmontools
    parted
    dosfstools
    exfatprogs

    # System monitoring
    htop
    sysstat
)

echo "Installing ${#PACKAGES[@]} packages..."
apt-get update -qq
apt-get install -y "${PACKAGES[@]}"
echo ""
echo "Done. All tools installed."
echo ""
echo "Tip: run 'smartctl -a /dev/sda' (or your boot device) to check drive health."
