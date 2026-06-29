<?php
/**
 * S.P.O.T.-IT — Get Detections API
 * auth/get_detections.php
 *
 * GET endpoint. Returns JSON.
 * Called every 10s by dashboard JS to update badges and room status.
 * MICROSERVICES: Reads from spotit_monitor_db only.
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

ms_require_auth('../pages/login.php');

$summary = isset($_GET['summary']);

// ── Summary mode (for sidebar badges) ─────────────────────────────────────────
if ($summary) {
    try {
        $confirmed = (int)$monitorPdo->query(
            "SELECT COUNT(*) FROM detections
             WHERE status = 'confirmed_missing'
               AND TIMESTAMPDIFF(MINUTE, detected_at, NOW()) >= " . TIMER_CONFIRMED_MIN
        )->fetchColumn();

        $potential = (int)$monitorPdo->query(
            "SELECT COUNT(*) FROM detections
             WHERE status IN ('pending','potential')
               AND TIMESTAMPDIFF(MINUTE, detected_at, NOW()) BETWEEN "
                . TIMER_POTENTIAL_MIN . " AND " . (TIMER_CONFIRMED_MIN - 1)
        )->fetchColumn();

        $claims = (int)$lfPdo->query(
            "SELECT COUNT(*) FROM claims WHERE status = 'pending'"
        )->fetchColumn();

        ms_json([
            'confirmed_missing' => $confirmed,
            'potential_lost'    => $potential,
            'pending_claims'    => $claims,
            'polled_at'         => date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
        ms_json(['confirmed_missing' => 0, 'potential_lost' => 0, 'pending_claims' => 0]);
    }
}

// ── Full detection list ───────────────────────────────────────────────────────
$room_id  = $_GET['room'] ?? null;
$status   = $_GET['status'] ?? null;
$limit    = min((int)($_GET['limit'] ?? 50), 200);
$offset   = (int)($_GET['offset'] ?? 0);

$where  = ['1=1'];
$params = [];

if ($room_id) {
    $where[]  = 'd.room_id = ?';
    $params[] = $room_id;
}
if ($status) {
    $where[]  = 'd.status = ?';
    $params[] = $status;
}

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
            d.baseline_count,
            d.live_count,
            (d.live_count - d.baseline_count) AS deviation,
            d.status,
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

    // Add detection stage to each event
    foreach ($detections as &$det) {
        $det['stage'] = ms_detection_stage($det['detected_at']);
        $det['snapshot_url'] = $det['snapshot_path']
            ? SNAPSHOT_URL . basename($det['snapshot_path'])
            : null;
    }

    ms_json([
        'success'    => true,
        'count'      => count($detections),
        'detections' => $detections,
        'polled_at'  => date('Y-m-d H:i:s'),
    ]);
} catch (Throwable $e) {
    ms_json(['success' => false, 'message' => 'Failed to fetch detections.'], 500);
}
