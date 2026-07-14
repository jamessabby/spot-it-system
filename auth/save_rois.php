<?php
require_once __DIR__ . '/service_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

ms_require_auth('../pages/login.php');

if (!in_array($_SESSION['user_role'] ?? '', ['staff', 'admin'], true)) {
    ms_json(['success' => false, 'message' => 'Unauthorized.'], 403);
}

$roomId = trim($_POST['room_id'] ?? 'DESK');
$roisJson = $_POST['rois'] ?? '';

$rois = json_decode($roisJson, true);
if (!is_array($rois)) {
    ms_json(['success' => false, 'message' => 'Invalid ROI payload.']);
}

// 1. Write to rois.json for Python script to consume
$pyRois = [];
foreach ($rois as $r) {
    $pyRois[] = [
        'label' => trim($r['label']),
        'x'     => (int)$r['x'],
        'y'     => (int)$r['y'],
        'w'     => (int)$r['w'],
        'h'     => (int)$r['h']
    ];
}

$targetJson = __DIR__ . '/../rois.json';
if (file_put_contents($targetJson, json_encode($pyRois, JSON_PRETTY_PRINT)) === false) {
    ms_json(['success' => false, 'message' => 'Failed to save rois.json config.']);
}

// 2. Clear old items for this room in DB and insert new ones
try {
    $monitorPdo->beginTransaction();

    // Remove old active items
    $monitorPdo->prepare("DELETE FROM registered_lab_items WHERE room_id = ?")->execute([$roomId]);

    // Insert new ones
    $insertStmt = $monitorPdo->prepare("
        INSERT INTO registered_lab_items (room_id, item_name, roi_label, expected_count, tier, registered_by, registered_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
    ");

    $registeredBy = $_SESSION['user_id'] ?? null;

    foreach ($rois as $r) {
        $label = trim($r['label']);
        $tier  = trim($r['tier'] ?? 'tier1');
        if (!in_array($tier, ['tier1', 'tier2', 'tier3', 'tier4'], true)) {
            $tier = 'tier1';
        }
        $insertStmt->execute([
            $roomId,
            $label,
            $label,
            1,
            $tier,
            $registeredBy
        ]);
    }

    // Update rooms baseline count
    $count = count($rois);
    $monitorPdo->prepare("UPDATE rooms SET baseline_count = ? WHERE room_id = ?")->execute([$count, $roomId]);

    $monitorPdo->commit();
    ms_json(['success' => true, 'message' => 'ROIs saved and synced with database successfully!', 'count' => $count]);

} catch (PDOException $e) {
    $monitorPdo->rollBack();
    ms_json(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
