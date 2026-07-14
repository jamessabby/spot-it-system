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

// ── Detection stage helper  [UPDATED — uses persisted DB status when available] ──
/**
 * Returns stage metadata for a detection event.
 *
 * @param string      $detectedAt   Datetime string when the detection was first recorded.
 * @param string|null $dbStatus     The persisted status from the DB (set by escalate_detections.php).
 *                                  When provided, we trust the DB over recalculating from elapsed time.
 *                                  This fixes the inconsistency where the dashboard showed a different
 *                                  stage than what was actually stored in the database.
 */
if (!function_exists('ms_detection_stage')) {
    function ms_detection_stage(string $detectedAt, ?string $dbStatus = null): array {
        $mins = (time() - strtotime($detectedAt)) / 60;

        // If escalate_detections.php has already written the status, trust it
        if ($dbStatus === 'confirmed_missing') {
            return ['stage' => 'confirmed', 'label' => 'Confirmed Missing', 'mins' => (int)$mins];
        }
        if ($dbStatus === 'potential') {
            return ['stage' => 'potential', 'label' => 'Potentially Lost', 'mins' => (int)$mins];
        }
        if ($dbStatus === 'dismissed') {
            return ['stage' => 'dismissed', 'label' => 'Dismissed', 'mins' => (int)$mins];
        }
        if ($dbStatus === 'recovered') {
            return ['stage' => 'recovered', 'label' => 'Recovered', 'mins' => (int)$mins];
        }

        // Fallback: calculate from elapsed time (for 'pending' or when cron hasn't run yet)
        if ($mins >= TIMER_CONFIRMED_MIN) {
            return ['stage' => 'confirmed', 'label' => 'Confirmed Missing', 'mins' => (int)$mins];
        }
        if ($mins >= TIMER_POTENTIAL_MIN) {
            return ['stage' => 'potential', 'label' => 'Potentially Lost', 'mins' => (int)$mins];
        }
        return ['stage' => 'detected', 'label' => 'Detected', 'mins' => (int)$mins];
    }
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
