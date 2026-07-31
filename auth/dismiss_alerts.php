<?php
/**
 * auth/dismiss_alerts.php
 * POST: Mark all active detections for a room as 'recovered' to dismiss false alarms.
 */
require_once __DIR__ . '/service_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'POST required'], 405);
}

$role = $_SESSION['user_role'] ?? '';
session_write_close();

if (!in_array($role, ['admin', 'staff'], true)) {
    ms_json(['success' => false, 'message' => 'Unauthorized'], 403);
}

$roomId = strtoupper(trim($_POST['room_id'] ?? ''));
if ($roomId === '') {
    ms_json(['success' => false, 'message' => 'room_id required'], 400);
}

try {
    // Signal main.py to dismiss alerts via detection_mode.json (no DB lock contention).
    // main.py will clear its internal state on next loop and stop creating detections.
    $mode_file = __DIR__ . '/../detection_mode.json';
    $current = [];
    if (file_exists($mode_file)) {
        $current = json_decode(file_get_contents($mode_file), true) ?? [];
    }

    $current['clear_alerts'] = true;
    $current['clear_alerts_timestamp'] = time();
    $current['updated_at'] = date('Y-m-d H:i:s');

    file_put_contents($mode_file, json_encode($current, JSON_PRETTY_PRINT));

    ms_json([
        'success' => true,
        'message' => "Dismiss signal sent to monitoring system for room {$roomId}. Alerts clearing...",
        'dismissed' => 0
    ]);
} catch (Exception $e) {
    ms_json(['success' => false, 'message' => 'Error: ' . $e->getMessage()], 500);
}
