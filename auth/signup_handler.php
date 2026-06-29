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

// ── 1. Collect & sanitize inputs ─────────────────────────────────────────────
$full_name = trim($_POST['full_name'] ?? '');
$email     = strtolower(trim($_POST['email']     ?? ''));
$id_number = trim($_POST['id_number'] ?? '');
$password  = $_POST['password']  ?? '';
$confirm   = $_POST['confirm_password'] ?? '';
$role      = trim($_POST['role'] ?? 'student');

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

// Only allow student and staff self-registration — admin is provisioned manually
$allowed_roles = ['student', 'staff'];
if (!in_array($role, $allowed_roles, true)) {
    $role = 'student';
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

    $authPdo->prepare(
        "INSERT INTO users (full_name, email, id_number, password_hash, role, auth_provider, is_active, created_at)
         VALUES (?, ?, ?, ?, ?, 'manual', 1, NOW())"
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

ms_json([
    'success'  => true,
    'message'  => 'Account created successfully.',
    'redirect' => 'login.php?registered=1',
]);
