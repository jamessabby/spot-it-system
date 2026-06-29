<?php
/**
 * S.P.O.T.-IT — Admin Dashboard
 * pages/dashboard-admin.php
 * MICROSERVICES: No SQL here. Data comes from auth/get_detections.php via JS fetch.
 */
require_once __DIR__ . '/../config/env.php';
ms_require_role('admin', 'login.php');
$active_page = 'dashboard';
$user_role   = 'admin';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Admin Dashboard — S.P.O.T.-IT</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
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
    <!-- Topbar -->
    <div class="topbar">
      <button class="tb-btn tb-hamburger" id="hamburgerBtn" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
      <div><span class="topbar-title">Overview Dashboard</span><span class="topbar-sub">— CEAT Building, MLH</span></div>
      <div class="live-pill" id="tourLiveIndicator"><div class="live-dot"></div>LIVE</div>
      <div class="topbar-right">
        <span style="font-family:var(--font-mono);font-size:.7rem;color:var(--text-dim);" id="liveClock"></span>
        <button class="tb-btn" onclick="location.reload()" title="Refresh"><i class="fa-solid fa-rotate-right"></i></button>
        <button class="tb-btn" id="tourNotifBtn" title="Notifications"><i class="fa-solid fa-bell"></i><div class="tb-notif-dot"></div></button>
        <button class="tb-btn" onclick="toggleTheme()" title="Theme"><i class="fa-solid fa-circle-half-stroke"></i></button>
      </div>
    </div>

    <div class="page-body">
      <!-- Stat cards -->
      <div class="stat-grid" id="tourStatGrid">
        <div class="stat-card"><div class="stat-icon ok"><i class="fa-solid fa-circle-check"></i></div><div><div class="stat-num" id="statRooms">8</div><div class="stat-label">Rooms Monitoring</div><div class="stat-delta flat"><i class="fa-solid fa-minus"></i> No change</div></div></div>
        <div class="stat-card"><div class="stat-icon alert"><i class="fa-solid fa-circle-minus"></i></div><div><div class="stat-num" id="statMissing">3</div><div class="stat-label">Active Deviations</div><div class="stat-delta up"><i class="fa-solid fa-arrow-up"></i> +2 since last check</div></div></div>
        <div class="stat-card"><div class="stat-icon warn"><i class="fa-solid fa-clock"></i></div><div><div class="stat-num" id="statPending">2</div><div class="stat-label">Pending Validation</div><div class="stat-delta flat"><i class="fa-solid fa-arrow-right"></i> Awaiting staff review</div></div></div>
        <div class="stat-card"><div class="stat-icon info"><i class="fa-solid fa-box-open"></i></div><div><div class="stat-num" id="statToday">11</div><div class="stat-label">Events Today</div><div class="stat-delta down"><i class="fa-solid fa-arrow-down"></i> −3 vs yesterday</div></div></div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 360px;gap:18px;align-items:start;">

        <!-- LEFT: Room table + event log -->
        <div style="display:flex;flex-direction:column;gap:18px;">

          <!-- Room status table -->
          <div class="card" id="tourRoomTable">
            <div class="card-head">
              <div class="card-title"><i class="fa-solid fa-door-open"></i> Room Status — All Monitored Rooms</div>
              <a href="room-monitor.php" class="card-action"><i class="fa-solid fa-expand"></i> Full View</a>
            </div>
            <div style="overflow-x:auto;">
              <table class="data-table">
                <thead><tr><th>Room</th><th>Live / Baseline</th><th>Deviation</th><th>Status</th><th>Timer</th><th>Last Event</th><th></th></tr></thead>
                <tbody>
                  <tr>
                    <td><div class="col-id">MLH 306</div><div class="col-sub">Systems &amp; App Dev Lab</div></td>
                    <td><span style="font-family:var(--font-mono);font-size:.88rem;font-weight:600;">29</span><span class="col-mono"> / 30</span></td>
                    <td><span class="dev-chip dev-neg">−1</span></td>
                    <td><span class="badge badge-alert"><span class="bdot"></span>MISSING</span></td>
                    <td><span class="countdown alert" id="t-mlh306">--:--:--</span><div style="font-size:.6rem;color:var(--text-dim);margin-top:1px;">since deviation</div></td>
                    <td><span class="col-mono">14:03:44</span></td>
                    <td><button class="btn btn-primary btn-sm" onclick="openEventModal('mlh306')">Review</button></td>
                  </tr>
                  <tr>
                    <td><div class="col-id">MLH 305</div><div class="col-sub">Logic &amp; Algorithms Lab</div></td>
                    <td><span style="font-family:var(--font-mono);font-size:.88rem;font-weight:600;">28</span><span class="col-mono"> / 30</span></td>
                    <td><span class="dev-chip dev-neg">−2</span></td>
                    <td><span class="badge badge-warn"><span class="bdot"></span>POTENTIAL</span></td>
                    <td><span class="countdown warn" id="t-mlh305">--:--:--</span><div style="font-size:.6rem;color:var(--text-dim);margin-top:1px;">30-min threshold</div></td>
                    <td><span class="col-mono">14:30:12</span></td>
                    <td><button class="btn btn-primary btn-sm" onclick="openEventModal('mlh305')">Review</button></td>
                  </tr>
                  <tr>
                    <td><div class="col-id">MLH 303</div><div class="col-sub">Advanced Programming Lab</div></td>
                    <td><span style="font-family:var(--font-mono);font-size:.88rem;font-weight:600;">31</span><span class="col-mono"> / 30</span></td>
                    <td><span class="dev-chip dev-pos">+1</span></td>
                    <td><span class="badge badge-warn"><span class="bdot"></span>UNREGISTERED</span></td>
                    <td><span class="countdown warn" id="t-mlh303">--:--:--</span></td>
                    <td><span class="col-mono">14:53:05</span></td>
                    <td><button class="btn btn-primary btn-sm" onclick="openEventModal('mlh303')">Review</button></td>
                  </tr>
                  <?php
                  $ok_rooms = [
                    ['MLH 304','Engineering CAD Lab',25,25,'13:41:00'],
                    ['MLH 203','Computational Engineering Lab',20,20,'13:55:22'],
                    ['MLH 301','Embedded Systems Lab',18,18,'14:10:05'],
                    ['MLH 201','Structured / Data Center Lab',22,22,'14:00:11'],
                  ];
                  foreach ($ok_rooms as $r): ?>
                  <tr>
                    <td><div class="col-id"><?= $r[0] ?></div><div class="col-sub"><?= htmlspecialchars($r[1]) ?></div></td>
                    <td><span style="font-family:var(--font-mono);font-size:.88rem;font-weight:600;"><?= $r[2] ?></span><span class="col-mono"> / <?= $r[3] ?></span></td>
                    <td><span class="dev-chip dev-zero">0</span></td>
                    <td><span class="badge badge-ok"><span class="bdot"></span>NORMAL</span></td>
                    <td><span class="col-mono">—</span></td>
                    <td><span class="col-mono"><?= $r[4] ?></span></td>
                    <td><button class="btn btn-sm">View</button></td>
                  </tr>
                  <?php endforeach; ?>
                  <tr>
                    <td><div class="col-id">MLH 401</div><div class="col-sub">Architectural CAD Lab</div></td>
                    <td><span class="col-mono" style="color:var(--text-dim);">— / 30</span></td>
                    <td><span class="dev-chip" style="background:var(--bg-base);color:var(--text-dim);">—</span></td>
                    <td><span class="badge badge-muted"><span class="bdot"></span>OFFLINE</span></td>
                    <td><span class="col-mono">—</span></td>
                    <td><span class="col-mono" style="color:var(--text-dim);">No feed</span></td>
                    <td><button class="btn btn-sm">Setup</button></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Detection event log -->
          <div class="card" id="tourEventLog">
            <div class="card-head">
              <div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Detection Event Log</div>
              <a href="#" class="card-action">Export <i class="fa-solid fa-download"></i></a>
            </div>
            <div class="filter-tabs">
              <div class="filter-tab active" onclick="setFilterTab(this)">All (11)</div>
              <div class="filter-tab" onclick="setFilterTab(this)">Pending (2)</div>
              <div class="filter-tab" onclick="setFilterTab(this)">Confirmed (3)</div>
              <div class="filter-tab" onclick="setFilterTab(this)">Dismissed (4)</div>
              <div class="filter-tab" onclick="setFilterTab(this)">Recovered (2)</div>
            </div>
            <?php
            $events = [
              ['id'=>'mlh306','room'=>'MLH 306','zone'=>'Workstation 7','title'=>'Monitor removed from registered ROI zone','time'=>'2026-06-14 · 14:03:44','dev'=>'−1 item','devCls'=>'neg','status'=>'Confirmed Missing','stCls'=>'est-confirmed','cls'=>'unread'],
              ['id'=>'mlh305','room'=>'MLH 305','zone'=>'WS 3 & 4','title'=>'Two keyboards no longer detected in their zones','time'=>'2026-06-14 · 14:30:12','dev'=>'−2 items','devCls'=>'neg','status'=>'Pending','stCls'=>'est-pending','cls'=>'warn-event unread'],
              ['id'=>'mlh303','room'=>'MLH 303','zone'=>'Unregistered Zone','title'=>'Unexpected item detected — count exceeds baseline','time'=>'2026-06-14 · 14:53:05','dev'=>'+1 item','devCls'=>'pos','status'=>'Pending','stCls'=>'est-pending','cls'=>'warn-event'],
              ['id'=>'','room'=>'MLH 304','zone'=>'Workstation 12','title'=>'False positive — temporary occlusion by student bag','time'=>'2026-06-14 · 13:11:30','dev'=>'−1 item','devCls'=>'neg','status'=>'Dismissed','stCls'=>'est-dismissed','cls'=>''],
              ['id'=>'','room'=>'MLH 201','zone'=>'Workstation 5','title'=>'Keyboard returned — deviation resolved','time'=>'2026-06-14 · 12:48:05','dev'=>'−1 → 0','devCls'=>'neg','status'=>'Recovered','stCls'=>'est-recovered','cls'=>''],
            ];
            foreach ($events as $ev): ?>
            <div class="event-row <?= $ev['cls'] ?>" <?= $ev['id'] ? "onclick=\"openEventModal('{$ev['id']}')\"" : '' ?> style="<?= !$ev['id'] ? 'opacity:.65;cursor:default;' : '' ?>">
              <div class="event-thumb">
                <svg width="52" height="40" style="position:absolute;inset:0;opacity:.7">
                  <rect x="4" y="6" width="44" height="8" rx="1" fill="rgba(255,255,255,.06)"/>
                  <rect x="4" y="17" width="20" height="8" rx="1" fill="rgba(255,255,255,.06)"/>
                  <rect x="28" y="17" width="20" height="8" rx="1" fill="rgba(255,255,255,.06)"/>
                  <rect x="4" y="28" width="20" height="8" rx="1" fill="rgba(255,255,255,.06)"/>
                  <?php if ($ev['devCls'] === 'neg'): ?>
                  <rect x="28" y="28" width="20" height="8" rx="1" fill="rgba(255,77,77,.12)" stroke="#ff4d4d" stroke-width="1" stroke-dasharray="2,2"/>
                  <?php elseif ($ev['devCls'] === 'pos'): ?>
                  <rect x="28" y="28" width="20" height="8" rx="1" fill="rgba(230,126,0,.12)" stroke="#e67e00" stroke-width="1.5"/>
                  <?php else: ?>
                  <polyline points="14,22 22,29 38,14" stroke="#5cffac" stroke-width="2" fill="none" stroke-linecap="round"/>
                  <?php endif; ?>
                </svg>
              </div>
              <div class="event-body">
                <div class="event-tag"><?= htmlspecialchars($ev['room']) ?> · <?= htmlspecialchars($ev['zone']) ?></div>
                <div class="event-title"><?= htmlspecialchars($ev['title']) ?></div>
                <div class="event-meta">
                  <span class="event-time"><?= $ev['time'] ?></span>
                  <span class="event-dev <?= $ev['devCls'] ?>"><?= $ev['dev'] ?></span>
                  <span class="event-status-tag <?= $ev['stCls'] ?>"><?= $ev['status'] ?></span>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- RIGHT: Alerts + Timeline -->
        <div style="display:flex;flex-direction:column;gap:18px;">
          <div class="card" id="tourAlerts">
            <div class="card-head">
              <div class="card-title"><i class="fa-solid fa-bell-ring"></i> Active Alerts</div>
              <a href="#" class="card-action">Mark all read</a>
            </div>
            <?php
            $alerts = [
              ['t'=>'alert','i'=>'fa-circle-minus','body'=>'<strong>MLH 306</strong> — 1 monitor missing for <strong>over 1 hour</strong>. Confirmed missing.','ts'=>'14:03:44 · 1h 02m ago'],
              ['t'=>'warn', 'i'=>'fa-clock','body'=>'<strong>MLH 305</strong> — 2 keyboards undetected for <strong>34 min</strong>. Approaching 1-hour threshold.','ts'=>'14:30:12 · 34m ago'],
              ['t'=>'warn', 'i'=>'fa-plus-circle','body'=>'<strong>MLH 303</strong> — 1 unregistered item detected, count is +1 above baseline.','ts'=>'14:53:05 · 12m ago'],
              ['t'=>'info', 'i'=>'fa-rotate','body'=>'<strong>MLH 304</strong> reference frame recalibrated by lab staff.','ts'=>'13:00:00 · 2h ago'],
              ['t'=>'ok',   'i'=>'fa-circle-check','body'=>'<strong>MLH 201</strong> — keyboard deviation resolved. Item returned.','ts'=>'12:48:05 · 2h 17m ago'],
            ];
            foreach ($alerts as $a): ?>
            <div class="alert-item">
              <div class="alert-ico <?= $a['t'] ?>"><i class="fa-solid <?= $a['i'] ?>"></i></div>
              <div class="alert-body"><?= $a['body'] ?><span class="alert-ts"><?= $a['ts'] ?></span></div>
            </div>
            <?php endforeach; ?>
          </div>

          <div class="card">
            <div class="card-head">
              <div class="card-title"><i class="fa-solid fa-timeline"></i> Today's Timeline</div>
              <span style="font-size:.65rem;color:var(--text-dim);font-family:var(--font-mono);">June 15, 2026</span>
            </div>
            <?php
            $tl = [
              ['t'=>'alert','lbl'=>'<strong>MLH 306</strong> — Monitor confirmed missing. 1-hour threshold exceeded.','meta'=>'15:06:01 · auto-escalated'],
              ['t'=>'warn', 'lbl'=>'<strong>MLH 303</strong> — Unregistered item detected, +1 above baseline.','meta'=>'14:53:05 · pending review'],
              ['t'=>'warn', 'lbl'=>'<strong>MLH 305</strong> — 2 keyboards not detected, 30-min threshold crossed.','meta'=>'14:30:12 · potentially lost'],
              ['t'=>'alert','lbl'=>'<strong>MLH 306</strong> — Count deviation first detected (−1 monitor).','meta'=>'14:03:44 · detection module'],
              ['t'=>'info', 'lbl'=>'<strong>MLH 304</strong> — Reference frame recalibrated.','meta'=>'13:00:00 · staff action'],
              ['t'=>'ok',   'lbl'=>'<strong>MLH 201</strong> — Keyboard deviation resolved.','meta'=>'12:48:05 · staff verified'],
              ['t'=>'info', 'lbl'=>'System monitoring session started for all 8 rooms.','meta'=>'08:00:00 · system boot'],
            ];
            foreach ($tl as $item): ?>
            <div class="timeline-item">
              <div class="tl-dot <?= $item['t'] ?>"></div>
              <div><div class="tl-label"><?= $item['lbl'] ?></div><div class="tl-meta"><?= $item['meta'] ?></div></div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- EVENT MODAL -->
