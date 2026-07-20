<?php
/**
 * auth/reset_recalibration.php
 * Endpoint to reject/dismiss mass deviation recalibration alerts and unpause live monitoring.
 * Updates detection_mode.json with a reset timestamp so main.py immediately unpauses.
 */
require_once __DIR__ . '/service_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SESSION['user_role'] ?? '') !== 'admin' && ($_SESSION['user_role'] ?? '') !== 'staff') {
    ms_json(['success' => false, 'message' => 'Unauthorized.'], 403);
}

$roomId = trim($_POST['room_id'] ?? 'DESK');

try {
    // 1. Clear active mass-deviation & pending false-alarm detections for this room
    $stmt = $monitorPdo->prepare("DELETE FROM detections WHERE room_id = ? AND status IN ('pending', 'potential')");
    $stmt->execute([$roomId]);

    // 2. Clear sandbox monitoring logs if DESK
    $stmtLogs = $monitorPdo->prepare("DELETE FROM monitoring_logs WHERE room_id = ? AND event_type LIKE '%mass_deviation%'");
    $stmtLogs->execute([$roomId]);

    // 3. Reset room monitoring status to active
    $monitorPdo->prepare("UPDATE rooms SET monitoring_status = 'active' WHERE room_id = ?")->execute([$roomId]);

    // 4. Update detection_mode.json to signal main.py to unpause
    $mode_file = __DIR__ . '/../detection_mode.json';
    $current = [];
    if (file_exists($mode_file)) {
        $current = json_decode(file_get_contents($mode_file), true) ?? [];
    }

    $current['reset_paused'] = true;
    $current['reset_timestamp'] = time();
    $current['updated_at'] = date('Y-m-d H:i:s');

    file_put_contents($mode_file, json_encode($current, JSON_PRETTY_PRINT));

    ms_json([
        'success' => true,
        'message' => "Recalibration alert dismissed for room '$roomId'. Live monitoring unpaused."
    ]);

} catch (PDOException $e) {
    ms_json(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
}
