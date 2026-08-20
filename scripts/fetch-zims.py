#!/usr/bin/env python3
"""fetch-zims.py - download the offline reference library onto the capture card.

Deliberately NOT on the boot card: root has ~20 GB free and these are ~19 GB.
They also should not go on the media card, which is reserved for movies and
ROMs and is the card that gets pulled and handed to a laptop.

Each file is resumable (curl -C -) and registered with kiwix-manage as it
lands, so an interrupted run costs only the file in flight and the library
stays usable throughout.
"""
import json
import os
import subprocess
import sys
import urllib.parse
import urllib.request
import xml.etree.ElementTree as ET

NS = {"a": "http://www.w3.org/2005/Atom"}
DEST = "/media/capture/zim"
LIBRARY = "/var/lib/kiwix/library.xml"

# (search term, filename fragment that identifies the right entry)
WANT = [
    ("wikipedia mini",      "wikipedia_en_all_mini"),
    ("wikimed",             "wikipedia_en_medicine_maxi"),
    ("ready.gov",           "www.ready.gov_en"),
    ("appropedia",          "appropedia_en_all_maxi"),
    ("energypedia",         "energypedia_en_all_maxi"),
    ("wikem",               "wikem_en_all_maxi"),
    ("military medicine",   "irp.fas.org_en_military-medicine"),
    ("water treatment",     "zimgit-water_en"),
]


def catalog(term, count=60):
    url = ("https://library.kiwix.org/catalog/v2/entries?q="
           + urllib.parse.quote(term) + "&count=%d" % count)
    for _ in range(4):
        try:
            return ET.fromstring(urllib.request.urlopen(url, timeout=45).read())
        except Exception:
            pass
    return None


def resolve(term, frag):
    """Find the real .zim URL. The OPDS href points at a .meta4; stripping that
    suffix gives the direct file, which curl can resume against."""
    root = catalog(term)
    if root is None:
        return None
    best = None
    for e in root.findall("a:entry", NS):
        for l in e.findall("a:link", NS):
            if "application/x-zim" not in (l.get("type") or ""):
                continue
            href = l.get("href") or ""
            name = href.split("/")[-1]
            if frag not in name:
                continue
            size = int(l.get("length") or 0)
            # Prefer the newest/largest match for this fragment.
            if best is None or size > best[1]:
                url = href[:-len(".meta4")] if href.endswith(".meta4") else href
                if url.startswith("/"):
                    url = "https://library.kiwix.org" + url
                best = (url, size, name.replace(".meta4", ""))
    return best


def have_space(need_bytes):
    st = os.statvfs(DEST)
    free = st.f_bavail * st.f_frsize
    return free, free > need_bytes + 4 * 1024 ** 3   # keep 4 GB headroom


def main():
    os.makedirs(DEST, exist_ok=True)
    plan = []
    print("resolving download URLs...", flush=True)
    for term, frag in WANT:
        r = resolve(term, frag)
        if not r:
            print(f"  !! could not resolve {frag}", flush=True)
            continue
        url, size, name = r
        plan.append((url, size, name))
        print(f"  {size/1e9:6.2f} GB  {name}", flush=True)

    total = sum(s for _, s, _ in plan)
    free, ok = have_space(total)
    print(f"\ntotal {total/1e9:.1f} GB; {free/1e9:.1f} GB free on the card", flush=True)
    if not ok:
        print("REFUSING: not enough headroom", file=sys.stderr)
        return 1

    for url, size, name in plan:
        dest = os.path.join(DEST, name)
        if os.path.exists(dest) and os.path.getsize(dest) == size:
            print(f"[have] {name}", flush=True)
        else:
            print(f"[get ] {name} ({size/1e9:.2f} GB)", flush=True)
            rc = subprocess.call([
                "curl", "-fL", "-C", "-", "--retry", "5", "--retry-delay", "10",
                # Keep well clear of the satellite fetcher's bandwidth.
                "--limit-rate", "8M",
                "-o", dest, url])
            if rc != 0:
                print(f"  download failed rc={rc}; leaving partial for resume",
                      file=sys.stderr, flush=True)
                continue
            got = os.path.getsize(dest)
            if got != size:
                print(f"  SIZE MISMATCH {got} != {size}; not registering",
                      file=sys.stderr, flush=True)
                continue

        # Register (idempotent-ish: kiwix-manage skips duplicates by id)
        subprocess.call(["sudo", "-n", "-u", "www-data",
                         "kiwix-manage", LIBRARY, "add", dest])
        print(f"  registered {name}", flush=True)

    print("\ndone", flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
