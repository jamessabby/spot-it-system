<?php
/**
 * S.P.O.T.-IT — Password reset, step 2: redeem the code
 * auth/verify_reset_code.php
 *
 * POST { email, code, csrf_token }. Returns JSON with a reset_token on success.
 *
 * WHY A SECOND TOKEN
 * Verifying the code does not change the password; it issues a separate random
 * reset_token that step 3 presents. Without that split, the final step would
 * have to re-send the 6-digit code, which would put it in a second request (and
 * a second server log line) and keep it live across both. The token is
 * long, random, single-use, and inherits the original expiry.
 *
 * Unlike steps 1 and 3, this one answers honestly: the caller already proved
 * they have the address by receiving the code, so a wrong-code message leaks
 * nothing they did not already know.
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

$email = strtolower(trim($_POST['email'] ?? ''));
$code  = preg_replace('/\D/', '', (string) ($_POST['code'] ?? ''));

if (!$email || strlen($code) !== 6) {
    ms_json(['success' => false, 'message' => 'Enter the 6-digit code from your email.']);
}

// Max wrong tries before the request is destroyed. 6 digits is a million
// combinations, but without a cap an unattended script would still get there.
const RESET_MAX_ATTEMPTS = 5;

try {
    $stmt = $authPdo->prepare(
        "SELECT id, code_hash, attempts, expires_at
           FROM password_resets
          WHERE email = ? AND used_at IS NULL
       ORDER BY id DESC LIMIT 1"
    );
    $stmt->execute([$email]);
    $row = $stmt->fetch();

    if (!$row) {
        ms_json(['success' => false, 'expired' => true,
                 'message' => 'That code is no longer valid. Request a new one.']);
    }

    if (strtotime((string) $row['expires_at']) < time()) {
        $authPdo->prepare("DELETE FROM password_resets WHERE id = ?")->execute([$row['id']]);
        ms_json(['success' => false, 'expired' => true,
                 'message' => 'That code has expired. Request a new one.']);
    }

    if ((int) $row['attempts'] >= RESET_MAX_ATTEMPTS) {
        $authPdo->prepare("DELETE FROM password_resets WHERE id = ?")->execute([$row['id']]);
        ms_json(['success' => false, 'expired' => true,
                 'message' => 'Too many incorrect attempts. Request a new code.']);
    }

    if (!password_verify($code, (string) $row['code_hash'])) {
        $authPdo->prepare("UPDATE password_resets SET attempts = attempts + 1 WHERE id = ?")
                ->execute([$row['id']]);
        $left = RESET_MAX_ATTEMPTS - ((int) $row['attempts'] + 1);
        ms_json([
            'success' => false,
            'message' => $left > 0
                ? "Incorrect code. {$left} attempt(s) left."
                : 'Too many incorrect attempts. Request a new code.',
        ]);
    }

    // Correct. Issue the token that authorises the change itself.
    $resetToken = bin2hex(random_bytes(32));
    $authPdo->prepare("UPDATE password_resets SET reset_token = ?, attempts = 0 WHERE id = ?")
            ->execute([$resetToken, $row['id']]);

    ms_json([
        'success'     => true,
        'reset_token' => $resetToken,
        'message'     => 'Code confirmed. Choose a new password.',
    ]);

} catch (Throwable $e) {
    error_log('[SPOT-IT] verify_reset_code failed: ' . $e->getMessage());
    ms_json(['success' => false, 'message' => 'Something went wrong. Please try again.'], 500);
}
