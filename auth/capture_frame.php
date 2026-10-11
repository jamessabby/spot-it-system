<?php
/**
 * S.P.O.T.-IT — Capture Baseline Frame
 * auth/capture_frame.php
 *
 * GET/POST endpoint. Captures a single baseline frame for the specified room,
 * saving to photos/ref_image_{room_id}.jpg and uploads/snapshots/{room}_baseline.jpg
 * and stamping rooms.last_calibrated in spotit_monitor_db.
 *
 * TWO PATHS, BECAUSE THE CAMERA IS NOT ALWAYS REACHABLE FROM HERE
 *
 *   SPOTIT_DEPLOY=local   — web app and camera on the same network (XAMPP dev).
 *                           Clone the detector's latest clean frame if it has
 *                           one, otherwise pull directly via ffmpeg. Unchanged
 *                           behaviour.
 *
 *   SPOTIT_DEPLOY=remote  — web app hosted, camera behind the lab's NAT. The
 *                           rtsp://192.168.x.x address is meaningless from a
 *                           data centre, and ffmpeg is rarely installed on
 *                           shared hosting anyway. So we invert the flow: queue
 *                           a `capture_baseline` command, and the detector — the
 *                           one machine that CAN see the camera — grabs a fresh
 *                           frame and posts it to auth/ingest_baseline.php.
 *
 * Same button, same result. In remote mode it completes one poll interval later
 * rather than instantly, which is why the response distinguishes the two cases.
 */
require_once __DIR__ . '/service_bootstrap.php';
require_once __DIR__ . '/detector_state.php';
header('Content-Type: application/json; charset=utf-8');

ms_require_auth('../pages/login.php');

if (!in_array($_SESSION['user_role'] ?? '', ['staff', 'admin'], true)) {
    ms_json(['success' => false, 'message' => 'Unauthorized.'], 403);
}

$roomId = detector_clean_room($_REQUEST['room_id'] ?? 'DESK');
if ($roomId === '') {
    ms_json(['success' => false, 'message' => 'Valid room_id required.'], 400);
}

$targetRef    = detector_ref_image_file($roomId);
$targetUpload = detector_baseline_copy_file($roomId);

@mkdir(dirname($targetRef), 0777, true);
@mkdir(dirname($targetUpload), 0777, true);

// ── Remote deployment: ask the detector to do it ──────────────────────────────
if (IS_REMOTE_DEPLOY) {
    if (!detector_queue_command($roomId, 'capture_baseline')) {
        ms_json(['success' => false, 'message' => 'Could not queue capture command.'], 500);
    }

    $health = detector_health($roomId);
    if (!$health['online']) {
        // Queued regardless — it will run when the detector comes back — but say
        // so plainly rather than letting the admin wait for a frame that is not
        // coming.
        ms_json([
            'success' => true,
            'queued'  => true,
            'warning' => true,
            'message' => 'Capture queued, but the detector for this room is OFFLINE. '
                       . 'The baseline will be captured when it reconnects.',
        ]);
    }

    ms_json([
        'success' => true,
        'queued'  => true,
        'message' => 'Capture requested. The lab detector will upload a fresh baseline in a few seconds.',
    ]);
}

// ── Local deployment: capture here ────────────────────────────────────────────

// Fetch room camera credentials from spotit_monitor_db
$rtsp = DESK_CAMERA_RTSP;
try {
    $camStmt = $monitorPdo->prepare("SELECT camera_ip, camera_user, camera_pass FROM rooms WHERE room_id = ?");
    $camStmt->execute([$roomId]);
    $camDb = $camStmt->fetch();
    if ($camDb && !empty($camDb['camera_ip'])) {
        $camIp   = $camDb['camera_ip'];
        $camUser = $camDb['camera_user'] ?: 'SpotItCamera';
        $camPass = $camDb['camera_pass'] ?: DESK_CAMERA_PASS;
        $rtsp    = "rtsp://{$camUser}:{$camPass}@{$camIp}:554/stream1";
    }
} catch (Exception $e) {
    // Fallback to default RTSP URL
}

// Smart active stream clone: use the detector's latest frame if it is running.
// Prefer the clean (un-annotated) frame — a baseline with ROI boxes drawn on it
// would poison every subsequent comparison.
$cleanSnapshot = SNAPSHOT_PATH . "clean_{$roomId}.jpg";
$liveSnapshot  = SNAPSHOT_PATH . "live_{$roomId}.jpg";
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

    if (!$ffmpegPath && strncasecmp(PHP_OS, 'WIN', 3) === 0) {
        $winCandidates = [
            (getenv('LOCALAPPDATA') ?: '') . '\Microsoft\WinGet\Links\ffmpeg.exe',
            'C:\Users\James\AppData\Local\Microsoft\WinGet\Links\ffmpeg.exe',
            'C:\ffmpeg\bin\ffmpeg.exe',
            'C:\Program Files\ffmpeg\bin\ffmpeg.exe'
        ];
        foreach ($winCandidates as $cand) {
            if (!empty($cand) && file_exists($cand)) {
                $ffmpegPath = $cand;
                break;
            }
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

    try {
        $relPath = "uploads/snapshots/{$roomId}_baseline.jpg";
        $monitorPdo->prepare("UPDATE rooms SET baseline_image = ?, last_calibrated = NOW() WHERE room_id = ?")
                    ->execute([$relPath, $roomId]);
    } catch (PDOException $e) {
        // Ignore DB update error
    }

    // Trigger immediate dynamic reload in main.py
    $dmFile = detector_mode_file();
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
