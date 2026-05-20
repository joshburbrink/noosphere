# Noosphere

**A self-contained, offline information and coordination hub for when the internet isn't there.**

Noosphere turns a single laptop or Raspberry Pi into a complete local network in a box. It broadcasts its own WiFi, captive-portals every device that connects, and serves a full suite of coordination, communication, mapping, and reference tools - with **no internet, no cell service, no cloud, and no accounts required**. Power it on, connect your phone, and you have a working community network.

---

## The vision

When infrastructure fails - a hurricane, a wildfire, a grid blackout, a comms outage - the tools people rely on to find each other, share information, and coordinate help all disappear at once. Noosphere is built for exactly that moment: a rugged, low-power appliance that any community group, shelter, search-and-rescue team, or neighborhood can deploy in minutes to restore the basics of coordinated communication.

It's also useful far from disasters: off-grid events, festivals, remote field camps, basecamps, and anywhere a shared local network beats a flaky internet connection.

The guiding principles:

- **Works with zero infrastructure** - its own WiFi, its own DNS, its own everything.
- **No barriers to entry** - no signup, no passwords, no app to install. Connect and use it.
- **Survives the conditions it's built for** - low power draw, boots from removable media, reboot-proof, runs on hardware you already have.
- **Owned by the operator** - all data is local; nothing phones home.

---

## How it works

1. The device broadcasts a WiFi network (its onboard radio, or an attached travel router).
2. Anyone who connects is automatically **captive-portaled** to the homepage - no need to know an address.
3. They get a tile-based menu of every enabled tool, tuned to the deployment.
4. Everything runs locally on the device. Disconnect from the world entirely and it keeps working.

---

## What it can do

Noosphere is modular - each capability is a self-contained module that operators turn on or off per deployment.

### Communication & coordination
- **Community Registry** - check in, mark yourself safe, report missing persons, list skills and supplies you have or need. Configurable for shelter intake, event check-in, or SAR personnel tracking.
- **Chat** - real-time group messaging.
- **Community Board (Forum)** - threaded announcements, coordination, and discussion with configurable categories.
- **Files** - share documents, notices, maps, and photos.
- **Tasks** - a volunteer job board; anyone can claim a task and help out.
- **Calendar** - community events and schedules.
- **Canvas** - freehand drawing for diagrams and annotated map sketches.

### Mapping & situational awareness
- **Offline Map** - vector map of the deployment region with multiple themes; tap to drop shared markers (hazards, camps, medical, resources, blocked routes, search areas).
- **Incident Reports** - unified reporting for damage, medical, hazard, missing-person, and resource incidents, plotted on the map with severity, status, and assignment.
- **Topo Maps** - USGS 1:24,000 topographic quads for the local area.
- **Command Dashboard** - a dense operator view of all active incidents, runners, supplies, and weather at a glance.

### Operations (incident command)
- **Runners** - track who's out, where they went, and whether they've returned.
- **Triage Log** - mass-casualty patient tracking with START triage priority and printable patient tags.
- **Role-based access** - operators, shelter staff, SAR, medical, comms, and volunteers get scoped permissions.

### Reference & knowledge
- **Offline Library (Kiwix)** - full Wikipedia, WikiMed medical reference, iFixit repair guides, survival/self-reliance content, and more, served from local ZIM files.
- **Local Knowledge Wiki** - community-editable reference for roads, water sources, local skills, and know-how.
- **Resources** - supply inventory tracking, a seed library with planting calendar, and tool lending/check-out.

### Radio & weather (with an optional SDR dongle)
- **NOAA Weather Radio** - receive and log NWR broadcasts and SAME alerts; auto-post alerts to chat; live audio + transcription.
- **Spectrum Scanner** - SDR waterfall display.
- **Radio Reference** - a searchable local frequency database with CHIRP export.
- **Radio Programmer** - program 500+ models of handheld radios directly over USB from the local frequency data.
- **Radio Log** - log contacts, traffic, and net check-ins.

### Morale & utilities
- **Games** - browser-based games (Snake, Tetris, 2048, Minesweeper, plus LAN multiplayer Codenames, Pictionary, Battleship).
- **Admin Panel** - full control center for settings, modules, regions, content, and downloads.

---

## Deployment presets

The admin panel has one-click presets that reconfigure every module and field for a scenario:

