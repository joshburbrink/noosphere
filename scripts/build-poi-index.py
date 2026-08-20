#!/usr/bin/env python3
"""
build-poi-index.py  -  build the offline point-of-interest index for the car
head unit from the statewide OSM extract already on disk.

    /media/capture/mapbuild/indiana-latest.osm.pbf   (input, ~188 MB)
      -> osmium tags-filter  -> poi-filtered.osm.pbf     (intermediate)
      -> osmium export       -> poi-filtered.geojsonseq  (intermediate)
      -> this script         -> /var/lib/noosphere/poi/poi.db   (SQLite)

Nothing here touches the network. The intermediates are large and live on
/media/capture; only the small SQLite index lands on root.

Two ideas drive the schema:

  * Raw OSM categories are useless to a driver - the top tags in this state
    are parking, pitch and swimming_pool. So every row carries a *normalised*
    category of our own (fuel, food, coffee, ...) alongside the raw tag it
    came from, and the head unit browses by the normalised one.

  * Attributes are sparse. Around here only ~39% of POIs are named, 11% have
    a street address and 7% a phone. Every optional column is therefore
    genuinely optional, and the UI is expected to render a row that has only
    a name and a position.

lat and lon are indexed so a bounding-box prefilter is cheap; the caller
refines with a real haversine distance afterwards (the same shape as
near() in geofence_daemon.py).

Re-runnable: it builds into a temporary file and renames over the live DB, so
a failed run leaves the previous index in place.

Usage:
    build-poi-index.py [--skip-extract] [--pbf PATH] [--out PATH]

  --skip-extract   reuse the existing intermediates (fast re-classify)
"""

import argparse
import json
import os
import shutil
import sqlite3
import subprocess
import sys
import time

DEFAULT_PBF = "/media/capture/mapbuild/indiana-latest.osm.pbf"
DEFAULT_OUT = "/var/lib/noosphere/poi/poi.db"
WORK_DIR = "/media/capture/mapbuild"

FILTERED_PBF = os.path.join(WORK_DIR, "poi-filtered.osm.pbf")
GEOJSONSEQ = os.path.join(WORK_DIR, "poi-filtered.geojsonseq")

# What osmium pulls out of the statewide file. Deliberately wide - the
# curation happens below, in Python, where it is easy to change without
# re-reading 188 MB.
TAG_FILTERS = [
    "nwr/amenity",
    "nwr/shop",
    "nwr/tourism",
    "nwr/healthcare",
    "nwr/office",
    "nwr/leisure",
    "nwr/highway=rest_area,services",
    "nwr/craft",
]

# ---------------------------------------------------------------------------
# Category curation
#
# PRIMARY are the browsable tiles on the head unit - the things a driver
# actually stops for. Order matters: the first match wins, which is why fuel
# is tested before grocery (most fuel stations are also shop=convenience) and
# pharmacy before shopping.
# ---------------------------------------------------------------------------

PRIMARY = [
    ("fuel", {
        "amenity": {"fuel"},
    }),
    ("ev", {
        "amenity": {"charging_station"},
    }),
    ("hospital", {
        "amenity": {"hospital", "clinic", "doctors"},
        "healthcare": {"hospital", "clinic", "urgent_care", "doctor", "centre"},
    }),
    ("pharmacy", {
        "amenity": {"pharmacy"},
        "healthcare": {"pharmacy"},
        "shop": {"chemist"},
    }),
    ("rest_area", {
        "highway": {"rest_area", "services"},
        "amenity": {"rest_area"},
    }),
    ("camping", {
        "tourism": {"camp_site", "caravan_site"},
    }),
    ("lodging", {
        "tourism": {"hotel", "motel", "hostel", "guest_house", "apartment",
                    "chalet"},
    }),
    ("coffee", {
        "amenity": {"cafe", "internet_cafe"},
        "shop": {"coffee"},
    }),
    ("food", {
        "amenity": {"restaurant", "fast_food", "food_court", "ice_cream",
                    "biergarten", "pub", "bar"},
        "shop": {"bakery", "pastry"},
    }),
    ("grocery", {
        "shop": {"supermarket", "convenience", "greengrocer", "butcher",
                 "deli", "general", "farm", "grocery", "food"},
    }),
    ("atm", {
        "amenity": {"atm", "bank", "bureau_de_change"},
    }),
    ("auto", {
        "shop": {"car_repair", "tyres", "car_parts", "car", "motorcycle_repair",
                 "truck_repair", "car_wash"},
        "amenity": {"car_wash", "vehicle_inspection", "driving_school"},
    }),
]

