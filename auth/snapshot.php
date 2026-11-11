<?php
/**
 * S.P.O.T.-IT — Protected snapshot / evidence image endpoint
 * auth/snapshot.php?f=<file name>
 *
 * Detection evidence (Snapshot A/B) and claim selfies live in uploads/snapshots/,
 * which is inside the web root. uploads/.htaccess now denies direct requests for
 * those files; this endpoint is the only way to fetch them, and it checks the
 * session first:
 *   admin / staff  -> any evidence file
 *   student        -> only the snapshot of an item currently offered for claiming
 *                     (recovered_items.snapshot_path), i.e. what the Lost & Found
 *                     thread already shows them
 *
 * `f` must be a bare file name (no directories); the resolved path must stay
 * inside SNAPSHOT_PATH. Live frames, clean frames and baselines are NOT served
 * here — they remain public because the live viewer and the ROI editor load them
 * directly and they contain no event evidence.
 *
 * MICROSERVICES: reads spotit_lf_db only for the student check.
 */
require_once __DIR__ . '/service_bootstrap.php';

$role = $_SESSION['user_role'] ?? '';
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    header('Content-Type: text/plain');
    echo 'Sign in to view evidence.';
    exit();
}
session_write_close();

$name = basename((string) ($_GET['f'] ?? ''));
if ($name === '' || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,120}\.(jpe?g|png|webp)$/i', $name) || str_contains($name, '..')) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo 'Invalid file name.';
    exit();
}

$dir  = realpath(SNAPSHOT_PATH);
$path = realpath(SNAPSHOT_PATH . $name);
if ($dir === false || $path === false || !is_file($path) || !str_starts_with($path, $dir . DIRECTORY_SEPARATOR)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Snapshot not found.';
    exit();
}

if (!in_array($role, ['admin', 'staff'], true)) {
    // Students: only items that are claimable right now.
    $chk = $lfPdo->prepare(
        "SELECT 1 FROM recovered_items
          WHERE snapshot_path = ? AND status IN ('recovered','pending_claim') LIMIT 1"
    );
    $chk->execute([$name]);
    if (!$chk->fetchColumn()) {
        http_response_code(403);
        header('Content-Type: text/plain');
        echo 'Not authorised to view this evidence.';
        exit();
    }
}

$info = @getimagesize($path);
if (!$info || empty($info['mime']) || !str_starts_with($info['mime'], 'image/')) {
    http_response_code(415);
    header('Content-Type: text/plain');
    echo 'Not an image.';
    exit();
}

$mtime = filemtime($path);
$etag  = '"' . md5($name . $mtime . filesize($path)) . '"';
header('Content-Type: ' . $info['mime']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $name . '"');
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
header('ETag: ' . $etag);
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit();
}
readfile($path);
