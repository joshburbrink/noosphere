# Noosphere Module Creation Guide

A module is a self-contained feature (registry, forum, weather, etc.) that lives under `/var/www/noosphere/<module>/` and is gated by a setting toggle so operators can enable/disable it per deployment.

This guide documents the conventions that keep modules consistent, safe, and operable in all six deployment presets (`emergency`, `event`, `sar`, `shelter`, `kiosk`, `resource`).

---

## 1. File layout

```
/var/www/noosphere/<module>/
  index.php          # main entry point
  <extra>.php        # optional sub-pages (export.php, _section.php, etc.)
/var/lib/noosphere/<module>.db   # SQLite database (created lazily)
```

Sub-pages that are included rather than served directly should be prefixed with `_` (e.g. `_scanner_section.php`).

---

## 2. Required boilerplate

Every module's `index.php` MUST begin with:

```php
<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();

// Module disabled -> 404 (NOT redirect  -  keeps URLs deterministic)
if (get_setting('show_<module>','1') !== '1') { http_response_code(404); exit; }

$is_admin    = !empty($_SESSION['admin']);
$is_readonly = is_readonly();
```

**Do not include `shared/analytics.php` directly.** `settings.php` registers a shutdown hook that auto-tracks page visits. If your module is a new top-level path, add it to the `$paths` map in `shared/settings.php`.

---

## 3. Settings integration

### 3a. Toggle in all six presets

Every module MUST have a `show_<module>` key set in all six presets in `shared/settings.php` -> `_presets()`. Decide on/off per preset using this rubric:

| Preset    | Default purpose                          |
|-----------|------------------------------------------|
| emergency | Most modules ON (full disaster response) |
| event     | Public-facing only (no SAR/weather/radio)|
| sar       | Operational only (no files/forum/cal)    |
| shelter   | Intake-focused (no maps/topo)            |
| kiosk     | Read-only public info                    |
| resource  | Coordination-focused                     |

### 3b. Sub-settings

Module-specific options (labels, categories, feature flags) MUST also appear in every preset. Use:
- `<module>_label`  -  string shown on the homepage tile
- `<module>_<feature>`  -  `'1'` / `'0'` boolean toggles
- `<module>_categories`  -  comma-separated or JSON list

### 3c. Admin panel wiring

Simple ON/OFF modules go in `$simple_mods` in `admin/index.php`. Modules with sub-settings get their own `mod-section`. Any new setting keys MUST be added to the appropriate save-handler array (`$toggle_keys` for booleans, `$text_keys` for strings).

---

## 4. Homepage tile

In `/var/www/noosphere/index.php`, add a tile guarded by the show toggle:

```php
if (get_setting('show_<module>','1')==='1') {
    $label = get_setting('<module>_label','Default');
    $tiles[] = ['url'=>'/<module>/', 'icon'=>'…', 'label'=>$label, 'desc'=>'…'];
}
```

---

## 5. Database

- One SQLite database per module at `/var/lib/noosphere/<module>.db`.
- Prefer the `SQLite3` class for new modules. PDO is allowed for legacy parity but must still respect WAL and prepared statements.
- ALWAYS set `PRAGMA journal_mode=WAL` immediately after opening  -  multiple PHP-FPM workers will deadlock otherwise.
- `CREATE TABLE IF NOT EXISTS …` at the top of the file is the schema source of truth.
- Schema migrations for existing installs: wrap `ALTER TABLE … ADD COLUMN` in `@` (or try/catch for PDO) so re-runs don't error.

```php
$db = new SQLite3('/var/lib/noosphere/<module>.db');
$db->exec("PRAGMA journal_mode=WAL");
$db->exec("CREATE TABLE IF NOT EXISTS …");
foreach (['new_col TEXT','another_col INTEGER DEFAULT 0'] as $col) {
    @$db->exec("ALTER TABLE <module> ADD COLUMN $col");
}
```

---

## 6. Security

| Concern              | Rule                                                              |
|----------------------|-------------------------------------------------------------------|
| CSRF                 | `csrf_verify()` on EVERY POST; `csrf_field()` in every form       |
| Read-only mode       | `readonly_die()` OR `if (!$is_readonly)` wrap on every write path |
| Admin actions        | Inside `if ($is_admin) { … }`  -  never trust client                |
| Output               | `htmlspecialchars($x, ENT_QUOTES)` on ALL user data               |
| DB input             | Prepared statements only  -  no string concatenation                |
| ID inputs            | `(int)$_POST['id']`  -  always cast                                 |
| File upload MIME     | `check_mime_safe()` / `allowed_image_mime()` from security.php    |
| Rate-limited actions | `rate_limit('action_key', max, window_seconds)`                   |
| Banned users         | `ban_check_or_die()` before accepting submissions                 |

---

## 7. Optionality & user identity

- **Kiosk / readonly:** writes blocked; reads always work.
- **Registered users:** if a user is logged into the registry, `$_SESSION['reg_name']` is set  -  auto-fill the author field and skip the name prompt.
- **`require_registration` setting:** if `'1'`, block anonymous posts; require name+PIN via `verify_pin()`.

---

## 8. Anti-patterns

### Nested forms

NEVER nest a `<form>` inside the admin `settings-form`. Browsers silently drop the inner form's fields. Use an external hidden form + JS helper:

```html
<form id="my-action-form" method="post" action="" style="display:none">
  <?= csrf_field() ?>
  <input type="hidden" name="act" value="…">
</form>
<button type="button" onclick="document.getElementById('my-action-form').submit()">…</button>
```

### Redirect instead of 404 when disabled

`header('Location: /')` leaks that the module existed and breaks deep links. Use `http_response_code(404); exit;`.

### Manual analytics calls

Don't `require_once shared/analytics.php` and call `track_visit()` yourself. The shutdown hook in `settings.php` already does this. Add new paths to the map there.

---

## 9. Nginx

If your module has its own routes (clean URLs, sub-paths, static assets), add a location block in `/etc/nginx/sites-available/noosphere`. Simple PHP modules need nothing  -  the default `location ~ \.php$` handler picks them up.

---

## 10. Pre-merge checklist

- [ ] `show_<module>` present in ALL six presets
- [ ] Module-specific settings in ALL six presets
- [ ] Homepage tile guarded by show toggle
- [ ] Admin toggle + save-handler keys
- [ ] Nginx location block (if needed)
- [ ] `sec_session_start()` + show-toggle guard at top of `index.php`
- [ ] `PRAGMA journal_mode=WAL` on every DB open
- [ ] Migration-safe `ALTER TABLE` for schema changes
- [ ] `csrf_verify()` on every POST handler
- [ ] `readonly_die()` / `!$is_readonly` on every write
- [ ] All output `htmlspecialchars`'d, all DB input prepared
- [ ] Mobile-friendly layout (viewport meta, responsive CSS)
- [ ] Works correctly in `kiosk` preset (read-only)

