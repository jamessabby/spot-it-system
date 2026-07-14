<?php
require_once __DIR__ . '/../auth/service_bootstrap.php';
ms_require_role('staff', 'login.php');
$active_page = 'dashboard'; $user_role = 'staff';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Staff Dashboard — S.P.O.T.-IT</title>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/css/notifications.css"/>
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
      <div><span class="topbar-title">Staff Dashboard</span><span class="topbar-sub">— Event Verification Queue</span></div>
      <div class="live-pill"><div class="live-dot"></div>LIVE</div>
      <div class="topbar-right">
        <span style="font-family:var(--font-mono);font-size:.7rem;color:var(--text-dim);" id="liveClock"></span>
        <button class="tb-btn" onclick="toggleTheme()"><i class="fa-solid fa-circle-half-stroke"></i></button>
        <button class="tb-btn notif-bell-wrap" onclick="toggleNotifPanel()" title="Notifications" style="position:relative;">
          <i class="fa-solid fa-bell"></i>
          <div class="notif-bell-dot" id="notifDotStaff"></div>
        </button>
      </div>
    </div>

    <div class="page-body">
      <div class="stat-grid" id="tourStatGrid">
        <div class="stat-card"><div class="stat-icon alert"><i class="fa-solid fa-circle-minus"></i></div><div><div class="stat-num">3</div><div class="stat-label">Needs Verification</div><div class="stat-delta up">Requires your action</div></div></div>
        <div class="stat-card"><div class="stat-icon warn"><i class="fa-solid fa-clock"></i></div><div><div class="stat-num">2</div><div class="stat-label">Pending Claims</div><div class="stat-delta flat">At claiming station</div></div></div>
        <div class="stat-card"><div class="stat-icon ok"><i class="fa-solid fa-circle-check"></i></div><div><div class="stat-num">6</div><div class="stat-label">Resolved Today</div><div class="stat-delta down">+3 vs yesterday</div></div></div>
        <div class="stat-card"><div class="stat-icon info"><i class="fa-solid fa-inbox"></i></div><div><div class="stat-num">4</div><div class="stat-label">Items in Storage</div><div class="stat-delta flat">Awaiting pickup</div></div></div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 340px;gap:18px;align-items:start;">
        <div style="display:flex;flex-direction:column;gap:18px;">

          <!-- Verification queue -->
          <div class="card" id="tourQueue">
            <div class="card-head">
              <div class="card-title"><i class="fa-solid fa-list-check"></i> Verification Queue — Pending Staff Action</div>
            </div>
            <div class="filter-tabs">
              <div class="filter-tab active" onclick="setFilterTab(this)">All Pending (3)</div>
              <div class="filter-tab" onclick="setFilterTab(this)">Confirmed Missing (1)</div>
              <div class="filter-tab" onclick="setFilterTab(this)">Potentially Lost (2)</div>
            </div>
            <?php
            $queue = [
              ['id'=>'ev1','room'=>'MLH 306','zone'=>'WS-07 Monitor Zone','title'=>'Monitor missing — confirmed 1+ hour. Verify room and secure item.','time'=>'14:03:44','age'=>'1h 12m','dev'=>'−1','devCls'=>'neg','priority'=>'HIGH','pCls'=>'alert','stage'=>'Confirmed Missing'],
              ['id'=>'ev2','room'=>'MLH 305','zone'=>'WS-03 & WS-04 Keyboard','title'=>'2 keyboards undetected. May have been moved or borrowed.','time'=>'14:30:12','age'=>'34m','dev'=>'−2','devCls'=>'neg','priority'=>'MED','pCls'=>'warn','stage'=>'Potentially Lost'],
              ['id'=>'ev3','room'=>'MLH 303','zone'=>'Unregistered — South','title'=>'Unregistered item in south corner. Identify and document.','time'=>'14:53:05','age'=>'12m','dev'=>'+1','devCls'=>'pos','priority'=>'LOW','pCls'=>'info','stage'=>'New Detection'],
            ];
            foreach ($queue as $q): ?>
            <div style="padding:14px 16px;border-bottom:1px solid var(--border);display:flex;gap:14px;align-items:flex-start;">
              <div style="display:flex;flex-direction:column;align-items:center;gap:4px;flex-shrink:0;">
                <span class="badge badge-<?= $q['pCls'] ?>"><?= $q['priority'] ?></span>
                <span class="dev-chip dev-<?= $q['devCls'] ?>"><?= $q['dev'] ?></span>
              </div>
              <div style="flex:1;">
                <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
                  <span style="font-family:var(--font-display);font-size:.82rem;font-weight:700;color:var(--text-primary);"><?= $q['room'] ?></span>
                  <span style="font-size:.72rem;color:var(--text-muted);">· <?= htmlspecialchars($q['zone']) ?></span>
                </div>
                <p style="font-size:.8rem;color:var(--text-muted);margin-bottom:6px;line-height:1.5;"><?= htmlspecialchars($q['title']) ?></p>
                <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                  <span class="col-mono"><?= $q['time'] ?></span>
                  <span style="font-size:.68rem;color:var(--text-dim);">· <?= $q['age'] ?> ago</span>
                  <span class="event-status-tag est-pending"><?= $q['stage'] ?></span>
                </div>
              </div>
              <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0;">
                <button class="btn btn-primary btn-sm" onclick="openVerifyModal('<?= $q['id'] ?>')"><i class="fa-solid fa-eye"></i> Verify</button>
                <button class="btn btn-sm" onclick="quickDismiss('<?= $q['id'] ?>')"><i class="fa-solid fa-ban"></i> Dismiss</button>
              </div>
            </div>
            <?php endforeach; ?>
          </div>

          <!-- Recently resolved -->
          <div class="card">
            <div class="card-head">
              <div class="card-title"><i class="fa-solid fa-circle-check"></i> Recently Resolved</div>
            </div>
            <?php
            $resolved = [
              ['room'=>'MLH 201','title'=>'Keyboard returned by student','time'=>'12:48:05','status'=>'Recovered','stCls'=>'est-recovered'],
              ['room'=>'MLH 304','title'=>'Monitor temporarily moved — dismissed as false positive','time'=>'11:22:14','status'=>'Dismissed','stCls'=>'est-dismissed'],
              ['room'=>'MLH 306','title'=>'Mouse returned after class session','time'=>'10:05:30','status'=>'Recovered','stCls'=>'est-recovered'],
            ];
            foreach ($resolved as $r): ?>
            <div class="event-row" style="cursor:default;">
              <div class="event-thumb"><svg width="52" height="40" style="position:absolute;inset:0;"><polyline points="14,22 22,29 38,14" stroke="#5cffac" stroke-width="2" fill="none" stroke-linecap="round"/></svg></div>
              <div class="event-body">
                <div class="event-tag"><?= $r['room'] ?></div>
                <div class="event-title"><?= htmlspecialchars($r['title']) ?></div>
                <div class="event-meta"><span class="event-time"><?= $r['time'] ?></span><span class="event-status-tag <?= $r['stCls'] ?>"><?= $r['status'] ?></span></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Right: Quick actions + room status -->
        <div style="display:flex;flex-direction:column;gap:18px;">
          <div class="card" id="tourQuickActions">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-bolt"></i> Quick Actions</div></div>
            <div style="padding:14px;display:flex;flex-direction:column;gap:8px;">
              <a href="claiming-station.php" class="btn btn-primary" style="justify-content:center;padding:12px;"><i class="fa-solid fa-hand-holding"></i> Open Claiming Station</a>
              <a href="room-monitor.php" class="btn" style="justify-content:center;padding:12px;"><i class="fa-solid fa-video"></i> View Room Monitor</a>
              <button class="btn" style="justify-content:center;padding:12px;" onclick="showToast('info','Recalibration requires access to the room setup panel.')"><i class="fa-solid fa-rotate"></i> Request Recalibration</button>
              <button class="btn" style="justify-content:center;padding:12px;" onclick="showToast('success','Daily summary report generated.')"><i class="fa-solid fa-download"></i> Export Today's Log</button>
            </div>
          </div>
          <div class="card">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-door-open"></i> My Room Assignments</div></div>
            <?php $myRooms = [['MLH 306','MISSING','alert'],['MLH 305','POTENTIAL','warn'],['MLH 304','NORMAL','ok'],['MLH 303','UNREGISTERED','warn']];
            foreach ($myRooms as $rm): ?>
            <div style="display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--border);">
              <span style="font-family:var(--font-display);font-size:.78rem;font-weight:700;color:var(--text-primary);min-width:60px;"><?= $rm[0] ?></span>
              <span class="badge badge-<?= $rm[2] ?>" style="margin-left:auto;"><span class="bdot"></span><?= $rm[1] ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Verify Modal -->
