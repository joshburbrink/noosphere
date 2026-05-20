#!/usr/bin/env bash
# install-region-pack.sh PACK.tar.gz [--no-verify]
# Extracts a region pack into /var/lib/noosphere/regions/<slug>/.
# Validates that the tarball top-level is a single directory containing region.json.
# If manifest.json is present, verifies SHA-256 checksums. Prints signer info if signed.
set -euo pipefail

NO_VERIFY=0
PACK=""

for arg in "$@"; do
    case "$arg" in
        --no-verify) NO_VERIFY=1 ;;
        *) PACK="$arg" ;;
    esac
done

if [ -z "$PACK" ]; then
  echo "usage: $0 <region-pack.tar.gz> [--no-verify]" >&2
  exit 64
fi

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

# ── Manifest verification (if manifest.json present) ────────────────────────

HAS_MANIFEST=0
HAS_SIG=0
tar -tzf "$PACK" | grep -qx "$TOP/manifest.json" && HAS_MANIFEST=1
tar -tzf "$PACK" | grep -qx "$TOP/manifest.json.sig" && HAS_SIG=1

if [ "$HAS_MANIFEST" = "1" ] && [ "$NO_VERIFY" = "0" ]; then
    echo "manifest.json found  -  verifying checksums..."
    TMPDIR_VERIFY=$(mktemp -d /tmp/noosphere-install-XXXXXX)
    cleanup_verify() { rm -rf "$TMPDIR_VERIFY"; }
    trap cleanup_verify EXIT

    tar -xzf "$PACK" -C "$TMPDIR_VERIFY"
    WORK_DIR="$TMPDIR_VERIFY/$TOP"

    # Verify SHA-256 checksums
    python3 - "$WORK_DIR" << 'PYEOF'
import json, hashlib, sys, os

work_dir = sys.argv[1]
manifest = json.load(open(os.path.join(work_dir, 'manifest.json')))
files = manifest.get('files', {})
errors = []
for rel, expected in files.items():
    full = os.path.join(work_dir, rel)
    if not os.path.exists(full):
        errors.append(f"  MISSING: {rel}")
        continue
    h = hashlib.sha256()
    with open(full, 'rb') as f:
        for chunk in iter(lambda: f.read(65536), b''):
            h.update(chunk)
    if h.hexdigest() != expected:
        errors.append(f"  MISMATCH: {rel}")

if errors:
    print("CHECKSUM VERIFICATION FAILED:")
    for e in errors:
        print(e)
    sys.exit(1)
else:
    print(f"  Checksums OK ({len(files)} files verified)")
    created = manifest.get('created_at', 'unknown')
    print(f"  Pack signed at: {created}")
PYEOF
    echo "Checksum verification passed."

    # GPG signature check
    if [ "$HAS_SIG" = "1" ]; then
        SIG_FILE="$WORK_DIR/manifest.json.sig"
        MANIFEST_FILE="$WORK_DIR/manifest.json"
        if command -v gpg >/dev/null 2>&1; then
            echo ""
            echo "GPG signature found  -  verifying..."
            if gpg --batch --verify "$SIG_FILE" "$MANIFEST_FILE" 2>&1; then
                FINGERPRINT=$(gpg --batch --verify "$SIG_FILE" "$MANIFEST_FILE" 2>&1 | grep -oE '[0-9A-F]{16,}' | head -1 || echo "unknown")
                echo "  Signature valid. Key: $FINGERPRINT"
            else
                echo "  WARNING: GPG signature could not be verified."
                echo "  (The pack may still be safe if you trust its source.)"
            fi
        else
            echo "  note: gpg not installed  -  skipping signature check"
        fi
    fi
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
