<?php
/**
 * S.P.O.T.-IT — Get User Settings API
 * auth/get_settings.php
 *
 * GET endpoint. Returns JSON. Any logged-in user — scoped to their own
 * account only ($_SESSION['user_id']), never a client-supplied id.
 *
 * Phase 2B: pages/settings.php rendered every toggle from a hardcoded PHP
 * default on every load, so even a successful save (once save existed)
 * would appear to vanish on reload. This is the other half of that fix:
 * the real saved value, from spotit_user_db.user_settings, or the same
 * defaults settings.php itself used to hardcode if the user has never
 * saved before (new accounts get no row — see services/user/schema.sql).
 *
 * MICROSERVICES: Reads spotit_user_db only.
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

ms_require_auth('../pages/login.php');
$userId = (int) $_SESSION['user_id'];

// Keep in sync with the hardcoded defaults in pages/settings.php's
// $emailSettings/$dashSettings/$privSettings/$displaySettings/$secSettings —
// these are what a user who has never saved before currently sees.
$defaults = [
    'notif_email_alerts'   => true,
    'notif_email_claims'   => true,
    'notif_email_security' => true,
    'notif_email_announce' => false,
    'notif_dash_alerts'    => true,
    'notif_dash_sounds'    => true,
    'notif_dash_claims'    => true,
    'notif_dash_status'    => false,
    'privacy_show_thread'  => true,
    'privacy_show_id'      => false,
    'privacy_show_status'  => true,
    'privacy_show_history' => true,
    'ui_compact_mode'      => false,
    'ui_animate_timers'    => true,
    'ui_show_cctv_hud'     => true,
    'ui_auto_refresh'      => true,
    'sec_login_alerts'     => true,
    'sec_session_timeout'  => false,
    'ui_theme'             => 'light',
];

try {
    $stmt = $userPdo->prepare('SELECT settings_json FROM user_settings WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    $saved = [];
    if ($row) {
        $decoded = json_decode((string) $row['settings_json'], true);
        if (is_array($decoded)) {
            $saved = $decoded;
        }
    }

    ms_json(['success' => true, 'settings' => array_merge($defaults, $saved)]);
} catch (Throwable $e) {
    ms_json(['success' => false, 'message' => 'Failed to load settings.'], 500);
}
