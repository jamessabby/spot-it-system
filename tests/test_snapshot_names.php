<?php
/**
 * Tests: unique per-event Snapshot A filenames, and that uploads/.htaccess still
 * denies direct access to every evidence filename shape (new and legacy).
 *
 *   php tests/test_snapshot_names.php
 *
 * Pure-function tests — no database, no network, no files written outside the
 * test's own temp checks. See CLAUDE.md "Evidence caveat" (2026-11-12): the
 * detector sends the same {label}_registered_N.jpg / {label}_snapshot_A.jpg name
 * for every event on a given ROI, so auth/ingest_detection.php used to "preserve"
 * that name verbatim and a later event silently overwrote an earlier event's
 * evidence file on disk. auth/snapshot_naming.php is the fix: every upload now
 * gets a filename unique to that event.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../auth/snapshot_naming.php';

$pass = $fail = 0;
function check(string $name, $actual, $expected): void
{
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
        echo "  PASS  {$name}\n";
    } else {
        $fail++;
        echo "  FAIL  {$name}\n        expected " . var_export($expected, true) . ', got ' . var_export($actual, true) . "\n";
    }
}

echo "== Filename uniqueness (same room+zone, repeated events) ==\n";
$names = [];
for ($i = 0; $i < 50; $i++) {
    $names[] = spotit_unique_snapshot_filename('MLH305', 'mouse1', 'mouse1_snapshot_A.jpg');
}
check('50 calls for the same room+zone all produce distinct names', count(array_unique($names)), 50);

echo "== Naming shape ==\n";
$n = spotit_unique_snapshot_filename('MLH305', 'mouse1', 'mouse1_snapshot_A.jpg');
check('contains room', str_contains($n, 'MLH305'), true);
check('contains zone', str_contains($n, 'mouse1'), true);
check('carries the "a" evidence marker', (bool) preg_match('/_a_[a-f0-9]+\.jpg$/', $n), true);

echo "== Extension handling ==\n";
check('png kept', pathinfo(spotit_unique_snapshot_filename('MLH305', 'mouse1', 'x.png'), PATHINFO_EXTENSION), 'png');
check('unknown/forged extension falls back to jpg', pathinfo(spotit_unique_snapshot_filename('MLH305', 'mouse1', 'x.php'), PATHINFO_EXTENSION), 'jpg');
check('no extension falls back to jpg', pathinfo(spotit_unique_snapshot_filename('MLH305', 'mouse1', 'x'), PATHINFO_EXTENSION), 'jpg');

echo "== Unsafe room/zone input can't inject path separators or traversal ==\n";
$hostile = spotit_unique_snapshot_filename('../../etc', 'mouse1/../../x', 'a.jpg');
check('no path separators survive', str_contains($hostile, '/') || str_contains($hostile, '\\'), false);
check('no ".." survives', str_contains($hostile, '..'), false);
check('still ends in a safe extension', (bool) preg_match('/\.(jpg|jpeg|png)$/', $hostile), true);

echo "== uploads/.htaccess still denies every evidence filename shape ==\n";
$htaccess = file_get_contents(__DIR__ . '/../uploads/.htaccess');
if (!preg_match('/<FilesMatch "(\(_registered_.*?\))">/', $htaccess, $m)) {
    echo "  FAIL  could not locate the evidence-deny <FilesMatch> block in uploads/.htaccess\n";
    $fail++;
} else {
    $denyRegex = '/' . $m[1] . '/';

    $shouldDeny = [
        'new Snapshot A (current naming)'    => spotit_unique_snapshot_filename('MLH305', 'mouse1', 'a.jpg'),
        'legacy registered progression file' => 'mouse1_registered_3.jpg',
        'legacy registered final file'       => 'mouse1_registered_final.jpg',
        'legacy snapshot_A (tier2/unreg.)'    => 'object1_snapshot_A.jpg',
        'legacy snapshot_B (tier2/unreg.)'    => 'object1_snapshot_B.jpg',
        'real Snapshot B name (detection id)' => date('Ymd_His') . '_55_b_' . uniqid() . '.jpg',
        'claim selfie'                        => 'claim_12_abcdef123456.jpg',
    ];
    foreach ($shouldDeny as $label => $fname) {
        check("DENY: {$label} ({$fname})", (bool) preg_match($denyRegex, $fname), true);
    }

    $shouldAllow = [
        'live frame'      => 'live_MLH305.jpg',
        'clean frame'     => 'clean_MLH305.jpg',
        'baseline frame'  => 'mouse1_baseline.jpg',
        'room baseline'   => 'ref_image_MLH305.jpg',
    ];
    foreach ($shouldAllow as $label => $fname) {
        check("ALLOW (not matched by evidence-deny rule): {$label} ({$fname})", (bool) preg_match($denyRegex, $fname), false);
    }
}

echo "\n{$pass} passed, {$fail} failed\n";
exit($fail ? 1 : 0);
