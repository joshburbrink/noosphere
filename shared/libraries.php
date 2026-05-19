<?php
// Custom-library helpers (issue #79).
//
// A "library" is an operator-defined inventory (book lending, food pantry,
// seed exchange, etc.) backed by a generic schema. Item-specific fields are
// stored as JSON keyed by the library's field_schema, so no per-library
// migrations.
//
// The 3 built-in modules (supplies/seeds/tools) are NOT migrated here; they
// have domain-specific features. Custom libraries cover the long tail.

require_once __DIR__ . '/security.php';
require_once __DIR__ . '/identity.php';

define('LIBRARIES_DB', '/var/lib/noosphere/libraries.db');

function lib_db(): PDO {
    static $db = null;
    if ($db) return $db;
    $db = new PDO('sqlite:' . LIBRARIES_DB);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec("PRAGMA journal_mode=WAL");
    $db->exec("CREATE TABLE IF NOT EXISTS libraries (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        slug          TEXT UNIQUE NOT NULL,
        name          TEXT NOT NULL,
        icon          TEXT NOT NULL DEFAULT '📚',
        description   TEXT NOT NULL DEFAULT '',
        field_schema  TEXT NOT NULL DEFAULT '[]',
        features      TEXT NOT NULL DEFAULT '{}',
        visibility    TEXT NOT NULL DEFAULT 'public',
        sort_order    INTEGER NOT NULL DEFAULT 100,
        enabled       INTEGER NOT NULL DEFAULT 1,
        created_at    INTEGER NOT NULL
    )");
    $db->exec("CREATE TABLE IF NOT EXISTS library_items (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        library_id  INTEGER NOT NULL,
        name        TEXT NOT NULL,
        data        TEXT NOT NULL DEFAULT '{}',
        photo       TEXT NOT NULL DEFAULT '',
        qty         INTEGER NOT NULL DEFAULT 1,
        qty_out     INTEGER NOT NULL DEFAULT 0,
        created_at  INTEGER NOT NULL,
        updated_at  INTEGER NOT NULL,
        FOREIGN KEY (library_id) REFERENCES libraries(id) ON DELETE CASCADE
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_items_lib ON library_items(library_id)");
    $db->exec("CREATE TABLE IF NOT EXISTS library_checkouts (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        library_id      INTEGER NOT NULL,
        item_id         INTEGER NOT NULL,
        borrower_name   TEXT NOT NULL,
        borrower_note   TEXT NOT NULL DEFAULT '',
        checked_out_at  INTEGER NOT NULL,
        expected_return INTEGER,
        returned_at     INTEGER,
        FOREIGN KEY (item_id) REFERENCES library_items(id) ON DELETE CASCADE
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_checkouts_item ON library_checkouts(item_id)");
    return $db;
}

function lib_default_features(): array {
    return [
        'lending'           => false,
        'quantities'        => false,
        'photos'            => false,
        'public_submission' => false,
        'search'            => true,
    ];
}

function lib_field_types(): array {
    return ['text','textarea','number','select','checkbox','date','tags'];
}

function list_libraries(bool $include_disabled = false): array {
    $sql = "SELECT * FROM libraries";
    if (!$include_disabled) $sql .= " WHERE enabled=1";
    $sql .= " ORDER BY sort_order, name";
    return lib_db()->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}

function get_library_by_slug(string $slug): ?array {
    $s = lib_db()->prepare("SELECT * FROM libraries WHERE slug=?");
    $s->execute([$slug]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function get_library(int $id): ?array {
    $s = lib_db()->prepare("SELECT * FROM libraries WHERE id=?");
    $s->execute([$id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function lib_features(array $library): array {
    $f = json_decode($library['features'] ?? '{}', true) ?: [];
    return array_merge(lib_default_features(), $f);
}

function lib_schema(array $library): array {
    $s = json_decode($library['field_schema'] ?? '[]', true);
    return is_array($s) ? $s : [];
}

function lib_slugify(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    $s = trim($s, '-');
    return substr($s, 0, 48);
}

function create_library(array $data): int {
    $slug = lib_slugify($data['slug'] ?? $data['name'] ?? '');
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,47}$/', $slug)) {
        throw new RuntimeException('Invalid slug');
    }
    $features = array_intersect_key(
        array_merge(lib_default_features(), $data['features'] ?? []),
        lib_default_features()
    );
    $schema = lib_normalize_schema($data['field_schema'] ?? []);
    $s = lib_db()->prepare("INSERT INTO libraries
        (slug,name,icon,description,field_schema,features,visibility,sort_order,enabled,created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?)");
    $s->execute([
        $slug,
        trim($data['name'] ?? $slug),
        trim($data['icon'] ?? '📚') ?: '📚',
        trim($data['description'] ?? ''),
        json_encode($schema, JSON_UNESCAPED_UNICODE),
        json_encode($features),
        in_array($data['visibility'] ?? 'public', ['public','members','operators'], true) ? $data['visibility'] : 'public',
        (int)($data['sort_order'] ?? 100),
        empty($data['disabled']) ? 1 : 0,
        time(),
    ]);
    return (int)lib_db()->lastInsertId();
}

function update_library(int $id, array $data): void {
    $lib = get_library($id);
    if (!$lib) throw new RuntimeException('Library not found');
    $features = array_intersect_key(
        array_merge(lib_features($lib), $data['features'] ?? []),
        lib_default_features()
    );
    $schema = isset($data['field_schema']) ? lib_normalize_schema($data['field_schema']) : lib_schema($lib);
    $s = lib_db()->prepare("UPDATE libraries SET
        name=?, icon=?, description=?, field_schema=?, features=?, visibility=?, sort_order=?, enabled=?
        WHERE id=?");
    $s->execute([
        trim($data['name'] ?? $lib['name']),
        trim($data['icon'] ?? $lib['icon']) ?: '📚',
        trim($data['description'] ?? $lib['description']),
        json_encode($schema, JSON_UNESCAPED_UNICODE),
        json_encode($features),
        $data['visibility'] ?? $lib['visibility'],
        (int)($data['sort_order'] ?? $lib['sort_order']),
        !empty($data['enabled']) ? 1 : 0,
        $id,
    ]);
}

function delete_library(int $id): void {
    lib_db()->prepare("DELETE FROM libraries WHERE id=?")->execute([$id]);
}

function lib_normalize_schema($schema): array {
    if (!is_array($schema)) return [];
    $out = [];
    $types = lib_field_types();
    foreach ($schema as $f) {
        if (!is_array($f)) continue;
        $key = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($f['key'] ?? '')));
        $key = trim($key, '_');
        if (!$key) continue;
        $type = in_array($f['type'] ?? '', $types, true) ? $f['type'] : 'text';
        $entry = [
            'key'      => substr($key, 0, 32),
            'label'    => substr(trim($f['label'] ?? $key), 0, 80),
            'type'     => $type,
            'required' => !empty($f['required']),
        ];
        if ($type === 'select') {
            $opts = is_array($f['options'] ?? null) ? $f['options'] : preg_split('/\s*[,\n]\s*/', (string)($f['options'] ?? ''));
            $opts = array_values(array_filter(array_map('trim', $opts)));
            $entry['options'] = array_slice($opts, 0, 40);
        }
        $out[] = $entry;
    }
    return $out;
}

// ─── Items ──────────────────────────────────────────────────────────────────

function get_items(int $library_id, string $q = ''): array {
    if ($q !== '') {
        $s = lib_db()->prepare("SELECT * FROM library_items WHERE library_id=? AND (name LIKE ? OR data LIKE ?) ORDER BY name");
        $like = '%' . $q . '%';
        $s->execute([$library_id, $like, $like]);
    } else {
        $s = lib_db()->prepare("SELECT * FROM library_items WHERE library_id=? ORDER BY name");
        $s->execute([$library_id]);
    }
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function get_item(int $id): ?array {
    $s = lib_db()->prepare("SELECT * FROM library_items WHERE id=?");
    $s->execute([$id]);
    $r = $s->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
}

function add_item(int $library_id, string $name, array $data, int $qty = 1, string $photo = ''): int {
    $s = lib_db()->prepare("INSERT INTO library_items (library_id,name,data,photo,qty,created_at,updated_at) VALUES (?,?,?,?,?,?,?)");
    $now = time();
    $s->execute([$library_id, trim($name), json_encode($data, JSON_UNESCAPED_UNICODE), $photo, max(1,$qty), $now, $now]);
    return (int)lib_db()->lastInsertId();
}

function update_item(int $id, string $name, array $data, int $qty = 1, ?string $photo = null): void {
    if ($photo === null) {
        $s = lib_db()->prepare("UPDATE library_items SET name=?, data=?, qty=?, updated_at=? WHERE id=?");
        $s->execute([trim($name), json_encode($data, JSON_UNESCAPED_UNICODE), max(1,$qty), time(), $id]);
    } else {
        $s = lib_db()->prepare("UPDATE library_items SET name=?, data=?, qty=?, photo=?, updated_at=? WHERE id=?");
        $s->execute([trim($name), json_encode($data, JSON_UNESCAPED_UNICODE), max(1,$qty), $photo, time(), $id]);
    }
}

function delete_item(int $id): void {
    lib_db()->prepare("DELETE FROM library_items WHERE id=?")->execute([$id]);
}

// ─── Checkouts ──────────────────────────────────────────────────────────────

function active_checkouts(int $item_id): array {
    $s = lib_db()->prepare("SELECT * FROM library_checkouts WHERE item_id=? AND returned_at IS NULL ORDER BY checked_out_at DESC");
    $s->execute([$item_id]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function library_active_checkouts(int $library_id): array {
    $s = lib_db()->prepare("SELECT c.*, i.name AS item_name FROM library_checkouts c
        JOIN library_items i ON i.id=c.item_id
        WHERE c.library_id=? AND c.returned_at IS NULL ORDER BY c.checked_out_at DESC");
    $s->execute([$library_id]);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

function checkout_item(int $library_id, int $item_id, string $borrower, string $note = '', ?int $expected_return = null): int {
    $item = get_item($item_id);
    if (!$item || $item['library_id'] != $library_id) throw new RuntimeException('Item not found');
    $available = max(0, (int)$item['qty'] - (int)$item['qty_out']);
    if ($available < 1) throw new RuntimeException('No copies available');
    $db = lib_db();
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE library_items SET qty_out = qty_out + 1, updated_at=? WHERE id=?")
           ->execute([time(), $item_id]);
        $db->prepare("INSERT INTO library_checkouts (library_id,item_id,borrower_name,borrower_note,checked_out_at,expected_return) VALUES (?,?,?,?,?,?)")
           ->execute([$library_id, $item_id, trim($borrower), trim($note), time(), $expected_return]);
        $id = (int)$db->lastInsertId();
        $db->commit();
        return $id;
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

function return_checkout(int $checkout_id): void {
    $db = lib_db();
    $s = $db->prepare("SELECT * FROM library_checkouts WHERE id=?");
    $s->execute([$checkout_id]);
    $c = $s->fetch(PDO::FETCH_ASSOC);
    if (!$c || $c['returned_at']) return;
    $db->beginTransaction();
    try {
        $db->prepare("UPDATE library_checkouts SET returned_at=? WHERE id=?")->execute([time(), $checkout_id]);
        $db->prepare("UPDATE library_items SET qty_out = MAX(qty_out - 1, 0), updated_at=? WHERE id=?")
           ->execute([time(), $c['item_id']]);
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
}

// ─── Per-library permission ─────────────────────────────────────────────────

function lib_can(array $library, string $action): bool {
    // Admin / operator always wins.
    if (!empty($_SESSION['admin'])) return true;
    $roles = function_exists('current_roles') ? current_roles() : [];
    if (in_array('operator', $roles, true)) return true;

    $features = lib_features($library);
    switch ($action) {
        case 'view':
            if ($library['visibility'] === 'public') return true;
            if ($library['visibility'] === 'members') return !empty(current_user());
            return false; // operators
        case 'add':
            if (!empty($features['public_submission']) && !empty(current_user())) return true;
            return false;
        case 'edit':
        case 'delete':
            return false; // operators only (handled above)
        case 'lend':
            return !empty($features['lending']) && !empty(current_user());
    }
    return false;
}
