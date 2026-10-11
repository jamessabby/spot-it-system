<?php
/**
 * S.P.O.T.-IT — Outbound email
 * config/mailer.php
 *
 * Loaded by config/env.php. One entry point: spotit_mail().
 *
 * WHY THIS EXISTS
 * Until 2026-08-22 no code in this repo had ever sent an email. The six MAIL_*
 * constants in env.php were read by zero files, there was no PHPMailer, no
 * composer and no vendor/ directory. Consequently signup had no verification
 * (accounts were live the instant they were created) and password reset was a
 * client-side mock that told users it had worked while changing nothing.
 *
 * THREE DRIVERS, chosen with MAIL_DRIVER in config/env.local.php:
 *
 *   'log'    — DEFAULT. Writes the message to logs/mail/ instead of sending it.
 *              Nothing leaves the machine. This is what makes the whole flow
 *              testable on XAMPP today, before a domain exists: you read the
 *              verification link or the reset code straight out of the file.
 *
 *   'mail'   — PHP's built-in mail(). Zero configuration on most shared hosting
 *              (Hostinger wires up sendmail for you). Deliverability to Outlook
 *              is mediocre without SPF/DKIM on the sending domain.
 *
 *   'resend' — HTTPS POST to api.resend.com via curl. Best deliverability, and
 *              dependency-free: no composer, no PHPMailer. Needs MAIL_API_KEY
 *              and a sending domain verified in the Resend dashboard.
 *
 * WHY NOT SMTP: sending SMTP properly means PHPMailer, and PHPMailer means
 * composer, which is not installed here. Hand-rolling SMTP over fsockopen to
 * avoid one dependency is not a trade worth making before a defense. If SMTP
 * becomes necessary, add it as a fourth driver — nothing outside this file
 * knows how mail is sent.
 *
 * FAILURE POLICY: spotit_mail() never throws. It returns a result array and
 * logs. A mail outage must degrade a signup into "we could not send your link,
 * request another" — never a 500 on a half-created account.
 */

if (!defined('MAIL_LOG_PATH')) {
    define('MAIL_LOG_PATH', __DIR__ . '/../logs/mail/');
}

