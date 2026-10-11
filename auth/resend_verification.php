<?php
/**
 * S.P.O.T.-IT — Resend the signup confirmation link
 * auth/resend_verification.php
 *
 * POST { email, csrf_token }. Returns JSON.
 *
 * Reached from the sign-in page after login is refused for an unverified
 * account, and after a signup whose email failed to send.
 *
 * ENUMERATION: the response is identical whether or not the address exists, is
 * already verified, or was throttled. Anything else turns this into a free
 * "does this person have an account?" oracle for an unauthenticated caller.
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

// The uniform reply. Every path below returns exactly this.
$uniform = [
    'success' => true,
    'message' => 'If that address needs confirming, a new link is on its way. Check your inbox and spam folder.',
];

if (!$email || !ms_is_dlsud_email($email)) {
    ms_json($uniform);
}

try {
    $stmt = $authPdo->prepare(
        "SELECT id, full_name, email, email_verified, verification_sent_at, is_active
           FROM users WHERE email = ? LIMIT 1"
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // Unknown address, already verified, or disabled — say nothing either way.
    if (!$user || (int) $user['email_verified'] === 1 || !$user['is_active']) {
        ms_json($uniform);
    }

    // Throttle: one link per minute per account. Without this the endpoint is a
    // free mail cannon pointed at any @dlsud.edu.ph address someone can guess,
    // and would burn the sending domain's reputation in an afternoon.
    $lastSent = strtotime((string) $user['verification_sent_at']);
    if ($lastSent !== false && (time() - $lastSent) < 60) {
        ms_json($uniform);
    }

    $mail = spotit_send_verification_email($authPdo, (int) $user['id'], $user['email'], (string) $user['full_name']);
    if (!$mail['ok']) {
        // Logged by spotit_mail() already. The caller still gets the uniform
        // reply — a mail-provider outage is not the visitor's problem to debug,
        // and reporting it here would leak that the address exists.
        error_log('[SPOT-IT] resend_verification: send failed for ' . $email . ': ' . $mail['error']);
    }

} catch (Throwable $e) {
    error_log('[SPOT-IT] resend_verification failed: ' . $e->getMessage());
}

ms_json($uniform);
