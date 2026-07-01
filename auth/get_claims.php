<?php
/**
 * S.P.O.T.-IT — Get Claims API
 * auth/get_claims.php
 *
 * GET endpoint. Returns JSON.
 * Returns claims for the currently logged-in student (or all claims for staff/admin).
 * MICROSERVICES: Reads from spotit_lf_db.claims and spotit_lf_db.recovered_items.
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

ms_require_auth('../pages/login.php');

$role    = $_SESSION['user_role'] ?? 'student';
$user_id = (int)$_SESSION['user_id'];
$limit   = min((int)($_GET['limit'] ?? 20), 100);
$status  = $_GET['status'] ?? null;

$where  = ['1=1'];
$params = [];

// Students only see their own claims; staff/admin see all
if ($role === 'student') {
    $where[]  = 'c.user_id = ?';
    $params[] = $user_id;
}
if ($status) {
    $where[]  = 'c.status = ?';
    $params[] = $status;
}

$whereSQL = implode(' AND ', $where);

try {
    $stmt = $lfPdo->prepare(
        "SELECT
             c.id              AS claim_id,
             c.recovery_id,
             c.claimant_name,
             c.university_id,
             c.item_description,
             c.status,
             c.submitted_at,
             c.claimed_at,
             r.item_description AS recovered_item_desc,
             r.item_type,
             r.room_id,
             r.found_location,
             r.recovered_at,
             r.snapshot_path
         FROM claims c
         LEFT JOIN recovered_items r ON r.recovery_id = c.recovery_id
         WHERE {$whereSQL}
         ORDER BY c.submitted_at DESC
         LIMIT ?"
    );
    $params[] = $limit;
    $stmt->execute($params);
    $claims = $stmt->fetchAll();

    ms_json([
        'success' => true,
        'count'   => count($claims),
        'claims'  => $claims,
    ]);
} catch (Throwable $e) {
    ms_json(['success' => false, 'message' => 'Failed to fetch claims.'], 500);
}
