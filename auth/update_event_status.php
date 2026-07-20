<?php
/**
 * S.P.O.T.-IT — Update Event Status Handler  [FIXES G5, G6, G7]
 * auth/update_event_status.php
 *
 * POST endpoint. Returns JSON.
 *
 * FIXES:
 *   G5 — verified_by is now written to detections when staff changes status.
 *   G6 — monitoring_logs now always includes room_id and triggered_by (user_id).
 *   G7 — recovered_items INSERT now includes source, item_type, item_tier fields.
 *
 * MICROSERVICES: Writes to spotit_monitor_db + spotit_lf_db + spotit_auth_db (notifications).
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

ms_require_auth('../pages/login.php');

if (!in_array($_SESSION['user_role'] ?? '', ['staff', 'admin'], true)) {
    ms_json(['success' => false, 'message' => 'Unauthorized. Staff or admin role required.'], 403);
}

$detection_id = (int)($_POST['detection_id'] ?? 0);
$new_status   = trim($_POST['status']        ?? '');
$notes        = trim($_POST['notes']         ?? '');
$actorId      = (int)$_SESSION['user_id'];
$actorName    = $_SESSION['user_name'] ?? 'Staff';

$allowed_statuses = ['dismissed', 'pending', 'potential', 'confirmed_missing', 'recovered'];
if (!$detection_id || !in_array($new_status, $allowed_statuses, true)) {
    ms_json(['success' => false, 'message' => 'Invalid detection ID or status value.']);
}

// ── Fetch existing detection record ───────────────────────────────────────────
$stmt = $monitorPdo->prepare("SELECT * FROM detections WHERE detection_id = ? LIMIT 1");
$stmt->execute([$detection_id]);
$det = $stmt->fetch();
if (!$det) {
    ms_json(['success' => false, 'message' => 'Detection event not found.']);
}

// ── PHYSICAL CAMERA PRESENCE VALIDATION ─────────────────────────────────────────
if ($new_status === 'recovered') {
    $roi_state_file = __DIR__ . '/../photos/live_roi_state.json';
    if (file_exists($roi_state_file)) {
        $live_state = json_decode(file_get_contents($roi_state_file), true);
        $zone = $det['object_zone'] ?? $det['object_type'] ?? '';
        if (is_array($live_state) && isset($live_state[$zone]) && !empty($live_state[$zone]['is_missing'])) {
            ms_json([
                'success' => false,
                'message' => "Cannot mark zone '{$zone}' as Recovered: Physical camera stream detects the item is still missing from the ROI box."
            ], 400);
        }
    }
}

// ── [G5 FIXED] Update status AND verified_by in detections ────────────────────
try {
    $appendNote = "\n[" . date('Y-m-d H:i:s') . "] {$actorName}: " . ($notes ?: ucwords(str_replace('_',' ',$new_status)));
    // Map item status → validation_status
    $newValidation = match($new_status) {
        'dismissed'          => 'rejected',
        'recovered'          => 'verified',
        'confirmed_missing'  => 'verified',
        default              => 'pending_review',
    };

    $monitorPdo->prepare(
        "UPDATE detections
         SET status            = ?,
             verified_by       = ?,
             validated_by      = ?,
             validated_at      = NOW(),
             validation_status = ?,
             notes             = CONCAT(COALESCE(notes, ''), ?),
             updated_at        = NOW()
         WHERE detection_id = ?"
    )->execute([$new_status, $actorId, $actorId, $newValidation, $appendNote, $detection_id]);

} catch (PDOException $e) {
    ms_json(['success' => false, 'message' => 'Failed to update detection status.'], 500);
}

// ── [G6 FIXED] monitoring_logs with room_id AND triggered_by ─────────────────
try {
    $monitorPdo->prepare(
        "INSERT INTO monitoring_logs
           (room_id, event_type, event_message, triggered_by, logged_at)
         VALUES (?, 'status_update', ?, ?, NOW())"
    )->execute([
        $det['room_id'],
        "Detection #{$detection_id} → {$new_status} by {$actorName} (ID:{$actorId}). Notes: {$notes}",
        $actorId,
    ]);
} catch (Throwable $e) { /* non-critical — continue */ }

