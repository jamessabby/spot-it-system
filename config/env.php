<?php
/**
 * S.P.O.T.-IT — Environment Configuration
 *
 * Every environment-specific value below resolves in this order:
 *
 *     1. config/env.local.php   (gitignored — real credentials live here)
 *     2. a real environment variable of the same name
 *     3. the local XAMPP default hardcoded here
 *
 * That means this file can be committed and deployed unchanged: a checkout on
 * XAMPP keeps working with no setup, and Hostinger is configured purely by
 * dropping in env.local.php. Nothing outside config/ hardcodes a host or path.
 *
 * MICROSERVICES RULE: Each service has its OWN database and its OWN user.
 * (Kept as root/no-password locally to match XAMPP defaults — switch to the
 * dedicated per-service users + real passwords before any real deployment.)
 */

// ── Local override file ───────────────────────────────────────────────────────
// config/env.local.php must `return` an associative array. See env.local.example.php.
$GLOBALS['__spotit_local'] = [];
$__spotitLocalFile = __DIR__ . '/env.local.php';
if (is_readable($__spotitLocalFile)) {
    $__spotitLoaded = require $__spotitLocalFile;
    if (is_array($__spotitLoaded)) {
        $GLOBALS['__spotit_local'] = $__spotitLoaded;
    }
}
unset($__spotitLocalFile, $__spotitLoaded);

if (!function_exists('spotit_env')) {
    /**
     * Resolve one setting: env.local.php → real environment variable → default.
     */
    function spotit_env(string $key, $default = null)
    {
        if (array_key_exists($key, $GLOBALS['__spotit_local'])) {
            return $GLOBALS['__spotit_local'][$key];
        }
        $val = getenv($key);
        return ($val === false || $val === '') ? $default : $val;
    }
}

// ── Deployment mode ───────────────────────────────────────────────────────────
// 'local'  — web app and main.py on the same machine (XAMPP dev / offline demo).
//            The MJPEG proxy and direct ffmpeg capture are available.
// 'remote' — web app hosted, main.py on a mini-PC in the lab. The camera is
//            unreachable from here, so all camera work is done by the detector
//            and delivered over HTTP. See md_files/DEPLOYMENT_GUIDE.md.
define('SPOTIT_DEPLOY', spotit_env('SPOTIT_DEPLOY', 'local') === 'remote' ? 'remote' : 'local');
define('IS_REMOTE_DEPLOY', SPOTIT_DEPLOY === 'remote');

// ── Site Config ──────────────────────────────────────────────────────────────
define('SITE_URL',        rtrim(spotit_env('SITE_URL', 'http://localhost/spotit'), '/'));
define('SITE_NAME',       spotit_env('SITE_NAME', 'S.P.O.T.-IT'));
define('ALLOWED_DOMAIN',  spotit_env('ALLOWED_DOMAIN', '@dlsud.edu.ph'));
define('TIMEZONE',        spotit_env('TIMEZONE', 'Asia/Manila'));

date_default_timezone_set(TIMEZONE);

// ── Session cookie hardening ──────────────────────────────────────────────────
// Must be set BEFORE session_start() or it has no effect on the cookie.
//
//   httponly  — JavaScript cannot read the session id, so an XSS bug in any one
//               page cannot be escalated into stealing an admin session.
//   samesite  — 'Lax' blocks the cookie on cross-site POSTs, which is a second
//               line of defence behind the CSRF token in config/security.php.
//   secure    — only over HTTPS. Set dynamically rather than hardcoded: the
//               .htaccess HTTPS redirect deliberately exempts localhost, so
//               forcing it on would break every XAMPP login and the offline
//               defense demo.
//
// Guarded: several entry points include this file, and a second session_start()
// emits a warning that corrupts JSON responses.
if (session_status() === PHP_SESSION_NONE) {
    $__spotitHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => $__spotitHttps,
    ]);
    session_start();
    unset($__spotitHttps);
}

// CSRF + CAPTCHA helpers. Loaded here so every page and handler has them
// without a second include; needs the session, hence after session_start().
require_once __DIR__ . '/security.php';

