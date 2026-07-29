<?php
/**
 * S.P.O.T.-IT — Save ROIs Handler
 * auth/save_rois.php
 *
 * POST endpoint. Saves ROI inspection zones for a specified room.
 * Updates JSON configuration files and registered_lab_items in spotit_monitor_db.
 */
require_once __DIR__ . '/service_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

$role = $_SESSION['user_role'] ?? '';
if (!in_array($role, ['admin', 'staff'], true)) {
    ms_json(['success' => false, 'message' => 'Unauthorized access.'], 403);
}

$roomId   = strtoupper(trim($_POST['room_id'] ?? 'DESK'));
$roisJson = $_POST['rois'] ?? '';

$rois = json_decode($roisJson, true);
if (!is_array($rois)) {
    ms_json(['success' => false, 'message' => 'Invalid ROI payload structure.']);
}

// Format Python ROI payload
$pyRois = [];
foreach ($rois as $r) {
    $pyRois[] = [
        'label' => trim($r['label'] ?? 'object'),
        'tier'  => trim($r['tier'] ?? 'tier1'),
        'x'     => (int)($r['x'] ?? 0),
        'y'     => (int)($r['y'] ?? 0),
        'w'     => (int)($r['w'] ?? 50),
        'h'     => (int)($r['h'] ?? 50)
    ];
}

// 1. Write to per-room JSON file for Python main.py detector
if ($roomId === 'DESK') {
    $targetJson = __DIR__ . '/../rois.json';
} else {
    $targetJson = __DIR__ . "/../rois_{$roomId}.json";
}
if (@file_put_contents($targetJson, json_encode($pyRois, JSON_PRETTY_PRINT)) === false) {
    ms_json(['success' => false, 'message' => "Failed to write ROI configuration file: rois_{$roomId}.json"]);
}

// 2. Database Sync: registered_lab_items & rooms table
try {
    // Auto-heal: Ensure room_id exists in rooms table to satisfy FK constraint
    $checkRoom = $monitorPdo->prepare("SELECT COUNT(*) FROM rooms WHERE room_id = ?");
    $checkRoom->execute([$roomId]);
    if (!$checkRoom->fetchColumn()) {
        $monitorPdo->prepare("
            INSERT INTO rooms (room_id, room_name, floor, room_type, camera_count, camera_ip, camera_user, camera_pass, baseline_count, monitoring_status, is_active)
            VALUES (?, ?, '1st Floor', 'Lab', 1, '192.168.18.11', 'SpotItCamera', 'spotittapo232', 0, 'active', 1)
        ")->execute([$roomId, $roomId === 'DESK' ? 'Sandbox Desk' : "Room {$roomId}"]);
    }

    $monitorPdo->beginTransaction();

    // Clear previous ROI registrations for this room
    $delStmt = $monitorPdo->prepare("DELETE FROM registered_lab_items WHERE room_id = ?");
    $delStmt->execute([$roomId]);

    // Insert newly registered ROI items
    $insertStmt = $monitorPdo->prepare("
        INSERT INTO registered_lab_items (room_id, item_name, roi_label, expected_count, tier, bounding_box, registered_by, registered_at)
        VALUES (?, ?, ?, 1, ?, ?, ?, NOW())
    ");

    $registeredBy = $_SESSION['user_id'] ?? null;

    foreach ($pyRois as $r) {
        $label = $r['label'];
        $tier  = in_array($r['tier'], ['tier1', 'tier2', 'tier3', 'tier4'], true) ? $r['tier'] : 'tier1';
        $bbox  = json_encode(['x' => $r['x'], 'y' => $r['y'], 'w' => $r['w'], 'h' => $r['h']]);

        $insertStmt->execute([
            $roomId,
            ucwords(str_replace('_', ' ', $label)),
            $label,
            $tier,
            $bbox,
            $registeredBy
        ]);
    }

    // Update baseline count and calibration timestamp in rooms table
    $updateRoom = $monitorPdo->prepare("
        UPDATE rooms 
        SET baseline_count = ?, monitoring_status = 'active', last_calibrated = NOW()
        WHERE room_id = ?
    ");
    $updateRoom->execute([count($pyRois), $roomId]);

    $monitorPdo->commit();

    // 3. Write dynamic reload signal to detection_mode.json so main.py immediately reloads
    $dmFile = __DIR__ . '/../detection_mode.json';
    if (file_exists($dmFile)) {
        $dm = json_decode(file_get_contents($dmFile), true) ?: [];
        $dm['reset_rois'] = true;
        $dm['reset_paused'] = true;
        $dm['updated_at'] = date('Y-m-d H:i:s');
        @file_put_contents($dmFile, json_encode($dm, JSON_PRETTY_PRINT));
    }

    ms_json(['success' => true, 'message' => 'ROI configurations saved successfully.']);

} catch (Throwable $e) {
    if ($monitorPdo->inTransaction()) {
        $monitorPdo->rollBack();
    }
    ms_json(['success' => false, 'message' => 'Database error: ' . $e->getMessage()], 500);
}
