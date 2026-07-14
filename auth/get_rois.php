<?php
require_once __DIR__ . '/service_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

ms_require_auth('../pages/login.php');

if (!in_array($_SESSION['user_role'] ?? '', ['staff', 'admin'], true)) {
    ms_json(['success' => false, 'message' => 'Unauthorized.'], 403);
}

$targetJson = __DIR__ . '/../rois.json';
if (file_exists($targetJson)) {
    echo file_get_contents($targetJson);
} else {
    echo '[]';
}
