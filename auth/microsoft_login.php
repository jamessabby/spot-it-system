<?php
/**
 * S.P.O.T.-IT — Microsoft OAuth Login (Step 1: Redirect)
 * auth/microsoft_login.php
 *
 * Redirects the user to Microsoft's authorization endpoint.
 * On return, microsoft_callback.php handles the token exchange.
 *
 * Azure AD App Registration required:
 *   - Redirect URI: https://spotit.dlsud.edu.ph/auth/microsoft_callback.php
 *   - Supported account types: Single tenant (DLSU-D directory)
 *   - Scopes: openid, profile, email, User.Read
 */
require_once __DIR__ . '/../config/env.php';

// Generate and store PKCE state to prevent CSRF
$state = bin2hex(random_bytes(16));
$_SESSION['ms_oauth_state'] = $state;

$params = http_build_query([
    'client_id'     => MS_CLIENT_ID,
    'response_type' => 'code',
    'redirect_uri'  => MS_REDIRECT_URI,
    'response_mode' => 'query',
    'scope'         => MS_SCOPES,
    'state'         => $state,
    'prompt'        => 'select_account',   // force account picker
    'domain_hint'   => 'dlsud.edu.ph',    // hint Microsoft to DLSU-D accounts
]);

$authUrl = "https://login.microsoftonline.com/" . MS_TENANT_ID . "/oauth2/v2.0/authorize?{$params}";

header("Location: {$authUrl}");
exit();
