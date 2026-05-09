#!/bin/bash
# download-zim.sh — Download Kiwix ZIM content for Noosphere
# Usage: ./download-zim.sh [destination]
# Default destination: /mnt/noosphere/kiwix

set -euo pipefail

DEST="${1:-/mnt/noosphere/kiwix}"
CATALOG="https://library.kiwix.org/catalog/v2/entries"

mkdir -p "$DEST"

log() { echo "[$(date '+%H:%M:%S')] $*"; }

# Use aria2c for parallel/resumable downloads if available, fall back to wget
if command -v aria2c &>/dev/null; then
    download() { aria2c --continue=true --max-connection-per-server=4 --dir="$DEST" "$1"; }
else
    download() { wget -c -P "$DEST" "$1"; }
fi

# Resolve current download URL from Kiwix catalog API
get_url() {
    curl -sf "${CATALOG}?lang=eng&name=${1}&count=1" \
        | grep -oE 'https://[^"<> ]+\.zim' \
        | head -1
}

declare -A BOOKS
BOOKS["wikipedia_en_all_maxi"]="Wikipedia EN full with images (~100GB)"
BOOKS["wikimed_en_all_maxi"]="WikiMed medical encyclopedia (~3GB)"
BOOKS["wikihow_en_maxi"]="WikiHow how-to guides (~10GB)"
BOOKS["ifixit_en_all"]="iFixit repair guides (~5GB)"
BOOKS["wikibooks_en_all_maxi"]="Wikibooks free textbooks (~5GB)"
BOOKS["stackoverflow.com_en_all"]="Stack Overflow (~34GB)"
BOOKS["wikivoyage_en_all_maxi"]="Wikivoyage geography & travel (~1GB)"
BOOKS["khan_academy_en_all"]="Khan Academy (~30GB)"
BOOKS["gutenberg_en_all"]="Project Gutenberg (~60GB)"
BOOKS["home.stackexchange.com_en_all"]="Stack Exchange: Home Improvement (~2GB)"
BOOKS["cooking.stackexchange.com_en_all"]="Stack Exchange: Cooking (~1GB)"
BOOKS["ham.stackexchange.com_en_all"]="Stack Exchange: Amateur Radio (~500MB)"
BOOKS["wiktionary_en_all_maxi"]="Wiktionary EN (~5GB)"

echo "=================================="
echo "  Noosphere ZIM Content Downloader"
echo "=================================="
echo "Destination: $DEST"
du -sh "$DEST" 2>/dev/null && echo "" || echo ""

TOTAL_SKIPPED=0
TOTAL_DOWNLOADED=0
FAILED=()

for name in "${!BOOKS[@]}"; do
    desc="${BOOKS[$name]}"
    log "Resolving: $name — $desc"

    url=$(get_url "$name" || true)

    if [[ -z "$url" ]]; then
        log "WARNING: Could not resolve URL for $name — skipping"
        FAILED+=("$name")
        continue
    fi

    filename=$(basename "$url")

    if [[ -f "$DEST/$filename" ]]; then
        log "Already exists: $filename — skipping"
        ((TOTAL_SKIPPED++)) || true
        continue
    fi

    log "Downloading: $filename"
    if download "$url"; then
        log "Done: $filename"
        ((TOTAL_DOWNLOADED++)) || true
    else
        log "ERROR: Failed to download $filename"
        FAILED+=("$name")
    fi
    echo ""
done

echo ""
echo "=================================="
log "Summary:"
log "  Downloaded: $TOTAL_DOWNLOADED"
log "  Skipped (already present): $TOTAL_SKIPPED"
log "  Failed: ${#FAILED[@]}"
if [[ ${#FAILED[@]} -gt 0 ]]; then
    log "  Failed books: ${FAILED[*]}"
    log "  Check catalog names at: https://library.kiwix.org"
fi
log "ZIM files in: $DEST"
du -sh "$DEST"
echo "=================================="