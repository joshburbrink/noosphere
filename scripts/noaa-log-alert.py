#!/usr/bin/env python3
"""Read EAS: lines from stdin (emitted by multimon-ng), parse the SAME
header, and insert one row per alert into /var/lib/noosphere/weather/alerts.db.

Pipeline: rtl_fm -> multimon-ng -a EAS -> grep ^EAS: -> this script.

SAME header format (NWS / FCC Part 11):
    ZCZC-ORG-EEE-PSSCCC[-PSSCCC...]+TTTT-JJJHHMM-LLLLLLLL-

ORG    = originator    (e.g. WXR, EAS, CIV)
EEE    = event code    (e.g. TOR, SVR, FFW)
PSSCCC = county FIPS   (P=part-of-county digit, SSCCC=state+county)
TTTT   = duration      HHMM
JJJHHMM= issue time    julian day + UTC HHMM
LLLLLLLL = station ID

LOCAL_FIPS_PREFIX env var (CSV) flags matching alerts with matched_local=1.
Default covers Bartholomew (018005) and Brown (018013) counties, Indiana.
"""
import sys, sqlite3, re, time, os

DB = "/var/lib/noosphere/weather/alerts.db"
LOCAL_FIPS_PREFIX = os.environ.get("LOCAL_FIPS_PREFIX", "018005,018013")

SAME_RE = re.compile(
    r"ZCZC-([A-Z]{3})-([A-Z]{3})-((?:\d{6}-)+)\+(\d{4})-(\d{7})-([A-Z0-9/ ]{8})-?"
)


def parse(raw):
    m = SAME_RE.search(raw)
    if not m:
        return None
    org, evt, fips_block, dur, issued, station = m.groups()
    fips = [f for f in fips_block.split('-') if f]
    return dict(
        originator=org, event_code=evt, fips=','.join(fips),
        duration=dur, issued=issued, station=station.strip(),
    )


def matched_local(fips_csv):
    locals_ = [p.strip() for p in LOCAL_FIPS_PREFIX.split(',') if p.strip()]
    for f in (fips_csv or '').split(','):
        if not f:
            continue
        sscc = f[1:]  # strip the part-of-county digit
        for L in locals_:
            if sscc == L or sscc.endswith(L[-5:]):
                return 1
    return 0


def main():
    con = sqlite3.connect(DB)
    for line in sys.stdin:
        line = line.strip()
        if not line.startswith("EAS:"):
            continue
        raw = line[4:].strip()
        p = parse(raw) or {}
        con.execute(
            "INSERT INTO alerts(ts,raw,originator,event_code,fips,duration,issued,station,matched_local) "
            "VALUES(?,?,?,?,?,?,?,?,?)",
            (
                int(time.time()), raw,
                p.get('originator'), p.get('event_code'),
                p.get('fips'), p.get('duration'),
                p.get('issued'), p.get('station'),
                matched_local(p.get('fips', '')),
            )
        )
        con.commit()
        print(
            f"[noaa-log-alert] logged: {p.get('event_code','?')} "
            f"fips={p.get('fips','?')}",
            flush=True,
        )


if __name__ == '__main__':
    main()
