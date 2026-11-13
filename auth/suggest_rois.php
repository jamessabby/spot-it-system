<?php
/**
 * S.P.O.T.-IT — Automatic ROI suggestions (experimental)
 * auth/suggest_rois.php
 *
 *   POST room_id, classes=keyboard,mouse,tv,laptop  -> asks the detector to propose ROIs
 *   GET  room_id                                     -> the detector's latest proposals
 *
 * The detector (main.py) runs YOLO on its FULL-resolution camera frame and
 * converts the boxes into the detection frame's coordinates — the same size as
 * the baseline the ROI editor draws on — so saved boxes line up with what the
 * detector compares. Nothing here touches the database or the ROI files: the
 * admin reviews the proposals in the editor and saves them with the normal
 * save_rois.php. Exchange is via runtime/ files, so it needs local mode (the
 * detector and the web server on one machine).
 */
require_once __DIR__ . '/service_bootstrap.php';
require_once __DIR__ . '/detector_state.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (!in_array($_SESSION['user_role'] ?? '', ['admin', 'staff'], true)) {
    ms_json(['success' => false, 'message' => 'Unauthorized.'], 403);
}
session_write_close();

const SUGGEST_CLASSES = ['keyboard', 'mouse', 'tv', 'laptop', 'cell phone'];

$roomId = detector_clean_room($_REQUEST['room_id'] ?? '');
if ($roomId === '') {
    ms_json(['success' => false, 'message' => 'Valid room_id required.'], 400);
}
$reqFile = RUNTIME_PATH . "suggest_{$roomId}.request.json";
$resFile = RUNTIME_PATH . "suggest_{$roomId}.result.json";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    spotit_require_csrf();
    $classes = array_values(array_intersect(
        SUGGEST_CLASSES,
        array_map('trim', explode(',', (string) ($_POST['classes'] ?? '')))
    ));
    if (!$classes) {
        ms_json(['success' => false, 'message' => 'Choose at least one equipment type.'], 400);
    }
    $health = detector_health($roomId);
    if (!$health['online']) {
        ms_json(['success' => false, 'message' =>
            "The detector for {$roomId} is not running, and suggestions come from its live full-resolution frame. "
            . 'Start main.py for this room, or draw the boxes manually.'], 409);
    }
    if (!is_dir(RUNTIME_PATH)) {
        @mkdir(RUNTIME_PATH, 0775, true);
    }
    $ok = @file_put_contents($reqFile, json_encode([
        'room_id'      => $roomId,
        'classes'      => $classes,
        'requested_at' => date('c'),
        'requested_by' => (int) ($_SESSION['user_id'] ?? 0),
    ])) !== false;
    clearstatcache();
    ms_json($ok
        ? ['success' => true, 'requested_mtime' => filemtime($reqFile), 'classes' => $classes]
        : ['success' => false, 'message' => 'Could not write the suggestion request.'], $ok ? 200 : 500);
}

// GET: latest result, and whether it answers the latest request.
clearstatcache();
$reqMtime = is_file($reqFile) ? filemtime($reqFile) : null;
$result = is_file($resFile) ? json_decode((string) file_get_contents($resFile), true) : null;
$answered = $result && $reqMtime !== null
    && abs((float) ($result['request_mtime'] ?? 0) - $reqMtime) < 1.0;
ms_json([
    'success' => true,
    'pending' => $reqMtime !== null && !$answered,
    'result'  => $answered ? $result : null,
]);
