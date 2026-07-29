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
    $stmt = $monitorPdo->prepare("
        UPDATE detections
        SET status = 'recovered', is_removed = 1, updated_at = NOW()
        WHERE room_id = ? AND status IN ('pending', 'potential', 'confirmed_missing') AND is_removed = 0
    ");
    $stmt->execute([$roomId]);
    $affected = $stmt->rowCount();

    ms_json([
        'success' => true,
        'message' => "Dismissed {$affected} active alert(s) for room {$roomId}.",
        'dismissed' => $affected
    ]);
} catch (Throwable $e) {
    ms_json(['success' => false, 'message' => 'DB error: ' . $e->getMessage()], 500);
}
