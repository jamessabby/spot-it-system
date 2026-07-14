<?php
/**
 * S.P.O.T.-IT — Detection Escalation Cron Handler  [FIXES G3, G11]
 * auth/escalate_detections.php
 *
 * Run every 5 minutes via cron:
 *   * /5 * * * *  php /var/www/html/spotit/auth/escalate_detections.php >> /var/log/spotit_escalate.log 2>&1
 *
 * What this does:
 *   1. Finds all 'pending' detections that have elapsed >= TIMER_POTENTIAL_MIN
 *      and updates their status to 'potential'.
 *   2. Finds all 'potential' detections that have elapsed >= TIMER_CONFIRMED_MIN
 *      and updates their status to 'confirmed_missing'.
 *   3. For each escalation: writes a monitoring_log entry AND creates an
 *      in-app notification for all admin + staff users.
 *
 * FIXES:
 *   G3  — ms_detection_stage() previously only calculated stage for display.
 *         This handler actually PERSISTS the status change to the DB.
 *   G11 — This file is the missing cron handler for auto-escalation.
 *
 * MICROSERVICES: Writes to spotit_monitor_db and spotit_auth_db (for notifications).
 * Can also be triggered via HTTP with the internal cron API key for serverless hosts.
 */
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../services/monitoring/db.php';
require_once __DIR__ . '/../services/auth/db.php';

