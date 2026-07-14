<?php
/**
 * S.P.O.T.-IT — Tapo CCTV Stream Handler
 * auth/tapo_stream.php
 *
 * Handles Tapo CCTV camera integration.
 * Tapo cameras (C200, C220, C310, etc.) expose an RTSP stream at:
 *   rtsp://[user]:[pass]@[ip]:554/stream1    (main stream, 1080p)
 *   rtsp://[user]:[pass]@[ip]:554/stream2    (sub-stream, 480p)
 *
 * Browser security prevents direct RTSP playback in <video> tags.
 * This handler uses one of three methods depending on server capability:
 *
 * METHOD A (preferred): MJPEG proxy via PHP+exec ffmpeg
 *   Requires: ffmpeg installed on server
 *   Output: multipart/x-mixed-replace MJPEG stream
 *
 * METHOD B: Snapshot polling (fallback if no ffmpeg)
 *   Fetches JPEG snapshots from Tapo HTTP API every ~1s
 *   Output: JSON with base64 JPEG for JS canvas rendering
 *
 * METHOD C: WebRTC/HLS status (future)
 *   For hosting that supports media servers (e.g. Nginx RTMP module)
 *
 * GET params:
 *   action   string  stream|snapshot|status|config
 *   room_id  string  which room's camera to use
 *   cam      int     1 or 2 (default 1)
 *   quality  string  high|low (default low for web)
 *
 * MICROSERVICES: Reads camera config from env. No DB writes.
 */
require_once __DIR__ . '/service_bootstrap.php';

