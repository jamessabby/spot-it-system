<?php
/**
 * S.P.O.T.-IT — Get Detections API  [FIXES G4]
 * auth/get_detections.php
 *
 * GET endpoint. Returns JSON.
 * Called every 10s by dashboard JS.
 *
 * FIXES:
 *   G4 — Summary badge for 'potential' previously queried a mix of status + elapsed
 *        which always returned 0 because status was never actually written to
 *        'potential' in the DB. Now that escalate_detections.php writes the status,
 *        we query status = 'potential' directly. The 'pending' badge also fixed:
 *        it now correctly shows items detected but not yet at the 30-min threshold.
 *
 * MICROSERVICES: Reads from spotit_monitor_db and spotit_lf_db.
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

ms_require_auth('../pages/login.php');

$summary = isset($_GET['summary']);

// ── [G4 FIXED] Summary mode — correct status-based badge counts ───────────────
if ($summary) {
    try {
        // confirmed_missing: status explicitly set by escalate_detections.php
        $confirmed = (int)$monitorPdo->query(
            "SELECT COUNT(*) FROM detections
             WHERE status = 'confirmed_missing'
               AND room_id != 'DESK'
               AND is_removed = 0"
        )->fetchColumn();

        // potential: status explicitly set (was always 0 before G4 fix)
        $potential = (int)$monitorPdo->query(
            "SELECT COUNT(*) FROM detections
             WHERE status = 'potential'
               AND room_id != 'DESK'
               AND is_removed = 0"
        )->fetchColumn();

        // pending: detected but not yet at 30-min threshold (still being watched)
        $pending = (int)$monitorPdo->query(
            "SELECT COUNT(*) FROM detections
             WHERE status = 'pending'
               AND room_id != 'DESK'
               AND is_removed = 0"
        )->fetchColumn();

        // pending claims from lf_db
        $claims = (int)$lfPdo->query(
            "SELECT COUNT(*) FROM claims WHERE status = 'pending'"
        )->fetchColumn();

        // unread notifications for current user
        $userId   = (int)$_SESSION['user_id'];
        $unreadStmt = $authPdo->prepare(
            "SELECT COUNT(*) FROM notifications
             WHERE user_id = ? AND is_read = 0"
        );
        $unreadStmt->execute([$userId]);
        $unread = (int)$unreadStmt->fetchColumn();

        ms_json([
            'confirmed_missing' => $confirmed,
            'potential_lost'    => $potential,
            'pending_new'       => $pending,
            'pending_claims'    => $claims,
            'unread_notifs'     => $unread,
            'polled_at'         => date('Y-m-d H:i:s'),
        ]);

    } catch (Throwable $e) {
        ms_json([
            'confirmed_missing' => 0,
            'potential_lost'    => 0,
            'pending_new'       => 0,
            'pending_claims'    => 0,
            'unread_notifs'     => 0,
        ]);
    }
}

// ── Full detection list ───────────────────────────────────────────────────────
$room_id = $_GET['room']   ?? null;
$status  = $_GET['status'] ?? null;
$limit   = min((int)($_GET['limit']  ?? 50), 200);
$offset  = (int)($_GET['offset'] ?? 0);

$where  = ['d.is_removed = 0'];
$params = [];

if ($room_id) {
    $where[] = 'd.room_id = ?';
    $params[] = $room_id;
} else {
    $where[] = "d.room_id != 'DESK'";
}
if ($status)  { $where[] = 'd.status = ?';    $params[] = $status; }

$whereSQL = implode(' AND ', $where);

try {
    $stmt = $monitorPdo->prepare(
        "SELECT
            d.detection_id,
            d.room_id,
            r.room_name,
            d.object_type,
            d.object_zone,
            d.detected_at,
            d.snapshot_path,
            d.snapshot_path_b,
            d.baseline_count,
            d.live_count,
            (d.live_count - d.baseline_count)  AS deviation,
            d.roi_change_pct,
            d.match_score,
            d.confidence_score,
            d.confidence_grade,
            d.confidence_factors,
            d.validation_status,
            d.validated_by,
            d.validated_at,
            d.status,
            d.verified_by,
            d.notes,
            TIMESTAMPDIFF(MINUTE, d.detected_at, NOW()) AS elapsed_minutes
         FROM detections d
         LEFT JOIN rooms r ON r.room_id = d.room_id
         WHERE {$whereSQL}
         ORDER BY d.detected_at DESC
         LIMIT ? OFFSET ?"
    );
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $detections = $stmt->fetchAll();

    // Enrich — add stage label + snapshot URL
    foreach ($detections as &$det) {
        $det['stage']        = ms_detection_stage($det['detected_at']);
        $det['snapshot_url'] = $det['snapshot_path']
            ? SNAPSHOT_URL . basename($det['snapshot_path'])
            : null;
        $det['snapshot_url_b'] = $det['snapshot_path_b']
            ? SNAPSHOT_URL . basename($det['snapshot_path_b'])
            : null;

        // Lookup verified_by name if set
        if ($det['verified_by']) {
            $u = ms_get_user($authPdo, (int)$det['verified_by']);
            $det['verified_by_name'] = $u['full_name'] ?? null;
        } else {
            $det['verified_by_name'] = null;
        }
    }
    unset($det);

    ms_json([
        'success'    => true,
        'count'      => count($detections),
        'detections' => $detections,
        'polled_at'  => date('Y-m-d H:i:s'),
    ]);

} catch (Throwable $e) {
    ms_json(['success' => false, 'message' => 'Failed to fetch detections.', 'detail' => $e->getMessage()], 500);
}
