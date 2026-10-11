<?php
/**
 * S.P.O.T.-IT — Form security helpers (CSRF + CAPTCHA)
 * config/security.php
 *
 * Included automatically by config/env.php, which has already started the
 * session. Both pages/ and auth/ get these with no extra include.
 *
 * WHY THIS EXISTS
 * An audit on 2026-08-22 found that login and signup shipped a CSRF field and a
 * CAPTCHA widget that were both pure theatre:
 *
 *   - The CSRF token was generated fresh on every page render with
 *     bin2hex(random_bytes(16)), never stored anywhere, and validated by no
 *     handler. Two emitters, zero verifiers.
 *   - The CAPTCHA was a setTimeout. pages/login.php's own comment said
 *     "CAPTCHA simulation". It drew a Google reCAPTCHA badge it had no
 *     relationship with, and posted no token, so anything hitting
 *     login_handler.php directly never saw it at all.
 *
 * Meanwhile pages/privacy-policy.php told users "Rate limiting (CAPTCHA +
 * progressive lockouts) protects against brute-force login attempts", and
 * CLAUDE.md §2 lists CAPTCHA as required. Both are now true rather than
 * aspirational.
 *
 * WHY AN ARITHMETIC CAPTCHA AND NOT reCAPTCHA OR AN IMAGE
 *   - reCAPTCHA needs a Google account, a site key, and a live callout to
 *     Google on every login. That is a third-party dependency and an outbound
 *     network call on the critical auth path, for a system that has to demo
 *     offline on one laptop if campus internet fails (DEPLOYMENT_GUIDE §11).
 *   - An image CAPTCHA needs ext-gd, which is NOT enabled in this PHP build
 *     (verified: extension_loaded('gd') === false).
 *
 * So: a small arithmetic challenge whose answer lives ONLY in the session and
 * is checked server-side. It is honest about what it is, needs nothing
 * external, and stops the naive scripted-POST case this actually faces. It is
 * not proof against a targeted attacker who parses the question — nothing at
 * this tier is, which is why the real brute-force defence remains the
 * login_attempts lockout in service_bootstrap.php.
 */

// ── CSRF ──────────────────────────────────────────────────────────────────────

if (!function_exists('spotit_csrf_token')) {
    /**
     * The session's CSRF token, created once and reused for the session's life.
     *
     * Per-session rather than per-form: a per-form token breaks the moment a
     * user opens two tabs, and this app's forms are all AJAX-submitted from
     * pages that may sit open for a while.
     */
    function spotit_csrf_token(): string
    {
        if (empty($_SESSION['spotit_csrf'])) {
            $_SESSION['spotit_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['spotit_csrf'];
    }

    /**
     * Constant-time check of a submitted token. Returns false when the session
     * has no token at all, so a request that arrives with no session cannot
     * pass by submitting an empty string.
     */
    function spotit_csrf_verify(?string $submitted): bool
    {
        if (empty($_SESSION['spotit_csrf']) || $submitted === null || $submitted === '') {
            return false;
        }
        return hash_equals($_SESSION['spotit_csrf'], $submitted);
    }
}

// ── CAPTCHA ───────────────────────────────────────────────────────────────────

if (!defined('SPOTIT_CAPTCHA_TTL')) {
    // Five minutes. Long enough to fill in a signup form unhurried, short
    // enough that a scraped question is not reusable later.
    define('SPOTIT_CAPTCHA_TTL', 300);
}

if (!function_exists('spotit_captcha_new')) {
    /**
     * Generate a fresh challenge and store its answer in the session.
     *
     * @return string The human-readable question, e.g. "7 + 4".
     */
    function spotit_captcha_new(): string
    {
        // Small operands and no negative results: this is a bot gate, not a
        // numeracy test, and a confusing question just punishes real users.
        $a = random_int(2, 9);
        $b = random_int(2, 9);

        if (random_int(0, 1) === 1) {
            $question = "{$a} + {$b}";
            $answer   = $a + $b;
        } else {
            // Order the operands so the result is never negative, and never let
            // them be equal: an answer of "0" looks like an unanswered field,
            // both to the user and to the JS that enables the submit button.
            if ($a === $b) {
                $b = ($b > 2) ? $b - 1 : $b + 1;
            }
            $hi = max($a, $b);
            $lo = min($a, $b);
            $question = "{$hi} - {$lo}";
            $answer   = $hi - $lo;
        }

        $_SESSION['spotit_captcha'] = [
            'answer'  => (string) $answer,
            'expires' => time() + SPOTIT_CAPTCHA_TTL,
        ];

        return $question;
    }

    /**
     * Verify a submitted answer.
     *
     * SINGLE USE: the stored challenge is cleared on every call, pass or fail.
     * Without that, one solved CAPTCHA would authorise unlimited POSTs, which
     * is precisely the replay the widget is supposed to prevent — and it would
     * also let an attacker retry a wrong answer until they hit it.
     *
     * @return array{ok: bool, error: string} error is '' when ok.
     */
    function spotit_captcha_verify(?string $submitted): array
    {
        $challenge = $_SESSION['spotit_captcha'] ?? null;
        unset($_SESSION['spotit_captcha']);

        if (!is_array($challenge) || !isset($challenge['answer'], $challenge['expires'])) {
            return ['ok' => false, 'error' => 'Security check expired. A new question has been loaded — please try again.'];
        }

        if (time() > (int) $challenge['expires']) {
            return ['ok' => false, 'error' => 'Security check expired. A new question has been loaded — please try again.'];
        }

        $submitted = trim((string) $submitted);
        if ($submitted === '' || !hash_equals((string) $challenge['answer'], $submitted)) {
            return ['ok' => false, 'error' => 'Security check answer was incorrect. Please try the new question.'];
        }

        return ['ok' => true, 'error' => ''];
    }
}
