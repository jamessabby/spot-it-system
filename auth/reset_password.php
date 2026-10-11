<?php
/**
 * S.P.O.T.-IT — Password reset, step 3: set the new password
 * auth/reset_password.php
 *
 * POST { email, reset_token, password, confirm_password, csrf_token }.
 *
 * This is the endpoint that actually changes a credential. Before 2026-08-22
 * pages/forgot-password.php's final step never read the new password from the
 * DOM and never transmitted it anywhere — it just advanced to a success screen
 * while the password stayed exactly as it was.
 *
 * MICROSERVICES: spotit_auth_db only.
 */

require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

if (!spotit_csrf_verify($_POST['csrf_token'] ?? null)) {
    ms_json([
        'success' => false,
        'csrf_invalid' => true,
        'message' => 'Your session expired. Please reload the page and try again.',
    ], 403);
}

$email    = strtolower(trim($_POST['email'] ?? ''));
$token    = trim($_POST['reset_token'] ?? '');
$password = (string) ($_POST['password'] ?? '');
$confirm  = (string) ($_POST['confirm_password'] ?? '');

if (!$email || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    ms_json(['success' => false, 'expired' => true,
             'message' => 'This reset session is no longer valid. Start again.']);
}

// Enforced server-side, matching the requirement checklist the page shows.
// The old page displayed those four rules and enforced none of them anywhere
// that mattered.
if (strlen($password) < 8) {
    ms_json(['success' => false, 'message' => 'Password must be at least 8 characters.']);
}
if (!preg_match('/[A-Z]/', $password)) {
    ms_json(['success' => false, 'message' => 'Password must contain an uppercase letter.']);
}
if (!preg_match('/\d/', $password)) {
    ms_json(['success' => false, 'message' => 'Password must contain a number.']);
}
if (!preg_match('/[^A-Za-z0-9]/', $password)) {
    ms_json(['success' => false, 'message' => 'Password must contain a special character.']);
}
if ($password !== $confirm) {
    ms_json(['success' => false, 'message' => 'Passwords do not match.']);
}

try {
    $stmt = $authPdo->prepare(
        "SELECT id, email, expires_at
           FROM password_resets
          WHERE email = ? AND reset_token = ? AND used_at IS NULL
          LIMIT 1"
    );
    $stmt->execute([$email, $token]);
    $row = $stmt->fetch();

    if (!$row || strtotime((string) $row['expires_at']) < time()) {
        ms_json(['success' => false, 'expired' => true,
                 'message' => 'This reset session has expired. Request a new code.']);
    }

    $userStmt = $authPdo->prepare("SELECT id, is_active FROM users WHERE email = ? LIMIT 1");
    $userStmt->execute([$email]);
    $user = $userStmt->fetch();

    if (!$user || !$user['is_active']) {
        ms_json(['success' => false, 'message' => 'That account is not available. Contact an administrator.']);
    }

    $authPdo->beginTransaction();

    $authPdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?")
            ->execute([password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $user['id']]);

    // Burn the request. Marked used rather than deleted so the row survives
    // long enough to be an audit trace of when the password last changed.
    $authPdo->prepare("UPDATE password_resets SET used_at = NOW(), reset_token = NULL WHERE id = ?")
            ->execute([$row['id']]);

    // Any other pending request for this address dies too — otherwise a second
    // code someone requested earlier would still open the door afterwards.
    $authPdo->prepare("DELETE FROM password_resets WHERE email = ? AND used_at IS NULL")
            ->execute([$email]);

    // Clear the brute-force lockout: the legitimate owner just proved control
    // of the mailbox, so making them also wait out a lockout is pure friction.
    ms_clear_attempts($authPdo, $email);

    $authPdo->commit();

    try {
        $monitorPdo->prepare(
            "INSERT INTO monitoring_logs (event_type, event_message, logged_at)
             VALUES ('password_reset', ?, NOW())"
        )->execute(["Password reset completed for {$email}"]);
    } catch (Throwable $e) { /* non-critical */ }

    ms_json(['success' => true, 'message' => 'Password updated. You can sign in with your new password.']);

} catch (Throwable $e) {
    if ($authPdo->inTransaction()) {
        $authPdo->rollBack();
    }
    error_log('[SPOT-IT] reset_password failed: ' . $e->getMessage());
    ms_json(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
