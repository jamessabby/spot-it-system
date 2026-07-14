<?php
/**
 * S.P.O.T.-IT — Analytics & Audit
 * pages/analytics.php
 */
require_once __DIR__ . '/../auth/service_bootstrap.php';
$active_page = 'analytics';
$user_role   = $_SESSION['user_role'] ?? 'student';

// Live calculations for Analytics
$activeEventsCount = (int)$monitorPdo->query("SELECT COUNT(*) FROM detections WHERE status IN ('pending', 'potential', 'confirmed_missing') AND is_removed = 0")->fetchColumn();
$resolvedCount = (int)$monitorPdo->query("SELECT COUNT(*) FROM detections WHERE status IN ('dismissed', 'recovered') AND MONTH(updated_at) = MONTH(CURRENT_DATE()) AND YEAR(updated_at) = YEAR(CURRENT_DATE())")->fetchColumn();
$claimsCount = (int)$lfPdo->query("SELECT COUNT(*) FROM claims WHERE MONTH(submitted_at) = MONTH(CURRENT_DATE()) AND YEAR(submitted_at) = YEAR(CURRENT_DATE())")->fetchColumn();
$avgConfidence = $monitorPdo->query("SELECT ROUND(AVG(confidence_score)) FROM detections WHERE confidence_score IS NOT NULL")->fetchColumn();
$avgConfidence = $avgConfidence !== null ? $avgConfidence . '%' : '0%';

// Room counts
$roomCountsStmt = $monitorPdo->query("SELECT room_id, COUNT(*) as count FROM detections GROUP BY room_id ORDER BY count DESC");
$roomCounts = $roomCountsStmt->fetchAll();
$maxCount = 1;
foreach ($roomCounts as $rc) {
    if ($rc['count'] > $maxCount) {
        $maxCount = $rc['count'];
    }
}

// Status Breakdown
$totalDetections = (int)$monitorPdo->query("SELECT COUNT(*) FROM detections")->fetchColumn();
$cMissing = 0; $cRecovered = 0; $cDismissed = 0; $cPending = 0;
if ($totalDetections > 0) {
    $cMissing = round(((int)$monitorPdo->query("SELECT COUNT(*) FROM detections WHERE status = 'confirmed_missing'")->fetchColumn() / $totalDetections) * 100);
    $cRecovered = round(((int)$monitorPdo->query("SELECT COUNT(*) FROM detections WHERE status = 'recovered'")->fetchColumn() / $totalDetections) * 100);
    $cDismissed = round(((int)$monitorPdo->query("SELECT COUNT(*) FROM detections WHERE status = 'dismissed'")->fetchColumn() / $totalDetections) * 100);
    $cPending = round(((int)$monitorPdo->query("SELECT COUNT(*) FROM detections WHERE status IN ('pending', 'potential')")->fetchColumn() / $totalDetections) * 100);
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Analytics & Audit — S.P.O.T.-IT</title>
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
        <span class="topbar-title">Analytics & Audit</span>
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
          <i class="fa-solid fa-chart-line"></i>
        </div>
        <div>
          <div style="font-family:var(--font-display);font-size:1.05rem;font-weight:800;color:var(--text-primary);">Analytics & Audit</div>
          <div style="font-size:.78rem;color:var(--text-muted);font-weight:300;">CEAT Building · S.P.O.T.-IT</div>
        </div>
      </div>

      
      <div class="stat-grid" style="grid-template-columns:repeat(4,1fr);margin-bottom:18px;">
        <div class="stat-card"><div class="stat-icon alert"><i class="fa-solid fa-triangle-exclamation"></i></div><div><div class="stat-num"><?= $activeEventsCount ?></div><div class="stat-label">Active Events</div></div></div>
        <div class="stat-card"><div class="stat-icon ok"><i class="fa-solid fa-circle-check"></i></div><div><div class="stat-num"><?= $resolvedCount ?></div><div class="stat-label">Resolved This Month</div></div></div>
        <div class="stat-card"><div class="stat-icon info"><i class="fa-solid fa-hand-holding"></i></div><div><div class="stat-num"><?= $claimsCount ?></div><div class="stat-label">Claims This Month</div></div></div>
        <div class="stat-card"><div class="stat-icon green"><i class="fa-solid fa-chart-line"></i></div><div><div class="stat-num"><?= $avgConfidence ?></div><div class="stat-label">Avg. Confidence Score</div></div></div>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;">
        <div class="card">
          <div class="card-head"><div class="card-title"><i class="fa-solid fa-chart-bar"></i> Detection Events by Room</div></div>
          <div style="padding:18px;">
            <?php if (empty($roomCounts)): ?>
            <div class="text-center py-4" style="color:var(--text-dim);font-size:.82rem;">No detection logs recorded yet.</div>
            <?php else: ?>
            <?php foreach($roomCounts as $rc):
                $room  = $rc['room_id'];
                $count = (int)$rc['count'];
            ?>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:10px;">
              <span style="font-family:var(--font-mono);font-size:.72rem;color:var(--text-muted);width:60px;"><?= htmlspecialchars($room) ?></span>
              <div style="flex:1;height:18px;background:var(--border);border-radius:4px;overflow:hidden;">
                <div style="height:100%;width:<?= ($count/$maxCount*100) ?>%;background:var(--green-main);border-radius:4px;"></div>
              </div>
              <span style="font-family:var(--font-mono);font-size:.72rem;color:var(--text-muted);width:20px;text-align:right;"><?= $count ?></span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="card">
          <div class="card-head"><div class="card-title"><i class="fa-solid fa-chart-pie"></i> Detection Status Breakdown</div></div>
          <div style="padding:18px;display:flex;flex-direction:column;gap:10px;">
            <?php
            $breakdown = [
              ['Confirmed Missing','alert',$cMissing,'#d0021b'],
              ['Recovered','ok',$cRecovered,'#00a152'],
              ['Dismissed (False Alert)','info',$cDismissed,'#1a6ab5'],
              ['Pending','warn',$cPending,'#e67e00'],
            ];
            foreach ($breakdown as [$lbl,$cls,$pct,$clr]): ?>
            <div>
              <div style="display:flex;justify-content:space-between;margin-bottom:4px;font-size:.76rem;">
                <span style="color:var(--text-muted);"><?= $lbl ?></span>
                <span style="font-family:var(--font-mono);font-weight:700;color:var(--text-primary);"><?= $pct ?>%</span>
              </div>
              <div style="height:8px;background:var(--border);border-radius:100px;overflow:hidden;">
                <div style="height:100%;width:<?= $pct ?>%;background:<?= $clr ?>;border-radius:100px;"></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
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
