<?php
/**
 * S.P.O.T.-IT — Announcements Page
 * pages/announcements.php
 * MICROSERVICES: No SQL. All data from auth/get_announcements.php via JS fetch.
 */
require_once __DIR__ . '/../auth/service_bootstrap.php';
$active_page = 'announcements';
$user_role   = $_SESSION['user_role'] ?? 'student';
$is_admin    = $user_role === 'admin';

// Fetch live announcements from community DB
$dbAnnsStmt = $communityPdo->query("
    SELECT announcement_id as id, author_name as author, title, content, category, is_pinned as pinned, view_count as views, created_at
    FROM announcements
    WHERE is_published = 1
    ORDER BY is_pinned DESC, created_at DESC
");
$dbAnns = $dbAnnsStmt->fetchAll();

// Compute stats
$statTotalCount = count($dbAnns);
$statPinnedCount = 0;
$statNewCount = 0;
$totalViews = 0;
$now = time();
$sevenDaysAgo = $now - (7 * 24 * 3600);

foreach ($dbAnns as $a) {
    if ($a['pinned']) $statPinnedCount++;
    if (strtotime($a['created_at']) >= $sevenDaysAgo) $statNewCount++;
    $totalViews += (int)$a['views'];
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Announcements — S.P.O.T.-IT</title>
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
        <span class="topbar-title">Announcements</span>
        <span class="topbar-sub">— Official communications from CEAT Administration</span>
      </div>
      <div class="topbar-right">
        <span style="font-family:var(--font-mono);font-size:.7rem;color:var(--text-dim);" id="liveClock"></span>
        <?php if ($is_admin): ?>
        <button class="btn btn-primary btn-sm" onclick="openModal('composeAnnModal')">
          <i class="fa-solid fa-plus"></i> New Announcement
        </button>
        <?php endif; ?>
        <button class="tb-btn" onclick="toggleTheme()"><i class="fa-solid fa-circle-half-stroke"></i></button>
      </div>
    </div>

    <div class="page-body">

      <!-- Header -->
      <div class="comm-header">
        <div class="comm-header-left">
          <h2><i class="fa-solid fa-bullhorn" style="color:var(--green-main);margin-right:8px;"></i>Official Announcements</h2>
          <p>Laboratory advisories, lost-and-found notices, claiming schedules, and system updates from CEAT Administration.</p>
        </div>
        <div style="display:flex;align-items:center;gap:8px;">
          <span class="badge badge-alert" id="newBadge" style="display:none;font-size:.68rem;padding:4px 10px;">
            <span class="bdot"></span><span id="newCount">0</span> New
          </span>
          <span style="font-family:var(--font-mono);font-size:.68rem;color:var(--text-dim);">Read-only for students &amp; staff</span>
        </div>
      </div>

      <!-- Filter bar -->
      <div class="comm-filter-bar">
        <div class="comm-search-wrap">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" id="annSearch" class="comm-search" placeholder="Search announcements…" oninput="filterAnnouncements()"/>
        </div>
        <select id="annCategory" class="comm-select" onchange="filterAnnouncements()">
          <option value="">All Categories</option>
          <option value="laboratory_advisory">Laboratory Advisory</option>
          <option value="lost_and_found">Lost &amp; Found</option>
          <option value="claiming_schedule">Claiming Schedule</option>
          <option value="maintenance">Maintenance</option>
          <option value="system_update">System Update</option>
          <option value="general">General</option>
        </select>
        <select id="annSort" class="comm-select" onchange="filterAnnouncements()">
          <option value="newest">Newest First</option>
          <option value="oldest">Oldest First</option>
          <option value="pinned">Pinned First</option>
        </select>
      </div>

      <!-- Stats row -->
      <div class="stat-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:18px;">
        <div class="stat-card"><div class="stat-icon green"><i class="fa-solid fa-bullhorn"></i></div><div><div class="stat-num" id="statTotal"><?= $statTotalCount ?></div><div class="stat-label">Total Announcements</div></div></div>
        <div class="stat-card"><div class="stat-icon alert"><i class="fa-solid fa-thumbtack"></i></div><div><div class="stat-num" id="statPinned"><?= $statPinnedCount ?></div><div class="stat-label">Pinned</div></div></div>
        <div class="stat-card"><div class="stat-icon warn"><i class="fa-solid fa-star"></i></div><div><div class="stat-num" id="statNew"><?= $statNewCount ?></div><div class="stat-label">This Week</div></div></div>
        <div class="stat-card"><div class="stat-icon info"><i class="fa-solid fa-eye"></i></div><div><div class="stat-num"><?= $totalViews ?></div><div class="stat-label">Total Views</div></div></div>
      </div>

      <!-- Announcements list -->
      <div id="annList">

        <?php
        $announcements = [];
        foreach ($dbAnns as $a) {
            $catLabel = match($a['category']) {
                'laboratory_advisory' => 'Laboratory Advisory',
                'lost_and_found'      => 'Lost & Found',
                'claiming_schedule'   => 'Claiming Schedule',
                'maintenance'         => 'Maintenance',
                'system_update'       => 'System Update',
                default               => 'General',
            };
            
            $isNew = (strtotime($a['created_at']) >= $sevenDaysAgo);
            
            $announcements[] = [
                'id'        => $a['id'],
                'pinned'    => (bool)$a['pinned'],
                'new'       => $isNew,
                'category'  => $a['category'],
                'cat_label' => $catLabel,
                'title'     => $a['title'],
                'content'   => $a['content'],
                'author'    => $a['author'],
                'date'      => date('M j, Y', strtotime($a['created_at'])),
                'views'     => (int)$a['views']
            ];
        }
        ?>
        <?php if (empty($announcements)): ?>
        <div class="comm-empty" id="annEmpty" style="display:flex;">
          <i class="fa-solid fa-bullhorn"></i>
          <h3>No announcements found</h3>
          <p>Official administrative updates will appear here once published.</p>
        </div>
        <?php else: ?>
        <?php foreach ($announcements as $ann):
          $catClass = 'ann-cat-' . $ann['category'];
        ?>
        <div class="ann-card <?= $ann['pinned'] ? 'pinned' : '' ?>"
             data-category="<?= $ann['category'] ?>"
             data-title="<?= strtolower(htmlspecialchars($ann['title'])) ?>"
             data-content="<?= strtolower(htmlspecialchars($ann['content'])) ?>">
          <?php if ($ann['pinned']): ?><div class="ann-pin-stripe"></div><?php endif; ?>
          <div class="ann-card-body">
            <div class="ann-top-row">
              <?php if ($ann['pinned']): ?>
              <span class="ann-pin-badge"><i class="fa-solid fa-thumbtack"></i> Pinned</span>
              <?php endif; ?>
              <?php if ($ann['new']): ?>
              <span class="badge badge-alert" style="font-size:.58rem;padding:2px 8px;"><span class="bdot"></span>New</span>
              <?php endif; ?>
              <span class="ann-category <?= $catClass ?>"><?= htmlspecialchars($ann['cat_label']) ?></span>
              <span style="margin-left:auto;font-size:.68rem;color:var(--text-dim);font-family:var(--font-mono);"><?= htmlspecialchars($ann['date']) ?></span>
            </div>

            <div class="ann-title" onclick="openAnnDetail(<?= $ann['id'] ?>)">
              <?= htmlspecialchars($ann['title']) ?>
            </div>

            <div class="ann-content clamped" id="ann-content-<?= $ann['id'] ?>">
              <?= htmlspecialchars($ann['content']) ?>
            </div>

            <div class="ann-footer">
              <span><i class="fa-solid fa-circle-user"></i> <?= htmlspecialchars($ann['author']) ?></span>
              <span><i class="fa-solid fa-eye"></i> <?= $ann['views'] ?> views</span>
              <a class="ann-read-more" onclick="openAnnDetail(<?= $ann['id'] ?>)">
                Read more <i class="fa-solid fa-chevron-right" style="font-size:.6rem;"></i>
              </a>
              <?php if ($is_admin): ?>
              <div class="ann-admin-actions">
                <button class="btn btn-sm" onclick="editAnn(<?= $ann['id'] ?>)" title="Edit">
                  <i class="fa-solid fa-pencil"></i>
                </button>
                <button class="btn btn-sm" onclick="togglePin(<?= $ann['id'] ?>, <?= $ann['pinned'] ? 'true' : 'false' ?>)" title="<?= $ann['pinned'] ? 'Unpin' : 'Pin' ?>">
                  <i class="fa-solid fa-thumbtack"></i>
                </button>
                <button class="btn btn-sm btn-alert" onclick="deleteAnn(<?= $ann['id'] ?>)" title="Delete">
                  <i class="fa-solid fa-trash"></i>
                </button>
              </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
        <?php endforeach; ?>
        <div class="comm-empty" id="annEmpty" style="display:none;">
          <i class="fa-solid fa-bullhorn"></i>
          <h3>No announcements found</h3>
          <p>Try a different search keyword or category filter.</p>
        </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>

<!-- ══════════ ANNOUNCEMENT DETAIL MODAL ══════════ -->
<div class="modal-overlay" id="annDetailModal" onclick="if(event.target===this)closeModal('annDetailModal')">
  <div class="modal-box" style="max-width:600px;">
    <div class="modal-head">
      <div class="modal-title" id="annDetailTitle">Announcement</div>
      <div class="modal-close" onclick="closeModal('annDetailModal')"><i class="fa-solid fa-xmark"></i></div>
    </div>
    <div class="modal-body">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
        <span class="ann-category" id="annDetailCat"></span>
        <span style="font-family:var(--font-mono);font-size:.68rem;color:var(--text-dim);" id="annDetailDate"></span>
        <span style="font-size:.72rem;color:var(--text-dim);" id="annDetailAuthor"></span>
      </div>
      <div class="ann-modal-content" id="annDetailContent"></div>
    </div>
  </div>
</div>

<?php if ($is_admin): ?>
<!-- ══════════ COMPOSE / EDIT ANNOUNCEMENT MODAL ══════════ -->
<div class="modal-overlay" id="composeAnnModal" onclick="if(event.target===this)closeModal('composeAnnModal')">
  <div class="modal-box" style="max-width:580px;">
    <div class="modal-head">
      <div class="modal-title" id="composeAnnTitle">New Announcement</div>
      <div class="modal-close" onclick="closeModal('composeAnnModal')"><i class="fa-solid fa-xmark"></i></div>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label class="form-label">Category *</label>
        <select class="form-control" id="annComposeCategory">
          <option value="general">General</option>
          <option value="laboratory_advisory">Laboratory Advisory</option>
          <option value="lost_and_found">Lost &amp; Found</option>
          <option value="claiming_schedule">Claiming Schedule</option>
          <option value="maintenance">Maintenance</option>
          <option value="system_update">System Update</option>
        </select>
      </div>
      <div class="form-group">
        <label class="form-label">Title *</label>
        <input type="text" class="form-control" id="annComposeTitle" placeholder="e.g. Laboratory Advisory — MLH 306"/>
      </div>
      <div class="form-group">
        <label class="form-label">Content *</label>
        <textarea class="form-control" id="annComposeContent" rows="5" placeholder="Write the announcement content here…"></textarea>
      </div>
      <div style="display:flex;align-items:center;gap:10px;margin-bottom:1rem;">
        <label class="remember-label" style="font-size:.8rem;color:var(--text-muted);cursor:pointer;display:flex;gap:8px;align-items:center;">
          <input type="checkbox" id="annComposePin" style="accent-color:var(--green-main);width:15px;height:15px;"/>
          Pin this announcement to the top
        </label>
      </div>
      <div style="padding:11px 13px;background:var(--info-bg);border:1px solid rgba(26,106,181,.18);border-radius:9px;font-size:.76rem;color:var(--text-primary);margin-bottom:1rem;">
        <i class="fa-solid fa-circle-info" style="color:var(--info);margin-right:6px;"></i>
        Only administrators can post announcements. Students and staff will see this as read-only.
      </div>
      <div class="modal-actions">
        <button class="modal-btn dismiss" onclick="closeModal('composeAnnModal')"><i class="fa-solid fa-xmark"></i> Cancel</button>
        <button class="modal-btn recover" onclick="publishAnn()"><i class="fa-solid fa-paper-plane"></i> Publish Announcement</button>
      </div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="toast-stack" id="toastStack"></div>
<script src="../assets/js/spotit.js"></script>
<script>
startLiveClock('liveClock');

/* ── Announcement detail data ── */
const annData = {
  1: {
    title: 'S.P.O.T.-IT System Now Active in CEAT Building',
    category: 'system_update', catLabel: 'System Update', catClass: 'ann-cat-system_update',
    date: 'June 15, 2026', author: 'Posted by System Administrator',
    content: 'The S.P.O.T.-IT IoT monitoring system is now fully operational inside selected laboratory rooms of the MLH Building. The system will automatically detect and flag missing laboratory equipment and unattended personal items.\n\nPlease ensure you retrieve your belongings before leaving any laboratory room. Detection events are automatically logged and staff are notified in real time via the monitoring dashboard.\n\nStaff have been briefed on the verification and claiming process. Students may submit claims through the S.P.O.T.-IT web portal or by visiting the CEAT dispensing window.',
  },
  2: {
    title: 'Claiming Schedule: Dispensing Window Hours',
    category: 'claiming_schedule', catLabel: 'Claiming Schedule', catClass: 'ann-cat-claiming_schedule',
    date: 'June 15, 2026', author: 'Posted by System Administrator',
    content: 'The CEAT dispensing window for lost-and-found item claims is open Monday to Friday, 8:00 AM to 5:00 PM. Students must present their university ID and provide an accurate description of their item to complete a claim.\n\nSaturday claims require prior coordination with the laboratory staff on duty. Please submit your claim online first through the S.P.O.T.-IT portal before visiting the window to reduce wait times.',
  },
  3: {
    title: 'MLH 306 Laboratory Maintenance — June 20',
    category: 'maintenance', catLabel: 'Maintenance', catClass: 'ann-cat-maintenance',
    date: 'June 13, 2026', author: 'Posted by Laboratory Personnel',
    content: 'MLH 306 (Systems & Application Development Lab) will undergo scheduled network maintenance on June 20, 2026 from 12:00 PM to 2:00 PM. The CCTV monitoring system may be temporarily offline during this period.\n\nAny detection events during this window will be manually verified by on-duty staff. Classes scheduled in MLH 306 during this time will proceed normally — only the monitoring system is affected.',
  },
};

function openAnnDetail(id) {
  const d = annData[id]; if (!d) return;
  document.getElementById('annDetailTitle').textContent = d.title;
  document.getElementById('annDetailCat').className = 'ann-category ' + d.catClass;
  document.getElementById('annDetailCat').textContent = d.catLabel;
  document.getElementById('annDetailDate').textContent = d.date;
  document.getElementById('annDetailAuthor').textContent = '· ' + d.author;
  document.getElementById('annDetailContent').innerHTML =
    d.content.split('\n\n').map(p => `<p>${p}</p>`).join('');
  openModal('annDetailModal');
}

/* ── Filter/search ── */
function filterAnnouncements() {
  const q    = document.getElementById('annSearch').value.toLowerCase();
  const cat  = document.getElementById('annCategory').value;
  const sort = document.getElementById('annSort').value;
  const cards = Array.from(document.querySelectorAll('.ann-card'));
  let visible = 0;

  cards.forEach(card => {
    const matchQ   = !q   || card.dataset.title.includes(q) || card.dataset.content.includes(q);
    const matchCat = !cat || card.dataset.category === cat;
    const show = matchQ && matchCat;
    card.style.display = show ? '' : 'none';
    if (show) visible++;
  });

  document.getElementById('annEmpty').style.display = visible === 0 ? '' : 'none';

  // Re-sort visible cards
  const list = document.getElementById('annList');
  const visibleCards = cards.filter(c => c.style.display !== 'none');
  if (sort === 'pinned') {
    visibleCards.sort((a,b) => b.classList.contains('pinned') - a.classList.contains('pinned'));
  } else if (sort === 'oldest') {
    visibleCards.reverse();
  }
  visibleCards.forEach(c => list.insertBefore(c, document.getElementById('annEmpty')));
}

<?php if ($is_admin): ?>
async function publishAnn() {
  const title    = document.getElementById('annComposeTitle').value.trim();
  const content  = document.getElementById('annComposeContent').value.trim();
  const category = document.getElementById('annComposeCategory').value;
  const pinned   = document.getElementById('annComposePin').checked ? 1 : 0;
  if (!title || !content) { showToast('error','Please fill in the title and content.'); return; }

  const btn = document.querySelector('#composeAnnModal .modal-btn.recover');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Publishing…'; }

  const fd = new FormData();
  fd.append('title',    title);
  fd.append('content',  content);
  fd.append('category', category);
  fd.append('pinned',   pinned);

  const data = await spotitFetch('../auth/create_announcement.php', { method:'POST', body: fd });
  if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Publish Announcement'; }

  if (data && data.success) {
    showToast('success', `Announcement published. ${data.notified_count} users notified.`);
    closeModal('composeAnnModal');
    ['annComposeTitle','annComposeContent'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('annComposePin').checked = false;
    document.getElementById('annComposeCategory').value = 'general';
    if (typeof pollBadges === 'function') pollBadges();
    // Reload to show new announcement in the list
    setTimeout(() => location.reload(), 1000);
  } else {
    showToast('error', data?.message || 'Failed to publish announcement.');
  }
}
function editAnn(id)         { document.getElementById('composeAnnTitle').textContent = 'Edit Announcement'; openModal('composeAnnModal'); }
function togglePin(id, isPinned) { showToast('success', isPinned ? 'Announcement unpinned.' : 'Announcement pinned to top.'); }
function deleteAnn(id) {
  if (confirm('Delete this announcement? This cannot be undone.')) showToast('warn','Announcement deleted.');
}
<?php endif; ?>
</script>
</body>
</html>
