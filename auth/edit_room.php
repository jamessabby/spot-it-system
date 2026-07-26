<?php
/**
 * S.P.O.T.-IT — Edit Room Handler
 * auth/edit_room.php
 *
 * POST endpoint. Admin-only. Updates an existing room configuration in spotit_monitor_db.
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'staff'], true)) {
    ms_json(['success' => false, 'message' => 'Unauthorized. Admin role required.'], 403);
}

$roomId      = strtoupper(trim($_POST['room_id'] ?? ''));
$roomName    = trim($_POST['room_name'] ?? '');
$floor       = trim($_POST['floor'] ?? '');
$roomType    = trim($_POST['room_type'] ?? '');
$cameraCount = isset($_POST['camera_count']) ? (int)$_POST['camera_count'] : 2;
$cameraIp    = trim($_POST['camera_ip'] ?? '');
$cameraUser  = trim($_POST['camera_user'] ?? '');
$cameraPass  = trim($_POST['camera_pass'] ?? '');

if (!$roomId || !$roomName || !$floor || !$roomType) {
    ms_json(['success' => false, 'message' => 'All fields (Room ID, Name, Floor, Type) are required.']);
}

try {
    // Check if room exists
    $stmt = $monitorPdo->prepare("SELECT COUNT(*) FROM rooms WHERE room_id = ? AND is_active = 1");
    $stmt->execute([$roomId]);
    if ($stmt->fetchColumn() == 0) {
        ms_json(['success' => false, 'message' => 'Room not found.']);
    }

    if ($cameraPass !== '') {
        $updateStmt = $monitorPdo->prepare("
            UPDATE rooms 
            SET room_name = ?, floor = ?, room_type = ?, camera_count = ?, camera_ip = ?, camera_user = ?, camera_pass = ?
            WHERE room_id = ?
        ");
        $updateStmt->execute([$roomName, $floor, $roomType, $cameraCount, $cameraIp ?: null, $cameraUser ?: null, $cameraPass, $roomId]);
    } else {
        $updateStmt = $monitorPdo->prepare("
            UPDATE rooms 
            SET room_name = ?, floor = ?, room_type = ?, camera_count = ?, camera_ip = ?, camera_user = ?
            WHERE room_id = ?
        ");
        $updateStmt->execute([$roomName, $floor, $roomType, $cameraCount, $cameraIp ?: null, $cameraUser ?: null, $roomId]);
    }
    ms_json(['success' => true, 'message' => 'Room configuration updated successfully.']);

} catch (PDOException $e) {
    ms_json(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
