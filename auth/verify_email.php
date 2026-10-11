<?php
/**
 * S.P.O.T.-IT — Email verification link handler
 * auth/verify_email.php
 *
 * GET ?token=... — the target of the link in the signup email.
 *
 * Renders a small standalone page rather than returning JSON: this is opened
 * directly from a mail client, so there is no page around it to show a toast.
 *
 * MICROSERVICES: spotit_auth_db only.
 */

require_once __DIR__ . '/service_bootstrap.php';

$token  = trim($_GET['token'] ?? '');
$state  = 'invalid';     // invalid | expired | already | ok
$name   = '';

if ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token)) {
    $stmt = $authPdo->prepare(
        "SELECT id, full_name, email, email_verified, verification_sent_at
           FROM users WHERE verification_token = ? LIMIT 1"
    );
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if ($user) {
        $name = (string) $user['full_name'];

        if ((int) $user['email_verified'] === 1) {
            // Redeemed tokens are nulled, so reaching here means the row was
            // verified some other way. Treat as success — a user clicking twice
            // should not see a failure.
            $state = 'already';
        } else {
            $sentAt  = strtotime((string) $user['verification_sent_at']);
            $expired = $sentAt === false || (time() - $sentAt) > (VERIFY_LINK_TTL_HOURS * 3600);

            if ($expired) {
                $state = 'expired';
            } else {
                // Token cleared on redemption: single use, so a link that later
                // leaks out of a mailbox cannot re-verify anything.
                $authPdo->prepare(
                    "UPDATE users
                        SET email_verified = 1, email_verified_at = NOW(), verification_token = NULL
                      WHERE id = ?"
                )->execute([$user['id']]);

                try {
                    $monitorPdo->prepare(
                        "INSERT INTO monitoring_logs (event_type, event_message, logged_at)
                         VALUES ('email_verified', ?, NOW())"
                    )->execute(["Email confirmed: {$user['email']}"]);
                } catch (Throwable $e) { /* non-critical */ }

                $state = 'ok';
            }
        }
    }
}

$copy = [
    'ok'      => ['Email confirmed', 'Your address is confirmed and your account is now active. You can sign in.', 'fa-circle-check', '#0b4e28'],
    'already' => ['Already confirmed', 'This address was already confirmed. You can sign in normally.', 'fa-circle-check', '#0b4e28'],
    'expired' => ['Link expired', 'This confirmation link has expired. Sign in and choose "Resend confirmation" to get a new one.', 'fa-clock', '#b7791f'],
    'invalid' => ['Link not recognised', 'This confirmation link is not valid. It may already have been used, or a newer link was sent — check for the most recent email.', 'fa-circle-xmark', '#c0392b'],
][$state];

[$heading, $message, $icon, $colour] = $copy;
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title><?= htmlspecialchars($heading) ?> — S.P.O.T.-IT</title>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;700;800&family=Plus+Jakarta+Sans:wght@300;400;500&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"/>
  <style>
    body {
      margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
      background:#f4f7f5; font-family:'Plus Jakarta Sans',system-ui,sans-serif; padding:20px;
    }
    .card {
      background:#fff; border:1px solid #e3ebe6; border-radius:14px;
      max-width:440px; width:100%; padding:36px 32px; text-align:center;
      box-shadow:0 6px 24px rgba(11,78,40,.06);
    }
    .ico { font-size:2.6rem; margin-bottom:16px; }
    h1 { font-family:'Outfit',sans-serif; font-size:1.25rem; font-weight:800; margin:0 0 10px; color:#1d2b22; }
    p  { font-size:.9rem; line-height:1.65; color:#5c7266; margin:0 0 24px; }
    .btn {
      display:inline-block; background:#0b4e28; color:#fff; text-decoration:none;
      font-family:'Outfit',sans-serif; font-weight:700; font-size:.85rem;
      padding:12px 28px; border-radius:9px;
    }
    .brand { font-family:'Outfit',sans-serif; font-weight:800; font-size:.8rem;
             color:#0b4e28; letter-spacing:.06em; margin-bottom:22px; }
  </style>
</head>
<body>
  <div class="card">
    <div class="brand">S.P.O.T.-IT</div>
    <div class="ico" style="color:<?= $colour ?>"><i class="fa-solid <?= $icon ?>"></i></div>
    <h1><?= htmlspecialchars($heading) ?></h1>
    <p><?= htmlspecialchars($message) ?></p>
    <a class="btn" href="<?= htmlspecialchars(SITE_URL) ?>/pages/login.php<?= $state === 'ok' ? '?verified=1' : '' ?>">
      Go to sign in
    </a>
  </div>
</body>
</html>
