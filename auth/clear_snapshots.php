<?php
// auth/clear_snapshots.php
// Cleans up movement snapshot pictures (*_registered_*, *_snapshot_A*, *_snapshot_B*, object*)
// while preserving critical system stream files (live_*, clean_*, *_baseline.jpg, ref_image*).

header('Content-Type: application/json');

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
