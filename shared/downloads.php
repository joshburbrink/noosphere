<?php
/*
 * shared/downloads.php  -  background download queue (#82 Phase B).
 *
 * Single SQLite queue. A long-running worker (noosphere-download-worker.service)
 * polls the `downloads` table and processes one item at a time so we don't
 * thrash the disk on a 64GB USB. Admin pages enqueue; worker updates progress.
 */

define('DOWNLOADS_DB', '/var/lib/noosphere/downloads.db');
define('CATALOG_CACHE', '/var/lib/noosphere/kiwix_catalog.json');

function _dl_db(): SQLite3 {
    static $db = null;
    if ($db) return $db;
    $db = new SQLite3(DOWNLOADS_DB);
    $db->exec('PRAGMA journal_mode=WAL');
    $db->exec("CREATE TABLE IF NOT EXISTS downloads (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        kind         TEXT NOT NULL,
        label        TEXT,
        url          TEXT NOT NULL,
        target_path  TEXT NOT NULL,
        status       TEXT NOT NULL DEFAULT 'queued',
        bytes_total  INTEGER DEFAULT 0,
        bytes_done   INTEGER DEFAULT 0,
        pid          INTEGER,
        error        TEXT,
        queued_at    INTEGER NOT NULL,
        started_at   INTEGER,
        finished_at  INTEGER
    )");
    return $db;
}

function dl_queue(string $kind, string $label, string $url, string $target_path, int $bytes_total = 0): int {
    $db = _dl_db();
    $s = $db->prepare('INSERT INTO downloads (kind,label,url,target_path,status,bytes_total,queued_at)
                       VALUES (?,?,?,?,?,?,?)');
    $s->bindValue(1, $kind);
    $s->bindValue(2, $label);
    $s->bindValue(3, $url);
    $s->bindValue(4, $target_path);
    $s->bindValue(5, 'queued');
    $s->bindValue(6, $bytes_total, SQLITE3_INTEGER);
    $s->bindValue(7, time(),       SQLITE3_INTEGER);
    $s->execute();
    return (int)$db->lastInsertRowID();
}

function dl_list(int $limit = 50): array {
    $db = _dl_db();
    $rs = $db->query('SELECT * FROM downloads ORDER BY id DESC LIMIT ' . (int)$limit);
    $out = [];
    while ($r = $rs->fetchArray(SQLITE3_ASSOC)) $out[] = $r;
    return $out;
}

function dl_active(): array {
    $db = _dl_db();
    $rs = $db->query("SELECT * FROM downloads WHERE status IN ('queued','running') ORDER BY id ASC");
    $out = [];
    while ($r = $rs->fetchArray(SQLITE3_ASSOC)) $out[] = $r;
    return $out;
}

function dl_cancel(int $id): bool {
    $db = _dl_db();
    $row = $db->querySingle('SELECT pid,status FROM downloads WHERE id=' . (int)$id, true);
    if (!$row) return false;
    if ($row['status'] === 'running' && !empty($row['pid'])) {
        @posix_kill((int)$row['pid'], 15);
    }
    $s = $db->prepare("UPDATE downloads SET status='cancelled', finished_at=? WHERE id=? AND status IN ('queued','running')");
    $s->bindValue(1, time(), SQLITE3_INTEGER);
    $s->bindValue(2, $id,    SQLITE3_INTEGER);
    $s->execute();
    return true;
}

function dl_clear_finished(): int {
    $db = _dl_db();
    $db->exec("DELETE FROM downloads WHERE status IN ('done','failed','cancelled')");
    return $db->changes();
}

/*
 * Kiwix OPDS catalog fetch + cache. Parses
 * https://library.kiwix.org/catalog/v2/entries (paginated) into a flat
 * JSON list cached at CATALOG_CACHE. Call from admin UI's "Refresh" button
 * while online.
 */
function catalog_refresh(int $timeout = 30): array {
    $base = 'https://library.kiwix.org/catalog/v2/entries?count=-1';
    $ctx  = stream_context_create(['http' => ['timeout' => $timeout, 'user_agent' => 'noosphere/1.0']]);
    $xml_raw = @file_get_contents($base, false, $ctx);
    if ($xml_raw === false) {
        throw new RuntimeException('Catalog fetch failed  -  check internet connectivity.');
    }
    $xml = @simplexml_load_string($xml_raw);
    if (!$xml) throw new RuntimeException('Catalog XML parse error.');

    $entries = [];
    foreach ($xml->entry as $e) {
        $ns_dc = $e->children('http://purl.org/dc/terms/');
        $title = (string)$e->title;
        $lang  = (string)($ns_dc->language ?? '');
        $cats  = [];
        foreach ($e->category as $c) {
            $t = (string)$c['term'];
            if ($t !== '') $cats[] = $t;
        }
        $url = ''; $size = 0; $mime = '';
        foreach ($e->link as $l) {
            $rel = (string)$l['rel'];
            if (strpos($rel, 'acquisition') !== false) {
                $url  = (string)$l['href'];
                $size = (int)$l['length'];
                $mime = (string)$l['type'];
                break;
            }
        }
        if (!$url || strpos($mime, 'zim') === false) continue;
        $entries[] = [
            'id'       => (string)$e->id,
            'title'    => $title,
            'summary'  => trim((string)$e->summary),
            'language' => $lang,
            'categories' => $cats,
            'url'      => $url,
            'size'     => $size,
            'filename' => basename(parse_url($url, PHP_URL_PATH) ?: ''),
        ];
    }
    $payload = ['fetched_at' => time(), 'count' => count($entries), 'entries' => $entries];
    @file_put_contents(CATALOG_CACHE, json_encode($payload));
    return $payload;
}

function catalog_load(): ?array {
    if (!is_file(CATALOG_CACHE)) return null;
    $j = @json_decode((string)file_get_contents(CATALOG_CACHE), true);
    return is_array($j) ? $j : null;
}

function catalog_online_check(int $timeout = 4): bool {
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'method' => 'HEAD']]);
    $h = @get_headers('https://library.kiwix.org/catalog/v2/entries?count=1', false, $ctx);
    return is_array($h) && strpos($h[0] ?? '', '200') !== false;
}

