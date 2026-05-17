<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();

// Admin-only page
if (empty($_SESSION['admin'])) {
    http_response_code(403);
    die('<p style="font-family:sans-serif;padding:2rem;color:#e94560;background:#0f0f1a;min-height:100vh;margin:0">Admin login required.</p>');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin Wiki — Noosphere</title>
<style>
* { box-sizing: border-box; margin: 0; padding: 0; }
body { font-family: system-ui, sans-serif; background: #0f0f1a; color: #e0e0e0; }

header {
    background: #1a1a2e; border-bottom: 2px solid #e94560;
    padding: 12px 20px; display: flex; align-items: center; gap: 12px;
}
header a { color: #888; text-decoration: none; font-size: 13px; }
header a:hover { color: #e94560; }
header h1 { font-size: 16px; color: #e94560; flex: 1; }

.container { max-width: 860px; margin: 0 auto; padding: 24px 20px 60px; }

/* Table of contents */
.toc {
    background: #1a1a2e; border: 1px solid #2a2a4a; border-radius: 8px;
    padding: 16px 20px; margin-bottom: 32px;
}
.toc h2 { color: #e94560; font-size: 13px; text-transform: uppercase; letter-spacing: .05em; margin-bottom: 10px; }
.toc ol { padding-left: 18px; }
.toc li { margin: 4px 0; }
.toc a { color: #7ab8e8; text-decoration: none; font-size: 14px; }
.toc a:hover { color: #e94560; text-decoration: underline; }

/* Sections */
.section { margin-bottom: 40px; }
.section h2 {
    font-size: 18px; color: #e94560; border-bottom: 1px solid #2a2a4a;
    padding-bottom: 8px; margin-bottom: 16px;
}
.section h3 { font-size: 15px; color: #c0c0e0; margin: 20px 0 8px; }
.section h4 { font-size: 13px; color: #aaa; margin: 14px 0 6px; text-transform: uppercase; letter-spacing: .04em; }
.section p  { font-size: 14px; line-height: 1.65; color: #c8c8d8; margin-bottom: 10px; }
.section ul, .section ol { padding-left: 20px; margin-bottom: 10px; }
.section li { font-size: 14px; line-height: 1.7; color: #c8c8d8; }

code, kbd {
    font-family: monospace; font-size: 13px;
    background: #16213e; border: 1px solid #2a2a4a;
    padding: 1px 6px; border-radius: 4px; color: #a0d0ff;
}
pre {
    background: #101828; border: 1px solid #2a2a4a; border-radius: 6px;
    padding: 12px 14px; overflow-x: auto; margin: 10px 0 14px;
    font-family: monospace; font-size: 13px; color: #a0d0ff; line-height: 1.6;
}
.note {
    background: #16213e; border-left: 3px solid #f8c000;
    padding: 10px 14px; border-radius: 0 6px 6px 0;
    font-size: 13px; color: #d0c870; margin: 12px 0;
}
.warn {
    background: #2e1a1a; border-left: 3px solid #e94560;
    padding: 10px 14px; border-radius: 0 6px 6px 0;
    font-size: 13px; color: #e09090; margin: 12px 0;
}
.ok {
    background: #1a2e1a; border-left: 3px solid #2ecc71;
    padding: 10px 14px; border-radius: 0 6px 6px 0;
    font-size: 13px; color: #90d090; margin: 12px 0;
}

.setting-table { width: 100%; border-collapse: collapse; margin: 10px 0 16px; }
.setting-table th {
    text-align: left; font-size: 12px; color: #777; text-transform: uppercase;
    letter-spacing: .05em; padding: 6px 10px; border-bottom: 1px solid #2a2a4a;
}
.setting-table td { padding: 8px 10px; font-size: 13px; border-bottom: 1px solid #1e1e3a; vertical-align: top; }
.setting-table tr:last-child td { border-bottom: none; }
.setting-table td:first-child { color: #7ab8e8; white-space: nowrap; font-family: monospace; }
.setting-table td:last-child  { color: #c0c0d0; }
</style>
</head>
<body>

<header>
  <a href="/admin/">← Admin</a>
  <h1>Admin Wiki</h1>
</header>

<div class="container">

<div class="toc">
  <h2>Contents</h2>
  <ol>
    <li><a href="#overview">Overview</a></li>
    <li><a href="#settings">Settings Reference</a></li>
    <li><a href="#setup">Initial Setup</a></li>
    <li><a href="#router">Router & Network</a></li>
    <li><a href="#admin-access">Admin Access</a></li>
    <li><a href="#troubleshoot">Troubleshooting</a></li>
    <li><a href="#clone">Cloning the Drive</a></li>
    <li><a href="#backup">Backup & Restore</a></li>
    <li><a href="#services">Service Reference</a></li>
    <li><a href="#zim">Offline Library (Kiwix)</a></li>
    <li><a href="#map">Offline Map</a></li>
  </ol>
</div>

<!-- ── 1. Overview ───────────────────────────────────────────────────────── -->
<div class="section" id="overview">
  <h2>1. Overview</h2>
  <p>Noosphere is a self-contained offline information hub that runs on a laptop (HP 3105m) booting from a USB drive. Anyone on the local WiFi network gets directed to this hub — no internet required.</p>
  <h3>What it provides</h3>
  <ul>
    <li><b>Registry</b> — community check-in, missing persons, skills &amp; supplies</li>
    <li><b>Chat</b> — local real-time messaging</li>
    <li><b>Forum</b> — threaded discussions by category (missing persons, lost &amp; found, general)</li>
    <li><b>Files</b> — shared file uploads and downloads</li>
    <li><b>Map</b> — offline vector tile map with custom markers for coordination</li>
    <li><b>Library</b> — offline Wikipedia, first aid, repair guides (via Kiwix)</li>
    <li><b>Topo PDFs</b> — USGS topographic maps for Bartholomew + Brown County</li>
    <li><b>Calendar</b> — shared event calendar</li>
  </ul>
  <h3>Network topology</h3>
  <pre>Laptop (192.168.8.2) ──ethernet──▶ GL-SFT1200 router (192.168.8.1)
                                           │
                                      WiFi (SSID: Noosphere)
                                           │
                               Phones / tablets / laptops
                               (all get captive-portaled to hub)</pre>
  <p>During WiFi-only mode (router not connected), the laptop's WiFi interface is at <code>192.168.2.167</code>.</p>
</div>

<!-- ── 2. Settings Reference ─────────────────────────────────────────────── -->
<div class="section" id="settings">
  <h2>2. Settings Reference</h2>
  <p>Settings are stored in <code>/var/lib/noosphere/settings.db</code> and managed from the Admin → Settings tab.</p>

  <h3>Instance</h3>
  <table class="setting-table">
    <tr><th>Key</th><th>Description</th></tr>
    <tr><td>instance_name</td><td>Site title shown in browser tabs and the top of each page</td></tr>
    <tr><td>instance_tagline</td><td>Subtitle on the homepage (e.g. "Offline hub — no internet needed")</td></tr>
    <tr><td>homepage_alert</td><td>Red alert banner on the homepage. Leave blank to hide it.</td></tr>
    <tr><td>readonly</td><td>When <b>1</b>, the entire site becomes read-only — chat, registry, forum, file upload are all blocked. Use for kiosk/display deployments.</td></tr>
  </table>

  <h3>Registry</h3>
  <table class="setting-table">
    <tr><th>Key</th><th>Description</th></tr>
    <tr><td>show_registry</td><td>Show/hide the Registry feature entirely</td></tr>
    <tr><td>registry_label</td><td>What to call it — "Registry", "Event Check-In", "Shelter Check-In", etc.</td></tr>
    <tr><td>registry_checkin</td><td>Enable the check-in form (name, status, location)</td></tr>
    <tr><td>registry_statuses</td><td>Comma-separated list of status options shown in the dropdown. Example: <code>OK, Need Help, Checking In</code></td></tr>
    <tr><td>registry_skills</td><td>Show "Skills available" field (medical, ham radio, search &amp; rescue, etc.)</td></tr>
    <tr><td>registry_supplies</td><td>Show "Supplies have/need" fields</td></tr>
    <tr><td>registry_missing</td><td>Show "Looking for / missing" field</td></tr>
    <tr><td>registry_found_person</td><td>Show "Found person" checkbox</td></tr>
    <tr><td>registry_shelter</td><td>Enable shelter-specific fields (bunk number, dietary needs, next of kin)</td></tr>
    <tr><td>shelter_name</td><td>Name of the shelter, shown in the header when registry_shelter is on</td></tr>
    <tr><td>shelter_capacity</td><td>Maximum occupancy for capacity badge on homepage</td></tr>
    <tr><td>registry_allow_self_register</td><td>When <b>1</b> (default), visitors can check themselves in. Uncheck to make the registry admin-managed only.</td></tr>
    <tr><td>registry_location_required</td><td>When <b>1</b> (default), the location field is required on check-in. Uncheck for events where location doesn't apply.</td></tr>
    <tr><td>registry_description</td><td>Custom description for the Registry tile on the homepage. Leave blank to auto-generate from enabled options.</td></tr>
  </table>

  <h3>Features</h3>
  <table class="setting-table">
    <tr><th>Key</th><th>Description</th></tr>
    <tr><td>show_chat</td><td>Enable the community chat page</td></tr>
    <tr><td>show_forum</td><td>Enable the community forum / bulletin board</td></tr>
    <tr><td>forum_categories</td><td>JSON array of category objects. Manage from the Settings UI — do not edit raw JSON here unless necessary.</td></tr>
    <tr><td>show_files</td><td>Enable shared file uploads</td></tr>
    <tr><td>show_library</td><td>Enable the Kiwix offline library link</td></tr>
    <tr><td>show_maps</td><td>Enable the offline map</td></tr>
    <tr><td>show_calendar</td><td>Enable the shared calendar</td></tr>
    <tr><td>show_topo</td><td>Enable the Topo PDFs page (USGS topographic maps)</td></tr>
    <tr><td>require_registration</td><td>When <b>1</b>, unregistered visitors can view content but cannot post, send chat messages, or upload files.</td></tr>
  </table>

  <h3>Quick Start Presets</h3>
  <table class="setting-table">
    <tr><th>Preset</th><th>Best for</th></tr>
    <tr><td>Emergency</td><td>General disaster response — all features on, skills &amp; missing persons enabled</td></tr>
    <tr><td>Event</td><td>Festival, fair, or community event — simplified check-in, no missing persons</td></tr>
    <tr><td>Search &amp; Rescue</td><td>SAR operations — map &amp; missing persons focused, no calendar or library</td></tr>
    <tr><td>Shelter</td><td>Shelter management — bunk tracking, dietary, next-of-kin fields</td></tr>
    <tr><td>Kiosk</td><td>Read-only display — no chat/registry, just library, forum, and map</td></tr>
    <tr><td>Resource Hub</td><td>Supply coordination — skills &amp; supplies fields enabled</td></tr>
  </table>
  <div class="warn">Applying a preset <b>overwrites all settings</b>. Use "Reset Instance" in the Status tab to wipe data and start fresh (requires typing RESET to confirm).</div>
</div>

<!-- ── 3. Initial Setup ───────────────────────────────────────────────────── -->
<div class="section" id="setup">
  <h2>3. Initial Setup</h2>
  <h3>First boot checklist</h3>
  <ol>
    <li>Boot laptop from the USB drive (press F9 or F10 on HP 3105m for boot menu)</li>
    <li>Login: <code>root</code> / <em>password set during install</em></li>
    <li>Verify services are running: <code>systemctl status nginx php8.4-fpm mbtileserver kiwix mariadb</code></li>
    <li>Check IP address: <code>ip addr show</code> — look for <code>192.168.2.x</code> (WiFi) or <code>192.168.8.2</code> (eth)</li>
    <li>Open a browser to <code>http://192.168.2.167</code> (or <code>http://192.168.8.2</code>) to verify the hub loads</li>
    <li>Log in to admin panel (keyboard shortcut <kbd>aaa</kbd> on the homepage, or go to <code>/admin/</code>)</li>
    <li>Set instance name and tagline for your deployment</li>
    <li>Apply the appropriate preset (Emergency, SAR, Shelter, etc.)</li>
  </ol>

  <h3>Database locations</h3>
  <table class="setting-table">
    <tr><th>Database</th><th>Path</th></tr>
    <tr><td>Settings</td><td><code>/var/lib/noosphere/settings.db</code></td></tr>
    <tr><td>Registry</td><td><code>/var/lib/noosphere/registry.db</code></td></tr>
    <tr><td>Chat</td><td><code>/var/lib/noosphere/chat.db</code></td></tr>
    <tr><td>Forum</td><td><code>/var/lib/noosphere/forum.db</code></td></tr>
    <tr><td>Calendar</td><td><code>/var/lib/noosphere/calendar.db</code></td></tr>
    <tr><td>Map markers</td><td><code>/var/lib/noosphere/map_markers.db</code></td></tr>
    <tr><td>Rate limits</td><td><code>/var/lib/noosphere/ratelimit.db</code></td></tr>
    <tr><td>Admin sessions</td><td><code>/var/lib/noosphere/admin.db</code></td></tr>
  </table>

  <h3>App file locations</h3>
  <pre>/var/www/noosphere/
├── index.php            ← Homepage
├── shared/
│   ├── security.php     ← CSRF, sessions, rate limiting, bans
│   └── settings.php     ← Settings library (get/set functions, presets)
├── admin/
│   ├── index.php        ← Admin panel
│   └── wiki.php         ← This page
├── registry/index.php
├── chat/index.php
├── forum/index.php
├── files/index.php
├── maps/
│   ├── index.php        ← Map viewer
│   ├── markers.php      ← Marker API
│   └── topo/index.php   ← Topo PDF listing
└── calendar/index.php</pre>
</div>

<!-- ── 4. Router & Network ────────────────────────────────────────────────── -->
<div class="section" id="router">
  <h2>4. Router &amp; Network</h2>
  <h3>Wiring</h3>
  <ol>
    <li>Plug an ethernet cable from the laptop's <b>eth0 / eno1</b> port into any <b>LAN port</b> on the GL-SFT1200 (not the WAN port)</li>
    <li>The router's WAN port should be left disconnected (no internet)</li>
    <li>The router DHCP will assign the laptop <code>192.168.8.x</code> — set it static at <code>192.168.8.2</code></li>
  </ol>

  <h3>Run router setup</h3>
  <pre>bash /usr/local/bin/setup-router.sh</pre>
  <p>This script configures dnsmasq and iptables so that anyone connecting to the router's WiFi gets captive-portaled to the hub.</p>

  <h3>Captive portal logic</h3>
  <p>When a device connects to the WiFi:</p>
  <ol>
    <li>Their DNS queries all resolve to <code>192.168.8.2</code> (the hub)</li>
    <li>HTTP requests to any domain return a redirect to <code>http://192.168.8.2/</code></li>
    <li>iOS / Android / Windows all detect the captive portal and pop up the "Sign in to network" dialog</li>
    <li>Detection endpoints at <code>/hotspot-detect.html</code>, <code>/generate_204</code>, <code>/ncsi.txt</code> are served by Nginx</li>
  </ol>

  <div class="note">HTTPS sites will not captive-portal correctly — the redirect only works for plain HTTP. This is normal and expected behavior.</div>

  <h3>Checking if captive portal is working</h3>
  <pre>iptables -t nat -L PREROUTING -n --line-numbers   # should show redirect rules
systemctl status dnsmasq</pre>
</div>

<!-- ── 5. Admin Access ─────────────────────────────────────────────────────── -->
<div class="section" id="admin-access">
  <h2>5. Admin Access</h2>
  <h3>From desktop / keyboard</h3>
  <p>On the homepage, quickly type <kbd>a</kbd> <kbd>a</kbd> <kbd>a</kbd> (three times, no spaces). You'll be redirected to <code>/admin/</code>.</p>

  <h3>From phone or tablet</h3>
  <p>Navigate directly to <code>http://192.168.8.2/admin/</code> (on router network) or <code>http://192.168.2.167/admin/</code> (on laptop WiFi). Enter your admin username and password.</p>

  <h3>Admin panel tabs</h3>
  <table class="setting-table">
    <tr><th>Tab</th><th>What it contains</th></tr>
    <tr><td>Dashboard</td><td>Service status cards, disk usage, registry/chat/forum/map quick stats, active bans count</td></tr>
    <tr><td>Network</td><td>Connected devices list with IP/MAC — ban by IP directly from here</td></tr>
    <tr><td>Community</td><td>Registry entries, forum threads/posts, bans (add/remove by name or IP), calendar events, uploaded files</td></tr>
    <tr><td>Content</td><td>Uploaded files, registry photos, map markers, ZIM library enable/disable — all deletable</td></tr>
    <tr><td>System</td><td>CPU temp, disk usage, top processes, nginx/php/system logs, database backup download</td></tr>
    <tr><td>Settings</td><td>Four sub-tabs: <b>Configure</b> (identity, read-only, registration access), <b>Modules</b> (per-feature toggles, custom registry fields, forum categories), <b>Security</b> (change Linux and admin passwords), <b>Tools</b> (presets, utility scripts, Reset Instance)</td></tr>
  </table>

  <h3>Changing the admin password</h3>
  <p>Use the admin panel: <b>Settings → Security → Admin Panel Password</b>. Enter your current password and set a new one.</p>
  <p>Command-line fallback (if locked out):</p>
  <pre># Generate a bcrypt hash:
NEW_HASH=$(php -r "echo password_hash('your-new-password', PASSWORD_DEFAULT);")
# Store it in the settings database:
sqlite3 /var/lib/noosphere/settings.db \
  "INSERT OR REPLACE INTO settings (key,value) VALUES ('admin_password_hash','$NEW_HASH');"</pre>
  <div class="warn">Change the admin password before deploying in a real scenario. Registry users with admin privilege can also log in with their registry PIN.</div>

  <h3>Changing Linux system passwords</h3>
  <p>Use <b>Settings → Security → Linux System Credentials</b> to change the password for the Linux user and/or root. These passwords control SSH and console access and persist when cloning the drive.</p>

  <h3>Banning a user</h3>
  <p>Two ways to ban from the admin panel:</p>
  <ul>
    <li><b>Network tab</b> — shows all connected devices by IP/MAC. Click Ban next to a device to ban by IP immediately.</li>
    <li><b>Community tab</b> — add a ban by name, IP, or both, with an optional reason. Also shows the full ban list for removal.</li>
  </ul>
  <p>Banned IPs are blocked from chat, posting, and file uploads. To inspect bans directly:</p>
  <pre>sqlite3 /var/lib/noosphere/admin.db "SELECT * FROM bans;"</pre>
</div>

<!-- ── 6. Troubleshooting ──────────────────────────────────────────────────── -->
<div class="section" id="troubleshoot">
  <h2>6. Troubleshooting</h2>

  <h3>Site not loading</h3>
  <pre>systemctl status nginx
systemctl status php8.4-fpm
# Restart both:
systemctl restart nginx php8.4-fpm</pre>
  <p>Check logs:</p>
  <pre>journalctl -u nginx -n 50
tail -50 /var/log/nginx/error.log</pre>

  <h3>Map tiles not showing</h3>
  <pre>systemctl status mbtileserver
# Restart:
systemctl restart mbtileserver
# Check tile server is responding:
curl -s http://localhost:8889/services | head</pre>
  <p>Tiles are at <code>/var/www/noosphere/maps/counties.mbtiles</code> and <code>/var/www/noosphere/maps/satellite.mbtiles</code>. Verify files exist and are not 0 bytes.</p>

  <h3>Library (Kiwix) not loading</h3>
  <pre>systemctl status kiwix
systemctl restart kiwix
# List registered ZIM files:
ls /var/lib/kiwix/zim/
# kiwix-watch auto-registers new ZIMs dropped in that directory</pre>

  <h3>Wireless interface not coming up</h3>
  <p>The HP 3105m uses a Realtek or Broadcom WiFi chip. Steps to bring it up:</p>
  <pre># Check what interfaces exist:
ip link show
# Or:
iw dev

# If wlan0 exists but is down:
ip link set wlan0 up
iw wlan0 scan    # verify it can see networks

# If no wlan interface appears, check if driver is loaded:
lspci | grep -i network
dmesg | grep -i wifi
dmesg | grep -i wireless
modprobe rtl8188ee   # or the driver name shown in dmesg

# If driver is missing, check firmware:
ls /lib/firmware/rtl*   # or brcm/ for Broadcom

# NetworkManager approach:
nmcli device status
nmcli device connect wlan0

# Or using wpa_supplicant directly:
wpa_supplicant -B -i wlan0 -c /etc/wpa_supplicant/wpa_supplicant.conf
dhclient wlan0</pre>
  <div class="note">In production the laptop is the <b>access point</b> (via the GL router), so the laptop's own WiFi adapter is less critical — ethernet to the router is the primary connection path.</div>

  <h3>Database errors / locked database</h3>
  <pre># Check WAL mode is on (should say 'wal'):
sqlite3 /var/lib/noosphere/chat.db "PRAGMA journal_mode;"

# Force WAL on all databases:
for db in /var/lib/noosphere/*.db; do
  sqlite3 "$db" "PRAGMA journal_mode=WAL;"
done

# If a database is corrupt:
sqlite3 /var/lib/noosphere/chat.db ".dump" > /tmp/chat_dump.sql
sqlite3 /var/lib/noosphere/chat_new.db < /tmp/chat_dump.sql
mv /var/lib/noosphere/chat.db /var/lib/noosphere/chat.db.bak
mv /var/lib/noosphere/chat_new.db /var/lib/noosphere/chat.db</pre>

  <h3>Disk full</h3>
  <pre>df -h
# Find large files:
du -sh /var/lib/noosphere/* | sort -rh | head
du -sh /var/lib/kiwix/* | sort -rh | head

# Clear old chat messages (keep last 10,000):
sqlite3 /var/lib/noosphere/chat.db \
  "DELETE FROM messages WHERE id NOT IN (SELECT id FROM messages ORDER BY id DESC LIMIT 10000);"

# Vacuum to reclaim space:
sqlite3 /var/lib/noosphere/chat.db "VACUUM;"</pre>

  <h3>Rate limiting blocking legitimate users</h3>
  <pre># Clear all rate limits:
sqlite3 /var/lib/noosphere/ratelimit.db "DELETE FROM hits;"
# Or restart the service (hits table is auto-maintained)</pre>

  <h3>PHP errors</h3>
  <pre>tail -100 /var/log/php8.4-fpm.log
# Enable error display temporarily (ONLY for debugging, disable after):
# In /etc/php/8.4/fpm/php.ini — set display_errors = On, restart php-fpm</pre>
</div>

<!-- ── 7. Cloning the Drive ────────────────────────────────────────────────── -->
<div class="section" id="clone">
  <h2>7. Cloning the Drive</h2>
  <p>The system boots from a USB drive. To create a backup copy or deploy to a second device, clone the USB drive.</p>

  <h3>Identify the drives</h3>
  <pre>lsblk
# Example output:
# sda   ← current boot USB
# sdb   ← target USB (plug in AFTER booting)</pre>

  <h3>Clone with dd (bit-for-bit copy)</h3>
  <div class="warn">This overwrites <b>everything</b> on the target drive. Double-check <code>of=</code> is the correct device.</div>
  <pre># Clone from sda to sdb (adjust device names as needed):
dd if=/dev/sda of=/dev/sdb bs=4M status=progress conv=fsync

# This will take 20-40 minutes for a 64GB drive.</pre>

  <h3>Clone with partclone (faster — skips empty blocks)</h3>
  <pre>apt install partclone
# Copy only used blocks:
partclone.ext4 -c -s /dev/sda2 | partclone.ext4 -r -o /dev/sdb2</pre>

  <h3>After cloning to a larger drive</h3>
  <pre># If the target drive is larger, expand the partition:
parted /dev/sdb resizepart 2 100%
resize2fs /dev/sdb2</pre>

  <h3>Transfer just the databases (data only)</h3>
  <p>If you want to copy data to an already-set-up system without a full clone:</p>
  <pre>rsync -av /var/lib/noosphere/ root@TARGET_IP:/var/lib/noosphere/
rsync -av /var/lib/kiwix/     root@TARGET_IP:/var/lib/kiwix/</pre>
</div>

<!-- ── 8. Backup & Restore ────────────────────────────────────────────────── -->
<div class="section" id="backup">
  <h2>8. Backup &amp; Restore</h2>
  <h3>Quick backup script</h3>
  <pre>#!/bin/bash
STAMP=$(date +%Y%m%d_%H%M)
DEST=/tmp/noosphere_backup_$STAMP.tar.gz
tar -czf "$DEST" /var/lib/noosphere/ /var/www/noosphere/
echo "Backup saved to $DEST"
ls -lh "$DEST"</pre>

  <h3>Copy backup to a USB stick</h3>
  <pre>mount /dev/sdc1 /mnt
cp /tmp/noosphere_backup_*.tar.gz /mnt/
umount /mnt</pre>

  <h3>Restore</h3>
  <pre>tar -xzf noosphere_backup_YYYYMMDD_HHMM.tar.gz -C /
systemctl restart nginx php8.4-fpm</pre>

  <h3>Export registry as CSV (for paper records)</h3>
  <pre>sqlite3 -csv /var/lib/noosphere/registry.db \
  "SELECT name, location, status, missing_family, notes, created_at FROM people ORDER BY created_at;" \
  > /tmp/registry_export.csv</pre>
</div>

<!-- ── 9. Service Reference ───────────────────────────────────────────────── -->
<div class="section" id="services">
  <h2>9. Service Reference</h2>
  <table class="setting-table">
    <tr><th>Service</th><th>Purpose</th><th>Control</th></tr>
    <tr><td>nginx</td><td>Web server — routes all HTTP traffic</td><td><code>systemctl restart nginx</code></td></tr>
    <tr><td>php8.4-fpm</td><td>PHP process manager — runs all app logic</td><td><code>systemctl restart php8.4-fpm</code></td></tr>
    <tr><td>mbtileserver</td><td>Serves vector map tiles on port 8889</td><td><code>systemctl restart mbtileserver</code></td></tr>
    <tr><td>kiwix</td><td>Serves offline Wikipedia/library on port 8888</td><td><code>systemctl restart kiwix</code></td></tr>
    <tr><td>mariadb</td><td>MySQL-compatible database (registry, forum, chat, calendar)</td><td><code>systemctl restart mariadb</code></td></tr>
    <tr><td>kiwix-watch</td><td>Auto-registers new ZIM files dropped in /var/lib/kiwix/</td><td><code>systemctl restart kiwix-watch</code></td></tr>
    <tr><td>dnsmasq</td><td>DNS + DHCP for captive portal</td><td><code>systemctl restart dnsmasq</code></td></tr>
    <tr><td>NetworkManager</td><td>WiFi management (if used)</td><td><code>systemctl restart NetworkManager</code></td></tr>
  </table>

  <h3>Check all at once</h3>
  <pre>systemctl status nginx php8.4-fpm mbtileserver kiwix mariadb dnsmasq --no-pager</pre>

  <h3>Enable auto-start on boot</h3>
  <pre>systemctl enable nginx php8.4-fpm mbtileserver kiwix mariadb</pre>

  <h3>Nginx config location</h3>
  <pre>/etc/nginx/sites-available/noosphere  (symlinked to sites-enabled/)
# After editing, test and reload:
nginx -t && systemctl reload nginx</pre>
</div>

<!-- ── 10. Offline Library ────────────────────────────────────────────────── -->
<div class="section" id="zim">
  <h2>10. Offline Library (Kiwix)</h2>
  <p>The library is powered by Kiwix, which serves ZIM files (compressed offline wikis).</p>

  <h3>Currently registered ZIM files</h3>
  <pre>ls -lh /var/lib/kiwix/*.zim</pre>

  <h3>Adding a new ZIM file</h3>
  <ol>
    <li>Download the ZIM from <code>download.kiwix.org</code> (on a machine with internet)</li>
    <li>Copy to <code>/var/lib/kiwix/</code>: <code>scp file.zim root@192.168.2.167:/var/lib/kiwix/</code></li>
    <li>kiwix-watch will auto-register it within a few seconds</li>
    <li>Verify: <code>curl -s http://localhost:8080</code> and browse to <code>/library/</code></li>
  </ol>

  <h3>Recommended ZIM files</h3>
  <table class="setting-table">
    <tr><th>File</th><th>Size</th><th>Use case</th></tr>
    <tr><td>wikipedia_en_medicine</td><td>~11 GB</td><td>First aid, medical reference</td></tr>
    <tr><td>wikipedia_en_simple_all</td><td>~1 GB</td><td>Plain-English Wikipedia</td></tr>
    <tr><td>wiktionary_en_all</td><td>~2 GB</td><td>Dictionary</td></tr>
    <tr><td>ifixit_en_all</td><td>~1.5 GB</td><td>Repair guides — generators, equipment</td></tr>
    <tr><td>lrnselfreliance_en_all</td><td>~200 MB</td><td>Preparedness and self-reliance guides</td></tr>
    <tr><td>wikispecies_en_all</td><td>~300 MB</td><td>Species identification</td></tr>
  </table>

  <div class="note">The 64GB USB drive is sufficient for the OS + essential ZIMs. For the full library (~31 GB of ZIMs), use a 128GB or larger drive.</div>
</div>


<!-- ── 11. Offline Map ───────────────────────────────────────────────────── -->
<div class=section id=map>
  <h2>11. Offline Map</h2>
  <p>The map is built on <b>MapLibre GL JS</b> with offline vector tiles covering Bartholomew and Brown County, Indiana. Everything — tiles, fonts, satellite imagery — is served from the server with no internet dependency.</p>

  <h3>Features</h3>
  <ul>
    <li><b>Street names</b> — rendered from the <code>transportation_name</code> vector layer at zoom 11+</li>
    <li><b>Themes</b> — Dark, Light, Hi-Vis (toggle in header)</li>
    <li><b>Satellite</b> — USGS NAIP aerial imagery layer (toggle in header); overlays road labels for context</li>
    <li><b>Pins</b> — any user can drop a pin (type, title, note, name); creator sees a Delete button in the popup via a localStorage token; admins can delete any pin</li>
    <li><b>Topo PDFs</b> — 28 USGS 1:24,000 quad sheets for both counties, downloadable from the Topo PDFs link</li>
  </ul>

  <h3>Tile files</h3>
  <table class=setting-table>
    <tr><th>File</th><th>Contents</th></tr>
    <tr><td><code>/var/www/noosphere/maps/counties.mbtiles</code></td><td>Vector tiles — roads, buildings, water, labels (zoom 4–14)</td></tr>
    <tr><td><code>/var/www/noosphere/maps/satellite.mbtiles</code></td><td>Raster satellite imagery — USGS NAIP (zoom 10–16)</td></tr>
  </table>

  <h3>Glyph fonts</h3>
  <p>Street name rendering requires offline PBF font files. Noto Sans Regular is pre-installed:</p>
  <pre>/var/www/noosphere/maps/fonts/Noto Sans Regular/*.pbf</pre>

  <h3>Satellite tile download</h3>
  <p>The satellite layer downloads USGS NAIP imagery for both counties (~1–3 GB). Run once while the server has internet access:</p>
  <pre>python3 /usr/local/bin/download-satellite.py</pre>
  <p>Monitor progress:</p>
  <pre>tail -f /var/log/satellite-download.log</pre>
  <p>Once <code>satellite.mbtiles</code> exists, mbtileserver auto-detects it. The Satellite button in the map will start working immediately — no service restart needed.</p>

  <h3>Pin ownership</h3>
  <p>When a pin is created, the server returns a one-time 32-character token stored in the browser's <code>localStorage</code>. That browser can delete its own pins. Tokens do not transfer across devices — if you need to delete a pin from a different device, use an admin account.</p>

  <h3>Adding / rebuilding tiles</h3>
  <p>The vector tiles were built with <code>tilemaker</code> from an OSM extract of Indiana. Config files are in <code>/var/www/noosphere/maps/tilemaker/</code>.</p>
  <pre># Re-generate from a fresh OSM extract (requires tilemaker installed):
tilemaker --input /var/www/noosphere/maps/indiana-latest.osm.pbf           --output /var/www/noosphere/maps/counties.mbtiles           --config  /var/www/noosphere/maps/tilemaker/config-openmaptiles.json           --process /var/www/noosphere/maps/tilemaker/process-openmaptiles.lua</pre>
  <div class=note>Tile generation can take 5–20 minutes depending on hardware. The existing <code>counties.mbtiles</code> is sufficient for normal use.</div>
</div>


<div class="section" id="hardware">
  <h2>12. Optional Hardware</h2>
  <p>Noosphere works without any additional hardware, but these peripherals unlock specific modules. All are passive add-ons — plug in and configure from the admin panel.</p>

  <h3>RTL-SDR Dongle</h3>
  <p><strong>Enables:</strong> NOAA Weather Radio streaming + SAME alert decoding (<code>/weather/</code>), spectrum waterfall scanner (<code>/radio/</code>).</p>
  <h4>Recommended models</h4>
  <ul>
    <li><strong>RTL-SDR Blog V3 / V4</strong> (~$30) — best sensitivity, TCXO clock, bias-tee for powered antennas</li>
    <li><strong>NooElec NESDR Smart</strong> (~$25) — solid budget option, TCXO, SMA connector</li>
    <li>Any RTL2832U-based dongle works; avoid the cheapest no-brand units (high PPM drift)</li>
  </ul>
  <h4>Setup</h4>
  <ol>
    <li>Plug dongle into any USB port on the server</li>
    <li>Drivers are pre-installed; DVB modules are blacklisted at <code>/etc/modprobe.d/rtlsdr-blacklist.conf</code></li>
    <li>Go to <strong>Admin → Settings → Modules → SDR Radio</strong> — select NWR or Scanner mode</li>
    <li>Configure frequency, gain, and PPM offset; hit Save</li>
  </ol>
  <h4>Verify detection</h4>
  <pre>rtlsdr-detect.sh --verbose   # should show tuner type and serial</pre>
  <div class=note>If the dongle is not detected, check that DVB modules are blacklisted: <code>lsmod | grep dvb</code> should return nothing. If modules are loaded, run <code>modprobe -r dvb_usb_rtl28xxu</code> and reboot.</div>

  <h3>NWR Antenna (162 MHz)</h3>
  <p><strong>Enables:</strong> Reliable NOAA Weather Radio reception. The stock whip antenna included with most RTL-SDR kits resonates near 860 MHz and has very poor gain at 162 MHz.</p>
  <h4>DIY quarter-wave dipole (~$5 in parts)</h4>
  <ul>
    <li>Two 462 mm (~18.2 in) wire elements, vertical orientation</li>
    <li>Mount near a window — metal roofs and walls block VHF significantly</li>
    <li>Connect to dongle via PL-259 or BNC → SMA adapter</li>
  </ul>
  <h4>Commercial options</h4>
  <ul>
    <li><strong>Bingfu VHF UHF Scanner Antenna</strong> (~$15) — magnetic base, telescoping, covers 136–512 MHz</li>
    <li><strong>Tram 1410</strong> (~$25) — discone, covers 25–1300 MHz, best all-around if you also run the scanner</li>
    <li>Search terms: VHF scanner antenna SMA or 162 MHz weather radio antenna</li>
  </ul>
  <div class=note>Even a basic telescoping antenna extended to 462 mm and placed near a window will dramatically outperform the stock whip at 162 MHz.</div>

  <h3>Radio Programming Cable (Baofeng / CHIRP)</h3>
  <p><strong>Enables:</strong> <a href="/radio/program/" style="color:#7ad">/radio/program/</a> — direct USB programming of 500+ radios from county frequency data without a separate laptop.</p>
  <h4>Compatible cables</h4>
  <ul>
    <li><strong>Baofeng USB-K cable</strong> (~$8) — works with UV-5R, UV-82, BF-888S, UV-17, and most Baofeng models; 3.5mm/2.5mm K-plug</li>
    <li><strong>Kenwood KPG-22U / KPG-46U clone</strong> (~$10) — Kenwood and compatible models</li>
    <li><strong>FTDI-based USB cables</strong> — Yaesu, Icom, Wouxun, and other brands; check CHIRP wiki for your specific model</li>
    <li>Avoid cables marked charge only — they lack the data lines needed for programming</li>
  </ul>
  <h4>Drivers</h4>
  <p>Most cables use CP2102, CH340, or PL2303 USB-serial chips — all are supported by the Debian kernel with no manual install. The cable will appear as <code>/dev/ttyUSB0</code> when plugged in.</p>
  <h4>Setup</h4>
  <ol>
    <li>Plug cable into the server USB port, other end into radio (radio powered on, in normal mode)</li>
    <li>Go to <a href="/radio/program/" style="color:#7ad">/radio/program/</a></li>
    <li>Select the detected port, choose brand and model, pick county data, click Program</li>
  </ol>
  <div class=note>The radio model cannot be auto-detected from USB — always select the correct model before programming. Programming the wrong model may corrupt the radio's memory; verify with the CHIRP channel preview before hitting Program.</div>
</div>

</div><!-- /container -->
</body>
</html>
