<?php
/**
 * S.P.O.T.-IT — Get Notifications
 * auth/get_notifications.php
 *
 * GET params:
 *   limit       int  (default 30, max 100)
 *   offset      int  (default 0, for pagination)
 *   unread      flag (if present, return unread_count only — for badge polling)
 *   type        str  (filter by notification type; a comma list = any of them,
 *                    which is what the notifications page's category pills send)
 *
 * Returns full notification objects + unread_count for the current user.
 * Notifications about a detection also carry that event's CURRENT state
 * (event_status, event_status_label, event_closed, event_missing) and a `link`
 * that opens that specific event, because the stored title is written once at
 * ingest and goes stale as soon as staff recover or dismiss the event.
 *
 * MICROSERVICES: Reads spotit_auth_db.notifications; the detection state comes
 * from the monitor DB through ms_detection_status_map() in service_bootstrap.php.
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

ms_require_auth('../pages/login.php');

$userId      = (int)$_SESSION['user_id'];
$userRole    = (string)($_SESSION['user_role'] ?? '');
session_write_close();
$limit       = min((int)($_GET['limit']  ?? 30), 100);
$offset      = (int)($_GET['offset'] ?? 0);
$typeFilter  = trim($_GET['type'] ?? '');
$badgeOnly   = isset($_GET['unread']);

// ── Unread count (badge polling mode) ────────────────────────────────────────
$countStmt = $authPdo->prepare(
    "SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0"
);
$countStmt->execute([$userId]);
$unreadCount = (int)$countStmt->fetchColumn();

if ($badgeOnly) {
    ms_json(['success' => true, 'unread_count' => $unreadCount]);
}

// ── Full list ─────────────────────────────────────────────────────────────────
$where  = ['user_id = ?'];
$params = [$userId];

if ($typeFilter) {
    // Was `type = ?` with the whole comma list as one value, so every category
    // pill on the notifications page (Detection, Claims, ...) came back empty.
    $types = array_values(array_unique(array_filter(
        array_map('trim', explode(',', $typeFilter)),
        fn($t) => preg_match('/^[a-z_]{1,40}$/', $t)
    )));
    if ($types) {
        $where[] = 'type IN (' . implode(',', array_fill(0, count($types), '?')) . ')';
        array_push($params, ...$types);
    }
}

$whereSQL = implode(' AND ', $where);

try {
    $stmt = $authPdo->prepare(
        "SELECT notification_id, type, title, body,
                detection_id, room_id, claim_id,
                action_url, is_read, read_at, created_at
         FROM notifications
         WHERE {$whereSQL}
         ORDER BY created_at DESC, notification_id DESC
         LIMIT ? OFFSET ?"
    );
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $notifications = $stmt->fetchAll();

    // Current state of the events these notifications point at.
    $detIds  = array_filter(array_column($notifications, 'detection_id'));
    $states  = null;
    try {
        $states = ms_detection_status_map($monitorPdo, $detIds);
    } catch (Throwable $ignored) { /* monitor DB down: fall back to the stored text */ }
    $isStaff = in_array($userRole, ['admin', 'staff'], true);
    foreach ($notifications as &$n) {
        $did = (int) ($n['detection_id'] ?? 0);
        $n['link'] = $n['action_url'] ?: null;
        if (!$did || $states === null) {
            continue;
        }
        $st = $states[$did] ?? null;
        $n['event_missing']      = $st === null;
        $n['event_status']       = $st['status'] ?? null;
        $n['event_status_label'] = $st['status_label'] ?? 'Event no longer on record';
        $n['event_closed']       = (bool) ($st['closed'] ?? false);
        // Staff land on the dashboard with that event's pop-up open.
        if ($isStaff && $st !== null && !$n['link']) {
            $n['link'] = 'dashboard-admin.php?event=' . $did;
        }
    }
    unset($n);

    // Total count for pagination
    $totalStmt = $authPdo->prepare(
        "SELECT COUNT(*) FROM notifications WHERE {$whereSQL}"
    );
    $totalStmt->execute(array_slice($params, 0, -2));
    $total = (int)$totalStmt->fetchColumn();

    ms_json([
        'success'       => true,
        'unread_count'  => $unreadCount,
        'total'         => $total,
        'count'         => count($notifications),
        'notifications' => $notifications,
        'fetched_at'    => date('Y-m-d H:i:s'),
    ]);

} catch (Throwable $e) {
    ms_json([
        'success'       => false,
        'message'       => 'Failed to fetch notifications.',
        'detail'        => $e->getMessage(),
        'unread_count'  => $unreadCount,
        'notifications' => [],
    ], 500);
}
