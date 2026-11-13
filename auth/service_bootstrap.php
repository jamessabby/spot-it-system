<?php
/**
 * S.P.O.T.-IT — Service Bootstrap  [UPDATED — workflow audit fixes]
 * auth/service_bootstrap.php
 *
 * Loads all 5 microservice DB connectors and provides cross-service helpers.
 * Include at the top of every auth/ handler that needs multiple DBs.
 *
 * RULE: Pages never use this. Only auth/ handlers use this.
 *
 * CHANGES (workflow audit):
 *   - Added getCommunityDB() for announcements/forum handlers.
 *   - ms_detection_stage() now accepts an optional $db_status parameter.
 *     When provided (i.e. the DB status has been written by escalate_detections.php),
 *     it returns that persisted value instead of recalculating from elapsed time.
 *     This ensures display is always consistent with the actual persisted state.
 */

require_once __DIR__ . '/../services/auth/db.php';
require_once __DIR__ . '/../services/monitoring/db.php';
require_once __DIR__ . '/../services/lostfound/db.php';
require_once __DIR__ . '/../services/user/db.php';
require_once __DIR__ . '/../services/community/db.php';
require_once __DIR__ . '/event_kinds.php';     // registered equipment vs. unregistered items
require_once __DIR__ . '/detector_state.php';  // detector_health() / ms_room_connectivity_status()

// Instantiate once — singletons via static
$authPdo      = getAuthDB();
$monitorPdo   = getMonitorDB();
$lfPdo        = getLostFoundDB();
$userPdo      = getUserDB();
$communityPdo = getCommunityDB();

// ── Cross-service user helpers ────────────────────────────────────────────────

function ms_get_user(PDO $authPdo, int $userId): ?array {
    $stmt = $authPdo->prepare(
        "SELECT id, full_name, email, role, avatar, is_active, created_at
         FROM users WHERE id = ? AND is_active = 1 LIMIT 1"
    );
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

function ms_get_users_by_ids(PDO $authPdo, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids) return [];
    $ph   = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $authPdo->prepare(
        "SELECT id, full_name, email, role, avatar FROM users WHERE id IN ({$ph})"
    );
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll() as $row) $out[(int)$row['id']] = $row;
    return $out;
}

// ── Rate-limiting helpers ─────────────────────────────────────────────────────

function ms_get_login_attempts(PDO $authPdo, string $identifier): array {
    $stmt = $authPdo->prepare(
        "SELECT attempt_count, locked_until FROM login_attempts WHERE identifier = ? LIMIT 1"
    );
    $stmt->execute([$identifier]);
    return $stmt->fetch() ?: ['attempt_count' => 0, 'locked_until' => null];
}

function ms_record_failed_attempt(PDO $authPdo, string $identifier): array {
    $authPdo->prepare(
        "INSERT INTO login_attempts (identifier, attempt_count, last_attempt)
         VALUES (?, 1, NOW())
         ON DUPLICATE KEY UPDATE attempt_count = attempt_count + 1, last_attempt = NOW()"
    )->execute([$identifier]);

    $row   = ms_get_login_attempts($authPdo, $identifier);
    $count = (int)$row['attempt_count'];
    $lockedUntil = null;

    if ($count >= RATE_LIMIT_LOCKOUT) {
        $lockedUntil = date('Y-m-d H:i:s', time() + COOLDOWN_LONG);
    } elseif ($count >= RATE_LIMIT_WARN) {
        $lockedUntil = date('Y-m-d H:i:s', time() + COOLDOWN_SHORT);
    }

    if ($lockedUntil) {
        $authPdo->prepare(
            "UPDATE login_attempts SET locked_until = ? WHERE identifier = ?"
        )->execute([$lockedUntil, $identifier]);
    }

    return ['attempt_count' => $count, 'locked_until' => $lockedUntil];
}

function ms_clear_attempts(PDO $authPdo, string $identifier): void {
    $authPdo->prepare("DELETE FROM login_attempts WHERE identifier = ?")->execute([$identifier]);
}

function ms_is_locked(PDO $authPdo, string $identifier): array {
    $row = ms_get_login_attempts($authPdo, $identifier);
    if (!$row['locked_until']) return ['locked' => false, 'seconds_left' => 0];
    $secsLeft = strtotime($row['locked_until']) - time();
    if ($secsLeft <= 0) {
        ms_clear_attempts($authPdo, $identifier);
        return ['locked' => false, 'seconds_left' => 0];
    }
    return ['locked' => true, 'seconds_left' => $secsLeft, 'count' => (int)$row['attempt_count']];
}

// ── Room camera exclusivity ───────────────────────────────────────────────────
/**
 * One camera, one active room.
 *
 * There is a single physical Tapo on the project, but several candidate rooms
 * are registered for testing, so the same camera_ip kept getting pasted into
 * two rooms at once. Two rooms pointing at one camera means two detector
 * sessions competing for the same RTSP stream — and the Tapo only allows a
 * couple of concurrent clients, so the loser silently drops to /stream2 at
 * 640x360 (CLAUDE.md §9) rather than failing loudly. Rejecting the duplicate
 * up front is the only place this is cheap to catch.
 *
 * A DISABLED room does not hold a claim: disabling is how staff free a camera
 * for reassignment without deleting the room's baseline, registered items or
 * detection history. Soft-deleted rooms (is_active = 0) do not hold one either.
 *
 * @return array|null  The conflicting room row (room_id, room_name), or null if free.
 */