<div class="modal-overlay" id="verifyModal" onclick="if(event.target===this)closeModal('verifyModal')">
  <div class="modal-box">
    <div class="modal-head"><div class="modal-title">Verify Detection Event</div><div class="modal-close" onclick="closeModal('verifyModal')"><i class="fa-solid fa-xmark"></i></div></div>
    <div class="modal-body">
      <div style="padding:14px;background:var(--bg-base);border-radius:9px;border:1px solid var(--border);margin-bottom:16px;font-size:.84rem;color:var(--text-muted);line-height:1.6;">
        <strong style="color:var(--text-primary);">Staff Verification Checklist</strong><br/>
        Before marking this event, physically verify the room and confirm:
        <ul style="margin-top:.5rem;padding-left:1.2rem;display:flex;flex-direction:column;gap:4px;">
          <li>The item is not present in the registered ROI zone</li>
          <li>The item is not temporarily moved within the tolerance zone</li>
          <li>Room activity / class is not causing the false reading</li>
          <li>The CCTV feed is not obstructed</li>
        </ul>
      </div>
      <div class="form-group"><label class="form-label">Staff Notes / Observation</label><textarea class="form-control" rows="3" placeholder="Describe what you found upon physical inspection…"></textarea></div>
      <div class="modal-actions">
        <button class="modal-btn dismiss" onclick="closeModal('verifyModal')">Cancel</button>
        <button class="modal-btn dismiss" onclick="staffAction('dismissed')"><i class="fa-solid fa-ban"></i> False Alert</button>
        <button class="modal-btn recover" onclick="staffAction('recovered')"><i class="fa-solid fa-circle-check"></i> Item Found</button>
        <button class="modal-btn confirm" onclick="staffAction('escalate')"><i class="fa-solid fa-triangle-exclamation"></i> Confirm Missing</button>
      </div>
    </div>
  </div>
