<?php
/**
 * S.P.O.T.-IT — Forum: Submit Comment
 * auth/forum_submit_comment.php
 *
 * POST body:
 *   post_id           int     required
 *   content           string  required
 *   parent_comment_id int     optional (NULL = top-level comment)
 *
 * Enforces max nesting depth of 5.
 * Increments forum_posts.comment_count.
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
$userId       = (int)$_SESSION['user_id'];
$userRole     = $_SESSION['user_role'] ?? 'student';
$userName     = $_SESSION['user_name'] ?? 'User';

$postId       = (int)($_POST['post_id']           ?? 0);
$content      = trim($_POST['content']             ?? '');
$parentId     = (int)($_POST['parent_comment_id']  ?? 0) ?: null;

if (!$postId || !$content) {
    ms_json(['success' => false, 'message' => 'post_id and content are required.']);
}

// Verify post exists and is not locked
$postStmt = $communityPdo->prepare(
    "SELECT post_id, is_locked FROM forum_posts WHERE post_id = ? AND is_removed = 0 LIMIT 1"
);
$postStmt->execute([$postId]);
$post = $postStmt->fetch();

if (!$post) {
    ms_json(['success' => false, 'message' => 'Post not found.']);
}
if ($post['is_locked']) {
    ms_json(['success' => false, 'message' => 'This thread is locked. No new comments can be added.']);
}

// Determine depth from parent
$depth = 0;
if ($parentId) {
    $parentStmt = $communityPdo->prepare(
        "SELECT depth FROM forum_comments WHERE comment_id = ? AND post_id = ? LIMIT 1"
    );
    $parentStmt->execute([$parentId, $postId]);
    $parent = $parentStmt->fetch();

    if (!$parent) {
        ms_json(['success' => false, 'message' => 'Parent comment not found.']);
    }

    $depth = (int)$parent['depth'] + 1;

    // Enforce max depth 5 — client shows "Continue Thread" button at depth 4
    if ($depth > 5) {
        ms_json(['success' => false, 'message' => 'Maximum nesting depth (5) reached.']);
    }
}

try {
    $communityPdo->beginTransaction();

    $communityPdo->prepare(
        "INSERT INTO forum_comments
           (post_id, user_id, author_name, author_role,
            parent_comment_id, depth, content,
            upvotes, downvotes, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, NOW())"
    )->execute([
        $postId, $userId, $userName, $userRole,
        $parentId, $depth, $content,
    ]);
    $commentId = (int)$communityPdo->lastInsertId();

    // Increment comment count on post
    $communityPdo->prepare(
        "UPDATE forum_posts SET comment_count = comment_count + 1 WHERE post_id = ?"
    )->execute([$postId]);

    $communityPdo->commit();

    ms_json([
        'success'    => true,
        'comment_id' => $commentId,
        'depth'      => $depth,
        'author'     => $userName,
        'role'       => $userRole,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

} catch (Throwable $e) {
    $communityPdo->rollBack();
    ms_json(['success' => false, 'message' => 'Failed to submit comment.', 'detail' => $e->getMessage()], 500);
}
