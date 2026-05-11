<?php
require_once '/var/www/noosphere/shared/security.php';
require_once '/var/www/noosphere/shared/settings.php';
sec_session_start();

define('ADMIN_PASS', 'admin'); // fallback bootstrap password
define('FORUM_DB',    '/var/lib/noosphere/forum.db');
define('FILES_DIR',   '/var/lib/noosphere/files/');
define('PHOTOS_DIR',  '/var/lib/noosphere/registry_photos/');
define('ZIM_DIR',     '/var/lib/kiwix/zim/');
define('ZIM_DIS_DIR', '/var/lib/kiwix/zim/disabled/');
define('KIWIX_LIB',   '/var/lib/kiwix/library.xml');

function get_zim_info() {
    $lib = [];
    $xml = @simplexml_load_file(KIWIX_LIB);
    if ($xml) {
        foreach ($xml->book as $book) {
            $fname = basename((string)$book['path']);
            $lib[$fname] = ['id' => (string)$book['id'], 'title' => (string)$book['title']];
        }
    }
    $zims = [];
    foreach (glob(ZIM_DIR . '*.zim') ?: [] as $p) {
        $f = basename($p);
        $zims[$f] = ['path'=>$p,'enabled'=>true,'size'=>filesize($p),'title'=>$lib[$f]['title']??'','id'=>$lib[$f]['id']??''];
    }
    foreach (is_dir(ZIM_DIS_DIR) ? (glob(ZIM_DIS_DIR . '*.zim') ?: []) : [] as $p) {
        $f = basename($p);
        $zims[$f] = ['path'=>$p,'enabled'=>false,'size'=>filesize($p),'title'=>$lib[$f]['title']??'','id'=>$lib[$f]['id']??''];
    }
    ksort($zims);
    return $zims;
}

function zim_display_name($fname, $title) {
    if ($title) return $title;
    $n = preg_replace('/[_-](\d{4}-\d{2})\.zim$/', '', $fname);
    $n = preg_replace('/_(en|all|maxi|nopic|mini)/', ' ', $n);
    return ucwords(trim(str_replace('_', ' ', $n)));
}

// ── Auth ─────────────────────────────────────────────────────────────────────
if (isset($_POST['logout'])) {
    csrf_verify();
    session_destroy();
    header('Location: /admin/');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    csrf_verify();
    rate_limit('admin_login', 10, 300);
    $pw   = $_POST['password'] ?? '';
    $name = trim($_POST['reg_name'] ?? '');

    if ($pw === ADMIN_PASS) {
        $_SESSION['admin']      = true;
        $_SESSION['admin_name'] = '(bootstrap)';
        rate_reset('admin_login');
    } elseif ($name) {
        $row = verify_pin($name, $pw, true);
        if ($row) {
            $_SESSION['admin']      = true;
            $_SESSION['admin_name'] = $row['name'];
        } else {
            $error = 'Incorrect credentials or insufficient privileges.';
        }
    } else {
        $error = 'Incorrect password.';
    }
}

$authed = !empty($_SESSION['admin']);

