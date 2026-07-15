<?php
require_once __DIR__ . '/service_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

ms_require_auth('../pages/login.php');

if (!in_array($_SESSION['user_role'] ?? '', ['staff', 'admin'], true)) {
    ms_json(['success' => false, 'message' => 'Unauthorized.'], 403);
}

$mode = trim($_POST['mode'] ?? 'testing');
if (!in_array($mode, ['testing', 'production'], true)) {
    ms_json(['success' => false, 'message' => 'Invalid mode.']);
}

$targetJson = __DIR__ . '/../detection_mode.json';
$data = ['mode' => $mode, 'updated_at' => date('Y-m-d H:i:s')];

if (file_put_contents($targetJson, json_encode($data, JSON_PRETTY_PRINT)) === false) {
    ms_json(['success' => false, 'message' => 'Failed to save detection mode configuration.']);
}

ms_json(['success' => true, 'message' => 'Detection mode updated successfully!', 'mode' => $mode]);
