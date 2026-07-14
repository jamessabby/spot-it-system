<?php
/**
 * S.P.O.T.-IT — Forum: Submit Post
 * auth/forum_submit_post.php
 *
 * POST body:
 *   title           string  required
 *   content         string  required
 *   category        string  required (lost_and_found|found_item|general|question|detection_thread)
 *   flair           string  optional
 *   detection_id    int     optional — links to a detection event
 *   detection_room  string  optional (denormalized from detection)
 *   detection_item  string  optional (denormalized from detection)
 *
 * MICROSERVICES: Writes to spotit_community_db.forum_posts.
 */
require_once __DIR__ . '/service_bootstrap.php';
require_once __DIR__ . '/../services/community/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

ms_require_auth('../pages/login.php');

$communityPdo = getCommunityDB();
$userId   = (int)$_SESSION['user_id'];
$userRole = $_SESSION['user_role'] ?? 'student';
$userName = $_SESSION['user_name'] ?? 'User';

$title        = trim($_POST['title']          ?? '');
$content      = trim($_POST['content']        ?? '');
$category     = trim($_POST['category']       ?? 'general');
$flair        = trim($_POST['flair']          ?? '') ?: null;
$detectionId  = (int)($_POST['detection_id']  ?? 0)  ?: null;
$detRoom      = trim($_POST['detection_room'] ?? '') ?: null;
$detItem      = trim($_POST['detection_item'] ?? '') ?: null;
$isAuto       = isset($_POST['is_auto_generated']) ? 1 : 0;

$allowed = ['lost_and_found','found_item','general','question','detection_thread'];
if (!$title || !$content) {
    ms_json(['success' => false, 'message' => 'Title and content are required.']);
}
if (!in_array($category, $allowed, true)) {
    $category = 'general';
}
if (mb_strlen($title) > 300) {
    ms_json(['success' => false, 'message' => 'Title exceeds 300 characters.']);
}

try {
    $communityPdo->prepare(
        "INSERT INTO forum_posts
           (user_id, author_name, author_role, title, content, category, flair,
            detection_id, detection_room, detection_item, is_auto_generated,
            upvotes, downvotes, comment_count, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0, 0, NOW())"
    )->execute([
        $userId, $userName, $userRole,
        $title, $content, $category, $flair,
        $detectionId, $detRoom, $detItem, $isAuto,
    ]);
    $postId = (int)$communityPdo->lastInsertId();

    ms_json([
        'success'  => true,
        'post_id'  => $postId,
        'redirect' => 'forum.php?view=post&post=' . $postId,
    ]);

} catch (Throwable $e) {
    ms_json(['success' => false, 'message' => 'Failed to create post.', 'detail' => $e->getMessage()], 500);
}
