# Noosphere

A bootable USB system that turns any x86 laptop into an offline information hub for post-disaster or off-grid scenarios. Users connect to a portable WiFi router, get redirected to a local homepage, and can access a message board, shared files, and a full offline Wikipedia — no internet required.

---

## Hardware

| Component | Role |
|---|---|
| Any x86 laptop | Server — boots from USB, runs all services |
| GL.iNet GL-SFT1200 (Opal) | WiFi access point — connects users wirelessly |
| 1TB USB drive | Storage — OS, Nextcloud data, Wikipedia ZIM files |

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
     | (connects to WiFi)
     |
[GL-SFT1200 Router]
     |
     | (ethernet)
     |
[Laptop booted from USB]
     |
     +-- Nginx (port 80/443)
     |     |-- Captive portal homepage
     |     |-- Reverse proxy → Nextcloud (:8080)
     |     └-- Reverse proxy → Kiwix (:8888)
     |
     +-- Nextcloud (PHP + MariaDB)
     |     |-- File sharing & uploads
     |     |-- Nextcloud Talk (messaging/chat)
     |     └-- Document collaboration
     |
     +-- Kiwix-serve
           |-- Wikipedia (full, with images, ~100GB)
           └-- WikiMed (~3GB)
```

**Network layout:**
- Router: `192.168.8.1` (default GL.iNet gateway)
- Laptop (server): `192.168.8.2` (static, ethernet)
- WiFi clients: `192.168.8.10–254` (DHCP from router)
- All port 80 traffic from clients → redirected to laptop via iptables

---

## Storage Layout (1TB USB Drive)

| Partition | Size | Format | Purpose |
|---|---|---|---|
| sda1 | 512MB | FAT32 | EFI boot |
| sda2 | 30GB | ext4 | Debian Live OS + persistence |
| sda3 | remainder (~870GB) | ext4 | Nextcloud data + Kiwix ZIM files |

---

## Software Stack

| Component | Version | Notes |
|---|---|---|
| Debian Live | 12 (Bookworm) | Base OS, persistent USB |
| Nginx | latest stable | Reverse proxy + captive portal |
| PHP | 8.2 | Nextcloud dependency |
| MariaDB | 10.11 | Nextcloud database |
| Nextcloud | latest stable | Core platform |
| Kiwix-serve | latest stable | ZIM file server |

---

## ZIM Content

| File | Size | Source |
|---|---|---|
| Wikipedia (EN, images) | ~100GB | [download.kiwix.org](https://download.kiwix.org) |
| WikiMed (medical) | ~3GB | [download.kiwix.org](https://download.kiwix.org) |

---

## Build Phases

- [ ] **Phase 1** — Partition and prepare 1TB USB drive
- [ ] **Phase 2** — Build Debian Live base with persistence
- [ ] **Phase 3** — Install and configure LAMP stack
- [ ] **Phase 4** — Install and configure Nextcloud
- [ ] **Phase 5** — Install Kiwix and download ZIM files
- [ ] **Phase 6** — Configure Nginx reverse proxy + captive portal
- [ ] **Phase 7** — Configure GL-SFT1200 router integration
- [ ] **Phase 8** — End-to-end testing

---

## Use Case

Designed for scenarios where infrastructure has failed — natural disasters, grid outages, communications blackouts. Anyone with a WiFi-capable device (phone, laptop, tablet) can connect to the router and immediately access:

- Community message board for coordination
- Shared file uploads (maps, documents, photos)
- Full Wikipedia for reference
- Medical information (WikiMed)

No accounts required for basic access. No internet. No cloud dependency.
