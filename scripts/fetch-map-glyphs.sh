#!/bin/bash
# fetch-map-glyphs.sh - install the MapLibre glyph set the map style needs.
#
# WHY THIS EXISTS
# The offline map rendered completely blank, and the cause was not the tiles.
# /var/www/noosphere/maps/fonts/"Noto Sans Regular"/*.pbf were not protobuf at
# all - they were 2725-byte copies of the openmaptiles.org font-server HTML
# landing page, saved by a download that "succeeded" (HTTP 200) but returned a
# web page. Symbol layers then failed to build, which errors the WHOLE tile,
# so MapLibre painted only the background colour. The tiles themselves were
# fine. This would blank ANY region, not just a particular one.
#
# The lesson: that font URL now serves HTML for every request, so any rebuild
# using the old fetch will silently re-break the map. Fetch from the release
# zip instead, and verify the bytes are protobuf rather than trusting HTTP 200.
set -euo pipefail

DEST="${1:-/var/www/noosphere/maps/fonts}"
FONT="Noto Sans Regular"
URL="https://github.com/openmaptiles/fonts/releases/download/v2.0/noto-sans.zip"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "== downloading glyph set =="
curl -fsSL "$URL" -o "$TMP/noto-sans.zip"
unzip -q "$TMP/noto-sans.zip" -d "$TMP/x"

SRC="$(find "$TMP/x" -type d -name "$FONT" | head -1)"
[ -n "$SRC" ] || { echo "'$FONT' not found in the archive" >&2; exit 1; }

echo "== validating (the failure mode is a valid-looking HTML file) =="
first="$SRC/0-255.pbf"
[ -f "$first" ] || { echo "missing $first" >&2; exit 1; }
if head -c 64 "$first" | grep -qi "<!DOCTYPE\|<html"; then
    echo "downloaded glyphs are HTML, not protobuf - refusing to install" >&2
    exit 1
fi
# A real glyph PBF starts with field 1 (stacks), wire type 2 -> 0x0a.
[ "$(head -c 1 "$first" | od -An -tx1 | tr -d ' ')" = "0a" ] \
    || { echo "$first does not start with a protobuf field tag" >&2; exit 1; }
echo "  ok: $(ls "$SRC" | wc -l) range files"

echo "== installing to $DEST/$FONT =="
mkdir -p "$DEST"
if [ -d "$DEST/$FONT" ] && ! head -c 64 "$DEST/$FONT/0-255.pbf" 2>/dev/null | grep -qi "<!DOCTYPE\|<html"; then
    echo "  existing glyphs look valid; replacing anyway"
fi
rm -rf "$DEST/$FONT.new"
cp -r "$SRC" "$DEST/$FONT.new"
rm -rf "$DEST/$FONT"
mv "$DEST/$FONT.new" "$DEST/$FONT"
chown -R www-data:www-data "$DEST/$FONT" 2>/dev/null || true

echo "== done =="
ls "$DEST/$FONT" | wc -l | sed 's/^/  range files: /'
du -sh "$DEST/$FONT" | sed 's/^/  size: /'
echo
echo "NOTE: browsers cache the bad responses. After fixing, force a reload"
echo "      (Chromium: fetch with cache:'reload') or the map still looks broken."
