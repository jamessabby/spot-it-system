<?php
/**
 * auth/reset_room_state.php
 * POST: Reset state for a specific production room (not DESK sandbox).
 * Clears ROI zones in registered_lab_items, detection logs, and resets rooms.baseline_count.
 */
require_once __DIR__ . '/service_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'POST required'], 405);
}

if (($_SESSION['user_role'] ?? '') !== 'admin') {
    ms_json(['success' => false, 'message' => 'Admin role required'], 403);
}

$roomId = strtoupper(trim($_POST['room_id'] ?? ''));
if ($roomId === '' || $roomId === 'DESK') {
    ms_json(['success' => false, 'message' => 'Invalid room_id. Use reset_system_state.php for the DESK sandbox.'], 400);
}

try {
    // 1. Clear ROI zones from DB for this room
    try { $monitorPdo->prepare("DELETE FROM registered_lab_items WHERE room_id = ?")->execute([$roomId]); } catch (Throwable $t) {}

    // 2. Clear detection logs for this room
    try { $monitorPdo->prepare("DELETE FROM detections WHERE room_id = ?")->execute([$roomId]); } catch (Throwable $t) {}
    try { $monitorPdo->prepare("DELETE FROM monitoring_logs WHERE room_id = ?")->execute([$roomId]); } catch (Throwable $t) {}
    try { $monitorPdo->prepare("DELETE FROM recalibrations WHERE room_id = ?")->execute([$roomId]); } catch (Throwable $t) {}

    // 3. Reset baseline_count and monitoring_status for this room
    try { $monitorPdo->prepare("UPDATE rooms SET baseline_count = 0, monitoring_status = 'active' WHERE room_id = ?")->execute([$roomId]); } catch (Throwable $t) {}

    // 4. Clear room-specific ROI JSON file
    $roiFile = __DIR__ . "/../rois_{$roomId}.json";
    @file_put_contents($roiFile, json_encode([], JSON_PRETTY_PRINT));

    // 5. Signal main.py to reload
    $modeFile = __DIR__ . '/../detection_mode.json';
    $modeData = [];
    if (file_exists($modeFile)) {
        $modeData = json_decode(file_get_contents($modeFile), true) ?: [];
    }
    $modeData['reset_paused']    = true;
    $modeData['reset_rois']      = true;
    $modeData['reset_timestamp'] = time();
    $modeData['updated_at']      = date('Y-m-d H:i:s');
    @file_put_contents($modeFile, json_encode($modeData, JSON_PRETTY_PRINT));

    ms_json([
        'success' => true,
        'message' => "Room {$roomId} reset: ROI zones cleared, detection logs deleted, baseline reset to 0."
    ]);
} catch (Throwable $e) {
    ms_json(['success' => false, 'message' => 'Reset error: ' . $e->getMessage()], 500);
}
