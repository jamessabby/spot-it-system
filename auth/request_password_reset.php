<?php
/**
 * S.P.O.T.-IT — Password reset, step 1: request a code
 * auth/request_password_reset.php
 *
 * POST { email, csrf_token }. Returns JSON.
 *
 * Generates a 6-digit code, stores only its hash, and emails the code.
 * pages/forgot-password.php step 1 posts here.
 *
 * ENUMERATION: replies identically for a known and an unknown address.
 *
 * MICROSERVICES: spotit_auth_db only.
 */

require_once __DIR__ . '/service_bootstrap.php';
require_once __DIR__ . '/account_mail.php';

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

$email = strtolower(trim($_POST['email'] ?? ''));

$uniform = [
    'success' => true,
    'message' => 'If an account exists for that address, a reset code is on its way.',
];

if (!$email || !ms_is_dlsud_email($email)) {
    ms_json($uniform);
}

try {
    // Housekeeping: drop rows that can no longer be used. Cheap, and it keeps
    // the table from growing without bound since nothing else prunes it.
    $authPdo->prepare(
        "DELETE FROM password_resets
          WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY) OR used_at IS NOT NULL"
    )->execute();

    $stmt = $authPdo->prepare(
        "SELECT id, full_name, email, is_active, password_hash
           FROM users WHERE email = ? LIMIT 1"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // No account, disabled account, or a Microsoft-only account with no
    // password to reset — all silently indistinguishable from success.
    if (!$user || !$user['is_active'] || empty($user['password_hash'])) {
        ms_json($uniform);
    }

    // Throttle: at most 3 requests per address per 15 minutes.
    $recent = $authPdo->prepare(
        "SELECT COUNT(*) FROM password_resets
          WHERE email = ? AND created_at > DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
    );
    $recent->execute([$email]);
    if ((int) $recent->fetchColumn() >= 3) {
        ms_json($uniform);
    }

    // Any older pending request for this address is retired first, so only one
    // code is ever live per account.
    $authPdo->prepare("DELETE FROM password_resets WHERE email = ? AND used_at IS NULL")
            ->execute([$email]);

    // random_int, not rand: this is a credential.
    $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

    $authPdo->prepare(
        "INSERT INTO password_resets (email, code_hash, expires_at, requested_ip, created_at)
         VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? MINUTE), ?, NOW())"
    )->execute([
        $email,
        password_hash($code, PASSWORD_BCRYPT),
        RESET_CODE_TTL_MIN,
        substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
    ]);

    $mail = spotit_send_reset_code_email($email, (string) $user['full_name'], $code);
    if (!$mail['ok']) {
        error_log('[SPOT-IT] request_password_reset: send failed for ' . $email . ': ' . $mail['error']);
    }

} catch (Throwable $e) {
    error_log('[SPOT-IT] request_password_reset failed: ' . $e->getMessage());
}

ms_json($uniform);
