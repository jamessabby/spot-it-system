<?php
/**
 * S.P.O.T.-IT — Submit Claim Handler
 * auth/submit_claim.php
 *
 * POST endpoint. Returns JSON.
 * Students submit a claim request for a recovered item.
 * MICROSERVICES: Writes to spotit_lf_db only.
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

ms_require_auth('../pages/login.php');

// ── Inputs ────────────────────────────────────────────────────────────────────
$recovery_id  = (int)($_POST['recovery_id']  ?? 0);
$claimant     = trim($_POST['full_name']     ?? '');
$univ_id      = trim($_POST['id_number']     ?? '');
$contact      = trim($_POST['contact']       ?? '');
$description  = trim($_POST['description']   ?? '');

if (!$recovery_id || !$claimant || !$univ_id || !$description) {
    ms_json(['success' => false, 'message' => 'All required fields must be filled.']);
}

// ── Validate ID format ────────────────────────────────────────────────────────
if (!preg_match('/^\d{4}-\d{4,6}$/', $univ_id)) {
    ms_json(['success' => false, 'message' => 'Invalid university ID format.']);
}

// ── Check item exists and is still available ──────────────────────────────────
$stmt = $lfPdo->prepare(
    "SELECT * FROM recovered_items WHERE recovery_id = ? AND status = 'recovered' LIMIT 1"
);
$stmt->execute([$recovery_id]);
$item = $stmt->fetch();

if (!$item) {
    ms_json(['success' => false, 'message' => 'Item not found or has already been claimed.']);
}

// ── Check for duplicate claim from same user ──────────────────────────────────
$stmt = $lfPdo->prepare(
    "SELECT id FROM claims
     WHERE recovery_id = ? AND university_id = ? AND status != 'rejected'
     LIMIT 1"
);
$stmt->execute([$recovery_id, $univ_id]);
if ($stmt->fetch()) {
    ms_json(['success' => false, 'message' => 'You have already submitted a claim for this item.']);
}

// ── Insert claim record ───────────────────────────────────────────────────────
try {
    $lfPdo->prepare(
        "INSERT INTO claims
           (recovery_id, user_id, claimant_name, university_id, contact, item_description, status, submitted_at)
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

    // Mark item as pending_claim so others can't submit duplicate claims
    $lfPdo->prepare(
        "UPDATE recovered_items SET status = 'pending_claim' WHERE recovery_id = ?"
    )->execute([$recovery_id]);

} catch (PDOException $e) {
    ms_json(['success' => false, 'message' => 'Failed to submit claim. Please try again.'], 500);
}

ms_json([
    'success'    => true,
    'claim_id'   => $claim_id,
    'message'    => 'Claim submitted successfully. Please visit the dispensing window with your university ID.',
    'submitted_at' => date('Y-m-d H:i:s'),
]);
