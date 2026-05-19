#!/usr/bin/env bash
# seed-builtin-regions.sh
# Copies repo-bundled region packs (regions/<slug>/) into
# /var/lib/noosphere/regions/. Idempotent - rsync-style overlay so local
# edits to a managed pack (e.g. extra topo PDFs dropped under the pack dir
# on the server) are preserved. region.json and other shipped files are
# always refreshed from the repo.
set -euo pipefail

REPO_DIR="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$REPO_DIR/regions"
DEST="/var/lib/noosphere/regions"

if [ ! -d "$SRC" ]; then
  echo "no $SRC found, nothing to seed" >&2
  exit 0
fi

mkdir -p "$DEST"

for d in "$SRC"/*/; do
  [ -d "$d" ] || continue
  slug=$(basename "$d")
  target="$DEST/$slug"
  mkdir -p "$target"
  # Overlay shipped files but don't delete extras the operator added on disk.
  cp -a "$d"/. "$target"/
  chown -R www-data:www-data "$target" 2>/dev/null || true
  echo "seeded: $target"
done
