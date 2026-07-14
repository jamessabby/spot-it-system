<?php
/**
 * S.P.O.T.-IT — Validate Detection Handler
 * auth/validate_detection.php
 *
 * POST endpoint. Updates validation_status (and optionally confidence_score override)
 * for a specific detection event. Separate from update_event_status.php because:
 *   - validation_status = did a human CHECK the detection? (epistemological)
 *   - status           = what happened to the item? (operational)
 * A detection can be 'verified' (human confirmed it is real) while the item
 * is still 'pending' (not yet recovered).
 *
 * POST body:
 *   detection_id      int      required
 *   validation_status string   required: verified | rejected | needs_review
 *   override_score    int      optional: admin can manually override confidence 0-100
 *   notes             string   optional
 *
 * MICROSERVICES: Writes to spotit_monitor_db only.
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

ms_require_auth('../pages/login.php');

if (!in_array($_SESSION['user_role'] ?? '', ['staff', 'admin'], true)) {
    ms_json(['success' => false, 'message' => 'Staff or admin role required.'], 403);
}

$detection_id    = (int)($_POST['detection_id']    ?? 0);
$validation      = trim($_POST['validation_status'] ?? '');
$notes           = trim($_POST['notes']             ?? '');
$override_score  = isset($_POST['override_score'])
                   ? max(0, min(100, (int)$_POST['override_score']))
                   : null;

$allowed = ['verified', 'rejected', 'needs_review', 'pending_review'];
if (!$detection_id || !in_array($validation, $allowed, true)) {
    ms_json(['success' => false, 'message' => 'Invalid detection_id or validation_status.']);
}

$actorId   = (int)$_SESSION['user_id'];
$actorName = $_SESSION['user_name'] ?? 'Staff';

// Fetch existing
$stmt = $monitorPdo->prepare(
    "SELECT detection_id, room_id, confidence_score, validation_status
     FROM detections WHERE detection_id = ? LIMIT 1"
);
$stmt->execute([$detection_id]);
$det = $stmt->fetch();
if (!$det) {
    ms_json(['success' => false, 'message' => 'Detection not found.']);
}

// Recompute grade if score is being overridden
$newScore = $override_score ?? $det['confidence_score'];
$newGrade = match(true) {
    $newScore >= 85 => 'HIGH',
    $newScore >= 60 => 'MEDIUM',
    $newScore >= 30 => 'LOW',
    default         => 'NOISE',
};

try {
    $noteAppend = $notes
        ? "\n[" . date('Y-m-d H:i:s') . "] {$actorName} (validation): {$notes}"
        : '';

    if ($override_score !== null) {
        $monitorPdo->prepare(
            "UPDATE detections
             SET validation_status   = ?,
                 validated_by        = ?,
                 validated_at        = NOW(),
                 confidence_score    = ?,
                 confidence_grade    = ?,
                 notes               = CONCAT(COALESCE(notes,''), ?),
                 updated_at          = NOW()
             WHERE detection_id = ?"
        )->execute([
            $validation, $actorId,
            $newScore, $newGrade,
            $noteAppend . "\n[Score overridden from {$det['confidence_score']} to {$newScore} by {$actorName}]",
            $detection_id,
        ]);
    } else {
        $monitorPdo->prepare(
            "UPDATE detections
             SET validation_status   = ?,
                 validated_by        = ?,
                 validated_at        = NOW(),
                 notes               = CONCAT(COALESCE(notes,''), ?),
                 updated_at          = NOW()
             WHERE detection_id = ?"
        )->execute([$validation, $actorId, $noteAppend, $detection_id]);
    }

    // Audit log
    $monitorPdo->prepare(
        "INSERT INTO monitoring_logs
           (room_id, event_type, event_message, triggered_by, logged_at)
         VALUES (?, 'validation_update', ?, ?, NOW())"
    )->execute([
        $det['room_id'],
        "Detection #{$detection_id} validation: {$det['validation_status']} → {$validation}" .
        ($override_score !== null ? " | Score overridden: {$det['confidence_score']}→{$newScore}" : '') .
        ($notes ? " | Note: {$notes}" : ''),
        $actorId,
    ]);

    ms_json([
        'success'           => true,
        'detection_id'      => $detection_id,
        'validation_status' => $validation,
        'confidence_score'  => $newScore,
        'confidence_grade'  => $newGrade,
        'validated_by'      => $actorName,
        'validated_at'      => date('Y-m-d H:i:s'),
    ]);

} catch (Throwable $e) {
    ms_json(['success' => false, 'message' => 'Failed to update validation status.'], 500);
}