</div>

<!-- ══════ SHARED NOTIFICATION PANEL ══════ -->
<div class="notif-panel" id="notifPanel">
  <div class="notif-panel-head">
    <div class="notif-panel-title">
      <i class="fa-solid fa-bell"></i> Notifications
      <span class="notif-count-badge" id="notifCount">0</span>
    </div>
    <div class="notif-panel-actions">
      <button class="btn btn-sm" onclick="markAllNotifsRead()" style="font-size:.66rem;">
        <i class="fa-solid fa-check-double"></i> All read
      </button>
      <a href="notifications.php" class="btn btn-sm" style="font-size:.66rem;">
        <i class="fa-solid fa-expand"></i> View all
      </a>
      <button class="tb-btn" onclick="toggleNotifPanel()" style="width:28px;height:28px;">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>
  </div>
  <div class="notif-filter-tabs">
    <button class="notif-filter-tab active" onclick="filterPanelType('',this)">All</button>
    <button class="notif-filter-tab" onclick="filterPanelType('potential_lost',this)">
      <i class="fa-solid fa-triangle-exclamation" style="color:var(--warn);"></i> Alerts
    </button>
    <button class="notif-filter-tab" onclick="filterPanelType('new_claim',this)">
      <i class="fa-solid fa-hand-holding" style="color:var(--info);"></i> Claims
    </button>
    <button class="notif-filter-tab" onclick="filterPanelType('new_announcement',this)">
      <i class="fa-solid fa-bullhorn" style="color:var(--green-main);"></i> Announcements
    </button>
  </div>
  <div class="notif-panel-body" id="notifList">
    <div id="notifLoader" class="notif-loader">
      <div class="notif-loader-row">
        <div class="sk" style="width:36px;height:36px;border-radius:10px;flex-shrink:0;"></div>
        <div style="flex:1;display:flex;flex-direction:column;gap:7px;">
          <div class="sk" style="width:70%;height:10px;border-radius:5px;"></div>
          <div class="sk" style="width:90%;height:9px;border-radius:5px;"></div>
        </div>
      </div>
    </div>
    <div class="notif-empty" id="notifEmpty" style="display:none;">
      <i class="fa-solid fa-bell-slash"></i>
      <h4>All caught up!</h4>
      <p>No new notifications.</p>
    </div>
  </div>
  <div class="notif-panel-foot">
    <span style="font-size:.7rem;color:var(--text-dim);" id="notifPanelTs">—</span>
    <a href="notifications.php" style="font-family:var(--font-display);font-size:.7rem;font-weight:700;color:var(--green-main);text-decoration:none;">
      View full history <i class="fa-solid fa-arrow-right" style="font-size:.6rem;"></i>
    </a>
  </div>
