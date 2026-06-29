<?php
/**
 * S.P.O.T.-IT — Get Tour Status Handler
 * auth/get_tour_status.php
 *
 * GET endpoint. Returns whether the current user has completed
 * the first-time onboarding tour for their role.
 * MICROSERVICES: Reads from spotit_auth_db only.
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (empty($_SESSION['user_id'])) {
    // Not logged in — treat as "completed" so no tour shows on public pages
    ms_json(['completed' => true]);
}

$userId = (int)$_SESSION['user_id'];
$role   = $_SESSION['user_role'] ?? 'student';

try {
    $stmt = $authPdo->prepare(
        "SELECT completed FROM tour_status WHERE user_id = ? AND role = ? LIMIT 1"
    );
    $stmt->execute([$userId, $role]);
    $row = $stmt->fetch();

    ms_json(['completed' => $row ? (bool)$row['completed'] : false]);
} catch (Throwable $e) {
    // If table doesn't exist yet or query fails, fail safe to "not completed"
    ms_json(['completed' => false]);
}
