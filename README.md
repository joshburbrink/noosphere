# Noosphere

A bootable USB system that turns any x86 laptop into an offline information hub for post-disaster or off-grid scenarios. Users connect to a portable WiFi router, get redirected to a local homepage, and can access a message board, shared files, and a full offline Wikipedia — no internet required.

---

## Hardware

| Component | Role |
|---|---|
| Any x86 laptop | Server — boots from USB, runs all services |
| GL.iNet GL-SFT1200 (Opal) | WiFi access point — connects users wirelessly |
| 64GB USB drive | Storage — OS + services (test; production drive larger) |

---

## Features

- **Captive portal** — anyone connecting to the WiFi is redirected to the Noosphere homepage
- **Nextcloud** — file sharing, document collaboration, chat (Talk), message board
- **Kiwix** — full offline Wikipedia (with images), WikiMed, and other ZIM content
- **No internet required** — fully self-contained, works in blackout/disaster conditions
- **Persistent** — data survives reboots, survives across sessions

---

## Architecture

```
[WiFi Device]
     |
     | (connects to GL-SFT1200 WiFi)
     | DNS query → 192.168.8.2 (Noosphere dnsmasq)
     | all domains resolve to 192.168.8.2
     |
[GL-SFT1200 Router]  192.168.8.1
     |
     | (ethernet, LAN port)
     |
[Laptop booted from USB]  192.168.8.2
     |
     +-- dnsmasq (port 53) — resolves all domains to 192.168.8.2
     |
     +-- iptables NAT — redirects port 80/443 from eno1 → localhost:80
     |
     +-- Nginx (port 80)
     |     |-- / → captive portal homepage
     |     |-- /nextcloud/ → Nextcloud (PHP-FPM)
     |     └-- /kiwix/ → kiwix-serve (:8888)
     |
     +-- Nextcloud 33 (LAMP stack — Apache/MariaDB/PHP)
     |
     └-- kiwix-serve 3.7 (systemd service, port 8888)
```

**Network layout:**
- Router: `192.168.8.1` (GL-SFT1200 LAN)
- Laptop (server): `192.168.8.2` (static, eno1 ethernet)
- WiFi clients: `192.168.8.x` (DHCP from router, DNS → 192.168.8.2)

---

## Captive Portal Flow

1. User connects phone/laptop to GL-SFT1200 WiFi
2. Router DHCP assigns IP, sets DNS server = `192.168.8.2`
3. Any domain user visits resolves to `192.168.8.2` (Noosphere dnsmasq)
4. Nginx serves Noosphere homepage — links to Nextcloud and Kiwix
5. OS-level captive portal detection (iOS/Android/Windows) triggers automatically

Captive portal detection endpoints handled: `/hotspot-detect.html`, `/generate_204`, `/gen_204`, `/ncsi.txt`, `/success.txt`, `/connecttest.txt`

---

## Storage Layout (64GB USB — Test)

| Partition | Size | Format | Purpose |
|---|---|---|---|
| sda1 | ~63GB | ext4 | Debian 13 OS + all services + data |

---

## Software Stack

| Component | Version | Notes |
|---|---|---|
| Debian | 13 (Trixie) | Base OS — minimal, broad hardware support, no snap overhead |
| Nginx | 1.26.3 | Reverse proxy + captive portal |
| Nextcloud | 33.0.3 | Installed via LAMP (MariaDB + PHP 8.4 + PHP-FPM) |
| kiwix-serve | 3.7.0 | ZIM file server (apt package) |
| dnsmasq | 2.91 | DNS redirect — all domains → 192.168.8.2 |
| iptables | — | NAT redirect port 80/443 from eno1 clients |

---

## ZIM Content

See [docs/kiwix-content.md](docs/kiwix-content.md) for the full content list (~257GB).
Download script: [scripts/download-zim.sh](scripts/download-zim.sh)

---

## Build Phases

- [x] **Phase 1** — Partition and format 64GB USB drive
- [x] **Phase 2** — Install Debian 13 (Trixie) to 64GB USB
- [x] **Phase 3** — Install and configure Nextcloud 33 (LAMP stack)
- [x] **Phase 4** — Install Kiwix 3.7, homepage live at 192.168.8.2
- [x] **Phase 5** — Captive portal: dnsmasq DNS redirect + iptables NAT + Nginx detection endpoints
- [ ] **Phase 6** — Configure GL-SFT1200 router (plug in ethernet, run `/usr/local/bin/setup-router.sh`)
- [ ] **Phase 7** — End-to-end testing (connect phone, verify captive portal triggers)

---

## Router Setup (Phase 6)

Once the ethernet cable is connected from the laptop's `eno1` port to the GL-SFT1200 LAN port:

```bash
# On the Noosphere server (root@192.168.8.2):
/usr/local/bin/setup-router.sh
```

This SSHes into the router and sets DHCP option 6 to point WiFi clients' DNS at `192.168.8.2`.

**Manual alternative (GL.iNet web admin):**
1. Connect to GL-SFT1200 WiFi → open `192.168.8.1` in browser
2. Network → LAN → Advanced → DHCP → Custom DNS: `192.168.8.2`
3. Save & Apply

---

## Use Case

Designed for scenarios where infrastructure has failed — natural disasters, grid outages, communications blackouts. Anyone with a WiFi-capable device (phone, laptop, tablet) can connect to the router and immediately access:

- Community message board for coordination
- Shared file uploads (maps, documents, photos)
- Full Wikipedia for reference
- Medical information (WikiMed)

No accounts required for basic access. No internet. No cloud dependency.
