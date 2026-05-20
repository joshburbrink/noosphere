#!/bin/bash
# migrate-import.sh  -  Import staged Noosphere data on the Pi's first boot.
#
# Run automatically by noosphere-firstboot.service AFTER noosphere-provision.sh
# has built the stack.  Staging is created by migrate-to-pi.sh at
# /var/lib/noosphere-migrate/.  If there is no staging dir, this is a no-op
# (fresh install, --no-data).
#
# Staging layout (same filesystem as the targets, so files are MOVED not copied):
#   $STAGE/fs/var/lib/noosphere/...        -> /var/lib/noosphere/
#   $STAGE/fs/var/lib/kiwix/...            -> /var/lib/kiwix/
#   $STAGE/fs/var/lib/mbtiles/...          -> /var/lib/mbtiles/
#   $STAGE/fs/var/www/noosphere/maps/...   -> /var/www/noosphere/maps/

set -uo pipefail

STAGE="/var/lib/noosphere-migrate"

info() { echo -e "\n>>> $*"; }
ok()   { echo "  ok: $*"; }
warn() { echo "  WARN: $*" >&2; }

[ -d "$STAGE" ] || { echo "No migration staging at $STAGE - nothing to import."; exit 0; }

info "Importing migrated Noosphere data..."

# ── Move each data subtree into place (rsync --remove-source-files keeps peak
#    disk use at ~1x; both sides are on the same ext4 so it's effectively a
#    rename per file).  Existing files from provision are overwritten with the
#    real migrated data (that's the point of the migration). ──────────────────
move_subtree() {
    local sub="$1"               # e.g. var/lib/noosphere
    local src="$STAGE/fs/$sub"
    local dst="/$sub"
    [ -d "$src" ] || return 0
    # skip if the staged subtree is empty
    if [ -z "$(ls -A "$src" 2>/dev/null)" ]; then return 0; fi
    info "Restoring /$sub ..."
    mkdir -p "$dst"
    rsync -aH --remove-source-files "$src/" "$dst/" \
        && ok "/$sub restored" \
        || warn "rsync reported errors restoring /$sub"
    find "$src" -type d -empty -delete 2>/dev/null || true
}

move_subtree "var/lib/noosphere"
move_subtree "var/lib/kiwix"
move_subtree "var/lib/mbtiles"
move_subtree "var/www/noosphere/maps"

# ── Ownership / permissions ───────────────────────────────────────────────────
info "Fixing ownership..."
chown -R www-data:www-data /var/lib/noosphere 2>/dev/null || true
chown -R www-data:www-data /var/lib/kiwix 2>/dev/null || true
chown -R www-data:www-data /var/lib/mbtiles 2>/dev/null || true
chown -R www-data:www-data /var/www/noosphere/maps 2>/dev/null || true
ok "Ownership set to www-data."

# ── Restart data-backed services so they pick up the migrated content ─────────
info "Restarting services..."
for svc in kiwix mbtileserver php8.4-fpm nginx; do
    systemctl restart "$svc" 2>/dev/null && ok "restarted $svc" || warn "could not restart $svc"
done

# ── Cleanup staging to reclaim space ──────────────────────────────────────────
info "Cleaning up staging..."
rm -rf "$STAGE" 2>/dev/null || true
ok "Migration import complete."