# Display order and labels for the primary tiles. The head unit reads these
# from the DB (meta table) so the two never drift apart.
PRIMARY_LABELS = [
    ("fuel", "Fuel"),
    ("food", "Food"),
    ("coffee", "Coffee"),
    ("grocery", "Grocery"),
    ("pharmacy", "Pharmacy"),
    ("hospital", "Hospital / Urgent"),
    ("atm", "ATM / Bank"),
    ("lodging", "Lodging"),
    ("camping", "Camping"),
    ("rest_area", "Rest Area"),
    ("ev", "EV Charging"),
    ("auto", "Auto Repair"),
]

# SECONDARY are not tiles, but they are worth having in the text index - if
# someone types "hardware" or "post office" they should find it. Unnamed
# secondary features are dropped: an anonymous shop is noise on a 8" screen.
SECONDARY = [
    ("emergency", {
        "amenity": {"police", "fire_station", "ranger_station"},
    }),
    ("attraction", {
        "tourism": {"attraction", "museum", "zoo", "theme_park", "gallery",
                    "aquarium", "viewpoint", "artwork", "information"},
    }),
    ("outdoors", {
        "leisure": {"park", "nature_reserve", "marina", "slipway", "golf_course",
                    "sports_centre", "fitness_centre", "stadium", "water_park",
                    "beach_resort", "bowling_alley", "ice_rink"},
        "tourism": {"picnic_site", "wilderness_hut", "alpine_hut"},
    }),
    ("services", {
        "amenity": {"post_office", "library", "townhall", "courthouse",
                    "community_centre", "veterinary", "dentist", "toilets",
                    "drinking_water", "place_of_worship", "school", "college",
                    "university", "kindergarten", "theatre", "cinema",
                    "public_bath", "bus_station", "ferry_terminal",
                    "car_rental", "post_depot", "childcare", "social_facility"},
        "office": {"*"},
        "craft": {"*"},
    }),
    ("shopping", {
        "shop": {"*"},
    }),
]

# Never index these, whatever else they carry. Dominated by the noise the
# brief warned about.
JUNK_AMENITY = {
    "parking", "parking_space", "parking_entrance", "bicycle_parking",
    "motorcycle_parking", "bench", "waste_basket", "waste_disposal",
    "recycling", "shelter", "hunting_stand", "grave_yard", "bbq",
    "vending_machine", "clock", "fountain", "telephone", "smoking_area",
    "watering_place", "loading_dock", "bicycle_repair_station",
}
JUNK_LEISURE = {
    "pitch", "swimming_pool", "fitness_station", "picnic_table", "track",
    "playground", "garden", "common", "dog_park", "bleachers", "outdoor_seating",
    "firepit", "horse_riding", "sauna", "hackerspace",
}

ADDR_KEYS = {
    "addr:housenumber": "addr_housenumber",
    "addr:street": "addr_street",
    "addr:city": "addr_city",
    "addr:state": "addr_state",
    "addr:postcode": "addr_postcode",
}

SCHEMA = """
CREATE TABLE poi (
    id               INTEGER PRIMARY KEY,
    osm_type         TEXT NOT NULL,          -- node / way / relation
    osm_id           INTEGER NOT NULL,
    name             TEXT,
    brand            TEXT,
    operator         TEXT,
    category         TEXT NOT NULL,          -- our normalised category
    raw              TEXT NOT NULL,          -- the OSM tag it came from
    lat              REAL NOT NULL,
    lon              REAL NOT NULL,
    addr_housenumber TEXT,
    addr_street      TEXT,
    addr_city        TEXT,
    addr_state       TEXT,
    addr_postcode    TEXT,
    phone            TEXT,
    opening_hours    TEXT,
    website          TEXT,
    cuisine          TEXT
);
CREATE INDEX idx_poi_lat ON poi(lat);
CREATE INDEX idx_poi_lon ON poi(lon);
CREATE INDEX idx_poi_cat_lat ON poi(category, lat);
CREATE TABLE meta (key TEXT PRIMARY KEY, value TEXT NOT NULL);
CREATE TABLE categories (
    slug     TEXT PRIMARY KEY,
    label    TEXT NOT NULL,
    sort     INTEGER NOT NULL,
    primary_ INTEGER NOT NULL,
    n        INTEGER NOT NULL DEFAULT 0
);
"""

FTS_SCHEMA = """
CREATE VIRTUAL TABLE poi_fts USING fts5(
    name, brand, category,
    content='poi', content_rowid='id',
    tokenize="unicode61 remove_diacritics 2",
    prefix='2 3 4 5'
);
"""