function ms_camera_ip_conflict(PDO $monitorPdo, string $cameraIp, string $exceptRoomId = ''): ?array {
    $cameraIp = trim($cameraIp);
    if ($cameraIp === '') return null;   // no camera assigned = nothing to conflict with

    $stmt = $monitorPdo->prepare(
        "SELECT room_id, room_name FROM rooms
         WHERE camera_ip = ? AND room_id != ? AND is_active = 1 AND is_enabled = 1
         LIMIT 1"
    );
    $stmt->execute([$cameraIp, strtoupper(trim($exceptRoomId))]);
    return $stmt->fetch() ?: null;
}

// ── DLSU-D domain check ───────────────────────────────────────────────────────

function ms_is_dlsud_email(string $email): bool {
    return str_ends_with(strtolower(trim($email)), ALLOWED_DOMAIN);
}

// ── Session helpers ───────────────────────────────────────────────────────────

function ms_set_session(array $user): void {
    $_SESSION['user_id']    = $user['id'];
    $_SESSION['user_role']  = $user['role'];
    $_SESSION['user_name']  = $user['full_name'];
    $_SESSION['user_email'] = $user['email'];
    session_regenerate_id(true);
}

function ms_require_auth(string $redirect = '../pages/login.php'): void {
    if (empty($_SESSION['user_id'])) { header("Location: {$redirect}"); exit(); }
}

function ms_require_role(string $role, string $redirect = '../pages/login.php'): void {
    ms_require_auth($redirect);
    if (($_SESSION['user_role'] ?? '') !== $role) {
        header("Location: {$redirect}?error=unauthorized"); exit();
    }
}

// ── JSON response ─────────────────────────────────────────────────────────────

function ms_json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

// ── Detection stage helper ──────────────────────────────────────────────────────
// ms_detection_stage() now lives in auth/event_kinds.php (loaded above) so it can be
// unit-tested and so it never labels a recovered/dismissed event or a timer
// escalation as "Confirmed Missing".

// ── Snapshot evidence URLs ─────────────────────────────────────────────────────
// Evidence files are no longer fetched straight from uploads/snapshots/ (denied
// in uploads/.htaccess); every page gets its image through auth/snapshot.php.
function ms_snapshot_url(?string $path): ?string
{
    $name = $path ? basename($path) : '';
    return $name !== '' ? SITE_URL . '/auth/snapshot.php?f=' . rawurlencode($name) : null;
}

// File facts for the evidence viewer. The detector reuses the same file name
// across events ({label}_registered_N.jpg), so a row's file can have been
// rewritten by a later event; `written_at` lets the UI say so instead of
// presenting a later frame as this event's evidence.
function ms_snapshot_info(?string $path, ?string $eventTime = null): array
{
    $name = $path ? basename($path) : '';
    if ($name === '') {
        return ['url' => null, 'exists' => false, 'written_at' => null, 'rewritten' => false];
    }
    $file = SNAPSHOT_PATH . $name;
    $exists = is_file($file);
    $mtime = $exists ? filemtime($file) : null;
    $rewritten = $exists && $eventTime && abs($mtime - strtotime($eventTime)) > 120;
    return [
        'url'        => ms_snapshot_url($name),
        'exists'     => $exists,
        'written_at' => $mtime ? date('Y-m-d H:i:s', $mtime) : null,
        'rewritten'  => $rewritten,
    ];
}

// ── Current state of the events notifications point at ────────────────────────
// A notification is written once, at ingest ("New Deviation Detected"), and never
// rewritten. Without this read the bell kept saying "new deviation" for events staff
// had long since recovered or dismissed. Cross-service read (auth notifications ->
// monitor detections), so it lives here per the microservice rule.
// Returns [detection_id => ['status', 'status_label', 'closed', 'object_zone']].
function ms_detection_status_map(PDO $monitorPdo, array $detectionIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $detectionIds))));
    if (!$ids) return [];
    $in = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $monitorPdo->prepare(
        "SELECT detection_id, status, object_zone, verified_by
           FROM detections WHERE detection_id IN ({$in})"
    );
    $stmt->execute($ids);
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $status = (string) $r['status'];
        $out[(int) $r['detection_id']] = [
            'status'       => $status,
            'status_label' => ms_status_label($r),
            'closed'       => in_array($status, ['recovered', 'dismissed'], true),
            'object_zone'  => (string) $r['object_zone'],
        ];
    }
    return $out;
}

// ── Shared notification helper (used across multiple handlers) ────────────────
function ms_notify(
    PDO    $authPdo,
    array  $userIds,
    string $type,
    string $title,
    string $body,
    int    $detectionId = 0,
    string $roomId      = '',
    int    $claimId     = 0,
    string $actionUrl   = ''
): int {
    if (!$userIds) return 0;
    $stmt = $authPdo->prepare(
        "INSERT INTO notifications
           (user_id, type, title, body,
            detection_id, room_id, claim_id, action_url,
            is_read, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())"
    );
    $count = 0;
    foreach ($userIds as $uid) {
        try {
            $stmt->execute([
                (int)$uid, $type, $title, $body,
                $detectionId ?: null,
                $roomId      ?: null,
                $claimId     ?: null,
                $actionUrl   ?: null,
            ]);
            $count++;
        } catch (Throwable $e) { /* skip duplicates / constraint errors */ }
    }
    return $count;
}
