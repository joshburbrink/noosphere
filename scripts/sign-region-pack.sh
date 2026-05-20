#!/usr/bin/env bash
# sign-region-pack.sh  -  create a verifiable manifest for a region pack.
#
# Usage:
#   sign-region-pack.sh <pack-dir-or-tarball>  [--gpg-key <keyid>]
#
# Creates manifest.json alongside region.json with SHA-256 of every file.
# With --gpg-key, also produces manifest.json.sig (detached armored signature).
#
# A signed pack can be shared with other Noosphere deployments and verified
# on install by install-region-pack.sh.

set -euo pipefail

PACK=""
GPG_KEY=""

usage() { echo "usage: $0 <pack-dir-or-tarball> [--gpg-key <keyid>]" >&2; exit 64; }

while [[ $# -gt 0 ]]; do
    case "$1" in
        --gpg-key) GPG_KEY="$2"; shift 2 ;;
        -*)        echo "unknown option: $1" >&2; usage ;;
        *)         PACK="$1"; shift ;;
    esac
done
[ -z "$PACK" ] && usage

# ── Work out if we got a directory or a tarball ──────────────────────────────

TMPDIR_SIGN=""
cleanup() { [ -n "$TMPDIR_SIGN" ] && rm -rf "$TMPDIR_SIGN"; }
trap cleanup EXIT

if [ -d "$PACK" ]; then
    WORK_DIR="$PACK"
elif [ -f "$PACK" ]; then
    TMPDIR_SIGN=$(mktemp -d /tmp/noosphere-sign-XXXXXX)
    echo "Extracting $PACK..."
    tar -xzf "$PACK" -C "$TMPDIR_SIGN"
    # Find the top-level slug dir
    SLUG_DIR=$(find "$TMPDIR_SIGN" -maxdepth 1 -mindepth 1 -type d | head -1)
    [ -z "$SLUG_DIR" ] && { echo "error: no top-level directory in tarball" >&2; exit 65; }
    WORK_DIR="$SLUG_DIR"
else
    echo "error: $PACK is not a directory or file" >&2; exit 66
fi

# Validate
[ -f "$WORK_DIR/region.json" ] || { echo "error: region.json not found in $WORK_DIR" >&2; exit 65; }

SLUG=$(python3 -c "import json; print(json.load(open('$WORK_DIR/region.json'))['slug'])" 2>/dev/null || basename "$WORK_DIR")

echo "Signing region pack: $SLUG"
echo "Pack directory: $WORK_DIR"

# ── Build manifest ───────────────────────────────────────────────────────────

python3 - "$WORK_DIR" "$SLUG" << 'PYEOF'
import json, hashlib, os, sys, datetime

work_dir = sys.argv[1]
slug     = sys.argv[2]

files = {}
for root, dirs, fnames in os.walk(work_dir):
    dirs.sort()
    for fn in sorted(fnames):
        if fn in ('manifest.json', 'manifest.json.sig'):
            continue
        full = os.path.join(root, fn)
        rel  = os.path.relpath(full, work_dir)
        h = hashlib.sha256()
        with open(full, 'rb') as f:
            for chunk in iter(lambda: f.read(65536), b''):
                h.update(chunk)
        files[rel] = h.hexdigest()

manifest = {
    "schema_version": 1,
    "slug": slug,
    "created_at": datetime.datetime.utcnow().strftime('%Y-%m-%dT%H:%M:%SZ'),
    "files": files
}

out = os.path.join(work_dir, 'manifest.json')
with open(out, 'w') as f:
    json.dump(manifest, f, indent=2, sort_keys=True)
print(f"Wrote {out}  ({len(files)} files)")
PYEOF

echo "Manifest created."

# ── GPG sign the manifest ─────────────────────────────────────────────────────

if [ -n "$GPG_KEY" ]; then
    SIG="$WORK_DIR/manifest.json.sig"
    echo "Signing with GPG key: $GPG_KEY"
    gpg --batch --yes --armor --detach-sign \
        --local-user "$GPG_KEY" \
        --output "$SIG" \
        "$WORK_DIR/manifest.json" \
        || { echo "error: gpg signing failed" >&2; exit 1; }
    FINGERPRINT=$(gpg --batch --with-colons --fingerprint "$GPG_KEY" 2>/dev/null | awk -F: '$1=="fpr"{print $10; exit}')
    echo "Signed. Fingerprint: $FINGERPRINT"
    echo "Signature:  $SIG"
fi

# ── Repack if input was a tarball ─────────────────────────────────────────────

if [ -f "$PACK" ]; then
    ORIG_BASENAME=$(basename "${PACK%.tar.gz}")
    OUT_PACK="${PACK%.tar.gz}-signed.tar.gz"
    echo "Repacking -> $OUT_PACK"
    tar -czf "$OUT_PACK" -C "$(dirname "$WORK_DIR")" "$(basename "$WORK_DIR")"
    echo "Signed pack: $OUT_PACK"
else
    echo "Manifest written in-place. Re-package the directory to distribute."
fi

echo "Done."