| Preset | Best for |
|---|---|
| **Emergency** | General disaster response - all features on |
| **Search & Rescue** | SAR ops - map, incidents, runners, missing persons |
| **Shelter** | Shelter management - intake, bunk tracking, dietary needs, next of kin |
| **Event** | Festivals / community events - simplified public check-in |
| **Resource Hub** | Supply coordination - skills + supplies + inventory |
| **Kiosk** | Read-only public info - library, board, and map only |

Everything is customizable beyond the presets: instance name, alert banner, registry labels and status options, forum categories, and every module toggle.

---

## Roles & access

By default Noosphere needs **no accounts** - anyone can connect and use the public tools. Operators can optionally:

- Require registration to post, chat, or upload.
- Disable public self-registration (admin-managed entries only).
- Assign **roles** (operator, shelter staff, SAR, medical, comms, volunteer) that grant scoped capabilities - editing incidents, dispatching runners, controlling the radio, moderating the board, managing inventory, and so on.

---

## Hardware

| Component | Role |
|---|---|
| x86 laptop **or** Raspberry Pi 5 | Server - runs all services |
| Onboard WiFi **or** a travel router (e.g. GL.iNet GL-SFT1200) | Broadcasts the network |
| 64GB+ USB / SD / NVMe | OS + services + offline content (128GB+ recommended) |

Tested on an HP 3105m laptop and Raspberry Pi 5. Any x86_64 machine with 2GB+ RAM works.

**Optional hardware:** an RTL-SDR dongle (weather radio, scanner), a VHF antenna for NOAA Weather Radio, and a USB programming cable for handheld radios.

### Raspberry Pi 5 (arm64)

The full stack runs on arm64 - provisioning auto-detects the architecture and pulls the right Kiwix (`aarch64`) and mbtileserver (`arm64`) binaries, and the access point uses the Pi's onboard WiFi (no external adapter needed).

- **Base image must be Trixie** (Raspberry Pi OS Trixie 64-bit or Debian 13 arm64) - the stack uses PHP 8.4; Bookworm's 8.2 will not work.
- An x86 Noosphere drive **cannot** boot a Pi (different CPU architecture) - the OS is rebuilt and only the data migrates. Use the migration tool below.

---

## Software stack

| Component | Purpose |
|---|---|
| Debian 13 (Trixie) / Raspberry Pi OS Trixie | Base OS |
| Nginx | Web server + captive portal |
| PHP 8.4 + PHP-FPM | Application logic |
| SQLite | All data storage (no database server) |
| mbtileserver | Vector map tiles (MBTiles) |
| kiwix-serve | Offline ZIM library |
| hostapd | WiFi access point (onboard radio) |
| dnsmasq | Captive-portal DNS + DHCP |
| iptables | Captive-portal NAT redirect |

---

## Architecture

```
[ Phones / tablets / laptops ]
        |  connect to WiFi "NET"
        v
[ Access point ]
   onboard WiFi (hostapd, 192.168.4.1)   ── or ──   external travel router (192.168.8.x)
        |
        v
[ Noosphere server ]
   dnsmasq      all domains -> the hub (captive portal)
   iptables     port 80/443 -> local hub
   Nginx (80)
     /              homepage (tile menu)
     /registry/     community check-in / missing persons
     /chat/  /forum/  /files/  /tasks/  /calendar/  /canvas/
     /maps/         offline vector map + shared markers
     /incidents/    unified incident reporting
     /command/      operator dashboard
     /runners/  /triage/
     /wiki/         local knowledge base
     /resources/  /supplies/  /seeds/  /tools/
     /weather/  /radio/        SDR weather radio, scanner, reference, programmer
     /library/      Kiwix offline library      (proxy -> :8080)
     /tiles/        vector tile server          (proxy -> :8889)
     /topo/         USGS topographic PDFs
     /games/        browser + LAN games
     /admin/        admin control panel
   mbtileserver   :8889
   kiwix-serve    :8080
```

---

## Installation & deployment

### x86 laptop
Boot Debian 13, then run the provisioner - it installs the entire stack, services, and default content:

```bash
sudo bash scripts/noosphere-provision.sh
```

`scripts/install-to-disk.sh` builds a bootable USB/disk image with a first-boot provisioning service. `scripts/setup-pxe.sh` can network-boot and install new machines over PXE.

### Raspberry Pi 5 - one-shot migration / build

