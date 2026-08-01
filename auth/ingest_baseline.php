<?php
/**
 * S.P.O.T.-IT — Baseline upload
 * auth/ingest_baseline.php
 *
 * POST (multipart), API-key authenticated. Called by main.py after it executes
 * a queued `capture_baseline` command.
 *
 * WHY CAPTURE BASELINE WORKS BACKWARDS NOW
 * auth/capture_frame.php used to shell out to ffmpeg and pull a frame straight
 * off rtsp://192.168.x.x. That works on XAMPP, where the web server sits in the
 * same room as the camera. It cannot work from a hosted server: that address is
 * private to the lab network and unreachable from the internet.
 *
 * So the flow inverts. The admin clicks Capture Baseline, capture_frame.php
 * queues a command, the detector sees it on its next poll, grabs a frame from
 * the camera it CAN reach, and posts it here. Same button, same result, one
 * poll interval slower.
 *
 * Writes both copies main.py and the ROI editor depend on:
 *   photos/ref_image_{ROOM}.jpg          — what main.py calibrates against
 *   uploads/snapshots/{ROOM}_baseline.jpg — what the ROI editor draws on
 * These must stay byte-identical, otherwise ROI boxes drawn in the editor would
 * not line up with the image the detector is comparing against.
 *
 * MICROSERVICES: touches spotit_monitor_db only, to stamp rooms.last_calibrated.
 */

require_once __DIR__ . '/detector_state.php';
require_once __DIR__ . '/../services/monitoring/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required.']);
    exit();
}

detector_require_key();

$roomId = detector_clean_room($_POST['room_id'] ?? '');
if ($roomId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Valid room_id required.']);
    exit();
}

if (!isset($_FILES['baseline']) || $_FILES['baseline']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No baseline uploaded.']);
    exit();
}

$info = @getimagesize($_FILES['baseline']['tmp_name']);
if ($info === false || $info[2] !== IMAGETYPE_JPEG) {
    http_response_code(415);
    echo json_encode(['success' => false, 'message' => 'Baseline must be a JPEG.']);
    exit();
}

$bytes = @file_get_contents($_FILES['baseline']['tmp_name']);
if ($bytes === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not read uploaded baseline.']);
    exit();
}

// Both destinations, from the same bytes, so they cannot drift apart.
$refOk  = detector_atomic_write(detector_ref_image_file($roomId), $bytes);
$copyOk = detector_atomic_write(detector_baseline_copy_file($roomId), $bytes);

if (!$refOk || !$copyOk) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Could not store baseline (check write permissions on photos/ and uploads/snapshots/).',
    ]);
    exit();
}

// Stamp the room so camera-setup.php can show when it was last calibrated, and
// so the staleness warning in the ROI editor has something to measure against.
try {
    $monitorPdo->prepare("UPDATE rooms SET last_calibrated = NOW() WHERE room_id = ?")
               ->execute([$roomId]);
} catch (Throwable $e) {
    // A missing rooms row must not lose us a valid baseline — the files are the
    // thing that actually matters to detection.
    error_log("[spotit] ingest_baseline: could not stamp last_calibrated for {$roomId}: " . $e->getMessage());
}

echo json_encode([
    'success'          => true,
    'message'          => "Baseline stored for {$roomId}.",
    'bytes'            => strlen($bytes),
    'dimensions'       => $info[0] . 'x' . $info[1],
    'config_version'   => detector_config_version($roomId),
]);
