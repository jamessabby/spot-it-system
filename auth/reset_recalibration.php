<?php
/**
 * auth/reset_recalibration.php
 * Signal main.py to unpause monitoring and clear mass-deviation alerts.
 */
require_once __DIR__ . '/service_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

// Early validation
$userRole = $_SESSION['user_role'] ?? '';
session_write_close();

if ($userRole !== 'admin' && $userRole !== 'staff') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$roomId = strtoupper(trim($_POST['room_id'] ?? 'DESK'));
if (!$roomId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'room_id required']);
    exit;
}

// Signal main.py to unpause via detection_mode.json (no DB lock contention)
$mode_file = __DIR__ . '/../detection_mode.json';

// Early exit if config file doesn't exist
if (!file_exists($mode_file)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Config file not found']);
    exit;
}

try {
    $current = json_decode(file_get_contents($mode_file), true) ?? [];
    $current['reset_paused'] = true;
    $current['reset_timestamp'] = time();
    $current['updated_at'] = date('Y-m-d H:i:s');

    if (!file_put_contents($mode_file, json_encode($current, JSON_PRETTY_PRINT))) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to write config']);
        exit;
    }

    // Success — immediate response, main.py will handle unpause on next loop
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => "Recalibration alert dismissed. Monitoring unpausing on next loop."
    ]);
    exit;

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
    exit;
}
