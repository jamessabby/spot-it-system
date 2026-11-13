<?php
/**
 * S.P.O.T.-IT — Forum: Delete Own Post
 * auth/forum_delete_post.php
 *
 * POST body:
 *   post_id   int  required
 *
 * Soft-deletes a post (is_removed = 1). Only the post's own author may
 * delete it; a locked thread cannot be deleted even by its author.
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
$postId = (int)($_POST['post_id'] ?? 0);

if (!$postId) {
    ms_json(['success' => false, 'message' => 'post_id is required.']);
}

$postStmt = $communityPdo->prepare(
    "SELECT post_id, user_id, is_locked FROM forum_posts WHERE post_id = ? AND is_removed = 0 LIMIT 1"
);
$postStmt->execute([$postId]);
$post = $postStmt->fetch();

if (!$post) {
    ms_json(['success' => false, 'message' => 'Post not found.']);
}
if ((int)$post['user_id'] !== $userId) {
    ms_json(['success' => false, 'message' => 'You can only delete your own posts.'], 403);
}
if ($post['is_locked']) {
    ms_json(['success' => false, 'message' => 'This thread is locked by an admin and cannot be deleted.']);
}

try {
    $communityPdo->prepare(
        "UPDATE forum_posts SET is_removed = 1 WHERE post_id = ? AND user_id = ?"
    )->execute([$postId, $userId]);

    ms_json(['success' => true, 'post_id' => $postId]);

} catch (Throwable $e) {
    ms_json(['success' => false, 'message' => 'Failed to delete post.', 'detail' => $e->getMessage()], 500);
}
