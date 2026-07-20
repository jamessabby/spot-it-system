<?php
/**
 * S.P.O.T.-IT — Add Room Handler
 * auth/add_room.php
 *
 * POST endpoint. Admin-only. Inserts a new room into spotit_monitor_db.
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

ms_require_auth('../pages/login.php');
ms_require_role('admin', '../pages/login.php');

$roomId      = strtoupper(trim($_POST['room_id'] ?? ''));
$roomName    = trim($_POST['room_name'] ?? '');
$floor       = trim($_POST['floor'] ?? '');
$roomType    = trim($_POST['room_type'] ?? '');
$cameraCount = isset($_POST['camera_count']) ? (int)$_POST['camera_count'] : 2;

if (!$roomId || !$roomName || !$floor || !$roomType) {
    ms_json(['success' => false, 'message' => 'All fields (Room ID, Name, Floor, Type) are required.']);
}

if (strlen($roomId) > 20) {
    ms_json(['success' => false, 'message' => 'Room ID must be 20 characters or less.']);
}

// Check if room_id already exists in spotit_monitor_db
try {
    $stmt = $monitorPdo->prepare("SELECT is_active FROM rooms WHERE room_id = ? LIMIT 1");
    $stmt->execute([$roomId]);
    $existing = $stmt->fetch();

    if ($existing) {
        if ($existing['is_active']) {
            ms_json(['success' => false, 'message' => 'Room ID already exists.']);
        } else {
            // Reactivate soft-deleted room
            $updateStmt = $monitorPdo->prepare("
                UPDATE rooms 
                SET room_name = ?, floor = ?, room_type = ?, camera_count = ?, is_active = 1, monitoring_status = 'inactive'
                WHERE room_id = ?
            ");
            $updateStmt->execute([$roomName, $floor, $roomType, $cameraCount, $roomId]);
            ms_json(['success' => true, 'message' => 'Room reactivated and updated successfully.']);
        }
    } else {
        // Insert new room
        $insertStmt = $monitorPdo->prepare("
            INSERT INTO rooms (room_id, room_name, floor, room_type, camera_count, baseline_count, monitoring_status, is_active)
            VALUES (?, ?, ?, ?, ?, 0, 'inactive', 1)
        ");
        $insertStmt->execute([$roomId, $roomName, $floor, $roomType, $cameraCount]);
        ms_json(['success' => true, 'message' => 'Room added successfully.']);
    }
} catch (PDOException $e) {
    ms_json(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