<div class="modal-overlay" id="eventModal" onclick="if(event.target===this)closeModal('eventModal')">
  <div class="modal-box">
    <div class="modal-head">
      <div class="modal-title" id="modalTitle">Detection Event</div>
      <div class="modal-close" onclick="closeModal('eventModal')"><i class="fa-solid fa-xmark"></i></div>
    </div>
    <div class="modal-body">
      <div class="snap-view">
        <div class="snap-scanline"></div>
        <div class="snap-hud"><span id="snapRoom">CAM-01 · MLH 306</span><span>MOTION: NONE</span><span id="snapAlert" style="color:#ff4d4d;">⚠ DEVIATION DETECTED</span></div>
        <div class="snap-ts" id="snapTs">2026-06-15 14:03:44</div>
        <svg width="100%" height="100%" style="position:absolute;inset:0;" viewBox="0 0 640 360" preserveAspectRatio="none" id="snapSVG">
          <rect x="20" y="40" width="120" height="70" rx="3" fill="rgba(0,200,120,.07)" stroke="#5cffac" stroke-width="1.5"/><text x="24" y="36" font-family="monospace" font-size="9" fill="#5cffac">WS-01 ✓</text>
          <rect x="160" y="40" width="120" height="70" rx="3" fill="rgba(0,200,120,.07)" stroke="#5cffac" stroke-width="1.5"/><text x="164" y="36" font-family="monospace" font-size="9" fill="#5cffac">WS-02 ✓</text>
          <rect x="300" y="40" width="120" height="70" rx="3" fill="rgba(0,200,120,.07)" stroke="#5cffac" stroke-width="1.5"/><text x="304" y="36" font-family="monospace" font-size="9" fill="#5cffac">WS-03 ✓</text>
          <rect x="440" y="40" width="120" height="70" rx="3" fill="rgba(255,77,77,.1)" stroke="#ff4d4d" stroke-width="2" stroke-dasharray="6,3" id="alertROI"/><text x="444" y="36" font-family="monospace" font-size="9" fill="#ff4d4d" id="alertLabel">WS-07 ✗ MISSING</text>
          <rect x="420" y="25" width="160" height="100" rx="3" fill="none" stroke="#e6cc00" stroke-width="1" stroke-dasharray="3,3" opacity=".5"/>
          <text x="444" y="142" font-family="monospace" font-size="8" fill="#e6cc00" opacity=".5">search zone</text>
        </svg>
      </div>
      <div class="detail-grid">
        <div class="detail-cell"><div class="detail-key">Room</div><div class="detail-val" id="dRoom">MLH 306</div></div>
        <div class="detail-cell"><div class="detail-key">Detected At</div><div class="detail-val" id="dTime">2026-06-15 14:03:44</div></div>
        <div class="detail-cell"><div class="detail-key">Baseline Count</div><div class="detail-val" id="dBaseline">30 items</div></div>
        <div class="detail-cell"><div class="detail-key">Live Count</div><div class="detail-val alert" id="dLive">29 items (−1)</div></div>
        <div class="detail-cell"><div class="detail-key">ROI Zone</div><div class="detail-val" id="dZone">Workstation 7 — Monitor Zone</div></div>
        <div class="detail-cell"><div class="detail-key">Duration</div><div class="detail-val alert" id="dDuration">1 hr 02 min — Escalated</div></div>
        <div class="detail-cell"><div class="detail-key">Detection Method</div><div class="detail-val">Background Subtraction + Contour Count</div></div>
        <div class="detail-cell"><div class="detail-key">Status</div><div class="detail-val alert" id="dStatus">Confirmed Missing</div></div>
      </div>
      <div class="stage-pipeline">
        <div class="stage-label">Detection Timeline</div>
        <div class="stage-dot-wrap"><div class="stage-dot" style="background:var(--ok);"></div><div class="stage-text">14:03:44 — First detected</div></div>
        <div class="stage-line"></div>
        <div class="stage-dot-wrap"><div class="stage-dot" style="background:var(--warn);"></div><div class="stage-text">14:33:44 — Potentially lost (30 min)</div></div>
        <div class="stage-line"></div>
        <div class="stage-dot-wrap active"><div class="stage-dot" style="background:var(--alert);box-shadow:0 0 6px var(--alert);"></div><div class="stage-text">15:03:44 — Confirmed Missing (1 hr)</div></div>
      </div>
      <textarea class="form-control" style="margin-top:14px;" rows="2" placeholder="Add staff notes or remarks about this event…"></textarea>
      <div class="modal-actions">
        <button class="modal-btn dismiss" onclick="closeModal('eventModal')"><i class="fa-solid fa-xmark"></i> Close</button>
        <button class="modal-btn dismiss" onclick="markEvent('dismissed')"><i class="fa-solid fa-ban"></i> Dismiss</button>
        <button class="modal-btn recover" onclick="markEvent('recovered')"><i class="fa-solid fa-circle-check"></i> Mark Recovered</button>
        <button class="modal-btn confirm" onclick="markEvent('confirm')"><i class="fa-solid fa-triangle-exclamation"></i> Confirm Missing</button>
      </div>
    </div>
  </div>