// Auth check — only admin and staff can view live feeds
ms_require_auth('../pages/login.php');
if (!in_array($_SESSION['user_role'] ?? '', ['admin','staff'], true)) {
    http_response_code(403);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

$action  = trim($_GET['action']  ?? 'status');
$roomId  = trim($_GET['room_id'] ?? '');
$camNum  = (int)($_GET['cam']    ?? 1);
$quality = (($_GET['quality'] ?? '') === 'high') ? 'stream1' : 'stream2';

// ── Camera configuration ───────────────────────────────────────────────────────
// In production: load from DB or config file per room
// Tapo cameras must be on same local network as the PHP server (or accessible via VPN)
$cameraConfig = [
    'MLH306' => [
        1 => ['ip' => getenv('TAPO_MLH306_CAM1_IP') ?: '192.168.1.101', 'user' => 'admin', 'pass' => getenv('TAPO_MLH306_CAM1_PASS') ?: ''],
        2 => ['ip' => getenv('TAPO_MLH306_CAM2_IP') ?: '192.168.1.102', 'user' => 'admin', 'pass' => getenv('TAPO_MLH306_CAM2_PASS') ?: ''],
    ],
    'MLH305' => [
        1 => ['ip' => getenv('TAPO_MLH305_CAM1_IP') ?: '192.168.1.103', 'user' => 'admin', 'pass' => getenv('TAPO_MLH305_CAM1_PASS') ?: ''],
        2 => ['ip' => getenv('TAPO_MLH305_CAM2_IP') ?: '192.168.1.104', 'user' => 'admin', 'pass' => getenv('TAPO_MLH305_CAM2_PASS') ?: ''],
    ],
    'DESK' => [
        1 => ['ip' => '192.168.18.11', 'user' => 'SpotItCamera', 'pass' => 'spotittapo232'],
    ],
];

if (!isset($cameraConfig[$roomId][$camNum])) {
    header('Content-Type: application/json');
    echo json_encode(['status' => 'offline', 'reason' => 'Camera not configured for this room']);
    exit();
}

$cam = $cameraConfig[$roomId][$camNum];
$rtsp = "rtsp://{$cam['user']}:{$cam['pass']}@{$cam['ip']}:554/{$quality}";

// ── Camera status check ───────────────────────────────────────────────────────
if ($action === 'status') {
    header('Content-Type: application/json');
    // Quick TCP check to see if camera is online
    $ctx = stream_context_create(['socket' => ['timeout' => 2]]);
    $sock = @stream_socket_client("tcp://{$cam['ip']}:554", $errno, $errstr, 2, STREAM_CLIENT_CONNECT, $ctx);
    if ($sock) {
        fclose($sock);
        echo json_encode([
            'status'   => 'online',
            'room_id'  => $roomId,
            'cam'      => $camNum,
            'ip'       => $cam['ip'],
            'protocol' => 'RTSP',
            'quality'  => $quality,
            'rtsp_url' => "rtsp://[redacted]@{$cam['ip']}:554/{$quality}",
            // HLS URL if nginx-rtmp is set up on the server
            'hls_url'  => getenv('SPOTIT_HLS_BASE_URL')
                          ? getenv('SPOTIT_HLS_BASE_URL') . "/{$roomId}/cam{$camNum}/index.m3u8"
                          : null,
        ]);
    } else {
        echo json_encode([
            'status'  => 'offline',
            'room_id' => $roomId,
            'cam'     => $camNum,
            'reason'  => "Cannot reach {$cam['ip']}:554",
        ]);
    }
    exit();
}

// ── Cross-Platform FFMPEG Detection ──────────────────────────────────────────
$ffmpegPath = '';
if (strncasecmp(PHP_OS, 'WIN', 3) === 0) {
    // Windows
    $winPath = trim(shell_exec('where ffmpeg 2>nul') ?: '');
    if ($winPath) {
        $lines = explode("\n", $winPath);
        $ffmpegPath = trim($lines[0]);
    }
} else {
    // Linux/Mac
    $ffmpegPath = trim(shell_exec('which ffmpeg 2>/dev/null') ?: '');
}

// Fallback: If not found but ffmpeg is globally executable
if (!$ffmpegPath) {
    $test = @shell_exec('ffmpeg -version 2>&1');
    if (stripos($test, 'ffmpeg version') !== false) {
        $ffmpegPath = 'ffmpeg';
    }
}

// ── MJPEG snapshot (one frame) — attempts RTSP pull via ffmpeg if available ───
if ($action === 'snapshot') {
    header('Content-Type: application/json');
    header('Cache-Control: no-cache, no-store');

    $data = null;
    
    // Attempt FFMPEG RTSP single-frame capture first (most robust for Tapo C200/C310)
    if ($ffmpegPath) {
        $tempFile = __DIR__ . '/../uploads/snapshots/temp_' . $roomId . '_' . $camNum . '.jpg';
        @mkdir(dirname($tempFile), 0777, true);
        @unlink($tempFile);

        $cmd = '"' . $ffmpegPath . '"'
            . " -rtsp_transport tcp"
            . " -y -i " . escapeshellarg($rtsp)
            . " -vframes 1 -f image2 " . escapeshellarg($tempFile);
        
        shell_exec($cmd);
        
        if (file_exists($tempFile)) {
            $data = @file_get_contents($tempFile);
            @unlink($tempFile);
        }
    }

    // Fallback: Tapo HTTP snapshot endpoint
    if (!$data || strlen($data) < 1000) {
        $snapUrl = "http://{$cam['ip']}/cgi-bin/api.cgi?cmd=Snap&channel=0&rs=1&user={$cam['user']}&password={$cam['pass']}";
        $ctx  = stream_context_create(['http' => ['timeout' => 2]]);
        $data = @file_get_contents($snapUrl, false, $ctx);
    }

    if ($data && strlen($data) > 1000) {
        echo json_encode([
            'success'   => true,
            'timestamp' => date('Y-m-d H:i:s'),
            'data'      => 'data:image/jpeg;base64,' . base64_encode($data),
            'room_id'   => $roomId,
            'cam'       => $camNum,
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'reason'  => 'Snapshot unavailable — camera offline or RTSP/HTTP endpoints unreachable',
            'rtsp'    => "rtsp://[user]:[pass]@{$cam['ip']}:554/{$quality}",
        ]);
    }
    exit();
}

// ── MJPEG proxy stream via ffmpeg ─────────────────────────────────────────────
if ($action === 'stream') {
    if (!$ffmpegPath) {
        header('Content-Type: application/json');
        echo json_encode([
            'error'  => 'ffmpeg not available on this server',
            'advice' => 'Use action=snapshot for JPEG polling, or configure HLS via nginx-rtmp',
            'rtsp'   => "rtsp://[user]:[pass]@{$cam['ip']}:554/{$quality}",
        ]);
        exit();
    }

    // Safety: disable output buffering for streaming
    if (ob_get_level()) ob_end_clean();
    set_time_limit(0);

    header('Content-Type: multipart/x-mixed-replace; boundary=frame');
    header('Cache-Control: no-cache, no-store');
    header('Pragma: no-cache');
    header('Connection: close');

    $scale = ($quality === 'stream1') ? '960:-2' : '640:-2';
    $qval  = ($quality === 'stream1') ? '3' : '5';
    $fps   = ($quality === 'stream1') ? '15' : '10';

    $cmd = '"' . $ffmpegPath . '"'
        . " -loglevel quiet"
        . " -rtsp_transport tcp"
        . " -i " . escapeshellarg($rtsp)
        . " -vf scale=" . $scale
        . " -q:v " . $qval . " -r " . $fps
        . " -f mjpeg pipe:1";

    $proc = popen($cmd, 'rb');
    if (!$proc) {
        echo '--frame' . "\r\n";
        echo 'Content-Type: text/plain' . "\r\n\r\n";
        echo 'Failed to start stream';
        exit();
    }

    $buffer = '';
    while (!feof($proc) && !connection_aborted()) {
        $buffer .= fread($proc, 8192);

        $start = strpos($buffer, "\xFF\xD8");
        $end   = strpos($buffer, "\xFF\xD9");

        if ($start !== false && $end !== false && $end > $start) {
            $frame = substr($buffer, $start, $end - $start + 2);
            $buffer = substr($buffer, $end + 2);
            echo "--frame\r\n";
            echo "Content-Type: image/jpeg\r\n";
            echo "Content-Length: " . strlen($frame) . "\r\n\r\n";
            echo $frame;
            flush();
        }

        if (strlen($buffer) > 500000) {
            $buffer = substr($buffer, -100000);
        }
    }
    pclose($proc);
    exit();
}

// Default: unknown action
header('Content-Type: application/json');
echo json_encode(['error' => "Unknown action: {$action}. Use: status|snapshot|stream"]);
