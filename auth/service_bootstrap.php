<?php
/**
 * S.P.O.T.-IT — Service Bootstrap
 * ─────────────────────────────────────────────────────────────────────────────
 * Loads all 4 microservice DB connectors and provides cross-service helpers.
 * Include this file at the top of every auth/ handler that needs multiple DBs.
 *
 * RULE: Pages never use this. Only auth/ handlers use this.
 * Pages call auth/ endpoints via fetch(). Auth/ handlers use this bootstrap.
 */

require_once __DIR__ . '/../services/auth/db.php';
require_once __DIR__ . '/../services/monitoring/db.php';
require_once __DIR__ . '/../services/lostfound/db.php';
require_once __DIR__ . '/../services/user/db.php';

// Instantiate once — singletons via static
$authPdo    = getAuthDB();
$monitorPdo = getMonitorDB();
$lfPdo      = getLostFoundDB();
$userPdo    = getUserDB();

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
    foreach ($stmt->fetchAll() as $row) {
        $out[(int)$row['id']] = $row;
    }
    return $out;
}

// ── Rate-limiting helpers (auth DB) ───────────────────────────────────────────

function ms_get_login_attempts(PDO $authPdo, string $identifier): array {
    $stmt = $authPdo->prepare(
        "SELECT attempt_count, locked_until
         FROM login_attempts
         WHERE identifier = ? LIMIT 1"
    );
    $stmt->execute([$identifier]);
    return $stmt->fetch() ?: ['attempt_count' => 0, 'locked_until' => null];
}

function ms_record_failed_attempt(PDO $authPdo, string $identifier): array {
    // Upsert attempt count
    $authPdo->prepare(
        "INSERT INTO login_attempts (identifier, attempt_count, last_attempt)
         VALUES (?, 1, NOW())
         ON DUPLICATE KEY UPDATE
           attempt_count = attempt_count + 1,
           last_attempt  = NOW()"
    )->execute([$identifier]);

    $row   = ms_get_login_attempts($authPdo, $identifier);
    $count = (int)$row['attempt_count'];

    $lockedUntil = null;
    if ($count >= RATE_LIMIT_LOCKOUT) {
        $lockedUntil = date('Y-m-d H:i:s', time() + COOLDOWN_LONG);
        $authPdo->prepare(
            "UPDATE login_attempts SET locked_until = ? WHERE identifier = ?"
        )->execute([$lockedUntil, $identifier]);
    } elseif ($count >= RATE_LIMIT_WARN) {
        $lockedUntil = date('Y-m-d H:i:s', time() + COOLDOWN_SHORT);
        $authPdo->prepare(
            "UPDATE login_attempts SET locked_until = ? WHERE identifier = ?"
        )->execute([$lockedUntil, $identifier]);
    }

    return ['attempt_count' => $count, 'locked_until' => $lockedUntil];
}

function ms_clear_attempts(PDO $authPdo, string $identifier): void {
    $authPdo->prepare(
        "DELETE FROM login_attempts WHERE identifier = ?"
    )->execute([$identifier]);
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
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['full_name'];
    $_SESSION['user_email']= $user['email'];
    session_regenerate_id(true);
}

function ms_require_auth(string $redirect = '../pages/login.php'): void {
    if (empty($_SESSION['user_id'])) {
        header("Location: {$redirect}");
        exit();
    }
}

function ms_require_role(string $role, string $redirect = '../pages/login.php'): void {
    ms_require_auth($redirect);
    if (($_SESSION['user_role'] ?? '') !== $role) {
        header("Location: {$redirect}?error=unauthorized");
        exit();
    }
}

// ── JSON response helper ──────────────────────────────────────────────────────

function ms_json(array $data, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit();
}

// ── Detection timer stage helper ──────────────────────────────────────────────

function ms_detection_stage(string $detectedAt): array {
    $mins = (time() - strtotime($detectedAt)) / 60;
    if ($mins >= TIMER_CONFIRMED_MIN) {
        return ['stage' => 'confirmed', 'label' => 'Confirmed Missing', 'mins' => (int)$mins];
    }
    if ($mins >= TIMER_POTENTIAL_MIN) {
        return ['stage' => 'potential', 'label' => 'Potentially Lost',  'mins' => (int)$mins];
    }
    return ['stage' => 'detected',  'label' => 'Detected',            'mins' => (int)$mins];
}