// ── Admin actions (all require auth + CSRF) ───────────────────────────────────
if ($authed && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $act = $_POST['act'] ?? '';

    // --- Run script ---
    $safe_scripts = ['status-check', 'restart-services', 'restart-wireless'];
    if ($act === 'run' && in_array($_POST['script'] ?? '', $safe_scripts)) {
        $script     = $_POST['script'];
        $run_output = shell_exec('sudo /usr/local/bin/' . $script . '.sh 2>&1');
        $run_name   = $script;
    }

    // --- Promote / demote registry user ---
    if ($act === 'set_admin') {
        $uid  = (int)($_POST['uid'] ?? 0);
        $flag = (int)($_POST['flag'] ?? 0);
        $rdb  = new SQLite3(REGISTRY_DB);
        $s    = $rdb->prepare('UPDATE registry SET is_admin=? WHERE id=?');
        $s->bindValue(1, $flag ? 1 : 0); $s->bindValue(2, $uid);
        $s->execute();
        $msg = $flag ? 'User promoted to admin.' : 'Admin privileges removed.';
    }

    // --- Ban user ---
    if ($act === 'ban') {
        $ban_name   = trim($_POST['ban_name'] ?? '');
        $ban_ip     = trim($_POST['ban_ip']   ?? '');
        $ban_reason = trim($_POST['ban_reason'] ?? '');
        if ($ban_name || $ban_ip) {
            add_ban($ban_name ?: null, $ban_ip ?: null, $ban_reason, $_SESSION['admin_name']);
            $msg = 'Ban added.';
        }
    }

    // --- Remove ban ---
    if ($act === 'unban') {
        remove_ban((int)($_POST['ban_id'] ?? 0));
        $msg = 'Ban removed.';
    }

    // --- Delete registry entry ---
    if ($act === 'del_registry') {
        $uid = (int)($_POST['uid'] ?? 0);
        $rdb = new SQLite3(REGISTRY_DB);
        $row = $rdb->querySingle("SELECT photo FROM registry WHERE id=$uid", true);
        if ($row) {
            if ($row['photo']) {
                $p = realpath(PHOTOS_DIR . $row['photo']);
                if ($p && strpos($p, realpath(PHOTOS_DIR)) === 0) @unlink($p);
            }
            $rdb->exec("DELETE FROM registry WHERE id=$uid");
            $msg = 'Registry entry deleted.';
        }
    }

    // --- Delete forum thread ---
    if ($act === 'del_thread') {
        $tid = (int)($_POST['tid'] ?? 0);
        $fdb = new PDO('sqlite:' . FORUM_DB);
        $fdb->prepare('DELETE FROM posts WHERE thread_id=?')->execute([$tid]);
        $fdb->prepare('DELETE FROM threads WHERE id=?')->execute([$tid]);
        $msg = 'Thread deleted.';
    }

    // --- Delete forum post ---
    if ($act === 'del_post') {
        $pid = (int)($_POST['pid'] ?? 0);
        $fdb = new PDO('sqlite:' . FORUM_DB);
        $fdb->prepare('DELETE FROM posts WHERE id=?')->execute([$pid]);
        $msg = 'Post deleted.';
    }

    // --- Delete file ---
    if ($act === 'del_file') {
        $fname = $_POST['fname'] ?? '';
        $path  = realpath(FILES_DIR . $fname);
        if ($path && strpos($path, realpath(FILES_DIR)) === 0 && file_exists($path)) {
            unlink($path);
            $msg = 'File deleted.';
        }
    }

    // --- Calendar: add event ---
    if ($act === 'add_event') {
        $cdb = new PDO('sqlite:/var/lib/noosphere/calendar.db');
        $cdb->exec("CREATE TABLE IF NOT EXISTS events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            event_date TEXT NOT NULL,
            event_time TEXT,
            location TEXT,
            notes TEXT,
            created_by TEXT,
            created_at INTEGER NOT NULL
        )");
        $title  = trim($_POST['title']  ?? '');
        $date   = trim($_POST['edate']  ?? '');
        $time   = trim($_POST['etime']  ?? '');
        $loc    = trim($_POST['eloc']   ?? '');
        $notes  = trim($_POST['enotes'] ?? '');
        if ($title && $date) {
            $cdb->prepare('INSERT INTO events (title,event_date,event_time,location,notes,created_by,created_at) VALUES (?,?,?,?,?,?,?)')
                ->execute([$title, $date, $time, $loc, $notes, $_SESSION['admin_name'], time()]);
            $msg = 'Event added.';
        }
    }

    // --- Calendar: delete event ---
    if ($act === 'del_event') {
        $eid = (int)($_POST['eid'] ?? 0);
        $cdb = new PDO('sqlite:/var/lib/noosphere/calendar.db');
        $cdb->prepare('DELETE FROM events WHERE id=?')->execute([$eid]);
        $msg = 'Event deleted.';
    }

    // --- Kiwix: toggle ZIM module ---
    if ($act === 'kiwix_toggle') {
        $zim    = $_POST['zim'] ?? '';
        $enable = ($_POST['enable'] ?? '0') === '1';
        if (!preg_match('/^[a-zA-Z0-9._\-]+\.zim$/', $zim)) {
            $msg = 'Invalid filename.';
        } else {
            $src = $enable ? ZIM_DIS_DIR . $zim : ZIM_DIR . $zim;
            $dst = $enable ? ZIM_DIR . $zim      : ZIM_DIS_DIR . $zim;
            if (file_exists($src)) {
                if (!$enable && !is_dir(ZIM_DIS_DIR)) mkdir(ZIM_DIS_DIR, 0755, true);
                rename($src, $dst);
                if ($enable) {
                    shell_exec('kiwix-manage ' . escapeshellarg(KIWIX_LIB) . ' add ' . escapeshellarg($dst) . ' 2>&1');
                } else {
                    $xml = @simplexml_load_file(KIWIX_LIB);
                    if ($xml) {
                        foreach ($xml->book as $book) {
                            if (basename((string)$book['path']) === $zim) {
                                shell_exec('kiwix-manage ' . escapeshellarg(KIWIX_LIB) . ' remove ' . escapeshellarg((string)$book['id']) . ' 2>&1');
                                break;
                            }
                        }
                    }
                }
                shell_exec('chown www-data:www-data ' . escapeshellarg(KIWIX_LIB));
                shell_exec('systemctl restart kiwix 2>&1');
                $msg = $enable ? 'Module enabled.' : 'Module disabled.';
            } else {
                $msg = 'ZIM file not found.';
            }
        }
    }

    // --- Settings: apply quick-start preset ---
    if ($act === 'apply_preset') {
        $preset = $_POST['preset'] ?? '';
        $presets = get_presets();
        if (apply_preset($preset)) {
            $label = ucwords(str_replace(['-','_'], ' ', $preset));
            $msg = 'Quick start applied: ' . esc($label) . '. Review and adjust below.';
        }
    }

    // --- Settings: save all settings ---
    if ($act === 'save_settings') {
        $text_keys = ['instance_name','instance_tagline','homepage_alert',
                      'registry_label','registry_description','registry_statuses','shelter_name','shelter_capacity'];
        foreach ($text_keys as $k) {
            if (isset($_POST[$k])) set_setting($k, trim($_POST[$k]));
        }
        $toggle_keys = ['show_registry','registry_checkin','registry_found_person','registry_location_required','registry_shelter',
                        'show_chat','show_forum','show_files','show_library','show_maps','show_calendar','readonly'];
        foreach ($toggle_keys as $k) {
            set_setting($k, isset($_POST[$k]) ? '1' : '0');
        }
        // Registry fields: build from parallel arrays
        if (isset($_POST['rf_label']) && is_array($_POST['rf_label'])) {
            $fields      = [];
            $rf_keys     = (array)($_POST['rf_key']     ?? []);
            $rf_types    = (array)($_POST['rf_type']    ?? []);
            $rf_enabled  = array_flip((array)($_POST['rf_enabled'] ?? []));
            $valid_types = ['text','textarea','checkbox'];
            foreach ($_POST['rf_label'] as $i => $label) {
                $label = trim($label);
                if (!$label) continue;
                $key  = trim($rf_keys[$i] ?? '');
                if (!$key) {
                    $key = preg_replace('/[^a-z0-9]+/', '_', strtolower($label));
                    $key = trim($key, '_') ?: 'field_' . $i;
                }
                $type    = in_array($rf_types[$i] ?? '', $valid_types) ? $rf_types[$i] : 'text';
                $enabled = isset($rf_enabled[$key]);
                $fields[] = ['key'=>$key, 'label'=>$label, 'type'=>$type, 'enabled'=>$enabled];
            }
            set_setting('registry_fields', json_encode($fields));
        }
        // Forum categories: build from parallel arrays
        if (isset($_POST['cat_label']) && is_array($_POST['cat_label'])) {
            $cats = [];
            $existing_keys = (array)($_POST['cat_key'] ?? []);
            $icons         = (array)($_POST['cat_icon'] ?? []);
            foreach ($_POST['cat_label'] as $i => $label) {
                $label = trim($label);
                if (!$label) continue;
                $icon = trim($icons[$i] ?? '');
                $key  = trim($existing_keys[$i] ?? '');
                if (!$key) {
                    $key = preg_replace('/[^a-z0-9]+/', '_', strtolower($label));
                    $key = trim($key, '_') ?: 'cat_' . $i;
                }
                if ($key === 'announcements') continue;
                $cats[] = ['key'=>$key, 'label'=>$label, 'icon'=>$icon, 'desc'=>''];
            }
            set_setting('forum_categories', json_encode($cats));
        }
        $msg = 'Settings saved.';
    }

    // --- Settings: reset instance ---
    if ($act === 'reset_instance') {
        $confirm = trim($_POST['reset_confirm'] ?? '');
        if ($confirm === 'RESET') {
            // Wipe all user data, preserve settings DB
            $dbs_to_clear = [
                '/var/lib/noosphere/registry.db' => ['registry'],
                '/var/lib/noosphere/chat.db'     => ['messages'],
                '/var/lib/noosphere/forum.db'    => ['threads','posts'],
                '/var/lib/noosphere/calendar.db' => ['events'],
                '/var/lib/noosphere/ratelimit.db'=> ['rate_hits'],
            ];
            foreach ($dbs_to_clear as $path => $tables) {
                try {
                    $db = new PDO('sqlite:' . $path);
                    foreach ($tables as $t) $db->exec("DELETE FROM $t");
                } catch (Exception $e) {}
            }
            // Clear uploaded files and photos
            foreach (glob(FILES_DIR . '*') ?: [] as $f) @unlink($f);
            foreach (glob(PHOTOS_DIR . '*') ?: [] as $f) @unlink($f);
            $msg = 'Instance reset. All user data cleared.';
        } else {
            $msg = 'Reset cancelled — you must type RESET exactly.';
        }
    }
}

// ── Data for display ──────────────────────────────────────────────────────────
$run_output = $run_output ?? '';
$run_name   = $run_name   ?? '';
$msg        = $msg        ?? '';
$error      = $error      ?? '';

