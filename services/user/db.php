<?php
/**
 * S.P.O.T.-IT — User Service DB
 * Database: spotit_user_db
 * Tables:   user_profiles, user_settings
 *
 * MICROSERVICES: This file only connects to spotit_user_db.
 */
require_once __DIR__ . '/../../config/env.php';

function getUserDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . USER_DB_NAME . ";charset=utf8mb4",
                USER_DB_USER,
                USER_DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
            $pdo->exec("SET time_zone = '+08:00'");
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'User DB connection failed']);
            exit();
        }
    }
    return $pdo;
}
