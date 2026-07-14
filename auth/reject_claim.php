<?php
/**
 * S.P.O.T.-IT — Reject Claim Handler
 * auth/reject_claim.php
 *
 * POST endpoint. Staff rejects a pending claim.
 * Sends a 'claim_rejected' notification to the claimant with reason.
 *
 * POST body:
 *   claim_id   int    required
 *   reason     string required  (rejection reason shown to claimant)
 *
 * MICROSERVICES: Writes to spotit_lf_db + spotit_auth_db + spotit_monitor_db (log).
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

ms_require_auth('../pages/login.php');

if (!in_array($_SESSION['user_role'] ?? '', ['staff', 'admin'], true)) {
    ms_json(['success' => false, 'message' => 'Staff or admin role required.'], 403);
}

$claim_id  = (int)($_POST['claim_id'] ?? 0);
$reason    = trim($_POST['reason']    ?? '');
$actorId   = (int)$_SESSION['user_id'];
$actorName = $_SESSION['user_name'] ?? 'Staff';

if (!$claim_id || !$reason) {
    ms_json(['success' => false, 'message' => 'claim_id and reason are required.']);
}

// Fetch claim
$claimStmt = $lfPdo->prepare("SELECT * FROM claims WHERE id = ? LIMIT 1");
$claimStmt->execute([$claim_id]);
$claim = $claimStmt->fetch();

if (!$claim) {
    ms_json(['success' => false, 'message' => 'Claim not found.']);
}
if ($claim['status'] !== 'pending') {
    ms_json(['success' => false, 'message' => 'Only pending claims can be rejected.']);
}

// Fetch linked recovered item
$itemStmt = $lfPdo->prepare("SELECT * FROM recovered_items WHERE recovery_id = ? LIMIT 1");
$itemStmt->execute([$claim['recovery_id']]);
$item = $itemStmt->fetch();

try {
    $lfPdo->beginTransaction();

    // Update claim status
    $lfPdo->prepare(
        "UPDATE claims
         SET status             = 'rejected',
             verified_by        = ?,
             verification_notes = CONCAT(COALESCE(verification_notes,''), ?, '\n[', NOW(), '] Rejected by ', ?)
         WHERE id = ?"
    )->execute([
        $actorId,
        "\nRejection reason: {$reason}",
        $actorName,
        $claim_id,
    ]);

    // Revert item back to 'recovered' so other students can claim it
    if ($item) {
        $lfPdo->prepare(
            "UPDATE recovered_items SET status = 'recovered' WHERE recovery_id = ?"
        )->execute([$claim['recovery_id']]);
    }

    $lfPdo->commit();

} catch (PDOException $e) {
    $lfPdo->rollBack();
    ms_json(['success' => false, 'message' => 'Failed to reject claim.'], 500);
}

// Audit log
try {
    $monitorPdo->prepare(
        "INSERT INTO monitoring_logs
           (room_id, event_type, event_message, triggered_by, logged_at)
         VALUES (?, 'claim_rejected', ?, ?, NOW())"
    )->execute([
        $item['room_id'] ?? null,
        "Claim #{$claim_id} REJECTED by {$actorName} (user_id:{$actorId}). " .
        "Item: {$item['item_description']}. Reason: {$reason}",
        $actorId,
    ]);
} catch (Throwable $e) {}

// Notify claimant — claim_rejected
if ($claim['user_id']) {
    try {
        ms_notify(
            $authPdo,
            [$claim['user_id']],
            'claim_rejected',
            'Claim Not Approved',
            "Your claim for '{$item['item_description']}' (Claim #{$claim_id}) was not approved. " .
            "Reason: {$reason}. You may visit the CEAT office for further assistance.",
            $item['detection_id'] ?? 0,
            $item['room_id'] ?? '',
            $claim_id,
            'pages/lost-thread.php'
        );
    } catch (Throwable $e) {}
}

ms_json([
    'success'      => true,
    'claim_id'     => $claim_id,
    'new_status'   => 'rejected',
    'rejected_by'  => $actorName,
    'rejected_at'  => date('Y-m-d H:i:s'),
]);
