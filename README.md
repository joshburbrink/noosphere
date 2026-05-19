# Noosphere

A bootable USB system that turns any x86 laptop into a self-contained offline information hub for disaster response, search and rescue, community events, or off-grid scenarios. Users connect to a portable WiFi router, get captive-portaled to the hub, and can coordinate, check in, communicate, and access reference information  -  no internet required.

---

## Hardware

| Component | Role |
|---|---|
| Any x86 laptop | Server  -  boots from USB, runs all services |
| GL.iNet GL-SFT1200 (Opal) | WiFi access point  -  connects users wirelessly |
| 64GB+ USB drive | Storage  -  OS + services + content |

Tested on HP 3105m. Any x86_64 laptop with 2GB+ RAM will work.

---

## Features

- **Community Registry**  -  check in, report missing persons, list skills and supplies available/needed; configurable for shelter management, event check-in, or SAR
- **Local Chat**  -  real-time messaging; links to registry profile for status badges
- **Forum / Bulletin Board**  -  threaded posts with configurable categories (missing persons, lost & found, general, etc.)
- **Shared Files**  -  upload and download documents, maps, photos
- **Offline Map**  -  vector tile map of the local area with 4 color themes; tap to drop markers (search areas, hazards, camps, medical, resources, blocked routes)
- **Offline Library**  -  Wikipedia, WikiMed, iFixit repair guides, and other ZIM content via Kiwix
- **Topo PDFs**  -  USGS topographic maps for local counties
- **Calendar**  -  shared event calendar
- **Admin Panel**  -  6-tab control panel (Dashboard, Network, Community, Content, System, Settings) with collapsible panels, deployment presets, system monitoring, ban management, DB backup
- **Registration controls**  -  optionally require registration to post/chat/upload; optionally disable public self-registration (admin-managed entries only)
- **Captive Portal**  -  anyone connecting to the WiFi is automatically redirected to the hub
- **No accounts required**  -  no signup, no passwords for basic access; optional PIN for registry profile linking
- **No internet required**  -  fully self-contained

---

## Software Stack

| Component | Purpose |
|---|---|
| Debian 13 (Trixie) | Base OS |
| Nginx | Web server + captive portal |
| PHP 8.4 + PHP-FPM | App logic |
| SQLite | All data storage (no database server needed) |
| mbtileserver | Serves vector map tiles (MBTiles format) |
| kiwix-serve | Serves offline ZIM library |
| dnsmasq | DNS redirect for captive portal |
| iptables | NAT redirect for captive portal |

---

## Architecture

```
[WiFi Device]
     |
     | connects to GL-SFT1200 WiFi
     | DHCP -> 192.168.8.x, DNS -> 192.168.8.2
     |
[GL-SFT1200 Router]  192.168.8.1
     |
     | ethernet (LAN port -> laptop eno1)
     |
[Laptop / USB boot]  192.168.8.2
     |
     +-- dnsmasq       all domains -> 192.168.8.2
     +-- iptables NAT  port 80 from router clients -> localhost:80
     +-- Nginx (80)
     |     /              homepage
     |     /registry/     community check-in
     |     /chat/         real-time chat
     |     /forum/        bulletin board
     |     /files/        shared file uploads
     |     /maps/         offline vector map + markers
     |     /calendar/     shared calendar
     |     /library/      Kiwix offline library (proxy -> :8080)
     |     /tiles/        map tile server (proxy -> :8889)
     |     /admin/        admin panel
     +-- mbtileserver  port 8889 (vector tiles)
     +-- kiwix-serve   port 8080 (ZIM library)
```

---

## Deployment Presets

The admin panel includes one-click presets that configure all settings for common scenarios:

| Preset | Best for |
|---|---|
| **Emergency** | General disaster response  -  all features, skills + missing persons |
| **Search & Rescue** | SAR operations  -  map + missing persons focused |
| **Shelter** | Shelter management  -  bunk tracking, dietary needs, next of kin |
| **Event** | Festival or community event  -  simplified check-in |
| **Resource Hub** | Supply coordination  -  skills + supplies fields |
| **Kiosk** | Read-only display  -  library, forum, and map only |

Individual settings can be customized freely: registry label, status options (free text), forum categories (add/remove), feature toggles, instance name and alert banner.

---

## Data

All application data is stored in SQLite databases at `/var/lib/noosphere/`:

| File | Contents |
|---|---|
| `settings.db` | All configuration |
| `registry.db` | Community registry entries |
| `chat.db` | Chat messages |
| `forum.db` | Forum posts and threads |
| `calendar.db` | Calendar events |
| `map_markers.db` | Map markers |
| `files/` | Uploaded files |

---

## Offline Map Content

Vector tiles covering Bartholomew and Brown County, Indiana (OSM data, zoom 4–14, overzoom to 19). USGS topo PDFs for local quads stored at `/var/www/noosphere/maps/topo/`.

To use different tile coverage, replace `/var/lib/noosphere/tiles/*.mbtiles` and update the mbtileserver config.

---

## Offline Library (Kiwix)

ZIM files are stored in `/var/lib/kiwix/`. Drop a new ZIM file there and `kiwix-watch.service` registers it automatically.

Recommended content: `wikipedia_en_medicine`, `wikipedia_en_simple_all`, `ifixit_en_all`, `wiktionary_en_all`, `lrnselfreliance_en_all`. See [docs/kiwix-content.md](docs/kiwix-content.md) for the full list.

---

## Build Phases

- [x] **Phase 1**  -  Partition and format USB drive
- [x] **Phase 2**  -  Install Debian 13 (Trixie)
- [x] **Phase 3**  -  Install Nginx, PHP 8.4, SQLite; deploy all app code
- [x] **Phase 4**  -  Install Kiwix, mbtileserver; load map tiles and ZIM content
- [x] **Phase 5**  -  Captive portal: dnsmasq + iptables + Nginx detection endpoints
- [ ] **Phase 6**  -  Configure GL-SFT1200 router (ethernet -> `eno1`, run `setup-router.sh`)
- [ ] **Phase 7**  -  End-to-end test: connect phone, verify captive portal, test all features

---

## Router Setup (Phase 6)

Plug an ethernet cable from the laptop's `eno1` port into any LAN port on the GL-SFT1200 (leave the WAN port unplugged), then:

```bash
/usr/local/bin/setup-router.sh
```

**Manual alternative:** Connect to GL-SFT1200 web admin at `192.168.8.1` -> Network -> LAN -> DHCP -> Custom DNS: `192.168.8.2`

---

## Admin Access

- Click the **Admin** button at the bottom of the homepage
- From a keyboard: type `aaa` quickly on the homepage
- From a phone or tablet: navigate to `/admin/` directly
- Operator documentation (setup, settings reference, troubleshooting) at `/admin/wiki.php`

---

## Known Issues

See [GitHub Issues](https://github.com/joshburbrink/noosphere/issues) for the full tracker.

| # | Summary |
|---|---------|
| [#1](https://github.com/joshburbrink/noosphere/issues/1) | Map pins cannot be placed in desktop/mouse mode  -  `index.html` is served instead of the full-featured `index.php` |
| [#2](https://github.com/joshburbrink/noosphere/issues/2) | Map street names not shown  -  VectorGrid label layer not yet implemented |

---

## Use Case

Designed for scenarios where infrastructure has failed  -  natural disasters, grid outages, communications blackouts. Anyone with a WiFi-capable device can connect and immediately access community coordination tools and reference information.

No accounts. No internet. No cloud.
