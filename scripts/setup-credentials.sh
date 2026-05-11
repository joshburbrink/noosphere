#!/bin/bash
# setup-credentials.sh — configure Linux user credentials for Noosphere
# Run as root before imaging or after first boot
#
# Usage:
#   ./setup-credentials.sh               # interactive prompts
#   ./setup-credentials.sh -u USERNAME   # set username only
#   ./setup-credentials.sh -p PASSWORD   # set user password only
#   ./setup-credentials.sh -r PASSWORD   # set root password only
#   ./setup-credentials.sh -u USER -p PW -r ROOTPW  # all at once, non-interactive

set -e

if [ "$(id -u)" != "0" ]; then
    echo "Error: must run as root (sudo setup-credentials.sh)" >&2
    exit 1
fi

CURRENT_USER=$(awk -F: '$3==1000{print $1}' /etc/passwd | head -1)
NEW_USER=""
NEW_USER_PW=""
NEW_ROOT_PW=""
INTERACTIVE=true

# Parse args
while getopts "u:p:r:" opt; do
    case $opt in
        u) NEW_USER="$OPTARG";     INTERACTIVE=false ;;
        p) NEW_USER_PW="$OPTARG";  INTERACTIVE=false ;;
        r) NEW_ROOT_PW="$OPTARG";  INTERACTIVE=false ;;
        *) echo "Usage: $0 [-u username] [-p user_password] [-r root_password]"; exit 1 ;;
    esac
done

if [ "$INTERACTIVE" = true ]; then
    echo "=== Noosphere Credential Setup ==="
    echo "Current Linux user: $CURRENT_USER"
    echo

    read -p "New username (leave blank to keep '$CURRENT_USER'): " NEW_USER

    read -s -p "New password for ${NEW_USER:-$CURRENT_USER} (leave blank to skip): " NEW_USER_PW
    echo
    if [ -n "$NEW_USER_PW" ]; then
        read -s -p "Confirm password: " NEW_USER_PW2
        echo
        if [ "$NEW_USER_PW" != "$NEW_USER_PW2" ]; then
            echo "Error: passwords do not match." >&2
            exit 1
        fi
    fi

    read -s -p "New root password (leave blank to skip): " NEW_ROOT_PW
    echo
    if [ -n "$NEW_ROOT_PW" ]; then
        read -s -p "Confirm root password: " NEW_ROOT_PW2
        echo
        if [ "$NEW_ROOT_PW" != "$NEW_ROOT_PW2" ]; then
            echo "Error: root passwords do not match." >&2
            exit 1
        fi
    fi
fi

CHANGED=0

# Rename user if requested
if [ -n "$NEW_USER" ] && [ "$NEW_USER" != "$CURRENT_USER" ]; then
    echo "Renaming user '$CURRENT_USER' -> '$NEW_USER'..."
    usermod -l "$NEW_USER" "$CURRENT_USER"
    usermod -d "/home/$NEW_USER" -m "$NEW_USER"
    groupmod -n "$NEW_USER" "$CURRENT_USER" 2>/dev/null || true
    CURRENT_USER="$NEW_USER"
    CHANGED=1
    echo "  Done."
fi

# Change user password
if [ -n "$NEW_USER_PW" ]; then
    echo "Setting password for '$CURRENT_USER'..."
    echo "$CURRENT_USER:$NEW_USER_PW" | chpasswd
    CHANGED=1
    echo "  Done."
fi

# Change root password
if [ -n "$NEW_ROOT_PW" ]; then
    echo "Setting root password..."
    echo "root:$NEW_ROOT_PW" | chpasswd
    CHANGED=1
    echo "  Done."
fi

if [ "$CHANGED" = 0 ]; then
    echo "No changes made."
else
    echo
    echo "Credentials updated. Current user: $CURRENT_USER"
    echo "These settings persist when cloning this drive."
fi
