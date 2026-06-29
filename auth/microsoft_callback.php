<?php
/**
 * S.P.O.T.-IT — Microsoft OAuth Callback (Step 2: Token Exchange)
 * auth/microsoft_callback.php
 *
 * MICROSERVICES: Reads/writes spotit_auth_db only.
 * Validates @dlsud.edu.ph domain before creating or signing in user.
 */
require_once __DIR__ . '/service_bootstrap.php';

$redirect_fail = '../pages/login.php?error=';

// ── 1. State / CSRF check ────────────────────────────────────────────────────
$state = $_GET['state'] ?? '';
if (!$state || $state !== ($_SESSION['ms_oauth_state'] ?? '')) {
    unset($_SESSION['ms_oauth_state']);
    header("Location: {$redirect_fail}oauth_failed"); exit();
}
unset($_SESSION['ms_oauth_state']);

// ── 2. Check for authorization errors ────────────────────────────────────────
if (isset($_GET['error'])) {
    header("Location: {$redirect_fail}oauth_failed"); exit();
}

$code = $_GET['code'] ?? '';
if (!$code) {
    header("Location: {$redirect_fail}oauth_failed"); exit();
}

// ── 3. Exchange code for token ───────────────────────────────────────────────
$tokenUrl = "https://login.microsoftonline.com/" . MS_TENANT_ID . "/oauth2/v2.0/token";

$tokenParams = [
    'client_id'     => MS_CLIENT_ID,
    'client_secret' => MS_CLIENT_SECRET,
    'code'          => $code,
    'redirect_uri'  => MS_REDIRECT_URI,
    'grant_type'    => 'authorization_code',
    'scope'         => MS_SCOPES,
];

$ch = curl_init($tokenUrl);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($tokenParams),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_TIMEOUT        => 15,
]);
$tokenResponse = curl_exec($ch);
$httpCode      = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$tokenResponse) {
    header("Location: {$redirect_fail}oauth_failed"); exit();
}

$tokenData = json_decode($tokenResponse, true);
if (empty($tokenData['access_token'])) {
    header("Location: {$redirect_fail}oauth_failed"); exit();
}

$accessToken = $tokenData['access_token'];

// ── 4. Fetch user profile from Microsoft Graph ────────────────────────────────
$ch = curl_init('https://graph.microsoft.com/v1.0/me?$select=id,displayName,mail,userPrincipalName');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ["Authorization: Bearer {$accessToken}"],
    CURLOPT_TIMEOUT        => 10,
]);
$profileResponse = curl_exec($ch);
curl_close($ch);

$profile = json_decode($profileResponse, true);
$msEmail = strtolower(trim($profile['mail'] ?? $profile['userPrincipalName'] ?? ''));
$msName  = $profile['displayName'] ?? 'DLSU-D User';
$msId    = $profile['id'] ?? '';

// ── 5. Domain enforcement ────────────────────────────────────────────────────
if (!ms_is_dlsud_email($msEmail)) {
    header("Location: {$redirect_fail}invalid_domain"); exit();
}

// ── 6. Find or create user in auth DB ────────────────────────────────────────
$stmt = $authPdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$msEmail]);
$user = $stmt->fetch();

if (!$user) {
    // Auto-register via Microsoft OAuth — role defaults to 'student'
    // Admins and staff must be provisioned manually
    $stmt = $authPdo->prepare(
        "INSERT INTO users (full_name, email, role, auth_provider, microsoft_id, is_active, created_at)
         VALUES (?, ?, 'student', 'microsoft', ?, 1, NOW())"
    );
    $stmt->execute([$msName, $msEmail, $msId]);
    $userId = (int)$authPdo->lastInsertId();

    // Re-fetch the new user
    $stmt = $authPdo->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $user = $stmt->fetch();
}

// ── 7. Active check ──────────────────────────────────────────────────────────
if (!$user['is_active']) {
    header("Location: {$redirect_fail}account_inactive"); exit();
}

// ── 8. Set session and redirect ───────────────────────────────────────────────
ms_set_session($user);

// Update microsoft_id if changed
if (($user['microsoft_id'] ?? '') !== $msId && $msId) {
    $authPdo->prepare("UPDATE users SET microsoft_id = ? WHERE id = ?")
            ->execute([$msId, $user['id']]);
}

$redirect = match($user['role']) {
    'admin'  => '../pages/dashboard-admin.php',
    'staff'  => '../pages/dashboard-staff.php',
    default  => '../pages/dashboard-student.php',
};

header("Location: {$redirect}"); exit();
