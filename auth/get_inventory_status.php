<?php
/**
 * S.P.O.T.-IT — Get Inventory Status Handler
 * auth/get_inventory_status.php
 *
 * GET endpoint. Returns per-room inventory summary for inventory-monitor.php.
 * Polled every 10 seconds by the auto-refresh system.
 *
 * Returns for each room:
 *   - room metadata (id, name, floor, type, cameras, status)
 *   - baseline_count   (from rooms.baseline_count — registered expected total)
 *   - live_count       (sum of detections.live_count for active detections)
 *   - deviation        (live_count - baseline_count, computed)
 *   - zone_breakdown   (per registered_lab_items ROI zone with live counts)
 *   - active_detection (most recent unresolved detection_id + detected_at)
 *   - elapsed_minutes  (time since first deviation for this room)
 *   - recent_logs      (last 5 monitoring_log entries for the room)
 *
 * MICROSERVICES: Reads from spotit_monitor_db only.
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

ms_require_auth('../pages/login.php');

// Optional single-room filter
$filterRoom = trim($_GET['room'] ?? '');

try {
    // ── 1. Fetch all active rooms ─────────────────────────────────────────────
    $roomWhere  = $filterRoom ? "WHERE r.room_id = ?" : "WHERE r.is_active = 1 AND r.room_id != 'DESK'";
    $roomParams = $filterRoom ? [$filterRoom] : [];

    $roomStmt = $monitorPdo->prepare(
        "SELECT
            r.room_id,
            r.room_name,
            r.floor,
            r.room_type,
            r.camera_count,
            r.baseline_count,
            r.monitoring_status,
            r.last_calibrated
         FROM rooms r
         {$roomWhere}
         ORDER BY r.floor, r.room_id"
    );
    $roomStmt->execute($roomParams);
    $rooms = $roomStmt->fetchAll();

    if (!$rooms) {
        ms_json(['success' => true, 'rooms' => [], 'summary' => _emptySummary()]);
    }

    $roomIds = array_column($rooms, 'room_id');
    $ph      = implode(',', array_fill(0, count($roomIds), '?'));

    // ── 2. Fetch active (unresolved) detections per room ─────────────────────
    // We want the most recent pending/potential/confirmed detection per room.
    $detStmt = $monitorPdo->prepare(
        "SELECT
            d.room_id,
            d.detection_id,
            d.object_zone,
            d.object_type,
            d.status,
            d.baseline_count,
            d.live_count,
            d.deviation,
            d.detected_at,
            d.snapshot_path,
            TIMESTAMPDIFF(MINUTE, d.detected_at, NOW()) AS elapsed_minutes
         FROM detections d
         INNER JOIN (
           SELECT room_id, MAX(detection_id) AS max_id
           FROM detections
           WHERE status IN ('pending','potential','confirmed_missing')
             AND is_removed = 0
             AND room_id IN ({$ph})
           GROUP BY room_id
         ) latest ON d.detection_id = latest.max_id"
    );
    $detStmt->execute($roomIds);
    $detections = [];
    foreach ($detStmt->fetchAll() as $d) {
        $detections[$d['room_id']] = $d;
    }

    // ── 3. Fetch registered lab items (ROI zones) per room ───────────────────
    $zoneStmt = $monitorPdo->prepare(
        "SELECT
            item_id,
            room_id,
            item_name,
            roi_label,
            expected_count,
            tier,
            is_active
         FROM registered_lab_items
         WHERE room_id IN ({$ph})
           AND is_active = 1
         ORDER BY room_id, item_id"
    );
    $zoneStmt->execute($roomIds);
    $zonesByRoom = [];
    foreach ($zoneStmt->fetchAll() as $z) {
        $zonesByRoom[$z['room_id']][] = $z;
    }

    // ── 4. Fetch recent logs per room (last 5 entries each) ───────────────────
    $logStmt = $monitorPdo->prepare(
        "SELECT l.room_id, l.event_type, l.event_message, l.logged_at
         FROM monitoring_logs l
         INNER JOIN (
           SELECT room_id, log_id
           FROM monitoring_logs
           WHERE room_id IN ({$ph})
           ORDER BY logged_at DESC
         ) recent ON l.log_id = recent.log_id
         ORDER BY l.room_id, l.logged_at DESC"
    );
    $logStmt->execute($roomIds);
    $logsByRoom = [];
    foreach ($logStmt->fetchAll() as $log) {
        if (count($logsByRoom[$log['room_id']] ?? []) < 5) {
            $logsByRoom[$log['room_id']][] = [
                'type'    => _logEventColor($log['event_type']),
                'message' => $log['event_message'],
                'ts'      => date('H:i:s · M j', strtotime($log['logged_at'])),
            ];
        }
    }

    // ── 5. Build per-room response ────────────────────────────────────────────
    $result   = [];
    $summary  = ['rooms' => 0, 'normal' => 0, 'potential' => 0,
                 'missing' => 0, 'offline' => 0, 'total_baseline' => 0];

    foreach ($rooms as $room) {
        $rid   = $room['room_id'];
        $det   = $detections[$rid] ?? null;
        $zones = $zonesByRoom[$rid] ?? [];
        $logs  = $logsByRoom[$rid]  ?? [];

        // Determine room status from monitoring_status + active detection
        if ($room['monitoring_status'] !== 'active') {
            $roomStatus = 'offline';
        } elseif ($det) {
            $roomStatus = match($det['status']) {
                'confirmed_missing' => 'missing',
                'potential'         => 'potential',
                default             => 'pending',
            };
        } else {
            $roomStatus = 'normal';
        }

        // Live count = baseline minus deviation (since deviation is negative for missing items)
        $liveCount = $det
            ? ($room['baseline_count'] + (int)$det['deviation'])
            : $room['baseline_count'];
        $deviation = $det ? (int)$det['deviation'] : 0;

        // Build zone breakdown — annotate each registered zone with live status
        $zoneBreakdown = [];
        foreach ($zones as $z) {
            // In live system: zone live count comes from detections matching this roi_label
            // For now: if an active detection involves this zone, mark it as missing
            $zoneLive = $z['expected_count'];
            $zoneDev  = 0;
            $zoneState = 'ok';

            if ($det && stripos($det['object_zone'], $z['roi_label']) !== false) {
                $zoneLive  = max(0, $z['expected_count'] - abs((int)$det['deviation']));
                $zoneDev   = $zoneLive - $z['expected_count'];
                $zoneState = $det['status'] === 'confirmed_missing' ? 'alert'
                           : ($det['status'] === 'potential' ? 'warn' : 'warn');
            }

            $zoneBreakdown[] = [
                'zone_id'        => $z['item_id'],
                'name'           => $z['item_name'],
                'roi_label'      => $z['roi_label'],
                'expected'       => (int)$z['expected_count'],
                'live'           => $zoneLive,
                'deviation'      => $zoneDev,
                'state'          => $zoneState,
                'tier'           => $z['tier'],
            ];
        }

        // Fill percentage
        $fillPct = $room['baseline_count'] > 0
            ? round($liveCount / $room['baseline_count'] * 100, 1)
            : 0;

        // Stage using persisted DB status
        $stage = $det
            ? ms_detection_stage($det['detected_at'], $det['status'])
            : ['stage' => 'normal', 'label' => 'Normal', 'mins' => 0];

        $result[] = [
            'id'               => $rid,
            'name'             => $room['room_name'],
            'floor'            => $room['floor'],
            'type'             => $room['room_type'],
            'cameras'          => (int)$room['camera_count'],
            'monitoring_status'=> $room['monitoring_status'],
            'status'           => $roomStatus,
            'baseline'         => (int)$room['baseline_count'],
            'live'             => $liveCount,
            'deviation'        => $deviation,
            'fill_pct'         => $fillPct,
            'last_calibrated'  => $room['last_calibrated']
                ? date('M j, Y', strtotime($room['last_calibrated'])) : null,
            'stage'            => $stage,
            'active_detection' => $det ? [
                'detection_id' => (int)$det['detection_id'],
                'object_zone'  => $det['object_zone'],
                'object_type'  => $det['object_type'],
                'status'       => $det['status'],
                'detected_at'  => $det['detected_at'],
                'elapsed_mins' => (int)$det['elapsed_minutes'],
                'snapshot_url' => $det['snapshot_path']
                    ? SNAPSHOT_URL . basename($det['snapshot_path']) : null,
            ] : null,
            'zones'      => $zoneBreakdown,
            'recent_log' => $logs,
        ];

        // Accumulate summary
        $summary['rooms']++;
        $summary['total_baseline'] += (int)$room['baseline_count'];
        if (isset($summary[$roomStatus])) $summary[$roomStatus]++;
    }

    ms_json([
        'success'    => true,
        'rooms'      => $result,
        'summary'    => $summary,
        'polled_at'  => date('Y-m-d H:i:s'),
    ]);

} catch (Throwable $e) {
    ms_json([
        'success' => false,
        'message' => 'Failed to fetch inventory status.',
        'detail'  => $e->getMessage(),
    ], 500);
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function _emptySummary(): array {
    return ['rooms' => 0, 'normal' => 0, 'potential' => 0,
            'missing' => 0, 'offline' => 0, 'total_baseline' => 0];
}

function _logEventColor(string $type): string {
    return match($type) {
        'detection', 'auto_escalation', 'confirmed_missing' => 'alert',
        'status_update', 'claim_submitted'                  => 'warn',
        'claim_completed', 'recalibration'                  => 'ok',
        default                                             => 'info',
    };
}
