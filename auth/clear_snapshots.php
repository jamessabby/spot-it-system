<?php
// auth/clear_snapshots.php
// Cleans up movement snapshot pictures (*_registered_*, *_snapshot_A*, *_snapshot_B*, object*)
// while preserving critical system stream files (live_*, clean_*, *_baseline.jpg, ref_image*).
//
// SECURITY FIX (website hardening pass): this file had NO auth check at all —
// not even a session include — so any anonymous request, from any origin,
// could wipe every non-baseline snapshot in uploads/snapshots/ (detection
// evidence, claim selfies). It is only ever called via POST from the admin
// Camera Setup / Room Monitor pages, so it now requires the same admin
// session + CSRF token every other destructive admin action does.
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

if (!in_array($_SESSION['user_role'] ?? '', ['staff', 'admin'], true)) {
    ms_json(['success' => false, 'message' => 'Unauthorized.'], 403);
}

spotit_require_csrf();

$snapshotsDir = __DIR__ . '/../uploads/snapshots';

if (!is_dir($snapshotsDir)) {
    echo json_encode([
        'success' => false,
        'message' => 'Snapshots directory does not exist.'
    ]);
    exit;
}

$files = scandir($snapshotsDir);
$deletedCount = 0;

$protectedPatterns = [
    '/^live_.*\.jpg$/i',
    '/^clean_.*\.jpg$/i',
    '/^.*_baseline\.jpg$/i',
    '/^ref_image.*\.jpg$/i',
    '/^\.gitignore$/i',
    '/^\.htaccess$/i'
];

foreach ($files as $file) {
    if ($file === '.' || $file === '..') {
        continue;
    }

    $filePath = $snapshotsDir . '/' . $file;

    // Skip subdirectories
    if (is_dir($filePath)) {
        continue;
    }

    // Check if file is protected
    $isProtected = false;
    foreach ($protectedPatterns as $pattern) {
        if (preg_match($pattern, $file)) {
            $isProtected = true;
            break;
        }
    }

    // If not protected, delete it!
    if (!$isProtected) {
        if (@unlink($filePath)) {
            $deletedCount++;
        }
    }
}

echo json_encode([
    'success' => true,
    'deleted_count' => $deletedCount,
    'message' => "Successfully deleted {$deletedCount} movement snapshot picture(s)."
]);
exit;
