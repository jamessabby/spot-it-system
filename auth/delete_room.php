<?php
/**
 * S.P.O.T.-IT — Delete Room Handler
 * auth/delete_room.php
 *
 * POST endpoint. Admin-only. Deletes a room and cleans up all related logs/detections.
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

ms_require_auth('../pages/login.php');
ms_require_role('admin', '../pages/login.php');

$roomId = trim($_POST['room_id'] ?? '');

if (!$roomId) {
    ms_json(['success' => false, 'message' => 'Room ID is required.']);
}

try {
    $monitorPdo->beginTransaction();

    // 1. Delete registered items (FK constraints should cascade, but we make sure)
    $stmtItems = $monitorPdo->prepare("DELETE FROM registered_lab_items WHERE room_id = ?");
    $stmtItems->execute([$roomId]);

    // 2. Delete detections
    $stmtDetections = $monitorPdo->prepare("DELETE FROM detections WHERE room_id = ?");
    $stmtDetections->execute([$roomId]);

    // 3. Delete monitoring logs
    $stmtLogs = $monitorPdo->prepare("DELETE FROM monitoring_logs WHERE room_id = ?");
    $stmtLogs->execute([$roomId]);

    // 4. Delete the room itself
    $stmtRoom = $monitorPdo->prepare("DELETE FROM rooms WHERE room_id = ?");
    $stmtRoom->execute([$roomId]);

    if ($stmtRoom->rowCount() === 0) {
        $monitorPdo->rollBack();
        ms_json(['success' => false, 'message' => 'Room not found.']);
    }

    $monitorPdo->commit();
    ms_json(['success' => true, 'message' => "Room '$roomId' and all associated data deleted successfully."]);

} catch (PDOException $e) {
    if ($monitorPdo->inTransaction()) {
        $monitorPdo->rollBack();
    }
    ms_json(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
