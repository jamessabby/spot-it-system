<?php
/**
 * S.P.O.T.-IT — Detection Ingest Endpoint
 * auth/ingest_detection.php
 *
 * POST endpoint called exclusively by the Python/OpenCV module.
 * Secured by SPOTIT_DETECTION_KEY.
 *
 * CONFIDENCE SCORING (new):
 *   Computes a composite 0–100 confidence score from three OpenCV signals:
 *     A) match_score     — template similarity (0.0–1.0, lower = more change)
 *     B) roi_change_pct  — % pixel area changed within ROI
 *     C) deviation       — absolute item count difference vs baseline
 *   Stores confidence_score, confidence_grade, confidence_factors, validation_status.
 *   AUTO-ACCEPTS (validation_status='auto_accepted') if confidence >= 85.
 *   FLAGS FOR REVIEW (validation_status='needs_review') if confidence < 30.
 *
 * Previous fixes retained: G1 duplicate suppression, G2 monitoring_logs.
 * MICROSERVICES: Writes to spotit_monitor_db only.
 */
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../services/monitoring/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit();
}

// ── API key auth ──────────────────────────────────────────────────────────────
define('DETECTION_API_KEY', getenv('SPOTIT_DETECTION_KEY') ?: 'CHANGE_ME_DETECTION_KEY');
$provided_key = trim($_POST['api_key'] ?? $_SERVER['HTTP_X_SPOTIT_KEY'] ?? '');
if (!hash_equals(DETECTION_API_KEY, $provided_key)) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized.']);
    exit();
}

// ── Action-based Routing (Snapshot B / Mass Deviation) ────────────────────────
$action = trim($_POST['action'] ?? '');

if ($action === 'snapshot_b') {
    $detection_id = (int)($_POST['detection_id'] ?? 0);
    if (!$detection_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'detection_id is required for snapshot_b.']);
        exit();
    }

    $snapshot_path_b = null;
    if (!empty($_FILES['snapshot']['tmp_name']) && is_uploaded_file($_FILES['snapshot']['tmp_name'])) {
        $ext      = pathinfo($_FILES['snapshot']['name'], PATHINFO_EXTENSION) ?: 'jpg';
        $filename = date('Ymd_His') . '_' . $detection_id . '_b_' . uniqid() . '.' . $ext;
        $dest     = SNAPSHOT_PATH . $filename;

        if (move_uploaded_file($_FILES['snapshot']['tmp_name'], $dest)) {
            $snapshot_path_b = $filename;
        }
    }

    if (!$snapshot_path_b) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Failed to upload snapshot B.']);
        exit();
    }

    try {
        $monitorPdo = getMonitorDB();
        
        // Update the detection record with snapshot B
        $stmt = $monitorPdo->prepare("UPDATE detections SET snapshot_path_b = ?, status = 'confirmed_missing' WHERE detection_id = ?");
        $stmt->execute([$snapshot_path_b, $detection_id]);

        // Get details for logging
        $stmtDetails = $monitorPdo->prepare("SELECT room_id, object_zone FROM detections WHERE detection_id = ?");
        $stmtDetails->execute([$detection_id]);
        $details = $stmtDetails->fetch();
        $room_id = $details ? $details['room_id'] : 'UNKNOWN';
        $object_zone = $details ? $details['object_zone'] : 'UNKNOWN';

        // Log it
        $monitorPdo->prepare(
            "INSERT INTO monitoring_logs (room_id, event_type, event_message, triggered_by, logged_at)
             VALUES (?, 'detection_update', ?, 0, NOW())"
        )->execute([
            $room_id,
            "Snapshot B (secondary interaction) uploaded for Detection #{$detection_id} in {$object_zone}"
        ]);

        echo json_encode([
            'success'      => true,
            'message'      => 'Snapshot B uploaded successfully.',
            'detection_id' => $detection_id
        ]);
        exit();
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error.', 'detail' => $e->getMessage()]);
        exit();
    }
}

