<?php
/**
 * S.P.O.T.-IT — Logout Handler
 * auth/logout.php
 *
 * Destroys the session and redirects to login page.
 * MICROSERVICES: Reads/writes spotit_auth_db for session invalidation only.
 */
require_once __DIR__ . '/../config/env.php';

// Invalidate any persistent session token in DB (if implemented)
if (!empty($_SESSION['user_id'])) {
    try {
        require_once __DIR__ . '/../services/auth/db.php';
        $pdo = getAuthDB();
        // Delete server-side session record if you store them
        $pdo->prepare("DELETE FROM sessions WHERE user_id = ?")
            ->execute([$_SESSION['user_id']]);
    } catch (Throwable $e) {
        // Non-critical
    }
}

// Destroy session completely
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(), '', time() - 42000,
        $params['path'], $params['domain'],
        $params['secure'], $params['httponly']
    );
}
session_destroy();

header('Location: ../pages/login.php?logged_out=1');
exit();
