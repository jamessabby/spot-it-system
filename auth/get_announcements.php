<?php
/**
 * S.P.O.T.-IT — Get Announcements Handler
 * auth/get_announcements.php
 *
 * GET params:
 *   unread    flag   return unread_count only (for sidebar badge)
 *   category  string filter by category
 *   limit     int    default 20, max 100
 *   offset    int    default 0
 *
 * MICROSERVICES: Reads from spotit_community_db + spotit_auth_db (last_viewed).
 */
require_once __DIR__ . '/service_bootstrap.php';
require_once __DIR__ . '/../services/community/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

ms_require_auth('../pages/login.php');

$communityPdo = getCommunityDB();
$userId       = (int)$_SESSION['user_id'];
$unreadOnly   = isset($_GET['unread']);
$category     = trim($_GET['category'] ?? '');
$limit        = min((int)($_GET['limit']  ?? 20), 100);
$offset       = (int)($_GET['offset'] ?? 0);

// ── Unread count for sidebar badge ────────────────────────────────────────────
if ($unreadOnly) {
    try {
        $lastViewStmt = $authPdo->prepare(
            "SELECT last_viewed_announcements FROM user_activity WHERE user_id = ? LIMIT 1"
        );
        $lastViewStmt->execute([$userId]);
        $lastView = $lastViewStmt->fetchColumn() ?: date('Y-m-d H:i:s', strtotime('-7 days'));

        $cStmt = $communityPdo->prepare(
            "SELECT COUNT(*) FROM announcements WHERE created_at > ? AND is_published = 1"
        );
        $cStmt->execute([$lastView]);
        $unreadCount = (int)$cStmt->fetchColumn();

        ms_json(['success' => true, 'unread_count' => $unreadCount]);

    } catch (Throwable $e) {
        ms_json(['success' => true, 'unread_count' => 0]);
    }
}

// ── Full announcements list ───────────────────────────────────────────────────
try {
    $where  = ['is_published = 1'];
    $params = [];

    if ($category) {
        $where[]  = 'category = ?';
        $params[] = $category;
    }
    $whereSQL = implode(' AND ', $where);

    $stmt = $communityPdo->prepare(
        "SELECT announcement_id, author_name, title, content,
                category, is_pinned, view_count, created_at, updated_at
         FROM announcements
         WHERE {$whereSQL}
         ORDER BY is_pinned DESC, created_at DESC
         LIMIT ? OFFSET ?"
    );
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $announcements = $stmt->fetchAll();

    $countStmt = $communityPdo->prepare("SELECT COUNT(*) FROM announcements WHERE {$whereSQL}");
    $countStmt->execute(array_slice($params, 0, -2));
    $total = (int)$countStmt->fetchColumn();

    // Update last_viewed_announcements for this user
    try {
        $authPdo->prepare(
            "INSERT INTO user_activity (user_id, last_viewed_announcements, updated_at)
             VALUES (?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE last_viewed_announcements = NOW(), updated_at = NOW()"
        )->execute([$userId]);
    } catch (Throwable $e) { /* non-critical */ }

    ms_json([
        'success'       => true,
        'total'         => $total,
        'count'         => count($announcements),
        'announcements' => $announcements,
        'fetched_at'    => date('Y-m-d H:i:s'),
    ]);

} catch (Throwable $e) {
    ms_json(['success' => false, 'message' => 'Failed to fetch announcements.', 'detail' => $e->getMessage()], 500);
}
