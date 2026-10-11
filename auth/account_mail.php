<?php
/**
 * S.P.O.T.-IT — Account email composition
 * auth/account_mail.php
 *
 * Builds and sends the two transactional emails the auth flow needs. Kept in
 * one place because each is sent from more than one entry point:
 *   - the verification link goes out from signup_handler.php AND resend_verification.php
 *   - the reset code goes out from request_password_reset.php AND its resend path
 *
 * Requires config/env.php (for spotit_mail / spotit_mail_template) to be loaded
 * already — every caller reaches this through service_bootstrap.php.
 */

if (!function_exists('spotit_send_verification_email')) {

    /**
     * Issue a fresh verification token for a user and email the link.
     *
     * The token is regenerated on every send, which invalidates any previous
     * link. That matters for the resend case: if someone requests a second
     * link because the first went to spam, the first must stop working, or a
     * link sitting in an inbox stays live indefinitely.
     *
     * @return array{ok: bool, error: string, token: string}
     */
    function spotit_send_verification_email(PDO $authPdo, int $userId, string $email, string $name): array
    {
        $token = bin2hex(random_bytes(32));

        $authPdo->prepare(
            "UPDATE users
                SET verification_token = ?, verification_sent_at = NOW()
              WHERE id = ?"
        )->execute([$token, $userId]);

        $link = SITE_URL . '/auth/verify_email.php?token=' . urlencode($token);
        $ttl  = VERIFY_LINK_TTL_HOURS;

        $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
        $safeLink = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');

        $body = <<<HTML
<p style="margin:0 0 14px;font-size:14px;line-height:1.65;">Hello {$safeName},</p>
<p style="margin:0 0 18px;font-size:14px;line-height:1.65;">
  An S.P.O.T.-IT account was created with this email address. Confirm it to activate your account.
</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 18px;">
  <tr><td style="background:#0b4e28;border-radius:8px;">
    <a href="{$safeLink}"
       style="display:inline-block;padding:12px 26px;font-family:Segoe UI,Arial,sans-serif;
              font-size:14px;font-weight:700;color:#ffffff;text-decoration:none;">
      Confirm my email
    </a>
  </td></tr>
</table>
<p style="margin:0 0 6px;font-size:12px;line-height:1.6;color:#5c7266;">
  If the button does not work, paste this into your browser:
</p>
<p style="margin:0;font-size:11px;line-height:1.5;word-break:break-all;color:#0b4e28;">{$safeLink}</p>
HTML;

        $foot = "This link expires in {$ttl} hours. If you did not create this account you can ignore this email — the account cannot be used until the address is confirmed.";

        $result = spotit_mail(
            $email,
            'Confirm your S.P.O.T.-IT account',
            spotit_mail_template('Confirm your email address', $body, $foot)
        );

        return [
            'ok'    => $result['ok'],
            'error' => $result['ok'] ? '' : ($result['error'] . ' ' . $result['detail']),
            'token' => $token,
        ];
    }

    /**
     * Email a 6-digit password-reset code.
     *
     * The code is passed in rather than generated here: the caller has to store
     * its hash in the same transaction it sends, and generating in two places
     * risks emailing one code while storing another.
     *
     * @return array{ok: bool, error: string}
     */
    function spotit_send_reset_code_email(string $email, string $name, string $code): array
    {
        $ttl      = RESET_CODE_TTL_MIN;
        $safeName = htmlspecialchars($name !== '' ? $name : 'there', ENT_QUOTES, 'UTF-8');
        $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');

        $body = <<<HTML
<p style="margin:0 0 14px;font-size:14px;line-height:1.65;">Hello {$safeName},</p>
<p style="margin:0 0 18px;font-size:14px;line-height:1.65;">
  Use this code to reset your S.P.O.T.-IT password:
</p>
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 18px;">
  <tr><td style="background:#f0f7f2;border:1px solid #cfe3d7;border-radius:8px;padding:14px 26px;">
    <span style="font-family:Consolas,Menlo,monospace;font-size:28px;font-weight:700;
                 letter-spacing:.35em;color:#0b4e28;">{$safeCode}</span>
  </td></tr>
</table>
<p style="margin:0;font-size:13px;line-height:1.65;">
  The code expires in {$ttl} minutes and can only be used once.
</p>
HTML;

        $foot = 'If you did not request a password reset, ignore this email — your password has not changed, and nobody can change it without this code.';

        $result = spotit_mail(
            $email,
            'Your S.P.O.T.-IT password reset code',
            spotit_mail_template('Password reset code', $body, $foot)
        );

        return [
            'ok'    => $result['ok'],
            'error' => $result['ok'] ? '' : ($result['error'] . ' ' . $result['detail']),
        ];
    }
}
