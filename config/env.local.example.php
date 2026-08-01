<?php
/**
 * S.P.O.T.-IT — Local/production environment overrides — EXAMPLE
 *
 * Copy this file to `config/env.local.php` and fill in real values.
 * env.local.php is gitignored and is the ONLY file that should ever contain
 * real credentials. config/env.php reads it first, then real environment
 * variables, then falls back to XAMPP defaults.
 *
 * On XAMPP you do not need this file at all — the defaults already work.
 * On Hostinger you do. See md_files/DEPLOYMENT_GUIDE.md.
 */

return [

    // ── Deployment mode ──────────────────────────────────────────────────────
    // 'remote' = the camera is NOT reachable from this server (the normal
    // production case: web app hosted, main.py on a mini-PC in the lab).
    // This disables the ffmpeg MJPEG proxy and switches Capture Baseline to
    // the command-queue path, which is the only thing that works over NAT.
    'SPOTIT_DEPLOY' => 'remote',

    // ── Site ─────────────────────────────────────────────────────────────────
    // No trailing slash. Must be the real public URL — Microsoft OAuth redirects
    // are derived from it, and the same URI must be registered in Azure.
    'SITE_URL' => 'https://spotit.example.com',

    // ── Databases ────────────────────────────────────────────────────────────
    // Hostinger prefixes database and user names (e.g. u123456789_spotit_auth).
    // Each service keeps its own database — do not merge them (CLAUDE.md §8).
    'DB_HOST' => 'localhost',

    'AUTH_DB_NAME' => 'u000000000_spotit_auth',
    'AUTH_DB_USER' => 'u000000000_spotit',
    'AUTH_DB_PASS' => '',

    'MONITOR_DB_NAME' => 'u000000000_spotit_monitor',
    'MONITOR_DB_USER' => 'u000000000_spotit',
    'MONITOR_DB_PASS' => '',

    'LF_DB_NAME' => 'u000000000_spotit_lf',
    'LF_DB_USER' => 'u000000000_spotit',
    'LF_DB_PASS' => '',

    'USER_DB_NAME' => 'u000000000_spotit_user',
    'USER_DB_USER' => 'u000000000_spotit',
    'USER_DB_PASS' => '',

    'COMMUNITY_DB_NAME' => 'u000000000_spotit_community',
    'COMMUNITY_DB_USER' => 'u000000000_spotit',
    'COMMUNITY_DB_PASS' => '',

    // ── API keys ─────────────────────────────────────────────────────────────
    // MUST be changed from the default and MUST match the API_KEY environment
    // variable set for main.py on the mini-PC. Generate one with:
    //     php -r "echo bin2hex(random_bytes(32));"
    'SPOTIT_DETECTION_KEY' => 'PUT_A_LONG_RANDOM_STRING_HERE',
    'SPOTIT_CRON_KEY'      => 'PUT_A_DIFFERENT_LONG_RANDOM_STRING_HERE',

    // ── Microsoft OAuth (Azure AD) ───────────────────────────────────────────
    'MS_CLIENT_ID'     => '',
    'MS_CLIENT_SECRET' => '',
    'MS_TENANT_ID'     => '',

    // ── Mail ─────────────────────────────────────────────────────────────────
    'MAIL_HOST' => 'smtp.gmail.com',
    'MAIL_PORT' => 587,
    'MAIL_USER' => '',
    'MAIL_PASS' => '',
];
