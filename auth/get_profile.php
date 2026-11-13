<?php
/**
 * S.P.O.T.-IT — Get Profile API
 * auth/get_profile.php
 *
 * GET endpoint. Returns JSON. Any logged-in user — scoped to their own
 * account only ($_SESSION['user_id']), never a client-supplied id.
 *
 * pages/profile.php's own docblock already claimed "Data fetched from
 * auth/get_profile.php via JS" — this file never existed, so every account
 * saw identical hardcoded fake values (ID number "2021-00001", course
 * "BS Computer Engineering", "Claims Submitted 2", "Account Age 14 days", ...).
 * This is read-only: it makes the ALREADY-DISPLAYED fields real, it does not
 * add any new editable field or write path (see auth/service_bootstrap.php's
 * per-page "no SQL in pages/*.php" rule — the query lives here, not in the page).
 *
 * MICROSERVICES: Reads spotit_auth_db, spotit_user_db, spotit_lf_db,
 * spotit_community_db — read-only cross-service lookups, same sanctioned
 * pattern as auth/get_surrender_logs.php's staff-name merge.
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

ms_require_auth('../pages/login.php');
$userId = (int) $_SESSION['user_id'];

try {
    $userStmt = $authPdo->prepare(
        'SELECT full_name, email, id_number, role, auth_provider, is_active, created_at, last_login_at
         FROM users WHERE id = ? LIMIT 1'
    );
    $userStmt->execute([$userId]);
    $user = $userStmt->fetch();
    if (!$user) {
        ms_json(['success' => false, 'message' => 'Account not found.'], 404);
    }

    $profileStmt = $userPdo->prepare(
        'SELECT phone, course, year_level FROM user_profiles WHERE user_id = ? LIMIT 1'
    );
    $profileStmt->execute([$userId]);
    $profile = $profileStmt->fetch() ?: ['phone' => null, 'course' => null, 'year_level' => null];

    $claimsStmt = $lfPdo->prepare('SELECT COUNT(*) FROM claims WHERE user_id = ?');
    $claimsStmt->execute([$userId]);
    $claimsSubmitted = (int) $claimsStmt->fetchColumn();

    $recoveredStmt = $lfPdo->prepare("SELECT COUNT(*) FROM claims WHERE user_id = ? AND status = 'claimed'");
    $recoveredStmt->execute([$userId]);
    $itemsRecovered = (int) $recoveredStmt->fetchColumn();

    $postsStmt = $communityPdo->prepare('SELECT COUNT(*) FROM forum_posts WHERE user_id = ? AND is_removed = 0');
    $postsStmt->execute([$userId]);
    $postsPublished = (int) $postsStmt->fetchColumn();

    $accountAgeDays = max(0, (int) floor((time() - strtotime($user['created_at'])) / 86400));

    ms_json([
        'success' => true,
        'profile' => [
            'full_name'         => $user['full_name'],
            'email'             => $user['email'],
            'id_number'         => $user['id_number'],
            'phone'             => $profile['phone'],
            'course'            => $profile['course'],
            'year_level'        => $profile['year_level'],
            'role'              => $user['role'],
            'auth_provider'     => $user['auth_provider'],
            'is_active'         => (bool) $user['is_active'],
            'created_at'        => $user['created_at'],
            'last_login_at'     => $user['last_login_at'],
            'account_age_days'  => $accountAgeDays,
            'claims_submitted'  => $claimsSubmitted,
            'items_recovered'   => $itemsRecovered,
            'posts_published'   => $postsPublished,
        ],
    ]);
} catch (Throwable $e) {
    ms_json(['success' => false, 'message' => 'Failed to load profile.'], 500);
}
