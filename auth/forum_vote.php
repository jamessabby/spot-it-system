<?php
/**
 * S.P.O.T.-IT — Forum: Vote
 * auth/forum_vote.php
 *
 * POST body:
 *   target_type  string  'post' | 'comment'  required
 *   target_id    int     post_id or comment_id  required
 *   vote_type    int     1 = upvote, -1 = downvote  required
 *
 * Vote behaviour (Reddit-style toggle):
 *   - No existing vote → INSERT new vote, increment tally
 *   - Same vote exists → DELETE (un-vote), decrement tally
 *   - Opposite vote exists → UPDATE to new type, adjust both tallies
 *
 * MICROSERVICES: Writes to spotit_community_db (votes + denormalised tallies).
 */
require_once __DIR__ . '/service_bootstrap.php';
require_once __DIR__ . '/../services/community/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

ms_require_auth('../pages/login.php');

$communityPdo = getCommunityDB();
$userId      = (int)$_SESSION['user_id'];
$targetType  = trim($_POST['target_type'] ?? '');
$targetId    = (int)($_POST['target_id']  ?? 0);
$voteType    = (int)($_POST['vote_type']  ?? 0);

if (!in_array($targetType, ['post','comment'], true) || !$targetId || !in_array($voteType, [1,-1], true)) {
    ms_json(['success' => false, 'message' => 'Invalid parameters.']);
}

$isPost = $targetType === 'post';

// Check existing vote
$existingStmt = $communityPdo->prepare(
    $isPost
        ? "SELECT vote_id, vote_type FROM forum_votes WHERE user_id = ? AND post_id = ? AND comment_id IS NULL LIMIT 1"
        : "SELECT vote_id, vote_type FROM forum_votes WHERE user_id = ? AND comment_id = ? AND post_id IS NULL LIMIT 1"
);
$existingStmt->execute([$userId, $targetId]);
$existing = $existingStmt->fetch();

try {
    $communityPdo->beginTransaction();

    if (!$existing) {
        // ── New vote ──────────────────────────────────────────────────────────
        $communityPdo->prepare(
            $isPost
                ? "INSERT INTO forum_votes (user_id, post_id, vote_type) VALUES (?,?,?)"
                : "INSERT INTO forum_votes (user_id, comment_id, vote_type) VALUES (?,?,?)"
        )->execute([$userId, $targetId, $voteType]);

        _updateTally($communityPdo, $targetType, $targetId, $voteType, 0);
        $newVote = $voteType;

    } elseif ((int)$existing['vote_type'] === $voteType) {
        // ── Un-vote (toggle off) ──────────────────────────────────────────────
        $communityPdo->prepare(
            "DELETE FROM forum_votes WHERE vote_id = ?"
        )->execute([$existing['vote_id']]);

        _updateTally($communityPdo, $targetType, $targetId, 0, $voteType);
        $newVote = 0;

    } else {
        // ── Switch vote ───────────────────────────────────────────────────────
        $communityPdo->prepare(
            "UPDATE forum_votes SET vote_type = ? WHERE vote_id = ?"
        )->execute([$voteType, $existing['vote_id']]);

        _updateTally($communityPdo, $targetType, $targetId, $voteType, (int)$existing['vote_type']);
        $newVote = $voteType;
    }

    $communityPdo->commit();

    // Return updated counts
    $row = _getCounts($communityPdo, $targetType, $targetId);

    ms_json([
        'success'   => true,
        'target'    => $targetType,
        'target_id' => $targetId,
        'my_vote'   => $newVote,
        'upvotes'   => (int)$row['upvotes'],
        'downvotes' => (int)$row['downvotes'],
        'score'     => (int)$row['upvotes'] - (int)$row['downvotes'],
    ]);

} catch (Throwable $e) {
    $communityPdo->rollBack();
    ms_json(['success' => false, 'message' => 'Vote failed.', 'detail' => $e->getMessage()], 500);
}

/**
 * Update the denormalised upvotes/downvotes columns on the target row.
 * $newType: the vote just cast (1, -1, or 0 if un-voting)
 * $oldType: the previous vote that is being removed/replaced (0 if none)
 */
function _updateTally(PDO $pdo, string $type, int $id, int $newType, int $oldType): void {
    $table = $type === 'post' ? 'forum_posts' : 'forum_comments';
    $pk    = $type === 'post' ? 'post_id'     : 'comment_id';

    $upAdj   = ($newType === 1 ? 1 : 0) - ($oldType === 1 ? 1 : 0);
    $downAdj = ($newType === -1 ? 1 : 0) - ($oldType === -1 ? 1 : 0);

    if ($upAdj === 0 && $downAdj === 0) return;

    $pdo->prepare(
        "UPDATE {$table}
         SET upvotes   = GREATEST(0, upvotes   + ?),
             downvotes = GREATEST(0, downvotes + ?)
         WHERE {$pk} = ?"
    )->execute([$upAdj, $downAdj, $id]);
}

function _getCounts(PDO $pdo, string $type, int $id): array {
    $table = $type === 'post' ? 'forum_posts' : 'forum_comments';
    $pk    = $type === 'post' ? 'post_id'     : 'comment_id';
    $stmt  = $pdo->prepare("SELECT upvotes, downvotes FROM {$table} WHERE {$pk} = ? LIMIT 1");
    $stmt->execute([$id]);
    return $stmt->fetch() ?: ['upvotes' => 0, 'downvotes' => 0];
}