function svc_status($name) {
    return trim(shell_exec("systemctl is-active " . escapeshellarg($name) . " 2>/dev/null") ?? '');
}
function esc($s) { return htmlspecialchars($s ?? '', ENT_QUOTES); }

$scripts = [
    ['name'=>'restart-wireless',  'file'=>'restart-wireless.sh',  'desc'=>'Restart wireless interface',          'runnable'=>true],
    ['name'=>'restart-services',  'file'=>'restart-services.sh',  'desc'=>'Restart all Noosphere services',      'runnable'=>true],
    ['name'=>'status-check',      'file'=>'status-check.sh',      'desc'=>'Show services, disk, ZIMs, registry', 'runnable'=>true],
    ['name'=>'clone-drive',       'file'=>'clone-drive.sh',       'desc'=>'Clone USB to another USB (interactive)','runnable'=>false],
    ['name'=>'backup-registry',   'file'=>'backup-registry.sh',   'desc'=>'Back up registry DB to USB drive',    'runnable'=>false],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin — Noosphere</title>
<style>
* { box-sizing:border-box; margin:0; padding:0; }
body { font-family:system-ui,sans-serif; background:#0f0f1a; color:#e0e0e0; min-height:100vh; }
header { background:#1a1a2e; border-bottom:2px solid #e94560; padding:12px 20px; display:flex; align-items:center; gap:16px; }
header h1 { font-size:16px; color:#e94560; }
header a { color:#888; text-decoration:none; font-size:13px; }
header a:hover { color:#e94560; }
.spacer { flex:1; }
.who { font-size:12px; color:#666; }
.container { max-width:900px; margin:28px auto; padding:0 18px; }
.login-box { background:#1a1a2e; border:1px solid #2a2a4a; border-radius:10px; padding:32px; max-width:380px; margin:80px auto; }
.login-box h2 { color:#e94560; margin-bottom:6px; font-size:17px; }
.login-box p { color:#888; font-size:12px; margin-bottom:18px; }
.login-box input { width:100%; padding:9px 12px; background:#16213e; border:1px solid #2a2a4a; border-radius:5px; color:#e0e0e0; font-size:14px; margin-bottom:10px; }
.login-box input:focus { outline:none; border-color:#e94560; }
.btn { background:#e94560; color:#fff; border:none; padding:9px 20px; border-radius:6px; cursor:pointer; font-size:13px; font-weight:bold; }
.btn:hover { background:#c73652; }
.btn-sm { background:#16213e; border:1px solid #2a2a4a; color:#e0e0e0; padding:5px 12px; border-radius:5px; cursor:pointer; font-size:12px; }
.btn-sm:hover { border-color:#e94560; color:#e94560; }
.btn-green { background:#1a3a1a; border:1px solid #2ecc71; color:#2ecc71; padding:5px 12px; border-radius:5px; cursor:pointer; font-size:12px; }
.btn-green:hover { background:#2ecc71; color:#000; }
.btn-red { background:#3a1a1a; border:1px solid #e94560; color:#e94560; padding:5px 10px; border-radius:5px; cursor:pointer; font-size:12px; }
.btn-red:hover { background:#e94560; color:#fff; }
.error { color:#e94560; font-size:13px; margin-bottom:10px; }
.msg-ok { color:#2ecc71; font-size:13px; margin-bottom:14px; }
section { margin-bottom:30px; }
section h2 { font-size:13px; color:#e94560; text-transform:uppercase; letter-spacing:1px; margin-bottom:12px; border-bottom:1px solid #2a2a4a; padding-bottom:7px; }
.svc-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(150px,1fr)); gap:8px; }
.svc-card { background:#1a1a2e; border:1px solid #2a2a4a; border-radius:6px; padding:10px 12px; }
.svc-name { font-size:11px; color:#888; margin-bottom:3px; }
.svc-state { font-size:13px; font-weight:bold; }
.active-state { color:#2ecc71; } .inactive-state { color:#e94560; }
.script-list { display:flex; flex-direction:column; gap:8px; }
.script-row { background:#1a1a2e; border:1px solid #2a2a4a; border-radius:6px; padding:12px 14px; display:flex; align-items:center; gap:12px; }
.script-info { flex:1; }
.script-name { font-size:13px; font-weight:bold; }
.script-desc { font-size:11px; color:#888; margin-top:2px; }
.script-actions { display:flex; gap:6px; flex-shrink:0; }
.output-box { background:#0a0a14; border:1px solid #2a2a4a; border-radius:6px; padding:14px; font-family:monospace; font-size:12px; white-space:pre-wrap; color:#ccc; margin-top:14px; max-height:380px; overflow-y:auto; }
.output-box h3 { color:#e94560; font-size:12px; margin-bottom:8px; font-family:system-ui; }
table { width:100%; border-collapse:collapse; font-size:13px; }
table th { text-align:left; padding:8px 10px; background:#1a1a2e; color:#888; font-size:11px; font-weight:normal; border-bottom:1px solid #2a2a4a; }
table td { padding:8px 10px; border-bottom:1px solid #1a1a2e; vertical-align:middle; }
table tr:hover td { background:#1a1a2e; }
.badge-admin { background:#16213e; border:1px solid #e94560; color:#e94560; padding:2px 8px; border-radius:10px; font-size:11px; }
.badge-user  { background:#16213e; border:1px solid #2a2a4a; color:#888; padding:2px 8px; border-radius:10px; font-size:11px; }
input[type=text], input[type=date], input[type=time], input[type=number], textarea, select {
    background:#16213e; border:1px solid #2a2a4a; color:#e0e0e0; padding:7px 10px; border-radius:5px; font-size:13px; width:100%;
}
input:focus, textarea:focus, select:focus { outline:none; border-color:#e94560; }
.form-row { display:flex; gap:10px; margin-bottom:8px; flex-wrap:wrap; }
.form-row > * { flex:1; min-width:140px; }
label { font-size:11px; color:#888; display:block; margin-bottom:3px; }
.tab-bar { display:flex; gap:2px; margin-bottom:20px; flex-wrap:wrap; }
.tab { padding:7px 16px; border-radius:6px 6px 0 0; font-size:13px; cursor:pointer; border:1px solid #2a2a4a; border-bottom:none; color:#888; background:#16213e; }
.tab.active { color:#e94560; border-color:#e94560; background:#1a1a2e; }
.tab-content { display:none; }
.tab-content.active { display:block; }
.event-list { display:flex; flex-direction:column; gap:6px; margin-bottom:16px; }
.event-row { background:#1a1a2e; border:1px solid #2a2a4a; border-radius:6px; padding:10px 14px; display:flex; align-items:center; gap:12px; }
.event-date { font-size:13px; font-weight:bold; color:#e94560; flex-shrink:0; width:90px; }
.event-info { flex:1; }
.event-title { font-size:14px; font-weight:bold; }
.event-meta { font-size:11px; color:#888; margin-top:2px; }
/* Settings tab */
/* Settings tab — module sections */
.mod-section { background:#1a1a2e; border:1px solid #2a2a4a; border-radius:8px; margin-bottom:8px; overflow:hidden; }
.mod-header { padding:12px 16px; display:flex; align-items:center; gap:12px; }
.mod-header label { font-size:14px; font-weight:bold; color:#e0e0e0; flex:1; cursor:pointer; margin:0; }
.mod-toggle { width:18px; height:18px; cursor:pointer; flex-shrink:0; accent-color:#e94560; }
.mod-body { padding:4px 16px 14px 42px; border-top:1px solid #2a2a4a; }
.mod-body.off { opacity:.4; pointer-events:none; }
.sub-row { display:flex; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid #16213e; }
.sub-row:last-child { border-bottom:none; }
.sub-row label { flex:1; font-size:13px; color:#ccc; cursor:pointer; margin:0; }
.sub-row input[type=checkbox] { width:15px; height:15px; cursor:pointer; flex-shrink:0; accent-color:#e94560; }
.sub-body { padding:8px 0 2px 26px; }
.sub-body.off { opacity:.4; pointer-events:none; }
.field-label { font-size:11px; color:#888; display:block; margin-bottom:3px; margin-top:8px; }
.radio-opts { display:flex; gap:6px; flex-wrap:wrap; margin-top:6px; margin-bottom:2px; }
.radio-opt { display:flex; align-items:center; gap:6px; background:#16213e; border:1px solid #2a2a4a; border-radius:5px; padding:7px 12px; cursor:pointer; font-size:12px; color:#ccc; }
.radio-opt input[type=radio] { accent-color:#e94560; cursor:pointer; }
/* identity section */
.identity-section { background:#1a1a2e; border:1px solid #2a2a4a; border-radius:8px; padding:16px; margin-bottom:8px; }
.identity-section h3 { font-size:11px; color:#e94560; text-transform:uppercase; letter-spacing:.06em; margin-bottom:12px; }
/* quick start */
.quick-start { border:1px solid #2a2a4a; border-radius:8px; margin-bottom:8px; overflow:hidden; }
.quick-start summary { padding:12px 16px; font-size:13px; color:#888; cursor:pointer; list-style:none; display:flex; align-items:center; gap:8px; }
.quick-start summary::before { content:'▶'; font-size:10px; transition:.15s; }
.quick-start[open] summary::before { transform:rotate(90deg); }
.quick-start summary:hover { color:#e0e0e0; }
.qs-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(160px,1fr)); gap:8px; padding:12px 16px 16px; }
.qs-card { background:#16213e; border:1px solid #2a2a4a; border-radius:6px; padding:12px; text-align:center; }
.qs-card h4 { font-size:12px; color:#e0e0e0; margin-bottom:3px; }
.qs-card p { font-size:11px; color:#666; margin-bottom:8px; }
.qs-card button { width:100%; }
/* reset */
.reset-zone { background:#1a0a0a; border:2px solid #e94560; border-radius:8px; padding:18px; margin-top:8px; }
.reset-zone h3 { color:#e94560; font-size:14px; margin-bottom:6px; }
.reset-zone p { font-size:12px; color:#888; margin-bottom:12px; }
.reset-row { display:flex; gap:8px; align-items:center; }
.reset-row input { flex:1; max-width:200px; }
</style>
</head>
<body>
<header>
  <a href="/">← Home</a>
  <h1>Admin</h1>
  <div class="spacer"></div>
  <?php if ($authed): ?>
    <span class="who">Logged in<?= $_SESSION['admin_name'] !== '(bootstrap)' ? ' as ' . esc($_SESSION['admin_name']) : '' ?></span>
    <form method="post" style="margin:0"><?= csrf_field() ?><button name="logout" class="btn-sm">Log out</button></form>
  <?php endif; ?>
</header>

<?php if (!$authed): ?>
<div class="login-box">
  <h2>Admin Access</h2>
  <p>Enter your registry name + PIN, or the system password.</p>
  <?php if (!empty($error)): ?><div class="error"><?= esc($error) ?></div><?php endif; ?>
  <form method="post">
    <?= csrf_field() ?>
    <input type="text" name="reg_name" placeholder="Registry name (optional)">
    <input type="password" name="password" placeholder="PIN or system password" autofocus>
    <button type="submit" class="btn" style="width:100%;margin-top:4px">Enter</button>
  </form>
</div>

<?php else: ?>
<div class="container">

<?php if ($msg): ?><div class="msg-ok"><?= esc($msg) ?></div><?php endif; ?>

<div class="tab-bar">
  <div class="tab active" onclick="showTab('status')">Status</div>
  <div class="tab" onclick="showTab('scripts')">Scripts</div>
  <div class="tab" onclick="showTab('users')">Users</div>
  <div class="tab" onclick="showTab('bans')">Bans</div>
  <div class="tab" onclick="showTab('moderation')">Moderation</div>
  <div class="tab" onclick="showTab('calendar')">Calendar</div>
  <div class="tab" onclick="showTab('settings')">Settings</div>
</div>

<!-- STATUS -->
<div id="tab-status" class="tab-content active">
  <section>
    <h2>Services</h2>
    <div class="svc-grid">
    <?php foreach (['nginx','php8.4-fpm','kiwix','mbtileserver'] as $svc):
      $st = svc_status($svc); ?>
      <div class="svc-card">
        <div class="svc-name"><?= $svc ?></div>
        <div class="svc-state <?= $st==='active'?'active-state':'inactive-state' ?>"><?= $st ?></div>
      </div>
    <?php endforeach; ?>
    </div>
  </section>
  <section>
    <h2>System Resources</h2>
    <?php
    // Load average
    $loadavg = file_exists('/proc/loadavg') ? explode(' ', trim(file_get_contents('/proc/loadavg'))) : [];
    $load1  = $loadavg[0] ?? '—';
    $load5  = $loadavg[1] ?? '—';
    $load15 = $loadavg[2] ?? '—';

    // Memory from /proc/meminfo
    $mem_total = $mem_avail = 0;
    if (file_exists('/proc/meminfo')) {
        foreach (file('/proc/meminfo') as $line) {
            if (preg_match('/^MemTotal:\s+(\d+)/', $line, $m))     $mem_total = (int)$m[1];
            if (preg_match('/^MemAvailable:\s+(\d+)/', $line, $m)) $mem_avail = (int)$m[1];
        }
    }
    $mem_used = $mem_total - $mem_avail;
    $mem_pct  = $mem_total ? round($mem_used / $mem_total * 100) : 0;
    $mem_col  = $mem_pct >= 85 ? '#e94560' : ($mem_pct >= 65 ? '#f39c12' : '#2ecc71');
    $mem_used_mb  = round($mem_used  / 1024);
    $mem_total_mb = round($mem_total / 1024);

    // Disk
    $disk_free  = disk_free_space('/var/lib/noosphere') ?: disk_free_space('/');
    $disk_total = disk_total_space('/var/lib/noosphere') ?: disk_total_space('/');
    $disk_used  = $disk_total - $disk_free;
    $disk_pct   = $disk_total ? round($disk_used / $disk_total * 100) : 0;
    $disk_col   = $disk_pct >= 85 ? '#e94560' : ($disk_pct >= 65 ? '#f39c12' : '#2ecc71');
    $disk_used_gb  = round($disk_used  / 1024**3, 1);
    $disk_total_gb = round($disk_total / 1024**3, 1);

    // Uptime
    $uptime_raw = file_exists('/proc/uptime') ? (float)explode(' ', file_get_contents('/proc/uptime'))[0] : 0;
    $uptime_d = floor($uptime_raw / 86400);
    $uptime_h = floor(($uptime_raw % 86400) / 3600);
    $uptime_m = floor(($uptime_raw % 3600) / 60);
    $uptime_str = ($uptime_d ? "{$uptime_d}d " : '') . ($uptime_h ? "{$uptime_h}h " : '') . "{$uptime_m}m";
    ?>
    <div class="svc-grid" style="grid-template-columns:repeat(auto-fill,minmax(170px,1fr))">
      <div class="svc-card">
        <div class="svc-name">Load avg (1/5/15 min)</div>
        <div class="svc-state" style="color:#e0e0e0;font-size:12px;margin-top:2px"><?= $load1 ?> / <?= $load5 ?> / <?= $load15 ?></div>
      </div>
      <div class="svc-card">
        <div class="svc-name">Memory</div>
        <div class="svc-state" style="color:<?= $mem_col ?>"><?= $mem_used_mb ?> / <?= $mem_total_mb ?> MB <span style="color:#888;font-size:11px">(<?= $mem_pct ?>%)</span></div>
      </div>
      <div class="svc-card">
        <div class="svc-name">Disk (data)</div>
        <div class="svc-state" style="color:<?= $disk_col ?>"><?= $disk_used_gb ?> / <?= $disk_total_gb ?> GB <span style="color:#888;font-size:11px">(<?= $disk_pct ?>%)</span></div>
      </div>
      <div class="svc-card">
        <div class="svc-name">Uptime</div>
        <div class="svc-state" style="color:#e0e0e0;font-size:12px;margin-top:2px"><?= $uptime_str ?></div>
      </div>
    </div>
  </section>
</div>

<!-- SCRIPTS -->
<div id="tab-scripts" class="tab-content">
  <section>
    <h2>Utility Scripts</h2>
    <div class="script-list">
    <?php foreach ($scripts as $s): ?>
      <div class="script-row">
        <div class="script-info">
          <div class="script-name"><?= esc($s['file']) ?></div>
          <div class="script-desc"><?= esc($s['desc']) ?></div>
        </div>
        <div class="script-actions">
          <a href="/admin/scripts/<?= $s['file'] ?>" download class="btn-sm">Download</a>
          <?php if ($s['runnable']): ?>
          <form method="post" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="run">
            <input type="hidden" name="script" value="<?= $s['name'] ?>">
            <button type="submit" class="btn-green">Run</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    <?php if ($run_output): ?>
    <div class="output-box"><h3><?= esc($run_name) ?>.sh</h3><?= esc($run_output) ?></div>
    <?php endif; ?>
  </section>
</div>

<!-- USERS -->
<div id="tab-users" class="tab-content">
  <section>
    <h2>Registry Users</h2>
    <?php
    $rdb   = new SQLite3(REGISTRY_DB);
    $users = $rdb->query("SELECT id,name,location,status,is_admin FROM registry WHERE entry_type='checkin' OR entry_type IS NULL OR entry_type='' ORDER BY name ASC");
    ?>
    <table>
      <tr><th>Name</th><th>Location</th><th>Status</th><th>Role</th><th>Actions</th></tr>
      <?php while ($u = $users->fetchArray(SQLITE3_ASSOC)): ?>
      <tr>
        <td><?= esc($u['name']) ?></td>
        <td><?= esc($u['location']) ?></td>
        <td><?= esc($u['status']) ?></td>
        <td><span class="<?= $u['is_admin'] ? 'badge-admin' : 'badge-user' ?>"><?= $u['is_admin'] ? 'Admin' : 'User' ?></span></td>
        <td>
          <form method="post" style="display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="set_admin">
            <input type="hidden" name="uid" value="<?= $u['id'] ?>">
            <input type="hidden" name="flag" value="<?= $u['is_admin'] ? 0 : 1 ?>">
            <button type="submit" class="<?= $u['is_admin'] ? 'btn-sm' : 'btn-green' ?>" style="font-size:11px">
              <?= $u['is_admin'] ? 'Demote' : 'Promote' ?>
            </button>
          </form>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this registry entry?')">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="del_registry">
            <input type="hidden" name="uid" value="<?= $u['id'] ?>">
            <button type="submit" class="btn-red" style="font-size:11px">Delete</button>
          </form>
        </td>
      </tr>
      <?php endwhile; ?>
    </table>
  </section>
</div>

<!-- BANS -->
<div id="tab-bans" class="tab-content">
  <section>
    <h2>Add Ban</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="ban">
      <div class="form-row">
        <div><label>Registry Name (optional)</label><input type="text" name="ban_name" placeholder="Leave blank to ban by IP only"></div>
        <div><label>IP Address (optional)</label><input type="text" name="ban_ip" placeholder="e.g. 192.168.8.42"></div>
      </div>
      <div style="margin-bottom:8px"><label>Reason</label><input type="text" name="ban_reason" placeholder="Reason (shown to admins only)"></div>
      <button type="submit" class="btn-red">Add Ban</button>
    </form>
  </section>
  <section>
    <h2>Active Bans</h2>
    <?php $bans = get_bans(); ?>
    <?php if (!$bans): ?><p style="color:#666;font-size:13px">No active bans.</p>
    <?php else: ?>
    <table>
      <tr><th>Name</th><th>IP</th><th>Reason</th><th>Banned by</th><th></th></tr>
      <?php foreach ($bans as $b): ?>
      <tr>
        <td><?= esc($b['name'] ?? '—') ?></td>
        <td><?= esc($b['ip']   ?? '—') ?></td>
        <td><?= esc($b['reason']) ?></td>
        <td><?= esc($b['banned_by']) ?></td>
        <td>
          <form method="post" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="unban">
            <input type="hidden" name="ban_id" value="<?= $b['id'] ?>">
            <button type="submit" class="btn-sm">Remove</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </section>
</div>

<!-- MODERATION -->
<div id="tab-moderation" class="tab-content">
  <section>
    <h2>Recent Forum Threads</h2>
    <?php
    try {
        $fdb     = new PDO('sqlite:' . FORUM_DB);
        $threads = $fdb->query('SELECT * FROM threads ORDER BY last_at DESC LIMIT 40')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $threads = []; }
    ?>
    <table>
      <tr><th>Title</th><th>Category</th><th>Author</th><th></th></tr>
      <?php foreach ($threads as $t): ?>
      <tr>
        <td><a href="/forum/?view=thread&thread=<?= $t['id'] ?>" style="color:#4a9eff;text-decoration:none"><?= esc($t['title']) ?></a></td>
        <td><?= esc($t['category']) ?></td>
        <td><?= esc($t['author']) ?></td>
        <td>
          <form method="post" style="margin:0" onsubmit="return confirm('Delete this thread and all replies?')">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="del_thread">
            <input type="hidden" name="tid" value="<?= $t['id'] ?>">
            <button type="submit" class="btn-red">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </section>

  <section>
    <h2>Uploaded Files</h2>
    <?php $ufiles = glob(FILES_DIR . '*') ?: []; ?>
    <table>
      <tr><th>Filename</th><th>Size</th><th></th></tr>
      <?php foreach ($ufiles as $fp):
        $fname = basename($fp); ?>
      <tr>
        <td><?= esc($fname) ?></td>
        <td><?= round(filesize($fp)/1024) ?> KB</td>
        <td>
          <form method="post" style="margin:0" onsubmit="return confirm('Delete this file?')">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="del_file">
            <input type="hidden" name="fname" value="<?= esc($fname) ?>">
            <button type="submit" class="btn-red">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
  </section>

  <section>
    <h2>Registry Entries (Found Persons)</h2>
    <?php
    $rdb2  = new SQLite3(REGISTRY_DB);
    $found = $rdb2->query("SELECT id,name,location,updated_at FROM registry WHERE entry_type='found_person' ORDER BY updated_at DESC");
    ?>
    <table>
      <tr><th>Name</th><th>Location</th><th></th></tr>
      <?php while ($f = $found->fetchArray(SQLITE3_ASSOC)): ?>
      <tr>
        <td><?= esc($f['name']) ?></td>
        <td><?= esc($f['location']) ?></td>
        <td>
          <form method="post" style="margin:0" onsubmit="return confirm('Delete this entry?')">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="del_registry">
            <input type="hidden" name="uid" value="<?= $f['id'] ?>">
            <button type="submit" class="btn-red">Delete</button>
          </form>
        </td>
      </tr>
      <?php endwhile; ?>
    </table>
  </section>
</div>

<!-- CALENDAR -->
<div id="tab-calendar" class="tab-content">
  <section>
    <h2>Upcoming Events</h2>
    <?php
    try {
        $cdb    = new PDO('sqlite:/var/lib/noosphere/calendar.db');
        $cdb->exec("CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, event_date TEXT NOT NULL, event_time TEXT, location TEXT, notes TEXT, created_by TEXT, created_at INTEGER NOT NULL)");
        $events = $cdb->query("SELECT * FROM events WHERE event_date >= date('now') ORDER BY event_date ASC, event_time ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $events = []; }
    ?>
    <?php if (!$events): ?><p style="color:#666;font-size:13px;margin-bottom:16px">No upcoming events.</p>
    <?php else: ?>
    <div class="event-list">
    <?php foreach ($events as $ev): ?>
      <div class="event-row">
        <div class="event-date"><?= esc($ev['event_date']) ?><?= $ev['event_time'] ? '<br><span style="font-size:11px;color:#888">'.esc($ev['event_time']).'</span>' : '' ?></div>
        <div class="event-info">
          <div class="event-title"><?= esc($ev['title']) ?></div>
          <div class="event-meta"><?= $ev['location'] ? '📍 '.esc($ev['location']) : '' ?><?= $ev['notes'] ? ' · '.esc($ev['notes']) : '' ?></div>
        </div>
        <form method="post" style="margin:0" onsubmit="return confirm('Delete this event?')">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="del_event">
          <input type="hidden" name="eid" value="<?= $ev['id'] ?>">
          <button type="submit" class="btn-red">Delete</button>
        </form>
      </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h2>Add Event</h2>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="add_event">
      <div class="form-row">
        <div><label>Title *</label><input type="text" name="title" required placeholder="Community meeting, supply distribution…"></div>
      </div>
      <div class="form-row">
        <div><label>Date *</label><input type="date" name="edate" required></div>
        <div><label>Time</label><input type="time" name="etime"></div>
        <div><label>Location</label><input type="text" name="eloc" placeholder="Shelter B, Courthouse…"></div>
      </div>
      <div style="margin-bottom:10px"><label>Notes</label><textarea name="enotes" rows="2" placeholder="Additional details…"></textarea></div>
      <button type="submit" class="btn">Add Event</button>
    </form>
  </section>
</div>

<!-- SETTINGS -->
<div id="tab-settings" class="tab-content">

<form method="post" id="settings-form">
<?= csrf_field() ?>
<input type="hidden" name="act" value="save_settings">

<!-- Identity -->
<div class="identity-section">
  <h3>Identity</h3>
  <div class="form-row">
    <div><label>Site Name</label><input type="text" name="instance_name" value="<?= esc(get_setting('instance_name','Noosphere')) ?>" placeholder="Noosphere"></div>
    <div><label>Tagline</label><input type="text" name="instance_tagline" value="<?= esc(get_setting('instance_tagline','')) ?>" placeholder="Offline information hub"></div>
  </div>
  <div><label>Alert Banner <span style="color:#555;font-weight:normal">(shown on homepage — leave blank to hide)</span></label>
  <input type="text" name="homepage_alert" value="<?= esc(get_setting('homepage_alert','')) ?>" placeholder="e.g. Shelter at capacity — see staff"></div>
</div>

<!-- Access -->
<div class="mod-section">
  <div class="mod-header">
    <input type="checkbox" class="mod-toggle" id="t_readonly" name="readonly" <?= get_setting('readonly','0')==='1'?'checked':'' ?>>
    <label for="t_readonly">Read-Only / Kiosk Mode</label>
    <span style="font-size:11px;color:#888">Blocks all writes system-wide</span>
  </div>
</div>

<!-- Registry -->
<div class="mod-section">
  <div class="mod-header">
    <input type="checkbox" class="mod-toggle" id="t_registry" name="show_registry" <?= get_setting('show_registry','1')==='1'?'checked':'' ?> onchange="modToggle('registry',this.checked)">
    <label for="t_registry">Registry</label>
  </div>
  <div class="mod-body<?= get_setting('show_registry','1')!=='1'?' off':'' ?>" id="body_registry">

    <div class="sub-row" style="padding-top:12px">
      <span class="field-label" style="margin:0;flex:1;font-size:13px;color:#ccc">Tile label on homepage</span>
      <input type="text" name="registry_label" value="<?= esc(get_setting('registry_label','Registry')) ?>" placeholder="Registry" style="width:180px">
    </div>
    <div style="margin-top:8px">
      <label class="field-label">Tile description <span style="color:#555;font-weight:normal">— leave blank to auto-generate from enabled options</span></label>
      <input type="text" name="registry_description" value="<?= esc(get_setting('registry_description','')) ?>" placeholder="Auto: Sign in and share your status — list skills">
    </div>

    <div class="sub-row" style="margin-top:10px">
      <input type="checkbox" class="sub-toggle" id="t_checkin" name="registry_checkin" <?= get_setting('registry_checkin','1')==='1'?'checked':'' ?>>
      <label for="t_checkin">Check-In form</label>
    </div>
    <div class="sub-row">
      <input type="checkbox" class="sub-toggle" id="t_found" name="registry_found_person" <?= get_setting('registry_found_person','1')==='1'?'checked':'' ?>>
      <label for="t_found">Found Person report</label>
    </div>
    <div class="sub-row">
      <input type="checkbox" class="sub-toggle" id="t_loc_req" name="registry_location_required" <?= get_setting('registry_location_required','1')==='1'?'checked':'' ?>>
      <label for="t_loc_req">Location field is required <span style="color:#555;font-weight:normal">— uncheck for events where location doesn't apply</span></label>
    </div>

    <div class="field-label" style="margin-top:14px">Status options <span style="color:#555;font-weight:normal">— comma-separated, shown in the status dropdown</span></div>
    <input type="text" name="registry_statuses" value="<?= esc(get_setting('registry_statuses','OK, Need Help, Checking In')) ?>" placeholder="OK, Need Help, Checking In" style="margin-bottom:4px">
    <div style="font-size:11px;color:#555">Examples: "OK, Need Help, Checking In" &nbsp;·&nbsp; "Checked In, Discharged, Transferred" &nbsp;·&nbsp; "Attending, Left Early"</div>

    <div class="field-label" style="margin-top:14px">Custom fields <span style="color:#555;font-weight:normal">— shown in the check-in form · check to enable</span></div>
    <div id="rf-list" style="display:flex;flex-direction:column;gap:4px;margin-top:6px">
    <?php foreach (get_registry_fields() as $rf): ?>
      <div class="rf-row sub-row" style="gap:6px;align-items:center">
        <input type="checkbox" name="rf_enabled[]" value="<?= esc($rf['key']) ?>" <?= $rf['enabled'] ? 'checked' : '' ?>>
        <input type="hidden"   name="rf_key[]"     value="<?= esc($rf['key']) ?>">
        <input type="text"     name="rf_label[]"   value="<?= esc($rf['label']) ?>" style="flex:1;min-width:0">
        <select name="rf_type[]" style="width:96px;flex-shrink:0">
          <option value="text"     <?= $rf['type']==='text'?'selected':'' ?>>Text</option>
          <option value="textarea" <?= $rf['type']==='textarea'?'selected':'' ?>>Paragraph</option>
          <option value="checkbox" <?= $rf['type']==='checkbox'?'selected':'' ?>>Checkbox</option>
        </select>
        <button type="button" onclick="removeField(this)" class="btn-red" style="font-size:11px;padding:4px 10px;flex-shrink:0">Remove</button>
      </div>
    <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:8px;margin-top:8px;align-items:center">
      <input type="text" id="new-rf-label" placeholder="New field label" style="flex:1">
      <select id="new-rf-type" style="width:96px">
        <option value="text">Text</option>
        <option value="textarea">Paragraph</option>
        <option value="checkbox">Checkbox</option>
      </select>
      <button type="button" onclick="addField()" class="btn-green" style="white-space:nowrap">+ Add</button>
    </div>

    <div class="sub-row" style="margin-top:14px">
      <input type="checkbox" id="t_shelter" name="registry_shelter" <?= get_setting('registry_shelter','0')==='1'?'checked':'' ?> onchange="shelterToggle(this.checked)">
      <label for="t_shelter">Shelter mode — shows capacity badge on homepage</label>
    </div>
    <div class="sub-body<?= get_setting('registry_shelter','0')!=='1'?' off':'' ?>" id="body_shelter">
      <div class="form-row" style="margin-top:6px">
        <div><label class="field-label">Shelter name</label><input type="text" name="shelter_name" value="<?= esc(get_setting('shelter_name','Shelter')) ?>" placeholder="Shelter"></div>
        <div><label class="field-label">Capacity (0 = unlimited)</label><input type="number" name="shelter_capacity" value="<?= esc(get_setting('shelter_capacity','0')) ?>" min="0"></div>
      </div>
    </div>

  </div>
</div>

<!-- Community Board -->
<div class="mod-section">
  <div class="mod-header">
    <input type="checkbox" class="mod-toggle" id="t_forum" name="show_forum" <?= get_setting('show_forum','1')==='1'?'checked':'' ?> onchange="modToggle('forum',this.checked)">
    <label for="t_forum">Community Board</label>
  </div>
  <div class="mod-body<?= get_setting('show_forum','1')!=='1'?' off':'' ?>" id="body_forum">
    <div class="field-label" style="margin-top:12px">Categories <span style="color:#555;font-weight:normal">— Announcements always on · drag to reorder</span></div>
    <div class="sub-row" style="opacity:.5;pointer-events:none">
      <span style="font-size:18px;width:24px;text-align:center">📢</span>
      <span style="flex:1;font-size:13px;color:#ccc">Announcements</span>
      <span style="font-size:11px;color:#555">locked</span>
    </div>
    <div id="cat-list" style="display:flex;flex-direction:column">
    <?php foreach (get_forum_categories() as $cat): ?>
      <div class="cat-row sub-row">
        <input type="hidden" name="cat_key[]" value="<?= esc($cat['key']) ?>">
        <input type="text" name="cat_icon[]" value="<?= esc($cat['icon']) ?>" placeholder="🏷" style="width:42px;padding:4px 6px;text-align:center">
        <input type="text" name="cat_label[]" value="<?= esc($cat['label']) ?>" placeholder="Category name" style="flex:1">
        <button type="button" onclick="removeCat(this)" class="btn-red" style="font-size:11px;padding:4px 10px">Remove</button>
      </div>
    <?php endforeach; ?>
    </div>
    <div style="display:flex;gap:8px;margin-top:8px;align-items:center">
      <input type="text" id="new-cat-icon" placeholder="🏷" style="width:42px;padding:4px 6px;text-align:center">
      <input type="text" id="new-cat-label" placeholder="New category name" style="flex:1">
      <button type="button" onclick="addCat()" class="btn-green" style="white-space:nowrap">+ Add</button>
    </div>
  </div>
</div>

<!-- Simple module toggles -->
<?php
$simple_mods = [
    ['show_chat',     't_chat',     'Chat'],
    ['show_files',    't_files',    'Files'],
    ['show_maps',     't_maps',     'Maps'],
    ['show_calendar', 't_calendar', 'Calendar'],
];
<!-- Library (Kiwix) -->
<div class="mod-section">
  <div class="mod-header" onclick="modToggle('library',document.getElementById('t_library').checked)" style="cursor:default">
    <input type="checkbox" class="mod-toggle" id="t_library" name="show_library" <?= get_setting('show_library','1')==='1'?'checked':'' ?> onchange="modToggle('library',this.checked)">
    <label for="t_library" style="cursor:pointer">Library (Kiwix)</label>
  </div>
  <div class="mod-body<?= get_setting('show_library','1')!=='1'?' off':'' ?>" id="body_library" style="padding:12px 0 4px">
    <div style="font-size:11px;color:#555;margin-bottom:10px">Enable or disable individual content modules. Changes take effect immediately.</div>
    <?php
    $all_zims = get_zim_info();
    if (!$all_zims):
    ?>
      <div style="font-size:12px;color:#555;padding:8px 0">No ZIM files found in <?= ZIM_DIR ?></div>
    <?php else: foreach ($all_zims as $fname => $z):
      $display = zim_display_name($fname, $z['title']);
      $size_mb  = round($z['size'] / 1048576);
      $enabled  = $z['enabled'];
    ?>
      <div style="display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid #1a1a2e">
        <span style="flex:1;font-size:13px;color:<?= $enabled ? '#e0e0e0' : '#555' ?>"><?= esc($display) ?></span>
        <span style="font-size:11px;color:#555;flex-shrink:0"><?= $size_mb ?> MB</span>
        <form method="post" style="flex-shrink:0">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="kiwix_toggle">
          <input type="hidden" name="zim" value="<?= esc($fname) ?>">
          <input type="hidden" name="enable" value="<?= $enabled ? '0' : '1' ?>">
          <button type="submit" style="padding:4px 12px;font-size:11px;border-radius:4px;border:1px solid <?= $enabled ? '#3a2a2a' : '#1a3a1a' ?>;background:none;color:<?= $enabled ? '#e94560' : '#2ecc71' ?>;cursor:pointer">
            <?= $enabled ? 'Disable' : 'Enable' ?>
          </button>
        </form>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<?php foreach ($simple_mods as [$key, $id, $label]):
?>
<div class="mod-section">
  <div class="mod-header">
    <input type="checkbox" class="mod-toggle" id="<?= $id ?>" name="<?= $key ?>" <?= get_setting($key,'1')==='1'?'checked':'' ?>>
    <label for="<?= $id ?>"><?= $label ?></label>
  </div>
</div>
<?php endforeach; ?>

<div style="margin:16px 0">
  <button type="submit" class="btn">Save Settings</button>
</div>

</form>

<!-- Quick Start Presets -->
<details class="quick-start">
  <summary>Quick Start Presets <span style="font-size:11px;margin-left:6px">(overwrites all settings above)</span></summary>
  <?php
  $preset_info = [
      'emergency' => ['Full / Emergency',         'All modules on, emergency status set'],
      'event'     => ['Event',                    'Check-in, no skills or missing persons, event status'],
      'sar'       => ['Search & Rescue',          'Missing persons + found, maps, chat only'],
      'shelter'   => ['Shelter',                  'Shelter check-in, bunk/dietary fields, capacity tracking'],
      'kiosk'     => ['Kiosk / Read-Only',        'Library, maps, forum view — all writes locked'],
      'resource'  => ['Resource Coordination',    'Skills, supplies, full forum, all modules'],
  ];
  ?>
  <div class="qs-grid">
  <?php foreach ($preset_info as $key => [$name, $desc]): ?>
  <div class="qs-card">
    <h4><?= esc($name) ?></h4>
    <p><?= esc($desc) ?></p>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="apply_preset">
      <input type="hidden" name="preset" value="<?= $key ?>">
      <button type="submit" class="btn-sm">Apply</button>
    </form>
  </div>
  <?php endforeach; ?>
  </div>
</details>

<!-- Reset Instance -->
<div class="reset-zone">
  <h3>Reset Instance</h3>
  <p>Permanently deletes all registry entries, chat messages, forum posts, calendar events, uploaded files, and photos. Settings and admin accounts are preserved. Cannot be undone.</p>
  <form method="post" onsubmit="return confirm('This will delete ALL user data. Are you absolutely sure?')">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="reset_instance">
    <div class="reset-row">
      <input type="text" name="reset_confirm" placeholder="Type RESET to confirm" autocomplete="off">
      <button type="submit" class="btn-red" style="white-space:nowrap;padding:8px 16px">Reset Instance</button>
    </div>
  </form>
</div>

</div><!-- #tab-settings -->

</div><!-- .container -->
<?php endif; ?>

<script>
function showTab(name) {
  document.querySelectorAll('.tab-content').forEach(function(el){ el.classList.remove('active'); });
  document.querySelectorAll('.tab').forEach(function(el){ el.classList.remove('active'); });
  document.getElementById('tab-' + name).classList.add('active');
  event.target.classList.add('active');
}

function modToggle(name, on) {
  var body = document.getElementById('body_' + name);
  if (body) { if (on) body.classList.remove('off'); else body.classList.add('off'); }
}

function shelterToggle(on) {
  var body = document.getElementById('body_shelter');
  if (body) { if (on) body.classList.remove('off'); else body.classList.add('off'); }
}

function ea(s) { return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;'); }

function addCat() {
  var icon  = document.getElementById('new-cat-icon').value.trim() || '🏷';
  var label = document.getElementById('new-cat-label').value.trim();
  if (!label) { document.getElementById('new-cat-label').focus(); return; }
  var row = document.createElement('div');
  row.className = 'cat-row sub-row';
  row.style.display = 'flex'; row.style.gap = '10px'; row.style.padding = '8px 0'; row.style.alignItems = 'center';
  row.innerHTML =
    '<input type="hidden" name="cat_key[]" value="">' +
    '<input type="text" name="cat_icon[]" value="' + ea(icon) + '" placeholder="🏷" style="width:42px;padding:4px 6px;text-align:center">' +
    '<input type="text" name="cat_label[]" value="' + ea(label) + '" placeholder="Category name" style="flex:1">' +
    '<button type="button" onclick="removeCat(this)" class="btn-red" style="font-size:11px;padding:4px 10px">Remove</button>';
  document.getElementById('cat-list').appendChild(row);
  document.getElementById('new-cat-icon').value  = '';
  document.getElementById('new-cat-label').value = '';
  document.getElementById('new-cat-label').focus();
}

function removeCat(btn) { btn.closest('.cat-row').remove(); }

document.getElementById('new-cat-label') && document.getElementById('new-cat-label').addEventListener('keydown', function(e){
  if (e.key === 'Enter') { e.preventDefault(); addCat(); }
});

function addField() {
  var label = document.getElementById('new-rf-label').value.trim();
  if (!label) { document.getElementById('new-rf-label').focus(); return; }
  var type = document.getElementById('new-rf-type').value;
  var key  = label.toLowerCase().replace(/[^a-z0-9]+/g,'_').replace(/^_+|_+$/g,'') || 'field_' + Date.now();
  var sel  = '<select name="rf_type[]" style="width:96px;flex-shrink:0">' +
    '<option value="text"'     + (type==='text'?     ' selected':'') + '>Text</option>' +
    '<option value="textarea"' + (type==='textarea'? ' selected':'') + '>Paragraph</option>' +
    '<option value="checkbox"' + (type==='checkbox'? ' selected':'') + '>Checkbox</option>' +
    '</select>';
  var row = document.createElement('div');
  row.className = 'rf-row sub-row';
  row.style.cssText = 'gap:6px;align-items:center';
  row.innerHTML =
    '<input type="checkbox" name="rf_enabled[]" value="' + ea(key) + '" checked>' +
    '<input type="hidden"   name="rf_key[]"     value="' + ea(key) + '">' +
    '<input type="text"     name="rf_label[]"   value="' + ea(label) + '" style="flex:1;min-width:0">' +
    sel +
    '<button type="button" onclick="removeField(this)" class="btn-red" style="font-size:11px;padding:4px 10px;flex-shrink:0">Remove</button>';
  document.getElementById('rf-list').appendChild(row);
  document.getElementById('new-rf-label').value = '';
  document.getElementById('new-rf-label').focus();
}

function removeField(btn) { btn.closest('.rf-row').remove(); }

document.getElementById('new-rf-label') && document.getElementById('new-rf-label').addEventListener('keydown', function(e){
  if (e.key === 'Enter') { e.preventDefault(); addField(); }
});
</script>
</body>
</html>
