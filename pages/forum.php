<?php
/**
 * S.P.O.T.-IT — Community Forum Page
 * pages/forum.php
 * Reddit-style: upvote/downvote, nested comments (max depth 5), sort modes.
 * MICROSERVICES: No SQL. Calls auth/forum_*.php handlers via JS.
 */
require_once __DIR__ . '/../auth/service_bootstrap.php';
$active_page = 'forum';
$user_role   = $_SESSION['user_role'] ?? 'student';
$uname       = $_SESSION['user_name'] ?? 'User';
$is_admin    = $user_role === 'admin';

// View mode: 'list' = post listing, 'post' = single post + comments
$view    = $_GET['view']    ?? 'list';
$post_id = (int)($_GET['post'] ?? 0);

// Fetch posts/post
$dbPosts = [];
$post = null;
if ($view === 'list') {
    $dbPostsStmt = $communityPdo->query("
        SELECT post_id as id, category, title, author_name as author, author_role as role, created_at, content as excerpt, upvotes, downvotes, comment_count as comments, is_auto_generated as auto, is_locked as locked, detection_id, detection_room, detection_item, detection_snap
        FROM forum_posts
        WHERE is_removed = 0
        ORDER BY created_at DESC
    ");
    $dbPosts = $dbPostsStmt->fetchAll();
} elseif ($post_id) {
    $postStmt = $communityPdo->prepare("
        SELECT post_id as id, category, title, author_name as author, author_role as role, created_at, content, upvotes, downvotes, is_auto_generated as auto, is_locked as locked, detection_id, detection_room, detection_item, detection_snap
        FROM forum_posts
        WHERE post_id = ? AND is_removed = 0 LIMIT 1
    ");
    $postStmt->execute([$post_id]);
    $post = $postStmt->fetch();
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Community Forum — S.P.O.T.-IT</title>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/css/community.css"/>
  <link rel="stylesheet" href="../assets/css/skeleton.css"/>
  <script>(function(){document.documentElement.setAttribute('data-theme',localStorage.getItem('spotit_theme')||'light')})();</script>
</head>
<body data-skeleton="dashboard">
<script src="../assets/js/skeleton.js"></script>
<div class="app-shell">
  <?php include '_sidebar.php'; ?>
  <div class="main-content">

    <!-- Topbar -->
    <div class="topbar">
      <button class="tb-btn tb-hamburger" id="hamburgerBtn" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
      <div>
        <?php if ($view === 'post'): ?>
        <a href="forum.php" style="font-size:.78rem;color:var(--green-main);text-decoration:none;margin-right:8px;">
          <i class="fa-solid fa-arrow-left"></i> Back to Forum
        </a>
        <span class="topbar-title">Post Thread</span>
        <?php else: ?>
        <span class="topbar-title">Community Forum</span>
        <span class="topbar-sub">— CEAT Lost &amp; Found Discussions</span>
        <?php endif; ?>
      </div>
      <div class="topbar-right">
        <span style="font-family:var(--font-mono);font-size:.7rem;color:var(--text-dim);" id="liveClock"></span>
        <button class="btn btn-primary btn-sm" onclick="openModal('composePostModal')">
          <i class="fa-solid fa-pen-to-square"></i> Create Post
        </button>
        <button class="tb-btn" onclick="toggleTheme()"><i class="fa-solid fa-circle-half-stroke"></i></button>
      </div>
    </div>

    <div class="page-body">

    <?php if ($view === 'list'): ?>
    <!-- ══════════════════════════════════════════
         POST LISTING VIEW
    ══════════════════════════════════════════ -->

      <div class="comm-header">
        <div class="comm-header-left">
          <h2><i class="fa-brands fa-reddit" style="color:var(--alert);margin-right:8px;"></i>Community Forum</h2>
          <p>Student discussions, lost &amp; found reports, detection threads, and questions about CEAT items.</p>
        </div>
      </div>

      <!-- Search + sort -->
      <div class="comm-filter-bar">
        <div class="comm-search-wrap">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" id="forumSearch" class="comm-search" placeholder="Search posts…" oninput="filterPosts()"/>
        </div>
        <select id="forumCategory" class="comm-select" onchange="filterPosts()">
          <option value="">All Categories</option>
          <option value="detection_thread">⚠ Detection Threads</option>
          <option value="lost_and_found">Lost &amp; Found</option>
          <option value="found_item">Found Items</option>
          <option value="question">Questions</option>
          <option value="general">General</option>
        </select>
        <div class="sort-tabs" id="sortTabs">
          <button class="sort-tab active" onclick="setSort('hot',this)"><i class="fa-solid fa-fire"></i> Hot</button>
          <button class="sort-tab" onclick="setSort('top',this)"><i class="fa-solid fa-trophy"></i> Top</button>
          <button class="sort-tab" onclick="setSort('new',this)"><i class="fa-solid fa-clock-rotate-left"></i> New</button>
          <button class="sort-tab" onclick="setSort('unanswered',this)"><i class="fa-solid fa-comment-slash"></i> Unanswered</button>
        </div>
      </div>

      <div class="forum-layout">

        <!-- Post feed -->
        <div id="postFeed">

          <?php
          $posts = [];
          foreach ($dbPosts as $dp) {
              $catLabel = match($dp['category']) {
                  'lost_and_found'    => 'Lost & Found',
                  'found_item'        => 'Found Item',
                  'question'          => 'Question',
                  'detection_thread'  => 'Detection Thread',
                  default             => 'General',
              };
              
              $detection = null;
              if ($dp['detection_id']) {
                  $detection = [
                      'room'   => $dp['detection_room'],
                      'item'   => $dp['detection_item'],
                      'time'   => $dp['created_at'],
                      'status' => 'Detected'
                  ];
              }

              // Simple time ago calculation
              $timeAgo = 'Just now';
              $elapsed = time() - strtotime($dp['created_at']);
              if ($elapsed >= 86400) {
                  $days = round($elapsed / 86400);
                  $timeAgo = $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
              } elseif ($elapsed >= 3600) {
                  $hours = round($elapsed / 3600);
                  $timeAgo = $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
              } elseif ($elapsed >= 60) {
                  $minutes = round($elapsed / 60);
                  $timeAgo = $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
              }
              
              // Strip markdown or HTML for excerpt
              $excerpt = strip_tags($dp['excerpt']);
              if (strlen($excerpt) > 150) {
                  $excerpt = substr($excerpt, 0, 150) . '...';
              }
              
              $posts[] = [
                  'id'        => $dp['id'],
                  'category'  => $dp['category'],
                  'cat_label' => $catLabel,
                  'auto'      => (bool)$dp['auto'],
                  'locked'    => (bool)$dp['locked'],
                  'title'     => $dp['title'],
                  'author'    => $dp['author'],
                  'role'      => $dp['role'],
                  'time'      => $timeAgo,
                  'excerpt'   => $excerpt,
                  'upvotes'   => (int)$dp['upvotes'],
                  'downvotes' => (int)$dp['downvotes'],
                  'comments'  => (int)$dp['comments'],
                  'detection' => $detection,
              ];
          }
          ?>
          <?php if (empty($posts)): ?>
          <div class="comm-empty" id="forumEmpty" style="display:flex;">
            <i class="fa-solid fa-comments"></i>
            <h3>No posts found</h3>
            <p>Be the first to start a discussion or post a report!</p>
          </div>
          <?php else: ?>
          <?php foreach ($posts as $p):
            $score = $p['upvotes'] - $p['downvotes'];
            $scoreClass = $score > 0 ? 'positive' : ($score < 0 ? 'negative' : '');
            $initials = strtoupper(substr($p['author'],0,1));
            $roleClass = $p['role'] === 'admin' ? 'role-badge-system' : '';
          ?>
          <div class="forum-post-card <?= $p['detection'] ? 'detection-post' : '' ?>"
               data-category="<?= $p['category'] ?>"
               data-title="<?= strtolower(htmlspecialchars($p['title'])) ?>"
               data-comments="<?= $p['comments'] ?>">

            <!-- Vote column -->
            <div class="vote-col">
              <button class="vote-btn upvote" onclick="castVote('post',<?= $p['id'] ?>,'up',this)" title="Upvote">
                <i class="fa-solid fa-caret-up"></i>
              </button>
              <div class="vote-score <?= $scoreClass ?>"><?= $score ?></div>
              <button class="vote-btn downvote" onclick="castVote('post',<?= $p['id'] ?>,'down',this)" title="Downvote">
                <i class="fa-solid fa-caret-down"></i>
              </button>
            </div>

            <!-- Post body -->
            <div class="post-body">
              <div class="post-meta-row">
                <span class="post-category-tag post-cat-<?= $p['category'] ?>"><?= htmlspecialchars($p['cat_label']) ?></span>
                <?php if ($p['auto']): ?>
                <span class="post-category-tag" style="background:rgba(26,106,181,.1);color:var(--info);">
                  <i class="fa-solid fa-robot"></i> Auto-Generated
                </span>
                <?php endif; ?>
                <div class="post-author-chip">
                  <div class="post-author-avatar <?= $roleClass ?>"><?= $initials ?></div>
                  <span style="font-weight:600;color:var(--text-muted);"><?= htmlspecialchars($p['author']) ?></span>
                </div>
                <span><?= $p['time'] ?></span>
              </div>

              <?php if ($p['detection']): ?>
              <div class="detection-infobox">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <div class="detection-infobox-text">
                  <strong><?= htmlspecialchars($p['detection']['item']) ?></strong> detected in
                  <strong><?= htmlspecialchars($p['detection']['room']) ?></strong> at
                  <strong><?= htmlspecialchars($p['detection']['time']) ?></strong> —
                  Status: <strong style="color:var(--alert);"><?= htmlspecialchars($p['detection']['status']) ?></strong>
                </div>
              </div>
              <?php endif; ?>

              <div class="post-title" onclick="viewPost(<?= $p['id'] ?>)">
                <?= htmlspecialchars($p['title']) ?>
              </div>
              <div class="post-excerpt"><?= htmlspecialchars($p['excerpt']) ?></div>

              <div class="post-action-row">
                <button class="post-action-btn" onclick="viewPost(<?= $p['id'] ?>)">
                  <i class="fa-solid fa-comment"></i> <?= $p['comments'] ?> Comments
                </button>
                <button class="post-action-btn" onclick="sharePost(<?= $p['id'] ?>)">
                  <i class="fa-solid fa-share"></i> Share
                </button>
                <button class="post-action-btn" onclick="savePost(<?= $p['id'] ?>)">
                  <i class="fa-regular fa-bookmark"></i> Save
                </button>
                <?php if ($is_admin): ?>
                <button class="post-action-btn" onclick="removePost(<?= $p['id'] ?>)" style="color:var(--alert);">
                  <i class="fa-solid fa-trash"></i> Remove
                </button>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <div class="comm-empty" id="forumEmpty" style="display:none;">
            <i class="fa-solid fa-comments"></i>
            <h3>No posts found</h3>
            <p>Try a different keyword or category, or be the first to start a discussion!</p>
          </div>
          <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <aside class="forum-sidebar">
          <!-- About -->
          <div class="comm-sidebar-card">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-circle-info"></i> About This Forum</div></div>
            <div style="padding:14px;font-size:.8rem;color:var(--text-muted);line-height:1.7;font-weight:300;">
              A community space for DLSU-D CEAT students and staff to discuss lost &amp; found items, share information about detection alerts, and ask questions. Be respectful and helpful.
            </div>
          </div>

          <!-- Rules -->
          <div class="comm-sidebar-card">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-gavel"></i> Forum Rules</div></div>
            <div style="padding:14px;display:flex;flex-direction:column;gap:9px;">
              <?php foreach([
                ['1','Be respectful and courteous to all members.'],
                ['2','Only post genuine lost/found reports — no spam.'],
                ['3','Include specific details (room, time, description).'],
                ['4','Do not share personal identifying information.'],
                ['5','Report suspicious posts to lab staff or admin.'],
              ] as [$n,$rule]): ?>
              <div style="display:flex;gap:8px;font-size:.77rem;color:var(--text-muted);">
                <span style="width:18px;height:18px;border-radius:50%;background:var(--green-main);color:#fff;font-family:var(--font-display);font-weight:800;font-size:.62rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;"><?= $n ?></span>
                <span><?= $rule ?></span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Stats -->
          <div class="comm-sidebar-card">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-chart-bar"></i> Forum Stats</div></div>
            <div style="padding:14px;display:flex;flex-direction:column;gap:8px;">
              <?php
              $totalPosts = (int)$communityPdo->query("SELECT COUNT(*) FROM forum_posts WHERE is_removed = 0")->fetchColumn();
              $totalComments = (int)$communityPdo->query("SELECT COUNT(*) FROM forum_comments")->fetchColumn();
              $totalVotes = (int)$communityPdo->query("SELECT IFNULL(SUM(upvotes), 0) FROM forum_posts WHERE is_removed = 0")->fetchColumn();
              $totalDetectionThreads = (int)$communityPdo->query("SELECT COUNT(*) FROM forum_posts WHERE category = 'detection_thread' AND is_removed = 0")->fetchColumn();
              
              foreach([
                [$totalPosts,'Total Posts','fa-pen-to-square','green'],
                [$totalComments,'Total Comments','fa-comments','info'],
                [$totalVotes,'Upvotes Cast','fa-caret-up','alert'],
                [$totalDetectionThreads,'Detection Threads','fa-triangle-exclamation','warn'],
              ] as [$n,$l,$ic,$c]): ?>
              <div style="display:flex;align-items:center;gap:10px;">
                <i class="fa-solid <?= $ic ?>" style="color:var(--<?= $c ?>);width:14px;text-align:center;"></i>
                <span style="font-size:.78rem;color:var(--text-muted);flex:1;"><?= htmlspecialchars($l) ?></span>
                <span style="font-family:var(--font-display);font-weight:800;font-size:.88rem;color:var(--text-primary);"><?= $n ?></span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Quick nav to announcements -->
          <a href="announcements.php" style="text-decoration:none;">
            <div class="comm-sidebar-card" style="background:var(--green-pale);border-color:rgba(0,86,49,.2);cursor:pointer;transition:var(--transition);">
              <div style="padding:16px;display:flex;align-items:center;gap:12px;">
                <i class="fa-solid fa-bullhorn" style="font-size:1.3rem;color:var(--green-main);"></i>
                <div>
                  <div style="font-family:var(--font-display);font-size:.84rem;font-weight:700;color:var(--text-primary);">Announcements</div>
                  <div style="font-size:.74rem;color:var(--text-muted);">Official notices from admin</div>
                </div>
                <i class="fa-solid fa-chevron-right" style="margin-left:auto;color:var(--green-main);font-size:.7rem;"></i>
              </div>
            </div>
          </a>
        </aside>
      </div>

    <?php else: // Single post view ?>
    <!-- ══════════════════════════════════════════
         SINGLE POST + COMMENTS VIEW
    ══════════════════════════════════════════ -->
      <?php
      if (!$post):
          echo '<div style="margin:40px auto; max-width:600px; padding:2rem; background:var(--card-bg); border-radius:12px; border:1px solid var(--border); text-align:center;">';
          echo '<i class="fa-solid fa-circle-exclamation" style="font-size:2rem; color:var(--alert); margin-bottom:12px;"></i>';
          echo '<h3 style="font-family:var(--font-display); font-weight:700;">Post not found</h3>';
          echo '<p style="font-size:.8rem; color:var(--text-muted); margin-bottom:1rem;">The post you are trying to view does not exist or was deleted.</p>';
          echo '<a href="forum.php" class="btn btn-primary" style="display:inline-flex; align-items:center; gap:8px;"><i class="fa-solid fa-arrow-left"></i> Return to Forum</a>';
          echo '</div>';
      else:
          $catLabel = match($post['category']) {
              'lost_and_found'    => 'Lost & Found',
              'found_item'        => 'Found Item',
              'question'          => 'Question',
              'detection_thread'  => 'Detection Thread',
              default             => 'General',
          };
          
          $detection = null;
          if ($post['detection_id']) {
              $detection = [
                  'room'   => $post['detection_room'],
                  'item'   => $post['detection_item'],
                  'time'   => date('M j, Y · H:i:s', strtotime($post['created_at'])),
                  'status' => 'Detected'
              ];
          }
          
          $score = (int)$post['upvotes'] - (int)$post['downvotes'];
          
          $timeAgo = 'Just now';
          $elapsed = time() - strtotime($post['created_at']);
          if ($elapsed >= 86400) {
              $days = round($elapsed / 86400);
              $timeAgo = $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
          } elseif ($elapsed >= 3600) {
              $hours = round($elapsed / 3600);
              $timeAgo = $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
          } elseif ($elapsed >= 60) {
              $minutes = round($elapsed / 60);
              $timeAgo = $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
          }
      ?>

      <!-- Full post card -->
      <div class="full-post-card">
        <div class="full-post-header">
          <div class="full-vote-col">
            <button class="vote-btn upvote" onclick="castVote('post',<?= $post['id'] ?>,'up',this)">
              <i class="fa-solid fa-caret-up"></i>
            </button>
            <div class="vote-score <?= $score > 0 ? 'positive' : '' ?>"><?= $score ?></div>
            <button class="vote-btn downvote" onclick="castVote('post',<?= $post['id'] ?>,'down',this)">
              <i class="fa-solid fa-caret-down"></i>
            </button>
          </div>
          <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:8px;font-size:.72rem;color:var(--text-dim);">
              <span class="post-category-tag post-cat-<?= $post['category'] ?>"><?= htmlspecialchars($catLabel) ?></span>
              <?php if ($post['auto']): ?>
              <span class="post-category-tag" style="background:rgba(26,106,181,.1);color:var(--info);">
                <i class="fa-solid fa-robot"></i> Auto-Generated
              </span>
              <?php endif; ?>
              <span>Posted by <strong style="color:var(--text-muted);"><?= htmlspecialchars($post['author']) ?></strong></span>
              <span><?= $timeAgo ?></span>
            </div>
          </div>
        </div>

        <div class="full-post-content" style="padding-left:68px;">
          <div class="full-post-title"><?= htmlspecialchars($post['title']) ?></div>

          <?php if ($detection): ?>
          <div class="detection-infobox" style="margin-bottom:14px;">
            <i class="fa-solid fa-triangle-exclamation"></i>
            <div class="detection-infobox-text">
              <strong><?= htmlspecialchars($detection['item']) ?></strong> detected in
              <strong><?= htmlspecialchars($detection['room']) ?></strong> at
              <strong><?= htmlspecialchars($detection['time']) ?></strong> —
              Status: <strong style="color:var(--alert);"><?= htmlspecialchars($detection['status']) ?></strong>
            </div>
          </div>

          <!-- Mock CCTV snapshot -->
          <div class="post-snap-mock">
            <div style="position:absolute;inset:0;">
              <svg width="100%" height="100%" viewBox="0 0 640 200" preserveAspectRatio="none">
                <?php for($r=0;$r<2;$r++): for($c=0;$c<5;$c++): $x=20+$c*120;$y=20+$r*90; ?>
                <rect x="<?=$x?>" y="<?=$y?>" width="100" height="60" rx="4" fill="rgba(255,255,255,.04)"/>
                <?php endfor; endfor; ?>
                <rect x="20" y="20" width="100" height="60" rx="4" fill="rgba(0,200,120,.08)" stroke="#5cffac" stroke-width="1.5"/>
                <rect x="140" y="20" width="100" height="60" rx="4" fill="rgba(0,200,120,.08)" stroke="#5cffac" stroke-width="1.5"/>
                <rect x="260" y="20" width="100" height="60" rx="4" fill="rgba(0,200,120,.08)" stroke="#5cffac" stroke-width="1.5"/>
                <rect x="500" y="20" width="100" height="60" rx="4" fill="rgba(255,77,77,.12)" stroke="#ff4d4d" stroke-width="2" stroke-dasharray="6,3"/>
                <text x="504" y="14" font-family="monospace" font-size="9" fill="#ff4d4d">WS-07 ✗ MISSING</text>
              </svg>
            </div>
            <div style="position:absolute;top:8px;left:10px;font-size:.58rem;color:rgba(100,255,160,.7);">CAM-01 · <?= htmlspecialchars($detection['room']) ?> · <?= htmlspecialchars($detection['time']) ?></div>
          </div>
          <?php endif; ?>

          <div class="full-post-body">
            <?php foreach(explode("\n\n",$post['content']) as $para):
              echo '<p>'.nl2br(htmlspecialchars(trim($para))).'</p>';
            endforeach; ?>
          </div>

          <div class="post-action-row" style="margin-top:12px;">
            <button class="post-action-btn" onclick="focusCommentBox()">
              <i class="fa-solid fa-comment"></i> Reply
            </button>
            <button class="post-action-btn" onclick="sharePost(<?= $post['id'] ?>)">
              <i class="fa-solid fa-share"></i> Share
            </button>
            <button class="post-action-btn" onclick="savePost(<?= $post['id'] ?>)">
              <i class="fa-regular fa-bookmark"></i> Save
            </button>
            <?php if ($is_admin): ?>
            <button class="post-action-btn" onclick="removePost(<?= $post['id'] ?>)" style="color:var(--alert);">
              <i class="fa-solid fa-lock"></i> Lock Thread
            </button>
            <?php endif; ?>
          </div>
        </div>

        <!-- Comment compose -->
        <div class="comment-compose" style="padding-left:68px;">
          <div class="compose-wrap" id="mainCompose">
            <textarea class="compose-textarea" id="mainCommentBox"
                      placeholder="Share what you know — has this item been returned or claimed? 👆"></textarea>
            <div class="compose-footer">
              <span style="font-size:.72rem;color:var(--text-dim);">Commenting as <strong><?= htmlspecialchars($uname) ?></strong></span>
              <button class="btn btn-sm" onclick="document.getElementById('mainCommentBox').value=''">Cancel</button>
              <button class="btn btn-primary btn-sm" onclick="submitComment(null)">
                <i class="fa-solid fa-paper-plane"></i> Comment
              </button>
            </div>
          </div>
        </div>

        <!-- Comments section -->
        <div class="comments-section">
          <?php
          // Fetch comments for post
          $commentsStmt = $communityPdo->prepare("
              SELECT comment_id as id, parent_comment_id as parent_id, depth, author_name as author, author_role as role, created_at, content as text, upvotes, downvotes
              FROM forum_comments
              WHERE post_id = ?
              ORDER BY created_at ASC
          ");
          $commentsStmt->execute([$post_id]);
          $rawComments = $commentsStmt->fetchAll();

          $commentMap = [];
          $rootComments = [];
          foreach ($rawComments as $rc) {
              $rcId = (int)$rc['id'];
              $rc['replies'] = [];
              
              $timeAgo = 'Just now';
              $elapsed = time() - strtotime($rc['created_at']);
              if ($elapsed >= 86400) {
                  $days = round($elapsed / 86400);
                  $timeAgo = $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
              } elseif ($elapsed >= 3600) {
                  $hours = round($elapsed / 3600);
                  $timeAgo = $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
              } elseif ($elapsed >= 60) {
                  $minutes = round($elapsed / 60);
                  $timeAgo = $minutes . ' minute' . ($minutes > 1 ? 's' : '') . ' ago';
              }
              $rc['time'] = $timeAgo;
              $commentMap[$rcId] = $rc;
          }

          foreach ($commentMap as $rcId => &$comment) {
              $pId = $comment['parent_id'] ? (int)$comment['parent_id'] : null;
              if ($pId === null) {
                  $rootComments[] = &$comment;
              } else {
                  if (isset($commentMap[$pId])) {
                      $commentMap[$pId]['replies'][] = &$comment;
                  } else {
                      $rootComments[] = &$comment;
                  }
              }
          }
          unset($comment);
          $comments = $rootComments;
          ?>
          <div class="comments-header">
            <span><i class="fa-solid fa-comments" style="color:var(--green-main);margin-right:6px;"></i> <?= count($rawComments) ?> Comments</span>
            <div class="sort-tabs" style="transform:scale(.85);transform-origin:right;">
              <button class="sort-tab active" onclick="setSort('hot',this)"><i class="fa-solid fa-fire"></i> Hot</button>
              <button class="sort-tab" onclick="setSort('top',this)"><i class="fa-solid fa-trophy"></i> Top</button>
              <button class="sort-tab" onclick="setSort('new',this)"><i class="fa-solid fa-clock-rotate-left"></i> New</button>
            </div>
          </div>

          <div class="comment-thread" id="commentThread">
            <?php
            function renderComment(array $c, $maxDepth = 5): void {
              $sc = $c['upvotes'] - $c['downvotes'];
              $scClass = $sc > 0 ? 'positive' : ($sc < 0 ? 'negative' : '');
              $roleLabel = ['admin'=>'Admin','staff'=>'Staff'][$c['role']] ?? null;
              $roleCls   = ['admin'=>'crb-admin','staff'=>'crb-staff','student'=>'crb-student'][$c['role']] ?? 'crb-student';
              $initials  = strtoupper(substr($c['author'],0,1));
              $indent    = $c['depth'] * 20;
              ?>
              <div class="comment-item" id="comment-<?= $c['id'] ?>" style="margin-left:<?= $indent ?>px;">
                <div class="comment-vote-col">
                  <button class="comment-vote-btn upvote" onclick="castVote('comment',<?= $c['id'] ?>,'up',this)" title="Upvote">
                    <i class="fa-solid fa-caret-up"></i>
                  </button>
                  <div class="comment-score <?= $scClass ?>"><?= $sc ?></div>
                  <button class="comment-vote-btn downvote" onclick="castVote('comment',<?= $c['id'] ?>,'down',this)" title="Downvote">
                    <i class="fa-solid fa-caret-down"></i>
                  </button>
                </div>
                <div style="width:1px;background:var(--border);margin:12px 6px 0;align-self:stretch;cursor:pointer;" onclick="collapseThread(<?= $c['id'] ?>)" title="Collapse thread"></div>
                <div class="comment-body">
                  <div class="comment-author-row">
                    <div class="post-author-avatar" style="<?= $c['role']==='admin'?'background:var(--alert);':($c['role']==='staff'?'background:var(--warn);':'') ?>"><?= $initials ?></div>
                    <span class="comment-author"><?= htmlspecialchars($c['author']) ?></span>
                    <?php if ($roleLabel): ?>
                    <span class="comment-role-badge <?= $roleCls ?>"><?= $roleLabel ?></span>
                    <?php endif; ?>
                    <span class="comment-ts"><?= $c['time'] ?></span>
                  </div>
                  <div class="comment-text"><?= htmlspecialchars($c['text']) ?></div>
                  <div class="comment-actions">
                    <button class="comment-action-btn" onclick="toggleReplyBox(<?= $c['id'] ?>)">
                      <i class="fa-solid fa-reply"></i> Reply
                    </button>
                    <button class="comment-action-btn" onclick="sharePost(<?= $c['id'] ?>)">
                      <i class="fa-solid fa-share"></i> Share
                    </button>
                    <?php if ($is_admin): ?>
                    <button class="comment-action-btn" onclick="removeComment(<?= $c['id'] ?>)" style="color:var(--alert);">
                      <i class="fa-solid fa-trash"></i> Remove
                    </button>
                    <?php endif; ?>
                  </div>
                  <!-- Inline reply box -->
                  <div class="reply-compose-box" id="reply-box-<?= $c['id'] ?>">
                    <textarea placeholder="Reply to <?= htmlspecialchars($c['author']) ?>…"></textarea>
                    <div class="reply-compose-footer">
                      <button class="btn btn-sm" onclick="toggleReplyBox(<?= $c['id'] ?>)">Cancel</button>
                      <button class="btn btn-primary btn-sm" onclick="submitComment(<?= $c['id'] ?>)">
                        <i class="fa-solid fa-paper-plane"></i> Reply
                      </button>
                    </div>
                  </div>
                  <?php if (!empty($c['replies'])): ?>
                  <div class="comment-replies" id="replies-<?= $c['id'] ?>">
                    <?php if ($c['depth'] >= $maxDepth - 1): ?>
                    <div class="continue-thread-btn" onclick="showToast('info','Deeper thread navigation will be available in the full implementation.')">
                      <i class="fa-solid fa-arrow-right"></i> Continue Thread
                    </div>
                    <?php else: ?>
                    <?php foreach($c['replies'] as $reply): renderComment($reply, $maxDepth); endforeach; ?>
                    <?php endif; ?>
                  </div>
                  <?php endif; ?>
                </div>
              </div>
            <?php
            }
            foreach ($comments as $comment) renderComment($comment);
            endif;
            ?>
          </div>
        </div>
      </div>
    <?php endif; ?>

    </div>
  </div>
</div>

<!-- ══════════ COMPOSE POST MODAL ══════════ -->
<div class="modal-overlay" id="composePostModal" onclick="if(event.target===this)closeModal('composePostModal')">
  <div class="modal-box" style="max-width:580px;">
    <div class="modal-head">
      <div class="modal-title">Create Community Post</div>
      <div class="modal-close" onclick="closeModal('composePostModal')"><i class="fa-solid fa-xmark"></i></div>
    </div>
    <div class="modal-body">
      <!-- Category picker -->
      <div class="form-group">
        <label class="form-label">Post Type *</label>
        <div class="compose-category-grid">
          <?php foreach([
            ['lost_and_found','fa-circle-question','Lost Item'],
            ['found_item','fa-circle-check','Found Item'],
            ['question','fa-circle-info','Question'],
            ['general','fa-comments','General'],
          ] as [$cat,$ic,$lb]): ?>
          <div class="compose-cat-btn <?= $cat==='general'?'active':'' ?>"
               data-cat="<?= $cat ?>" onclick="selectPostCategory('<?= $cat ?>',this)">
            <i class="fa-solid <?= $ic ?>"></i>
            <div class="cat-label"><?= $lb ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <input type="hidden" id="postCategory" value="general"/>
      </div>

      <!-- Detection link (auto-fill from dashboard) -->
      <div class="detection-link-banner" id="detectionLinkBanner" style="display:none;">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <div class="text">
          <strong>Linked to Detection Event:</strong> <span id="detectionLinkText">—</span><br/>
          This post will be linked to the CCTV detection record and marked as an auto-generated discussion thread.
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Title *</label>
        <input type="text" class="form-control" id="postTitle" placeholder="e.g. Lost: Black umbrella near MLH 306"/>
      </div>
      <div class="form-group">
        <label class="form-label">Content *</label>
        <textarea class="form-control" id="postContent" rows="4" placeholder="Describe your item, where you lost it, when, and any identifying details…"></textarea>
      </div>
      <div class="form-group">
        <label class="form-label">Optional Flair / Tag</label>
        <input type="text" class="form-control" id="postFlair" placeholder="e.g. MLH 306, Electronics, Urgent" maxlength="50"/>
      </div>

      <div style="padding:11px 13px;background:var(--warn-bg);border:1px solid var(--warn-border);border-radius:9px;font-size:.76rem;color:var(--text-primary);margin-bottom:1rem;">
        <i class="fa-solid fa-triangle-exclamation" style="color:var(--warn);margin-right:5px;"></i>
        Do not share personal contact details publicly. Use the S.P.O.T.-IT claim system for official item recovery.
      </div>

      <div class="modal-actions">
        <button class="modal-btn dismiss" onclick="closeModal('composePostModal')"><i class="fa-solid fa-xmark"></i> Cancel</button>
        <button class="modal-btn recover" onclick="submitPost()">
          <i class="fa-solid fa-paper-plane"></i> Publish Post
        </button>
      </div>
    </div>
  </div>
</div>

<div class="toast-stack" id="toastStack"></div>
<script src="../assets/js/spotit.js"></script>
<script>
startLiveClock('liveClock');

let currentSort = 'hot';

/* ── Sort ── */
function setSort(mode, btn) {
  currentSort = mode;
  document.querySelectorAll('.sort-tab').forEach(t => t.classList.remove('active'));
  btn.classList.add('active');
  sortPosts(mode);
}

function sortPosts(mode) {
  const feed = document.getElementById('postFeed');
  if (!feed) return;
  const cards = Array.from(feed.querySelectorAll('.forum-post-card'));
  cards.sort((a, b) => {
    if (mode === 'hot' || mode === 'top') {
      const sa = parseInt(a.querySelector('.vote-score')?.textContent) || 0;
      const sb = parseInt(b.querySelector('.vote-score')?.textContent) || 0;
      return sb - sa;
    }
    if (mode === 'unanswered') {
      const ca = parseInt(a.dataset.comments) || 0;
      const cb = parseInt(b.dataset.comments) || 0;
      return ca - cb;
    }
    // newest — reverse current order (DOM already newest-first from PHP)
    return 0;
  });
  const empty = document.getElementById('forumEmpty');
  cards.forEach(c => feed.insertBefore(c, empty));
}

/* ── Filter ── */
function filterPosts() {
  const q   = (document.getElementById('forumSearch')?.value || '').toLowerCase();
  const cat = document.getElementById('forumCategory')?.value || '';
  const cards = document.querySelectorAll('.forum-post-card');
  let visible = 0;
  cards.forEach(card => {
    const matchQ   = !q   || card.dataset.title?.includes(q);
    const matchCat = !cat || card.dataset.category === cat;
    card.style.display = (matchQ && matchCat) ? '' : 'none';
    if (matchQ && matchCat) visible++;
  });
  const empty = document.getElementById('forumEmpty');
  if (empty) empty.style.display = visible === 0 ? '' : 'none';
}

/* ── Voting ── */
async function castVote(type, id, dir, btn) {
  const container = btn.closest(
    type === 'post' ? '.vote-col, .full-vote-col' : '.comment-vote-col'
  ) || btn.parentElement;
  const upBtn   = container.querySelector('.upvote');
  const downBtn = container.querySelector('.downvote');
  const scoreEl = container.querySelector('.vote-score, .comment-score');
  if (!scoreEl) return;

  // Optimistic UI update
  const wasActive  = btn.classList.contains('active');
  const voteType   = dir === 'up' ? 1 : -1;
  let score = parseInt(scoreEl.textContent) || 0;

  upBtn?.classList.remove('active');
  downBtn?.classList.remove('active');

  if (!wasActive) {
    btn.classList.add('active');
    score += voteType;
  } else {
    score -= voteType; // undo
  }
  scoreEl.textContent = score;
  scoreEl.className = scoreEl.className.replace(/positive|negative/g, '').trim();
  if (score > 0) scoreEl.classList.add('positive');
  else if (score < 0) scoreEl.classList.add('negative');

  // Backend call
  const fd = new FormData();
  fd.append('target_type', type);
  fd.append('target_id',   id);
  fd.append('vote_type',   voteType);

  const data = await spotitFetch('../auth/forum_vote.php', { method:'POST', body: fd });
  if (data && data.success) {
    // Sync with server's confirmed counts
    scoreEl.textContent = data.score;
    scoreEl.className   = scoreEl.className.replace(/positive|negative/g, '').trim();
    if (data.score > 0) scoreEl.classList.add('positive');
    else if (data.score < 0) scoreEl.classList.add('negative');
    if (data.my_vote === 1)  upBtn?.classList.add('active');
    if (data.my_vote === -1) downBtn?.classList.add('active');
    if (data.my_vote === 0)  { upBtn?.classList.remove('active'); downBtn?.classList.remove('active'); }
  }
}

/* ── Comments ── */
function toggleReplyBox(commentId) {
  const box = document.getElementById('reply-box-' + commentId);
  if (!box) return;
  box.classList.toggle('open');
  if (box.classList.contains('open')) box.querySelector('textarea').focus();
}

function collapseThread(commentId) {
  const replies = document.getElementById('replies-' + commentId);
  if (replies) { replies.style.display = replies.style.display === 'none' ? '' : 'none'; }
}

function focusCommentBox() {
  const box = document.getElementById('mainCommentBox');
  if (box) { box.scrollIntoView({behavior:'smooth',block:'center'}); box.focus(); }
}

async function submitComment(parentId) {
  const box = parentId
    ? document.getElementById('reply-box-' + parentId)?.querySelector('textarea')
    : document.getElementById('mainCommentBox');
  const text = box?.value.trim();
  if (!text) { showToast('error','Please write something before submitting.'); return; }

  // Determine post_id from URL
  const urlParams = new URLSearchParams(window.location.search);
  const postId    = parseInt(urlParams.get('post')) || 0;

  const fd = new FormData();
  fd.append('post_id',  postId);
  fd.append('content',  text);
  if (parentId) fd.append('parent_comment_id', parentId);

  const data = await spotitFetch('../auth/forum_submit_comment.php', { method:'POST', body: fd });

  if (data && data.success) {
    box.value = '';
    if (parentId) {
      const rb = document.getElementById('reply-box-' + parentId);
      if (rb) rb.classList.remove('open');
    }
    showToast('success', 'Comment posted!');
    // Reload page to show new comment in thread
    setTimeout(() => location.reload(), 600);
  } else {
    showToast('error', data?.message || 'Failed to post comment.');
  }
}

function removeComment(id) {
  if (confirm('Remove this comment?')) {
    const el = document.getElementById('comment-' + id);
    if (el) el.querySelector('.comment-text').textContent = '[Removed by administrator]';
    showToast('warn','Comment removed.');
  }
}

/* ── Post compose ── */
function selectPostCategory(cat, el) {
  document.querySelectorAll('.compose-cat-btn').forEach(b => b.classList.remove('active'));
  el.classList.add('active');
  document.getElementById('postCategory').value = cat;
}

async function submitPost() {
  const title    = document.getElementById('postTitle').value.trim();
  const content  = document.getElementById('postContent').value.trim();
  const category = document.getElementById('postCategory').value;
  const flair    = document.getElementById('postFlair').value.trim();
  if (!title || !content) { showToast('error','Please fill in the title and content.'); return; }

  const btn = document.querySelector('#composePostModal .modal-btn.recover');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Posting…'; }

  const fd = new FormData();
  fd.append('title',    title);
  fd.append('content',  content);
  fd.append('category', category);
  if (flair) fd.append('flair', flair);

  // Detection link if pre-filled
  const detText = document.getElementById('detectionLinkText')?.textContent;
  if (detText && detText !== '—') {
    const params = new URLSearchParams(window.location.search);
    if (params.get('room'))  fd.append('detection_room', params.get('room'));
    if (params.get('item'))  fd.append('detection_item', params.get('item'));
    if (category === 'detection_thread') fd.append('is_auto_generated', '1');
  }

  const data = await spotitFetch('../auth/forum_submit_post.php', { method:'POST', body: fd });
  if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Publish Post'; }

  if (data && data.success) {
    closeModal('composePostModal');
    showToast('success', 'Post published! Redirecting…');
    ['postTitle','postContent','postFlair'].forEach(id => { const el=document.getElementById(id); if(el) el.value=''; });
    setTimeout(() => { window.location.href = data.redirect; }, 800);
  } else {
    showToast('error', data?.message || 'Failed to publish post. Please try again.');
  }
}

function sharePost(id) { showToast('info','Link copied to clipboard.'); }
function savePost(id)  { showToast('success','Post saved to your bookmarks.'); }
function removePost(id){ if(confirm('Remove this post?')) showToast('warn','Post removed.'); }
function viewPost(id)  { window.location.href = 'forum.php?view=post&post=' + id; }

/* ── Create post from detection event (called from dashboard) ── */
window.SpotitForum = {
  createFromDetection(detectionId, room, item, timestamp) {
    document.getElementById('postCategory').value = 'detection_thread';
    document.getElementById('postTitle').value = `⚠️ Detection Alert — ${item} possibly missing in ${room}`;
    document.getElementById('postContent').value =
      `The S.P.O.T.-IT system has detected a possible item deviation in **${room}**.\n\n` +
      `**Detected:** ${item}\n**Room:** ${room}\n**Time:** ${timestamp}\n\n` +
      `Has this item been claimed or returned? Lab staff have been notified.`;
    const banner = document.getElementById('detectionLinkBanner');
    document.getElementById('detectionLinkText').textContent = `${item} in ${room} at ${timestamp}`;
    if (banner) banner.style.display = 'flex';
    selectPostCategory('detection_thread', document.querySelector('[data-cat="detection_thread"]') || document.querySelector('.compose-cat-btn'));
    openModal('composePostModal');
  }
};

// Auto-open compose modal if redirected from dashboard with prefill params
document.addEventListener('DOMContentLoaded', function () {
  const params = new URLSearchParams(window.location.search);
  if (params.get('prefill') === '1') {
    const room = params.get('room') || '';
    const item = params.get('item') || '';
    const time = params.get('time') || new Date().toLocaleString('en-PH');
    setTimeout(() => window.SpotitForum.createFromDetection(null, room, item, time), 600);
  }
});
</script>
</body>
</html>
