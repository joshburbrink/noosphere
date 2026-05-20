#!/usr/bin/env bash
# noosphere-build-region.sh  -  build a region pack from public data sources.
#
# Usage: noosphere-build-region.sh [options] <slug> <label> <country>
#
# Options:
#   --state <geofabrik-state>   Geofabrik state slug (e.g. "indiana"), US only
#   --counties "C1,C2"          Comma-separated county names
#   --bbox "S,W,N,E"            Bounding box in decimal degrees (required for tiles/topo)
#   --climate-zone <zone>       e.g. "6a"
#   --center-lat <lat>          Map center lat (defaults to bbox center)
#   --center-lng <lng>          Map center lng (defaults to bbox center)
#   --zoom <n>                  Default zoom level (default: 11)
#   --fetch-tiles               Download OSM PBF + build vector MBTiles
#   --fetch-topo                Download USGS topo PDFs (US only)
#   --nwr-all                   Include all 7 NWR frequencies
#   --nwr-freqs "f1,f2,..."     Specific NWR frequencies
#
# Status is written to /var/lib/noosphere/region-builds/<slug>/status.json.
# Log is written to /var/lib/noosphere/region-builds/<slug>/output.log.

set -uo pipefail

SLUG=""
LABEL=""
COUNTRY=""
STATE=""
COUNTIES=""
BBOX=""
CLIMATE_ZONE=""
CENTER_LAT=""
CENTER_LNG=""
ZOOM="11"
FETCH_TILES=0
FETCH_TOPO=0
NWR_ALL=0
NWR_FREQS=""

STATUS_BASE="/var/lib/noosphere/region-builds"
REGIONS_BASE="/var/lib/noosphere/regions"
MAPS_DIR="/var/www/noosphere/maps"

BUILD_STARTED=$(date +%s)

usage() { echo "usage: $0 [options] <slug> <label> <country>" >&2; exit 64; }

die() {
    echo "ERROR: $*" >&2
    _update_status "error" "$*" 100
    exit 1
}

_status_log_tail() {
    local logfile="$STATUS_BASE/$SLUG/output.log"
    [ -f "$logfile" ] && tail -c 1800 "$logfile" | python3 -c \
        'import sys,json; print(json.dumps(sys.stdin.buffer.read().decode("utf-8","replace")))' \
        2>/dev/null || echo '""'
}

_update_status() {
    local status="$1" step="$2" pct="$3"
    [ -z "$SLUG" ] && return
    mkdir -p "$STATUS_BASE/$SLUG"
    printf '{"status":"%s","step":"%s","pct":%d,"pid":%d,"started":%d,"log":%s}\n' \
        "$status" "$(printf '%s' "$step" | sed 's/\\/\\\\/g; s/"/\\"/g')" \
        "$pct" "$$" "$BUILD_STARTED" "$(_status_log_tail)" \
        > "$STATUS_BASE/$SLUG/status.json"
}

# ── Parse positional args then options ──────────────────────────────────────

if [ $# -lt 3 ]; then usage; fi
SLUG="$1"; LABEL="$2"; COUNTRY="$3"; shift 3

if ! [[ "$SLUG" =~ ^[a-z0-9][a-z0-9_-]{0,63}$ ]]; then
    echo "error: invalid slug '$SLUG'" >&2; exit 65
fi

while [[ $# -gt 0 ]]; do
    case "$1" in
        --state)        STATE="$2"; shift 2 ;;
        --counties)     COUNTIES="$2"; shift 2 ;;
        --bbox)         BBOX="$2"; shift 2 ;;
        --climate-zone) CLIMATE_ZONE="$2"; shift 2 ;;
        --center-lat)   CENTER_LAT="$2"; shift 2 ;;
        --center-lng)   CENTER_LNG="$2"; shift 2 ;;
        --zoom)         ZOOM="$2"; shift 2 ;;
        --fetch-tiles)  FETCH_TILES=1; shift ;;
        --fetch-topo)   FETCH_TOPO=1; shift ;;
        --nwr-all)      NWR_ALL=1; shift ;;
        --nwr-freqs)    NWR_FREQS="$2"; shift 2 ;;
        *) echo "error: unknown option $1" >&2; exit 64 ;;
    esac
