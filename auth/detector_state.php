<?php
/**
 * S.P.O.T.-IT — Detector control-plane state
 * auth/detector_state.php
 *
 * Shared helpers for the poll/push contract between the web app and main.py.
 *
 * WHY THIS EXISTS
 * The mini-PC running main.py sits behind the school's NAT, so this server can
 * never open a connection to it. Every interaction must therefore be started by
 * the detector: it polls get_detector_config.php for instructions and pushes
 * results back. This file holds the small amount of server-side state that
 * contract needs — a command queue, a heartbeat, and a record of whether anyone
 * is currently watching the live view.
 *
 * State lives in runtime/detector_{ROOM}.json, which runtime/.htaccess keeps out
 * of the web root's reach. It is deliberately flat files rather than a table:
 * this is transient coordination state, it must survive a DB outage, and it does
 * not belong to any one of the four service databases (CLAUDE.md §2).
 */

require_once __DIR__ . '/../config/env.php';

// ── Room id sanitising ────────────────────────────────────────────────────────

/**
 * Room ids are interpolated into filenames, so they are whitelisted rather than
 * escaped. Returns '' when the input could not be trusted.
 */
function detector_clean_room(?string $roomId): string
{
    $roomId = strtoupper(trim((string) $roomId));
    return preg_match('/^[A-Z0-9_-]{1,32}$/', $roomId) ? $roomId : '';
}

// ── Paths ─────────────────────────────────────────────────────────────────────

function detector_state_file(string $roomId): string
{
    return RUNTIME_PATH . "detector_{$roomId}.json";
}

/** The ROI definition file main.py reads. DESK is the sandbox's special case. */
function detector_roi_file(string $roomId): string
{
    $root = __DIR__ . '/../';
    return $roomId === 'DESK' ? $root . 'rois.json' : $root . "rois_{$roomId}.json";
}

/** The baseline main.py calibrates against. */
function detector_ref_image_file(string $roomId): string
{
    $root = __DIR__ . '/../photos/';
    return $roomId === 'DESK' ? $root . 'ref_image.jpg' : $root . "ref_image_{$roomId}.jpg";
}

/** Byte-identical copy of the baseline that the ROI editor draws on. */
function detector_baseline_copy_file(string $roomId): string
{
    return SNAPSHOT_PATH . "{$roomId}_baseline.jpg";
}

function detector_mode_file(): string
{
    return __DIR__ . '/../detection_mode.json';
}

// ── Atomic write ──────────────────────────────────────────────────────────────

/**
 * Write via temp file + rename so a reader never observes a half-written file.
 * The live frame is read by browsers several times a second while being
 * overwritten, which is exactly the race this avoids (main.py does the same
 * thing locally in save_frames_atomic).
 */
function detector_atomic_write(string $path, string $bytes): bool
{
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        return false;
    }
    $tmp = $path . '.tmp' . getmypid();
    if (@file_put_contents($tmp, $bytes) === false) {
        return false;
    }
    // Windows rename() will not clobber an existing file; remove it first.
    if (DIRECTORY_SEPARATOR === '\\' && file_exists($path)) {
        @unlink($path);
    }
    if (@rename($tmp, $path)) {
        return true;
    }
    // Fallback: If rename failed due to Windows process file locks, copy over
    if (@copy($tmp, $path)) {
        @unlink($tmp);
        return true;
    }
    @unlink($tmp);
    return false;
}

// ── State read / write ────────────────────────────────────────────────────────

function detector_state_read(string $roomId): array
{
    $file = detector_state_file($roomId);
    if (!is_readable($file)) {
        return [];
    }
    $decoded = json_decode((string) @file_get_contents($file), true);
    return is_array($decoded) ? $decoded : [];
}

/**
 * Read-modify-write under an exclusive lock. Concurrent dashboard clicks and the
 * detector's own poll both mutate this file, so the whole cycle has to be held.
 */
