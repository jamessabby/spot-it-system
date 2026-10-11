<?php
/**
 * S.P.O.T.-IT — CAPTCHA challenge endpoint
 * auth/captcha.php
 *
 * GET. Returns a fresh challenge as JSON: {"success":true,"question":"7 + 4"}
 *
 * The login and signup pages render their FIRST challenge server-side, so a
 * normal visitor never waits on this. It exists for the refresh case: the
 * challenge is single-use (see config/security.php), so after any submit —
 * successful or not — the page must pull a new question or the user would be
 * stuck answering a challenge the server has already discarded.
 *
 * Deliberately NOT session-authenticated: it is reached from the login and
 * signup pages, where by definition nobody is logged in yet.
 *
 * MICROSERVICES: touches no database. Session state only.
 */

require_once __DIR__ . '/../config/env.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

echo json_encode([
    'success'  => true,
    'question' => spotit_captcha_new(),
]);