// ── Microsoft OAuth (Azure AD) ────────────────────────────────────────────────
// MS_REDIRECT_URI derives from SITE_URL, so changing SITE_URL is enough — but the
// resulting production URI must also be registered in the Azure app registration.
define('MS_CLIENT_ID',     spotit_env('MS_CLIENT_ID',     'YOUR_AZURE_CLIENT_ID'));
define('MS_CLIENT_SECRET', spotit_env('MS_CLIENT_SECRET', 'YOUR_AZURE_CLIENT_SECRET'));
define('MS_TENANT_ID',     spotit_env('MS_TENANT_ID',     'YOUR_AZURE_TENANT_ID'));  // or 'common'
define('MS_REDIRECT_URI',  SITE_URL . '/auth/microsoft_callback.php');
define('MS_SCOPES',        'openid profile email User.Read');

// ── Rate Limiting ─────────────────────────────────────────────────────────────
define('RATE_LIMIT_WARN',     3);   // attempts before 30s cooldown
define('RATE_LIMIT_LOCKOUT',  5);   // attempts before 5 min lock
define('COOLDOWN_SHORT',     30);   // seconds
define('COOLDOWN_LONG',     300);   // seconds (5 minutes)

// ── Detection Timer Thresholds ────────────────────────────────────────────────
define('TIMER_POTENTIAL_MIN',  30);  // minutes → "Potentially Lost"
define('TIMER_CONFIRMED_MIN',  60);  // minutes → "Confirmed Missing"

// ── Sandbox / DESK camera ─────────────────────────────────────────────────────
// The DESK sandbox camera has no row in spotit_monitor_db.rooms to read from, so
// its address used to be hardcoded in four separate PHP files and three Python
// spots. A DHCP lease change (or a factory reset) then broke the system in
// several places at once, each with a different symptom. Defined once here.
//
// Production rooms do NOT use these — they read camera_ip / camera_user /
// camera_pass from the rooms table via auth/get_room_camera.php.
//
// If the address keeps moving, give the camera a DHCP reservation on the router
// (or a static IP in the Tapo app) instead of chasing it here.
define('DESK_CAMERA_IP',   spotit_env('DESK_CAMERA_IP',   '10.62.74.79'));
define('DESK_CAMERA_USER', spotit_env('DESK_CAMERA_USER', 'SpotItCamera'));
define('DESK_CAMERA_PASS', spotit_env('DESK_CAMERA_PASS', 'spotitcamera232'));
define('DESK_CAMERA_RTSP', 'rtsp://' . DESK_CAMERA_USER . ':' . DESK_CAMERA_PASS
                          . '@' . DESK_CAMERA_IP . ':554/stream1');

// ── DB Host ───────────────────────────────────────────────────────────────────
define('DB_HOST', spotit_env('DB_HOST', 'localhost'));

// ── Auth Service DB ───────────────────────────────────────────────────────────
define('AUTH_DB_NAME', spotit_env('AUTH_DB_NAME', 'spotit_auth_db'));
define('AUTH_DB_USER', spotit_env('AUTH_DB_USER', 'root'));
define('AUTH_DB_PASS', spotit_env('AUTH_DB_PASS', ''));

// ── Monitoring Service DB ─────────────────────────────────────────────────────
define('MONITOR_DB_NAME', spotit_env('MONITOR_DB_NAME', 'spotit_monitor_db'));
define('MONITOR_DB_USER', spotit_env('MONITOR_DB_USER', 'root'));
define('MONITOR_DB_PASS', spotit_env('MONITOR_DB_PASS', ''));

// ── Lost & Found Service DB ───────────────────────────────────────────────────
define('LF_DB_NAME', spotit_env('LF_DB_NAME', 'spotit_lf_db'));
define('LF_DB_USER', spotit_env('LF_DB_USER', 'root'));
define('LF_DB_PASS', spotit_env('LF_DB_PASS', ''));

// ── User Service DB ───────────────────────────────────────────────────────────
define('USER_DB_NAME', spotit_env('USER_DB_NAME', 'spotit_user_db'));
define('USER_DB_USER', spotit_env('USER_DB_USER', 'root'));
define('USER_DB_PASS', spotit_env('USER_DB_PASS', ''));

