<?php
/**
 * Tests: WEB-07 live-feed state (detector_feed_state in auth/detector_state.php).
 *
 *   C:\xampp\php\php.exe tests/test_feed_state.php
 *
 * Pure-function checks — no database, no files written. The scenarios mirror
 * what the dashboard must report for: normal operation, camera disconnect,
 * reconnect in progress, detector shutdown, and no camera assigned.
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

echo "== Camera disconnected while the detector keeps running\n";
$s = $state(1, $hb('offline', 12));
check('camera offline -> CAMERA_OFFLINE', $s['state'], 'CAMERA_OFFLINE');
check('frame age reported', $s['frame_age'], 12);
check('message gives last-frame time', str_contains($s['message'], 'Last frame received at'), true);

echo "== Reconnect in progress\n";
check('camera reconnecting -> RECONNECTING', $state(1, $hb('reconnecting', 20))['state'], 'RECONNECTING');

echo "== Detector shut down / hung\n";
$s = $state(DETECTOR_OFFLINE_SEC + 1, $hb('live', DETECTOR_OFFLINE_SEC + 1));
check('heartbeat too old -> DETECTOR_OFFLINE even if last report said live', $s['state'], 'DETECTOR_OFFLINE');
check('detector-offline message keeps the last-frame time', str_contains($s['message'], 'Last frame received at'), true);
check('never reported -> DETECTOR_OFFLINE', $state(null, [])['state'], 'DETECTOR_OFFLINE');
check('exactly at the limit is still online', $state(DETECTOR_OFFLINE_SEC, $hb('live', 1))['state'], 'LIVE');

echo "== No camera assigned\n";
check('no_camera -> NO_CAMERA', $state(1, $hb('no_camera'))['state'], 'NO_CAMERA');

echo "== Older main.py heartbeats (no frame age) still work\n";
check('legacy "online" -> LIVE', $state(1, ['camera' => 'online'])['state'], 'LIVE');
check('legacy "offline" -> CAMERA_OFFLINE', $state(1, ['camera' => 'offline'])['state'], 'CAMERA_OFFLINE');

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
