<?php
/**
 * auth/truncate_sandbox.php
 * Truncates detections for room_id = 'DESK'.
 */
require_once __DIR__ . '/service_bootstrap.php';
header('Content-Type: application/json');

if (($_SESSION['user_role'] ?? '') !== 'admin' && ($_SESSION['user_role'] ?? '') !== 'staff') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

try {
    // We only delete detections where room_id = 'DESK' to keep sandbox isolated
    $stmt = $monitorPdo->prepare("DELETE FROM detections WHERE room_id = 'DESK'");
    $stmt->execute();
    
    // Also delete sandbox logs from monitoring_logs
    $stmtLogs = $monitorPdo->prepare("DELETE FROM monitoring_logs WHERE room_id = 'DESK'");
    $stmtLogs->execute();

    // Reset baseline counts for DESK
    $monitorPdo->prepare("UPDATE rooms SET baseline_count = 0 WHERE room_id = 'DESK'")->execute();
    
    // Clear items
    $monitorPdo->prepare("DELETE FROM registered_lab_items WHERE room_id = 'DESK'")->execute();

    echo json_encode(['success' => true]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}
