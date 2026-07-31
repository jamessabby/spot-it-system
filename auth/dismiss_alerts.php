<?php
/**
 * auth/dismiss_alerts.php
 * POST: Mark all active detections for a room as 'recovered', and signal main.py
 * to reset its in-memory timers so it does not immediately re-fire the same alert.
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

try {
    // 1. Resolve the open alerts in the database.
    $stmt = $monitorPdo->prepare("
        UPDATE detections
        SET status = 'recovered', is_removed = 1, updated_at = NOW()
        WHERE room_id = ? AND status IN ('pending', 'potential', 'confirmed_missing') AND is_removed = 0
    ");
    $stmt->execute([$roomId]);
    $affected = $stmt->rowCount();

    // 2. Tell main.py to reset its in-memory confirmation timers for this room.
    //    Without this it still holds absent_time >= MISSING_CONFIRM_SEC and would
    //    re-raise the same alert on its very next frame.
    $mode_file = __DIR__ . '/../detection_mode.json';
    if (file_exists($mode_file)) {
        $current = json_decode(file_get_contents($mode_file), true) ?? [];
        $current['clear_alerts'] = true;
        $current['clear_alerts_timestamp'] = time();
        $current['updated_at'] = date('Y-m-d H:i:s');
        file_put_contents($mode_file, json_encode($current, JSON_PRETTY_PRINT));
    }

    echo json_encode([
        'success' => true,
        'message' => "Dismissed {$affected} active alert(s) for room {$roomId}.",
        'dismissed' => $affected
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB error: ' . $e->getMessage()]);
}