// Allow either CLI execution or HTTP with cron key
$isCli = (php_sapi_name() === 'cli');
if (!$isCli) {
    $cronKey = trim($_GET['cron_key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '');
    $expectedKey = getenv('SPOTIT_CRON_KEY') ?: 'CHANGE_ME_CRON_KEY';
    if (!hash_equals($expectedKey, $cronKey)) {
        http_response_code(401);
        echo json_encode(['error' => 'Unauthorized']);
        exit();
    }
    header('Content-Type: application/json; charset=utf-8');
}

$monitorPdo = getMonitorDB();
$authPdo    = getAuthDB();

$escalated_to_potential  = [];
$escalated_to_confirmed  = [];
$errors = [];

$now = date('Y-m-d H:i:s');

// ══════════════════════════════════════════════════════
// PASS 1 — pending → potential  (>= 30 min elapsed)
// ══════════════════════════════════════════════════════
try {
    $stmt = $monitorPdo->prepare(
        "SELECT detection_id, room_id, object_zone, object_type, detected_at
         FROM detections
         WHERE status = 'pending'
           AND TIMESTAMPDIFF(MINUTE, detected_at, NOW()) >= ?
           AND is_removed = 0"
    );
    $stmt->execute([TIMER_POTENTIAL_MIN]);
    $pending = $stmt->fetchAll();

    foreach ($pending as $det) {
        try {
            // Update detection status
            $monitorPdo->prepare(
                "UPDATE detections SET status = 'potential', updated_at = NOW()
                 WHERE detection_id = ?"
            )->execute([$det['detection_id']]);

            // Write escalation log
            $monitorPdo->prepare(
                "INSERT INTO monitoring_logs
                   (room_id, event_type, event_message, triggered_by, logged_at)
                 VALUES (?, 'auto_escalation', ?, 0, NOW())"
            )->execute([
                $det['room_id'],
                "Detection #{$det['detection_id']} escalated: pending → potential " .
                "({$det['object_zone']} in {$det['room_id']}, " .
                TIMER_POTENTIAL_MIN . " min elapsed)",
            ]);

            // Create notification for staff + admin
            _createNotification(
                $authPdo,
                'potential_lost',
                "Potentially Lost — {$det['object_zone']}",
                "Item in {$det['room_id']} has been undetected for " . TIMER_POTENTIAL_MIN . " minutes.",
                $det['detection_id'],
                $det['room_id'],
                ['admin', 'staff']
            );

            $escalated_to_potential[] = $det['detection_id'];

        } catch (Throwable $e) {
            $errors[] = "pending→potential #{$det['detection_id']}: " . $e->getMessage();
        }
    }

} catch (Throwable $e) {
    $errors[] = "Pass 1 query failed: " . $e->getMessage();
}

// ══════════════════════════════════════════════════════
// PASS 2 — potential → confirmed_missing  (>= 60 min elapsed)
// ══════════════════════════════════════════════════════
try {
    $stmt = $monitorPdo->prepare(
        "SELECT detection_id, room_id, object_zone, object_type, detected_at
         FROM detections
         WHERE status = 'potential'
           AND TIMESTAMPDIFF(MINUTE, detected_at, NOW()) >= ?
           AND is_removed = 0"
    );
    $stmt->execute([TIMER_CONFIRMED_MIN]);
    $potential = $stmt->fetchAll();

    foreach ($potential as $det) {
        try {
            $monitorPdo->prepare(
                "UPDATE detections SET status = 'confirmed_missing', updated_at = NOW()
                 WHERE detection_id = ?"
            )->execute([$det['detection_id']]);

            $monitorPdo->prepare(
                "INSERT INTO monitoring_logs
                   (room_id, event_type, event_message, triggered_by, logged_at)
                 VALUES (?, 'auto_escalation', ?, 0, NOW())"
            )->execute([
                $det['room_id'],
                "Detection #{$det['detection_id']} escalated: potential → confirmed_missing " .
                "({$det['object_zone']} in {$det['room_id']}, " .
                TIMER_CONFIRMED_MIN . " min elapsed) — AUTO-ESCALATED",
            ]);

            // High-priority notification to admin + staff
            _createNotification(
                $authPdo,
                'confirmed_missing',
                "⚠ CONFIRMED MISSING — {$det['object_zone']}",
                "Item in {$det['room_id']} has been missing for " . TIMER_CONFIRMED_MIN .
                " minutes and is now confirmed missing. Immediate action required.",
                $det['detection_id'],
                $det['room_id'],
                ['admin', 'staff']
            );

            $escalated_to_confirmed[] = $det['detection_id'];

        } catch (Throwable $e) {
            $errors[] = "potential→confirmed #{$det['detection_id']}: " . $e->getMessage();
        }
    }

} catch (Throwable $e) {
    $errors[] = "Pass 2 query failed: " . $e->getMessage();
}

// ══════════════════════════════════════════════════════
// Summary output
// ══════════════════════════════════════════════════════
$summary = [
    'run_at'                  => $now,
    'escalated_to_potential'  => $escalated_to_potential,
    'escalated_to_confirmed'  => $escalated_to_confirmed,
    'total_escalated'         => count($escalated_to_potential) + count($escalated_to_confirmed),
    'errors'                  => $errors,
];

if ($isCli) {
    echo "[" . $now . "] Escalation run complete:\n";
    echo "  potential:  " . count($escalated_to_potential) . " detection(s) " .
         (count($escalated_to_potential) ? "(IDs: " . implode(',', $escalated_to_potential) . ")" : "") . "\n";
    echo "  confirmed:  " . count($escalated_to_confirmed) . " detection(s) " .
         (count($escalated_to_confirmed) ? "(IDs: " . implode(',', $escalated_to_confirmed) . ")" : "") . "\n";
    if ($errors) {
        echo "  ERRORS (" . count($errors) . "):\n";
        foreach ($errors as $err) echo "    - $err\n";
    }
} else {
    echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}


// ══════════════════════════════════════════════════════
// Shared notification helper
// ══════════════════════════════════════════════════════
function _createNotification(
    PDO    $authPdo,
    string $type,
    string $title,
    string $body,
    int    $detectionId,
    string $roomId,
    array  $targetRoles
): void {
    // Fetch all users with the target roles
    $placeholders = implode(',', array_fill(0, count($targetRoles), '?'));
    $users = $authPdo->prepare(
        "SELECT id FROM users WHERE role IN ({$placeholders}) AND is_active = 1"
    );
    $users->execute($targetRoles);
    $userRows = $users->fetchAll();

    $stmt = $authPdo->prepare(
        "INSERT INTO notifications
           (user_id, type, title, body, detection_id, room_id, is_read, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 0, NOW())"
    );
    foreach ($userRows as $u) {
        $stmt->execute([
            $u['id'], $type, $title, $body,
            $detectionId, $roomId,
        ]);
    }
}
