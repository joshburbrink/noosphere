#!/bin/bash
# Fetch MapLibre GL JS + Noto Sans glyphs into the maps/ tree.
# Required for the /maps/ page to render; both directories are gitignored
# (binary assets + large glyph set) so fresh installs need this step.
#
# Idempotent: re-runs are safe; existing files are kept unless --force.

set -e

MAPLIBRE_VERSION="4.7.1"
WEBROOT="${WEBROOT:-/var/www/noosphere}"
LIB_DIR="$WEBROOT/maps/lib"
FONT_DIR="$WEBROOT/maps/fonts/Noto Sans Regular"
FORCE=0
[ "$1" = "--force" ] && FORCE=1

info()  { echo "[fetch-map-assets] $*"; }
fetch() {
    local url="$1" dest="$2"
    if [ "$FORCE" -eq 0 ] && [ -s "$dest" ]; then
        info "skip (exists): $dest"
        return 0
    fi
    info "GET $url"
    mkdir -p "$(dirname "$dest")"
    curl -fsSL --retry 3 --retry-delay 2 -o "$dest.tmp" "$url"
    mv "$dest.tmp" "$dest"
}

# MapLibre GL JS
fetch "https://unpkg.com/maplibre-gl@${MAPLIBRE_VERSION}/dist/maplibre-gl.js"  "$LIB_DIR/maplibre-gl.js"
fetch "https://unpkg.com/maplibre-gl@${MAPLIBRE_VERSION}/dist/maplibre-gl.css" "$LIB_DIR/maplibre-gl.css"

# Glyph ranges actually referenced by the OpenMapTiles style for Latin text.
# Keep this small on purpose - a full 0-65535 sweep is ~10MB and we don't
# render CJK/etc. Add ranges here if a style needs them.
GLYPH_RANGES=(
    "0-255"      # Basic Latin + Latin-1 Supplement
    "256-511"    # Latin Extended-A
    "8192-8447"  # General Punctuation
)
for range in "${GLYPH_RANGES[@]}"; do
    fetch "https://fonts.openmaptiles.org/Noto%20Sans%20Regular/${range}.pbf" "$FONT_DIR/${range}.pbf"
done

if id www-data &>/dev/null; then
    chown -R www-data:www-data "$LIB_DIR" "$WEBROOT/maps/fonts"
fi

info "done. MapLibre ${MAPLIBRE_VERSION} + ${#GLYPH_RANGES[@]} glyph ranges in place."
