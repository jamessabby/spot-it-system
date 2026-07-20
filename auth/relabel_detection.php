<?php
/**
 * auth/relabel_detection.php
 * Allows admin/staff to relabel an auto-detected item (e.g. 'object1' -> 'Black Jewelry Box')
 */
require_once __DIR__ . '/service_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

ms_require_auth('../pages/login.php');

if (!in_array($_SESSION['user_role'] ?? '', ['staff', 'admin'], true)) {
    ms_json(['success' => false, 'message' => 'Unauthorized.'], 403);
}

$detection_id = (int)($_POST['detection_id'] ?? 0);
$new_name     = trim($_POST['new_name'] ?? '');

if (!$detection_id || !$new_name) {
    ms_json(['success' => false, 'message' => 'Invalid parameters. Detection ID and new label are required.']);
}

try {
    $monitorPdo = getMonitorDB();
    $stmt = $monitorPdo->prepare("UPDATE detections SET object_zone = ?, object_type = ? WHERE detection_id = ?");
    $stmt->execute([$new_name, $new_name, $detection_id]);

    ms_json(['success' => true, 'message' => "Item successfully relabeled to '{$new_name}'"]);
} catch (PDOException $e) {
    ms_json(['success' => false, 'message' => 'Database update error: ' . $e->getMessage()]);
}
