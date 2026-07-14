<?php
/**
 * S.P.O.T.-IT — Submit Claim Handler  [FIXES G10]
 * auth/submit_claim.php
 *
 * FIXES:
 *   G10 — Now writes to monitoring_logs when a claim is submitted so the
 *          full audit trail is unbroken.
 *
 * MICROSERVICES: Writes to spotit_lf_db. Logs to spotit_monitor_db.
 *                Notifies staff via spotit_auth_db.notifications.
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

ms_require_auth('../pages/login.php');

// ── Inputs ────────────────────────────────────────────────────────────────────
$recovery_id = (int)($_POST['recovery_id'] ?? 0);
$claimant    = trim($_POST['full_name']    ?? '');
$univ_id     = trim($_POST['id_number']    ?? '');
$contact     = trim($_POST['contact']      ?? '');
$description = trim($_POST['description']  ?? '');

if (!$recovery_id || !$claimant || !$univ_id || !$description) {
    ms_json(['success' => false, 'message' => 'All required fields must be filled.']);
}

if (!preg_match('/^\d{4}-\d{4,6}$/', $univ_id)) {
    ms_json(['success' => false, 'message' => 'Invalid university ID format. Use YYYY-NNNNN.']);
}

// ── Validate item is available ────────────────────────────────────────────────
$stmt = $lfPdo->prepare(
    "SELECT * FROM recovered_items WHERE recovery_id = ? AND status = 'recovered' LIMIT 1"
);
$stmt->execute([$recovery_id]);
$item = $stmt->fetch();

if (!$item) {
    ms_json(['success' => false, 'message' => 'Item not found or has already been claimed.']);
}

// ── Check for duplicate claim ─────────────────────────────────────────────────
$stmt = $lfPdo->prepare(
    "SELECT id FROM claims
     WHERE recovery_id = ? AND university_id = ? AND status != 'rejected' LIMIT 1"
);
$stmt->execute([$recovery_id, $univ_id]);
if ($stmt->fetch()) {
    ms_json(['success' => false, 'message' => 'You have already submitted a claim for this item.']);
}

// ── Insert claim ──────────────────────────────────────────────────────────────
try {
    $lfPdo->beginTransaction();

    $lfPdo->prepare(
        "INSERT INTO claims
           (recovery_id, user_id, claimant_name, university_id,
            contact, item_description, status, submitted_at)
         VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())"
    )->execute([
        $recovery_id,
        $_SESSION['user_id'],
        $claimant,
        $univ_id,
        $contact,
        $description,
    ]);
    $claim_id = (int)$lfPdo->lastInsertId();

    // Mark item as pending_claim
    $lfPdo->prepare(
        "UPDATE recovered_items SET status = 'pending_claim' WHERE recovery_id = ?"
    )->execute([$recovery_id]);

    $lfPdo->commit();

} catch (PDOException $e) {
    $lfPdo->rollBack();
    ms_json(['success' => false, 'message' => 'Failed to submit claim. Please try again.'], 500);
}

// ── [G10 FIXED] Write to monitoring_logs ──────────────────────────────────────
try {
    $monitorPdo->prepare(
        "INSERT INTO monitoring_logs
           (room_id, event_type, event_message, triggered_by, logged_at)
         VALUES (?, 'claim_submitted', ?, ?, NOW())"
    )->execute([
        $item['room_id'] ?? null,
        "Claim #{$claim_id} submitted for recovery_id={$recovery_id} " .
        "by {$claimant} (ID:{$univ_id}, user_id:{$_SESSION['user_id']}). " .
        "Item: {$item['item_description']}",
        (int)$_SESSION['user_id'],
    ]);
} catch (Throwable $e) { /* non-critical */ }

// ── Notify staff of new claim ─────────────────────────────────────────────────
try {
    $staffStmt = $authPdo->prepare(
        "SELECT id FROM users WHERE role IN ('staff','admin') AND is_active = 1"
    );
    $staffStmt->execute();
    $insert = $authPdo->prepare(
        "INSERT INTO notifications
           (user_id, type, title, body, detection_id, room_id, is_read, created_at)
         VALUES (?, 'new_claim', ?, ?, ?, ?, 0, NOW())"
    );
    $detId  = $item['detection_id'] ?? 0;
    $roomId = $item['room_id']      ?? '';
    foreach ($staffStmt->fetchAll() as $s) {
        $insert->execute([
            $s['id'],
            "New Claim Submitted — {$item['item_description']}",
            "{$claimant} (ID: {$univ_id}) submitted a claim for " .
            "'{$item['item_description']}' from {$roomId}. Please verify at the claiming station.",
            $detId,
            $roomId,
        ]);
    }
} catch (Throwable $e) { /* non-critical */ }

ms_json([
    'success'      => true,
    'claim_id'     => $claim_id,
    'message'      => 'Claim submitted. Please visit the dispensing window with your university ID.',
    'submitted_at' => date('Y-m-d H:i:s'),
]);
