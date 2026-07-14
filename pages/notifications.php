<?php
/**
 * S.P.O.T.-IT — Notifications Page
 * pages/notifications.php
 *
 * Full notification history for the current user.
 * Grouped by date, filterable by category, paginated.
 * MICROSERVICES: No SQL. All data from auth/get_notifications.php via JS.
 */
require_once __DIR__ . '/../config/env.php';
$active_page = 'notifications';
$user_role   = $_SESSION['user_role'] ?? 'student';
$user_name   = $_SESSION['user_name'] ?? 'User';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Notifications — S.P.O.T.-IT</title>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/css/notifications.css"/>
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
      <button class="tb-btn tb-hamburger" id="hamburgerBtn" onclick="toggleSidebar()">
        <i class="fa-solid fa-bars"></i>
      </button>
      <div>
        <span class="topbar-title">Notifications</span>
        <span class="topbar-sub"> — Your activity feed</span>
      </div>
      <div class="topbar-right">
        <span style="font-family:var(--font-mono);font-size:.7rem;color:var(--text-dim);" id="liveClock"></span>
        <button class="tb-btn" onclick="toggleTheme()"><i class="fa-solid fa-circle-half-stroke"></i></button>
      </div>
    </div>

    <div class="page-body">

      <!-- Page header -->
      <div class="notif-page-header">
        <div>
          <h2><i class="fa-solid fa-bell" style="color:var(--green-main);margin-right:8px;"></i>Notifications</h2>
          <p style="font-size:.8rem;color:var(--text-muted);font-weight:300;">
            All system alerts, claim updates, and announcements for your account.
          </p>
        </div>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <span class="badge badge-alert" id="pageUnreadBadge" style="display:none;font-size:.7rem;padding:5px 12px;">
            <span class="bdot"></span><span id="pageUnreadCount">0</span> unread
          </span>
          <button class="btn btn-sm" onclick="markAllNotifsRead()">
            <i class="fa-solid fa-check-double"></i> Mark all read
          </button>
        </div>
      </div>

      <!-- Summary stat cards -->
      <div class="stat-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:20px;">
        <div class="stat-card">
          <div class="stat-icon alert"><i class="fa-solid fa-circle-exclamation"></i></div>
          <div><div class="stat-num" id="statUnread">—</div><div class="stat-label">Unread</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon warn"><i class="fa-solid fa-triangle-exclamation"></i></div>
          <div><div class="stat-num" id="statDetections">—</div><div class="stat-label">Detection Alerts</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon ok"><i class="fa-solid fa-box-open"></i></div>
          <div><div class="stat-num" id="statClaims">—</div><div class="stat-label">Claim Updates</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon info"><i class="fa-solid fa-bullhorn"></i></div>
          <div><div class="stat-num" id="statAnnouncements">—</div><div class="stat-label">Announcements</div></div>
        </div>
      </div>

      <!-- Category filter pills -->
      <div class="notif-cat-pills" id="catPills">
        <button class="notif-cat-pill active" data-type="" onclick="setTypeFilter('',this)">
          <i class="fa-solid fa-bell"></i> All
          <span class="pill-n" id="cn-all">—</span>
        </button>
        <button class="notif-cat-pill" data-type="detection" onclick="setTypeFilter('detection',this)">
          <i class="fa-solid fa-video" style="color:var(--alert);"></i> Detection Alerts
          <span class="pill-n" id="cn-detection">—</span>
        </button>
        <button class="notif-cat-pill" data-type="claim" onclick="setTypeFilter('claim',this)">
          <i class="fa-solid fa-hand-holding" style="color:var(--info);"></i> Claims
          <span class="pill-n" id="cn-claim">—</span>
        </button>
        <button class="notif-cat-pill" data-type="new_announcement" onclick="setTypeFilter('new_announcement',this)">
          <i class="fa-solid fa-bullhorn" style="color:var(--green-main);"></i> Announcements
          <span class="pill-n" id="cn-announcement">—</span>
        </button>
        <button class="notif-cat-pill" data-type="item_recovered" onclick="setTypeFilter('item_recovered',this)">
          <i class="fa-solid fa-box-open" style="color:var(--ok);"></i> Recoveries
          <span class="pill-n" id="cn-recovery">—</span>
        </button>
      </div>

      <!-- Notifications list -->
      <div class="notif-page-card" id="notifPageCard">
        <!-- Loading shimmer -->
        <div id="pageLoader" style="padding:16px;">
          <?php for($i=0;$i<5;$i++): ?>
          <div class="notif-loader-row">
            <div class="sk" style="width:36px;height:36px;border-radius:10px;flex-shrink:0;"></div>
            <div style="flex:1;display:flex;flex-direction:column;gap:7px;">
              <div class="sk" style="width:65%;height:11px;border-radius:5px;"></div>
              <div class="sk" style="width:90%;height:9px;border-radius:5px;"></div>
              <div class="sk" style="width:35%;height:8px;border-radius:5px;"></div>
            </div>
          </div>
          <?php endfor; ?>
        </div>

        <!-- Populated by JS -->
        <div id="notifPageList" style="display:none;"></div>

        <!-- Empty state -->
        <div class="notif-empty" id="notifPageEmpty" style="display:none;">
          <i class="fa-solid fa-bell-slash"></i>
          <h4>No notifications</h4>
          <p>Nothing here yet — you're all caught up.</p>
        </div>
      </div>

      <!-- Pagination -->
      <div id="notifPagination" style="display:flex;align-items:center;justify-content:center;gap:10px;margin-top:18px;display:none;">
        <button class="btn btn-sm" id="prevBtn" onclick="changePage(-1)" disabled>
          <i class="fa-solid fa-chevron-left"></i> Previous
        </button>
        <span style="font-family:var(--font-mono);font-size:.76rem;color:var(--text-dim);" id="pageInfo">Page 1</span>
        <button class="btn btn-sm" id="nextBtn" onclick="changePage(1)">
          Next <i class="fa-solid fa-chevron-right"></i>
        </button>
      </div>

    </div><!-- /page-body -->
  </div><!-- /main-content -->
