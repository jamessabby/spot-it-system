<?php
require_once __DIR__ . '/service_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

ms_require_auth('../pages/login.php');

if (!in_array($_SESSION['user_role'] ?? '', ['staff', 'admin'], true)) {
    ms_json(['success' => false, 'message' => 'Unauthorized.'], 403);
}

$roomId = trim($_GET['room_id'] ?? 'DESK');

$cameraConfig = [
    'MLH306' => [
        1 => ['ip' => getenv('TAPO_MLH306_CAM1_IP') ?: '192.168.1.101', 'user' => 'admin', 'pass' => getenv('TAPO_MLH306_CAM1_PASS') ?: ''],
    ],
    'MLH305' => [
        1 => ['ip' => getenv('TAPO_MLH305_CAM1_IP') ?: '192.168.1.103', 'user' => 'admin', 'pass' => getenv('TAPO_MLH305_CAM1_PASS') ?: ''],
    ],
    'DESK' => [
        1 => ['ip' => '192.168.18.11', 'user' => 'SpotItCamera', 'pass' => 'spotittapo232'],
    ],
];

if (!isset($cameraConfig[$roomId][1])) {
    ms_json(['success' => false, 'message' => 'Camera not configured for this room.']);
}

$cam = $cameraConfig[$roomId][1];
$rtsp = "rtsp://{$cam['user']}:{$cam['pass']}@{$cam['ip']}:554/stream1";

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

$targetRef = __DIR__ . '/../photos/ref_image.jpg';
$targetUpload = __DIR__ . "/../uploads/snapshots/{$roomId}_baseline.jpg";

@mkdir(dirname($targetRef), 0777, true);
@mkdir(dirname($targetUpload), 0777, true);

// Smart active stream clone: Use the live snapshot if python script is active
// to avoid Tapo single-stream RTSP connection lock.
$liveSnapshot = __DIR__ . "/../uploads/snapshots/clean_{$roomId}.jpg";
$usedLive = false;

if (file_exists($liveSnapshot) && (time() - filemtime($liveSnapshot)) < 15) {
    if (copy($liveSnapshot, $targetRef)) {
        $usedLive = true;
    }
}

if (!$usedLive) {
    if (file_exists($targetRef)) {
        @unlink($targetRef);
    }
    $cmd = '"' . $ffmpegPath . '"'
        . " -rtsp_transport tcp -y -i " . escapeshellarg($rtsp)
        . " -vframes 1 -vf scale=960:-2 -f image2 " . escapeshellarg($targetRef);

    shell_exec($cmd);
}

if (file_exists($targetRef) && filesize($targetRef) > 1000) {
    copy($targetRef, $targetUpload);
    
    try {
        $relPath = "uploads/snapshots/{$roomId}_baseline.jpg";
        $monitorPdo->prepare("UPDATE rooms SET baseline_image = ?, last_calibrated = NOW() WHERE room_id = ?")
                    ->execute([$relPath, $roomId]);
    } catch (PDOException $e) {
        // Ignore DB update error
    }

    ms_json([
        'success' => true,
        'message' => $usedLive ? 'Baseline cloned from active stream!' : 'Reference frame captured successfully!',
        'image_url' => "../uploads/snapshots/{$roomId}_baseline.jpg?t=" . time()
    ]);
} else {
    ms_json(['success' => false, 'message' => 'Failed to capture frame from Tapo camera RTSP stream. Check camera network.']);
}
