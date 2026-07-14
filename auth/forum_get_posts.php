<?php
/**
 * S.P.O.T.-IT — Forum: Get Posts
 * auth/forum_get_posts.php
 *
 * GET params:
 *   sort      string  hot|top|new|unanswered  (default: hot)
 *   category  string  filter by category
 *   search    string  full-text search on title + content
 *   limit     int     default 20, max 50
 *   offset    int     default 0
 *
 * Returns posts with per-user vote state for the current user.
 * MICROSERVICES: Reads from spotit_community_db + spotit_auth_db (user lookup).
 */
require_once __DIR__ . '/service_bootstrap.php';
require_once __DIR__ . '/../services/community/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

ms_require_auth('../pages/login.php');

$communityPdo = getCommunityDB();
$userId       = (int)$_SESSION['user_id'];
$sort         = $_GET['sort']     ?? 'hot';
$category     = trim($_GET['category'] ?? '');
$search       = trim($_GET['search']   ?? '');
$limit        = min((int)($_GET['limit']  ?? 20), 50);
$offset       = (int)($_GET['offset'] ?? 0);

// ── Build WHERE ───────────────────────────────────────────────────────────────
$where  = ['p.is_removed = 0'];
$params = [];

if ($category) {
    $where[]  = 'p.category = ?';
    $params[] = $category;
}
if ($search) {
    $where[]  = '(p.title LIKE ? OR p.content LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$whereSQL = implode(' AND ', $where);

// ── ORDER BY based on sort mode ───────────────────────────────────────────────
$orderSQL = match($sort) {
    'top'         => 'p.score DESC, p.created_at DESC',
    'new'         => 'p.created_at DESC',
    'unanswered'  => 'p.comment_count ASC, p.created_at DESC',
    default       => // hot: score weighted by recency (Wilson-like approximation)
                    '(p.score / (TIMESTAMPDIFF(HOUR, p.created_at, NOW()) + 2)) DESC, p.created_at DESC',
};

try {
    // Fetch posts
    $stmt = $communityPdo->prepare(
        "SELECT
            p.post_id, p.user_id, p.author_name, p.author_role,
            p.title, p.content, p.category, p.flair,
            p.detection_id, p.detection_room, p.detection_item, p.detection_snap,
            p.is_auto_generated, p.is_locked,
            p.upvotes, p.downvotes, p.score, p.comment_count,
            p.created_at, p.updated_at
         FROM forum_posts p
         WHERE {$whereSQL}
         ORDER BY {$orderSQL}
         LIMIT ? OFFSET ?"
    );
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $posts = $stmt->fetchAll();

    // Total for pagination
    $countStmt = $communityPdo->prepare(
        "SELECT COUNT(*) FROM forum_posts p WHERE {$whereSQL}"
    );
    $countStmt->execute(array_slice($params, 0, -2));
    $total = (int)$countStmt->fetchColumn();

    // Fetch this user's votes on returned posts in one query
    if ($posts) {
        $postIds = array_column($posts, 'post_id');
        $ph      = implode(',', array_fill(0, count($postIds), '?'));
        $voteStmt = $communityPdo->prepare(
            "SELECT post_id, vote_type FROM forum_votes
             WHERE user_id = ? AND post_id IN ({$ph}) AND comment_id IS NULL"
        );
        $voteStmt->execute(array_merge([$userId], $postIds));
        $myVotes = [];
        foreach ($voteStmt->fetchAll() as $v) {
            $myVotes[(int)$v['post_id']] = (int)$v['vote_type'];
        }

        // Annotate posts with user vote state and time-ago
        foreach ($posts as &$p) {
            $p['my_vote']      = $myVotes[(int)$p['post_id']] ?? 0;
            $p['is_own']       = (int)$p['user_id'] === $userId;
            $p['content_preview'] = mb_substr($p['content'], 0, 220) . (mb_strlen($p['content']) > 220 ? '…' : '');
        }
        unset($p);
    }

    ms_json([
        'success' => true,
        'sort'    => $sort,
        'total'   => $total,
        'count'   => count($posts),
        'posts'   => $posts,
    ]);

} catch (Throwable $e) {
    ms_json(['success' => false, 'message' => 'Failed to fetch posts.', 'detail' => $e->getMessage()], 500);
}