</div>
<div class="toast-stack" id="toastStack"></div>

<script src="../assets/js/spotit.js"></script>
<script>
startLiveClock('liveClock');
// Start countup timers from known detection times
startCountup('t-mlh306', '2026-06-15 14:03:44');
startCountup('t-mlh305', '2026-06-15 14:30:12');
startCountup('t-mlh303', '2026-06-15 14:53:05');

const modalData = {
  mlh306: { title:'Detection Event — MLH 306', room:'MLH 306 — Systems & App Dev', time:'2026-06-15 14:03:44', baseline:'30 items', live:'29 items (−1)', liveClass:'alert', zone:'Workstation 7 — Monitor Zone', duration:'1 hr 02 min — Escalated', status:'Confirmed Missing', statusClass:'alert', snapRoom:'CAM-01 · MLH 306', alertColor:'#ff4d4d', alertLabel:'WS-07 Monitor ✗ MISSING' },
  mlh305: { title:'Detection Event — MLH 305', room:'MLH 305 — Logic & Algorithms', time:'2026-06-15 14:30:12', baseline:'30 items', live:'28 items (−2)', liveClass:'alert', zone:'WS-03 & WS-04 — Keyboard Zones', duration:'34 min — Potentially Lost', status:'Pending Verification', statusClass:'warn', snapRoom:'CAM-01 · MLH 305', alertColor:'#e67e00', alertLabel:'WS-03/WS-04 Keyboard ✗' },
  mlh303: { title:'Detection Event — MLH 303', room:'MLH 303 — Advanced Programming', time:'2026-06-15 14:53:05', baseline:'30 items', live:'31 items (+1)', liveClass:'warn', zone:'Unregistered — South Corner', duration:'12 min', status:'Pending — Unregistered Item', statusClass:'warn', snapRoom:'CAM-01 · MLH 303', alertColor:'#e67e00', alertLabel:'UNREGISTERED ITEM +1' },
};

