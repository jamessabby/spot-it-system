<?php
/**
 * S.P.O.T.-IT — Save User Settings API
 * auth/save_settings.php
 *
 * POST endpoint. Returns JSON. Any logged-in user — writes only to their
 * own row ($_SESSION['user_id']), via an upsert since no row exists for an
 * account that has never saved before (signup never creates one — see
 * services/user/schema.sql's own comment that one should be, but isn't).
 *
 * Phase 2B: pages/settings.php's "Save All" button never called this or any
 * handler — it just hid the unsaved-changes bar and showed a success toast.
 * This is the real save behind it.
 *
 * Only the keys below are ever written. The System tab's four admin-only
 * toggles (sys_maintenance_mode, sys_debug_mode, sys_allow_registration,
 * sys_detection_active) and the four threshold number inputs are
 * deliberately NOT in this list and are not sent by the page's JS: they are
 * written in the UI to describe SITE-WIDE effects ("put the site in
 * maintenance mode"), but nothing in this schema or codebase stores or acts
 * on a site-wide flag, and user_settings is a per-user table — persisting
 * them here would just move the same false claim into the database instead
 * of removing it. Reported, not invented; see the Phase 2B session report.
 *
 * MICROSERVICES: Writes to spotit_user_db only.
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

ms_require_auth('../pages/login.php');
spotit_require_csrf();
$userId = (int) $_SESSION['user_id'];

// ── Whitelist: only these keys are ever written, regardless of what a
// caller sends. Boolean keys must decode to a real JSON boolean; ui_theme
// must be one of the three values the UI actually offers.
$BOOL_KEYS = [
    'notif_email_alerts', 'notif_email_claims', 'notif_email_security', 'notif_email_announce',
    'notif_dash_alerts', 'notif_dash_sounds', 'notif_dash_claims', 'notif_dash_status',
    'privacy_show_thread', 'privacy_show_id', 'privacy_show_status', 'privacy_show_history',
    'ui_compact_mode', 'ui_animate_timers', 'ui_show_cctv_hud', 'ui_auto_refresh',
    'sec_login_alerts', 'sec_session_timeout',
];
$THEME_VALUES = ['light', 'dark', 'system'];

$raw = json_decode((string) ($_POST['settings'] ?? ''), true);
if (!is_array($raw)) {
    ms_json(['success' => false, 'message' => 'Invalid settings payload.']);
}

$clean = [];
foreach ($BOOL_KEYS as $key) {
    if (array_key_exists($key, $raw) && is_bool($raw[$key])) {
        $clean[$key] = $raw[$key];
    }
}
if (array_key_exists('ui_theme', $raw) && in_array($raw['ui_theme'], $THEME_VALUES, true)) {
    $clean['ui_theme'] = $raw['ui_theme'];
}

// Nothing recognized survived validation — reject outright rather than
// writing an empty/no-op settings_json over whatever was last saved.
if (!$clean) {
    ms_json(['success' => false, 'message' => 'No valid settings in request.']);
}

try {
    // Merge onto the existing row so a partial save (e.g. only the
    // Notifications tab was touched) never erases unrelated keys saved
    // earlier from a different tab.
    $stmt = $userPdo->prepare('SELECT settings_json FROM user_settings WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $existingRow = $stmt->fetch();
    $existing = [];
    if ($existingRow) {
        $decoded = json_decode((string) $existingRow['settings_json'], true);
        if (is_array($decoded)) {
            $existing = $decoded;
        }
    }
    $merged = array_merge($existing, $clean);

    $userPdo->prepare(
        'INSERT INTO user_settings (user_id, settings_json, updated_at)
         VALUES (?, ?, NOW())
         ON DUPLICATE KEY UPDATE settings_json = VALUES(settings_json), updated_at = NOW()'
    )->execute([$userId, json_encode($merged, JSON_UNESCAPED_UNICODE)]);

    ms_json(['success' => true, 'settings' => $merged]);
} catch (Throwable $e) {
    ms_json(['success' => false, 'message' => 'Failed to save settings. Please try again.'], 500);
}
