<?php
/**
 * auth/reset_recalibration.php
 * Endpoint to reject/dismiss mass deviation recalibration alerts and unpause live monitoring.
 * Updates detection_mode.json with a reset timestamp so main.py immediately unpauses.
 */
require_once __DIR__ . '/service_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

// Release session lock early to prevent queue hangs
$userRole = $_SESSION['user_role'] ?? '';
session_write_close();

if ($userRole !== 'admin' && $userRole !== 'staff') {
    ms_json(['success' => false, 'message' => 'Unauthorized.'], 403);
}

$roomId = trim($_POST['room_id'] ?? 'DESK');

try {
    // Signal main.py to unpause via detection_mode.json (no DB lock contention)
    $mode_file = __DIR__ . '/../detection_mode.json';
    $current = [];
    if (file_exists($mode_file)) {
        $current = json_decode(file_get_contents($mode_file), true) ?? [];
    }

    $current['reset_paused'] = true;
    $current['reset_timestamp'] = time();
    $current['updated_at'] = date('Y-m-d H:i:s');

    file_put_contents($mode_file, json_encode($current, JSON_PRETTY_PRINT));

    // main.py will handle clearing its state & detections on next loop.
    // No database ops here to avoid lock contention with live detection writes.

    ms_json([
        'success' => true,
        'message' => "Recalibration alert dismissed for room '$roomId'. Live monitoring unpaused."
    ]);

} catch (Exception $e) {
    ms_json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
}