function openEventModal(id) {
  const d = modalData[id]; if (!d) return;
  document.getElementById('modalTitle').textContent = d.title;
  document.getElementById('snapRoom').textContent   = d.snapRoom;
  document.getElementById('snapTs').textContent     = d.time;
  document.getElementById('snapAlert').style.color  = d.alertColor;
  document.getElementById('alertROI').setAttribute('stroke', d.alertColor);
  document.getElementById('alertROI').setAttribute('fill', d.alertColor === '#ff4d4d' ? 'rgba(255,77,77,.1)' : 'rgba(230,126,0,.1)');
  document.getElementById('alertLabel').textContent  = d.alertLabel;
  document.getElementById('alertLabel').setAttribute('fill', d.alertColor);
  document.getElementById('dRoom').textContent      = d.room;
  document.getElementById('dTime').textContent      = d.time;
  document.getElementById('dBaseline').textContent  = d.baseline;
  document.getElementById('dLive').textContent      = d.live;
  document.getElementById('dLive').className        = 'detail-val ' + d.liveClass;
  document.getElementById('dZone').textContent      = d.zone;
  document.getElementById('dDuration').textContent  = d.duration;
  document.getElementById('dDuration').className    = 'detail-val ' + d.statusClass;
  document.getElementById('dStatus').textContent    = d.status;
  document.getElementById('dStatus').className      = 'detail-val ' + d.statusClass;
  openModal('eventModal');
}

