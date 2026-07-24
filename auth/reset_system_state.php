<?php
/**
 * auth/reset_system_state.php
 * Master reset endpoint for room 'DESK'.
 * Clears bounded ROIs (rois.json), reference image (photos/ref_image.jpg),
 * snapshot files, and truncates database logs.
 */
require_once __DIR__ . '/service_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if (($_SESSION['user_role'] ?? '') !== 'admin' && ($_SESSION['user_role'] ?? '') !== 'staff') {
    ms_json(['success' => false, 'message' => 'Unauthorized. Admin or staff role required.'], 401);
}

try {
    // 1. Clear bounded ROIs in rois.json
    $roi_file = __DIR__ . '/../rois.json';
    @file_put_contents($roi_file, json_encode([], JSON_PRETTY_PRINT));

    // 2. Remove reference image & live frames in photos/
    $photos_dir = __DIR__ . '/../photos';
    if (is_dir($photos_dir)) {
        $pfiles = @glob($photos_dir . '/*');
        if (is_array($pfiles)) {
            foreach ($pfiles as $pf) {
                if (is_file($pf)) {
                    @unlink($pf);
                }
            }
        }
    }

    // 3. Remove snapshot image files in uploads/snapshots/
    $snapshot_dir = __DIR__ . '/../uploads/snapshots';
    if (is_dir($snapshot_dir)) {
        $files = @glob($snapshot_dir . '/*');
        if (is_array($files)) {
            foreach ($files as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
        }
    }

    // 4. Copy clean standby placeholder to active snapshot paths
    $standby_svg = __DIR__ . '/../assets/img/standby_placeholder.svg';
    if (file_exists($standby_svg)) {
        @copy($standby_svg, __DIR__ . '/../uploads/snapshots/clean_DESK.jpg');
        @copy($standby_svg, __DIR__ . '/../photos/ref_image.jpg');
    }

    // 4. Truncate database records for DESK safely
    try { $monitorPdo->exec("DELETE FROM detections WHERE room_id = 'DESK'"); } catch (Throwable $t) {}
    try { $monitorPdo->exec("DELETE FROM monitoring_logs WHERE room_id = 'DESK'"); } catch (Throwable $t) {}
    try { $monitorPdo->exec("DELETE FROM recalibrations WHERE room_id = 'DESK'"); } catch (Throwable $t) {}
    try { $monitorPdo->exec("DELETE FROM registered_lab_items WHERE room_id = 'DESK'"); } catch (Throwable $t) {}
    try { $monitorPdo->exec("UPDATE rooms SET baseline_count = 0, monitoring_status = 'active' WHERE room_id = 'DESK'"); } catch (Throwable $t) {}
    try { $monitorPdo->exec("DELETE FROM accuracy_trials"); } catch (Throwable $t) {}

    try {
        $lfPdo = getLfDB();
        $lfPdo->exec("DELETE FROM recovered_items WHERE room_id = 'DESK'");
    } catch (Throwable $t) {}

    // 5. Signal detection_mode.json for main.py dynamic state reset
    $mode_file = __DIR__ . '/../detection_mode.json';
    $mode_data = [
        'mode' => 'testing',
        'tracking_mode' => 'registered',
        'reset_paused' => true,
        'reset_rois' => true,
        'reset_timestamp' => time(),
        'updated_at' => date('Y-m-d H:i:s')
    ];
    @file_put_contents($mode_file, json_encode($mode_data, JSON_PRETTY_PRINT));

    ms_json([
        'success' => true,
        'message' => 'System reset completed! Reference image, bounded ROIs, snapshot files, and detection logs have been cleared.'
    ]);

} catch (Throwable $e) {
    ms_json(['success' => false, 'message' => 'Reset error: ' . $e->getMessage()], 500);
}
