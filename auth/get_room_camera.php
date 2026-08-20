<?php
/**
 * S.P.O.T.-IT — Get Room Camera Config Endpoint
 * auth/get_room_camera.php
 *
 * GET: Returns camera configurations (ip, user, pass, and RTSP URL) for a specified room.
 * Accessible by Python daemon (API Key auth) or Admin session.
 */
require_once __DIR__ . '/service_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

$apiKey = $_SERVER['HTTP_X_API_KEY'] ?? $_GET['api_key'] ?? '';
$isValidKey = false;
if ($apiKey !== '') {
    $expectedKey = getenv('SPOTIT_DETECTION_KEY') ?: 'CHANGE_ME_DETECTION_KEY';
    $isValidKey = hash_equals($expectedKey, $apiKey);
}

if (!$isValidKey && !in_array($_SESSION['user_role'] ?? '', ['staff', 'admin'], true)) {
    ms_json(['success' => false, 'message' => 'Unauthorized'], 403);
}

$roomId = strtoupper(trim($_GET['room_id'] ?? ''));
if ($roomId === '') {
    ms_json(['success' => false, 'message' => 'room_id required'], 400);
}

try {
    $stmt = $monitorPdo->prepare("SELECT camera_ip, camera_user, camera_pass, is_enabled FROM rooms WHERE room_id = ? LIMIT 1");
    $stmt->execute([$roomId]);
    $room = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$room) {
        ms_json(['success' => false, 'message' => 'Room not found'], 404);
    }

    // A disabled room has released its camera. This is the gate that actually
    // stops main.py monitoring a parked room: it reports configured=false, which
    // main.py already handles by dropping to STANDBY rather than crashing.
    // Reported as success:true so the detector treats it as "nothing to watch"
    // instead of a transport failure it should retry.
    if (!(int)($room['is_enabled'] ?? 1)) {
        ms_json([
            'success'    => true,
            'configured' => false,
            'disabled'   => true,
            'message'    => 'Room is disabled. Its camera has been released for reassignment.'
        ]);
    }

    if (empty($room['camera_ip'])) {
        ms_json([
            'success' => true,
            'configured' => false,
            'message' => 'No camera configured for this room.'
        ]);
    } else {
        $ip = $room['camera_ip'];
        $user = $room['camera_user'] ?: 'admin';
        $pass = $room['camera_pass'] ?: '';
        $quality = (($_GET['quality'] ?? '') === 'low') ? 'stream2' : 'stream1';
        
        $rtspUrl = "rtsp://{$user}:{$pass}@{$ip}:554/{$quality}";
        ms_json([
            'success' => true,
            'configured' => true,
            'ip' => $ip,
            'user' => $user,
            'pass' => $pass,
            'rtsp_url' => $rtspUrl
        ]);
    }
} catch (Throwable $e) {
    ms_json(['success' => false, 'message' => 'DB error: ' . $e->getMessage()], 500);
}