// ── [G7 FIXED] If recovered: create recovered_items with all required fields ──
if ($new_status === 'recovered') {
    try {
        // Determine item_tier from object_type (defaulting Tier 1 for electronics)
        $tier_map = [
            'monitor' => 'tier1', 'keyboard' => 'tier1', 'mouse' => 'tier1',
            'laptop'  => 'tier1', 'cpu'      => 'tier1', 'avr'  => 'tier1',
            'cable'   => 'tier2', 'charger'  => 'tier2', 'usb'  => 'tier2',
            'chair'   => 'tier3', 'bag'      => 'tier2',
        ];
        $objLower   = strtolower($det['object_type'] ?? '');
        $item_tier  = $tier_map[$objLower] ?? 'tier1';
        $item_type  = ucwords($det['object_type'] ?? 'Unknown Item');
        $description = "{$item_type} — {$det['object_zone']} (Detected {$det['detected_at']})";

        $lfPdo->prepare(
            "INSERT INTO recovered_items
               (detection_id, room_id, item_description, item_type, item_tier,
                found_location, snapshot_path, recovered_at, recovered_by,
                source, status, notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), ?, 'cctv_auto', 'recovered', ?)"
        )->execute([
            $detection_id,
            $det['room_id'],
            $description,
            $item_type,         // G7: item_type
            $item_tier,         // G7: item_tier
            $det['object_zone'],
            $det['snapshot_path'] ?? null,
            $actorId,           // recovered_by = the staff who marked it
            $notes ?: null,
        ]);

        // Notify students that an item was recovered (they can now claim it)
        try {
            _notifyRecovery($authPdo, $detection_id, $det['room_id'], $item_type, $actorId);
        } catch (Throwable $e) { /* non-critical */ }

    } catch (Throwable $e) {
        // Log but don't fail the status update
        error_log('[S.P.O.T.-IT] recovered_items insert failed for detection_id=' . $detection_id . ': ' . $e->getMessage());
    }
}

// ── If confirmed_missing: auto-create community forum thread ──────────────────
if ($new_status === 'confirmed_missing') {
    try {
        require_once __DIR__ . '/../services/community/db.php';
        $communityPdo = getCommunityDB();
        $threadTitle  = "⚠ Detection Alert — {$det['object_type']} possibly missing in {$det['room_id']}";
        $threadBody   =
            "The **S.P.O.T.-IT system** has detected a confirmed missing item in **{$det['room_id']}**.

" .
            "**Item:** {$det['object_type']}
" .
            "**Zone:** {$det['object_zone']}
" .
            "**Detected at:** {$det['detected_at']}
" .
            "**Status:** Confirmed Missing (60+ min elapsed)

" .
            "Lab staff have been notified. If you have information about this item, please reply below.";

        // Check no thread already exists for this detection
        $existsStmt = $communityPdo->prepare(
            "SELECT post_id FROM forum_posts WHERE detection_id = ? LIMIT 1"
        );
        $existsStmt->execute([$detection_id]);
        if (!$existsStmt->fetch()) {
            $communityPdo->prepare(
                "INSERT INTO forum_posts
                   (user_id, author_name, author_role, title, content, category,
                    detection_id, detection_room, detection_item,
                    is_auto_generated, upvotes, downvotes, comment_count, created_at)
                 VALUES (?, 'S.P.O.T.-IT System', 'admin', ?, ?, 'detection_thread',
                         ?, ?, ?, 1, 0, 0, 0, NOW())"
            )->execute([
                $actorId,
                $threadTitle,
                $threadBody,
                $detection_id,
                $det['room_id'],
                $det['object_type'] . ' — ' . $det['object_zone'],
            ]);
        }
    } catch (Throwable $e) { /* non-critical — detection update already succeeded */ }
}

// ── If confirmed_missing: notify admin escalation ─────────────────────────────
if ($new_status === 'confirmed_missing') {
    try {
        $stmt = $authPdo->prepare(
            "SELECT id FROM users WHERE role = 'admin' AND is_active = 1"
        );
        $stmt->execute();
        $admins = $stmt->fetchAll();
        $insert = $authPdo->prepare(
            "INSERT INTO notifications
               (user_id, type, title, body, detection_id, room_id, is_read, created_at)
             VALUES (?, 'confirmed_missing', ?, ?, ?, ?, 0, NOW())"
        );
        foreach ($admins as $admin) {
            $insert->execute([
                $admin['id'],
                "Staff confirmed: MISSING — {$det['object_zone']}",
                "{$actorName} confirmed Detection #{$detection_id} as missing in {$det['room_id']}.",
                $detection_id,
                $det['room_id'],
            ]);
        }
    } catch (Throwable $e) { /* non-critical */ }
}

ms_json([
    'success'      => true,
    'detection_id' => $detection_id,
    'new_status'   => $new_status,
    'verified_by'  => $actorId,
    'updated_at'   => date('Y-m-d H:i:s'),
]);

// ── Recovery notification helper ─────────────────────────────────────────────
function _notifyRecovery(PDO $authPdo, int $detId, string $roomId, string $itemType, int $staffId): void {
    $students = $authPdo->prepare(
        "SELECT id FROM users WHERE role = 'student' AND is_active = 1 LIMIT 200"
    );
    $students->execute();
    $insert = $authPdo->prepare(
        "INSERT INTO notifications
           (user_id, type, title, body, detection_id, room_id, is_read, created_at)
         VALUES (?, 'item_recovered', ?, ?, ?, ?, 0, NOW())"
    );
    foreach ($students->fetchAll() as $s) {
        $insert->execute([
            $s['id'],
            "Item Recovered — {$itemType}",
            "A {$itemType} has been recovered from {$roomId}. If this is yours, visit the Lost & Found thread to submit a claim.",
            $detId,
            $roomId,
        ]);
    }
}