def log(msg):
    print(f"[{time.strftime('%H:%M:%S')}] {msg}", flush=True)


def run(cmd):
    log("$ " + " ".join(cmd))
    t0 = time.time()
    subprocess.run(cmd, check=True)
    log(f"  done in {time.time() - t0:.1f}s")


def extract(pbf):
    if not os.path.exists(pbf):
        sys.exit(f"input extract missing: {pbf}")
    os.makedirs(WORK_DIR, exist_ok=True)
    run(["osmium", "tags-filter", "--overwrite", "--no-progress",
         "-o", FILTERED_PBF, pbf] + TAG_FILTERS)
    run(["osmium", "export", "--overwrite", "--no-progress",
         "-u", "type_id", "-f", "geojsonseq",
         "--geometry-types", "point,polygon",
         "-o", GEOJSONSEQ, FILTERED_PBF])


def match(tags, table):
    """First matching (category, raw-tag) from a rule table, else None."""
    for cat, rules in table:
        for key, values in rules.items():
            v = tags.get(key)
            if v is None:
                continue
            if "*" in values or v in values:
                return cat, f"{key}={v}"
    return None


def classify(tags):
    if tags.get("amenity") in JUNK_AMENITY:
        return None
    if tags.get("leisure") in JUNK_LEISURE and not tags.get("amenity") \
            and not tags.get("shop") and not tags.get("tourism"):
        return None
    m = match(tags, PRIMARY)
    if m:
        return m[0], m[1], True
    m = match(tags, SECONDARY)
    if m:
        return m[0], m[1], False
    return None


def centroid(geom):
    """Point straight through; polygon -> average of its outer ring. Good
    enough at driving scale, and far cheaper than a true centroid."""
    t = geom.get("type")
    c = geom.get("coordinates")
    if t == "Point":
        return c[0], c[1]
    if t == "Polygon":
        ring = c[0]
    elif t == "MultiPolygon":
        ring = c[0][0]
    else:
        return None
    if not ring:
        return None
    # Drop the repeated closing vertex so it does not bias the mean.
    pts = ring[:-1] if len(ring) > 1 and ring[0] == ring[-1] else ring
    n = len(pts)
    if not n:
        return None
    return sum(p[0] for p in pts) / n, sum(p[1] for p in pts) / n


def attr_score(rec):
    """How much a record actually tells the driver - used to pick the better
    of two duplicates (the node inside the building, or the building)."""
    return sum(1 for k in ("name", "brand", "phone", "opening_hours",
                           "addr_street", "website", "cuisine") if rec.get(k))


def first(tags, *keys):
    for k in keys:
        v = tags.get(k)
        if v:
            v = v.strip()
            if v:
                return v[:200]
    return None


def parse(path):
    """Stream the GeoJSON sequence, keeping the best copy of each POI."""
    seen = {}
    total = read = 0
    with open(path, "r", encoding="utf-8", errors="replace") as fh:
        for line in fh:
            line = line.strip().lstrip("\x1e")
            if not line:
                continue
            read += 1
            try:
                feat = json.loads(line)
            except ValueError:
                continue
            tags = feat.get("properties") or {}
            cls = classify(tags)
            if not cls:
                continue
            cat, raw, is_primary = cls
            name = first(tags, "name", "official_name", "short_name")
            brand = first(tags, "brand", "operator")
            if not is_primary and not name and not brand:
                continue          # anonymous secondary rows are pure noise
            pt = centroid(feat.get("geometry") or {})
            if not pt:
                continue
            lon, lat = pt
            # osmium's type_id ids are "n<id>", "w<id>", "r<id>" and - for
            # anything it assembled into an area - "a<area-id>", where the
            # area id is way_id*2 for closed ways and relation_id*2+1 for
            # multipolygons. Undo that so the row points at real OSM objects.
            uid = str(feat.get("id") or "")
            prefix, digits = uid[:1], uid[1:]
            try:
                oid = int(digits)
            except ValueError:
                oid = 0
            if prefix == "a":
                otype = "way" if oid % 2 == 0 else "relation"
                oid //= 2
            else:
                otype = {"w": "way", "r": "relation"}.get(prefix, "node")

            rec = {
                "osm_type": otype, "osm_id": oid,
                "name": name, "brand": tags.get("brand"),
                "operator": tags.get("operator"),
                "category": cat, "raw": raw,
                "lat": round(lat, 7), "lon": round(lon, 7),
                "phone": first(tags, "phone", "contact:phone", "telephone"),
                "opening_hours": first(tags, "opening_hours"),
                "website": first(tags, "website", "contact:website", "url"),
                "cuisine": first(tags, "cuisine"),
            }
            for k, col in ADDR_KEYS.items():
                rec[col] = first(tags, k)

            # Dedupe: same category and same name within ~100 m is one place
            # mapped twice (a POI node sitting inside its own building).
            if name:
                key = (cat, name.lower(), round(lat, 3), round(lon, 3))
            else:
                key = (cat, "", round(lat, 4), round(lon, 4))
            old = seen.get(key)
            if old is None or attr_score(rec) > attr_score(old):
                seen[key] = rec
            total += 1
    log(f"read {read} features, kept {len(seen)} after curation "
        f"({total - len(seen)} duplicates merged)")
    return list(seen.values())