if ($action === 'item_recovered') {
    $room_id     = trim($_POST['room_id'] ?? 'DESK');
    $object_zone = trim($_POST['object_zone'] ?? '');
    
    if ($object_zone) {
        try {
            $monitorPdo = getMonitorDB();
            $stmt = $monitorPdo->prepare("
                UPDATE detections 
                SET status = 'recovered', updated_at = NOW() 
                WHERE room_id = ? AND object_zone = ? AND status IN ('pending', 'potential', 'confirmed_missing')
            ");
            $stmt->execute([$room_id, $object_zone]);

            $monitorPdo->prepare(
                "INSERT INTO monitoring_logs (room_id, event_type, event_message, triggered_by, logged_at)
                 VALUES (?, 'item_recovered', ?, 0, NOW())"
            )->execute([$room_id, "Item '{$object_zone}' automatically detected as physically restored on camera feed."]);
        } catch (Throwable $e) {
            // Ignore DB errors
        }
    }

    echo json_encode(['success' => true, 'message' => 'Item auto-marked as recovered']);
    exit();
}

if ($action === 'escalate_status') {
    $detection_id = (int)($_POST['detection_id'] ?? 0);
    $status       = trim($_POST['status'] ?? 'confirmed_missing');
    if ($detection_id) {
        try {
            $monitorPdo = getMonitorDB();
            $stmt = $monitorPdo->prepare("UPDATE detections SET status = ?, updated_at = NOW() WHERE detection_id = ?");
            $stmt->execute([$status, $detection_id]);
        } catch (Throwable $e) {
            // Ignore DB errors
        }
    }
    echo json_encode(['success' => true]);
    exit();
}

if ($action === 'mass_deviation') {
    $room_id = trim($_POST['room_id'] ?? '');
    if (!$room_id) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'room_id is required for mass_deviation.']);
        exit();
    }

    try {
        $monitorPdo = getMonitorDB();
        
        // Pause monitoring for the room
        $stmt = $monitorPdo->prepare("UPDATE rooms SET monitoring_status = 'inactive' WHERE room_id = ?");
        $stmt->execute([$room_id]);

        // Log mass-deviation alert
        $monitorPdo->prepare(
            "INSERT INTO monitoring_logs (room_id, event_type, event_message, triggered_by, logged_at)
             VALUES (?, 'mass_deviation', 'Mass deviation detected. Room monitoring paused. Recalibration required.', 0, NOW())"
        )->execute([$room_id]);

        echo json_encode([
            'success' => true,
            'message' => 'Room monitoring paused due to mass deviation.'
        ]);
        exit();
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error.', 'detail' => $e->getMessage()]);
        exit();
    }
}

// ── Inputs ────────────────────────────────────────────────────────────────────
$room_id        = trim($_POST['room_id']         ?? '');
$object_type    = trim($_POST['object_type']     ?? '');
$object_zone    = trim($_POST['object_zone']     ?? '');
$baseline_count = (int)($_POST['baseline_count'] ?? 0);
$live_count     = (int)($_POST['live_count']     ?? 0);
$roi_change_pct = (float)($_POST['roi_change_pct'] ?? 0.0);
$match_score    = isset($_POST['match_score']) ? (float)$_POST['match_score'] : null;

if (!$room_id || !$object_zone) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'room_id and object_zone are required.']);
    exit();
}

