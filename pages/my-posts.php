<?php
/**
 * S.P.O.T.-IT — My Posts Page
 * pages/my-posts.php
 * Shows the current user's submitted reports, posts, and activity.
 * MICROSERVICES: No SQL. Data from auth/get_posts.php via JS.
 */
require_once __DIR__ . '/../auth/service_bootstrap.php';
$active_page = 'posts';
$user_role   = $_SESSION['user_role'] ?? 'student';
$uname       = $_SESSION['user_name'] ?? 'User';
$userId      = $_SESSION['user_id'] ?? 0;

// Fetch user's forum posts
$userPostsStmt = $communityPdo->prepare("
    SELECT post_id, title, content, category, flair, created_at, upvotes, comment_count, is_locked
    FROM forum_posts
    WHERE user_id = ? AND is_removed = 0
    ORDER BY created_at DESC
");
$userPostsStmt->execute([$userId]);
$userPosts = $userPostsStmt->fetchAll();

// Count resolved claims for user
$resolvedClaimsStmt = $lfPdo->prepare("SELECT COUNT(*) FROM claims WHERE user_id = ? AND status = 'claimed'");
$resolvedClaimsStmt->execute([$userId]);
$resolvedClaims = (int)$resolvedClaimsStmt->fetchColumn();

// Count pending claims for user
$pendingClaimsStmt = $lfPdo->prepare("SELECT COUNT(*) FROM claims WHERE user_id = ? AND status = 'pending'");
$pendingClaimsStmt->execute([$userId]);
$pendingClaimsCount = (int)$pendingClaimsStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>My Posts — S.P.O.T.-IT</title>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/css/skeleton.css"/>
  <link rel="stylesheet" href="../assets/css/onboarding.css"/>
  <script>(function(){document.documentElement.setAttribute('data-theme',localStorage.getItem('spotit_theme')||'light')})();</script>
</head>
<body data-skeleton="dashboard">
<script src="../assets/js/skeleton.js"></script>
<div class="app-shell">
  <?php include '_sidebar.php'; ?>
  <div class="main-content">
    <div class="topbar">
      <button class="tb-btn tb-hamburger" id="hamburgerBtn" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
      <div><span class="topbar-title">My Posts &amp; Activity</span><span class="topbar-sub">— Your submissions and reports</span></div>
      <div class="topbar-right">
        <span style="font-family:var(--font-mono);font-size:.7rem;color:var(--text-dim);" id="liveClock"></span>
        <button class="tb-btn" onclick="openModal('newPostModal')"><i class="fa-solid fa-plus"></i></button>
        <button class="tb-btn" onclick="toggleTheme()"><i class="fa-solid fa-circle-half-stroke"></i></button>
      </div>
    </div>

    <div class="page-body">
        <div class="stat-card"><div class="stat-icon green"><i class="fa-solid fa-pen-to-square"></i></div><div><div class="stat-num"><?= count($userPosts) ?></div><div class="stat-label">Total Posts</div></div></div>
        <div class="stat-card"><div class="stat-icon ok"><i class="fa-solid fa-circle-check"></i></div><div><div class="stat-num"><?= $resolvedClaims ?></div><div class="stat-label">Claims Resolved</div></div></div>
        <div class="stat-card"><div class="stat-icon warn"><i class="fa-solid fa-clock"></i></div><div><div class="stat-num"><?= $pendingClaimsCount ?></div><div class="stat-label">Pending</div></div></div>
        <div class="stat-card"><div class="stat-icon info"><i class="fa-solid fa-eye"></i></div><div><div class="stat-num">0</div><div class="stat-label">Total Views</div></div></div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 280px;gap:18px;align-items:start;">
        <div style="display:flex;flex-direction:column;gap:18px;">

          <!-- Post type tabs -->
          <div class="card">
            <div class="card-head">
              <div class="card-title"><i class="fa-solid fa-pen-to-square"></i> My Submitted Posts</div>
              <button class="btn btn-primary btn-sm" onclick="openModal('newPostModal')">
                <i class="fa-solid fa-plus"></i> New Post
              </button>
            </div>
             <?php
             $cLost = 0; $cFound = 0; $cClaim = 0;
             foreach ($userPosts as $up) {
                 if ($up['category'] === 'lost_and_found') $cLost++;
                 elseif ($up['category'] === 'found_item') $cFound++;
                 else $cClaim++;
             }
             ?>
             <div class="filter-tabs">
               <div class="filter-tab active" onclick="setFilterTab(this)">All (<?= count($userPosts) ?>)</div>
               <div class="filter-tab" onclick="setFilterTab(this)">Lost Reports (<?= $cLost ?>)</div>
               <div class="filter-tab" onclick="setFilterTab(this)">Found Reports (<?= $cFound ?>)</div>
               <div class="filter-tab" onclick="setFilterTab(this)">Claim Requests (<?= $cClaim ?>)</div>
             </div>

             <?php
             $posts = [];
             foreach ($userPosts as $up) {
                 $type = $up['category'];
                 $icon = 'fa-pen-to-square';
                 $color = 'info';
                 
                 if ($type === 'lost_and_found') {
                     $type = 'lost';
                     $icon = 'fa-circle-question';
                     $color = 'alert';
                 } elseif ($type === 'found_item') {
                     $type = 'found';
                     $icon = 'fa-circle-check';
                     $color = 'ok';
                 }
                 
                 $posts[] = [
                     'id'         => $up['post_id'],
                     'type'       => $type,
                     'icon'       => $icon,
                     'type_color' => $color,
                     'title'      => $up['title'],
                     'desc'       => $up['content'],
                     'room'       => $up['flair'] ?? 'General',
                     'date'       => date('F j, Y', strtotime($up['created_at'])),
                     'views'      => 0,
                     'status'     => $up['is_locked'] ? 'Locked' : 'Active',
                     'st_cls'     => $up['is_locked'] ? 'est-dismissed' : 'est-pending',
                     'match'      => null
                 ];
             }
             ?>
             <?php if (empty($posts)): ?>
             <div class="p-4 text-center" style="color:var(--text-dim);font-size:.82rem;">You have not submitted any posts yet.</div>
             <?php else: ?>
             <?php foreach ($posts as $p): ?>
             <div style="padding:16px 18px;border-bottom:1px solid var(--border);">
               <div style="display:flex;align-items:flex-start;gap:12px;">
                 <!-- Type icon -->
                 <div class="stat-icon <?= $p['type_color'] ?>" style="width:38px;height:38px;border-radius:9px;flex-shrink:0;font-size:.82rem;">
                   <i class="fa-solid <?= $p['icon'] ?>"></i>
                 </div>
                 <div style="flex:1;min-width:0;">
                   <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap;">
                     <span style="font-family:var(--font-display);font-size:.88rem;font-weight:700;color:var(--text-primary);"><?= htmlspecialchars($p['title']) ?></span>
                     <span class="badge badge-<?= $p['type_color'] ?>"><span class="bdot"></span><?= ucfirst($p['type']) ?></span>
                   </div>
                   <p style="font-size:.79rem;color:var(--text-muted);line-height:1.55;margin-bottom:8px;"><?= htmlspecialchars($p['desc']) ?></p>

                   <?php if ($p['match']): ?>
                   <div style="display:flex;align-items:center;gap:7px;padding:8px 10px;background:var(--ok-bg);border:1px solid var(--ok-border);border-radius:7px;font-size:.74rem;color:var(--text-primary);margin-bottom:8px;">
                     <i class="fa-solid fa-link" style="color:var(--ok);flex-shrink:0;"></i>
                     <?= htmlspecialchars($p['match']) ?>
                   </div>
                   <?php endif; ?>

                   <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                     <span class="col-mono" style="font-size:.64rem;"><i class="fa-solid fa-door-open" style="margin-right:3px;"></i><?= htmlspecialchars($p['room']) ?></span>
                     <span class="col-mono" style="font-size:.64rem;"><i class="fa-solid fa-clock" style="margin-right:3px;"></i><?= htmlspecialchars($p['date']) ?></span>
                     <span class="col-mono" style="font-size:.64rem;"><i class="fa-solid fa-eye" style="margin-right:3px;"></i><?= $p['views'] ?> views</span>
                     <span class="event-status-tag <?= $p['st_cls'] ?>" style="margin-left:auto;"><?= htmlspecialchars($p['status']) ?></span>
                   </div>
                 </div>
                 <div style="display:flex;flex-direction:column;gap:5px;flex-shrink:0;">
                   <button class="btn btn-sm" onclick="editPost()"><i class="fa-solid fa-pencil"></i></button>
                   <button class="btn btn-sm" onclick="deletePost(this)"><i class="fa-solid fa-trash"></i></button>
                 </div>
               </div>
             </div>
             <?php endforeach; ?>
             <?php endif; ?>
           </div>

          <!-- Activity timeline -->
          <div class="card">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-timeline"></i> My Activity Timeline</div></div>
            <?php
            // Fetch claims submitted by this user
            $userClaimsStmt = $lfPdo->prepare("
                SELECT c.claimant_name, r.item_type, c.status, c.submitted_at, c.claimed_at
                FROM claims c
                INNER JOIN recovered_items r ON c.recovery_id = r.recovery_id
                WHERE c.user_id = ?
                ORDER BY c.submitted_at DESC LIMIT 5
            ");
            $userClaimsStmt->execute([$userId]);
            $userClaims = $userClaimsStmt->fetchAll();

            $activity = [];
            // Add user's forum posts to timeline
            foreach ($userPosts as $up) {
                $activity[] = [
                    'color' => 'info',
                    'icon'  => 'fa-pen-to-square',
                    'label' => "Posted to forum: " . htmlspecialchars($up['title']),
                    'time'  => date('F j, Y · H:i', strtotime($up['created_at'])),
                    'timestamp' => strtotime($up['created_at'])
                ];
            }
            // Add claims to timeline
            foreach ($userClaims as $uc) {
                if ($uc['status'] === 'claimed') {
                    $activity[] = [
                        'color' => 'ok',
                        'icon'  => 'fa-circle-check',
                        'label' => "Claim completed — " . htmlspecialchars($uc['item_type']) . " picked up at dispensing window.",
                        'time'  => date('F j, Y · H:i', strtotime($uc['claimed_at'])),
                        'timestamp' => strtotime($uc['claimed_at'])
                    ];
                } else {
                    $activity[] = [
                        'color' => 'warn',
                        'icon'  => 'fa-hand-holding',
                        'label' => "Claim request submitted for " . htmlspecialchars($uc['item_type']) . ".",
                        'time'  => date('F j, Y · H:i', strtotime($uc['submitted_at'])),
                        'timestamp' => strtotime($uc['submitted_at'])
                    ];
                }
            }

            // Sort timeline by timestamp desc
            usort($activity, function($a, $b) {
                return $b['timestamp'] - $a['timestamp'];
            });
            ?>
            <?php if (empty($activity)): ?>
            <div class="p-3 text-center" style="color:var(--text-dim);font-size:.8rem;">No activity logged yet.</div>
            <?php else: ?>
            <?php foreach ($activity as $a): ?>
            <div class="timeline-item">
              <div class="tl-dot <?= $a['color'] ?>"></div>
              <div>
                <div class="tl-label"><i class="fa-solid <?= $a['icon'] ?>" style="margin-right:5px;font-size:.7rem;"></i><?= $a['label'] ?></div>
                <div class="tl-meta"><?= $a['time'] ?></div>
              </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>

        <!-- RIGHT tips -->
        <div style="display:flex;flex-direction:column;gap:16px;">
          <div class="card" style="background:var(--green-pale);border-color:rgba(0,86,49,.18);">
            <div style="padding:16px;">
              <div style="font-family:var(--font-display);font-size:.8rem;font-weight:700;color:var(--text-primary);margin-bottom:.5rem;"><i class="fa-solid fa-lightbulb" style="color:var(--green-main);"></i> Post Tips</div>
              <ul style="font-size:.76rem;color:var(--text-muted);line-height:1.7;padding-left:1.1rem;margin:0;display:flex;flex-direction:column;gap:3px;">
                <li>Include unique markings or characteristics</li>
                <li>Mention the exact room and time if known</li>
                <li>Add your contact for faster resolution</li>
                <li>Check the recovered items thread first</li>
              </ul>
            </div>
          </div>
          <div class="card">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Post History</div></div>
            <div style="padding:12px 14px;">
              <?php $months=[['June 2026',3],['May 2026',0],['April 2026',1]]; foreach($months as $m): ?>
              <div style="display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border);font-size:.78rem;">
                <span style="color:var(--text-muted);"><?= $m[0] ?></span>
                <span style="font-family:var(--font-display);font-weight:700;color:var(--text-primary);"><?= $m[1] ?> posts</span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- New Post Modal -->
<div class="modal-overlay" id="newPostModal" onclick="if(event.target===this)closeModal('newPostModal')">
  <div class="modal-box" style="max-width:500px;">
    <div class="modal-head"><div class="modal-title">Create New Post</div><div class="modal-close" onclick="closeModal('newPostModal')"><i class="fa-solid fa-xmark"></i></div></div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label">Post Type</label>
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;">
          <?php foreach([['lost','fa-circle-question','alert','I Lost Something'],['found','fa-circle-check','ok','I Found Something'],['claim','fa-hand-holding','warn','Claim an Item']] as [$t,$ic,$cl,$lb]): ?>
          <div onclick="selectPostType('<?= $t ?>')" id="pt_<?= $t ?>" class="post-type-btn <?= $t==='lost'?'active':'' ?>" style="text-align:center;padding:12px 8px;border-radius:9px;border:1.5px solid var(--border);cursor:pointer;transition:var(--transition);">
            <i class="fa-solid <?= $ic ?>" style="font-size:1.1rem;margin-bottom:5px;display:block;color:var(--text-dim);"></i>
            <div style="font-family:var(--font-display);font-size:.68rem;font-weight:700;color:var(--text-muted);"><?= $lb ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <input type="hidden" id="postType" value="lost"/>
      </div>
      <div class="form-group">
        <label class="form-label">Post Title</label>
        <input type="text" class="form-control" id="postTitle" placeholder="e.g. Lost: Black Umbrella near MLH 306"/>
      </div>
      <div class="form-group">
        <label class="form-label">Description</label>
        <textarea class="form-control" id="postDesc" rows="3" placeholder="Describe the item — color, brand, unique markings, when and where it was lost or found…"></textarea>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group">
          <label class="form-label">Related Room</label>
          <select class="form-control" id="postRoom">
            <option value="">— Select Room —</option>
            <?php foreach(['MLH 306','MLH 305','MLH 304','MLH 303','MLH 301','MLH 203','MLH 201','Other'] as $r): ?>
            <option><?= $r ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Contact (optional)</label>
          <input type="text" class="form-control" id="postContact" placeholder="09xx-xxx-xxxx"/>
        </div>
      </div>
      <div class="modal-actions">
        <button class="modal-btn dismiss" onclick="closeModal('newPostModal')">Cancel</button>
        <button class="modal-btn recover" onclick="submitPost()"><i class="fa-solid fa-paper-plane"></i> Submit Post</button>
      </div>
    </div>
  </div>
</div>

<div class="toast-stack" id="toastStack"></div>
<script src="../assets/js/spotit.js"></script>
<script>
startLiveClock('liveClock');
function selectPostType(t) {
  document.querySelectorAll('.post-type-btn').forEach(b=>{b.style.borderColor='var(--border)';b.style.background='';});
  const el = document.getElementById('pt_'+t);
  el.style.borderColor = 'var(--green-main)';
  el.style.background  = 'var(--green-pale)';
  document.getElementById('postType').value = t;
}
function submitPost() {
  const title = document.getElementById('postTitle').value.trim();
  const desc  = document.getElementById('postDesc').value.trim();
  if (!title || !desc) { showToast('error','Please fill in the title and description.'); return; }
  closeModal('newPostModal');
  showToast('success','Post submitted successfully and is now visible in the Lost & Found thread.');
}
function editPost()   { showToast('info','Edit post feature coming soon.'); }
function deletePost(btn) {
  if (confirm('Delete this post?')) { btn.closest('div[style]').style.opacity = '.4'; showToast('warn','Post deleted.'); }
}
</script>
</body>
</html>
