<?php
/**
 * auth/save_detection_mode.php
 * Saves the detection mode ('testing' | 'production') to detection_mode.json.
 * main.py picks up the change automatically via mtime polling — no restart needed.
 */
require_once __DIR__ . '/service_bootstrap.php';
header('Content-Type: application/json');

if (($_SESSION['user_role'] ?? '') !== 'admin' && ($_SESSION['user_role'] ?? '') !== 'staff') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$allowed = ['testing', 'production'];
$mode    = trim($_POST['mode'] ?? '');

if (!in_array($mode, $allowed)) {
    echo json_encode(['success' => false, 'message' => 'Invalid mode value.']);
    exit;
}

$mode_file = __DIR__ . '/../detection_mode.json';
$current   = [];
if (file_exists($mode_file)) {
    $current = json_decode(file_get_contents($mode_file), true) ?? [];
}

$current['mode']       = $mode;
$current['updated_at'] = date('Y-m-d H:i:s');

if (file_put_contents($mode_file, json_encode($current, JSON_PRETTY_PRINT)) !== false) {
    echo json_encode(['success' => true, 'mode' => $mode]);
} else {
    echo json_encode(['success' => false, 'message' => 'Failed to write detection_mode.json.']);
}
