<?php
/**
 * S.P.O.T.-IT — Enable / Disable Room Handler
 * auth/toggle_room_enabled.php
 *
 * POST endpoint. Admin-only. Takes a room in or out of service.
 *
 * WHY THIS EXISTS
 * There is one physical Tapo camera and several candidate rooms registered for
 * testing, so staff need to move that camera from room to room. Deleting the
 * room to free its IP would take the baseline, the registered ROI items and the
 * whole detection history with it (delete_room.php is a hard DELETE). Disabling
 * keeps every one of those rows and only releases the room's claim on
 * camera_ip — see ms_camera_ip_conflict() in service_bootstrap.php.
 *
 * NOT the same flag as is_active:
 *   is_active  = row lifecycle  (0 = soft-deleted, hidden everywhere)
 *   is_enabled = service state  (0 = parked by an admin, still listed and editable)
 * See services/migration_room_enabled.sql for the full reasoning.
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

ms_require_auth('../pages/login.php');
ms_require_role('admin', '../pages/login.php');

$roomId  = strtoupper(trim($_POST['room_id'] ?? ''));
$enabled = isset($_POST['enabled']) ? (int)$_POST['enabled'] : null;

if ($roomId === '') {
    ms_json(['success' => false, 'message' => 'Room ID is required.']);
}
if ($enabled !== 0 && $enabled !== 1) {
    ms_json(['success' => false, 'message' => 'enabled must be 0 or 1.']);
}

try {
    $stmt = $monitorPdo->prepare(
        "SELECT room_id, room_name, camera_ip, is_enabled FROM rooms
         WHERE room_id = ? AND is_active = 1 LIMIT 1"
    );
    $stmt->execute([$roomId]);
    $room = $stmt->fetch();

    if (!$room) {
        ms_json(['success' => false, 'message' => 'Room not found.']);
    }

    // Re-enabling can reintroduce a camera clash: the IP may have been handed to
    // another room while this one was parked. Check before flipping it back on.
    if ($enabled === 1) {
        $conflict = ms_camera_ip_conflict($monitorPdo, (string)($room['camera_ip'] ?? ''), $roomId);
        if ($conflict) {
            ms_json([
                'success' => false,
                'message' => "Cannot enable {$roomId}: its camera {$room['camera_ip']} is now assigned to "
                           . "{$conflict['room_id']} ({$conflict['room_name']}). Disable that room first, "
                           . "or clear this room's camera IP."
            ]);
        }
    }

    // Disabling also stops monitoring. Leaving monitoring_status = 'active' on a
    // parked room would keep it showing as live on the dashboards.
    if ($enabled === 0) {
        $monitorPdo->prepare(
            "UPDATE rooms SET is_enabled = 0, monitoring_status = 'inactive' WHERE room_id = ?"
        )->execute([$roomId]);
    } else {
        $monitorPdo->prepare(
            "UPDATE rooms SET is_enabled = 1 WHERE room_id = ?"
        )->execute([$roomId]);
    }

    $action = $enabled === 1 ? 'enabled' : 'disabled';

    $monitorPdo->prepare(
        "INSERT INTO monitoring_logs (room_id, event_type, event_message, triggered_by)
         VALUES (?, ?, ?, ?)"
    )->execute([
        $roomId,
        'room_' . $action,
        "Room {$roomId} {$action} by admin."
            . ($enabled === 0 && !empty($room['camera_ip'])
                ? " Camera {$room['camera_ip']} released for reassignment."
                : ''),
        $_SESSION['user_id'] ?? null,
    ]);

    // If the detector is currently pointed at this room, say so — main.py will
    // drop to STANDBY on its next poll because get_room_camera.php now refuses
    // to hand out credentials for a disabled room.
    $detectorOnThisRoom = false;
    $modeFile = __DIR__ . '/../detection_mode.json';
    if ($enabled === 0 && is_readable($modeFile)) {
        $mode = json_decode((string)file_get_contents($modeFile), true);
        $detectorOnThisRoom = is_array($mode)
            && strtoupper((string)($mode['room_id'] ?? '')) === $roomId;
    }

    $message = "Room {$roomId} {$action}.";
    if ($enabled === 0) {
        $message .= !empty($room['camera_ip'])
            ? " Camera {$room['camera_ip']} is now free to assign to another room."
            : '';
        if ($detectorOnThisRoom) {
            $message .= ' The detector was watching this room — it will go to standby.';
        }
    }

    ms_json([
        'success'    => true,
        'room_id'    => $roomId,
        'is_enabled' => $enabled,
        'message'    => $message,
    ]);

} catch (PDOException $e) {
    ms_json(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
