<?php
/**
 * S.P.O.T.-IT — Detection Ingest Endpoint
 * auth/ingest_detection.php
 *
 * POST endpoint called exclusively by the Python/OpenCV detection module.
 * Secured by a shared API key (not by session — Python has no browser session).
 *
 * MICROSERVICES: Writes to spotit_monitor_db.detections only.
 *
 * Python call example:
 *   import requests
 *   requests.post('https://spotit.dlsud.edu.ph/auth/ingest_detection.php', data={
 *     'api_key':        'YOUR_DETECTION_API_KEY',
 *     'room_id':        'MLH306',
 *     'object_type':    'Monitor',
 *     'object_zone':    'WS-07 Monitor Zone',
 *     'baseline_count': 30,
 *     'live_count':     29,
 *     'roi_change_pct': 71.4,
 *     'match_score':    0.73,
 *   }, files={'snapshot': open('snap.jpg','rb')})
 */
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../services/monitoring/db.php';

header('Content-Type: application/json; charset=utf-8');

// ── Only accept POST ───────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit();
}

// ── API key authentication (Python module uses this, not session) ──────────────
define('DETECTION_API_KEY', getenv('SPOTIT_DETECTION_KEY') ?: 'CHANGE_ME_DETECTION_KEY');
$provided_key = trim($_POST['api_key'] ?? $_SERVER['HTTP_X_SPOTIT_KEY'] ?? '');

if (!hash_equals(DETECTION_API_KEY, $provided_key)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}

// ── Collect inputs ────────────────────────────────────────────────────────────
$room_id        = trim($_POST['room_id']        ?? '');
$object_type    = trim($_POST['object_type']    ?? '');
$object_zone    = trim($_POST['object_zone']    ?? '');
$baseline_count = (int)($_POST['baseline_count'] ?? 0);
$live_count     = (int)($_POST['live_count']     ?? 0);
$roi_change_pct = (float)($_POST['roi_change_pct'] ?? 0.0);
$match_score    = isset($_POST['match_score']) ? (float)$_POST['match_score'] : null;

if (!$room_id || !$object_zone) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'room_id and object_zone are required.']);
    exit();
}

// ── Save snapshot image if uploaded ───────────────────────────────────────────
$snapshot_path = null;
if (!empty($_FILES['snapshot']['tmp_name']) && is_uploaded_file($_FILES['snapshot']['tmp_name'])) {
    $ext      = pathinfo($_FILES['snapshot']['name'], PATHINFO_EXTENSION) ?: 'jpg';
    $filename = date('Ymd_His') . '_' . $room_id . '_' . uniqid() . '.' . $ext;
    $dest     = SNAPSHOT_PATH . $filename;

    if (move_uploaded_file($_FILES['snapshot']['tmp_name'], $dest)) {
        $snapshot_path = $filename;
    }
}

// ── Insert detection record ───────────────────────────────────────────────────
try {
    $monitorPdo = getMonitorDB();

    $stmt = $monitorPdo->prepare(
        "INSERT INTO detections
           (room_id, object_type, object_zone, detected_at,
            snapshot_path, baseline_count, live_count,
            roi_change_pct, match_score, status)
         VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, 'pending')"
    );
    $stmt->execute([
        $room_id, $object_type, $object_zone,
        $snapshot_path,
        $baseline_count, $live_count,
        $roi_change_pct, $match_score,
    ]);

    $detection_id = (int)$monitorPdo->lastInsertId();
    $deviation    = $live_count - $baseline_count;

    // Log it
    $monitorPdo->prepare(
        "INSERT INTO monitoring_logs (room_id, event_type, event_message, logged_at)
         VALUES (?, 'detection', ?, NOW())"
    )->execute([
        $room_id,
        "Detection #{$detection_id}: {$object_zone} deviation={$deviation} (baseline={$baseline_count}, live={$live_count})"
    ]);

    echo json_encode([
        'success'      => true,
        'detection_id' => $detection_id,
        'deviation'    => $deviation,
        'stage'        => ms_detection_stage(date('Y-m-d H:i:s')),
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error.', 'detail' => $e->getMessage()]);
}
