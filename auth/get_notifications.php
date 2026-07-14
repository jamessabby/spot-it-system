<?php
/**
 * S.P.O.T.-IT — Get Notifications
 * auth/get_notifications.php
 *
 * GET params:
 *   limit       int  (default 30, max 100)
 *   offset      int  (default 0, for pagination)
 *   unread      flag (if present, return unread_count only — for badge polling)
 *   type        str  (filter by notification type)
 *
 * Returns full notification objects + unread_count for the current user.
 *
 * MICROSERVICES: Reads from spotit_auth_db.notifications only.
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

ms_require_auth('../pages/login.php');

$userId      = (int)$_SESSION['user_id'];
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
    $where[]  = 'type = ?';
    $params[] = $typeFilter;
}

$whereSQL = implode(' AND ', $where);

try {
    $stmt = $authPdo->prepare(
        "SELECT notification_id, type, title, body,
                detection_id, room_id, claim_id,
                action_url, is_read, read_at, created_at
         FROM notifications
         WHERE {$whereSQL}
         ORDER BY created_at DESC
         LIMIT ? OFFSET ?"
    );
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $notifications = $stmt->fetchAll();

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