// ══════════════════════════════════════════════════════════════════════════════
// CONFIDENCE SCORE COMPUTATION
// ══════════════════════════════════════════════════════════════════════════════
//
// Three independent OpenCV signals, each weighted:
//
//   Signal A — Template Match Score (weight: 50 pts)
//     match_score is 0.0–1.0 where 1.0 = identical to baseline (nothing changed).
//     Inverted: (1 - match_score) × 50
//     If match_score=0.15 (very different): contributes 42.5 pts  ← high confidence
//     If match_score=0.90 (very similar):  contributes  5.0 pts  ← low confidence
//
//   Signal B — ROI Pixel Change % (weight: 30 pts)
//     roi_change_pct: % of ROI pixels that changed vs reference frame.
//     Capped at 100%: (min(roi_change_pct, 100) / 100) × 30
//     If roi_change_pct=80%: contributes 24.0 pts  ← high confidence
//     If roi_change_pct=10%: contributes  3.0 pts  ← low confidence
//
//   Signal C — Count Deviation (weight: 20 pts, max 2 items)
//     abs(live_count - baseline_count), capped at 2 items × 10 pts each.
//     If deviation=-2: contributes 20 pts  ← maximum (clearly 2 items gone)
//     If deviation=-1: contributes 10 pts
//     If deviation= 0: contributes  0 pts  ← no count change (likely false alarm)
//
// Total: 0–100.  Grades: HIGH≥85, MEDIUM≥60, LOW≥30, NOISE<30
//
// Auto-accept threshold: confidence_score >= 85 → validation_status = 'auto_accepted'
// Mandatory review flag: confidence_score <  30 → validation_status = 'needs_review'
// Default:                                        validation_status = 'pending_review'

$deviation = $live_count - $baseline_count;

// Normalise inputs defensively
$match_norm  = ($match_score !== null) ? max(0.0, min(1.0, $match_score))   : 0.5; // 0.5 if unknown
$change_norm = max(0.0, min(100.0, $roi_change_pct));
$dev_abs     = min(abs($deviation), 2); // cap at 2 items for weight purposes

// Individual contributions
$contrib_match  = round((1.0 - $match_norm) * 50.0, 2);   // 0–50
$contrib_change = round(($change_norm / 100.0) * 30.0, 2); // 0–30
$contrib_dev    = $dev_abs * 10;                            // 0, 10, or 20

$confidence_score = (int)min(100, max(0, round($contrib_match + $contrib_change + $contrib_dev)));

// Grade
$confidence_grade = match(true) {
    $confidence_score >= 85 => 'HIGH',
    $confidence_score >= 60 => 'MEDIUM',
    $confidence_score >= 30 => 'LOW',
    default                 => 'NOISE',
};

// Validation status
$validation_status = match(true) {
    $confidence_score >= 85 => 'auto_accepted',
    $confidence_score <  30 => 'needs_review',
    default                 => 'pending_review',
};

// JSON breakdown for audit trail
$confidence_factors = json_encode([
    'signals' => [
        'match_score'     => $match_score,
        'roi_change_pct'  => $roi_change_pct,
        'deviation'       => $deviation,
    ],
    'contributions' => [
        'match_contrib'   => $contrib_match,
        'change_contrib'  => $contrib_change,
        'dev_contrib'     => $contrib_dev,
    ],
    'weights' => [
        'match_weight'  => 50,
        'change_weight' => 30,
        'dev_weight'    => 20,
    ],
    'computed_at' => date('Y-m-d H:i:s'),
]);

// ── G1: Strict Duplicate suppression per object zone ──────────────────────────
try {
    $monitorPdo = getMonitorDB();
    // Match any active detection for this zone OR any detection logged in the last 15 minutes
    $existing = $monitorPdo->prepare(
        "SELECT detection_id, status FROM detections
         WHERE room_id = ? AND object_zone = ?
           AND (status IN ('pending','potential','confirmed_missing') OR detected_at >= NOW() - INTERVAL 15 MINUTE)
         ORDER BY detection_id DESC LIMIT 1"
    );
    $existing->execute([$room_id, $object_zone]);
    $dup = $existing->fetch();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'DB error during duplicate check.']);
    exit();
}

// ── Save snapshot using standard naming convention ───────────────────────────
$snapshot_path = null;
if (!empty($_FILES['snapshot']['tmp_name']) && is_uploaded_file($_FILES['snapshot']['tmp_name'])) {
    $orig_name = basename($_FILES['snapshot']['name']);
    // Preserve standard naming convention (e.g. mouse_registered_final.jpg)
    if (preg_match('/^[a-zA-Z0-9_\-]+\.(jpg|jpeg|png)$/i', $orig_name)) {
        $filename = $orig_name;
    } else {
        $ext      = pathinfo($_FILES['snapshot']['name'], PATHINFO_EXTENSION) ?: 'jpg';
        $filename = date('Ymd_His') . '_' . $room_id . '_' . uniqid() . '.' . $ext;
    }
    $dest = SNAPSHOT_PATH . $filename;
    if (move_uploaded_file($_FILES['snapshot']['tmp_name'], $dest)) {
        $snapshot_path = $filename;
    }
}