done

# ── Set up logging ───────────────────────────────────────────────────────────

mkdir -p "$STATUS_BASE/$SLUG"
LOG="$STATUS_BASE/$SLUG/output.log"
# Truncate log on fresh build, not on restart of same slug
: > "$LOG"
exec > >(tee -a "$LOG") 2>&1

echo "=== Noosphere region build: $SLUG ==="
echo "    Label:   $LABEL"
echo "    Country: $COUNTRY"
echo "    State:   ${STATE:-(none)}"
echo "    Counties: ${COUNTIES:-(none)}"
echo "    BBOX:    ${BBOX:-(none)}"
echo "    Started: $(date)"
echo ""
_update_status "running" "Starting..." 0

# ── Parse bbox ───────────────────────────────────────────────────────────────

BBOX_S=""; BBOX_W=""; BBOX_N=""; BBOX_E=""
if [ -n "$BBOX" ]; then
    IFS=',' read -r BBOX_S BBOX_W BBOX_N BBOX_E <<< "$BBOX"
    if [ -z "$CENTER_LAT" ]; then
        CENTER_LAT=$(python3 -c "print(round(($BBOX_S + $BBOX_N) / 2, 4))")
    fi
    if [ -z "$CENTER_LNG" ]; then
        CENTER_LNG=$(python3 -c "print(round(($BBOX_W + $BBOX_E) / 2, 4))")
    fi
fi

# ── Disk space check ─────────────────────────────────────────────────────────

FREE_KB=$(df "$MAPS_DIR" --output=avail 2>/dev/null | tail -1 | tr -d ' ' || echo 0)
if (( FREE_KB < 1200000 )); then
    die "Not enough disk space (need ~1.2 GB free, have $(( FREE_KB / 1024 )) MB). Free up space first."
fi
echo "Disk free: $(( FREE_KB / 1024 )) MB  -  OK"

# ── Install tippecanoe if needed ─────────────────────────────────────────────

if [ "$FETCH_TILES" = "1" ]; then
    _update_status "running" "Checking tools..." 2
    if ! command -v tippecanoe >/dev/null 2>&1; then
        echo "tippecanoe not installed  -  installing..."
        apt-get install -y tippecanoe >/dev/null 2>&1 || die "Could not install tippecanoe. Run: apt install tippecanoe"
        echo "tippecanoe installed."
    fi
    if ! command -v osmium >/dev/null 2>&1; then
        apt-get install -y osmium-tool >/dev/null 2>&1 || die "osmium-tool not installed. Run: apt install osmium-tool"
    fi
fi

# ── Create region directory ──────────────────────────────────────────────────

DEST="$REGIONS_BASE/$SLUG"
mkdir -p "$DEST/topo" "$DEST/radio" "$DEST/seeds"

TMPDIR_BUILD=$(mktemp -d /tmp/noosphere-region-XXXXXX)
cleanup() { rm -rf "$TMPDIR_BUILD"; }
trap cleanup EXIT

TILE_URL=""

# ── Fetch tiles: OSM PBF -> MBTiles ─────────────────────────────────────────

