<?php
/*
 * shared/identity.php — session identity helpers for #68.
 *
 * Reads/writes:
 *   $_SESSION['admin']          (existing) — password-gated admin superuser
 *   $_SESSION['admin_name']     (existing) — admin display name
 *   $_SESSION['reg_user_id']    (new)      — registry row id of signed-in user
 *   $_SESSION['reg_name']       (existing) — registry display name
 *   $_SESSION['reg_roles']      (new)      — CSV roles from registry row
 *   $_SESSION['reg_admin']      (existing) — legacy registry-side admin flag
 *
 * Schema: ensures `roles` TEXT column exists on registry table (idempotent).
 */

require_once __DIR__ . '/capabilities.php';

function _identity_ensure_schema(): void {
    static $done = false;
    if ($done) return;
    try {
        $db = new PDO('sqlite:/var/lib/noosphere/registry.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $cols = $db->query("PRAGMA table_info(registry)")->fetchAll(PDO::FETCH_ASSOC);
        $have = array_column($cols, 'name');
        if (!in_array('roles', $have, true)) {
            $db->exec("ALTER TABLE registry ADD COLUMN roles TEXT DEFAULT ''");
        }
    } catch (Throwable $e) {
        error_log('identity schema: ' . $e->getMessage());
    }
    $done = true;
}
_identity_ensure_schema();

/*
 * current_user() — associative array describing the current session, or [] if anonymous.
 * Keys: id, name, roles (array), is_admin (bool), source ('admin'|'registry'|'').
 */
function current_user(): array {
    if (!empty($_SESSION['admin'])) {
        return [
            'id'       => 0,
            'name'     => $_SESSION['admin_name'] ?? 'admin',
            'roles'    => known_roles(),  // admin holds all roles implicitly
            'is_admin' => true,
            'source'   => 'admin',
        ];
    }
    if (!empty($_SESSION['reg_user_id'])) {
        $roles = array_values(array_filter(array_map('trim', explode(',', $_SESSION['reg_roles'] ?? ''))));
        return [
            'id'       => (int)$_SESSION['reg_user_id'],
            'name'     => $_SESSION['reg_name'] ?? '',
            'roles'    => $roles,
            'is_admin' => !empty($_SESSION['reg_admin']),
            'source'   => 'registry',
        ];
    }
    return [];
}

function current_roles(): array {
    $u = current_user();
    return $u['roles'] ?? [];
}

function current_name(): string {
    $u = current_user();
    return $u['name'] ?? '';
}

/*
 * sign_in_registry_user($row) — promotes a registry row to a logged-in session.
 * $row is a registry table row (PDO assoc fetch). Caller is responsible for
 * having verified the PIN.
 */
function sign_in_registry_user(array $row): void {
    $_SESSION['reg_user_id'] = (int)$row['id'];
    $_SESSION['reg_name']    = $row['name'];
    $_SESSION['reg_roles']   = $row['roles'] ?? '';
    if (!empty($row['is_admin'])) $_SESSION['reg_admin'] = true;
    session_regenerate_id(true);
}

function sign_out_registry_user(): void {
    unset($_SESSION['reg_user_id'], $_SESSION['reg_name'], $_SESSION['reg_roles'], $_SESSION['reg_admin']);
    session_regenerate_id(true);
}

/*
 * Backwards-compat shim — modules that still test $is_admin should keep working.
 * After Phase D (gate migration) we can drop this.
 */
function legacy_is_admin(): bool {
    return !empty($_SESSION['admin']) || !empty($_SESSION['reg_admin']);
}
