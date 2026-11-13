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
// Single definition lives in auth/event_kinds.php (pure functions, no DB access).
// Loaded here because auth/ingest_detection.php and auth/escalate_detections.php
// include only this file, not the full service_bootstrap.php. A second copy here
// used to drift from the bootstrap one; require_once keeps exactly one.
require_once __DIR__ . '/../../auth/event_kinds.php';

/**
 * Admin dashboard KPI cards, with the scope of each number stated (WEB-14).
 *
 * The card values are unchanged in meaning where they were right, and scoped
 * where they were not:
 *   rooms          enabled rooms (is_active AND is_enabled)
 *   open           every unresolved event (pending/potential/confirmed_missing),
 *                  any room, any age — these are real records nobody has closed,
 *                  so they are still counted; the breakdown says where they are
 *   open_enabled   ...of those, in rooms currently in service
 *   open_stale     ...of those, older than 24 h
 *   review_open    events awaiting staff validation that are STILL OPEN. The card
 *                  used to count closed events too (41 of 47 on 2026-11-11 were
 *                  already recovered), so it read "awaiting review" for events the
 *                  detector had resolved
 *   review_closed  closed events nobody validated (shown as a note, not the number)
 *   today          events detected today
 */
function ms_dashboard_kpis(PDO $monitorPdo): array
{
    $open = "d.status IN ('pending','potential','confirmed_missing')";
    $review = "d.validation_status IN ('pending_review','needs_review')";
    $row = $monitorPdo->query("
        SELECT
          SUM($open)                                                       AS open_all,
          SUM($open AND r.is_enabled = 1 AND r.is_active = 1)              AS open_enabled,
          SUM($open AND d.detected_at < NOW() - INTERVAL 1 DAY)            AS open_stale,
          SUM($review AND $open)                                           AS review_open,
          SUM($review AND d.status IN ('recovered','dismissed'))           AS review_closed,
          SUM(DATE(d.detected_at) = CURDATE())                             AS today
        FROM detections d
        LEFT JOIN rooms r ON r.room_id = d.room_id
        WHERE d.is_removed = 0
    ")->fetch() ?: [];
    $rooms = (int) $monitorPdo->query(
        "SELECT COUNT(*) FROM rooms WHERE is_active = 1 AND is_enabled = 1"
    )->fetchColumn();
    return [
        'rooms'         => $rooms,
        'open'          => (int) ($row['open_all'] ?? 0),
        'open_enabled'  => (int) ($row['open_enabled'] ?? 0),
        'open_stale'    => (int) ($row['open_stale'] ?? 0),
        'review_open'   => (int) ($row['review_open'] ?? 0),
        'review_closed' => (int) ($row['review_closed'] ?? 0),
        'today'         => (int) ($row['today'] ?? 0),
    ];
}

/**
 * Per-room count of unresolved CCTV alerts — WEB-05 (Option A): the Admin
 * Dashboard's Room Status table shows this as "Open Alerts" instead of the
 * ambiguous "Deviation" column, and links straight to this same set on
 * alerts.php?scope=cctv&room=<id>. Same status list as ms_dashboard_kpis()'s
 * $open, kept here instead of re-typing it, specifically so the two numbers
 * cannot drift apart: pending/potential/confirmed_missing are "unresolved",
 * recovered/dismissed are not, regardless of which page is asking.
 *
 * @return array<string,int> room_id => open alert count (rooms with zero are
 *                            simply absent — callers should default to 0)
 */
function ms_open_alert_counts_by_room(PDO $monitorPdo): array
{
    $rows = $monitorPdo->query("
        SELECT room_id, COUNT(*) AS n
        FROM detections
        WHERE status IN ('pending','potential','confirmed_missing') AND is_removed = 0
        GROUP BY room_id
    ")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[$r['room_id']] = (int) $r['n'];
    }
    return $out;
}