if [ "$FETCH_TILES" = "1" ]; then
    [ "$COUNTRY" != "US" ] && die "--fetch-tiles currently requires country=US"
    [ -z "$STATE" ]        && die "--fetch-tiles requires --state <geofabrik-state-slug>"
    [ -z "$BBOX" ]         && die "--fetch-tiles requires --bbox S,W,N,E"

    _update_status "running" "Downloading OSM state extract from Geofabrik..." 8
    PBF_URL="https://download.geofabrik.de/north-america/us/${STATE}-latest.osm.pbf"
    PBF_LOCAL="$TMPDIR_BUILD/state.osm.pbf"
    echo "Downloading: $PBF_URL"
    curl -fL --retry 3 --retry-delay 5 --progress-bar -o "$PBF_LOCAL" "$PBF_URL" \
        || die "Failed to download $PBF_URL"

    _update_status "running" "Extracting bounding box with osmium..." 38
    EXTRACT_PBF="$TMPDIR_BUILD/extract.osm.pbf"
    echo "Extracting bbox: W=$BBOX_W S=$BBOX_S E=$BBOX_E N=$BBOX_N"
    osmium extract --bbox "${BBOX_W},${BBOX_S},${BBOX_E},${BBOX_N}" \
        "$PBF_LOCAL" -o "$EXTRACT_PBF" --overwrite \
        || die "osmium extract failed"
    rm -f "$PBF_LOCAL"

    _update_status "running" "Building vector tiles with tippecanoe (this may take several minutes)..." 50
    MBTILES="$MAPS_DIR/region-${SLUG}.mbtiles"
    echo "Running tippecanoe -> $MBTILES"
    tippecanoe -o "$MBTILES" -Z4 -z14 --force \
        --simplification=4 --drop-densest-as-needed \
        "$EXTRACT_PBF" \
        || die "tippecanoe failed"
    rm -f "$EXTRACT_PBF"

    chown www-data:www-data "$MBTILES" 2>/dev/null || true

    # Reload mbtileserver (it was started with --enable-reload-signal)
    pkill -SIGHUP mbtileserver 2>/dev/null || true
    sleep 1

    TILE_URL="/tiles/region-${SLUG}/tiles/{z}/{x}/{y}.pbf"
    echo "Tiles built: $MBTILES"
    echo "Tile URL:    $TILE_URL"
    _update_status "running" "Vector tiles built." 68
fi

# ── Fetch USGS topo PDFs ─────────────────────────────────────────────────────

if [ "$FETCH_TOPO" = "1" ]; then
    [ "$COUNTRY" != "US" ] && echo "Warning: USGS topo only available for US. Skipping." && FETCH_TOPO=0
    [ -z "$BBOX" ]         && echo "Warning: --bbox required for topo fetch. Skipping." && FETCH_TOPO=0
fi

if [ "$FETCH_TOPO" = "1" ]; then
    _update_status "running" "Fetching USGS topo quad list..." 70
    TOPO_DIR="$DEST/topo"
    TNM_URL="https://tnmaccess.nationalmap.gov/api/v1/products?bbox=${BBOX_W},${BBOX_S},${BBOX_E},${BBOX_N}&datasets=National%20Geospatial%20Program%20US%20Topo%207.5%20Minute&outputFormat=JSON&max=60"
    echo "TNM query: $TNM_URL"
    TNM_JSON="$TMPDIR_BUILD/tnm.json"
    if curl -fsSL --retry 3 --max-time 30 -o "$TNM_JSON" "$TNM_URL"; then
        COUNT=$(python3 -c "import json; d=json.load(open('$TNM_JSON')); print(len(d.get('items',[])))" 2>/dev/null || echo 0)
        echo "Found $COUNT topo quads"
        _update_status "running" "Downloading $COUNT USGS topo PDFs..." 72

        python3 - "$TNM_JSON" "$TOPO_DIR" << 'PYEOF'
