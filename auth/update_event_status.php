<?php
/**
 * S.P.O.T.-IT — Update Event Status Handler
 * auth/update_event_status.php
 *
 * POST endpoint. Returns JSON.
 * Used by staff/admin to mark detection events as:
 *   dismissed | pending | potential | confirmed_missing | recovered
 * MICROSERVICES: Writes to spotit_monitor_db. May also write to spotit_lf_db on recovery.
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

ms_require_auth('../pages/login.php');

// Only staff and admin can update event status
if (!in_array($_SESSION['user_role'] ?? '', ['staff', 'admin'], true)) {
    ms_json(['success' => false, 'message' => 'Unauthorized.'], 403);
}

$detection_id = (int)($_POST['detection_id'] ?? 0);
$new_status   = trim($_POST['status'] ?? '');
$notes        = trim($_POST['notes']  ?? '');

$allowed_statuses = ['dismissed', 'pending', 'potential', 'confirmed_missing', 'recovered'];
if (!$detection_id || !in_array($new_status, $allowed_statuses, true)) {
    ms_json(['success' => false, 'message' => 'Invalid detection ID or status.']);
}

// ── Fetch the detection record first ──────────────────────────────────────────
$stmt = $monitorPdo->prepare("SELECT * FROM detections WHERE detection_id = ? LIMIT 1");
$stmt->execute([$detection_id]);
$det = $stmt->fetch();

if (!$det) {
    ms_json(['success' => false, 'message' => 'Detection event not found.']);
}

// ── Update status in monitoring DB ───────────────────────────────────────────
try {
    $monitorPdo->prepare(
        "UPDATE detections
         SET status = ?, notes = CONCAT(IFNULL(notes,''), '\n[', NOW(), '] Staff update: ', ?), updated_at = NOW()
         WHERE detection_id = ?"
    )->execute([$new_status, "[{$_SESSION['user_name']}] " . $notes, $detection_id]);
} catch (PDOException $e) {
    ms_json(['success' => false, 'message' => 'Failed to update detection status.'], 500);
}

// ── If recovered, create a record in lost & found DB ─────────────────────────
if ($new_status === 'recovered') {
    try {
        $lfPdo->prepare(
            "INSERT INTO recovered_items
               (detection_id, room_id, item_description, found_location, recovered_at, status)
             VALUES (?, ?, ?, ?, NOW(), 'recovered')"
        )->execute([
            $detection_id,
            $det['room_id'],
            $det['object_type'] . ' — ' . $det['object_zone'],
            $det['room_id'],
        ]);
    } catch (Throwable $e) {
        error_log('[S.P.O.T.-IT] Failed to write recovered_items for detection_id=' . $detection_id);
    }
}

// ── Log in monitoring_logs ────────────────────────────────────────────────────
try {
    $monitorPdo->prepare(
        "INSERT INTO monitoring_logs (room_id, event_type, event_message, logged_at)
         VALUES (?, 'status_update', ?, NOW())"
    )->execute([
        $det['room_id'],
        "Detection #{$detection_id} marked as {$new_status} by {$_SESSION['user_name']} ({$_SESSION['user_role']})"
    ]);
} catch (Throwable $e) { /* non-critical */ }

ms_json([
    'success'      => true,
    'detection_id' => $detection_id,
    'new_status'   => $new_status,
    'updated_by'   => $_SESSION['user_name'],
    'updated_at'   => date('Y-m-d H:i:s'),
]);
