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

// ── Detection timer stage helper ──────────────────────────────────────────────
// Kept here (rather than only in auth/service_bootstrap.php) so that
// auth/ingest_detection.php and auth/escalate_detections.php — both of which
// only include this file, not the full service_bootstrap.php — can call
// ms_detection_stage() without a fatal "undefined function" error.
// service_bootstrap.php still re-declares it; the function_exists guard here
// prevents a duplicate-declaration fatal if both files are loaded in the same
// request. Signature matches auth/service_bootstrap.php's version exactly
// (optional $dbStatus param — see that file's docblock for the rationale).
if (!function_exists('ms_detection_stage')) {
    function ms_detection_stage(string $detectedAt, ?string $dbStatus = null): array {
        $mins = (time() - strtotime($detectedAt)) / 60;

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

        if ($mins >= TIMER_CONFIRMED_MIN) {
            return ['stage' => 'confirmed', 'label' => 'Confirmed Missing', 'mins' => (int)$mins];
        }
        if ($mins >= TIMER_POTENTIAL_MIN) {
            return ['stage' => 'potential', 'label' => 'Potentially Lost', 'mins' => (int)$mins];
        }
        return ['stage' => 'detected', 'label' => 'Detected', 'mins' => (int)$mins];
    }
}