import json, sys, os, urllib.request, time
tnm_file, topo_dir = sys.argv[1], sys.argv[2]
data = json.load(open(tnm_file))
items = data.get('items', [])
downloaded = skipped = failed = 0
for item in items:
    url = item.get('downloadURL', '') or ''
    if not url or not url.lower().endswith('.pdf'):
        # Try downloadLinksList
        links = item.get('downloadLinksList', [])
        for l in (links if isinstance(links, list) else []):
            if isinstance(l, str) and l.lower().endswith('.pdf'):
                url = l; break
    if not url:
        continue
    title = item.get('title', 'topo').lower()
    fname = ''.join(c if c.isalnum() or c in '-_ ' else '' for c in title)
    fname = fname.strip().replace(' ', '_') + '.pdf'
    dest = os.path.join(topo_dir, fname)
    if os.path.exists(dest):
        print(f'  skip: {fname}')
        skipped += 1
        continue
    try:
        print(f'  downloading: {fname}')
        urllib.request.urlretrieve(url, dest)
        downloaded += 1
        time.sleep(0.2)
    except Exception as e:
        print(f'  warning: {fname}: {e}')
        failed += 1
print(f'Topo: {downloaded} downloaded, {skipped} skipped, {failed} failed')
PYEOF
    else
        echo "Warning: could not reach TNM API. Topo PDFs not downloaded."
    fi
    _update_status "running" "Topo PDFs done." 82
fi

# ── Build region.json ─────────────────────────────────────────────────────────

_update_status "running" "Writing region.json..." 90

python3 - << PYEOF
import json, os

slug         = "$SLUG"
label        = "$LABEL"
country      = "$COUNTRY"
state        = "$STATE"
counties_raw = "$COUNTIES"
climate_zone = "$CLIMATE_ZONE"
center_lat   = "$CENTER_LAT"
center_lng   = "$CENTER_LNG"
zoom         = int("$ZOOM") if "$ZOOM" else 11
tile_url     = "$TILE_URL"
bbox_s       = "$BBOX_S"
bbox_w       = "$BBOX_W"
bbox_n       = "$BBOX_N"
bbox_e       = "$BBOX_E"
nwr_all      = $NWR_ALL
nwr_freqs    = "$NWR_FREQS"

counties = [c.strip() for c in counties_raw.split(',') if c.strip()] if counties_raw else []

# NWR channels
if nwr_all:
    nwr_channels = ["162.400","162.425","162.450","162.475","162.500","162.525","162.550"]
elif nwr_freqs:
    nwr_channels = [f.strip() for f in nwr_freqs.split(',') if f.strip()]
else:
    nwr_channels = []

# Planting calendar placeholder
zone_label = f"Zone {climate_zone}" if climate_zone else "No climate zone set"

region = {
    "slug": slug,
    "label": label,
    "country": country,
    "state": state,
    "counties": counties,
    "climate_zone": climate_zone,
    "center": {
        "lat": float(center_lat) if center_lat else 39.5,
        "lng": float(center_lng) if center_lng else -98.35
    },
    "zoom": zoom,
    "min_zoom": 4,
    "max_zoom": 19,
    "radio": {
        "counties": [{"slug": c.lower().replace(' ','-'), "label": c} for c in counties]
    },
    "seeds": {
        "zone_label": zone_label,
        "last_frost": "unknown",
        "first_frost": "unknown"
    },
    "weather": {
        "nwr_channels": nwr_channels
    }
}

if bbox_s and bbox_w and bbox_n and bbox_e:
    region["bbox"] = {
        "south": float(bbox_s), "west": float(bbox_w),
        "north": float(bbox_n), "east": float(bbox_e)
    }

if tile_url:
    region["tiles"] = {
        "vector": tile_url,
        "vector_minzoom": 4,
        "vector_maxzoom": 14
    }

dest = "$DEST/region.json"
with open(dest, 'w') as f:
    json.dump(region, f, indent=2)
print(f"Wrote {dest}")
PYEOF

chown -R www-data:www-data "$DEST" 2>/dev/null || true
chmod -R u=rwX,go=rX "$DEST" 2>/dev/null || true

echo ""
echo "=== Build complete: $SLUG  $(date) ==="
echo "Region pack: $DEST"
echo "Activate from Admin -> Region tab."
_update_status "done" "Region pack '$SLUG' built successfully. Go to Admin -> Region to activate it." 100