</div><!-- /app-shell -->

<div class="toast-stack" id="toastStack"></div>
<script src="../assets/js/spotit.js"></script>
<script>
startLiveClock('liveClock');

/* ── State ── */
let currentPage   = 0;
const PAGE_SIZE   = 20;
let currentFilter = '';
let totalCount    = 0;

/* ── Category type groups (for pill counting) ── */
const TYPE_GROUPS = {
  detection:   ['potential_lost','confirmed_missing','auto_escalation','detection_verified','detection_rejected'],
  claim:       ['new_claim','claim_approved','claim_rejected','claim_completed'],
  announcement:['new_announcement'],
  recovery:    ['item_recovered'],
};

/* ── Load on page open ── */
document.addEventListener('DOMContentLoaded', () => loadPage());

async function loadPage() {
  document.getElementById('pageLoader').style.display = '';
  document.getElementById('notifPageList').style.display = 'none';
  document.getElementById('notifPageEmpty').style.display = 'none';

  // Build URL — type filter maps pill group to multiple types OR single type
  const typeParam = _buildTypeParam(currentFilter);
  const url = `../auth/get_notifications.php?limit=${PAGE_SIZE}&offset=${currentPage * PAGE_SIZE}${typeParam}`;
  const data = await spotitFetch(url);

  document.getElementById('pageLoader').style.display = 'none';

  if (!data || !data.success) {
    showToast('error', 'Failed to load notifications.');
    return;
  }

  totalCount = data.total || 0;

  // Update stat cards and badges
  const unread = data.unread_count || 0;
  document.getElementById('statUnread').textContent = unread;
  document.getElementById('pageUnreadBadge').style.display = unread > 0 ? '' : 'none';
  document.getElementById('pageUnreadCount').textContent   = unread;

  // Count by category group
  const allData = await spotitFetch(`../auth/get_notifications.php?limit=200`);
  if (allData && allData.notifications) {
    _updatePillCounts(allData.notifications);
    _updateStatCards(allData.notifications);
  }

  // Render list grouped by date
  if (!data.notifications.length) {
    document.getElementById('notifPageEmpty').style.display = '';
    document.getElementById('notifPagination').style.display = 'none';
    return;
  }

  document.getElementById('notifPageList').style.display = '';
  document.getElementById('notifPageList').innerHTML = _renderGrouped(data.notifications);

  // Pagination
  const totalPages = Math.ceil(totalCount / PAGE_SIZE);
  const pagEl = document.getElementById('notifPagination');
  pagEl.style.display = totalPages > 1 ? 'flex' : 'none';
  document.getElementById('pageInfo').textContent  = `Page ${currentPage + 1} of ${totalPages}`;
  document.getElementById('prevBtn').disabled = currentPage === 0;
  document.getElementById('nextBtn').disabled = currentPage >= totalPages - 1;
}

