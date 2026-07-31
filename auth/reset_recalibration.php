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
    // 1. Clear the mass-deviation / pending false-alarm detections for this room.
    $monitorPdo->prepare(
        "DELETE FROM detections WHERE room_id = ? AND status IN ('pending', 'potential')"
    )->execute([$roomId]);

    // 2. Clear the mass-deviation monitoring log entries.
    $monitorPdo->prepare(
        "DELETE FROM monitoring_logs WHERE room_id = ? AND event_type LIKE '%mass_deviation%'"
    )->execute([$roomId]);

    // 3. Mark the room active again.
    $monitorPdo->prepare(
        "UPDATE rooms SET monitoring_status = 'active' WHERE room_id = ?"
    )->execute([$roomId]);

    // 4. Signal main.py to unpause and reset its in-memory state.
    $current = json_decode(file_get_contents($mode_file), true) ?? [];
    $current['reset_paused'] = true;
    $current['reset_timestamp'] = time();
    $current['updated_at'] = date('Y-m-d H:i:s');
    file_put_contents($mode_file, json_encode($current, JSON_PRETTY_PRINT));

    echo json_encode([
        'success' => true,
        'message' => "Recalibration alert dismissed for room '$roomId'. Live monitoring unpaused."
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