def build(records, out):
    outdir = os.path.dirname(out)
    os.makedirs(outdir, exist_ok=True)
    tmp = out + ".tmp"
    for suffix in ("", "-wal", "-shm"):
        try:
            os.unlink(tmp + suffix)
        except FileNotFoundError:
            pass

    db = sqlite3.connect(tmp)
    db.executescript(SCHEMA)
    cols = ["osm_type", "osm_id", "name", "brand", "operator", "category", "raw",
            "lat", "lon", "addr_housenumber", "addr_street", "addr_city",
            "addr_state", "addr_postcode", "phone", "opening_hours", "website",
            "cuisine"]
    db.executemany(
        f"INSERT INTO poi ({','.join(cols)}) VALUES ({','.join('?' * len(cols))})",
        [tuple(r.get(c) for c in cols) for r in records])

    db.executescript(FTS_SCHEMA)
    db.execute("INSERT INTO poi_fts(poi_fts) VALUES('rebuild')")

    labels = dict(PRIMARY_LABELS)
    order = {slug: i for i, (slug, _) in enumerate(PRIMARY_LABELS)}
    sec_labels = {"emergency": "Police / Fire", "attraction": "Attractions",
                  "outdoors": "Parks / Outdoors", "services": "Services",
                  "shopping": "Shopping"}
    counts = dict(db.execute(
        "SELECT category, COUNT(*) FROM poi GROUP BY category").fetchall())
    rows = []
    for slug, label in PRIMARY_LABELS:
        rows.append((slug, label, order[slug], 1, counts.get(slug, 0)))
    for i, (slug, label) in enumerate(sec_labels.items()):
        rows.append((slug, label, 100 + i, 0, counts.get(slug, 0)))
    db.executemany("INSERT INTO categories (slug,label,sort,primary_,n) "
                   "VALUES (?,?,?,?,?)", rows)

    db.executemany("INSERT INTO meta (key,value) VALUES (?,?)", [
        ("built_at", str(int(time.time()))),
        ("built_at_human", time.strftime("%Y-%m-%d %H:%M:%S")),
        ("source", os.path.basename(DEFAULT_PBF)),
        ("count", str(len(records))),
    ])
    db.commit()
    db.execute("ANALYZE")
    db.commit()
    db.execute("VACUUM")
    db.close()

    os.replace(tmp, out)
    os.chmod(out, 0o644)
    return labels, sec_labels, counts


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument("--pbf", default=DEFAULT_PBF)
    ap.add_argument("--out", default=DEFAULT_OUT)
    ap.add_argument("--skip-extract", action="store_true",
                    help="reuse the existing osmium intermediates")
    args = ap.parse_args()

    t0 = time.time()
    if args.skip_extract and os.path.exists(GEOJSONSEQ):
        log(f"reusing {GEOJSONSEQ}")
    else:
        if not shutil.which("osmium"):
            sys.exit("osmium not installed")
        extract(args.pbf)

    records = parse(GEOJSONSEQ)
    if not records:
        sys.exit("no POIs classified - refusing to replace the live index")
    labels, sec_labels, counts = build(records, args.out)

    size = os.path.getsize(args.out)
    log(f"wrote {args.out}  ({size/1e6:.1f} MB, {len(records)} POIs) "
        f"in {time.time() - t0:.1f}s total")
    print()
    print("  browsable categories")
    for slug, label in PRIMARY_LABELS:
        print(f"    {label:<20} {counts.get(slug, 0):>7}   ({slug})")
    print("  searchable only")
    for slug, label in sec_labels.items():
        print(f"    {label:<20} {counts.get(slug, 0):>7}   ({slug})")
    print()


if __name__ == "__main__":
    main()
