<?php
/**
 * S.P.O.T.-IT — Forum: Update Own Post
 * auth/forum_update_post.php
 *
 * POST body:
 *   post_id   int     required
 *   title     string  required
 *   content   string  required
 *   category  string  optional (lost_and_found|found_item|general|question|detection_thread)
 *   flair     string  optional
 *
 * Only the post's own author may edit it; a locked thread cannot be edited
 * even by its author.
 * MICROSERVICES: Writes to spotit_community_db only.
 */
require_once __DIR__ . '/service_bootstrap.php';
require_once __DIR__ . '/../services/community/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

ms_require_auth('../pages/login.php');

$communityPdo = getCommunityDB();
$userId = (int)$_SESSION['user_id'];

$postId   = (int)($_POST['post_id'] ?? 0);
$title    = trim($_POST['title']    ?? '');
$content  = trim($_POST['content']  ?? '');
$category = trim($_POST['category'] ?? '');
$flair    = trim($_POST['flair']    ?? '') ?: null;

if (!$postId || !$title || !$content) {
    ms_json(['success' => false, 'message' => 'post_id, title and content are required.']);
}
if (mb_strlen($title) > 300) {
    ms_json(['success' => false, 'message' => 'Title exceeds 300 characters.']);
}

$allowed = ['lost_and_found', 'found_item', 'general', 'question', 'detection_thread'];

$postStmt = $communityPdo->prepare(
    "SELECT post_id, user_id, category, is_locked FROM forum_posts WHERE post_id = ? AND is_removed = 0 LIMIT 1"
);
$postStmt->execute([$postId]);
$post = $postStmt->fetch();

if (!$post) {
    ms_json(['success' => false, 'message' => 'Post not found.']);
}
if ((int)$post['user_id'] !== $userId) {
    ms_json(['success' => false, 'message' => 'You can only edit your own posts.'], 403);
}
if ($post['is_locked']) {
    ms_json(['success' => false, 'message' => 'This thread is locked by an admin and cannot be edited.']);
}

if (!in_array($category, $allowed, true)) {
    $category = $post['category'];
}

try {
    $communityPdo->prepare(
        "UPDATE forum_posts SET title = ?, content = ?, category = ?, flair = ? WHERE post_id = ? AND user_id = ?"
    )->execute([$title, $content, $category, $flair, $postId, $userId]);

    ms_json(['success' => true, 'post_id' => $postId]);

} catch (Throwable $e) {
    ms_json(['success' => false, 'message' => 'Failed to update post.', 'detail' => $e->getMessage()], 500);
}