function markEvent(action) {
  const msgs = { dismissed:'Event dismissed as false alert.', recovered:'Item marked as recovered. Record updated.', confirm:'Event confirmed as missing. Staff notified.' };
  showToast(action === 'recovered' ? 'success' : action === 'dismissed' ? 'warn' : 'error', msgs[action]);
  closeModal('eventModal');
}
</script>

<!-- ══════════════════════════════════════
     First-Time Onboarding Tour — Admin Dashboard
══════════════════════════════════════ -->
<script>
window.SPOTIT_USER_ROLE = 'admin';
window.SPOTIT_TOUR_STEPS = [
  {
    target: '#sidebar',
    icon: 'fa-solid fa-compass',
    title: 'Your Navigation Hub',
    desc: 'Everything lives here — room monitoring, alerts, lost &amp; found, claims, and admin management tools. Organized by category so you always know where to look.',
    placement: 'right',
  },
  {
    target: '#tourLiveIndicator',
    icon: 'fa-solid fa-satellite-dish',
    title: 'Live Monitoring Status',
    desc: 'This pill shows the system is actively watching all CEAT laboratory rooms in real time via the CCTV detection module.',
    placement: 'bottom',
  },
  {
    target: '#tourStatGrid',
    icon: 'fa-solid fa-gauge-high',
    title: 'At-a-Glance Overview',
    desc: 'Four key numbers: how many rooms are being monitored, active deviations needing attention, events pending validation, and total events logged today.',
    placement: 'bottom',
  },
  {
    target: '#tourRoomTable',
    icon: 'fa-solid fa-door-open',
    title: 'Room Status Table',
    desc: 'See every monitored room\'s live item count vs. baseline. Rooms with a deviation show a countdown timer — 30 minutes turns it "Potentially Lost", 60 minutes confirms it "Missing". Click <strong>Review</strong> to investigate.',
    placement: 'top',
  },
  {
    target: '#tourEventLog',
    icon: 'fa-solid fa-clock-rotate-left',
    title: 'Detection Event Log',
    desc: 'A running history of every detection — what was flagged, when, and its current status. Use the filter tabs to narrow down by Pending, Confirmed, Dismissed, or Recovered.',
    placement: 'top',
  },
  {
    target: '#tourAlerts',
    icon: 'fa-solid fa-bell-ring',
    title: 'Active Alerts Feed',
    desc: 'Your priority inbox. The most urgent, unresolved alerts appear here first so nothing slips through the cracks.',
    placement: 'left',
  },
  {
    target: '#tourNotifBtn',
    icon: 'fa-solid fa-bell',
    title: 'Notification Bell',
    desc: 'Quick access to system notifications from anywhere in the dashboard. The red dot means there\'s something new to check.',
    placement: 'bottom',
  },
];
</script>
<script src="../assets/js/onboarding.js"></script>
</body>
</html>
