<?php
/**
 * S.P.O.T.-IT — Auth Service DB
 * Database: spotit_auth_db
 * Tables:   users, login_attempts, sessions, microsoft_tokens
 *
 * MICROSERVICES: This file only connects to spotit_auth_db.
 * Do NOT add other database connections here.
 */
require_once __DIR__ . '/../../config/env.php';

function getAuthDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . AUTH_DB_NAME . ";charset=utf8mb4",
                AUTH_DB_USER,
                AUTH_DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
            $pdo->exec("SET time_zone = '+08:00'");
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Auth DB connection failed', 'detail' => $e->getMessage()]);
            exit();
        }
    }
    return $pdo;
}
