<?php
/**
 * auth/dismiss_alerts.php
 * POST: Signal main.py to dismiss active alerts for a room.
 */
require_once __DIR__ . '/service_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

// Early validation
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'POST required']);
    exit;
}

$role = $_SESSION['user_role'] ?? '';
session_write_close();

if (!in_array($role, ['admin', 'staff'], true)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$roomId = strtoupper(trim($_POST['room_id'] ?? ''));
if ($roomId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'room_id required']);
    exit;
}

// Signal main.py to dismiss alerts via detection_mode.json (no DB lock contention).
// main.py will clear its internal state on next loop.
$mode_file = __DIR__ . '/../detection_mode.json';

// Early exit if mode file doesn't exist and can't be created
if (!file_exists($mode_file)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Config file not found']);
    exit;
}

try {
    $current = json_decode(file_get_contents($mode_file), true) ?? [];
    $current['clear_alerts'] = true;
    $current['clear_alerts_timestamp'] = time();
    $current['updated_at'] = date('Y-m-d H:i:s');

    if (!file_put_contents($mode_file, json_encode($current, JSON_PRETTY_PRINT))) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to write config']);
        exit;
    }

    // Success — immediate response
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => "Dismiss signal sent. Alerts clearing on next loop."
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    exit;
}
