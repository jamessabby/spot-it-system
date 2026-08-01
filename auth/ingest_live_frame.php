<?php
/**
 * S.P.O.T.-IT — Live frame push
 * auth/ingest_live_frame.php
 *
 * POST (multipart), API-key authenticated. Called by main.py.
 *
 * Receives the annotated frame the detector is already drawing (ROI boxes,
 * similarity scores, state colours) and writes it to
 * uploads/snapshots/live_{ROOM}.jpg — the exact path assets/js/stream-viewer.js
 * already polls. The browser code therefore needs no change at all.
 *
 * WHY THIS REPLACES THE MJPEG PROXY (auth/tapo_stream.php) IN PRODUCTION
 *   1. Reachability. tapo_stream.php runs ffmpeg against rtsp://192.168.x.x.
 *      That address only exists inside the lab; from a hosted server there is
 *      nothing there to connect to.
 *   2. Cost. The MJPEG proxy holds one Apache worker open for as long as each
 *      viewer watches. Shared hosting has a small worker pool, so a handful of
 *      dashboards would exhaust it and the whole site would stop responding.
 *      A written file costs nothing to serve.
 *
 * The detector throttles how often it calls this using `viewers_active` from
 * get_detector_config.php — roughly 1 fps while somebody is watching, and a
 * trickle otherwise. Continuous 1 fps would be ~5 GB per room per day.
 */

require_once __DIR__ . '/detector_state.php';

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

if (!isset($_FILES['frame']) || $_FILES['frame']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No frame uploaded.']);
    exit();
}

// Only ever accept an actual JPEG here. This directory is web-served, so a file
// with a misleading name must not be able to land in it (uploads/.htaccess also
// disables script execution as a second layer).
$tmp = $_FILES['frame']['tmp_name'];
$info = @getimagesize($tmp);
if ($info === false || ($info[2] !== IMAGETYPE_JPEG && $info[2] !== IMAGETYPE_PNG)) {
    http_response_code(415);
    echo json_encode(['success' => false, 'message' => 'Frame must be a JPEG or PNG image.']);
    exit();
}

$bytes = @file_get_contents($tmp);
if ($bytes === false) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not read uploaded frame.']);
    exit();
}

// Atomic: browsers poll this file continuously while it is being replaced, and a
// half-written JPEG renders as a broken image.
$dest = SNAPSHOT_PATH . "live_{$roomId}.jpg";
if (!detector_atomic_write($dest, $bytes)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Could not store frame.']);
    exit();
}

// The detector also sends its clean (un-annotated) frame when it has one; the
// ROI editor and baseline capture prefer that over a frame covered in overlays.
if (isset($_FILES['clean']) && $_FILES['clean']['error'] === UPLOAD_ERR_OK) {
    $cleanInfo = @getimagesize($_FILES['clean']['tmp_name']);
    if ($cleanInfo !== false && $cleanInfo[2] === IMAGETYPE_JPEG) {
        $cleanBytes = @file_get_contents($_FILES['clean']['tmp_name']);
        if ($cleanBytes !== false) {
            detector_atomic_write(SNAPSHOT_PATH . "clean_{$roomId}.jpg", $cleanBytes);
        }
    }
}

// Piggyback the answer the detector needs anyway, so it does not have to make a
// second request just to find out whether to keep pushing at full rate.
echo json_encode([
    'success'        => true,
    'bytes'          => strlen($bytes),
    'viewers_active' => detector_viewers_active($roomId),
]);
