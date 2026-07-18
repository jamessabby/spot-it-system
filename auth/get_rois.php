<?php
require_once __DIR__ . '/service_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

ms_require_auth('../pages/login.php');

if (!in_array($_SESSION['user_role'] ?? '', ['staff', 'admin'], true)) {
    ms_json(['success' => false, 'message' => 'Unauthorized.'], 403);
}

$roomId = trim($_GET['room_id'] ?? '');

if ($roomId !== '') {
    try {
        $stmt = $monitorPdo->prepare("SELECT roi_label, tier, bounding_box FROM registered_lab_items WHERE room_id = ?");
        $stmt->execute([$roomId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $rois = [];
        foreach ($rows as $row) {
            $bbox = json_decode($row['bounding_box'], true) ?: ['x' => 0, 'y' => 0, 'w' => 0, 'h' => 0];
            $rois[] = [
                'label' => $row['roi_label'],
                'tier'  => $row['tier'],
                'x'     => $bbox['x'] ?? 0,
                'y'     => $bbox['y'] ?? 0,
                'w'     => $bbox['w'] ?? 0,
                'h'     => $bbox['h'] ?? 0
            ];
        }
        echo json_encode($rois);
    } catch (PDOException $e) {
        echo '[]';
    }
} else {
    $targetJson = __DIR__ . '/../rois.json';
    if (file_exists($targetJson)) {
        $rois = json_decode(file_get_contents($targetJson), true) ?: [];
        foreach ($rois as &$r) {
            if (!isset($r['tier'])) {
                $r['tier'] = 'tier1';
            }
        }
        echo json_encode($rois);
    } else {
        echo '[]';
    }
}