function detector_state_mutate(string $roomId, callable $mutator): array
{
    if (!is_dir(RUNTIME_PATH)) {
        @mkdir(RUNTIME_PATH, 0775, true);
    }
    $file = detector_state_file($roomId);
    $lock = @fopen($file . '.lock', 'c');
    if ($lock) {
        flock($lock, LOCK_EX);
    }

    $state = detector_state_read($roomId);
    $state = $mutator($state);
    detector_atomic_write($file, json_encode($state, JSON_PRETTY_PRINT));

    if ($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $state;
}

// ── Config version ────────────────────────────────────────────────────────────

/**
 * Derived from the mtime+size of everything the detector depends on, rather than
 * an incrementing counter. That means any writer — save_rois.php, a manual file
 * edit, a restored backup — changes the version automatically, with no risk of
 * someone forgetting to bump it.
 */
function detector_config_version(string $roomId): string
{
    $parts = [];
    foreach ([
        detector_roi_file($roomId),
        detector_ref_image_file($roomId),
        detector_mode_file(),
    ] as $f) {
        $parts[] = is_readable($f) ? filemtime($f) . ':' . filesize($f) : 'missing';
    }
    return substr(md5(implode('|', $parts)), 0, 16);
}

// ── Command queue ─────────────────────────────────────────────────────────────

const DETECTOR_COMMANDS = ['capture_baseline', 'reload_rois', 'reset_paused', 'clear_alerts'];

/**
 * Queue an instruction for the detector's next poll. Deduplicated: clicking
 * Capture Baseline five times must produce one capture, not five.
 */
function detector_queue_command(string $roomId, string $command): bool
{
    if (!in_array($command, DETECTOR_COMMANDS, true)) {
        return false;
    }
    detector_state_mutate($roomId, function (array $s) use ($command) {
        $pending = $s['commands'] ?? [];
        if (!in_array($command, $pending, true)) {
            $pending[] = $command;
        }
        $s['commands'] = array_values($pending);
        $s['commands_queued_at'] = time();
        return $s;
    });
    return true;
}

/** Hand the queue to the detector and clear it in the same locked cycle. */
function detector_take_commands(string $roomId): array
{
    $taken = [];
    detector_state_mutate($roomId, function (array $s) use (&$taken) {
        $taken = $s['commands'] ?? [];
        $s['commands'] = [];
        return $s;
    });
    return $taken;
}

// ── Live-view presence ────────────────────────────────────────────────────────

/**
 * Called whenever a dashboard asks for a live frame. The detector reads this to
 * decide whether to push ~1 fps or drop to a trickle — the difference between
 * roughly 5 GB and 0.3 GB of upload per room per day.
 */
function detector_mark_viewer(string $roomId): void
{
    detector_state_mutate($roomId, function (array $s) {
        $s['last_viewer_request'] = time();
        return $s;
    });
}

function detector_viewers_active(string $roomId): bool
{
    $s = detector_state_read($roomId);
    return (time() - (int) ($s['last_viewer_request'] ?? 0)) <= LIVE_VIEWER_TTL_SEC;
}

// ── Heartbeat ─────────────────────────────────────────────────────────────────

function detector_record_heartbeat(string $roomId, array $data): void
{
    detector_state_mutate($roomId, function (array $s) use ($data) {
        $s['last_heartbeat'] = time();
        $s['heartbeat'] = $data;
        return $s;
    });
}

/**
 * Health for the dashboard badge. Without this a crashed detector is
 * indistinguishable from a quiet room — which stops being acceptable the moment
 * nobody is watching a terminal.
 */
function detector_health(string $roomId): array
{
    $s = detector_state_read($roomId);
    $last = (int) ($s['last_heartbeat'] ?? 0);
    $age = $last > 0 ? time() - $last : null;
    $hb = $s['heartbeat'] ?? [];
    $feed = detector_feed_state($last ?: null, $hb, time());
    return [
        'online'       => $age !== null && $age <= DETECTOR_OFFLINE_SEC,
        'last_seen'    => $last > 0 ? date('c', $last) : null,
        'seconds_ago'  => $age,
        'fps'          => $hb['fps'] ?? null,
        'camera'       => $hb['camera'] ?? null,
        'deploy'       => $hb['deploy'] ?? null,
        // WEB-07: what the viewer should actually say about the picture.
        'feed_state'        => $feed['state'],
        'feed_message'      => $feed['message'],
        'last_frame_at'     => $feed['last_frame_at'] ? date('c', $feed['last_frame_at']) : null,
        'last_frame_time'   => $feed['last_frame_at'] ? date('H:i:s', $feed['last_frame_at']) : null,
        'frame_age_seconds' => $feed['frame_age'],
    ];
}

/**
 * WEB-07 — decide what the live view may honestly claim. Pure (no I/O).
 *
 * The image file on disk is NOT evidence of a live camera: main.py rewrites it
 * every loop, and when the camera stops it keeps rewriting the last cached
 * frame, so a frozen picture always looks freshly saved. The detector therefore
 * reports the age of its last genuinely NEW camera frame in each heartbeat; the
 * server turns that into an absolute time on its own clock at receipt
 * (`last_frame_at`), which also works when detector and server clocks differ.
 *
 * States, in priority order:
 *   DETECTOR_OFFLINE  no heartbeat within DETECTOR_OFFLINE_SEC (main.py down/hung)
 *   NO_CAMERA         detector running, but no camera assigned to this room
 *   RECONNECTING      detector is re-opening the camera stream right now
 *   CAMERA_OFFLINE    detector running, camera not delivering new frames
 *   STALE             detector running, camera reports live, but its last
 *                     heartbeat is older than DETECTOR_STALE_SEC — still
 *                     within the offline grace period, so not yet a failure,
 *                     but old enough that calling it "live" would overclaim
 *   LIVE              new frames are arriving and the heartbeat is fresh
 *
 * Heartbeats from an older main.py (camera = "online"/"offline", no frame age)
 * map onto LIVE / CAMERA_OFFLINE so a mixed deployment keeps working.
 *
 * @return array{state:string, message:string, last_frame_at:?int, frame_age:?int}
 */
function detector_feed_state(?int $lastHeartbeat, array $hb, int $now): array
{
    $lastFrameAt = isset($hb['last_frame_at']) ? (int) $hb['last_frame_at'] : null;
    $frameAge = $lastFrameAt ? max(0, $now - $lastFrameAt) : null;
    $out = fn(string $state, string $msg) => [
        'state' => $state, 'message' => $msg, 'last_frame_at' => $lastFrameAt, 'frame_age' => $frameAge,
    ];
    $since = $lastFrameAt ? ' Last frame received at ' . date('H:i:s', $lastFrameAt) . " ({$frameAge}s ago)." : '';

    $offlineSec = defined('DETECTOR_OFFLINE_SEC') ? DETECTOR_OFFLINE_SEC : 30;
    $staleSec   = defined('DETECTOR_STALE_SEC') ? DETECTOR_STALE_SEC : 15;
    $heartbeatAge = $lastHeartbeat !== null ? $now - $lastHeartbeat : null;
    if (!$lastHeartbeat || $heartbeatAge > $offlineSec) {
        return $out('DETECTOR_OFFLINE', $lastHeartbeat
            ? 'The detector (main.py) stopped reporting at ' . date('H:i:s', $lastHeartbeat) . '. The picture shown is not live.' . $since
            : 'The detector (main.py) has never reported for this room.');
    }
    $camera = (string) ($hb['camera'] ?? 'unknown');
    if ($camera === 'no_camera') {
        return $out('NO_CAMERA', 'The detector is running, but no camera is assigned to this room.');
    }
    if ($camera === 'reconnecting') {
        return $out('RECONNECTING', 'The camera stopped sending frames; the detector is reconnecting.' . $since);
    }
    if ($camera === 'offline') {
        return $out('CAMERA_OFFLINE', 'The detector is running, but the camera is not sending frames.' . $since);
    }
    // Camera reports live/online/unknown. Still check heartbeat freshness before
    // calling it LIVE — a heartbeat that is merely "not yet offline" is not the
    // same as "confirmed moments ago". See DETECTOR_STALE_SEC above.
    if ($heartbeatAge > $staleSec) {
        return $out('STALE', 'The detector last checked in at ' . date('H:i:s', $lastHeartbeat)
            . " ({$heartbeatAge}s ago). The picture may not reflect the current state." . $since);
    }
    if ($camera === 'live' || $camera === 'online') {
        return $out('LIVE', 'Live.');
    }
    return $out('LIVE', 'Live (detector did not report camera state).');
}

// ── Administrative monitoring-pause axis ──────────────────────────────────────

/**
 * Phase 1B — one room's status along two axes kept deliberately distinct from
 * each other, and from whatever detection-based status (confirmed_missing /
 * potential / normal) the caller tracks separately:
 *
 *   - camera/detector connectivity, from the heartbeat file (detector_health())
 *   - the administrative "monitoring paused" flag (rooms.monitoring_status),
 *     set e.g. by the mass-deviation auto-flood-gate (CLAUDE.md §3c) — a
 *     deliberate pause, not a connection failure
 *
 * Collapsing both into one generic "offline" (the bug this fixes) hides a
 * calm, expected recalibration pause behind the same label as a crashed
 * detector, and vice versa.
 *
 * Returns null when both axes are healthy (detector online, feed LIVE or
 * STALE, monitoring_status active) — the caller's own detection-based status
 * is authoritative in that case. Otherwise returns the one state that should
 * take priority over it, with a human label and a generic severity
 * ('alert'|'warn'|'muted') for the caller to map onto its own CSS. Takes
 * $monitoringStatus as a plain value rather than querying the database —
 * every caller already has the room row in hand.
 *
 * @return array{status:string, label:string, severity:string, health:array}|null
 */
function ms_room_connectivity_status(string $roomId, string $monitoringStatus): ?array
{
    $health = detector_health($roomId);
    $feed   = $health['online'] ? $health['feed_state'] : 'DETECTOR_OFFLINE';

    $connectivity = [
        'DETECTOR_OFFLINE' => ['status' => 'offline',      'label' => 'Detector Offline',   'severity' => 'alert'],
        'CAMERA_OFFLINE'   => ['status' => 'offline',      'label' => 'Camera Offline',     'severity' => 'alert'],
        'RECONNECTING'     => ['status' => 'reconnecting', 'label' => 'Reconnecting',       'severity' => 'warn'],
        'NO_CAMERA'        => ['status' => 'no_camera',    'label' => 'No Camera Assigned', 'severity' => 'muted'],
    ];
    if (isset($connectivity[$feed])) {
        return $connectivity[$feed] + ['health' => $health];
    }

    if ($monitoringStatus !== 'active') {
        $label = $monitoringStatus === 'calibrating' ? 'Calibrating' : 'Monitoring Paused';
        return ['status' => 'paused', 'label' => $label, 'severity' => 'muted', 'health' => $health];
    }

    return null;
}

// ── Shared API-key auth for detector endpoints ────────────────────────────────

/**
 * Every detector endpoint authenticates the same way: the shared key, via POST
 * field or X-Spotit-Key header. Uses the SPOTIT_DETECTION_KEY constant so that
 * env.local.php overrides actually apply.
 */
function detector_require_key(): void
{
    $provided = trim($_POST['api_key'] ?? $_GET['api_key'] ?? $_SERVER['HTTP_X_SPOTIT_KEY'] ?? '');
    if (!hash_equals(SPOTIT_DETECTION_KEY, $provided)) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
        exit();
    }
}
