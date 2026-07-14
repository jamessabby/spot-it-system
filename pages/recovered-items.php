<?php
/**
 * S.P.O.T.-IT — Recovered Items
 * pages/recovered-items.php
 * MICROSERVICES: No SQL. All data from auth/ handlers via JS.
 */
require_once __DIR__ . '/../config/env.php';
$active_page = 'recovered_items';
$user_role   = $_SESSION['user_role'] ?? 'student';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Recovered Items — S.P.O.T.-IT</title>
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
    <div class="topbar">
      <button class="tb-btn tb-hamburger" id="hamburgerBtn" onclick="toggleSidebar()">
        <i class="fa-solid fa-bars"></i>
      </button>
      <div>
        <span class="topbar-title">Recovered Items</span>
      </div>
      <div class="topbar-right">
        <span style="font-family:var(--font-mono);font-size:.7rem;color:var(--text-dim);" id="liveClock"></span>
        <button class="tb-btn notif-bell-wrap" onclick="toggleNotifPanel()" title="Notifications">
          <i class="fa-solid fa-bell"></i>
          <div class="notif-bell-dot" id="notifDot"></div>
        </button>
        <button class="tb-btn" onclick="toggleTheme()"><i class="fa-solid fa-circle-half-stroke"></i></button>
      </div>
    </div>
    <div class="page-body">

      <!-- Page header -->
      <div style="display:flex;align-items:center;gap:14px;margin-bottom:22px;">
        <div style="width:48px;height:48px;border-radius:12px;background:var(--green-pale);
                    color:var(--green-main);display:flex;align-items:center;justify-content:center;font-size:1.1rem;">
          <i class="fa-solid fa-boxes-stacked"></i>
        </div>
        <div>
          <div style="font-family:var(--font-display);font-size:1.05rem;font-weight:800;color:var(--text-primary);">Recovered Items</div>
          <div style="font-size:.78rem;color:var(--text-muted);font-weight:300;">CEAT Building · S.P.O.T.-IT</div>
        </div>
      </div>

      
      <div class="stat-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:18px;">
        <div class="stat-card"><div class="stat-icon ok"><i class="fa-solid fa-box-open"></i></div><div><div class="stat-num" id="statRecovered">—</div><div class="stat-label">Total Recovered</div></div></div>
        <div class="stat-card"><div class="stat-icon warn"><i class="fa-solid fa-clock"></i></div><div><div class="stat-num" id="statPending">—</div><div class="stat-label">Pending Claim</div></div></div>
        <div class="stat-card"><div class="stat-icon info"><i class="fa-solid fa-hand-holding"></i></div><div><div class="stat-num" id="statClaimed">—</div><div class="stat-label">Claimed</div></div></div>
        <div class="stat-card"><div class="stat-icon alert"><i class="fa-solid fa-trash"></i></div><div><div class="stat-num" id="statDisposed">—</div><div class="stat-label">Disposed</div></div></div>
      </div>
      <div class="card">
        <div class="card-head">
          <div class="card-title"><i class="fa-solid fa-boxes-stacked"></i> Recovered Items Register</div>
          <div style="display:flex;gap:6px;">
            <input type="text" class="form-control" id="riSearch" placeholder="Search items…" style="width:200px;font-size:.8rem;" oninput="filterItems()"/>
            <select class="form-control" id="riStatus" style="width:140px;font-size:.8rem;" onchange="filterItems()">
              <option value="">All Status</option>
              <option value="recovered">Recovered</option>
              <option value="pending_claim">Pending Claim</option>
              <option value="claimed">Claimed</option>
            </select>
          </div>
        </div>
        <div id="riList" style="padding:2rem;text-align:center;color:var(--text-dim);font-size:.8rem;">
          Loading recovered items…
        </div>
      </div>

    </div>
  </div>
</div>
<!-- Notification Panel -->
<div class="notif-panel" id="notifPanel">
  <div class="notif-panel-head">
    <div class="notif-panel-title"><i class="fa-solid fa-bell"></i> Notifications
      <span class="notif-count-badge" id="notifCount">0</span></div>
    <div class="notif-panel-actions">
      <button class="btn btn-sm" onclick="markAllNotifsRead()" style="font-size:.66rem;"><i class="fa-solid fa-check-double"></i> All read</button>
      <a href="notifications.php" class="btn btn-sm" style="font-size:.66rem;"><i class="fa-solid fa-expand"></i> View all</a>
      <button class="tb-btn" onclick="toggleNotifPanel()" style="width:28px;height:28px;"><i class="fa-solid fa-xmark"></i></button>
    </div>
  </div>
  <div class="notif-panel-body" id="notifList">
    <div class="notif-empty" id="notifEmpty"><i class="fa-solid fa-bell-slash"></i><h4>All caught up!</h4></div>
  </div>
  <div class="notif-panel-foot">
    <a href="notifications.php" style="font-family:var(--font-display);font-size:.7rem;font-weight:700;color:var(--green-main);text-decoration:none;margin-left:auto;">View full history <i class="fa-solid fa-arrow-right" style="font-size:.6rem;"></i></a>
  </div>
</div>
<div class="notif-backdrop" id="notifBackdrop" onclick="toggleNotifPanel()"></div>
<div class="toast-stack" id="toastStack"></div>
<script src="../assets/js/spotit.js"></script>
<script>
startLiveClock('liveClock');
function toggleNotifPanel() {
  const p = document.getElementById('notifPanel');
  const b = document.getElementById('notifBackdrop');
  window._notifPanelOpen = !window._notifPanelOpen;
  p.classList.toggle('open', window._notifPanelOpen);
  b.classList.toggle('open', window._notifPanelOpen);
  if (window._notifPanelOpen) loadNotifPanel();
}
</script>
</body>
</html>
