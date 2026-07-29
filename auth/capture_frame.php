<?php
/**
 * S.P.O.T.-IT — Capture Baseline Frame
 * auth/capture_frame.php
 *
 * GET/POST endpoint. Captures a single baseline frame from the RTSP camera stream 
 * or live snapshot buffer for the specified room. Saves to photos/ref_image_{room_id}.jpg 
 * and updates rooms table in spotit_monitor_db.
 */
require_once __DIR__ . '/service_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

ms_require_auth('../pages/login.php');

if (!in_array($_SESSION['user_role'] ?? '', ['staff', 'admin'], true)) {
    ms_json(['success' => false, 'message' => 'Unauthorized.'], 403);
}

$roomId = strtoupper(trim($_REQUEST['room_id'] ?? 'DESK'));

// Fetch room camera credentials from spotit_monitor_db
$rtsp = 'rtsp://SpotItCamera:spotittapo232@192.168.18.11:554/stream1';
try {
    $camStmt = $monitorPdo->prepare("SELECT camera_ip, camera_user, camera_pass FROM rooms WHERE room_id = ?");
    $camStmt->execute([$roomId]);
    $camDb = $camStmt->fetch();
    if ($camDb && !empty($camDb['camera_ip'])) {
        $camIp   = $camDb['camera_ip'];
        $camUser = $camDb['camera_user'] ?: 'SpotItCamera';
        $camPass = $camDb['camera_pass'] ?: 'spotittapo232';
        $rtsp    = "rtsp://{$camUser}:{$camPass}@{$camIp}:554/stream1";
    }
} catch (Exception $e) {
    // Fallback to default RTSP URL
}

// Per-room target paths
$targetRef    = ($roomId === 'DESK') ? __DIR__ . '/../photos/ref_image.jpg' : __DIR__ . "/../photos/ref_image_{$roomId}.jpg";
$targetUpload = __DIR__ . "/../uploads/snapshots/{$roomId}_baseline.jpg";

@mkdir(dirname($targetRef), 0777, true);
@mkdir(dirname($targetUpload), 0777, true);

// Smart active stream clone: Use the live snapshot if python detector is active
$cleanSnapshot = __DIR__ . "/../uploads/snapshots/clean_{$roomId}.jpg";
$liveSnapshot  = __DIR__ . "/../uploads/snapshots/live_{$roomId}.jpg";
$usedLive = false;

if (file_exists($cleanSnapshot) && filesize($cleanSnapshot) > 1000) {
    if (@copy($cleanSnapshot, $targetRef)) {
        $usedLive = true;
    }
} elseif (file_exists($liveSnapshot) && filesize($liveSnapshot) > 1000) {
    if (@copy($liveSnapshot, $targetRef)) {
        $usedLive = true;
    }
}

if (!$usedLive) {
    // Locate FFmpeg executable
    $ffmpegPath = '';
    if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        $winPath = trim(shell_exec('where ffmpeg 2>nul') ?: '');
        if ($winPath) {
            $lines = explode("\n", $winPath);
            $ffmpegPath = trim($lines[0]);
        }
    } else {
        $ffmpegPath = trim(shell_exec('which ffmpeg 2>/dev/null') ?: '');
    }
    if (!$ffmpegPath) {
        $test = @shell_exec('ffmpeg -version 2>&1');
        if (stripos($test, 'ffmpeg version') !== false) {
            $ffmpegPath = 'ffmpeg';
        }
    }

    if (!$ffmpegPath) {
        ms_json(['success' => false, 'message' => 'FFmpeg is not installed or configured on the server PATH.']);
    }

    $cmd = '"' . $ffmpegPath . '"'
        . " -rtsp_transport tcp -y -i " . escapeshellarg($rtsp)
        . " -vframes 1 -vf scale=960:-2 -f image2 " . escapeshellarg($targetRef);

    shell_exec($cmd);
}

if (file_exists($targetRef) && filesize($targetRef) > 1000) {
    copy($targetRef, $targetUpload);

    // Also copy to photos/ref_image.jpg if DESK to ensure sandbox compatibility
    if ($roomId === 'DESK') {
        @copy($targetRef, __DIR__ . '/../photos/ref_image.jpg');
    }
    
    try {
        $relPath = "uploads/snapshots/{$roomId}_baseline.jpg";
        $monitorPdo->prepare("UPDATE rooms SET baseline_image = ?, last_calibrated = NOW() WHERE room_id = ?")
                    ->execute([$relPath, $roomId]);
    } catch (PDOException $e) {
        // Ignore DB update error
    }

    // Trigger immediate dynamic reload in main.py
    $dmFile = __DIR__ . '/../detection_mode.json';
    if (file_exists($dmFile)) {
        $dm = json_decode(file_get_contents($dmFile), true) ?: [];
        $dm['reset_rois'] = true;
        $dm['reset_paused'] = true;
        $dm['updated_at'] = date('Y-m-d H:i:s');
        @file_put_contents($dmFile, json_encode($dm, JSON_PRETTY_PRINT));
    }

    ms_json(['success' => true, 'message' => 'Baseline captured successfully!']);
} else {
    ms_json(['success' => false, 'message' => 'Failed to capture frame from RTSP stream. Check camera IP and power.']);
}