</div>
<div class="notif-backdrop" id="notifBackdrop" onclick="toggleNotifPanel()"></div>
<div class="toast-stack" id="toastStack"></div>
<script src="../assets/js/spotit.js"></script>
<script>
startLiveClock('liveClock');
function openVerifyModal(id) { openModal('verifyModal'); }
function quickDismiss(id) { showToast('warn','Event dismissed as false alert.'); }
function staffAction(act) { const m={dismissed:'Marked as false alert.',recovered:'Item marked as found and recovered.',escalate:'Escalated to admin. Chain of custody updated.'}; showToast(act==='recovered'?'success':act==='escalate'?'error':'warn',m[act]); closeModal('verifyModal'); }
</script>

<script>
/* -- Notification panel -- */

<script>
window.SPOTIT_USER_ROLE = 'staff';
window.SPOTIT_TOUR_STEPS = [
  {
    target: '#sidebar',
    icon: 'fa-solid fa-compass',
    title: 'Your Navigation Hub',
    desc: 'Quick access to room monitoring, the lost &amp; found event log, and the claiming station — all from one place.',
    placement: 'right',
  },
  {
    target: '#tourStatGrid',
    icon: 'fa-solid fa-gauge-high',
    title: 'Your Daily Snapshot',
    desc: 'See what needs your attention right away: items awaiting verification, pending claims, and what you\'ve already resolved today.',
    placement: 'bottom',
  },
  {
    target: '#tourQueue',
    icon: 'fa-solid fa-list-check',
    title: 'Verification Queue',
    desc: 'This is your main task list. Each flagged event shows priority, the deviation, and how long it\'s been pending. Click <strong>Verify</strong> to physically check the room and confirm what happened.',
    placement: 'top',
  },
  {
    target: '#tourQuickActions',
    icon: 'fa-solid fa-bolt',
    title: 'Quick Actions',
    desc: 'Jump straight to the Claiming Station or Room Monitor, request a recalibration, or export today\'s log — all in one click.',
    placement: 'left',
  },
];
</script>
<script src="../assets/js/onboarding.js"></script>


<div id="notifBackdrop" onclick="toggleNotifPanel()" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);z-index:4999;backdrop-filter:blur(2px);"></div>
</body></html>
