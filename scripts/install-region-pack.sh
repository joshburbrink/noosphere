#!/usr/bin/env bash
# install-region-pack.sh PACK.tar.gz
# Extracts a region pack into /var/lib/noosphere/regions/<slug>/.
# Validates that the tarball top-level is a single directory containing region.json.
set -euo pipefail

if [ $# -lt 1 ]; then
  echo "usage: $0 <region-pack.tar.gz>" >&2
  exit 64
fi

PACK="$1"
DEST_BASE="/var/lib/noosphere/regions"

if [ ! -f "$PACK" ]; then
  echo "error: $PACK not found" >&2
  exit 66
fi

# Find top-level dir
TOP=$(tar -tzf "$PACK" | awk -F/ 'NF>1 {print $1; exit}')
if [ -z "$TOP" ]; then
  echo "error: tarball has no top-level directory" >&2
  exit 65
fi
if ! [[ "$TOP" =~ ^[a-z0-9][a-z0-9_-]{0,63}$ ]]; then
  echo "error: invalid slug '$TOP' (must be [a-z0-9_-], 1-64 chars, leading alnum)" >&2
  exit 65
fi

# Validate it contains region.json
if ! tar -tzf "$PACK" | grep -qx "$TOP/region.json"; then
  echo "error: $TOP/region.json missing from tarball" >&2
  exit 65
fi

DEST="$DEST_BASE/$TOP"
mkdir -p "$DEST_BASE"

if [ -d "$DEST" ]; then
  STAMP=$(date +%Y%m%d-%H%M%S)
  echo "note: $DEST already exists, moving aside to $DEST.bak-$STAMP" >&2
  mv "$DEST" "$DEST.bak-$STAMP"
fi

tar -xzf "$PACK" -C "$DEST_BASE"
chown -R www-data:www-data "$DEST" || true
chmod -R u=rwX,go=rX "$DEST" || true

echo "installed: $DEST"
echo "to activate: set 'active_region=$TOP' from Admin / Region tab"
