<?php
/**
 * S.P.O.T.-IT — Detector heartbeat
 * auth/detector_heartbeat.php
 *
 * Two callers, two directions:
 *
 *   POST + api_key   — main.py checking in ("I am alive, here is my FPS").
 *   GET  + session   — the dashboard asking whether the detector is alive.
 *
 * WHY THIS MATTERS ONCE main.py IS A BACKGROUND SERVICE
 * Today you can see the detector is running because its terminal is on screen.
 * On the mini-PC there is no terminal — it starts at boot and runs unattended.
 * Without a heartbeat, a crashed detector looks exactly like a quiet room: all
 * boxes green, no alerts, nothing obviously wrong. That is the worst possible
 * failure for a security system, because it fails silently in the safe-looking
 * direction.
 *
 * MICROSERVICES: no database. Health is transient state, not a record.
 */

require_once __DIR__ . '/detector_state.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── Detector reporting in ─────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    detector_require_key();

    $roomId = detector_clean_room($_POST['room_id'] ?? '');
    if ($roomId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Valid room_id required.']);
        exit();
    }

    detector_record_heartbeat($roomId, [
        'fps'      => isset($_POST['fps']) ? round((float) $_POST['fps'], 1) : null,
        'camera'   => substr(trim((string) ($_POST['camera'] ?? 'unknown')), 0, 32),
        'deploy'   => substr(trim((string) ($_POST['deploy'] ?? 'unknown')), 0, 16),
        'rois'     => isset($_POST['roi_count']) ? (int) $_POST['roi_count'] : null,
        'missing'  => isset($_POST['missing_count']) ? (int) $_POST['missing_count'] : null,
        'version'  => substr(trim((string) ($_POST['version'] ?? '')), 0, 32),
    ]);

    echo json_encode([
        'success'        => true,
        'viewers_active' => detector_viewers_active($roomId),
        'server_time'    => time(),
    ]);
    exit();
}

// ── Dashboard asking ──────────────────────────────────────────────────────────
// Session-authenticated: this reveals operational state, so it is staff-only.
require_once __DIR__ . '/service_bootstrap.php';

$role = $_SESSION['user_role'] ?? '';
session_write_close();

if (!in_array($role, ['admin', 'staff'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}

$roomId = detector_clean_room($_GET['room_id'] ?? '');
if ($roomId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Valid room_id required.']);
    exit();
}

$health = detector_health($roomId);

// A dashboard asking about health is also a dashboard that is probably showing
// the live view, so this doubles as the signal that keeps frame push at full
// rate. Cheaper than a separate "I am watching" request.
if (!empty($_GET['watching'])) {
    detector_mark_viewer($roomId);
}

echo json_encode([
    'success' => true,
    'room_id' => $roomId,
    'health'  => $health,
    'deploy'  => SPOTIT_DEPLOY,
]);
