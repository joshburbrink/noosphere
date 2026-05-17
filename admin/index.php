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

    $stored_hash = get_setting('admin_password_hash', '');
if ($stored_hash ? password_verify($pw, $stored_hash) : ($pw === ADMIN_PASS)) {
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

    // --- SDR diagnostics (AJAX — returns JSON) ---
    if (in_array($act, ['sdr_diag_usb','sdr_diag_rtltest','sdr_diag_log','sdr_diag_restart','sdr_diag_blacklist'])) {
        header('Content-Type: application/json');
        $arg = '';
        if ($act === 'sdr_diag_log' || $act === 'sdr_diag_restart') {
            $svc = preg_replace('/[^a-z\-]/', '', $_POST['service'] ?? 'noaa-weather');
            $arg = in_array($svc, ['noaa-weather','scanner-waterfall','noosphere-rtl433','noosphere-aprs','noosphere-aprs-writer']) ? $svc : 'noaa-weather';
        }
        $diag_cmds = [
            'sdr_diag_usb'      => 'sudo /usr/local/bin/sdr-diag.sh usb',
            'sdr_diag_rtltest'  => 'sudo /usr/local/bin/sdr-diag.sh rtl_test',
            'sdr_diag_log'      => 'sudo /usr/local/bin/sdr-diag.sh log ' . escapeshellarg($arg),
            'sdr_diag_restart'  => 'sudo /usr/local/bin/sdr-diag.sh restart ' . escapeshellarg($arg),
            'sdr_diag_blacklist'=> 'sudo /usr/local/bin/sdr-diag.sh blacklist',
        ];
        $out = shell_exec($diag_cmds[$act] . ' 2>&1') ?? '(no output)';
        echo json_encode(['ok' => true, 'output' => $out]);
        exit;
    }

    // --- Transcribe now (AJAX) ---
    if ($act === 'transcribe_now') {
        header('Content-Type: application/json');
        $cmd = '/opt/noosphere-whisper/bin/python3 /usr/local/bin/noosphere-weather-transcribe.py manual 2>&1';
        $out = shell_exec($cmd) ?? '(no output)';
        $status = [];
        $sf = '/var/lib/noosphere/weather/last-transcription.json';
        if (file_exists($sf)) $status = json_decode(file_get_contents($sf), true) ?? [];
        echo json_encode(['ok' => true, 'output' => $out, 'status' => $status]);
        exit;
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

    // --- Calendar: add event (admin — auto-approved) ---
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
            created_at INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'approved'
        )");
        try { $cdb->exec("ALTER TABLE events ADD COLUMN status TEXT NOT NULL DEFAULT 'approved'"); } catch (Exception $e) {}
        $cdb->exec("UPDATE events SET status='approved' WHERE status IS NULL OR status=''");
        $title  = trim($_POST['title']  ?? '');
        $date   = trim($_POST['edate']  ?? '');
        $time   = trim($_POST['etime']  ?? '');
        $loc    = trim($_POST['eloc']   ?? '');
        $notes  = trim($_POST['enotes'] ?? '');
        if ($title && $date) {
            $cdb->prepare('INSERT INTO events (title,event_date,event_time,location,notes,created_by,created_at,status) VALUES (?,?,?,?,?,?,?,?)')
                ->execute([$title, $date, $time, $loc, $notes, $_SESSION['admin_name'], time(), 'approved']);
            $msg = 'Event added.';
        }
    }

    // --- Calendar: approve submission ---
    if ($act === 'approve_event') {
        $eid = (int)($_POST['eid'] ?? 0);
        $cdb = new PDO('sqlite:/var/lib/noosphere/calendar.db');
        $cdb->prepare("UPDATE events SET status='approved' WHERE id=?")->execute([$eid]);
        $msg = 'Event approved — now visible on the calendar.';
    }

    // --- Calendar: reject submission ---
    if ($act === 'reject_event') {
        $eid = (int)($_POST['eid'] ?? 0);
        $cdb = new PDO('sqlite:/var/lib/noosphere/calendar.db');
        $cdb->prepare('DELETE FROM events WHERE id=?')->execute([$eid]);
        $msg = 'Submission rejected and removed.';
    }

    // --- External drive: mount ---
    if ($act === 'ext_mount') {
        $dev = $_POST['ext_device'] ?? '';
        if (!preg_match('#^/dev/(sd[b-z][0-9]+|nvme[0-9]+n[0-9]+p[0-9]+|mmcblk[0-9]+p[0-9]+)$#', $dev)) {
            $msg = 'Invalid device.';
        } else {
            $out = shell_exec('sudo /usr/local/bin/external-mount.sh ' . escapeshellarg($dev) . ' 2>&1');
            if (strpos($out, 'Error') !== false) {
                $msg = trim($out);
            } else {
                set_setting('ext_drive_mounted', '1');
                set_setting('ext_drive_device', $dev);
                set_setting('ext_zims_loaded', '[]');
                $msg = 'Drive mounted.';
            }
        }
    }

    // --- External drive: unmount ---
    if ($act === 'ext_umount') {
        // Unregister any loaded external ZIMs from Kiwix library first
        $ext_zims = json_decode(get_setting('ext_zims_loaded','[]'), true) ?: [];
        $xml = @simplexml_load_file('/var/lib/kiwix/library.xml');
        if ($xml && $ext_zims) {
            foreach ($xml->book as $book) {
                $path = (string)$book['path'];
                if (strpos($path, '/media/noosphere-ext/') === 0) {
                    shell_exec('kiwix-manage /var/lib/kiwix/library.xml remove ' . escapeshellarg((string)$book['id']) . ' 2>&1');
                }
            }
            shell_exec('chown www-data:www-data /var/lib/kiwix/library.xml');
            shell_exec('systemctl restart kiwix 2>&1');
        }
        shell_exec('sudo /usr/local/bin/external-umount.sh 2>&1');
        set_setting('ext_drive_mounted', '0');
        set_setting('ext_drive_device', '');
        set_setting('ext_zims_loaded', '[]');
        $msg = 'Drive unmounted.';
    }

    // --- External drive: load ZIM into Kiwix ---
    if ($act === 'ext_load_zim') {
        $zim = $_POST['ext_zim'] ?? '';
        if (!preg_match('/^[a-zA-Z0-9._\-]+\.zim$/', $zim)) {
            $msg = 'Invalid ZIM filename.';
        } else {
            $out = shell_exec('sudo /usr/local/bin/external-load-zim.sh ' . escapeshellarg($zim) . ' 2>&1');
            if (strpos($out ?? '', 'Error') !== false) {
                $msg = trim($out);
            } else {
                $loaded = json_decode(get_setting('ext_zims_loaded','[]'), true) ?: [];
                if (!in_array($zim, $loaded)) $loaded[] = $zim;
                set_setting('ext_zims_loaded', json_encode($loaded));
                $msg = 'ZIM loaded: ' . esc($zim);
            }
        }
    }

    // --- External drive: unload ZIM from Kiwix ---
    if ($act === 'ext_unload_zim') {
        $zim = $_POST['ext_zim'] ?? '';
        if (preg_match('/^[a-zA-Z0-9._\-]+\.zim$/', $zim)) {
            $zim_path = '/media/noosphere-ext/kiwix/' . $zim;
            $xml = @simplexml_load_file('/var/lib/kiwix/library.xml');
            if ($xml) {
                foreach ($xml->book as $book) {
                    if ((string)$book['path'] === $zim_path) {
                        shell_exec('kiwix-manage /var/lib/kiwix/library.xml remove ' . escapeshellarg((string)$book['id']) . ' 2>&1');
                        shell_exec('chown www-data:www-data /var/lib/kiwix/library.xml');
                        shell_exec('systemctl restart kiwix 2>&1');
                        break;
                    }
                }
            }
            $loaded = json_decode(get_setting('ext_zims_loaded','[]'), true) ?: [];
            set_setting('ext_zims_loaded', json_encode(array_values(array_diff($loaded, [$zim]))));
            $msg = 'ZIM unloaded.';
        }
    }

    // --- Clear analytics ---
    if ($act === 'clear_stats') {
        try {
            $adb = new PDO('sqlite:/var/lib/noosphere/analytics.db');
            $adb->exec('DELETE FROM sessions; DELETE FROM hits;');
            $msg = 'Statistics cleared.';
        } catch (Exception $e) { $msg = 'No stats data yet.'; }
    }

    // --- Change Linux system credentials ---
    if ($act === 'change_linux_pw') {
        $target   = $_POST['linux_target'] ?? '';  // 'user' or 'root'
        $new_pw   = $_POST['linux_new_pw']   ?? '';
        $con_pw   = $_POST['linux_con_pw']   ?? '';
        $linux_user = trim(shell_exec("awk -F: '\$3==1000{print \$1}' /etc/passwd | head -1") ?: 'cogitator');
        $change_user = ($target === 'root') ? 'root' : $linux_user;
        if (strlen($new_pw) < 6) {
            $msg = 'Password must be at least 6 characters.';
        } elseif ($new_pw !== $con_pw) {
            $msg = 'Passwords do not match.';
        } else {
            $proc = proc_open(
                'sudo /usr/local/bin/noosphere-chpasswd.sh ' . escapeshellarg($change_user),
                [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']],
                $pipes
            );
            fwrite($pipes[0], $new_pw . "\n");
            fclose($pipes[0]);
            proc_close($proc);
            $msg = 'Linux ' . esc($change_user) . ' password updated.';
        }
    }

    // --- Change admin password ---
    if ($act === 'change_password') {
        $cur = $_POST['cur_pw'] ?? '';
        $new = $_POST['new_pw'] ?? '';
        $con = $_POST['con_pw'] ?? '';
        $stored_hash = get_setting('admin_password_hash', '');
        $cur_ok = $stored_hash ? password_verify($cur, $stored_hash) : ($cur === ADMIN_PASS);
        if (!$cur_ok) {
            $msg = 'Current password is incorrect.';
        } elseif (strlen($new) < 6) {
            $msg = 'New password must be at least 6 characters.';
        } elseif ($new !== $con) {
            $msg = 'New passwords do not match.';
        } else {
            set_setting('admin_password_hash', password_hash($new, PASSWORD_DEFAULT));
            $msg = 'Password changed.';
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
                      'registry_label','registry_description','registry_statuses','shelter_name','shelter_capacity',
                      'tasks_categories','tasks_auto_close_hours',
                      'radio_freq','radio_gain','radio_ppm','radio_same_fips'];
        foreach ($text_keys as $k) {
            if (isset($_POST[$k])) set_setting($k, trim($_POST[$k]));
        }
        $toggle_keys = ['transcription_enabled','transcription_nwr_auto','transcription_nwr_hybrid','transcription_talk_post',
                        'show_registry','registry_checkin','registry_found_person','registry_location_required','registry_shelter',
                        'show_tasks','tasks_show_rewards','tasks_require_login','tasks_allow_self_create',
                        'show_chat','show_forum','show_files','show_library','show_maps','show_topo','show_calendar',
                        'show_weather','show_radio','show_runners','show_damage','show_triage','readonly'];
        foreach ($toggle_keys as $k) {
            set_setting($k, isset($_POST[$k]) ? '1' : '0');
        }
        // --- Sync transcription timer ---
        $t_auto = get_setting('transcription_enabled','0') === '1' && get_setting('transcription_nwr_auto','0') === '1';
        shell_exec($t_auto
            ? 'systemctl enable --now noosphere-weather-transcribe.timer 2>&1'
            : 'systemctl disable --now noosphere-weather-transcribe.timer 2>&1');

        // --- SDR radio mode (off|nwr|scanner) — invokes helper if changed ---
        if (isset($_POST['radio_mode'])) {
            $valid_modes = ['off','nwr','scanner','rtl433','aprs'];
            $new_mode = in_array($_POST['radio_mode'], $valid_modes, true) ? $_POST['radio_mode'] : 'off';
            $old_mode = get_setting('radio_mode', 'off');
            $freq = trim($_POST['radio_freq'] ?? '162.550M') ?: '162.550M';
            $gain = trim($_POST['radio_gain'] ?? '49.6') ?: '49.6';
            $ppm  = (string)(int)($_POST['radio_ppm'] ?? 0);
            $fips = preg_replace('/[^0-9,]/', '', $_POST['radio_same_fips'] ?? '018005,018013');
            set_setting('radio_mode', $new_mode);
            set_setting('radio_freq', $freq);
            set_setting('radio_gain', $gain);
            set_setting('radio_ppm', $ppm);
            set_setting('radio_same_fips', $fips);
            // rtl_433 sub-settings
            $rtl433_sid   = preg_replace('/[^0-9a-zA-Z_\-]/', '', $_POST['rtl433_sensor_id'] ?? '');
            $rtl433_model = substr(preg_replace('/[^0-9a-zA-Z_ \-\.]/', '', $_POST['rtl433_sensor_model'] ?? ''), 0, 60);
            set_setting('rtl433_sensor_id',    $rtl433_sid);
            set_setting('rtl433_sensor_model', $rtl433_model);
            // APRS sub-settings
            $aprs_freq_raw = trim($_POST['aprs_freq'] ?? '144.3900');
            $aprs_freq = preg_match('/^\d+\.?\d*$/', $aprs_freq_raw) ? $aprs_freq_raw : '144.3900';
            $aprs_expiry = (string)max(1, min(48, (int)($_POST['aprs_expiry_hours'] ?? 2)));
            set_setting('aprs_freq',         $aprs_freq);
            set_setting('aprs_expiry_hours', $aprs_expiry);
            // Write rtl433 conf so bridge picks it up on next read
            @file_put_contents('/etc/noosphere/rtl433.conf',
                "sensor_id=$rtl433_sid\nsensor_model=$rtl433_model\n");
            // Write aprs conf so radio-mode.sh picks it up
            @file_put_contents('/etc/noosphere/aprs.conf',
                "APRS_FREQ=$aprs_freq\n");
            // Apply unless mode is off AND nothing changed
            $cmd = sprintf('sudo /usr/local/bin/noosphere-radio-mode.sh %s %s %s %s %s 2>&1',
                escapeshellarg($new_mode),
                escapeshellarg($freq),
                escapeshellarg($gain),
                escapeshellarg($ppm),
                escapeshellarg($fips ?: ''));
            $out = shell_exec($cmd);
            if ($out !== null) $msg = ($msg ? $msg . ' · ' : '') . 'SDR: ' . trim($out);
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

    // --- Post announcement to forum ---
    if ($act === 'post_announcement') {
        $ann_title = trim($_POST['ann_title'] ?? '');
        $ann_body  = trim($_POST['ann_body']  ?? '');
        if ($ann_title && $ann_body) {
            try {
                $fdb    = new PDO('sqlite:' . FORUM_DB);
                $author = $_SESSION['admin_name'] ?? 'Admin';
                $now    = time();
                $fdb->prepare('INSERT INTO threads (category,title,author,pinned,created_at,last_at,reply_count) VALUES(?,?,?,1,?,?,0)')
                    ->execute(['announcements', $ann_title, $author, $now, $now]);
                $tid = $fdb->lastInsertId();
                $fdb->prepare('INSERT INTO posts (thread_id,author,body,created_at) VALUES(?,?,?,?)')
                    ->execute([$tid, $author, $ann_body, $now]);
                $fdb->prepare('UPDATE threads SET reply_count=1 WHERE id=?')->execute([$tid]);
                $msg = 'Announcement posted.';
            } catch (Exception $e) { $msg = 'Forum database error.'; }
        }
    }

    // --- Broadcast message to chat ---
    if ($act === 'broadcast_chat') {
        $bcast = trim($_POST['broadcast_body'] ?? '');
        if ($bcast && mb_strlen($bcast) <= 1000) {
            try {
                $cdb    = new PDO('sqlite:/var/lib/noosphere/chat.db');
                $author = '📢 ' . ($_SESSION['admin_name'] ?? 'Admin');
                $cdb->exec("CREATE TABLE IF NOT EXISTS messages (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, body TEXT NOT NULL, reg_status TEXT, reg_location TEXT, created_at INTEGER NOT NULL)");
                $cdb->prepare('INSERT INTO messages (name,body,created_at) VALUES(?,?,?)')
                    ->execute([$author, $bcast, time()]);
                $msg = 'Message broadcast to chat.';
            } catch (Exception $e) { $msg = 'Chat database error.'; }
        }
    }

    // --- Delete registry photo ---
    if ($act === 'del_photo') {
        $fname = basename($_POST['fname'] ?? '');
        $path  = realpath(PHOTOS_DIR . $fname);
        if ($path && strpos($path, realpath(PHOTOS_DIR)) === 0 && file_exists($path)) {
            unlink($path); $msg = 'Photo deleted.';
        }
    }

    // --- Delete map marker ---
    if ($act === 'del_marker') {
        $mid = (int)($_POST['marker_id'] ?? 0);
        if ($mid) {
            try {
                $mdb = new PDO('sqlite:/var/lib/noosphere/markers.db');
                $mdb->prepare('DELETE FROM markers WHERE id=?')->execute([$mid]);
                $msg = 'Marker deleted.';
            } catch (Exception $e) {}
        }
    }

    // --- Download DB backup ---
    if ($act === 'backup_db') {
        $zip_file = sys_get_temp_dir() . '/noosphere_backup_' . date('Ymd_His') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($zip_file, ZipArchive::CREATE) === true) {
            foreach (['/var/lib/noosphere/registry.db','/var/lib/noosphere/settings.db',
                      '/var/lib/noosphere/forum.db','/var/lib/noosphere/calendar.db',
                      '/var/lib/noosphere/analytics.db'] as $db_path) {
                if (file_exists($db_path)) $zip->addFile($db_path, basename($db_path));
            }
            $zip->close();
            header('Content-Type: application/zip');
            header('Content-Disposition: attachment; filename="noosphere_backup_' . date('Ymd_His') . '.zip"');
            header('Content-Length: ' . filesize($zip_file));
            readfile($zip_file);
            @unlink($zip_file);
            exit;
        }
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

function get_connected_devices() {
    $arp = shell_exec('arp -n 2>/dev/null') ?: '';
    $devices = [];
    foreach (explode("\n", $arp) as $line) {
        if (preg_match('/^(\d+\.\d+\.\d+\.\d+)\s+\S+\s+([0-9a-f:]{17})\s+\S+\s+(\S+)/i', $line, $m)) {
            $mac = strtoupper($m[2]);
            if ($mac === '00:00:00:00:00:00') continue;
            $devices[$mac] = ['ip'=>$m[1],'mac'=>$mac,'iface'=>$m[3],'hostname'=>''];
        }
    }
    foreach (['/var/lib/misc/dnsmasq.leases','/var/lib/dnsmasq/dnsmasq.leases','/tmp/dnsmasq.leases'] as $lf) {
        if (!file_exists($lf)) continue;
        foreach (file($lf) as $line) {
            $p = preg_split('/\s+/', trim($line));
            if (count($p) >= 4) {
                $mac  = strtoupper($p[1]);
                $host = ($p[3] !== '*') ? $p[3] : '';
                if (isset($devices[$mac]))  $devices[$mac]['hostname'] = $host;
                elseif ($p[2] && $p[2] !== '0.0.0.0') $devices[$mac] = ['ip'=>$p[2],'mac'=>$mac,'iface'=>'','hostname'=>$host];
            }
        }
        break;
    }
    return array_values($devices);
}

function get_iface_stats() {
    $stats = [];
    if (!file_exists('/proc/net/dev')) return $stats;
    foreach (array_slice(file('/proc/net/dev'), 2) as $line) {
        $p = preg_split('/\s+/', trim($line));
        $iface = rtrim($p[0], ':');
        if ($iface === 'lo') continue;
        $stats[$iface] = ['rx'=>(int)$p[1], 'tx'=>(int)$p[9]];
    }
    return $stats;
}

function fmt_bytes($b) {
    if ($b < 1024) return $b . ' B';
    if ($b < 1048576) return round($b/1024,1) . ' KB';
    if ($b < 1073741824) return round($b/1048576,1) . ' MB';
    return round($b/1073741824,1) . ' GB';
}

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
.sub-tab-bar { display:flex; gap:2px; margin-bottom:18px; border-bottom:1px solid #2a2a4a; }
.sub-tab { padding:6px 14px; font-size:12px; cursor:pointer; color:#666; border-radius:6px 6px 0 0; border:1px solid transparent; border-bottom:none; margin-bottom:-1px; }
.sub-tab.active { color:#e0e0e0; background:#1a1a2e; border-color:#2a2a4a; }
.sub-tab:hover:not(.active) { color:#aaa; }
.sub-tab-content { display:none; }
.sub-tab-content.active { display:block; }
/* Collapsible panels */
details.cpanel { background:#1a1a2e; border:1px solid #2a2a4a; border-radius:8px; margin-bottom:10px; overflow:hidden; }
details.cpanel > summary { padding:12px 16px; cursor:pointer; font-size:13px; font-weight:bold; color:#ccc; list-style:none; display:flex; align-items:center; justify-content:space-between; user-select:none; }
details.cpanel > summary::-webkit-details-marker { display:none; }
details.cpanel > summary::after { content:'▾'; font-size:11px; color:#555; transition:.15s; }
details.cpanel[open] > summary::after { transform:rotate(180deg); }
details.cpanel > summary .badge { font-size:10px; color:#888; font-weight:normal; background:#111126; padding:2px 8px; border-radius:10px; margin-left:6px; }
details.cpanel > .cpbody { padding:4px 16px 16px; border-top:1px solid #1e1e38; }
/* Device / process tables */
.dtable { width:100%; border-collapse:collapse; font-size:12px; }
.dtable th { color:#555; font-weight:normal; text-align:left; padding:6px 8px; border-bottom:1px solid #2a2a4a; white-space:nowrap; }
.dtable td { padding:6px 8px; border-bottom:1px solid #111126; vertical-align:middle; }
.dtable tr:last-child td { border-bottom:none; }
/* Log / code output */
.logbox { background:#0a0a14; border:1px solid #1e1e38; border-radius:6px; padding:10px 12px; font-family:monospace; font-size:11px; color:#777; max-height:220px; overflow-y:auto; white-space:pre-wrap; word-break:break-all; }
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
    <a href="/admin/wiki.php" style="color:#888;text-decoration:none;font-size:13px" onmouseover="this.style.color='#e94560'" onmouseout="this.style.color='#888'">📖 Docs</a>
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
  <div class="tab active" onclick="showTab('dashboard')">Dashboard</div>
  <div class="tab" onclick="showTab('network')">Network</div>
  <div class="tab" onclick="showTab('community')">Community</div>
  <div class="tab" onclick="showTab('content')">Content</div>
  <div class="tab" onclick="showTab('system')">System</div>
  <div class="tab" onclick="showTab('settings')">Settings</div>
</div>

<!-- DASHBOARD -->
<div id="tab-dashboard" class="tab-content active">

<details class="cpanel" open>
  <summary>Services</summary>
  <div class="cpbody">
    <div class="svc-grid">
    <?php foreach (['nginx','php8.4-fpm','kiwix','mbtileserver'] as $svc):
      $st = svc_status($svc); ?>
      <div class="svc-card">
        <div class="svc-name"><?= $svc ?></div>
        <div class="svc-state <?= $st==='active'?'active-state':'inactive-state' ?>"><?= $st ?></div>
      </div>
    <?php endforeach; ?>
    </div>
  </div>
</details>

<details class="cpanel" open>
  <summary>System Resources</summary>
  <div class="cpbody">
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
  </div>
</details>

<?php
  $ext_mounted = get_setting('ext_drive_mounted','0') === '1';
  if ($ext_mounted && !is_dir('/media/noosphere-ext')) { set_setting('ext_drive_mounted','0'); $ext_mounted = false; }
  $ext_device      = get_setting('ext_drive_device','');
  $ext_zims_loaded = json_decode(get_setting('ext_zims_loaded','[]'), true) ?: [];
  $sys_disk = trim(shell_exec("findmnt -n -o SOURCE / 2>/dev/null | xargs lsblk -no pkname 2>/dev/null") ?: '');
  $lsblk_data = json_decode(shell_exec('lsblk -J -o NAME,SIZE,TYPE,MOUNTPOINT,FSTYPE,LABEL 2>/dev/null') ?: '{}', true);
  $avail_drives = [];
  foreach ($lsblk_data['blockdevices'] ?? [] as $disk) {
      if ($disk['name'] === $sys_disk) continue;
      foreach ($disk['children'] ?? [] as $part) {
          if ($part['mountpoint'] ?? null) continue;
          if (!($part['fstype'] ?? '')) continue;
          $avail_drives[] = ['device'=>'/dev/'.$part['name'],'size'=>$part['size'],'fstype'=>$part['fstype'],'label'=>$part['label']??''];
      }
  }
  $ext_files = $ext_zims = [];
  if ($ext_mounted) {
      $ext_files = array_map('basename', glob('/media/noosphere-ext/files/*') ?: []);
      $ext_zims  = array_map('basename', glob('/media/noosphere-ext/kiwix/*.zim') ?: []);
  }
  ?>

<details class="cpanel" open>
  <summary>External Drive</summary>
  <div class="cpbody">
    <?php if ($ext_mounted): ?>
      <div style="background:#1a3a1a;border:1px solid #2ecc71;border-radius:6px;padding:10px 14px;margin-bottom:12px;font-size:13px">
        <span style="color:#2ecc71;font-weight:bold">&#x2714; Mounted</span>
        <span style="color:#888;margin-left:10px"><?= esc($ext_device) ?></span>
      </div>
      <div class="svc-grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr));margin-bottom:14px">
        <div class="svc-card"><div class="svc-name">Files available</div><div class="svc-state" style="color:#e0e0e0"><?= count($ext_files) ?></div></div>
        <div class="svc-card"><div class="svc-name">ZIMs on drive</div><div class="svc-state" style="color:#e0e0e0"><?= count($ext_zims) ?></div></div>
        <div class="svc-card"><div class="svc-name">ZIMs loaded</div><div class="svc-state" style="color:#2ecc71"><?= count($ext_zims_loaded) ?></div></div>
      </div>
      <?php if ($ext_zims): ?>
        <div style="font-size:12px;color:#888;margin-bottom:8px">ZIM files found in <code>kiwix/</code> on drive</div>
        <div style="display:flex;flex-direction:column;margin-bottom:14px">
        <?php foreach ($ext_zims as $zim):
          $zloaded = in_array($zim, $ext_zims_loaded);
          $zmb     = file_exists('/media/noosphere-ext/kiwix/'.$zim) ? round(filesize('/media/noosphere-ext/kiwix/'.$zim)/1048576) : 0;
        ?>
          <div style="display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid #111126;font-size:13px">
            <span style="flex:1;color:<?= $zloaded?'#e0e0e0':'#555' ?>"><?= esc($zim) ?></span>
            <span style="color:#555;font-size:11px"><?= $zmb ?> MB</span>
            <?php if ($zloaded): ?>
              <span style="font-size:11px;color:#2ecc71">&#x2714; In Kiwix</span>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="ext_unload_zim"><input type="hidden" name="ext_zim" value="<?= esc($zim) ?>">
                <button type="submit" style="padding:3px 10px;font-size:11px;border:1px solid #3a2a2a;background:none;color:#e94560;border-radius:4px;cursor:pointer">Unload</button>
              </form>
            <?php else: ?>
              <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="ext_load_zim"><input type="hidden" name="ext_zim" value="<?= esc($zim) ?>">
                <button type="submit" style="padding:3px 10px;font-size:11px;border:1px solid #1a3a1a;background:none;color:#2ecc71;border-radius:4px;cursor:pointer">Load into Kiwix</button>
              </form>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div style="font-size:12px;color:#555;margin-bottom:12px">No ZIM files found in <code>kiwix/</code> on drive.</div>
      <?php endif; ?>
      <?php if ($ext_files): ?>
        <div style="font-size:12px;color:#888;margin-bottom:12px"><?= count($ext_files) ?> file(s) available in the <a href="/files/" style="color:#4a9eff">Files</a> section from <code>files/</code> on drive.</div>
      <?php else: ?>
        <div style="font-size:12px;color:#555;margin-bottom:12px">No files found in <code>files/</code> on drive.</div>
      <?php endif; ?>
      <form method="post" onsubmit="return confirm('Unmount? Loaded Kiwix ZIMs will be unregistered.')">
        <?= csrf_field() ?><input type="hidden" name="act" value="ext_umount">
        <button type="submit" class="btn-red" style="font-size:12px;padding:7px 18px">Unmount Drive</button>
      </form>
    <?php else: ?>
      <?php if (!$avail_drives): ?>
        <div style="font-size:13px;color:#555;padding:8px 0">No external partitions detected. Plug in a drive and refresh.</div>
      <?php else: ?>
        <form method="post"><?= csrf_field() ?><input type="hidden" name="act" value="ext_mount">
          <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap">
            <select name="ext_device" style="flex:1;min-width:220px">
              <?php foreach ($avail_drives as $d): ?>
              <option value="<?= esc($d['device']) ?>"><?= esc($d['device']) ?> — <?= esc($d['size']) ?> (<?= esc($d['fstype']) ?><?= $d['label'] ? ', '.esc($d['label']) : '' ?>)</option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-green" style="padding:8px 18px;font-size:13px;white-space:nowrap">Mount (read-only)</button>
          </div>
          <div style="font-size:11px;color:#555;margin-top:8px">Prepare the drive with a <code>files/</code> folder for shared documents and a <code>kiwix/</code> folder for ZIM libraries.</div>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</details>

<!-- Pending calendar submissions count for dashboard badge -->
<?php
$dash_pending_events = 0;
try {
    $cdb_dash = new PDO('sqlite:/var/lib/noosphere/calendar.db');
    $dash_pending_events = (int)$cdb_dash->query("SELECT COUNT(*) FROM events WHERE status='pending'")->fetchColumn();
} catch (Exception $e) {}
?>
<?php if ($dash_pending_events > 0): ?>
<details class="cpanel" open>
  <summary>Pending Event Submissions <span class="badge" style="background:#f39c12;color:#000"><?= $dash_pending_events ?></span></summary>
  <div class="cpbody">
    <p style="font-size:13px;color:#c8a040;margin-bottom:10px"><?= $dash_pending_events ?> event suggestion<?= $dash_pending_events !== 1 ? 's' : '' ?> waiting for review.</p>
    <a href="#" onclick="showTab('community');document.querySelector('[onclick*=community]').click();return false" class="btn" style="font-size:12px;padding:7px 16px;text-decoration:none">Review in Community tab →</a>
  </div>
</details>
<?php endif; ?>

<!-- STATS -->
<?php
require_once '/var/www/noosphere/shared/analytics.php';
$st = analytics_stats();
function fmt_dur($s) {
    if ($s < 60) return $s . 's';
    if ($s < 3600) return floor($s/60) . 'm ' . ($s%60) . 's';
    return floor($s/3600) . 'h ' . floor(($s%3600)/60) . 'm';
}
$mod_labels = ['home'=>'Home','registry'=>'Registry','forum'=>'Forum','chat'=>'Chat',
               'files'=>'Files','maps'=>'Maps','library'=>'Library','calendar'=>'Calendar'];
?>
<?php if (!$st): ?>
  <details class="cpanel" open><summary>Analytics</summary><div class="cpbody"><p style="color:#666;font-size:13px;padding:4px 0">No analytics data yet — visitors will be tracked automatically as they use the hub.</p></div></details>
<?php else:
  $unreg = $st['total'] - $st['registered'];
  $reg_rate = $st['total'] ? round($st['registered'] / $st['total'] * 100) : 0;
?>
<details class="cpanel" open>
  <summary>Analytics Overview</summary>
  <div class="cpbody">
  <div class="svc-grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr));margin-bottom:16px">
    <?php foreach ([
      ['Unique visitors',        $st['total'],      '#e0e0e0'],
      ['Registered',             $st['registered'], '#2ecc71'],
      ['Browsed, didn\'t register', $unreg,         '#f39c12'],
      ['Active today',           $st['today'],      '#4a9eff'],
      ['Registration rate',      $reg_rate . '%',   $reg_rate >= 50 ? '#2ecc71' : '#f39c12'],
      ['Avg time on site',       $st['avg_duration'] ? fmt_dur($st['avg_duration']) : '—', '#e0e0e0'],
      ['Total page hits',        $st['total_hits'],  '#e0e0e0'],
    ] as [$label, $val, $col]): ?>
    <div class="svc-card">
      <div class="svc-name"><?= $label ?></div>
      <div class="svc-state" style="color:<?= $col ?>;font-size:16px;font-weight:bold"><?= $val ?></div>
    </div>
    <?php endforeach; ?>
  </div>
</div>
</details>

<details class="cpanel" open>
  <summary>Module Popularity</summary>
  <div class="cpbody">
  <div style="display:flex;flex-direction:column;gap:6px;margin-top:4px">
  <?php foreach ($st['modules'] as $mod => $cnt):
    $pct = $st['max_hits'] ? round($cnt / $st['max_hits'] * 100) : 0;
    $lbl = $mod_labels[$mod] ?? ucfirst($mod);
  ?>
    <div style="display:flex;align-items:center;gap:10px;font-size:13px">
      <div style="width:80px;color:#888;text-align:right;flex-shrink:0"><?= $lbl ?></div>
      <div style="flex:1;background:#111126;border-radius:3px;height:18px;overflow:hidden">
        <div style="width:<?= $pct ?>%;background:#e94560;height:100%;border-radius:3px;transition:.3s"></div>
      </div>
      <div style="width:40px;color:#aaa;font-size:12px;flex-shrink:0"><?= $cnt ?></div>
    </div>
  <?php endforeach; ?>
  <?php if (!$st['modules']): ?><div style="color:#555;font-size:13px">No hits recorded yet.</div><?php endif; ?>
  </div>
  </div>
</details>

<details class="cpanel">
  <summary>Peak Activity Hours <span class="badge">last 7 days</span></summary>
  <div class="cpbody">
  <div style="display:flex;align-items:flex-end;gap:2px;height:60px;margin-top:6px">
  <?php for ($h = 0; $h < 24; $h++):
    $cnt = $st['hours'][$h];
    $pct = $st['max_hour'] ? round($cnt / $st['max_hour'] * 100) : 0;
    $col = $cnt === max($st['hours']) && $cnt > 0 ? '#e94560' : '#2a2a4a';
  ?>
    <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:2px">
      <div style="flex:1;width:100%;display:flex;align-items:flex-end">
        <div style="width:100%;height:<?= $pct ?>%;background:<?= $col ?>;min-height:<?= $cnt>0?'2':0 ?>px;border-radius:2px 2px 0 0"></div>
      </div>
      <?php if ($h % 6 === 0): ?><div style="font-size:9px;color:#555"><?= $h ?>h</div><?php else: ?><div style="font-size:9px;color:transparent">·</div><?php endif; ?>
    </div>
  <?php endfor; ?>
  </div>
  </div>
</details>

<details class="cpanel">
  <summary>Visitors — Last 7 Days</summary>
  <div class="cpbody">
  <div style="display:flex;align-items:flex-end;gap:4px;height:70px;margin-top:6px">
  <?php foreach ($st['days'] as $date => $cnt):
    $pct = $st['max_day'] ? round($cnt / $st['max_day'] * 100) : 0;
    $dow = date('D', strtotime($date));
  ?>
    <div style="flex:1;display:flex;flex-direction:column;align-items:center;gap:4px">
      <div style="width:100%;height:50px;display:flex;align-items:flex-end">
        <div style="width:100%;height:<?= max($pct,0) ?>%;background:#4a9eff;min-height:<?= $cnt>0?'3':0 ?>px;border-radius:3px 3px 0 0"></div>
      </div>
      <div style="font-size:10px;color:#555"><?= $dow ?></div>
      <div style="font-size:10px;color:#888"><?= $cnt ?: '' ?></div>
    </div>
  <?php endforeach; ?>
  </div>
  </div>
</details>

<details class="cpanel">
  <summary>Clear Analytics Data</summary>
  <div class="cpbody">
  <form method="post" onsubmit="return confirm('Clear all stats? This cannot be undone.')">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="clear_stats">
    <button type="submit" class="btn-red" style="font-size:12px;padding:6px 16px">Clear All Stats</button>
    <span style="font-size:11px;color:#555;margin-left:8px">Useful when switching between deployments or events.</span>
  </form>
  </div>
</details>

<?php endif; ?>
</div>

<!-- COMMUNITY -->
<div id="tab-community" class="tab-content">

<details class="cpanel" open>
  <summary>Post Announcement</summary>
  <div class="cpbody">
    <div style="font-size:11px;color:#555;margin-bottom:10px">Posts a pinned thread in the Announcements category on the Community Board.</div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="post_announcement">
      <div style="margin-bottom:8px"><label class="field-label">Title</label><input type="text" name="ann_title" placeholder="Important update, supply distribution schedule…" required></div>
      <div style="margin-bottom:10px"><label class="field-label">Body</label><textarea name="ann_body" rows="3" placeholder="Announcement text…" required></textarea></div>
      <button type="submit" class="btn">Post Announcement</button>
    </form>
  </div>
</details>

<details class="cpanel">
  <summary>Broadcast to Chat</summary>
  <div class="cpbody">
    <div style="font-size:11px;color:#555;margin-bottom:10px">Inserts a system message into the live chat visible to all users immediately.</div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="broadcast_chat">
      <div style="margin-bottom:10px"><label class="field-label">Message</label><textarea name="broadcast_body" rows="2" placeholder="Message for all chat users…" required></textarea></div>
      <button type="submit" class="btn">Broadcast</button>
    </form>
  </div>
</details>

<details class="cpanel" open>
  <summary>Registry Users</summary>
  <div class="cpbody">
    <?php
    $rdb   = new SQLite3(REGISTRY_DB);
    $users = $rdb->query("SELECT id,name,location,status,is_admin FROM registry WHERE entry_type='checkin' OR entry_type IS NULL OR entry_type='' ORDER BY name ASC");
    $user_rows = [];
    while ($u = $users->fetchArray(SQLITE3_ASSOC)) $user_rows[] = $u;
    ?>
    <?php if (!$user_rows): ?><div style="color:#555;font-size:13px">No registered users yet.</div>
    <?php else: ?>
    <table>
      <tr><th>Name</th><th>Location</th><th>Status</th><th>Role</th><th>Actions</th></tr>
      <?php foreach ($user_rows as $u): ?>
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
      <?php endforeach; ?>
    </table>
    <div style="font-size:11px;color:#555;margin-top:6px"><?= count($user_rows) ?> registered user(s)</div>
    <?php endif; ?>
  </div>
</details>

<details class="cpanel" open>
  <summary>Bans</summary>
  <div class="cpbody">
    <div style="font-size:11px;color:#888;margin-bottom:12px">Add a ban by name, IP, or both. You can also ban by IP directly from the Network tab.</div>
    <form method="post" style="margin-bottom:16px">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="ban">
      <div class="form-row">
        <div><label>Registry Name <span style="color:#555">(optional)</span></label><input type="text" name="ban_name" placeholder="Leave blank to ban by IP only"></div>
        <div><label>IP Address <span style="color:#555">(optional)</span></label><input type="text" name="ban_ip" placeholder="e.g. 192.168.8.42"></div>
      </div>
      <div style="margin-bottom:8px"><label>Reason <span style="color:#555">(internal, admin-only)</span></label><input type="text" name="ban_reason" placeholder="Reason"></div>
      <button type="submit" class="btn-red">Add Ban</button>
    </form>
    <?php $bans = get_bans(); ?>
    <?php if (!$bans): ?><p style="color:#666;font-size:13px">No active bans.</p>
    <?php else: ?>
    <table>
      <tr><th>Name</th><th>IP</th><th>Reason</th><th>Banned by</th><th></th></tr>
      <?php foreach ($bans as $b): ?>
      <tr>
        <td><?= esc($b['name'] ?? '—') ?></td>
        <td style="font-family:monospace;font-size:12px"><?= esc($b['ip'] ?? '—') ?></td>
        <td><?= esc($b['reason']) ?></td>
        <td style="color:#555"><?= esc($b['banned_by']) ?></td>
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
  </div>
</details>

<details class="cpanel" open>
  <summary>Forum Moderation</summary>
  <div class="cpbody">
    <?php
    try {
        $fdb     = new PDO('sqlite:' . FORUM_DB);
        $threads = $fdb->query('SELECT * FROM threads ORDER BY last_at DESC LIMIT 40')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) { $threads = []; }
    ?>
    <?php if (!$threads): ?><div style="color:#555;font-size:13px">No forum threads yet.</div>
    <?php else: ?>
    <table>
      <tr><th>Title</th><th>Category</th><th>Author</th><th>Replies</th><th></th></tr>
      <?php foreach ($threads as $t): ?>
      <tr>
        <td><a href="/forum/?view=thread&thread=<?= $t['id'] ?>" style="color:#4a9eff;text-decoration:none"><?= esc($t['title']) ?></a><?= $t['pinned'] ? ' <span style="font-size:10px;color:#e94560">📌</span>' : '' ?></td>
        <td style="color:#555"><?= esc($t['category']) ?></td>
        <td><?= esc($t['author']) ?></td>
        <td style="color:#555;text-align:center"><?= (int)($t['reply_count'] ?? 0) ?></td>
        <td>
          <form method="post" style="margin:0" onsubmit="return confirm('Delete this thread and all replies?')">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="del_thread">
            <input type="hidden" name="tid" value="<?= $t['id'] ?>">
            <button type="submit" class="btn-red" style="font-size:11px">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</details>

<details class="cpanel">
  <summary>Found Persons</summary>
  <div class="cpbody">
    <?php
    $rdb2  = new SQLite3(REGISTRY_DB);
    $found = $rdb2->query("SELECT id,name,location,updated_at FROM registry WHERE entry_type='found_person' ORDER BY updated_at DESC");
    $found_rows = [];
    while ($f = $found->fetchArray(SQLITE3_ASSOC)) $found_rows[] = $f;
    ?>
    <?php if (!$found_rows): ?><div style="color:#555;font-size:13px">No found person entries.</div>
    <?php else: ?>
    <table>
      <tr><th>Name</th><th>Location</th><th>Reported</th><th></th></tr>
      <?php foreach ($found_rows as $f): ?>
      <tr>
        <td><?= esc($f['name']) ?></td>
        <td><?= esc($f['location']) ?></td>
        <td style="color:#555;font-size:11px"><?= $f['updated_at'] ? date('M j H:i', $f['updated_at']) : '—' ?></td>
        <td>
          <form method="post" style="margin:0" onsubmit="return confirm('Delete this entry?')">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="del_registry">
            <input type="hidden" name="uid" value="<?= $f['id'] ?>">
            <button type="submit" class="btn-red" style="font-size:11px">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</details>

<details class="cpanel" open>
  <summary>Calendar Events</summary>
  <div class="cpbody">
    <?php
    $pending_events = $upcoming_events = [];
    try {
        $cdb = new PDO('sqlite:/var/lib/noosphere/calendar.db');
        $cdb->exec("CREATE TABLE IF NOT EXISTS events (id INTEGER PRIMARY KEY AUTOINCREMENT, title TEXT NOT NULL, event_date TEXT NOT NULL, event_time TEXT, location TEXT, notes TEXT, created_by TEXT, created_at INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'approved')");
        try { $cdb->exec("ALTER TABLE events ADD COLUMN status TEXT NOT NULL DEFAULT 'approved'"); } catch (Exception $e) {}
        $cdb->exec("UPDATE events SET status='approved' WHERE status IS NULL OR status=''");
        $pending_events  = $cdb->query("SELECT * FROM events WHERE status='pending' ORDER BY created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
        $upcoming_events = $cdb->query("SELECT * FROM events WHERE status='approved' AND event_date >= date('now') ORDER BY event_date ASC, event_time ASC")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
    ?>

    <?php if ($pending_events): ?>
    <h2 style="color:#f39c12;border-color:#f39c12;margin-bottom:10px">Pending Submissions (<?= count($pending_events) ?>)</h2>
    <div class="event-list" style="margin-bottom:24px">
    <?php foreach ($pending_events as $ev): ?>
      <div class="event-row" style="border-left:3px solid #f39c12;padding-left:10px">
        <div class="event-date"><?= esc($ev['event_date']) ?><?= $ev['event_time'] ? '<br><span style="font-size:11px;color:#888">'.esc($ev['event_time']).'</span>' : '' ?></div>
        <div class="event-info">
          <div class="event-title"><?= esc($ev['title']) ?></div>
          <div class="event-meta">
            <?= $ev['location'] ? '📍 '.esc($ev['location']).' · ' : '' ?>
            Submitted by <?= esc($ev['created_by'] ?: 'Anonymous') ?><?= $ev['notes'] ? ' · '.esc($ev['notes']) : '' ?>
          </div>
        </div>
        <div style="display:flex;gap:6px;flex-shrink:0">
          <form method="post" style="margin:0">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="approve_event">
            <input type="hidden" name="eid" value="<?= $ev['id'] ?>">
            <button type="submit" class="btn-green">Approve</button>
          </form>
          <form method="post" style="margin:0" onsubmit="return confirm('Reject and delete this submission?')">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="reject_event">
            <input type="hidden" name="eid" value="<?= $ev['id'] ?>">
            <button type="submit" class="btn-red">Reject</button>
          </form>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <h2>Upcoming Events</h2>
    <?php if (!$upcoming_events): ?><p style="color:#666;font-size:13px;margin-bottom:16px">No upcoming events.</p>
    <?php else: ?>
    <div class="event-list">
    <?php foreach ($upcoming_events as $ev): ?>
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

    <h2 style="margin-top:20px">Add Event</h2>
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
  </div>
</details>

</div><!-- #tab-community -->

<!-- NETWORK -->
<div id="tab-network" class="tab-content">

<details class="cpanel" open>
  <summary>Connected Devices <span class="badge" id="dev-count"></span></summary>
  <div class="cpbody">
    <div style="font-size:11px;color:#555;margin-bottom:10px">Devices visible via ARP + DHCP leases. Refresh to update. Banning by IP blocks posts/chat.</div>
    <?php $net_devices = get_connected_devices(); ?>
    <?php if (!$net_devices): ?>
      <div style="color:#555;font-size:13px;padding:8px 0">No devices found. Devices appear once they make a network request.</div>
    <?php else: ?>
    <table class="dtable">
      <tr><th>IP</th><th>MAC</th><th>Hostname</th><th>Interface</th><th></th></tr>
      <?php foreach ($net_devices as $dev): ?>
      <tr>
        <td style="font-family:monospace;font-size:12px"><?= esc($dev['ip']) ?></td>
        <td style="font-family:monospace;font-size:11px;color:#888"><?= esc($dev['mac']) ?></td>
        <td><?= esc($dev['hostname']) ?: '<span style="color:#555">—</span>' ?></td>
        <td style="color:#555;font-size:11px"><?= esc($dev['iface']) ?></td>
        <td>
          <form method="post" style="margin:0;display:inline">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="ban">
            <input type="hidden" name="ban_ip" value="<?= esc($dev['ip']) ?>">
            <input type="hidden" name="ban_reason" value="Banned via Network tab">
            <button type="submit" class="btn-red" style="font-size:11px;padding:3px 8px" onclick="return confirm('Ban IP <?= esc($dev['ip']) ?>?')">Ban IP</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <div style="font-size:11px;color:#555;margin-top:8px"><?= count($net_devices) ?> device(s) visible. Use Community tab to manage all active bans.</div>
    <?php endif; ?>
  </div>
</details>

<details class="cpanel" open>
  <summary>Network Interfaces</summary>
  <div class="cpbody">
    <?php $iface_stats = get_iface_stats(); ?>
    <?php if (!$iface_stats): ?>
      <div style="color:#555;font-size:13px">No interface data available.</div>
    <?php else: ?>
    <table class="dtable">
      <tr><th>Interface</th><th>IP Address</th><th>RX</th><th>TX</th><th>State</th></tr>
      <?php foreach ($iface_stats as $iface => $s):
        $ip_addr = trim(shell_exec("ip -4 addr show " . escapeshellarg($iface) . " 2>/dev/null | awk '/inet /{print \$2}' | head -1") ?: '');
        $link    = trim(@file_get_contents("/sys/class/net/{$iface}/operstate") ?: 'unknown');
      ?>
      <tr>
        <td><strong style="font-size:13px"><?= esc($iface) ?></strong></td>
        <td style="font-family:monospace;font-size:12px;color:#aaa"><?= $ip_addr ? esc($ip_addr) : '<span style="color:#555">—</span>' ?></td>
        <td style="font-family:monospace"><?= fmt_bytes($s['rx']) ?></td>
        <td style="font-family:monospace"><?= fmt_bytes($s['tx']) ?></td>
        <td style="color:<?= $link==='up'?'#2ecc71':'#555' ?>"><?= esc($link) ?></td>
      </tr>
      <?php endforeach; ?>
    </table>
    <?php endif; ?>
  </div>
</details>

<details class="cpanel">
  <summary>WiFi Details</summary>
  <div class="cpbody">
    <?php $iwinfo = shell_exec('iw dev 2>/dev/null') ?: (shell_exec('iwconfig 2>/dev/null') ?: ''); ?>
    <?php if ($iwinfo): ?>
      <div class="logbox"><?= esc($iwinfo) ?></div>
    <?php else: ?>
      <div style="color:#555;font-size:13px;padding:8px 0">No wireless interface info available.</div>
    <?php endif; ?>
    <form method="post" style="margin-top:12px">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="run">
      <input type="hidden" name="script" value="restart-wireless">
      <button type="submit" class="btn-sm">Restart Wireless</button>
    </form>
  </div>
</details>

<details class="cpanel">
  <summary>DHCP Leases</summary>
  <div class="cpbody">
    <?php
    $leases_raw = '';
    foreach (['/var/lib/misc/dnsmasq.leases','/var/lib/dnsmasq/dnsmasq.leases','/tmp/dnsmasq.leases'] as $lf) {
        if (file_exists($lf)) { $leases_raw = file_get_contents($lf); break; }
    }
    ?>
    <?php if ($leases_raw): ?>
      <div class="logbox"><?= esc($leases_raw) ?></div>
    <?php else: ?>
      <div style="color:#555;font-size:13px;padding:8px 0">No DHCP lease file found.</div>
    <?php endif; ?>
  </div>
</details>

</div><!-- #tab-network -->

<!-- CONTENT -->
<div id="tab-content" class="tab-content">

<details class="cpanel" open>
  <summary>Uploaded Files</summary>
  <div class="cpbody">
    <?php $ct_files = glob(FILES_DIR . '*') ?: []; ?>
    <?php if (!$ct_files): ?>
      <div style="color:#555;font-size:13px;padding:8px 0">No files uploaded yet.</div>
    <?php else: ?>
    <table class="dtable">
      <tr><th>Filename</th><th>Size</th><th>Type</th><th></th></tr>
      <?php foreach ($ct_files as $fp): $fname = basename($fp); $ext = strtolower(pathinfo($fp, PATHINFO_EXTENSION)); ?>
      <tr>
        <td><?= esc($fname) ?></td>
        <td><?= round(filesize($fp)/1024) ?> KB</td>
        <td style="color:#555;font-size:11px"><?= esc($ext) ?></td>
        <td>
          <a href="/files/<?= esc($fname) ?>" target="_blank" class="btn-sm" style="text-decoration:none;font-size:11px;padding:3px 8px">View</a>
          <form method="post" style="margin:0;display:inline" onsubmit="return confirm('Delete this file?')">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="del_file">
            <input type="hidden" name="fname" value="<?= esc($fname) ?>">
            <button type="submit" class="btn-red" style="font-size:11px;padding:3px 8px">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <div style="font-size:11px;color:#555;margin-top:8px"><?= count($ct_files) ?> file(s) · served from <a href="/files/" style="color:#4a9eff">/files/</a></div>
    <?php endif; ?>
  </div>
</details>

<details class="cpanel">
  <summary>Registry Photos</summary>
  <div class="cpbody">
    <?php $ct_photos = glob(PHOTOS_DIR . '*') ?: []; ?>
    <?php if (!$ct_photos): ?>
      <div style="color:#555;font-size:13px;padding:8px 0">No photos uploaded.</div>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(130px,1fr));gap:10px;margin-bottom:8px">
    <?php foreach ($ct_photos as $ph): $pfname = basename($ph); ?>
      <div style="background:#111126;border:1px solid #2a2a4a;border-radius:6px;overflow:hidden;text-align:center">
        <img src="/registry/photo/<?= esc($pfname) ?>" alt="" style="width:100%;height:90px;object-fit:cover;display:block" loading="lazy">
        <div style="padding:4px 6px 0;font-size:10px;color:#555;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= esc($pfname) ?></div>
        <div style="padding:4px 6px 6px">
        <form method="post" onsubmit="return confirm('Delete photo?')">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="del_photo">
          <input type="hidden" name="fname" value="<?= esc($pfname) ?>">
          <button type="submit" class="btn-red" style="width:100%;font-size:11px;padding:3px">Delete</button>
        </form>
        </div>
      </div>
    <?php endforeach; ?>
    </div>
    <div style="font-size:11px;color:#555"><?= count($ct_photos) ?> photo(s)</div>
    <?php endif; ?>
  </div>
</details>

<details class="cpanel">
  <summary>Map Markers</summary>
  <div class="cpbody">
    <?php
    $ct_markers = [];
    try {
        $mdb = new PDO('sqlite:/var/lib/noosphere/markers.db');
        $ct_markers = $mdb->query('SELECT * FROM markers ORDER BY created_at DESC LIMIT 100')->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {}
    ?>
    <?php if (!$ct_markers): ?>
      <div style="color:#555;font-size:13px;padding:8px 0">No map markers placed yet.</div>
    <?php else: ?>
    <table class="dtable">
      <tr><th>Title</th><th>Type</th><th>Notes</th><th>Author</th><th></th></tr>
      <?php foreach ($ct_markers as $mk): ?>
      <tr>
        <td><?= esc($mk['title'] ?? '—') ?></td>
        <td style="color:#888"><?= esc($mk['type'] ?? '—') ?></td>
        <td style="color:#555;font-size:11px"><?= esc(mb_substr($mk['notes'] ?? '', 0, 50)) ?><?= mb_strlen($mk['notes'] ?? '') > 50 ? '…' : '' ?></td>
        <td style="color:#555"><?= esc($mk['author'] ?? '—') ?></td>
        <td>
          <form method="post" style="margin:0" onsubmit="return confirm('Delete this marker?')">
            <?= csrf_field() ?>
            <input type="hidden" name="act" value="del_marker">
            <input type="hidden" name="marker_id" value="<?= (int)$mk['id'] ?>">
            <button type="submit" class="btn-red" style="font-size:11px;padding:3px 8px">Delete</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </table>
    <div style="font-size:11px;color:#555;margin-top:6px"><?= count($ct_markers) ?> marker(s)</div>
    <?php endif; ?>
  </div>
</details>

<details class="cpanel">
  <summary>Library — ZIM Modules</summary>
  <div class="cpbody">
    <div style="font-size:11px;color:#555;margin-bottom:10px">Enable or disable offline library modules. Changes take effect immediately (restarts Kiwix).</div>
    <?php $ct_zims = get_zim_info(); ?>
    <?php if (!$ct_zims): ?>
      <div style="color:#555;font-size:13px">No ZIM files found in <?= ZIM_DIR ?>. Copy .zim files there to add them.</div>
    <?php else: foreach ($ct_zims as $fname => $z):
      $display = zim_display_name($fname, $z['title']);
      $size_mb = round($z['size'] / 1048576);
      $enabled = $z['enabled'];
    ?>
      <div style="display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid #1a1a2e">
        <div style="flex:1">
          <div style="font-size:13px;color:<?= $enabled ? '#e0e0e0' : '#555' ?>"><?= esc($display) ?></div>
          <div style="font-size:10px;color:#444;margin-top:1px"><?= esc($fname) ?></div>
        </div>
        <span style="font-size:11px;color:#555;flex-shrink:0"><?= $size_mb ?> MB</span>
        <form method="post" style="flex-shrink:0">
          <?= csrf_field() ?>
          <input type="hidden" name="act" value="kiwix_toggle">
          <input type="hidden" name="zim" value="<?= esc($fname) ?>">
          <input type="hidden" name="enable" value="<?= $enabled ? '0' : '1' ?>">
          <button type="submit" style="padding:4px 14px;font-size:11px;border-radius:4px;border:1px solid <?= $enabled ? '#3a2a2a' : '#1a3a1a' ?>;background:none;color:<?= $enabled ? '#e94560' : '#2ecc71' ?>;cursor:pointer">
            <?= $enabled ? 'Disable' : 'Enable' ?>
          </button>
        </form>
      </div>
    <?php endforeach; endif; ?>
  </div>
</details>

</div><!-- #tab-content -->

<!-- SYSTEM -->
<div id="tab-system" class="tab-content">

<details class="cpanel" open>
  <summary>System Overview</summary>
  <div class="cpbody">
    <?php
    $temp_raw = 0;
    foreach (['/sys/class/thermal/thermal_zone0/temp','/sys/class/hwmon/hwmon0/temp1_input'] as $tf) {
        if (file_exists($tf)) { $temp_raw = (int)file_get_contents($tf); break; }
    }
    $temp_c   = $temp_raw ? round($temp_raw / 1000, 1) : null;
    $temp_col = $temp_c ? ($temp_c >= 75 ? '#e94560' : ($temp_c >= 60 ? '#f39c12' : '#2ecc71')) : '#888';
    $procs     = (int)trim(shell_exec('ps aux --no-header 2>/dev/null | wc -l') ?: '0');
    $php_procs = (int)trim(shell_exec('pgrep -c php-fpm 2>/dev/null') ?: '0');
    $nginx_procs = (int)trim(shell_exec('pgrep -c nginx 2>/dev/null') ?: '0');
    ?>
    <div class="svc-grid" style="grid-template-columns:repeat(auto-fill,minmax(155px,1fr))">
      <div class="svc-card">
        <div class="svc-name">CPU Temperature</div>
        <div class="svc-state" style="color:<?= $temp_col ?>"><?= $temp_c ? $temp_c . ' °C' : '—' ?></div>
      </div>
      <div class="svc-card">
        <div class="svc-name">Total processes</div>
        <div class="svc-state" style="color:#e0e0e0"><?= $procs ?></div>
      </div>
      <div class="svc-card">
        <div class="svc-name">PHP-FPM workers</div>
        <div class="svc-state" style="color:#e0e0e0"><?= $php_procs ?></div>
      </div>
      <div class="svc-card">
        <div class="svc-name">Nginx workers</div>
        <div class="svc-state" style="color:#e0e0e0"><?= $nginx_procs ?></div>
      </div>
    </div>
  </div>
</details>

<details class="cpanel" open>
  <summary>Disk Usage</summary>
  <div class="cpbody">
    <?php $df_out = shell_exec('df -h 2>/dev/null') ?: ''; ?>
    <div class="logbox"><?= esc($df_out) ?></div>
  </div>
</details>

<details class="cpanel">
  <summary>Top Processes (by CPU)</summary>
  <div class="cpbody">
    <?php $ps_out = shell_exec('ps aux --sort=-%cpu 2>/dev/null | head -15') ?: ''; ?>
    <div class="logbox"><?= esc($ps_out) ?></div>
  </div>
</details>

<details class="cpanel">
  <summary>Recent System Log</summary>
  <div class="cpbody">
    <?php $journal = shell_exec('journalctl -n 80 --no-pager -q 2>/dev/null') ?: (shell_exec('tail -80 /var/log/syslog 2>/dev/null') ?: ''); ?>
    <?php if ($journal): ?>
      <div class="logbox"><?= esc($journal) ?></div>
    <?php else: ?>
      <div style="color:#555;font-size:13px">Journal not available.</div>
    <?php endif; ?>
  </div>
</details>

<details class="cpanel">
  <summary>Nginx Access Log</summary>
  <div class="cpbody">
    <?php $nginx_log = shell_exec('tail -60 /var/log/nginx/access.log 2>/dev/null') ?: ''; ?>
    <?php if ($nginx_log): ?>
      <div class="logbox"><?= esc($nginx_log) ?></div>
    <?php else: ?>
      <div style="color:#555;font-size:13px">Log not found or empty.</div>
    <?php endif; ?>
  </div>
</details>

<details class="cpanel">
  <summary>Nginx Error Log</summary>
  <div class="cpbody">
    <?php $nginx_err = shell_exec('tail -40 /var/log/nginx/error.log 2>/dev/null') ?: ''; ?>
    <?php if ($nginx_err): ?>
      <div class="logbox"><?= esc($nginx_err) ?></div>
    <?php else: ?>
      <div style="color:#555;font-size:13px">No recent errors.</div>
    <?php endif; ?>
  </div>
</details>

<details class="cpanel">
  <summary>PHP-FPM Error Log</summary>
  <div class="cpbody">
    <?php $fpm_log = shell_exec('tail -40 /var/log/php8.4-fpm.log 2>/dev/null') ?: (shell_exec('tail -40 /var/log/php-fpm/error.log 2>/dev/null') ?: ''); ?>
    <?php if ($fpm_log): ?>
      <div class="logbox"><?= esc($fpm_log) ?></div>
    <?php else: ?>
      <div style="color:#555;font-size:13px">Log not found or empty.</div>
    <?php endif; ?>
  </div>
</details>

<details class="cpanel">
  <summary>Database Backup</summary>
  <div class="cpbody">
    <div style="font-size:12px;color:#888;margin-bottom:12px">Downloads a .zip archive of all SQLite databases (registry, settings, forum, calendar, analytics).</div>
    <form method="post">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="backup_db">
      <button type="submit" class="btn-green" style="padding:8px 22px;font-size:13px">Download Backup (.zip)</button>
    </form>
    <div style="font-size:11px;color:#555;margin-top:8px">Backup does not include uploaded files or ZIM libraries — copy those manually.</div>
  </div>
</details>

</div><!-- #tab-system -->

<!-- SETTINGS -->
<div id="tab-settings" class="tab-content">

<div class="sub-tab-bar">
  <div class="sub-tab active" onclick="showSubTab('stab-configure',this)">Configure</div>
  <div class="sub-tab" onclick="showSubTab('stab-modules',this)">Modules</div>
  <div class="sub-tab" onclick="showSubTab('stab-security',this)">Security</div>
  <div class="sub-tab" onclick="showSubTab('stab-tools',this)">Tools</div>
</div>

<!-- The main settings form wraps Configure + Modules so all settings post together -->
<form method="post" id="settings-form">
<?= csrf_field() ?>
<input type="hidden" name="act" value="save_settings">

<!-- ── CONFIGURE ─────────────────────────────────────────────────────────── -->
<div id="stab-configure" class="sub-tab-content active">

<div class="identity-section">
  <h3>Identity</h3>
  <div class="form-row">
    <div><label>Site Name</label><input type="text" name="instance_name" value="<?= esc(get_setting('instance_name','Noosphere')) ?>" placeholder="Noosphere"></div>
    <div><label>Tagline</label><input type="text" name="instance_tagline" value="<?= esc(get_setting('instance_tagline','')) ?>" placeholder="Offline information hub"></div>
  </div>
  <div><label>Alert Banner <span style="color:#555;font-weight:normal">(shown on homepage — leave blank to hide)</span></label>
  <input type="text" name="homepage_alert" value="<?= esc(get_setting('homepage_alert','')) ?>" placeholder="e.g. Shelter at capacity — see staff"></div>
</div>

<div class="mod-section">
  <div class="mod-header">
    <input type="checkbox" class="mod-toggle" id="t_readonly" name="readonly" <?= get_setting('readonly','0')==='1'?'checked':'' ?>>
    <label for="t_readonly">Read-Only / Kiosk Mode</label>
    <span style="font-size:11px;color:#888">Blocks all writes system-wide</span>
  </div>
</div>

<div style="margin:14px 0 6px;font-size:12px;color:#888;text-transform:uppercase;letter-spacing:.05em">Registration &amp; Access</div>

<div class="mod-section">
  <div class="mod-header">
    <input type="checkbox" class="mod-toggle" id="t_self_reg" name="registry_allow_self_register" <?= get_setting('registry_allow_self_register','1')==='1'?'checked':'' ?>>
    <label for="t_self_reg">Allow public self-registration</label>
    <span style="font-size:11px;color:#888">Visitors can check themselves in. Uncheck to make the registry read-only for non-admins (admin-managed entries only).</span>
  </div>
</div>

<div class="mod-section">
  <div class="mod-header">
    <input type="checkbox" class="mod-toggle" id="t_req_reg" name="require_registration" <?= get_setting('require_registration','0')==='1'?'checked':'' ?>>
    <label for="t_req_reg">Require registration for actions</label>
    <span style="font-size:11px;color:#888">Unregistered visitors can view everything but cannot post, send chat messages, or upload files.</span>
  </div>
</div>

<div style="margin:16px 0">
  <button type="submit" class="btn">Save</button>
</div>
</div><!-- #stab-configure -->

<!-- ── MODULES ───────────────────────────────────────────────────────────── -->
<div id="stab-modules" class="sub-tab-content">

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

<!-- Library (Kiwix) -->
<div class="mod-section">
  <div class="mod-header">
    <input type="checkbox" class="mod-toggle" id="t_library" name="show_library" <?= get_setting('show_library','1')==='1'?'checked':'' ?> onchange="modToggle('library',this.checked)">
    <label for="t_library">Library (Kiwix)</label>
  </div>
  <div class="mod-body<?= get_setting('show_library','1')!=='1'?' off':'' ?>" id="body_library" style="padding:12px 0 4px">
    <div style="font-size:11px;color:#555;margin-bottom:10px">Enable or disable individual ZIM modules. Changes take effect immediately.</div>
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
        <button type="button"
                onclick="zimToggle(<?= htmlspecialchars(json_encode($fname)) ?>,<?= $enabled ? '0' : '1' ?>)"
                style="flex-shrink:0;padding:4px 12px;font-size:11px;border-radius:4px;border:1px solid <?= $enabled ? '#3a2a2a' : '#1a3a1a' ?>;background:none;color:<?= $enabled ? '#e94560' : '#2ecc71' ?>;cursor:pointer">
          <?= $enabled ? 'Disable' : 'Enable' ?>
        </button>
      </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<?php
    
?>
<div class="mod-section">
  <div class="mod-header">
    <input type="checkbox" class="mod-toggle" id="t_tasks" name="show_tasks" <?= get_setting('show_tasks','1')==='1'?'checked':'' ?>>
    <label for="t_tasks">Tasks</label>
  </div>
  <div class="mod-body">
    <div class="field-label" style="margin-top:8px">Categories <span style="color:#555;font-weight:normal">&mdash; comma-separated, shown as filter tabs on the task board</span></div>
    <input type="text" name="tasks_categories" value="<?= esc(get_setting('tasks_categories','Rescue,Logistics,Medical,Maintenance,Other')) ?>" placeholder="Rescue,Logistics,Medical,Other">
    <div style="font-size:11px;color:#555;margin-top:4px">Suggested &mdash; Emergency: Rescue,Logistics,Medical,Maintenance,Communications,Other &middot; SAR: Search,Rescue,Medical,Logistics,Command,Other &middot; Shelter: Intake,Logistics,Medical,Maintenance,Staffing,Other</div>

    <div style="margin-top:14px;display:flex;flex-wrap:wrap;gap:14px 28px;align-items:center">
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
        <input type="checkbox" name="tasks_show_rewards" <?= get_setting('tasks_show_rewards','0')==='1'?'checked':'' ?>>
        <span>Show reward / payment field on tasks</span>
      </label>
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
        <input type="checkbox" name="tasks_require_login" <?= get_setting('tasks_require_login','0')==='1'?'checked':'' ?>>
        <span>Require registry login to claim tasks</span>
      </label>
      <label style="display:flex;align-items:center;gap:6px;cursor:pointer">
        <input type="checkbox" name="tasks_allow_self_create" <?= get_setting('tasks_allow_self_create','0')==='1'?'checked':'' ?>>
        <span>Allow anyone to submit task requests</span>
      </label>
    </div>
    <div style="margin-top:10px">
      <label class="field-label" style="display:inline">Auto-close completed tasks after <input type="number" name="tasks_auto_close_hours" value="<?= esc(get_setting('tasks_auto_close_hours','0')) ?>" min="0" style="width:64px;margin:0 4px"> hours <span style="color:#555;font-weight:normal">(0 = never)</span></label>
    </div>
  </div>
</div>
<?php
// Detect dongle status for the SDR card
$sdr_status = trim(shell_exec("/usr/local/bin/rtlsdr-detect.sh --verbose 2>&1") ?? "");
$sdr_ok = (strpos($sdr_status, "Status:    OK") !== false);
$sdr_mode = get_setting("radio_mode", "off");
$sdr_freq = get_setting("radio_freq", "162.550M");
$sdr_gain = get_setting("radio_gain", "49.6");
$sdr_ppm  = get_setting("radio_ppm", "0");
$sdr_fips = get_setting("radio_same_fips", "018005,018013");
$svc_state        = trim(shell_exec("systemctl is-active noaa-weather.service 2>/dev/null") ?? "");
$svc_rtl433       = trim(shell_exec("systemctl is-active noosphere-rtl433.service 2>/dev/null") ?? "");
$svc_aprs         = trim(shell_exec("systemctl is-active noosphere-aprs.service 2>/dev/null") ?? "");
$svc_aprs_writer  = trim(shell_exec("systemctl is-active noosphere-aprs-writer.service 2>/dev/null") ?? "");
$rtl433_sid       = get_setting('rtl433_sensor_id',    '');
$rtl433_model     = get_setting('rtl433_sensor_model', '');
$aprs_freq        = get_setting('aprs_freq',         '144.3900');
$aprs_expiry      = get_setting('aprs_expiry_hours', '2');
$status_box_bg  = $sdr_ok ? "#0a2a1a" : "#2a0a0a";
$status_box_br  = $sdr_ok ? "#2a4a3a" : "#5a3a3a";
$status_box_fg  = $sdr_ok ? "#7ad"   : "#e94560";
$card_border    = $sdr_ok ? "#4a4a6a" : "#5a3a3a";
?>
<div class="mod-section" style="border-color:<?= $card_border ?>">
  <div class="mod-header">
    <span style="font-size:15px">📡 SDR Radio <span style="font-size:11px;color:#888;font-weight:normal">— NOAA Weather Radio, spectrum scanner, rtl_433 sensors, APRS</span></span>
  </div>
  <div class="mod-body">

    <div style="background:<?= $status_box_bg ?>;border:1px solid <?= $status_box_br ?>;padding:8px 12px;border-radius:6px;margin:8px 0 12px;font-size:12px;font-family:monospace;white-space:pre-wrap;color:<?= $status_box_fg ?>"><?= htmlspecialchars($sdr_status ?: "(probe failed)") ?></div>

    <div class="field-label" style="margin-top:4px">Mode <span style="color:#555;font-weight:normal">— only one can hold the dongle at a time</span></div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px">
      <?php foreach ([
        ["off",     "Off",                  "Dongle idle. No SDR sections active."],
        ["nwr",     "NOAA Weather Radio",   "Decode SAME alerts &amp; stream live audio. Section appears in /weather/."],
        ["scanner", "Spectrum Scanner",     "Waterfall display. Section appears in /radio/."],
        ["rtl433",  "rtl_433 Sensors",      "Auto-log 433 MHz weather sensors to the Weather Log."],
        ["aprs",    "APRS Receive",         "Decode 144.390 MHz APRS packets; stations appear as map markers."],
      ] as [$mval,$mlbl,$mdesc]):
        $active = ($sdr_mode === $mval);
        $bcol = $active ? "#e94560" : "#2a2a4a";
        $bg   = $active ? "#2a1525" : "transparent";
      ?>
      <label style="flex:1;min-width:140px;padding:10px 12px;border:1px solid <?= $bcol ?>;border-radius:6px;cursor:pointer;background:<?= $bg ?>">
        <input type="radio" name="radio_mode" value="<?= $mval ?>" <?= $active ? "checked" : "" ?>> <strong><?= $mlbl ?></strong>
        <div style="font-size:11px;color:#888;margin-top:2px"><?= $mdesc ?></div>
      </label>
      <?php endforeach; ?>
    </div>

    <div class="field-label" style="margin-top:16px">NOAA Weather Radio tuning</div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-top:6px">
      <div>
        <div style="font-size:11px;color:#888;margin-bottom:3px">Frequency</div>
        <select name="radio_freq" style="width:100%">
        <?php foreach (["162.400M","162.425M","162.450M","162.475M","162.500M","162.525M","162.550M"] as $f): ?>
          <option value="<?= $f ?>" <?= $f === $sdr_freq ? "selected" : "" ?>><?= $f ?></option>
        <?php endforeach; ?>
        </select>
        <div style="font-size:10px;color:#555;margin-top:3px">Use noaa-scan.sh on the host to pick strongest local NWR</div>
      </div>
      <div>
        <div style="font-size:11px;color:#888;margin-bottom:3px">Tuner gain (dB)</div>
        <input type="text" name="radio_gain" value="<?= htmlspecialchars($sdr_gain) ?>" style="width:100%" placeholder="49.6">
        <div style="font-size:10px;color:#555;margin-top:3px">numeric dB; max varies by tuner (R820T tops at 49.6)</div>
      </div>
      <div>
        <div style="font-size:11px;color:#888;margin-bottom:3px">PPM correction</div>
        <input type="number" name="radio_ppm" value="<?= htmlspecialchars($sdr_ppm) ?>" style="width:100%" placeholder="0">
        <div style="font-size:10px;color:#555;margin-top:3px">Crystal drift, usually 0–60</div>
      </div>
    </div>

    <div class="field-label" style="margin-top:14px">Local FIPS codes <span style="color:#555;font-weight:normal">— alerts matching these are flagged as local</span></div>
    <input type="text" name="radio_same_fips" value="<?= htmlspecialchars($sdr_fips) ?>" placeholder="018005,018013" style="margin-bottom:4px">
    <div style="font-size:11px;color:#555">Bartholomew IN = 018005 · Brown IN = 018013 · Lookup at weather.gov/nwr/counties</div>

    <div style="margin-top:14px;display:flex;gap:8px;align-items:center;flex-wrap:wrap">
      <span style="font-size:12px;color:#888">Service:</span>
      <span style="font-family:monospace;font-size:12px;color:<?= $svc_state === "active" ? "#7ad" : "#888" ?>">noaa-weather.service = <?= htmlspecialchars($svc_state ?: "unknown") ?></span>
      <span style="margin-left:auto;font-size:11px;color:#555">Save Settings to apply mode + tuning changes</span>
    </div>

    <div class="field-label" style="margin-top:18px">rtl_433 Sensor Filter <span style="color:#555;font-weight:normal">— leave blank to accept all sensors nearby</span></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:6px">
      <div>
        <div style="font-size:11px;color:#888;margin-bottom:3px">Sensor ID</div>
        <input type="text" name="rtl433_sensor_id" value="<?= htmlspecialchars($rtl433_sid) ?>" style="width:100%" placeholder="e.g. 42">
        <div style="font-size:10px;color:#555;margin-top:3px">Numeric ID from rtl_433 JSON output</div>
      </div>
      <div>
        <div style="font-size:11px;color:#888;margin-bottom:3px">Sensor Model</div>
        <input type="text" name="rtl433_sensor_model" value="<?= htmlspecialchars($rtl433_model) ?>" style="width:100%" placeholder="e.g. Acurite-606TX">
        <div style="font-size:10px;color:#555;margin-top:3px">Model string from rtl_433 JSON output</div>
      </div>
    </div>

    <div class="field-label" style="margin-top:18px">APRS Receive Settings</div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:6px">
      <div>
        <div style="font-size:11px;color:#888;margin-bottom:3px">Frequency (MHz)</div>
        <input type="text" name="aprs_freq" value="<?= htmlspecialchars($aprs_freq) ?>" style="width:100%" placeholder="144.3900">
        <div style="font-size:10px;color:#555;margin-top:3px">144.390 = North American APRS</div>
      </div>
      <div>
        <div style="font-size:11px;color:#888;margin-bottom:3px">Station expiry (hours)</div>
        <input type="number" name="aprs_expiry_hours" value="<?= htmlspecialchars($aprs_expiry) ?>" min="1" max="48" style="width:100%" placeholder="2">
        <div style="font-size:10px;color:#555;margin-top:3px">Stations fade from map after this long</div>
      </div>
    </div>

    <div style="margin-top:12px;display:flex;gap:16px;flex-wrap:wrap;font-size:12px;color:#666">
      <?php
      $svcs = [
          'noaa-weather' => $svc_state,
          'noosphere-rtl433' => $svc_rtl433,
          'noosphere-aprs' => $svc_aprs,
          'noosphere-aprs-writer' => $svc_aprs_writer,
      ];
      foreach ($svcs as $sname => $sstate): ?>
        <span style="font-family:monospace;color:<?= $sstate === 'active' ? '#7ad' : '#555' ?>">
          <?= htmlspecialchars($sname) ?> = <?= htmlspecialchars($sstate ?: 'inactive') ?>
        </span>
      <?php endforeach; ?>
      <span style="margin-left:auto;font-size:11px;color:#555">Save Settings to apply</span>
    </div>

    <details style="margin-top:16px;border:1px solid #2a2a4a;border-radius:6px">
      <summary style="padding:8px 12px;font-size:12px;color:#888;cursor:pointer;user-select:none">🔧 Diagnostics &amp; Troubleshooting</summary>
      <div style="padding:12px">

        <!-- Quick-fix buttons -->
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px">
          <button type="button" class="sdr-diag-btn" data-act="sdr_diag_usb" style="background:#111126;border:1px solid #2a2a4a;color:#7ad;border-radius:5px;padding:6px 12px;font-size:12px;cursor:pointer">🔍 Scan USB</button>
          <button type="button" class="sdr-diag-btn" data-act="sdr_diag_rtltest" style="background:#111126;border:1px solid #2a2a4a;color:#7ad;border-radius:5px;padding:6px 12px;font-size:12px;cursor:pointer">📡 rtl_test (~8s)</button>
          <button type="button" class="sdr-diag-btn" data-act="sdr_diag_log" data-service="noaa-weather" style="background:#111126;border:1px solid #2a2a4a;color:#aaa;border-radius:5px;padding:6px 12px;font-size:12px;cursor:pointer">📋 NWR Log</button>
          <button type="button" class="sdr-diag-btn" data-act="sdr_diag_log" data-service="scanner-waterfall" style="background:#111126;border:1px solid #2a2a4a;color:#aaa;border-radius:5px;padding:6px 12px;font-size:12px;cursor:pointer">📋 Scanner Log</button>
          <button type="button" class="sdr-diag-btn" data-act="sdr_diag_log" data-service="noosphere-rtl433" style="background:#111126;border:1px solid #2a2a4a;color:#aaa;border-radius:5px;padding:6px 12px;font-size:12px;cursor:pointer">📋 rtl_433 Log</button>
          <button type="button" class="sdr-diag-btn" data-act="sdr_diag_log" data-service="noosphere-aprs" style="background:#111126;border:1px solid #2a2a4a;color:#aaa;border-radius:5px;padding:6px 12px;font-size:12px;cursor:pointer">📋 APRS Log</button>
          <button type="button" class="sdr-diag-btn" data-act="sdr_diag_restart" data-service="noaa-weather" style="background:#111126;border:1px solid #e9456022;color:#e94560;border-radius:5px;padding:6px 12px;font-size:12px;cursor:pointer" onclick="return confirm('Restart noaa-weather.service?')">↺ Restart NWR</button>
          <button type="button" class="sdr-diag-btn" data-act="sdr_diag_restart" data-service="scanner-waterfall" style="background:#111126;border:1px solid #e9456022;color:#e94560;border-radius:5px;padding:6px 12px;font-size:12px;cursor:pointer" onclick="return confirm('Restart scanner-waterfall.service?')">↺ Restart Scanner</button>
          <button type="button" class="sdr-diag-btn" data-act="sdr_diag_restart" data-service="noosphere-rtl433" style="background:#111126;border:1px solid #e9456022;color:#e94560;border-radius:5px;padding:6px 12px;font-size:12px;cursor:pointer" onclick="return confirm('Restart noosphere-rtl433.service?')">↺ Restart rtl_433</button>
          <button type="button" class="sdr-diag-btn" data-act="sdr_diag_restart" data-service="noosphere-aprs" style="background:#111126;border:1px solid #e9456022;color:#e94560;border-radius:5px;padding:6px 12px;font-size:12px;cursor:pointer" onclick="return confirm('Restart noosphere-aprs.service?')">↺ Restart APRS</button>
          <button type="button" class="sdr-diag-btn" data-act="sdr_diag_blacklist" style="background:#111126;border:1px solid #e9456022;color:#f39c12;border-radius:5px;padding:6px 12px;font-size:12px;cursor:pointer" onclick="return confirm('Re-write blacklist and unload DVB modules?')">🛡 Re-apply Blacklist</button>
        </div>

        <!-- Output area -->
        <div id="sdr-diag-output" style="display:none;background:#000;border:1px solid #2a2a4a;border-radius:5px;padding:10px;font-family:monospace;font-size:11px;color:#ccc;white-space:pre-wrap;max-height:260px;overflow-y:auto"></div>
        <div id="sdr-diag-spinner" style="display:none;font-size:12px;color:#555;margin-top:6px">Running…</div>

        <!-- Common errors guide -->
        <details style="margin-top:14px">
          <summary style="font-size:12px;color:#555;cursor:pointer">Common issues</summary>
          <div style="margin-top:8px;font-size:12px;color:#888;line-height:1.7">
            <strong style="color:#aaa">No device found</strong> — dongle not plugged in, or DVB kernel modules claimed it first. Run <em>Re-apply Blacklist</em> then unplug/replug dongle.<br>
            <strong style="color:#aaa">Device busy / cannot open</strong> — another process is using the dongle. Stop all SDR services (set Mode → Off, save), then retry.<br>
            <strong style="color:#aaa">Weak / no SAME alerts</strong> — stock antenna has poor gain at 162 MHz. Use a quarter-wave dipole (~46cm) near a window. See Admin → Wiki → Optional Hardware.<br>
            <strong style="color:#aaa">Wrong frequency</strong> — WXL58 (Indianapolis) transmits on 162.550 MHz. Confirm with <code style="color:#888">rtl_fm -f 162550000 -s 22050 | play -r 22050 -t raw -e s -b 16 -c 1 - 2>/dev/null</code> on the server.<br>
            <strong style="color:#aaa">High PPM drift</strong> — cheap dongles drift ±60 PPM. Run <em>rtl_test</em> and watch the PPM correction value; enter it in the PPM field above.
          </div>
        </details>
      </div>
    </details>

    <script>
    (function(){
      document.querySelectorAll('.sdr-diag-btn').forEach(function(btn){
        btn.addEventListener('click', function(e){
          if (btn.getAttribute('onclick') && !confirm('')) return;
          var act = btn.dataset.act;
          var svc = btn.dataset.service || '';
          var out = document.getElementById('sdr-diag-output');
          var spin = document.getElementById('sdr-diag-spinner');
          out.style.display = 'none';
          spin.style.display = 'block';
          var fd = new FormData();
          fd.append('act', act);
          if (svc) fd.append('service', svc);
          fd.append('csrf_token', document.querySelector('[name=csrf_token]').value);
          fetch('', {method:'POST', body:fd})
            .then(function(r){ return r.json(); })
            .then(function(r){
              spin.style.display = 'none';
              out.textContent = r.output || r.error || '(no output)';
              out.style.display = 'block';
              out.scrollTop = out.scrollHeight;
            })
            .catch(function(e){ spin.style.display='none'; out.textContent='Error: '+e; out.style.display='block'; });
        });
      });
    })();
    </script>
  </div>
</div>


<!-- Transcription -->
<?php
$t_enabled     = get_setting('transcription_enabled','0') === '1';
$t_nwr_auto    = get_setting('transcription_nwr_auto','0') === '1';
$t_nwr_hybrid  = get_setting('transcription_nwr_hybrid','0') === '1';
$t_talk_post   = get_setting('transcription_talk_post','0') === '1';
$whisper_ok    = (trim(shell_exec('/opt/noosphere-whisper/bin/python3 -c "import vosk; print(1)" 2>/dev/null') ?? '') === '1') && count(glob('/var/lib/noosphere/vosk-models/vosk-model*')) > 0;
$last_tx = [];
$tx_file = '/var/lib/noosphere/weather/last-transcription.json';
if (file_exists($tx_file)) $last_tx = json_decode(file_get_contents($tx_file), true) ?? [];
?>
<details class="cpanel" <?= $t_enabled ? 'open' : '' ?>>
  <summary>🎙 Transcription <span style="font-size:11px;color:#888;font-weight:normal">— Auto-log NWR audio with vosk (offline speech recognition)</span></summary>
  <div class="cpbody">
    <?php if (!$whisper_ok): ?>
    <div style="background:#1a1a00;border:1px solid #554400;border-radius:6px;padding:10px 14px;margin-bottom:12px;font-size:12px;color:#aa9">
      <strong style="color:#cc9">vosk or model not found.</strong> Install:<br>
      <code style="font-size:11px;color:#888">python3 -m venv /opt/noosphere-whisper &amp;&amp; /opt/noosphere-whisper/bin/pip install vosk</code><br>
      Then download a model into <code style="color:#888">/var/lib/noosphere/vosk-models/</code><br>
      (e.g. <code style="color:#888">vosk-model-small-en-us-0.15</code> from alphacephei.com/vosk/models)<br>
      Features below will be unavailable until both are present.
    </div>
    <?php else: ?>
    <?php $vmodel = basename(glob("/var/lib/noosphere/vosk-models/vosk-model*")[0] ?? ""); ?>
    <div style="font-size:12px;color:#2ecc71;margin-bottom:10px">&#x2714; vosk ready &mdash; model: <?= esc($vmodel) ?></div>
    <?php endif; ?>

    <div style="display:flex;flex-direction:column;gap:8px;margin-bottom:14px">
      <label style="display:flex;align-items:center;gap:8px;font-size:13px">
        <input type="checkbox" name="transcription_enabled" <?= $t_enabled?'checked':'' ?> <?= !$whisper_ok?'disabled':'' ?>>
        Enable transcription
      </label>
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;padding-left:20px;color:<?= $t_enabled?'#ccc':'#555'?>">
        <input type="checkbox" name="transcription_nwr_auto" <?= $t_nwr_auto?'checked':'' ?> <?= (!$t_enabled||!$whisper_ok)?'disabled':'' ?>>
        Auto-transcribe NWR every 30 min (systemd timer)
      </label>
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;padding-left:20px;color:<?= $t_enabled?'#ccc':'#555'?>">
        <input type="checkbox" name="transcription_nwr_hybrid" <?= $t_nwr_hybrid?'checked':'' ?> <?= (!$t_enabled||!$whisper_ok)?'disabled':'' ?>>
        Hybrid mode — listen live + auto-transcribe simultaneously
      </label>
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;padding-left:20px;color:<?= $t_enabled?'#ccc':'#555'?>">
        <input type="checkbox" name="transcription_talk_post" <?= $t_talk_post?'checked':'' ?> <?= (!$t_enabled||!$whisper_ok)?'disabled':'' ?>>
        Post summary to Talk after each transcription
      </label>
    </div>

    <?php if ($whisper_ok && $t_enabled): ?>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px">
      <button type="button" id="tx-now-btn" style="background:#1a2a3a;border:1px solid #2a5a7a;color:#7ad;border-radius:5px;padding:7px 16px;font-size:12px;cursor:pointer">🎙 Transcribe Now</button>
      <?php if ($last_tx): ?>
      <span style="font-size:11px;color:#555">Last run: <?= date('M j H:i', $last_tx['ts'] ?? 0) ?></span>
      <?php endif; ?>
    </div>
    <?php if ($last_tx): ?>
    <div style="background:#0a0a1a;border:1px solid #2a2a4a;border-radius:5px;padding:8px 12px;font-size:11px;color:#888;margin-bottom:4px">
      <div style="color:#aaa;margin-bottom:3px">Last transcript:</div>
      <?= htmlspecialchars(substr($last_tx['transcript'] ?? '', 0, 300)) ?>
      <?php if (!empty($last_tx['parsed'])): ?>
      <div style="margin-top:4px;color:#7ad">
        Parsed: <?= htmlspecialchars(implode(' · ', array_map(fn($k,$v)=>"$k: $v", array_keys($last_tx['parsed']), $last_tx['parsed']))) ?>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <div id="tx-output" style="display:none;background:#000;border:1px solid #2a2a4a;border-radius:5px;padding:10px;font-family:monospace;font-size:11px;color:#ccc;white-space:pre-wrap;max-height:200px;overflow-y:auto;margin-top:8px"></div>
    <div id="tx-spinner" style="display:none;font-size:12px;color:#555;margin-top:6px">Transcribing… (may take 30-60s on this hardware)</div>
    <script>
    document.getElementById('tx-now-btn').addEventListener('click', function() {
      var out = document.getElementById('tx-output');
      var spin = document.getElementById('tx-spinner');
      out.style.display = 'none'; spin.style.display = 'block';
      var fd = new FormData();
      fd.append('act', 'transcribe_now');
      fd.append('csrf_token', document.querySelector('[name=csrf_token]').value);
      fetch('', {method:'POST', body:fd})
        .then(function(r){ return r.json(); })
        .then(function(r){
          spin.style.display = 'none';
          out.textContent = r.output || '(no output)';
          out.style.display = 'block';
          out.scrollTop = out.scrollHeight;
        })
        .catch(function(e){ spin.style.display='none'; out.textContent='Error: '+e; out.style.display='block'; });
    });
    </script>
    <?php endif; ?>
  </div>
</details>

<?php
$simple_mods = [
    ['show_chat',     't_chat',     'Chat'],
    ['show_files',    't_files',    'Files'],
    ['show_maps',     't_maps',     'Maps'],
    ['show_topo',     't_topo',     'Topo Maps'],
    ['show_calendar', 't_calendar', 'Calendar'],
    ['show_weather',  't_weather',  'Weather Log'],
    ['show_radio',    't_radio',    'Radio Net Log'],
    ['show_runners',  't_runners',  'Runner Board'],
    ['show_damage',   't_damage',   'Damage Reports'],
    ['show_triage',   't_triage',   'Triage Log'],
];
foreach ($simple_mods as [$key, $id, $label]):
?>
<div class="mod-section">
  <div class="mod-header">
    <input type="checkbox" class="mod-toggle" id="<?= $id ?>" name="<?= $key ?>" <?= get_setting($key,'1')==='1'?'checked':'' ?>>
    <label for="<?= $id ?>"><?= $label ?></label>
  </div>
</div>
<?php endforeach; ?>

<div style="margin:16px 0">
  <button type="submit" class="btn">Save</button>
</div>
</div><!-- #stab-modules -->

</form>

<!-- ── SECURITY ──────────────────────────────────────────────────────────── -->
<div id="stab-security" class="sub-tab-content">

<?php $linux_user = trim(shell_exec("awk -F: '\$3==1000{print \$1}' /etc/passwd | head -1") ?: 'cogitator'); ?>
<details class="cpanel" open>
  <summary>Linux System Credentials</summary>
  <div class="cpbody">
  <div style="font-size:12px;color:#555;margin-bottom:14px">Current Linux user: <strong style="color:#aaa"><?= esc($linux_user) ?></strong> — passwords for SSH/console login. Persist when cloning the drive.</div>
  <div style="display:flex;gap:12px;flex-wrap:wrap">
    <?php foreach ([['user', "User ($linux_user)"], ['root', 'Root']] as [$target, $lbl]): ?>
    <form method="post" style="flex:1;min-width:200px;background:#111126;border:1px solid #2a2a4a;border-radius:6px;padding:12px">
      <?= csrf_field() ?>
      <input type="hidden" name="act" value="change_linux_pw">
      <input type="hidden" name="linux_target" value="<?= $target ?>">
      <div style="font-size:12px;font-weight:bold;color:#aaa;margin-bottom:10px"><?= $lbl ?></div>
      <label class="field-label">New password</label>
      <input type="password" name="linux_new_pw" required minlength="6" style="margin-bottom:8px">
      <label class="field-label">Confirm</label>
      <input type="password" name="linux_con_pw" required minlength="6" style="margin-bottom:10px">
      <button type="submit" class="btn" style="width:100%;margin-top:0;padding:7px">Set <?= $lbl ?> Password</button>
    </form>
    <?php endforeach; ?>
  </div>
  <div style="font-size:11px;color:#555;margin-top:10px">To rename the Linux user or set credentials before imaging, run: <code style="color:#aaa">sudo setup-credentials.sh</code></div>
  </div>
</details>

<details class="cpanel" open>
  <summary>Admin Panel Password</summary>
  <div class="cpbody">
  <form method="post">
    <?= csrf_field() ?>
    <input type="hidden" name="act" value="change_password">
    <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:8px">
      <div style="flex:1;min-width:140px"><label class="field-label">Current password</label><input type="password" name="cur_pw" required></div>
      <div style="flex:1;min-width:140px"><label class="field-label">New password</label><input type="password" name="new_pw" required minlength="6"></div>
      <div style="flex:1;min-width:140px"><label class="field-label">Confirm new</label><input type="password" name="con_pw" required minlength="6"></div>
    </div>
    <button type="submit" class="btn" style="margin-top:12px;padding:8px 20px">Update Password</button>
    <div style="font-size:11px;color:#555;margin-top:8px">Registry admin users can always log in with their registry PIN regardless of this password.</div>
  </form>
  </div>
</details>

</div><!-- #stab-security -->

<!-- ── TOOLS ─────────────────────────────────────────────────────────────── -->
<div id="stab-tools" class="sub-tab-content">

<details class="cpanel" open>
  <summary>Quick Start Presets</summary>
  <div class="cpbody">
  <div style="font-size:12px;color:#555;margin-bottom:12px">Applies a full configuration preset — overwrites all settings in Configure and Modules.</div>
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
  </div>
</details>

<details class="cpanel" open>
  <summary>Utility Scripts</summary>
  <div class="cpbody">
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
  <div class="output-box" style="margin-top:10px"><h3><?= esc($run_name) ?>.sh</h3><?= esc($run_output) ?></div>
  <?php endif; ?>
  </div>
</details>

<details class="cpanel">
  <summary>Reset Instance</summary>
  <div class="cpbody">
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
  </div>
</details>

</div><!-- #stab-tools -->

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
function showSubTab(id, el) {
  document.querySelectorAll('.sub-tab-content').forEach(function(e){ e.classList.remove('active'); });
  document.querySelectorAll('.sub-tab').forEach(function(e){ e.classList.remove('active'); });
  document.getElementById(id).classList.add('active');
  el.classList.add('active');
}

function modToggle(name, on) {
  var body = document.getElementById('body_' + name);
  if (body) { if (on) body.classList.remove('off'); else body.classList.add('off'); }
}

function zimToggle(fname, enable) {
  var f = document.getElementById('zim-action-form');
  f.querySelector('[name=zim]').value = fname;
  f.querySelector('[name=enable]').value = enable;
  f.submit();
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
<form id="zim-action-form" method="post" style="display:none">
  <?= csrf_field() ?>
  <input type="hidden" name="act" value="kiwix_toggle">
  <input type="hidden" name="zim" value="">
  <input type="hidden" name="enable" value="">
</form>
</body>
</html>
