<?php
/**
 * S.P.O.T.-IT — Monitoring Service DB
 * Database: spotit_monitor_db
 * Tables:   rooms, registered_lab_items, detections, monitoring_logs
 *
 * MICROSERVICES: This file only connects to spotit_monitor_db.
 */
require_once __DIR__ . '/../../config/env.php';

function getMonitorDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                "mysql:host=" . DB_HOST . ";dbname=" . MONITOR_DB_NAME . ";charset=utf8mb4",
                MONITOR_DB_USER,
                MONITOR_DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]
            );
            $pdo->exec("SET time_zone = '+08:00'");
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Monitor DB connection failed']);
            exit();
        }
    }
    return $pdo;
}
