<?php
/**
 * S.P.O.T.-IT — Signup Handler
 * auth/signup_handler.php
 *
 * POST endpoint. Returns JSON.
 * MICROSERVICES: Writes to spotit_auth_db and spotit_user_db only.
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

// ── 0. CSRF + CAPTCHA ────────────────────────────────────────────────────────
// Both were UI-only before 2026-08-22: the token was emitted and never checked,
// the CAPTCHA was a setTimeout that posted nothing. See config/security.php.
if (!spotit_csrf_verify($_POST['csrf_token'] ?? null)) {
    ms_json([
        'success' => false,
        'csrf_invalid' => true,
        'message' => 'Your session expired. Please reload the page and try again.',
    ], 403);   // 403, not 419: 419 is a Laravel convention, not an IANA code,
               // and Apache rewrites unknown codes to a bare 500 on the wire —
               // which would log a rejected CSRF token as a server fault.
}

$captcha = spotit_captcha_verify($_POST['captcha_answer'] ?? null);
if (!$captcha['ok']) {
    ms_json([
        'success' => false,
        'captcha_failed' => true,
        'message' => $captcha['error'],
    ]);
}

// ── 1. Collect & sanitize inputs ─────────────────────────────────────────────
$full_name = trim($_POST['full_name'] ?? '');
$email     = strtolower(trim($_POST['email']     ?? ''));
$id_number = trim($_POST['id_number'] ?? '');
$password  = $_POST['password']  ?? '';
$confirm   = $_POST['confirm_password'] ?? '';

// PRIVILEGE ESCALATION FIX (2026-08-22).
// $_POST['role'] used to be accepted with a ['student','staff'] whitelist, and
// the signup page offered "Staff" in a role picker. Because login_handler.php
// normalises the 'staff' role to an 'admin' SESSION (CLAUDE.md §2), anyone on
// the internet could register themselves straight into the admin dashboard —
// room data, user management, system logs, the donation purge.
//
// Self-registration is now ALWAYS 'student'. The submitted value is ignored
// rather than validated, so re-adding a picker to the form cannot silently
// re-open this. Staff and admin are provisioned by an existing admin, which is
// what CLAUDE.md §2 said all along.
$role = 'student';

// ── 2. Validation ─────────────────────────────────────────────────────────────
if (!$full_name || !$email || !$id_number || !$password || !$confirm) {
    ms_json(['success' => false, 'message' => 'All fields are required.']);
}

if (!ms_is_dlsud_email($email)) {
    ms_json(['success' => false, 'message' => 'Only @dlsud.edu.ph email addresses are accepted.']);
}

if (strlen($password) < 8) {
    ms_json(['success' => false, 'message' => 'Password must be at least 8 characters.']);
}

if ($password !== $confirm) {
    ms_json(['success' => false, 'message' => 'Passwords do not match.']);
}

// Validate ID number format (YYYY-NNNNN)
if (!preg_match('/^\d{4}-\d{4,6}$/', $id_number)) {
    ms_json(['success' => false, 'message' => 'Invalid university ID format. Use YYYY-NNNNN (e.g. 2021-00001).']);
}

// ── 3. Check if email already exists ─────────────────────────────────────────
$stmt = $authPdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    ms_json(['success' => false, 'message' => 'An account with this email already exists. Please sign in instead.']);
}

// ── 4. Check if ID number already registered ─────────────────────────────────
$stmt = $authPdo->prepare("SELECT id FROM users WHERE id_number = ? LIMIT 1");
$stmt->execute([$id_number]);
if ($stmt->fetch()) {
    ms_json(['success' => false, 'message' => 'This university ID is already linked to an existing account.']);
}

// ── 5. Hash password ──────────────────────────────────────────────────────────
$password_hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

// ── 6. Insert into auth DB ────────────────────────────────────────────────────
try {
    $authPdo->beginTransaction();

    // email_verified = 0. is_active stays 1: 'inactive' is the admin's
    // disable switch and carries its own message ("contact an administrator"),
    // which is the wrong thing to tell someone who just needs to click a link.
    // The two states are separate on purpose and login_handler checks both.
    $authPdo->prepare(
        "INSERT INTO users (full_name, email, id_number, password_hash, role, auth_provider, is_active, email_verified, created_at)
         VALUES (?, ?, ?, ?, ?, 'manual', 1, 0, NOW())"
    )->execute([$full_name, $email, $id_number, $password_hash, $role]);

    $new_user_id = (int)$authPdo->lastInsertId();

    $authPdo->commit();
} catch (PDOException $e) {
    $authPdo->rollBack();
    ms_json(['success' => false, 'message' => 'Registration failed. Please try again.', 'detail' => $e->getMessage()], 500);
}

// ── 7. Create user profile in user DB (non-blocking) ─────────────────────────
try {
    $userPdo->prepare(
        "INSERT INTO user_profiles (user_id, name, email, role, created_at)
         VALUES (?, ?, ?, ?, NOW())"
    )->execute([$new_user_id, $full_name, $email, $role]);
} catch (Throwable $e) {
    // Non-critical — profile can be created later
    error_log('[S.P.O.T.-IT] User profile creation failed for user_id=' . $new_user_id . ': ' . $e->getMessage());
}

// ── 8. Log registration in monitoring logs (non-blocking) ─────────────────────
try {
    $monitorPdo->prepare(
        "INSERT INTO monitoring_logs (event_type, event_message, logged_at)
         VALUES ('user_registered', ?, NOW())"
    )->execute(["New {$role} registered: {$email}"]);
} catch (Throwable $e) { /* non-critical */ }

// ── 9. Send the verification link ─────────────────────────────────────────────
// The account exists either way. If mail fails we say so plainly and point at
// the resend button rather than pretending the email is on its way — the old
// forgot-password page's habit of claiming success is exactly what made it
// useless (BUGS.md §1).
require_once __DIR__ . '/account_mail.php';
$mail = spotit_send_verification_email($authPdo, $new_user_id, $email, $full_name);

if (!$mail['ok']) {
    ms_json([
        'success'      => true,
        'mail_failed'  => true,
        'needs_verify' => true,
        'message'      => 'Account created, but we could not send the confirmation email. '
                        . 'Use "Resend confirmation" on the sign-in page.',
        'redirect'     => 'login.php?registered=1&mail_failed=1',
    ]);
}

ms_json([
    'success'      => true,
    'needs_verify' => true,
    'message'      => 'Account created. Check your DLSU-D email for the confirmation link.',
    'redirect'     => 'login.php?registered=1',
]);
