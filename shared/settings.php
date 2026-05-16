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

$_settings_cache = [];

function get_setting($key, $default = '') {
    global $_settings_cache;
    if (array_key_exists($key, $_settings_cache)) return $_settings_cache[$key];
    $s = _sdb()->prepare('SELECT value FROM settings WHERE key=?');
    $s->execute([$key]);
    $row = $s->fetch(PDO::FETCH_ASSOC);
    $_settings_cache[$key] = $row ? $row['value'] : $default;
    return $_settings_cache[$key];
}

function set_setting($key, $value) {
    global $_settings_cache;
    unset($_settings_cache[$key]);
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

// Returns array of ['key','label','type','enabled'] for configurable registry fields
function get_registry_fields() {
    $raw = get_setting('registry_fields', '');
    if ($raw) {
        $fields = json_decode($raw, true);
        if (is_array($fields) && $fields) return $fields;
    }
    return _default_registry_fields();
}

function _default_registry_fields() {
    return [
        ['key'=>'skills',      'label'=>'Skills you can offer',             'type'=>'textarea', 'enabled'=>true],
        ['key'=>'have',        'label'=>'Supplies / resources available',   'type'=>'textarea', 'enabled'=>false],
        ['key'=>'need',        'label'=>'Supplies / resources needed',      'type'=>'textarea', 'enabled'=>false],
        ['key'=>'bunk',        'label'=>'Bunk / room assignment',           'type'=>'text',     'enabled'=>false],
        ['key'=>'dietary',     'label'=>'Dietary / medical needs',          'type'=>'text',     'enabled'=>false],
        ['key'=>'next_of_kin', 'label'=>'Next of kin / emergency contact', 'type'=>'text',     'enabled'=>false],
    ];
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

// Returns the registry tile description — custom override if set, otherwise auto-generated
function get_registry_description() {
    $custom = get_setting('registry_description', '');
    if ($custom !== '') return $custom;

    $checkin = get_setting('registry_checkin','1') === '1';
    $found   = get_setting('registry_found_person','1') === '1';
    $shelter = get_setting('registry_shelter','0') === '1';
    $enabled = array_column(array_filter(get_registry_fields(), function($f){ return $f['enabled']; }), 'key');

    $has_skills   = in_array('skills',      $enabled);
    $has_supply   = in_array('have',        $enabled) || in_array('need', $enabled);
    $has_shelter_fields = in_array('bunk',  $enabled) || in_array('dietary', $enabled) || in_array('next_of_kin', $enabled);

    if (!$checkin && $found)  return 'Report and track found persons';
    if (!$checkin && !$found) return 'Community registry';

    if ($shelter || $has_shelter_fields) {
        $parts = ['Shelter check-in'];
        if ($has_shelter_fields) $parts[] = 'room and dietary tracking';
        if ($found) $parts[] = 'found person reports';
        return implode(' · ', $parts);
    }

    $desc = 'Sign in and share your status';
    $extras = [];
    if ($has_skills) $extras[] = 'skills';
    if ($has_supply) $extras[] = 'resources';
    if ($extras) $desc .= ' — list ' . implode(' and ', $extras);
    if ($found)  $desc .= ' · report found persons';
    return $desc;
}

function _rf($enabled_keys) {
    $all = _default_registry_fields();
    foreach ($all as &$f) $f['enabled'] = in_array($f['key'], $enabled_keys);
    return json_encode($all);
}

function _presets() {
    $full_cats  = json_encode(_default_forum_cats());
    $event_cats = json_encode([['key'=>'general','label'=>'General','icon'=>'💬','desc'=>'Questions, coordination, everything else']]);
    $sar_cats   = json_encode([['key'=>'missing','label'=>'Missing Persons','icon'=>'🔍','desc'=>'Searching for someone — also check the Registry']]);
    $res_cats   = json_encode(_default_forum_cats());

    return [
        'emergency' => [
            'instance_tagline'          => 'Offline information hub — no internet required',
            'readonly'                  => '0',
            'homepage_alert'            => '',
            'show_registry'             => '1',
            'registry_label'            => 'Registry',
            'registry_description'      => '',
            'registry_checkin'          => '1',
            'registry_statuses'         => 'OK, Need Help, Checking In',
            'registry_fields'           => _rf(['skills']),
            'registry_found_person'     => '1',
            'registry_location_required'=> '1',
            'registry_shelter'          => '0',
            'shelter_name'         => '',
            'shelter_capacity'     => '0',
            'show_chat'            => '1',
            'show_forum'           => '1',
            'forum_categories'     => $full_cats,
            'show_files'           => '1',
            'show_library'         => '1',
            'show_maps'            => '1',
            'show_topo'            => '1',
            'show_calendar'        => '1',
            'show_tasks'           => '1',
            'tasks_categories'     => 'Rescue,Logistics,Medical,Maintenance,Communications,Other',
            'tasks_show_rewards'   => '0',
            'tasks_require_login'  => '0',
            'tasks_allow_self_create' => '0',
            'tasks_auto_close_hours'  => '0',
            'show_weather'         => '1',
            'weather_label'        => 'Weather Log',
            'show_radio'           => '1',
            'radio_label'          => 'Radio Net Log',
            'show_runners'         => '1',
            'runners_label'        => 'Runner Board',
            'registry_allow_self_register' => '1',
            'require_registration'        => '0',
        ],
        'event' => [
            'instance_tagline'          => 'Event check-in and information hub',
            'readonly'                  => '0',
            'homepage_alert'            => '',
            'show_registry'             => '1',
            'registry_label'            => 'Event Check-In',
            'registry_checkin'          => '1',
            'registry_statuses'         => 'Attending, Not Yet Arrived, Left Early',
            'registry_fields'           => _rf([]),
            'registry_found_person'     => '0',
            'registry_location_required'=> '0',
            'registry_shelter'          => '0',
            'shelter_name'         => '',
            'shelter_capacity'     => '0',
            'show_chat'            => '1',
            'show_forum'           => '1',
            'forum_categories'     => $event_cats,
            'show_files'           => '1',
            'show_library'         => '0',
            'show_maps'            => '1',
            'show_topo'            => '1',
            'show_calendar'        => '1',
            'show_tasks'           => '0',
            'tasks_categories'     => 'Setup,Logistics,Cleanup,Volunteers,Other',
            'tasks_show_rewards'   => '0',
            'tasks_require_login'  => '0',
            'tasks_allow_self_create' => '0',
            'tasks_auto_close_hours'  => '0',
            'show_weather'         => '0',
            'weather_label'        => 'Weather Log',
            'show_radio'           => '0',
            'radio_label'          => 'Radio Net Log',
            'show_runners'         => '0',
            'runners_label'        => 'Runner Board',
            'registry_allow_self_register' => '1',
            'require_registration'        => '0',
        ],
        'sar' => [
            'instance_tagline'          => 'Search & Rescue Operations',
            'readonly'                  => '0',
            'homepage_alert'            => 'Search & Rescue Operation Active',
            'show_registry'             => '1',
            'registry_label'            => 'Registry',
            'registry_checkin'          => '0',
            'registry_statuses'         => 'OK, Need Help, Checking In',
            'registry_fields'           => _rf([]),
            'registry_found_person'     => '1',
            'registry_location_required'=> '1',
            'registry_shelter'          => '0',
            'shelter_name'         => '',
            'shelter_capacity'     => '0',
            'show_chat'            => '1',
            'show_forum'           => '0',
            'forum_categories'     => $sar_cats,
            'show_files'           => '0',
            'show_library'         => '0',
            'show_maps'            => '1',
            'show_topo'            => '1',
            'show_calendar'        => '0',
            'show_tasks'           => '1',
            'tasks_categories'     => 'Search,Rescue,Medical,Logistics,Command,Other',
            'tasks_show_rewards'   => '0',
            'tasks_require_login'  => '0',
            'tasks_allow_self_create' => '0',
            'tasks_auto_close_hours'  => '0',
            'show_weather'         => '1',
            'weather_label'        => 'Weather Log',
            'show_radio'           => '1',
            'radio_label'          => 'Radio Net Log',
            'show_runners'         => '1',
            'runners_label'        => 'Runner Board',
            'registry_allow_self_register' => '1',
            'require_registration'        => '0',
        ],
        'shelter' => [
            'instance_tagline'          => 'Shelter check-in and management',
            'readonly'                  => '0',
            'homepage_alert'            => '',
            'show_registry'             => '1',
            'registry_label'            => 'Shelter Check-In',
            'registry_checkin'          => '1',
            'registry_statuses'         => 'Checked In, Discharged, Transferred',
            'registry_fields'           => _rf(['bunk','dietary','next_of_kin']),
            'registry_found_person'     => '0',
            'registry_location_required'=> '1',
            'registry_shelter'          => '1',
            'shelter_name'         => 'Shelter',
            'shelter_capacity'     => '100',
            'show_chat'            => '1',
            'show_forum'           => '1',
            'forum_categories'     => $event_cats,
            'show_files'           => '1',
            'show_library'         => '0',
            'show_maps'            => '0',
            'show_topo'            => '0',
            'show_calendar'        => '1',
            'show_tasks'           => '1',
            'tasks_categories'     => 'Intake,Logistics,Medical,Maintenance,Staffing,Other',
            'tasks_show_rewards'   => '0',
            'tasks_require_login'  => '0',
            'tasks_allow_self_create' => '0',
            'tasks_auto_close_hours'  => '0',
            'show_weather'         => '0',
            'weather_label'        => 'Weather Log',
            'show_radio'           => '1',
            'radio_label'          => 'Radio Net Log',
            'show_runners'         => '1',
            'runners_label'        => 'Runner Board',
            'registry_allow_self_register' => '1',
            'require_registration'        => '1',
        ],
        'kiosk' => [
            'instance_tagline'          => 'Community information kiosk',
            'readonly'                  => '1',
            'homepage_alert'            => '',
            'show_registry'             => '0',
            'registry_label'            => 'Registry',
            'registry_checkin'          => '0',
            'registry_statuses'         => 'OK, Need Help, Checking In',
            'registry_fields'           => _rf([]),
            'registry_found_person'     => '0',
            'registry_location_required'=> '1',
            'registry_shelter'          => '0',
            'shelter_name'         => '',
            'shelter_capacity'     => '0',
            'show_chat'            => '0',
            'show_forum'           => '1',
            'forum_categories'     => $full_cats,
            'show_files'           => '0',
            'show_library'         => '1',
            'show_maps'            => '1',
            'show_topo'            => '0',
            'show_calendar'        => '1',
            'show_tasks'           => '0',
            'tasks_categories'     => 'General,Other',
            'tasks_show_rewards'   => '0',
            'tasks_require_login'  => '0',
            'tasks_allow_self_create' => '0',
            'tasks_auto_close_hours'  => '0',
            'show_weather'         => '0',
            'weather_label'        => 'Weather Log',
            'show_radio'           => '0',
            'radio_label'          => 'Radio Net Log',
            'show_runners'         => '0',
            'runners_label'        => 'Runner Board',
            'registry_allow_self_register' => '0',
            'require_registration'        => '0',
        ],
        'resource' => [
            'instance_tagline'          => 'Community resource coordination hub',
            'readonly'                  => '0',
            'homepage_alert'            => '',
            'show_registry'             => '1',
            'registry_label'            => 'Registry',
            'registry_description'      => '',
            'registry_checkin'          => '1',
            'registry_statuses'         => 'OK, Need Help, Checking In',
            'registry_fields'           => _rf(['skills','have','need']),
            'registry_found_person'     => '0',
            'registry_location_required'=> '1',
            'registry_shelter'          => '0',
            'shelter_name'         => '',
            'shelter_capacity'     => '0',
            'show_chat'            => '1',
            'show_forum'           => '1',
            'forum_categories'     => $res_cats,
            'show_files'           => '1',
            'show_library'         => '1',
            'show_maps'            => '1',
            'show_topo'            => '0',
            'show_calendar'        => '1',
            'show_tasks'           => '1',
            'tasks_categories'     => 'Distribution,Collection,Logistics,Other',
            'tasks_show_rewards'   => '1',
            'tasks_require_login'  => '0',
            'tasks_allow_self_create' => '1',
            'tasks_auto_close_hours'  => '0',
            'show_weather'         => '0',
            'weather_label'        => 'Weather Log',
            'show_radio'           => '0',
            'radio_label'          => 'Radio Net Log',
            'show_runners'         => '0',
            'runners_label'        => 'Runner Board',
            'registry_allow_self_register' => '1',
            'require_registration'        => '0',
        ],
    ];
}

function get_presets() { return _presets(); }

// Auto-track page visits via shutdown hook (skips admin, requires active session)
register_shutdown_function(function() {
    if (session_status() !== PHP_SESSION_ACTIVE) return;
    $uri = $_SERVER['REQUEST_URI'] ?? '/';
    if (strpos($uri, '/admin') !== false) return;
    $module = 'home';
    foreach (['/registry'=>'registry','/forum'=>'forum','/chat'=>'chat',
              '/files'=>'files','/maps'=>'maps','/library'=>'library',
              '/calendar'=>'calendar','/weather'=>'weather','/radio'=>'radio','/runners'=>'runners'] as $path => $mod) {
        if (strpos($uri, $path) !== false) { $module = $mod; break; }
    }
    $af = __DIR__ . '/analytics.php';
    if (file_exists($af)) { require_once $af; track_visit($module); }
});

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