`scripts/migrate-to-pi.sh` builds a ready-to-boot Pi drive from a laptop. It flashes Raspberry Pi OS, configures headless SSH, registers a first-boot provisioning service, pre-configures the access point, and (optionally) stages your existing data. **Plug the drive into the Pi, plug in ethernet + power, and walk away** - it provisions itself, imports data, and starts broadcasting WiFi automatically.

```bash
# migrate data from a running server over the network:
sudo ./scripts/migrate-to-pi.sh /dev/sdX --from-server 192.168.2.166
# or copy from the old drive mounted locally:
sudo ./scripts/migrate-to-pi.sh /dev/sdX --from-disk /mnt/old-noosphere
# or a clean Pi with no data:
sudo ./scripts/migrate-to-pi.sh /dev/sdX --no-data
```

Ethernet is needed only for the one-time online provisioning; afterward the hub runs fully offline. The AP defaults to an open network named `NET` and is reboot-persistent. (Commit and push repo changes first - first-boot provisioning clones the app from GitHub.)

### Access point
```bash
sudo setup-hostapd.sh configure   # pick the wireless interface, SSID, channel
sudo setup-hostapd.sh enable       # broadcast + captive portal (survives reboots)
```

---

## Regions & localization

Map, topo, and frequency content is organized into **region packs** so Noosphere can be deployed anywhere - not just one area. The built-in example region is **Bartholomew & Brown County, Indiana**. Operators can build a new region for their own area from the admin panel (`scripts/build-region-pack.sh`): it downloads OpenStreetMap data, renders vector tiles, fetches USGS topo quads, and assembles a localized pack. Packs can be checksummed and signed for sharing.

---

## Offline content

- **Library (Kiwix):** ZIM files live in `/var/lib/kiwix/`; drop one in and it's auto-registered. The admin panel has a catalog browser + resumable download manager. See [docs/kiwix-content.md](docs/kiwix-content.md).
- **Maps:** vector tiles (MBTiles) served by mbtileserver; swappable per region.
- **Topo:** USGS 1:24,000 PDFs for the region's quads.

---

## Admin & operation

- Open the **Admin** tile on the homepage, type `aaa` on the homepage, or go to `/admin/`.
- The admin panel covers settings, module toggles, presets, network/AP mode, region building, content wipe/download, and system monitoring.
- On the console, `noosphere-help` is a full paged guide for non-technical operators, and the login MOTD shows live service/network/disk status.

All application data is SQLite under `/var/lib/noosphere/` (registry, chat, forum, incidents, wiki, tasks, supplies, etc.), with uploads and photos alongside it. Back up that directory and `/var/lib/kiwix/` to preserve a deployment.

---

## Roadmap - where it's going

Noosphere is actively developed. The larger features on the horizon, and why they matter:

- **LoRa mesh networking (Meshtastic)** [#90] - WiFi only reaches a building or campsite. Bridging Noosphere to a long-range, low-power LoRa mesh would let multiple hubs and remote nodes relay messages and alerts across **miles** of a town or county with no infrastructure - turning a single hub into a wide-area disaster network.
- **Fully offline provisioning** [#92] - today, first-time setup needs internet once (to install packages). Pre-baking all arm64 packages into the build would let a Pi provision itself with **zero internet, ever** - the right end-state for an appliance that may never see a connection.
- **Unified calendar hub + offline time integrity** [#89, #87] - a hub with no internet has no trusted clock. This adds a tamper-aware offline time source plus a calendar that aggregates events, task due dates, planting windows, and holidays - for planning *and* morale during long outages.
- **Spanish (and beyond) localization** [#17] - disaster response has to reach everyone. Multilingual user-facing pages remove a language barrier when it matters most.
- **First-time setup wizard + printable operator guide** [#30, #11] - the operator in a real emergency may not be technical. Guided onboarding and a one-page printable quick-start card make Noosphere deployable by anyone.
- **Storage & content management** [#91, #82] - hot-plug a second drive for more library content, with low-disk alerts, plus satellite imagery layers - so a hub can hold far more reference material in the field.
- **Resilience hardening** [#88, #37, #36] - automatic WiFi-driver rebuilds across kernel upgrades, a full mobile-UI pass, and an admin panel overhaul, so the appliance stays reliable and usable under stress.

See the [issue tracker](https://github.com/joshburbrink/noosphere/issues) for the full backlog.

---

## Philosophy

> No accounts. No internet. No cloud.

Noosphere exists so that the loss of infrastructure doesn't have to mean the loss of coordination. Everything it needs, it carries. Everyone who needs it, can use it.