try {
    if ($dup) {
        // UPDATE existing detection row (prevent duplicate notifications)
        $monitorPdo->prepare(
            "UPDATE detections SET
               status              = 'confirmed_missing',
               live_count          = ?,
               baseline_count      = ?,
               roi_change_pct      = ?,
               match_score         = ?,
               snapshot_path       = COALESCE(?, snapshot_path),
               confidence_score    = ?,
               confidence_grade    = ?,
               confidence_factors  = ?,
               updated_at          = NOW()
             WHERE detection_id = ?"
        )->execute([
            $live_count, $baseline_count, $roi_change_pct, $match_score,
            $snapshot_path,
            $confidence_score, $confidence_grade, $confidence_factors,
            $dup['detection_id'],
        ]);
        $detection_id = $dup['detection_id'];
        $action = 'updated';
    } else {
        // INSERT new
        $monitorPdo->prepare(
            "INSERT INTO detections
               (room_id, object_type, object_zone, detected_at,
                snapshot_path, baseline_count, live_count,
                roi_change_pct, match_score,
                confidence_score, confidence_grade, confidence_factors,
                validation_status, status)
             VALUES (?, ?, ?, NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending')"
        )->execute([
            $room_id, $object_type, $object_zone,
            $snapshot_path, $baseline_count, $live_count,
            $roi_change_pct, $match_score,
            $confidence_score, $confidence_grade, $confidence_factors,
            $validation_status,
        ]);
        $detection_id = (int)$monitorPdo->lastInsertId();
        $action = 'inserted';
    }

    $monitorPdo->prepare(
        "INSERT INTO monitoring_logs
           (room_id, event_type, event_message, triggered_by, logged_at)
         VALUES (?, 'detection', ?, 0, NOW())"
    )->execute([
        $room_id,
        "Detection #{$detection_id} [{$action}]: {$object_zone} deviation={$deviation} confidence={$confidence_score}% ({$confidence_grade})"
    ]);

    // ── G3: Create in-app notification immediately for all admins and staff ──────
    if ($action === 'inserted') {
        try {
            require_once __DIR__ . '/../services/auth/db.php';
            $authPdo = getAuthDB();
            
            $usersStmt = $authPdo->prepare("SELECT id FROM users WHERE role IN ('admin', 'staff') AND is_active = 1");
            $usersStmt->execute();
            $targetUsers = $usersStmt->fetchAll();
            
            $notifStmt = $authPdo->prepare(
                "INSERT INTO notifications 
                   (user_id, type, title, body, detection_id, room_id, is_read, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, 0, NOW())"
            );
            
            $notifTitle = "New Deviation Detected — {$object_zone}";
            $notifBody  = "Item in {$room_id} has been reported missing (Confidence: {$confidence_score}% {$confidence_grade}).";
            $notifType  = 'potential_lost';
            
            foreach ($targetUsers as $u) {
                $notifStmt->execute([
                    $u['id'], 
                    $notifType, 
                    $notifTitle, 
                    $notifBody, 
                    $detection_id, 
                    $room_id
                ]);
            }
        } catch (Throwable $notifEx) {
            // Fail silently so a notification database issue doesn't crash the camera stream ingest
        }
    }

    echo json_encode([
        'success'           => true,
        'action'            => $action,
        'detection_id'      => $detection_id,
        'deviation'         => $deviation,
        'confidence_score'  => $confidence_score,
        'confidence_grade'  => $confidence_grade,
        'validation_status' => $validation_status,
        'stage'             => ms_detection_stage(date('Y-m-d H:i:s')),
    ]);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Database error during detection save.',
        'detail'  => $e->getMessage(),
    ]);
}
