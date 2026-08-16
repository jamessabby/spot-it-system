<?php
/**
 * S.P.O.T.-IT — Detector configuration poll
 * auth/get_detector_config.php
 *
 * GET/POST, API-key authenticated. Called by main.py on a short interval.
 *
 * This is the detector's single source of truth once it is running on a
 * different machine from the web app. It replaces reading detection_mode.json,
 * rois_{ROOM}.json and photos/ref_image_{ROOM}.jpg off a shared local disk —
 * files that simply do not exist on the mini-PC once the site is hosted.
 *
 * It is also the ONLY way the dashboard can give the detector an instruction,
 * because the school's NAT means this server can never call the detector. Hence
 * `commands`: the UI leaves a note here, and the detector picks it up on its
 * next poll. See md_files/DEPLOYMENT_GUIDE.md.
 *
 * Response contract (main.py depends on these names):
 *   mode, tracking_mode, timer_mode, room_id  — same values as detection_mode.json
 *   rois[]                                    — the ROI list, inlined
 *   config_version                            — changes when any input changes
 *   baseline_url / baseline_version           — where to fetch a new baseline
 *   viewers_active                            — is anyone watching the live view
 *   commands[]                                — instructions, delivered once
 *
 * MICROSERVICES: reads no database. Pure control-plane state.
 */

require_once __DIR__ . '/detector_state.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

detector_require_key();

// ── Which room is this detector responsible for? ──────────────────────────────
$roomId = detector_clean_room($_REQUEST['room_id'] ?? '');

// Fall back to whatever the dashboard last selected, so a detector started with
// no ROOM_ID still lands somewhere sensible instead of failing outright.
$mode = [];
if (is_readable(detector_mode_file())) {
    $decoded = json_decode((string) file_get_contents(detector_mode_file()), true);
    if (is_array($decoded)) {
        $mode = $decoded;
    }
}
if ($roomId === '') {
    $roomId = detector_clean_room($mode['room_id'] ?? 'DESK') ?: 'DESK';
}

// ── ROIs, inlined so the detector needs no second request ─────────────────────
$rois = [];
$roiFile = detector_roi_file($roomId);
if (is_readable($roiFile)) {
    $decoded = json_decode((string) file_get_contents($roiFile), true);
    if (is_array($decoded)) {
        $rois = $decoded;
    }
}

// ── Baseline ──────────────────────────────────────────────────────────────────
// Served from uploads/snapshots/ because that path is web-reachable; photos/ is
// blocked by .htaccess. The two files are byte-identical by construction.
$baselineFile = detector_baseline_copy_file($roomId);
$baselineVersion = is_readable($baselineFile) ? (string) filemtime($baselineFile) : null;
$baselineUrl = $baselineVersion !== null
    ? SITE_URL . '/uploads/snapshots/' . rawurlencode("{$roomId}_baseline.jpg")
    : null;

// ── Commands are delivered exactly once ───────────────────────────────────────
// Taken and cleared in a single locked cycle. If the detector dies mid-poll the
// command is lost rather than repeated forever, which is the safer failure for
// something like capture_baseline.
$commands = detector_take_commands($roomId);

echo json_encode([
    'success'          => true,
    'room_id'          => $roomId,
    'mode'             => $mode['mode'] ?? 'testing',
    'tracking_mode'    => $mode['tracking_mode'] ?? 'registered',
    'timer_mode'       => $mode['timer_mode'] ?? 'testing_speed',
    'pipeline'         => $mode['pipeline'] ?? 'gatekeeper',
    'rois'             => $rois,
    'roi_count'        => count($rois),
    'config_version'   => detector_config_version($roomId),
    'baseline_url'     => $baselineUrl,
    'baseline_version' => $baselineVersion,
    'viewers_active'   => detector_viewers_active($roomId),
    'live_idle_sec'    => LIVE_VIEWER_TTL_SEC,
    'commands'         => $commands,
    'server_time'      => time(),
], JSON_UNESCAPED_SLASHES);
