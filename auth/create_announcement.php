<?php
/**
 * S.P.O.T.-IT — Create Announcement Handler
 * auth/create_announcement.php
 *
 * POST endpoint. Admin-only. Inserts a new announcement into
 * spotit_community_db and dispatches 'new_announcement' notifications
 * to all active users.
 *
 * MICROSERVICES: Writes to spotit_community_db + spotit_auth_db (notifications).
 */
require_once __DIR__ . '/service_bootstrap.php';
require_once __DIR__ . '/../services/community/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

ms_require_auth('../pages/login.php');
ms_require_role('admin', '../pages/login.php');

$title    = trim($_POST['title']    ?? '');
$content  = trim($_POST['content']  ?? '');
$category = trim($_POST['category'] ?? 'general');
$pinned   = isset($_POST['pinned']) ? (int)(bool)$_POST['pinned'] : 0;

$allowed_categories = ['laboratory_advisory','lost_and_found','claiming_schedule','maintenance','system_update','general'];
if (!$title || !$content) {
    ms_json(['success' => false, 'message' => 'Title and content are required.']);
}
if (!in_array($category, $allowed_categories, true)) {
    $category = 'general';
}

$actorId   = (int)$_SESSION['user_id'];
$actorName = $_SESSION['user_name'] ?? 'Administrator';

$communityPdo = getCommunityDB();

// Insert announcement
try {
    $communityPdo->prepare(
        "INSERT INTO announcements
           (author_id, author_name, title, content, category, is_pinned, is_published, created_at)
         VALUES (?, ?, ?, ?, ?, ?, 1, NOW())"
    )->execute([$actorId, $actorName, $title, $content, $category, $pinned]);

    $annId = (int)$communityPdo->lastInsertId();

} catch (Throwable $e) {
    ms_json(['success' => false, 'message' => 'Failed to create announcement.', 'detail' => $e->getMessage()], 500);
}

// Dispatch 'new_announcement' notification to ALL active users
$notifiedCount = 0;
try {
    $allUsers = $authPdo->query(
        "SELECT id FROM users WHERE is_active = 1"
    )->fetchAll();
    $userIds = array_column($allUsers, 'id');

    $catLabels = [
        'laboratory_advisory' => 'Laboratory Advisory',
        'lost_and_found'      => 'Lost & Found',
        'claiming_schedule'   => 'Claiming Schedule',
        'maintenance'         => 'Maintenance',
        'system_update'       => 'System Update',
        'general'             => 'General',
    ];
    $catLabel = $catLabels[$category] ?? 'General';

    $notifiedCount = ms_notify(
        $authPdo,
        $userIds,
        'new_announcement',
        "New Announcement: {$title}",
        "[{$catLabel}] {$actorName} posted a new announcement. " . substr($content, 0, 120) . (strlen($content) > 120 ? '…' : ''),
        0,
        '',
        0,
        'pages/announcements.php'
    );
} catch (Throwable $e) {
    // Non-critical — announcement was already created
    error_log('[S.P.O.T.-IT] Notification dispatch failed for announcement #' . $annId . ': ' . $e->getMessage());
}

ms_json([
    'success'         => true,
    'announcement_id' => $annId,
    'notified_count'  => $notifiedCount,
    'pinned'          => (bool)$pinned,
    'created_at'      => date('Y-m-d H:i:s'),
]);
