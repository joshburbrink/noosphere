<?php
// Region pack helpers  -  see issue #78 Phase 1.
// A region pack lives at /var/lib/noosphere/regions/<slug>/ and contains
// region.json plus optional subdirs (topo/, radio/, seeds/, etc).
// The "world" pack ships in the repo and is the always-available fallback.

require_once __DIR__ . '/settings.php';

define('REGIONS_DIR', '/var/lib/noosphere/regions');
define('REGION_FALLBACK_SLUG', 'world');

$_region_cache = [];

function active_region_slug() {
    $slug = get_setting('active_region', REGION_FALLBACK_SLUG);
    if (!$slug || !preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug)) {
        return REGION_FALLBACK_SLUG;
    }
    if (!is_dir(REGIONS_DIR . '/' . $slug)) {
        return REGION_FALLBACK_SLUG;
    }
    return $slug;
}

function region_dir($slug = null) {
    $slug = $slug ?: active_region_slug();
    return REGIONS_DIR . '/' . $slug;
}

// Build a path under the active region. Does NOT verify existence.
function region_path($sub = '', $slug = null) {
    $base = region_dir($slug);
    if ($sub === '' || $sub === null) return $base;
    return $base . '/' . ltrim($sub, '/');
}

// Public URL prefix for static assets in the active region pack.
// Served via nginx at /regions/<slug>/...
function region_url($sub = '', $slug = null) {
    $slug = $slug ?: active_region_slug();
    $u = '/regions/' . rawurlencode($slug);
    if ($sub !== '' && $sub !== null) $u .= '/' . ltrim($sub, '/');
    return $u;
}

function _load_region_json($slug) {
    global $_region_cache;
    if (isset($_region_cache[$slug])) return $_region_cache[$slug];
    $path = REGIONS_DIR . '/' . $slug . '/region.json';
    $data = null;
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        if ($raw !== false) {
            $j = json_decode($raw, true);
            if (is_array($j)) $data = $j;
        }
    }
    return $_region_cache[$slug] = $data;
}

// Read a region metadata key. Dotted paths supported: region_meta('center.lat').
// Falls back to the world pack, then to $default.
function region_meta($key, $default = null) {
    $slug = active_region_slug();
    $val = _meta_lookup(_load_region_json($slug), $key);
    if ($val !== null) return $val;
    if ($slug !== REGION_FALLBACK_SLUG) {
        $val = _meta_lookup(_load_region_json(REGION_FALLBACK_SLUG), $key);
        if ($val !== null) return $val;
    }
    return $default;
}

function _meta_lookup($data, $key) {
    if (!is_array($data)) return null;
    $cur = $data;
    foreach (explode('.', $key) as $part) {
        if (!is_array($cur) || !array_key_exists($part, $cur)) return null;
        $cur = $cur[$part];
    }
    return $cur;
}

function list_installed_regions() {
    $out = [];
    if (!is_dir(REGIONS_DIR)) return $out;
    foreach (scandir(REGIONS_DIR) as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $j = _load_region_json($entry);
        if (!$j) continue;
        $out[] = [
            'slug'    => $entry,
            'label'   => $j['label']    ?? $entry,
            'country' => $j['country']  ?? '',
            'state'   => $j['state']    ?? '',
            'zone'    => $j['climate_zone'] ?? '',
        ];
    }
    usort($out, fn($a, $b) => strcmp($a['label'], $b['label']));
    return $out;
}

function set_active_region($slug) {
    if (!is_dir(REGIONS_DIR . '/' . $slug)) return false;
    set_setting('active_region', $slug);
    $j = _load_region_json($slug);
    if (is_array($j)) {
        if (!empty($j['label']))        set_setting('region_label', $j['label']);
        if (!empty($j['country']))      set_setting('region_country', $j['country']);
        if (!empty($j['state']))        set_setting('region_state', $j['state']);
        if (!empty($j['counties']) && is_array($j['counties'])) {
            set_setting('region_counties', implode(',', $j['counties']));
        }
        if (!empty($j['climate_zone'])) set_setting('region_climate_zone', $j['climate_zone']);
        if (isset($j['center']['lat']))  set_setting('region_lat', (string)$j['center']['lat']);
        if (isset($j['center']['lng']))  set_setting('region_lng', (string)$j['center']['lng']);
    }
    return true;
}