function _buildTypeParam(filter) {
  if (!filter) return '';
  const group = TYPE_GROUPS[filter];
  if (group) return '&type=' + encodeURIComponent(group.join(','));
  return '&type=' + encodeURIComponent(filter);
}

function _renderGrouped(notifications) {
  const groups = {};
  notifications.forEach(n => {
    const date = new Date(n.created_at.replace(' ', 'T'));
    const today     = new Date(); today.setHours(0,0,0,0);
    const yesterday = new Date(today); yesterday.setDate(today.getDate()-1);
    let label;
    if (date >= today)     label = 'Today';
    else if (date >= yesterday) label = 'Yesterday';
    else label = date.toLocaleDateString('en-PH', { weekday:'long', month:'long', day:'numeric', year:'numeric' });
    (groups[label] = groups[label] || []).push(n);
  });

  return Object.entries(groups).map(([dateLabel, items]) =>
    `<div class="notif-date-divider">${dateLabel}</div>` +
    items.map(n => _renderNotifRow(n)).join('')
  ).join('');
}

function _updatePillCounts(all) {
  const counts = { detection:0, claim:0, announcement:0, recovery:0 };
  all.forEach(n => {
    Object.entries(TYPE_GROUPS).forEach(([group, types]) => {
      if (types.includes(n.type)) counts[group]++;
    });
  });
  document.getElementById('cn-all').textContent          = all.length;
  document.getElementById('cn-detection').textContent    = counts.detection;
  document.getElementById('cn-claim').textContent        = counts.claim;
  document.getElementById('cn-announcement').textContent = counts.announcement;
  document.getElementById('cn-recovery').textContent     = counts.recovery;
}

function _updateStatCards(all) {
  let detCount = 0, claimCount = 0, annCount = 0;
  all.forEach(n => {
    if (TYPE_GROUPS.detection.includes(n.type))    detCount++;
    if (TYPE_GROUPS.claim.includes(n.type))        claimCount++;
    if (TYPE_GROUPS.announcement.includes(n.type)) annCount++;
  });
  document.getElementById('statDetections').textContent   = detCount;
  document.getElementById('statClaims').textContent       = claimCount;
  document.getElementById('statAnnouncements').textContent= annCount;
}

function setTypeFilter(type, btn) {
  currentFilter = type;
  currentPage   = 0;
  document.querySelectorAll('.notif-cat-pill').forEach(p => p.classList.remove('active'));
  btn.classList.add('active');
  loadPage();
}

function changePage(dir) {
  currentPage = Math.max(0, currentPage + dir);
  loadPage();
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

/* Override handleNotifClick to stay on page (just mark read, don't navigate) */
async function handleNotifClick(id, url, el) {
  el.classList.remove('notif-unread');
  el.querySelector('.notif-unread-dot')?.remove();
  await spotitFetch('../auth/mark_notifications_read.php', {
    method: 'POST', body: new URLSearchParams({ notification_id: id }),
  });
  // Update unread count
  const badge = document.getElementById('pageUnreadBadge');
  const cnt   = document.getElementById('pageUnreadCount');
  const stat  = document.getElementById('statUnread');
  const curr  = parseInt(cnt?.textContent) || 0;
  if (curr > 0) {
    const next = curr - 1;
    if (cnt)  cnt.textContent  = next;
    if (stat) stat.textContent = next;
    if (badge) badge.style.display = next > 0 ? '' : 'none';
  }
  // Navigate after short delay if action_url provided
  if (url && url !== '#' && url !== 'undefined') {
    showToast('info', 'Opening…');
    setTimeout(() => { window.location.href = url; }, 600);
  }
}
</script>
</body>
</html>