// ── Community Service DB (forum/announcements) ────────────────────────────────
define('COMMUNITY_DB_NAME', spotit_env('COMMUNITY_DB_NAME', 'spotit_community_db'));
define('COMMUNITY_DB_USER', spotit_env('COMMUNITY_DB_USER', 'root'));
define('COMMUNITY_DB_PASS', spotit_env('COMMUNITY_DB_PASS', ''));

// ── API / Cron Keys ────────────────────────────────────────────────────────────
// The detection key authenticates main.py. It MUST be rotated before going public
// — the default below is a placeholder that also ships in main.py so the sandbox
// works with no configuration.
define('SPOTIT_DETECTION_KEY', spotit_env('SPOTIT_DETECTION_KEY', 'CHANGE_ME_DETECTION_KEY'));
define('SPOTIT_CRON_KEY',      spotit_env('SPOTIT_CRON_KEY',      'CHANGE_ME_CRON_KEY'));

// ── Mail ──────────────────────────────────────────────────────────────────────
// Until 2026-08-22 these constants were read by NO code at all — the whole mail
// subsystem was six unused define()s. They are live now; see config/mailer.php.
//
// MAIL_DRIVER decides how mail leaves (or doesn't):
//   'log'    — default. Writes to logs/mail/ instead of sending. Keeps the
//              verification and password-reset flows fully testable on XAMPP
//              before a sending domain exists.
//   'mail'   — PHP's built-in mail(). Works out of the box on most shared
//              hosting; XAMPP has no MTA, so this does nothing locally.
//   'resend' — HTTPS to api.resend.com. Needs MAIL_API_KEY and a domain
//              verified in the Resend dashboard.
define('MAIL_DRIVER',   strtolower(spotit_env('MAIL_DRIVER', 'log')));

// MAIL_FROM must be on a domain you control and have verified with your
// provider. It is NOT @dlsud.edu.ph — that is the recipient domain; the
// university's mail servers would reject anything claiming to send as them.
define('MAIL_FROM',     spotit_env('MAIL_FROM', 'noreply@spotit.local'));
define('MAIL_FROM_NAME',spotit_env('MAIL_FROM_NAME', 'S.P.O.T.-IT System'));

// Resend (or any HTTP mail API) credential.
define('MAIL_API_KEY',  spotit_env('MAIL_API_KEY', 'CHANGE_ME_MAIL_API_KEY'));

// Retained for a future SMTP driver. Nothing reads them today — see
// config/mailer.php for why SMTP was not implemented (it needs PHPMailer,
// which needs composer, which is not installed).
define('MAIL_HOST',     spotit_env('MAIL_HOST', 'smtp.gmail.com'));
define('MAIL_PORT',     (int) spotit_env('MAIL_PORT', 587));
define('MAIL_USER',     spotit_env('MAIL_USER', 'noreply@dlsud.edu.ph'));
define('MAIL_PASS',     spotit_env('MAIL_PASS', 'CHANGE_ME_MAIL'));

require_once __DIR__ . '/mailer.php';

// How long a signup verification link stays valid, and a password-reset code.
define('VERIFY_LINK_TTL_HOURS', (int) spotit_env('VERIFY_LINK_TTL_HOURS', 24));
define('RESET_CODE_TTL_MIN',    (int) spotit_env('RESET_CODE_TTL_MIN', 10));

// ── Snapshot Upload Path ────────────────────────────────────────────────────────
define('SNAPSHOT_PATH', __DIR__ . '/../uploads/snapshots/');
define('SNAPSHOT_URL',  SITE_URL . '/uploads/snapshots/');

// ── Detector control-plane state ───────────────────────────────────────────────
// Server-side scratch space for the poll/push contract in auth/get_detector_config.php:
// pending commands, config version, last heartbeat, last live-view request.
// Kept out of the web root's reach by runtime/.htaccess.
define('RUNTIME_PATH', __DIR__ . '/../runtime/');

// How long after a dashboard requests the live view we keep telling the detector
// someone is watching. The detector pushes ~1 fps while true and drops to
// LIVE_FRAME_IDLE_SEC otherwise — this is what keeps bandwidth sane.
define('LIVE_VIEWER_TTL_SEC', 15);

// A detector that has not checked in for this long is shown as offline.
define('DETECTOR_OFFLINE_SEC', 30);
