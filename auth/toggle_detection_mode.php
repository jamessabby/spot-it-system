<?php
/**
 * auth/toggle_detection_mode.php
 * Handles toggling active room_id, tracking_mode ('registered' | 'unregistered'),
 * mode ('testing' | 'production'), and timer_mode ('testing_speed' | 'production_speed').
 */
require_once __DIR__ . '/service_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SESSION['user_role'] ?? '') !== 'admin' && ($_SESSION['user_role'] ?? '') !== 'staff') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$mode_file = __DIR__ . '/../detection_mode.json';
$current   = [];
if (file_exists($mode_file)) {
    $current = json_decode(file_get_contents($mode_file), true) ?? [];
}

$room_id       = trim($_POST['room_id']       ?? '');
$tracking_mode = trim($_POST['tracking_mode'] ?? '');
$mode          = trim($_POST['mode']          ?? '');
$timer_mode    = trim($_POST['timer_mode']     ?? '');

if ($room_id !== '') {
    $current['room_id'] = strtoupper($room_id);
}

if ($tracking_mode && in_array($tracking_mode, ['registered', 'unregistered'], true)) {
    $current['tracking_mode'] = $tracking_mode;
}

if ($mode && in_array($mode, ['testing', 'production'], true)) {
    $current['mode'] = $mode;
}

if ($timer_mode && in_array($timer_mode, ['testing_speed', 'production_speed'], true)) {
    $current['timer_mode'] = $timer_mode;
}

$current['updated_at'] = date('Y-m-d H:i:s');

if (file_put_contents($mode_file, json_encode($current, JSON_PRETTY_PRINT)) !== false) {
    echo json_encode([
        'success'       => true, 
        'room_id'       => $current['room_id'] ?? 'DESK',
        'mode'          => $current['mode'] ?? 'testing',
        'tracking_mode' => $current['tracking_mode'] ?? 'registered',
        'timer_mode'    => $current['timer_mode'] ?? 'testing_speed'
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to update detection_mode.json']);
}
