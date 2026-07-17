<?php
/**
 * auth/save_tracking_mode.php
 * Saves the sandbox tracking_mode ('registered' | 'unregistered') to detection_mode.json.
 * main.py picks up the file change automatically via mtime polling.
 */
require_once __DIR__ . '/service_bootstrap.php';
header('Content-Type: application/json');

if ($_SESSION['user_role'] !== 'admin' && $_SESSION['user_role'] !== 'staff') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$allowed = ['registered', 'unregistered'];
$tracking_mode = trim($_POST['tracking_mode'] ?? '');

if (!in_array($tracking_mode, $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Invalid tracking mode.']);
    exit;
}

$mode_file = __DIR__ . '/../detection_mode.json';
$current = [];
if (file_exists($mode_file)) {
    $current = json_decode(file_get_contents($mode_file), true) ?? [];
}

$current['tracking_mode'] = $tracking_mode;
$current['updated_at']    = date('Y-m-d H:i:s');

if (file_put_contents($mode_file, json_encode($current, JSON_PRETTY_PRINT)) !== false) {
    echo json_encode(['success' => true, 'tracking_mode' => $tracking_mode]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to write detection_mode.json']);
}
