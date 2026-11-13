<?php
/**
 * Tests: WEB-07 live-feed state and Phase 1B connectivity/pause status
 * (detector_feed_state() and ms_room_connectivity_status() in
 * auth/detector_state.php).
 *
 *   C:\xampp\php\php.exe tests/test_feed_state.php
 *
 * Pure-function checks — no database, no files written. The scenarios mirror
 * what the dashboard must report for: normal operation, camera disconnect,
 * reconnect in progress, detector shutdown, stale heartbeats, no camera
 * assigned, and the administrative monitoring-pause flag.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../auth/detector_state.php';

$pass = $fail = 0;
function check(string $name, $actual, $expected): void
{
    global $pass, $fail;
    if ($actual === $expected) { $pass++; echo "  PASS  {$name}\n"; }
    else { $fail++; echo "  FAIL  {$name}\n        expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n"; }
}

$now = 1_800_000_000;
$hb = fn(string $camera, ?int $frameAgo = null) => ['camera' => $camera, 'last_frame_at' => $frameAgo === null ? null : $now - $frameAgo];
$state = fn(?int $hbAgo, array $h) => detector_feed_state($hbAgo === null ? null : $now - $hbAgo, $h, $now);

echo "== Normal operation\n";
check('fresh heartbeat, camera live -> LIVE', $state(2, $hb('live', 0))['state'], 'LIVE');
check('live between heartbeats (heartbeat 9 s old) -> still LIVE', $state(9, $hb('live', 9))['state'], 'LIVE');
check('heartbeat right at the stale boundary -> still LIVE', $state(DETECTOR_STALE_SEC, $hb('live', 1))['state'], 'LIVE');

echo "== Camera disconnected while the detector keeps running\n";
$s = $state(1, $hb('offline', 12));
check('camera offline -> CAMERA_OFFLINE', $s['state'], 'CAMERA_OFFLINE');
check('frame age reported', $s['frame_age'], 12);
check('message gives last-frame time', str_contains($s['message'], 'Last frame received at'), true);

echo "== Reconnect in progress\n";
check('camera reconnecting -> RECONNECTING', $state(1, $hb('reconnecting', 20))['state'], 'RECONNECTING');

echo "== Heartbeat aging but not yet offline (Phase 1B)\n";
$s = $state(DETECTOR_STALE_SEC + 1, $hb('live', DETECTOR_STALE_SEC + 1));
check('heartbeat just past the stale boundary, camera live -> STALE (not LIVE)', $s['state'], 'STALE');
check('STALE message names the last check-in time', str_contains($s['message'], 'last checked in at'), true);
check('exactly at the offline limit, camera live -> STALE, never LIVE', $state(DETECTOR_OFFLINE_SEC, $hb('live', 1))['state'], 'STALE');
check('CAMERA_OFFLINE still wins over staleness (connectivity > freshness)', $state(DETECTOR_STALE_SEC + 1, $hb('offline', 1))['state'], 'CAMERA_OFFLINE');
check('RECONNECTING still wins over staleness', $state(DETECTOR_STALE_SEC + 1, $hb('reconnecting', 1))['state'], 'RECONNECTING');

echo "== Detector shut down / hung\n";
$s = $state(DETECTOR_OFFLINE_SEC + 1, $hb('live', DETECTOR_OFFLINE_SEC + 1));
check('heartbeat too old -> DETECTOR_OFFLINE even if last report said live', $s['state'], 'DETECTOR_OFFLINE');
check('detector-offline message keeps the last-frame time', str_contains($s['message'], 'Last frame received at'), true);
check('never reported -> DETECTOR_OFFLINE', $state(null, [])['state'], 'DETECTOR_OFFLINE');

echo "== No camera assigned\n";
check('no_camera -> NO_CAMERA', $state(1, $hb('no_camera'))['state'], 'NO_CAMERA');

echo "== Older main.py heartbeats (no frame age) still work\n";
check('legacy "online" -> LIVE', $state(1, ['camera' => 'online'])['state'], 'LIVE');
check('legacy "offline" -> CAMERA_OFFLINE', $state(1, ['camera' => 'offline'])['state'], 'CAMERA_OFFLINE');
check('legacy "online" but stale heartbeat -> STALE', $state(DETECTOR_STALE_SEC + 1, ['camera' => 'online'])['state'], 'STALE');

echo "\n== ms_room_connectivity_status() — connectivity + administrative pause, kept distinct (Phase 1B)\n";

// No heartbeat file at all for this made-up room id -> detector_health()
// reports "never reported", i.e. online=false. Exercises the DETECTOR_OFFLINE
// branch, and proves it is NOT silently read as a normal/quiet room.
$offlineRoom = '__TEST_ROOM_OFFLINE__';
$conn = ms_room_connectivity_status($offlineRoom, 'active');
check('no heartbeat file at all -> DETECTOR_OFFLINE, not treated as normal', $conn['status'] ?? null, 'offline');
check('DETECTOR_OFFLINE severity is alert (a real problem)', $conn['severity'] ?? null, 'alert');

// A genuinely healthy, fresh heartbeat, written through the same function
// main.py's own heartbeat endpoint calls — exercises the pause-vs-connectivity
// priority ordering, then cleans up the test fixture it created.
$healthyRoom = '__TEST_ROOM_HEALTHY__';
detector_record_heartbeat($healthyRoom, ['camera' => 'live', 'last_frame_at' => time(), 'deploy' => 'local']);
try {
    check('connectivity healthy + monitoring active -> null (caller\'s own status applies)',
        ms_room_connectivity_status($healthyRoom, 'active'), null);

    $paused = ms_room_connectivity_status($healthyRoom, 'inactive');
    check('connectivity healthy + monitoring_status inactive -> paused, not offline', $paused['status'] ?? null, 'paused');
    check('paused severity is muted, not alarming like a real failure', $paused['severity'] ?? null, 'muted');
    check('paused label is human-readable', $paused['label'] ?? null, 'Monitoring Paused');

    $calibrating = ms_room_connectivity_status($healthyRoom, 'calibrating');
    check('monitoring_status calibrating gets its own label', $calibrating['label'] ?? null, 'Calibrating');
} finally {
    // Leave no trace: this is a fixture this test created, not real detector state.
    @unlink(detector_state_file($healthyRoom));
    @unlink(detector_state_file($healthyRoom) . '.lock');
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
