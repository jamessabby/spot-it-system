<?php
/**
 * S.P.O.T.-IT — Login Handler
 * auth/login_handler.php
 *
 * POST endpoint. Returns JSON.
 * MICROSERVICES: Only reads from spotit_auth_db via service_bootstrap.
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$email    = strtolower(trim($_POST['email']    ?? ''));
$password = trim($_POST['password'] ?? '');

// ── 0. CSRF ──────────────────────────────────────────────────────────────────
// Checked first, before any DB work. Until 2026-08-22 the token was emitted by
// the form and validated by nobody. 'csrf_invalid' lets the page fetch a fresh
// token and retry rather than showing the user a dead end after their session
// has simply expired in a background tab.
if (!spotit_csrf_verify($_POST['csrf_token'] ?? null)) {
    ms_json([
        'success' => false,
        'csrf_invalid' => true,
        'message' => 'Your session expired. Please reload the page and try again.',
    ], 403);   // 403, not 419: 419 is a Laravel convention, not an IANA code,
               // and Apache rewrites unknown codes to a bare 500 on the wire —
               // which would log a rejected CSRF token as a server fault.
}

// ── 0b. CAPTCHA ──────────────────────────────────────────────────────────────
// Server-side and single-use (config/security.php). The old widget was a
// setTimeout that posted nothing, so anything calling this endpoint directly
// bypassed it entirely.
$captcha = spotit_captcha_verify($_POST['captcha_answer'] ?? null);
if (!$captcha['ok']) {
    ms_json([
        'success' => false,
        'captcha_failed' => true,
        'message' => $captcha['error'],
    ]);
}

// ── 1. Domain check ─────────────────────────────────────────────────────────
if (!ms_is_dlsud_email($email)) {
    ms_json(['success' => false, 'message' => 'Only @dlsud.edu.ph email addresses are accepted.']);
}

// ── 2. Rate-limit check ──────────────────────────────────────────────────────
$lockStatus = ms_is_locked($authPdo, $email);
if ($lockStatus['locked']) {
    ms_json([
        'success'     => false,
        'locked'      => true,
        'seconds_left'=> $lockStatus['seconds_left'],
        'count'       => $lockStatus['count'],
        'message'     => 'Account temporarily locked. Try again later.',
    ]);
}

// ── 3. Input validation ──────────────────────────────────────────────────────
if (!$email || !$password) {
    ms_json(['success' => false, 'message' => 'Email and password are required.']);
}

// ── 4. Fetch user from auth DB ───────────────────────────────────────────────
$stmt = $authPdo->prepare(
    "SELECT id, full_name, email, password_hash, role, is_active, email_verified
     FROM users WHERE email = ? LIMIT 1"
);
$stmt->execute([$email]);
$user = $stmt->fetch();

// ── 5. Verify credentials ────────────────────────────────────────────────────
// password_hash is NULL for Microsoft-only accounts (see microsoft_callback.php).
// Passing NULL to password_verify() raises a deprecation notice in PHP 8.1+,
// which prints into the response body and corrupts the JSON the page is
// parsing — so short-circuit instead. The message stays generic on purpose:
// telling an anonymous caller "that address is a Microsoft account" would
// confirm the address exists.
if (!$user || empty($user['password_hash']) || !password_verify($password, $user['password_hash'])) {
    $result = ms_record_failed_attempt($authPdo, $email);
    $locked = ms_is_locked($authPdo, $email);

    ms_json([
        'success'     => false,
        'locked'      => $locked['locked'],
        'seconds_left'=> $locked['seconds_left'] ?? 0,
        'count'       => $result['attempt_count'],
        'message'     => 'Incorrect email or password.',
    ]);
}

// ── 6. Account active check ──────────────────────────────────────────────────
if (!$user['is_active']) {
    ms_json(['success' => false, 'message' => 'Your account is inactive. Contact an administrator.']);
}

// ── 6b. Email verification check ─────────────────────────────────────────────
// Deliberately AFTER the password check: telling an anonymous caller "that
// address exists but is unverified" before they prove they know the password
// would turn this into an account-enumeration oracle.
//
// Accounts that predate this feature were backfilled to verified by
// migration_email_verification.sql, so nobody is locked out retroactively.
if (!$user['email_verified']) {
    ms_json([
        'success'  => false,
        'unverified' => true,
        'email'    => $user['email'],
        'message'  => 'Please confirm your email address first. Check your inbox for the link we sent.',
    ]);
}

// ── 7. Successful login ──────────────────────────────────────────────────────
ms_clear_attempts($authPdo, $email);

// users.last_login_at has existed since the first schema and nothing ever wrote
// to it, so every account looked like it had never signed in. Non-blocking:
// failing to record a timestamp must not cost the user their login.
try {
    $authPdo->prepare("UPDATE users SET last_login_at = NOW() WHERE id = ?")
            ->execute([$user['id']]);
} catch (Throwable $e) { /* non-critical */ }

// Staff is treated as admin for UI/routing purposes (no separate staff
// dashboard/nav) — the DB role value is left untouched, only the session
// and redirect are normalized. See CLAUDE.md §2 for why.
$sessionUser = $user;
if ($sessionUser['role'] === 'staff') {
    $sessionUser['role'] = 'admin';
}
ms_set_session($sessionUser);

// Log the login to monitoring_logs (optional, non-blocking) — logs the
// REAL DB role, not the normalized one, so audit history stays accurate.
try {
    $monitorPdo->prepare(
        "INSERT INTO monitoring_logs (event_type, event_message, logged_at)
         VALUES ('user_login', ?, NOW())"
    )->execute(["User login: {$user['email']} (role: {$user['role']})"]);
} catch (Throwable $e) { /* non-critical */ }

$redirect = match($sessionUser['role']) {
    'admin'  => 'dashboard-admin.php',
    default  => 'dashboard-student.php',
};

ms_json(['success' => true, 'redirect' => $redirect, 'role' => $sessionUser['role']]);