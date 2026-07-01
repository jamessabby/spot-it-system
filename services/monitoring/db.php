<?php
/**
 * S.P.O.T.-IT — Monitoring Service DB
 * Database: spotit_monitor_db
 * Tables:   rooms, registered_lab_items, detections, monitoring_logs
 *
 * MICROSERVICES: This file only connects to spotit_monitor_db.
 *
 * Also houses ms_detection_stage() so that ingest_detection.php
 * (a sessionless, Python-facing API endpoint) can compute the stage
 * label without pulling in the full service_bootstrap.php.
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

// ── Detection timer stage helper ──────────────────────────────────────────────
// Kept here (rather than only in service_bootstrap.php) so that
// ingest_detection.php can use it without a session or the full bootstrap.
// service_bootstrap.php still re-declares it; PHP's function_exists guard
// prevents a fatal duplicate-function error if both files happen to be loaded
// in the same request (e.g. during integrated testing).
if (!function_exists('ms_detection_stage')) {
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
}
