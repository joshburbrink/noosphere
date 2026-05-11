<?php
define('SETTINGS_DB', '/var/lib/noosphere/settings.db');

function _sdb() {
    static $db = null;
    if (!$db) {
        $db = new PDO('sqlite:' . SETTINGS_DB);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY, value TEXT NOT NULL)");
        _init_settings($db);
    }
    return $db;
}

function _init_settings($db) {
    $count = $db->query('SELECT COUNT(*) FROM settings')->fetchColumn();
    if ($count == 0) _apply_preset('emergency', $db);
}

function get_setting($key, $default = '') {
    static $cache = [];
    if (array_key_exists($key, $cache)) return $cache[$key];
    $s = _sdb()->prepare('SELECT value FROM settings WHERE key=?');
    $s->execute([$key]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    $cache[$key] = $row ? $row['value'] : $default;
    return $cache[$key];
}

function set_setting($key, $value) {
    // Reset static cache so subsequent get_setting calls read fresh values
    get_setting('__bust__');
    $ref = &$GLOBALS;  // force cache access via workaround below
    _sdb()->prepare('INSERT OR REPLACE INTO settings (key,value) VALUES (?,?)')->execute([$key, (string)$value]);
}

function is_readonly() {
    return get_setting('readonly', '0') === '1';
}

function readonly_die() {
    if (is_readonly()) {
        http_response_code(403);
        die('<p style="font-family:sans-serif;padding:2rem;color:#e94560;background:#0f0f1a;min-height:100vh;margin:0">This system is in read-only kiosk mode.</p>');
    }
}

// Returns array of status strings from the free-text setting
function get_status_options() {
    $raw = get_setting('registry_statuses', '');
    if ($raw) {
        $opts = array_values(array_filter(array_map('trim', explode(',', $raw))));
        if ($opts) return $opts;
    }
    return ['OK', 'Need Help', 'Checking In'];
}

// Returns array of ['key','label','icon','desc'] — excludes announcements (always hardcoded)
function get_forum_categories() {
    $raw = get_setting('forum_categories', '');
    if ($raw) {
        $cats = json_decode($raw, true);
        if (is_array($cats) && $cats) return $cats;
    }
    return _default_forum_cats();
}

function _default_forum_cats() {
    return [
        ['key'=>'missing',   'label'=>'Missing Persons', 'icon'=>'🔍', 'desc'=>'Searching for someone — also check the Registry'],
        ['key'=>'lostfound', 'label'=>'Lost & Found',    'icon'=>'📦', 'desc'=>'Lost or found items — post here to reunite them'],
        ['key'=>'general',   'label'=>'General',          'icon'=>'💬', 'desc'=>'Coordination, questions, everything else'],
    ];
}

function _presets() {
    $full_cats  = json_encode(_default_forum_cats());
    $event_cats = json_encode([['key'=>'general','label'=>'General','icon'=>'💬','desc'=>'Questions, coordination, everything else']]);
    $sar_cats   = json_encode([['key'=>'missing','label'=>'Missing Persons','icon'=>'🔍','desc'=>'Searching for someone — also check the Registry']]);
    $res_cats   = json_encode(_default_forum_cats());

    return [
        'emergency' => [
            'instance_tagline'    => 'Offline information hub — no internet required',
            'readonly'            => '0',
            'homepage_alert'      => '',
            'show_registry'       => '1',
            'registry_label'      => 'Registry',
            'registry_checkin'    => '1',
            'registry_statuses'   => 'OK, Need Help, Checking In',
            'registry_skills'     => '1',
            'registry_supplies'   => '0',
            'registry_missing'    => '1',
            'registry_found_person'=> '1',
            'registry_shelter'    => '0',
            'shelter_name'        => '',
            'shelter_capacity'    => '0',
            'show_chat'           => '1',
            'show_forum'          => '1',
            'forum_categories'    => $full_cats,
            'show_files'          => '1',
            'show_library'        => '1',
            'show_maps'           => '1',
            'show_calendar'       => '1',
        ],
        'event' => [
            'instance_tagline'    => 'Event check-in and information hub',
            'readonly'            => '0',
            'homepage_alert'      => '',
            'show_registry'       => '1',
            'registry_label'      => 'Event Check-In',
            'registry_checkin'    => '1',
            'registry_statuses'   => 'Attending, Not Yet Arrived, Left Early',
            'registry_skills'     => '0',
            'registry_supplies'   => '0',
            'registry_missing'    => '0',
            'registry_found_person'=> '0',
            'registry_shelter'    => '0',
            'shelter_name'        => '',
            'shelter_capacity'    => '0',
            'show_chat'           => '1',
            'show_forum'          => '1',
            'forum_categories'    => $event_cats,
            'show_files'          => '1',
            'show_library'        => '0',
            'show_maps'           => '1',
            'show_calendar'       => '1',
        ],
        'sar' => [
            'instance_tagline'    => 'Search & Rescue Operations',
            'readonly'            => '0',
            'homepage_alert'      => 'Search & Rescue Operation Active',
            'show_registry'       => '1',
            'registry_label'      => 'Registry',
            'registry_checkin'    => '0',
            'registry_statuses'   => 'OK, Need Help, Checking In',
            'registry_skills'     => '0',
            'registry_supplies'   => '0',
            'registry_missing'    => '1',
            'registry_found_person'=> '1',
            'registry_shelter'    => '0',
            'shelter_name'        => '',
            'shelter_capacity'    => '0',
            'show_chat'           => '1',
            'show_forum'          => '0',
            'forum_categories'    => $sar_cats,
            'show_files'          => '0',
            'show_library'        => '0',
            'show_maps'           => '1',
            'show_calendar'       => '0',
        ],
        'shelter' => [
            'instance_tagline'    => 'Shelter check-in and management',
            'readonly'            => '0',
            'homepage_alert'      => '',
            'show_registry'       => '1',
            'registry_label'      => 'Shelter Check-In',
            'registry_checkin'    => '1',
            'registry_statuses'   => 'Checked In, Discharged, Transferred',
            'registry_skills'     => '0',
            'registry_supplies'   => '0',
            'registry_missing'    => '0',
            'registry_found_person'=> '0',
            'registry_shelter'    => '1',
            'shelter_name'        => 'Shelter',
            'shelter_capacity'    => '100',
            'show_chat'           => '1',
            'show_forum'          => '1',
            'forum_categories'    => $event_cats,
            'show_files'          => '1',
            'show_library'        => '0',
            'show_maps'           => '0',
            'show_calendar'       => '1',
        ],
        'kiosk' => [
            'instance_tagline'    => 'Community information kiosk',
            'readonly'            => '1',
            'homepage_alert'      => '',
            'show_registry'       => '0',
            'registry_label'      => 'Registry',
            'registry_checkin'    => '0',
            'registry_statuses'   => 'OK, Need Help, Checking In',
            'registry_skills'     => '0',
            'registry_supplies'   => '0',
            'registry_missing'    => '0',
            'registry_found_person'=> '0',
            'registry_shelter'    => '0',
            'shelter_name'        => '',
            'shelter_capacity'    => '0',
            'show_chat'           => '0',
            'show_forum'          => '1',
            'forum_categories'    => $full_cats,
            'show_files'          => '0',
            'show_library'        => '1',
            'show_maps'           => '1',
            'show_calendar'       => '1',
        ],
        'resource' => [
            'instance_tagline'    => 'Community resource coordination hub',
            'readonly'            => '0',
            'homepage_alert'      => '',
            'show_registry'       => '1',
            'registry_label'      => 'Registry',
            'registry_checkin'    => '1',
            'registry_statuses'   => 'OK, Need Help, Checking In',
            'registry_skills'     => '1',
            'registry_supplies'   => '1',
            'registry_missing'    => '0',
            'registry_found_person'=> '0',
            'registry_shelter'    => '0',
            'shelter_name'        => '',
            'shelter_capacity'    => '0',
            'show_chat'           => '1',
            'show_forum'          => '1',
            'forum_categories'    => $res_cats,
            'show_files'          => '1',
            'show_library'        => '1',
            'show_maps'           => '1',
            'show_calendar'       => '1',
        ],
    ];
}

function get_presets() { return _presets(); }

function apply_preset($mode) {
    _apply_preset($mode, _sdb());
}

function _apply_preset($mode, $db) {
    $presets = _presets();
    if (!isset($presets[$mode])) return false;
    $stmt = $db->prepare('INSERT OR REPLACE INTO settings (key,value) VALUES (?,?)');
    foreach ($presets[$mode] as $k => $v) $stmt->execute([$k, $v]);
    return true;
}
