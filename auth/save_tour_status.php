<?php
/**
 * S.P.O.T.-IT — Save Tour Status Handler
 * auth/save_tour_status.php
 *
 * POST endpoint. Persists onboarding tour completion (or reset) per user+role.
 * MICROSERVICES: Writes to spotit_auth_db only.
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

ms_require_auth('../pages/login.php');

$userId    = (int)$_SESSION['user_id'];
$role      = $_SESSION['user_role'] ?? 'student';
$completed = isset($_POST['completed']) ? (int)!!$_POST['completed'] : 1;

try {
    $authPdo->prepare(
        "INSERT INTO tour_status (user_id, role, completed, completed_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE
           completed    = VALUES(completed),
           completed_at = VALUES(completed_at)"
    )->execute([$userId, $role, $completed]);

    ms_json(['success' => true, 'completed' => (bool)$completed]);
} catch (Throwable $e) {
    ms_json(['success' => false, 'message' => 'Failed to save tour status.'], 500);
}
