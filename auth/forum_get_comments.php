<?php
/**
 * S.P.O.T.-IT — Forum: Get Comments
 * auth/forum_get_comments.php
 *
 * GET params:
 *   post_id   int   required
 *   sort      string  hot|top|new  (default: hot)
 *
 * Returns flat list of non-removed comments for the post,
 * annotated with the current user's vote state.
 * Depth, parent_comment_id, and nesting are handled client-side.
 *
 * MICROSERVICES: Reads from spotit_community_db only.
 */
require_once __DIR__ . '/service_bootstrap.php';
require_once __DIR__ . '/../services/community/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

ms_require_auth('../pages/login.php');

$communityPdo = getCommunityDB();
$userId       = (int)$_SESSION['user_id'];
$postId       = (int)($_GET['post_id'] ?? 0);
$sort         = $_GET['sort'] ?? 'hot';

if (!$postId) {
    ms_json(['success' => false, 'message' => 'post_id is required.']);
}

// Verify post exists
$postStmt = $communityPdo->prepare(
    "SELECT post_id, title, comment_count, is_locked FROM forum_posts WHERE post_id = ? LIMIT 1"
);
$postStmt->execute([$postId]);
$post = $postStmt->fetch();
if (!$post) {
    ms_json(['success' => false, 'message' => 'Post not found.']);
}

$orderSQL = match($sort) {
    'top' => 'c.score DESC, c.created_at ASC',
    'new' => 'c.created_at DESC',
    default => 'c.depth ASC, c.score DESC, c.created_at ASC', // hot: top-level first, then by score
};

try {
    $stmt = $communityPdo->prepare(
        "SELECT
            c.comment_id, c.post_id, c.user_id,
            c.author_name, c.author_role,
            c.parent_comment_id, c.depth,
            c.content, c.is_removed,
            c.upvotes, c.downvotes, c.score,
            c.created_at, c.updated_at
         FROM forum_comments c
         WHERE c.post_id = ?
         ORDER BY {$orderSQL}"
    );
    $stmt->execute([$postId]);
    $comments = $stmt->fetchAll();

    // Annotate removed comments — replace content but keep structure
    foreach ($comments as &$c) {
        if ($c['is_removed']) {
            $c['content'] = '[Removed by administrator]';
        }
        $c['is_own'] = (int)$c['user_id'] === $userId;
    }
    unset($c);

    // Fetch user votes on these comments
    if ($comments) {
        $commentIds = array_column($comments, 'comment_id');
        $ph         = implode(',', array_fill(0, count($commentIds), '?'));
        $voteStmt   = $communityPdo->prepare(
            "SELECT comment_id, vote_type FROM forum_votes
             WHERE user_id = ? AND comment_id IN ({$ph}) AND post_id IS NULL"
        );
        $voteStmt->execute(array_merge([$userId], $commentIds));
        $myVotes = [];
        foreach ($voteStmt->fetchAll() as $v) {
            $myVotes[(int)$v['comment_id']] = (int)$v['vote_type'];
        }
        foreach ($comments as &$c) {
            $c['my_vote'] = $myVotes[(int)$c['comment_id']] ?? 0;
        }
        unset($c);
    }

    ms_json([
        'success'       => true,
        'post_id'       => $postId,
        'post_title'    => $post['title'],
        'comment_count' => (int)$post['comment_count'],
        'is_locked'     => (bool)$post['is_locked'],
        'sort'          => $sort,
        'comments'      => $comments,
    ]);

} catch (Throwable $e) {
    ms_json(['success' => false, 'message' => 'Failed to fetch comments.', 'detail' => $e->getMessage()], 500);
}