/*
 * Storage targets  -  where large downloads can be written. Always offers the
 * boot drive; additionally any writable filesystem mounted under /media/* or
 * /mnt/* (a second USB / disk). Lets an operator send big ZIMs to a roomy
 * external drive instead of filling the 64GB boot USB.
 */
function storage_targets(): array {
    $targets = [];
    // Boot drive (always present).
    $targets[] = [
        'id'    => 'boot',
        'label' => 'Boot drive (this USB)',
        'root'  => '/',
        'free'  => @disk_free_space('/') ?: 0,
        'total' => @disk_total_space('/') ?: 0,
    ];
    // Mounted external filesystems.
    foreach (['/media', '/mnt'] as $base) {
        foreach (glob($base . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            // Must be an actual mount point and writable.
            if (!is_dir($dir) || !is_writable($dir)) continue;
            if (!@disk_total_space($dir)) continue;
            // Skip if it resolves to the same device as root (not a separate mount).
            $st_dir = @stat($dir); $st_root = @stat('/');
            if ($st_dir && $st_root && $st_dir['dev'] === $st_root['dev']) continue;
            $targets[] = [
                'id'    => 'ext:' . $dir,
                'label' => 'External: ' . basename($dir),
                'root'  => $dir,
                'free'  => @disk_free_space($dir) ?: 0,
                'total' => @disk_total_space($dir) ?: 0,
            ];
        }
    }
    return $targets;
}

function storage_target_by_id(string $id): ?array {
    foreach (storage_targets() as $t) if ($t['id'] === $id) return $t;
    return null;
}

// ZIM directory for a given storage target. Boot drive uses the canonical
// kiwix path; external drives get a noosphere/kiwix/zim subtree.
function storage_zim_dir(array $target): string {
    if ($target['id'] === 'boot') return '/var/lib/kiwix/zim';
    return rtrim($target['root'], '/') . '/noosphere/kiwix/zim';
}

/*
 * Query the USGS National Map (TNM) API for US Topo 7.5-minute quads covering
 * a bbox. Returns [['title','url','size','filename'], ...]. Online-only.
 */
function tnm_topo_query(float $s, float $w, float $n, float $e, int $max = 60, int $timeout = 30): array {
    // TNM bbox param order is minX,minY,maxX,maxY  =  W,S,E,N.
    $url = 'https://tnmaccess.nationalmap.gov/api/v1/products?bbox='
         . rawurlencode("$w,$s,$e,$n")
         . '&datasets=' . rawurlencode('National Geospatial Program US Topo 7.5 Minute')
         . '&outputFormat=JSON&max=' . (int)$max;
    $ctx = stream_context_create(['http' => ['timeout' => $timeout, 'user_agent' => 'noosphere/1.0']]);
    $raw = @file_get_contents($url, false, $ctx);
    if ($raw === false) throw new RuntimeException('Could not reach the USGS TNM API  -  needs internet.');
    $j = json_decode($raw, true);
    if (!is_array($j) || !isset($j['items'])) throw new RuntimeException('Unexpected TNM API response.');
    $out = [];
    foreach ($j['items'] as $it) {
        $u = (string)($it['downloadURL'] ?? '');
        if (!$u || stripos($u, '.pdf') === false) {
            foreach (($it['downloadLinksList'] ?? []) as $l) {
                if (is_string($l) && stripos($l, '.pdf') !== false) { $u = $l; break; }
            }
        }
        if (!$u || !preg_match('#^https?://#', $u)) continue;
        $title = trim((string)($it['title'] ?? 'topo'));
        $fname = preg_replace('/[^a-z0-9 _-]/', '', strtolower($title));
        $fname = preg_replace('/\s+/', '_', trim($fname)) . '.pdf';
        $out[] = [
            'title'    => $title ?: $fname,
            'url'      => $u,
            'size'     => (int)($it['sizeInBytes'] ?? 0),
            'filename' => $fname,
        ];
    }
    return $out;
}

// Allowed hosts for topo PDF downloads (defense against arbitrary-URL queueing).
function topo_url_allowed(string $url): bool {
    $host = strtolower((string)parse_url($url, PHP_URL_HOST));
    if ($host === '') return false;
    foreach (['nationalmap.gov', 'usgs.gov', 'amazonaws.com'] as $ok) {
        if ($host === $ok || str_ends_with($host, '.' . $ok)) return true;
    }
    return false;
}
