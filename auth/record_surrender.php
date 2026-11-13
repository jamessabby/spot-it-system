<?php
/**
 * S.P.O.T.-IT — Record Manual Surrender Handler
 * auth/record_surrender.php
 *
 * POST endpoint. Returns JSON. Admin/staff only.
 *
 * Phase 2A: pages/surrender-log.php's "Record Surrender" button called an
 * undefined recordSurrender() with no handler behind it at all — the modal
 * always closed with a toast regardless of whether anything was saved. This
 * is the real handler: it both logs the physical hand-off in surrender_logs
 * (the audit record this page exists to keep) and creates the matching
 * recovered_items row with status='recovered', the exact state the CCTV-auto
 * path (auth/update_event_status.php) already uses to make an item claimable.
 * No new "publish" step is introduced — a manual surrender becomes claimable
 * the same instant a CCTV recovery does, through the same existing column.
 *
 * MICROSERVICES: Writes to spotit_lf_db (recovered_items, surrender_logs).
 * Logs to spotit_monitor_db.monitoring_logs (non-critical, best effort).
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

ms_require_auth('../pages/login.php');
spotit_require_csrf();

// Admin only — matches pages/surrender-log.php's own spotit_require_admin()
// gate. Checked again here because the page guard only stops a browser from
// loading the page; it does nothing to stop a direct POST to this file.
$role   = $_SESSION['user_role'] ?? '';
$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($role !== 'admin') {
    ms_json(['success' => false, 'message' => 'Only staff/admin accounts can record a surrender.'], 403);
}

// ── Inputs ──────────────────────────────────────────────────────────────────
$itemDescription = trim($_POST['item_description'] ?? '');
$foundLocation    = trim($_POST['found_location']   ?? '');
$condition        = trim($_POST['condition']        ?? 'Good');
$surrenderedBy    = trim($_POST['surrendered_by']   ?? '');

if ($itemDescription === '' || $foundLocation === '') {
    ms_json(['success' => false, 'message' => 'Item description and found location are required.']);
}
if (mb_strlen($itemDescription) > 1000) {
    ms_json(['success' => false, 'message' => 'Item description is too long.']);
}
if (mb_strlen($foundLocation) > 200) {
    ms_json(['success' => false, 'message' => 'Found location is too long.']);
}
$allowedConditions = ['Good', 'Damaged', 'Incomplete'];
if (!in_array($condition, $allowedConditions, true)) {
    ms_json(['success' => false, 'message' => 'Invalid item condition.']);
}
// surrendered_by is optional in the UI, but the column is NOT NULL — the
// person physically handing over the item is not always named.
if ($surrenderedBy === '') {
    $surrenderedBy = 'Anonymous';
}
if (mb_strlen($surrenderedBy) > 200) {
    ms_json(['success' => false, 'message' => 'Surrenderer name is too long.']);
}

// Manually surrendered items have no camera room — a fixed, non-FK label
// (room_id is denormalized display text everywhere it's read, never joined;
// see services/lostfound/schema.sql's own note on this).
$ROOM_LABEL = 'SURRENDER';
// item_type has no dedicated UI field; fall back to a trimmed description so
// claiming-station.php's "name" column is never blank for a manual surrender.
$itemType = mb_substr($itemDescription, 0, 60);

try {
    $lfPdo->beginTransaction();

    $lfPdo->prepare(
        "INSERT INTO recovered_items
           (room_id, item_description, item_type, item_tier, found_location,
            recovered_at, recovered_by, source, status, notes)
         VALUES (?, ?, ?, 'tier1', ?, NOW(), ?, 'manual_surrender', 'recovered', ?)"
    )->execute([
        $ROOM_LABEL,
        $itemDescription,
        $itemType,
        $foundLocation,
        $userId,
        // Reused free-text column: manual_surrender rows store the item's
        // physical condition here; cctv_auto rows use it for staff notes
        // instead (auth/update_event_status.php). The two sources never mix
        // on a page that reads this column (surrender-log.php only ever
        // joins surrender_logs rows, which are always manual_surrender).
        "Condition: {$condition}",
    ]);
    $recoveryId = (int) $lfPdo->lastInsertId();

    $lfPdo->prepare(
        "INSERT INTO surrender_logs
           (recovery_id, surrendered_by, item_description, room_id,
            staff_accepted_by, surrendered_at, source)
         VALUES (?, ?, ?, ?, ?, NOW(), 'student')"
    )->execute([
        $recoveryId,
        $surrenderedBy,
        $itemDescription,
        $ROOM_LABEL,
        $userId,
    ]);
    $surrenderId = (int) $lfPdo->lastInsertId();

    $lfPdo->commit();
} catch (PDOException $e) {
    if ($lfPdo->inTransaction()) {
        $lfPdo->rollBack();
    }
    ms_json(['success' => false, 'message' => 'Failed to record surrender. Please try again.'], 500);
}

// ── Audit trail (non-critical, best effort — same pattern as submit_claim.php) ──
try {
    $monitorPdo->prepare(
        "INSERT INTO monitoring_logs
           (room_id, event_type, event_message, triggered_by, logged_at)
         VALUES (NULL, 'surrender_recorded', ?, ?, NOW())"
    )->execute([
        "Surrender #{$surrenderId} recorded (recovery_id={$recoveryId}) by " .
        "{$_SESSION['user_name']} (user_id:{$userId}). Surrendered by {$surrenderedBy}. " .
        "Item: {$itemDescription} — found at {$foundLocation}.",
        $userId,
    ]);
} catch (Throwable $e) { /* non-critical — continue */ }

ms_json([
    'success'      => true,
    'surrender_id' => $surrenderId,
    'recovery_id'  => $recoveryId,
    'message'      => 'Surrender recorded. The item is now available for claiming.',
]);
