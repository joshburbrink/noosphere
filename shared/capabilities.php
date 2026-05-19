<?php
/*
 * shared/capabilities.php  -  central role/capability map for #68.
 *
 * Modules call can('weather.set_freq') or require_capability('weather.set_freq')
 * instead of `if ($is_admin)`. Admin (the password-protected $_SESSION['admin']
 * flag) always passes  -  admin is the superuser.
 *
 * Roles are stored on registry rows as a CSV in the `roles` column.
 * Known roles: operator, shelter_staff, sar, medical, comms, volunteer.
 *
 * To add a new gated action: add the cap key here and call require_capability()
 * at the top of the handler.
 */

function capability_map(): array {
    return [
        // Weather / NWR
        'weather.set_freq'      => ['operator', 'comms'],
        'weather.scan'          => ['operator', 'comms'],
        'weather.delete_log'    => ['operator', 'comms'],

        // Radio / SDR
        'radio.control'         => ['operator', 'comms'],
        'radio.program'         => ['operator', 'comms'],
        'radio.delete_log'      => ['operator', 'comms'],

        // Incidents
        'incidents.edit'        => ['operator', 'sar', 'medical'],
        'incidents.delete'      => ['operator'],
        'incidents.resolve'     => ['operator', 'sar', 'medical'],

        // Damage (legacy -> incidents, kept for /damage/ redirect compatibility)
        'damage.edit'           => ['operator'],
        'damage.delete'         => ['operator'],

        // Triage
        'triage.edit'           => ['medical', 'operator'],

        // Registry
        'registry.edit_any'     => ['operator', 'shelter_staff'],
        'registry.delete'       => ['operator'],

        // Supplies / Tools / Seeds
        'supplies.edit'         => ['operator', 'shelter_staff'],
        'tools.lend'            => ['operator', 'shelter_staff'],
        'seeds.edit'            => ['operator'],

        // Wiki
        'wiki.edit'             => ['operator', 'volunteer'],
        'wiki.delete'           => ['operator'],
        'wiki.lock'             => ['operator'],

        // Tasks / Runners
        'tasks.manage'          => ['operator'],
        'runners.dispatch'      => ['operator'],
        'runners.manage'        => ['operator'],
        'tools.manage'          => ['operator', 'shelter_staff'],

        // Files
        'files.upload'          => ['operator', 'volunteer'],
        'files.delete'          => ['operator'],

        // Forum
        'forum.moderate'        => ['operator'],

        // Maps
        'maps.edit_layers'      => ['operator'],

        // Command dashboard (#76)
        'command.view'          => ['operator', 'command'],
    ];
}

/*
 * can($cap)  -  true if the current session is allowed to perform $cap.
 * Admin always wins. Unknown caps default-deny (returns false, logs warning
 * to error_log for the developer who forgot to register the cap).
 */
function can(string $cap): bool {
    if (!empty($_SESSION['admin'])) return true;

    $map = capability_map();
    if (!array_key_exists($cap, $map)) {
        error_log("noosphere: unknown capability '$cap'  -  default-deny. Add it to shared/capabilities.php.");
        return false;
    }
    $allowed = $map[$cap];
    $roles   = current_roles();
    foreach ($roles as $r) if (in_array($r, $allowed, true)) return true;
    return false;
}

function require_capability(string $cap): void {
    if (can($cap)) return;
    http_response_code(403);
    $msg = empty(current_user())
        ? 'This action requires sign-in. Go to <a href="/registry/login.php" style="color:#4fc3f7">Sign In</a>.'
        : 'You do not have permission for this action.';
    die('<p style="font-family:sans-serif;padding:2rem;color:#e94560;background:#0f0f1a;min-height:100vh;margin:0">' . $msg . '</p>');
}

function known_roles(): array {
    return ['operator', 'command', 'shelter_staff', 'sar', 'medical', 'comms', 'volunteer'];
}