if (!function_exists('spotit_mail')) {

    /**
     * Send one transactional email.
     *
     * @param string $to      Recipient address.
     * @param string $subject Plain-text subject.
     * @param string $html    HTML body.
     * @param string $text    Plain-text fallback. Auto-derived when empty.
     * @return array{ok: bool, driver: string, error: string, detail: string}
     */
    function spotit_mail(string $to, string $subject, string $html, string $text = ''): array
    {
        $driver = strtolower((string) MAIL_DRIVER);

        if ($text === '') {
            // Cheap but adequate: keep link URLs visible in the text part.
            $text = trim(html_entity_decode(strip_tags(
                preg_replace('/<a\b[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/is', '$2 ($1)', $html)
            ), ENT_QUOTES, 'UTF-8'));
            $text = preg_replace("/\n{3,}/", "\n\n", $text);
        }

        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'driver' => $driver, 'error' => 'invalid_recipient', 'detail' => $to];
        }

        try {
            switch ($driver) {
                case 'resend':
                    $result = spotit_mail_resend($to, $subject, $html, $text);
                    break;
                case 'mail':
                    $result = spotit_mail_php($to, $subject, $html, $text);
                    break;
                case 'log':
                default:
                    $result = spotit_mail_log($to, $subject, $html, $text);
                    break;
            }
        } catch (Throwable $e) {
            $result = ['ok' => false, 'driver' => $driver, 'error' => 'exception', 'detail' => $e->getMessage()];
        }

        if (!$result['ok']) {
            error_log("[SPOT-IT][mail] {$driver} failed for {$to}: {$result['error']} {$result['detail']}");
        }
        return $result;
    }

    // ── Driver: log ───────────────────────────────────────────────────────────

    /**
     * Write the message to logs/mail/ instead of sending it.
     *
     * The .html file is openable in a browser so links are clickable, and the
     * index line gives you the newest message without hunting through a folder.
     */
    function spotit_mail_log(string $to, string $subject, string $html, string $text): array
    {
        if (!is_dir(MAIL_LOG_PATH) && !@mkdir(MAIL_LOG_PATH, 0775, true) && !is_dir(MAIL_LOG_PATH)) {
            return ['ok' => false, 'driver' => 'log', 'error' => 'mkdir_failed', 'detail' => MAIL_LOG_PATH];
        }

        $stamp = date('Ymd-His');
        $safe  = preg_replace('/[^a-zA-Z0-9._-]/', '_', $to);
        $file  = MAIL_LOG_PATH . "{$stamp}_{$safe}.html";

        $header = "<!-- TO: {$to}\n     SUBJECT: {$subject}\n     AT: " . date('Y-m-d H:i:s')
                . "\n     DRIVER: log (not actually sent - set MAIL_DRIVER in config/env.local.php to send for real)\n-->\n";

        if (@file_put_contents($file, $header . $html) === false) {
            return ['ok' => false, 'driver' => 'log', 'error' => 'write_failed', 'detail' => $file];
        }

        // One-line index, newest last, so `tail` shows the most recent message.
        @file_put_contents(
            MAIL_LOG_PATH . 'index.log',
            sprintf("[%s] TO=%s SUBJECT=%s FILE=%s%s", date('Y-m-d H:i:s'), $to, $subject, basename($file), PHP_EOL),
            FILE_APPEND
        );

        return ['ok' => true, 'driver' => 'log', 'error' => '', 'detail' => $file];
    }

    // ── Driver: PHP mail() ────────────────────────────────────────────────────

    function spotit_mail_php(string $to, string $subject, string $html, string $text): array
    {
        $boundary = '=_spotit_' . bin2hex(random_bytes(12));
        $from     = MAIL_FROM;
        $fromName = MAIL_FROM_NAME;

        $headers = implode("\r\n", [
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'From: ' . spotit_mail_encode_name($fromName) . ' <' . $from . '>',
            'Reply-To: ' . $from,
            'X-Mailer: S.P.O.T.-IT',
        ]);

        $body = "--{$boundary}\r\n"
              . "Content-Type: text/plain; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: 8bit\r\n\r\n"
              . $text . "\r\n\r\n"
              . "--{$boundary}\r\n"
              . "Content-Type: text/html; charset=UTF-8\r\n"
              . "Content-Transfer-Encoding: 8bit\r\n\r\n"
              . $html . "\r\n\r\n"
              . "--{$boundary}--";

        $ok = @mail($to, spotit_mail_encode_name($subject), $body, $headers);

        return $ok
            ? ['ok' => true, 'driver' => 'mail', 'error' => '', 'detail' => '']
            : ['ok' => false, 'driver' => 'mail', 'error' => 'mail_returned_false',
               'detail' => 'No local MTA, or the host rejected the message. XAMPP has no MTA by default — use MAIL_DRIVER=log locally.'];
    }

    /** RFC 2047 encode a header value only when it needs it. */
    function spotit_mail_encode_name(string $value): string
    {
        return preg_match('/[^\x20-\x7E]/', $value)
            ? '=?UTF-8?B?' . base64_encode($value) . '?='
            : $value;
    }

    // ── Driver: Resend HTTP API ───────────────────────────────────────────────

    function spotit_mail_resend(string $to, string $subject, string $html, string $text): array
    {
        if (MAIL_API_KEY === '' || MAIL_API_KEY === 'CHANGE_ME_MAIL_API_KEY') {
            return ['ok' => false, 'driver' => 'resend', 'error' => 'no_api_key',
                    'detail' => 'Set MAIL_API_KEY in config/env.local.php.'];
        }
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'driver' => 'resend', 'error' => 'no_curl', 'detail' => 'ext-curl is not enabled.'];
        }

        $payload = json_encode([
            'from'    => MAIL_FROM_NAME . ' <' . MAIL_FROM . '>',
            'to'      => [$to],
            'subject' => $subject,
            'html'    => $html,
            'text'    => $text,
        ], JSON_UNESCAPED_SLASHES);

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . MAIL_API_KEY,
                'Content-Type: application/json',
            ],
        ]);
        $response = curl_exec($ch);
        $status   = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'driver' => 'resend', 'error' => 'curl_error', 'detail' => $curlErr];
        }
        if ($status < 200 || $status >= 300) {
            // Resend puts a human-readable reason in the body; keep it, because
            // "domain not verified" is by far the most likely first failure.
            return ['ok' => false, 'driver' => 'resend', 'error' => "http_{$status}",
                    'detail' => substr((string) $response, 0, 300)];
        }

        return ['ok' => true, 'driver' => 'resend', 'error' => '', 'detail' => ''];
    }

    // ── Shared HTML shell ─────────────────────────────────────────────────────

    /**
     * Wrap body content in the S.P.O.T.-IT email shell.
     *
     * Table-based layout with inline styles on purpose: Outlook — which is
     * exactly where every @dlsud.edu.ph address lands — ignores <style> blocks
     * and flexbox. No external images either, since most clients block them by
     * default and a logo that fails to load looks like a broken email.
     */
    function spotit_mail_template(string $heading, string $bodyHtml, string $footNote = ''): string
    {
        $year = date('Y');
        $foot = $footNote !== ''
            ? '<p style="margin:18px 0 0;font-size:12px;line-height:1.6;color:#8a9a8f;">' . $footNote . '</p>'
            : '';

        return <<<HTML
<!doctype html>
<html>
<body style="margin:0;padding:0;background:#f4f7f5;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f4f7f5;padding:28px 12px;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
             style="max-width:520px;background:#ffffff;border:1px solid #e3ebe6;border-radius:12px;overflow:hidden;">
        <tr>
          <td style="background:#0b4e28;padding:20px 26px;">
            <span style="font-family:Segoe UI,Arial,sans-serif;font-size:17px;font-weight:700;color:#ffffff;letter-spacing:.02em;">
              S.P.O.T.-IT
            </span>
            <span style="font-family:Segoe UI,Arial,sans-serif;font-size:11px;color:#9fd4b4;display:block;margin-top:2px;">
              DLSU-D CEAT Laboratory Monitoring
            </span>
          </td>
        </tr>
        <tr>
          <td style="padding:26px;font-family:Segoe UI,Arial,sans-serif;color:#1d2b22;">
            <h1 style="margin:0 0 14px;font-size:19px;font-weight:700;color:#0b4e28;">{$heading}</h1>
            {$bodyHtml}
            {$foot}
          </td>
        </tr>
        <tr>
          <td style="padding:14px 26px;background:#f7faf8;border-top:1px solid #e3ebe6;
                     font-family:Segoe UI,Arial,sans-serif;font-size:11px;color:#8a9a8f;">
            &copy; {$year} S.P.O.T.-IT &middot; De La Salle University &ndash; Dasmari&ntilde;as<br/>
            This is an automated message. Please do not reply.
          </td>
        </tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }
}
