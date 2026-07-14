<?php
/**
 * S.P.O.T.-IT — Complete Claim Handler  [FIXES G8]
 * auth/complete_claim.php
 *
 * POST endpoint. Called by the Claiming Station (Step 4 — Confirm & Log).
 * This is the MISSING link that closes the entire detection → claim chain.
 *
 * What it does:
 *   1. Updates claims.status → 'claimed', sets claimed_at, verified_by,
 *      and saves the webcam snapshot path.
 *   2. Updates recovered_items.status → 'claimed'.
 *   3. Updates detections.status → 'recovered' (final state).
 *   4. Writes a full audit log entry to monitoring_logs.
 *   5. Sends a completion notification to the claimant.
 *
 * MICROSERVICES: Writes to spotit_lf_db, spotit_monitor_db, spotit_auth_db.
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

ms_require_auth('../pages/login.php');

if (!in_array($_SESSION['user_role'] ?? '', ['staff', 'admin'], true)) {
    ms_json(['success' => false, 'message' => 'Unauthorized. Staff or admin role required.'], 403);
}

$claim_id   = (int)($_POST['claim_id']    ?? 0);
$staff_note = trim($_POST['staff_note']   ?? '');
$actorId    = (int)$_SESSION['user_id'];
$actorName  = $_SESSION['user_name'] ?? 'Staff';

if (!$claim_id) {
    ms_json(['success' => false, 'message' => 'claim_id is required.']);
}

// ── Save webcam documentation photo ──────────────────────────────────────────
$webcam_path = null;
if (!empty($_FILES['webcam_snapshot']['tmp_name']) && is_uploaded_file($_FILES['webcam_snapshot']['tmp_name'])) {
    $ext      = pathinfo($_FILES['webcam_snapshot']['name'], PATHINFO_EXTENSION) ?: 'jpg';
    $filename = 'claim_' . $claim_id . '_' . date('Ymd_His') . '.' . $ext;
    $dest     = SNAPSHOT_PATH . $filename;
    if (move_uploaded_file($_FILES['webcam_snapshot']['tmp_name'], $dest)) {
        $webcam_path = $filename;
    }
}

// ── 1. Fetch the claim ────────────────────────────────────────────────────────
$claimStmt = $lfPdo->prepare("SELECT * FROM claims WHERE id = ? LIMIT 1");
$claimStmt->execute([$claim_id]);
$claim = $claimStmt->fetch();

if (!$claim) {
    ms_json(['success' => false, 'message' => 'Claim not found.']);
}
if ($claim['status'] === 'claimed') {
    ms_json(['success' => false, 'message' => 'This claim has already been completed.']);
}

// ── 2. Fetch the recovered item ───────────────────────────────────────────────
$itemStmt = $lfPdo->prepare("SELECT * FROM recovered_items WHERE recovery_id = ? LIMIT 1");
$itemStmt->execute([$claim['recovery_id']]);
$item = $itemStmt->fetch();

if (!$item) {
    ms_json(['success' => false, 'message' => 'Recovered item record not found.']);
}

// ── Transaction across lf_db ──────────────────────────────────────────────────
try {
    $lfPdo->beginTransaction();

    // Update claim → 'claimed'
    $lfPdo->prepare(
        "UPDATE claims
         SET status              = 'claimed',
             claimed_at          = NOW(),
             verified_by         = ?,
             webcam_snapshot     = ?,
             verification_notes  = CONCAT(COALESCE(verification_notes,''), ?, '\n[', NOW(), '] Completed by ', ?)
         WHERE id = ?"
    )->execute([
        $actorId,
        $webcam_path,
        $staff_note ? "\nStaff note: {$staff_note}" : '',
        $actorName,
        $claim_id,
    ]);

    // Update recovered_item → 'claimed'
    $lfPdo->prepare(
        "UPDATE recovered_items SET status = 'claimed' WHERE recovery_id = ?"
    )->execute([$claim['recovery_id']]);

    $lfPdo->commit();

} catch (PDOException $e) {
    $lfPdo->rollBack();
    ms_json(['success' => false, 'message' => 'Failed to complete claim in database.', 'detail' => $e->getMessage()], 500);
}

// ── Update detection status to 'recovered' (closed) ─────────────────────────
$detectionId = $item['detection_id'];
if ($detectionId) {
    try {
        $monitorPdo->prepare(
            "UPDATE detections
             SET status      = 'recovered',
                 verified_by = ?,
                 updated_at  = NOW()
             WHERE detection_id = ?"
        )->execute([$actorId, $detectionId]);
    } catch (Throwable $e) { /* non-critical — claim already completed */ }
}

// ── Write full audit log ──────────────────────────────────────────────────────
try {
    $claimantName = $claim['claimant_name'];
    $univId       = $claim['university_id'];
    $itemDesc     = $item['item_description'] ?? 'Unknown Item';
    $roomId       = $item['room_id'] ?? '';

    $monitorPdo->prepare(
        "INSERT INTO monitoring_logs
           (room_id, event_type, event_message, triggered_by, logged_at)
         VALUES (?, 'claim_completed', ?, ?, NOW())"
    )->execute([
        $roomId,
        "Claim #{$claim_id} COMPLETED — '{$itemDesc}' " .
        "claimed by {$claimantName} (ID: {$univId}). " .
        "Verified by {$actorName} (user_id:{$actorId}). " .
        ($webcam_path ? "Webcam snapshot: {$webcam_path}. " : '') .
        ($staff_note  ? "Note: {$staff_note}" : ''),
        $actorId,
    ]);
} catch (Throwable $e) { /* non-critical */ }

// ── Notify claimant — claim_approved ─────────────────────────────────────────
if ($claim['user_id']) {
    try {
        ms_notify(
            $authPdo,
            [$claim['user_id']],
            'claim_approved',
            'Your Claim Was Approved! 🎉',
            "Your claim for '{$item['item_description']}' has been approved and released " .
            "by {$actorName}. Claim #{$claim_id} is now closed. Receipt: {$receipt}.",
            $detectionId ?? 0,
            $item['room_id'] ?? '',
            $claim_id,
            'pages/claiming-station.php'
        );
    } catch (Throwable $e) { /* non-critical */ }
}

// ── Generate receipt number ───────────────────────────────────────────────────
$receipt = 'CLM-' . strtoupper(substr(md5($claim_id . date('Y')), 0, 8));

ms_json([
    'success'     => true,
    'claim_id'    => $claim_id,
    'receipt_no'  => $receipt,
    'claimed_at'  => date('Y-m-d H:i:s'),
    'verified_by' => $actorName,
    'webcam_saved'=> !is_null($webcam_path),
    'chain_closed'=> true,
]);
