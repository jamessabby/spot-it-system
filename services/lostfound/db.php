<?php
/**
 * S.P.O.T.-IT — Lost & Found Service DB
 * Database: spotit_lf_db
 * Tables:   recovered_items, surrender_logs, claims
 *
 * MICROSERVICES: This file only connects to spotit_lf_db.
 */
require_once __DIR__ . '/../../config/env.php';

function getLostFoundDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . LF_DB_NAME . ";charset=utf8mb4",
                LF_DB_USER,
                LF_DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
            $pdo->exec("SET time_zone = '+08:00'");
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Lost & Found DB connection failed']);
            exit();
        }
    }
    return $pdo;
}
