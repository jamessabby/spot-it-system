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
    "SELECT id, full_name, email, password_hash, role, is_active
     FROM users WHERE email = ? LIMIT 1"
);
$stmt->execute([$email]);
$user = $stmt->fetch();

// ── 5. Verify credentials ────────────────────────────────────────────────────
if (!$user || !password_verify($password, $user['password_hash'])) {
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

// ── 7. Successful login ──────────────────────────────────────────────────────
ms_clear_attempts($authPdo, $email);
ms_set_session($user);

// Log the login to monitoring_logs (optional, non-blocking)
try {
    $monitorPdo->prepare(
        "INSERT INTO monitoring_logs (event_type, event_message, logged_at)
         VALUES ('user_login', ?, NOW())"
    )->execute(["User login: {$user['email']} (role: {$user['role']})"]);
} catch (Throwable $e) { /* non-critical */ }

$redirect = match($user['role']) {
    'admin'  => 'dashboard-admin.php',
    'staff'  => 'dashboard-staff.php',
    default  => 'dashboard-student.php',
};

ms_json(['success' => true, 'redirect' => $redirect, 'role' => $user['role']]);
