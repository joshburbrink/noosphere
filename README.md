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
     +-- Nextcloud (snap)
     |     |-- File sharing & uploads
     |     |-- Nextcloud Talk (messaging/chat)
     |     └-- Document collaboration
     |
     +-- Kiwix-serve
           |-- Wikipedia (full, with images, ~100GB)
           |-- WikiMed (~3GB)
           └-- + additional ZIM content (~257GB total)
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
| sda2 | 30GB | ext4 | Ubuntu Server OS |
| sda3 | ~901GB | ext4 | Nextcloud data + Kiwix ZIM files |

---

## Software Stack

| Component | Version | Notes |
|---|---|---|
| Ubuntu Server | 24.04 LTS | Base OS — broad hardware compatibility, 5yr support |
| Nextcloud | latest stable | Installed via snap — zero dependency management |
| Kiwix-serve | latest stable | ZIM file server |
| Nginx | latest stable | Reverse proxy + captive portal |

---

## ZIM Content

See [docs/kiwix-content.md](docs/kiwix-content.md) for the full content list (~257GB).

---

## Build Phases

- [x] **Phase 1** — Partition and format 1TB USB drive
- [ ] **Phase 2** — Install Ubuntu Server 24.04 LTS to USB
- [ ] **Phase 3** — Install and configure Nextcloud (snap)
- [ ] **Phase 4** — Install Kiwix and download ZIM files
- [ ] **Phase 5** — Configure Nginx reverse proxy + captive portal
- [ ] **Phase 6** — Configure GL-SFT1200 router integration
- [ ] **Phase 7** — End-to-end testing

---

## Use Case

Designed for scenarios where infrastructure has failed — natural disasters, grid outages, communications blackouts. Anyone with a WiFi-capable device (phone, laptop, tablet) can connect to the router and immediately access:

- Community message board for coordination
- Shared file uploads (maps, documents, photos)
- Full Wikipedia for reference
- Medical information (WikiMed)

No accounts required for basic access. No internet. No cloud dependency.