<?php
require_once __DIR__ . '/../config/env.php';
$active_page = 'rooms'; $user_role = $_SESSION['user_role'] ?? 'admin';
$room_id   = $_GET['room'] ?? 'MLH306';
$room_name = ['MLH306'=>'Systems & App Dev Lab','MLH305'=>'Logic & Algorithms Lab','MLH304'=>'Engineering CAD Lab','MLH303'=>'Advanced Programming Lab','MLH203'=>'Computational Engineering Lab'][$room_id] ?? 'Laboratory Room';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Room Monitor — <?= htmlspecialchars($room_id) ?> — S.P.O.T.-IT</title>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/css/room-monitor.css"/>
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
      <div>
        <span class="topbar-title">Room Monitor</span>
        <span class="topbar-sub"> — <?= htmlspecialchars($room_id) ?> · <?= htmlspecialchars($room_name) ?></span>
      </div>
      <div class="live-pill"><div class="live-dot"></div>LIVE FEED</div>
      <div class="topbar-right">
        <span style="font-family:var(--font-mono);font-size:.7rem;color:var(--text-dim);" id="liveClock"></span>
        <!-- Room selector -->
        <select id="roomSelect" onchange="switchRoom(this.value)" style="font-family:var(--font-display);font-size:.72rem;font-weight:700;padding:6px 10px;border-radius:7px;border:1px solid var(--border);background:var(--bg-base);color:var(--text-primary);cursor:pointer;">
          <?php foreach(['MLH306','MLH305','MLH304','MLH303','MLH203','MLH301','MLH201'] as $r): ?>
          <option value="<?= $r ?>" <?= $r===$room_id?'selected':'' ?>><?= $r ?></option>
          <?php endforeach; ?>
        </select>
        <button class="tb-btn" onclick="toggleTheme()"><i class="fa-solid fa-circle-half-stroke"></i></button>
      </div>
    </div>

    <div class="page-body">
      <div style="display:grid;grid-template-columns:1fr 320px;gap:18px;align-items:start;">

        <!-- LEFT: CCTV feeds + ROI map -->
        <div style="display:flex;flex-direction:column;gap:18px;">

          <!-- Live count bar -->
          <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;">
            <div class="stat-card"><div class="stat-icon alert"><i class="fa-solid fa-circle-minus"></i></div><div><div class="stat-num" id="liveCount">29</div><div class="stat-label">Live Count</div></div></div>
            <div class="stat-card"><div class="stat-icon info"><i class="fa-solid fa-bullseye"></i></div><div><div class="stat-num">30</div><div class="stat-label">Baseline</div></div></div>
            <div class="stat-card"><div class="stat-icon alert"><i class="fa-solid fa-arrow-down"></i></div><div><div class="stat-num" style="color:var(--alert);">−1</div><div class="stat-label">Deviation</div></div></div>
            <div class="stat-card"><div class="stat-icon warn"><i class="fa-solid fa-clock"></i></div><div><div class="stat-num countdown alert" id="roomTimer" style="font-size:1.2rem;">--:--:--</div><div class="stat-label">Elapsed</div></div></div>
          </div>

          <!-- Dual CCTV feeds -->
          <div class="card">
            <div class="card-head">
              <div class="card-title"><i class="fa-solid fa-video"></i> Live CCTV Feeds — <?= htmlspecialchars($room_id) ?></div>
              <div style="display:flex;gap:6px;">
                <button class="btn btn-sm" id="btnPause" onclick="togglePause()"><i class="fa-solid fa-pause"></i> Pause</button>
                <button class="btn btn-sm btn-primary" onclick="showToast('info','Full-screen mode requires connected CCTV hardware.')"><i class="fa-solid fa-expand"></i></button>
              </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;">
              <!-- Feed 1 -->
              <div class="cctv-panel" id="feed1">
                <div class="cctv-label">CAM-01 · <?= htmlspecialchars($room_id) ?> · NORTH</div>
                <div class="cctv-ts" id="feedTs1"></div>
                <div class="cctv-motion" id="motionBadge1"><span class="cctv-rec"></span>NO MOTION</div>
                <svg width="100%" height="100%" viewBox="0 0 100 75" preserveAspectRatio="none" style="position:absolute;inset:0;">
                  <!-- Room layout schematic -->
                  <?php for($row=0;$row<3;$row++): for($col=0;$col<5;$col++): $x=5+$col*19; $y=8+$row*21; ?>
                  <rect x="<?=$x?>" y="<?=$y?>" width="15" height="12" rx="1" fill="rgba(255,255,255,.05)"/>
                  <?php endfor; endfor; ?>
                  <!-- OK ROIs (green) -->
                  <rect x="5" y="8" width="15" height="12" rx="1" fill="rgba(0,200,120,.12)" stroke="#5cffac" stroke-width=".6"/><text x="6" y="17" font-size="3" fill="#5cffac" font-family="monospace">WS-01 ✓</text>
                  <rect x="24" y="8" width="15" height="12" rx="1" fill="rgba(0,200,120,.12)" stroke="#5cffac" stroke-width=".6"/><text x="25" y="17" font-size="3" fill="#5cffac" font-family="monospace">WS-02 ✓</text>
                  <rect x="43" y="8" width="15" height="12" rx="1" fill="rgba(0,200,120,.12)" stroke="#5cffac" stroke-width=".6"/><text x="44" y="17" font-size="3" fill="#5cffac" font-family="monospace">WS-03 ✓</text>
                  <rect x="62" y="8" width="15" height="12" rx="1" fill="rgba(0,200,120,.12)" stroke="#5cffac" stroke-width=".6"/><text x="63" y="17" font-size="3" fill="#5cffac" font-family="monospace">WS-04 ✓</text>
                  <rect x="81" y="8" width="15" height="12" rx="1" fill="rgba(0,200,120,.12)" stroke="#5cffac" stroke-width=".6"/><text x="82" y="17" font-size="3" fill="#5cffac" font-family="monospace">WS-05 ✓</text>
                  <!-- MISSING ROI (red dashed) -->
                  <rect x="81" y="29" width="15" height="12" rx="1" fill="rgba(255,77,77,.15)" stroke="#ff4d4d" stroke-width=".8" stroke-dasharray="2,1"/><text x="82" y="38" font-size="3" fill="#ff4d4d" font-family="monospace">WS-07 ✗</text>
                  <!-- Tolerance/search zone -->
                  <rect x="78" y="26" width="21" height="18" rx="1" fill="none" stroke="#e6cc00" stroke-width=".5" stroke-dasharray="1.5,1" opacity=".6"/>
                  <text x="79" y="47" font-size="2.5" fill="#e6cc00" font-family="monospace" opacity=".6">search</text>
                </svg>
                <div class="cctv-scanline"></div>
                <div class="cctv-status-alert">⚠ DEVIATION DETECTED — WS-07</div>
              </div>
              <!-- Feed 2 -->
              <div class="cctv-panel" id="feed2" style="border-left:1px solid rgba(0,0,0,.2);">
                <div class="cctv-label">CAM-02 · <?= htmlspecialchars($room_id) ?> · SOUTH</div>
                <div class="cctv-ts" id="feedTs2"></div>
                <div class="cctv-motion" id="motionBadge2" style="background:rgba(0,161,82,.2);color:rgba(100,255,160,.8);border-color:rgba(100,255,160,.2);"><span style="width:5px;height:5px;border-radius:50%;background:var(--ok);display:inline-block;margin-right:4px;"></span>NO MOTION</div>
                <svg width="100%" height="100%" viewBox="0 0 100 75" preserveAspectRatio="none" style="position:absolute;inset:0;">
                  <?php for($row=0;$row<3;$row++): for($col=0;$col<5;$col++): $x=5+$col*19; $y=8+$row*21; ?>
                  <rect x="<?=$x?>" y="<?=$y?>" width="15" height="12" rx="1" fill="rgba(255,255,255,.04)"/>
                  <?php endfor; endfor; ?>
                  <rect x="5" y="29" width="15" height="12" rx="1" fill="rgba(0,200,120,.12)" stroke="#5cffac" stroke-width=".6"/><text x="6" y="38" font-size="3" fill="#5cffac" font-family="monospace">WS-06 ✓</text>
                  <rect x="24" y="29" width="15" height="12" rx="1" fill="rgba(0,200,120,.12)" stroke="#5cffac" stroke-width=".6"/><text x="25" y="38" font-size="3" fill="#5cffac" font-family="monospace">WS-08 ✓</text>
                  <rect x="43" y="29" width="15" height="12" rx="1" fill="rgba(0,200,120,.12)" stroke="#5cffac" stroke-width=".6"/><text x="44" y="38" font-size="3" fill="#5cffac" font-family="monospace">WS-09 ✓</text>
                  <rect x="62" y="50" width="15" height="12" rx="1" fill="rgba(0,200,120,.12)" stroke="#5cffac" stroke-width=".6"/><text x="63" y="59" font-size="3" fill="#5cffac" font-family="monospace">WS-10 ✓</text>
                </svg>
                <div class="cctv-scanline" style="animation-delay:2s;"></div>
              </div>
            </div>
            <!-- Feed controls -->
            <div style="display:flex;gap:8px;padding:10px 14px;background:var(--bg-base);border-top:1px solid var(--border);align-items:center;flex-wrap:wrap;">
              <span style="font-family:var(--font-mono);font-size:.64rem;color:var(--text-dim);">STREAM: RTSP · LOCAL NETWORK · 1080p@25FPS</span>
              <div style="margin-left:auto;display:flex;gap:6px;">
                <button class="btn btn-sm" onclick="showToast('info','Reference frame recalibration requires admin access.')"><i class="fa-solid fa-rotate"></i> Recalibrate</button>
                <button class="btn btn-sm btn-alert" onclick="openModal('snapshotModal')"><i class="fa-solid fa-camera"></i> Take Snapshot</button>
              </div>
            </div>
          </div>

          <!-- ROI Zone list -->
          <div class="card">
            <div class="card-head">
              <div class="card-title"><i class="fa-solid fa-vector-square"></i> Registered ROI Zones — <?= htmlspecialchars($room_id) ?></div>
              <button class="card-action"><i class="fa-solid fa-plus"></i> Add Zone</button>
            </div>
            <div style="overflow-x:auto;">
              <table class="data-table">
                <thead><tr><th>Zone Label</th><th>Item Type</th><th>Expected</th><th>Live</th><th>Deviation</th><th>Status</th><th>Last Change</th></tr></thead>
                <tbody>
                  <?php
                  $zones = [
                    ['WS-01 Monitor Zone','Monitor',1,1,'0','ok','PRESENT','13:41:00'],
                    ['WS-02 Monitor Zone','Monitor',1,1,'0','ok','PRESENT','13:41:00'],
                    ['WS-03 Monitor Zone','Monitor',1,1,'0','ok','PRESENT','13:41:00'],
                    ['WS-04 Monitor Zone','Monitor',1,1,'0','ok','PRESENT','13:41:00'],
                    ['WS-05 Monitor Zone','Monitor',1,1,'0','ok','PRESENT','13:41:00'],
                    ['WS-06 Monitor Zone','Monitor',1,1,'0','ok','PRESENT','13:41:00'],
                    ['WS-07 Monitor Zone','Monitor',1,0,'−1','alert','MISSING','14:03:44'],
                    ['WS-08 Monitor Zone','Monitor',1,1,'0','ok','PRESENT','13:41:00'],
                    ['WS-09 Keyboard Zone','Keyboard',1,1,'0','ok','PRESENT','13:41:00'],
                    ['WS-10 Keyboard Zone','Keyboard',1,1,'0','ok','PRESENT','13:41:00'],
                  ];
                  foreach ($zones as $z): ?>
                  <tr <?= $z[5]==='alert'?'style="background:rgba(208,2,27,.03);"':'' ?>>
                    <td><span class="col-id"><?= $z[0] ?></span></td>
                    <td><span class="col-mono"><?= $z[1] ?></span></td>
                    <td><span class="col-mono"><?= $z[2] ?></span></td>
                    <td><span class="col-mono <?= $z[5]==='alert'?'alert':'' ?>"><?= $z[3] ?></span></td>
                    <td><span class="dev-chip dev-<?= $z[5]==='alert'?'neg':'zero' ?>"><?= $z[4] ?></span></td>
                    <td><span class="badge badge-<?= $z[5] ?>"><span class="bdot"></span><?= $z[6] ?></span></td>
                    <td><span class="col-mono"><?= $z[7] ?></span></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <!-- RIGHT: Detection log + pipeline -->
        <div style="display:flex;flex-direction:column;gap:18px;">

          <!-- Detection stage -->
          <div class="card">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-diagram-project"></i> Detection Stage</div></div>
            <div style="padding:16px;">
              <div style="display:flex;flex-direction:column;gap:10px;">
                <div class="stage-row done"><div class="stage-r-dot"></div><div><div class="stage-r-label">First Detected</div><div class="stage-r-ts">14:03:44 · WS-07 Monitor Zone</div></div></div>
                <div class="stage-connector done"></div>
                <div class="stage-row done warn"><div class="stage-r-dot warn"></div><div><div class="stage-r-label">Potentially Lost (30 min)</div><div class="stage-r-ts">14:33:44 · Auto-flagged</div></div></div>
                <div class="stage-connector done"></div>
                <div class="stage-row active"><div class="stage-r-dot alert pulse"></div><div><div class="stage-r-label">Confirmed Missing (1 hr)</div><div class="stage-r-ts" id="confirmedTs">15:03:44 · Escalated to admin</div></div></div>
                <div class="stage-connector"></div>
                <div class="stage-row inactive"><div class="stage-r-dot inactive"></div><div><div class="stage-r-label">Resolved / Recovered</div><div class="stage-r-ts">—</div></div></div>
              </div>
            </div>
          </div>

          <!-- Recent detection events for this room -->
          <div class="card">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Room Event Log</div></div>
            <div class="filter-tabs" style="padding-top:10px;">
              <div class="filter-tab active" onclick="setFilterTab(this)">All</div>
              <div class="filter-tab" onclick="setFilterTab(this)">Active</div>
              <div class="filter-tab" onclick="setFilterTab(this)">Resolved</div>
            </div>
            <?php
            $roomEvents = [
              ['WS-07 Monitor Zone','Monitor missing — deviation persisted > 1 hour','14:03:44','−1','neg','est-confirmed','Confirmed'],
              ['WS-02 Monitor Zone','False positive — student briefly moved monitor','11:22:14','−1','neg','est-dismissed','Dismissed'],
              ['WS-09 Keyboard Zone','Mouse repositioned within tolerance zone','10:45:00','0','zero','est-dismissed','No Change'],
              ['WS-05 Monitor Zone','Item returned after class','09:30:00','0','zero','est-recovered','Recovered'],
            ];
            foreach ($roomEvents as $re): ?>
            <div class="event-row" style="cursor:default;">
              <div class="event-body">
                <div class="event-tag"><?= $re[0] ?></div>
                <div class="event-title" style="font-size:.76rem;"><?= htmlspecialchars($re[1]) ?></div>
                <div class="event-meta">
                  <span class="event-time"><?= $re[2] ?></span>
                  <span class="event-dev <?= $re[3]==='0'?'zero':$re[4] ?>"><?= $re[3] ?></span>
                  <span class="event-status-tag <?= $re[5] ?>"><?= $re[6] ?></span>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>

          <!-- Room info -->
          <div class="card">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-circle-info"></i> Room Info</div></div>
            <div style="padding:14px;display:flex;flex-direction:column;gap:8px;">
              <?php
              $info = [['Room ID','MLH 306'],['Room Name','Systems &amp; App Dev Lab'],['Floor','3rd Floor, MLH'],['Cameras','2 × Dahua 5MP IP CCTV'],['Registered Items','30 (Tier 1)'],['Monitoring Since','08:00:00 today'],['Last Calibrated','June 12, 2026']];
              foreach ($info as [$k,$v]): ?>
              <div style="display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid var(--border);font-size:.78rem;">
                <span style="color:var(--text-dim);font-weight:500;"><?= $k ?></span>
                <span style="font-family:var(--font-mono);font-size:.76rem;color:var(--text-primary);font-weight:600;"><?= $v ?></span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Snapshot modal -->
<div class="modal-overlay" id="snapshotModal" onclick="if(event.target===this)closeModal('snapshotModal')">
  <div class="modal-box" style="max-width:520px;">
    <div class="modal-head"><div class="modal-title">Manual Snapshot — <?= htmlspecialchars($room_id) ?></div><div class="modal-close" onclick="closeModal('snapshotModal')"><i class="fa-solid fa-xmark"></i></div></div>
    <div class="modal-body">
      <div class="snap-view">
        <div class="snap-scanline"></div>
        <div class="snap-hud"><span>CAM-01 · <?= htmlspecialchars($room_id) ?></span><span>MANUAL SNAPSHOT</span><span style="color:#ff4d4d;">⚠ DEVIATION ACTIVE</span></div>
        <div class="snap-ts" id="snapshotTs"></div>
        <svg width="100%" height="100%" viewBox="0 0 640 360" preserveAspectRatio="none" style="position:absolute;inset:0;">
          <rect x="20" y="40" width="100" height="60" rx="3" fill="rgba(0,200,120,.08)" stroke="#5cffac" stroke-width="1.5"/><text x="24" y="36" font-family="monospace" font-size="9" fill="#5cffac">WS-01 ✓</text>
          <rect x="140" y="40" width="100" height="60" rx="3" fill="rgba(0,200,120,.08)" stroke="#5cffac" stroke-width="1.5"/><text x="144" y="36" font-family="monospace" font-size="9" fill="#5cffac">WS-02 ✓</text>
          <rect x="260" y="40" width="100" height="60" rx="3" fill="rgba(0,200,120,.08)" stroke="#5cffac" stroke-width="1.5"/><text x="264" y="36" font-family="monospace" font-size="9" fill="#5cffac">WS-03 ✓</text>
          <rect x="380" y="40" width="100" height="60" rx="3" fill="rgba(255,77,77,.12)" stroke="#ff4d4d" stroke-width="2" stroke-dasharray="6,3"/><text x="384" y="36" font-family="monospace" font-size="9" fill="#ff4d4d">WS-07 ✗ MISSING</text>
          <rect x="360" y="25" width="140" height="90" rx="3" fill="none" stroke="#e6cc00" stroke-width="1" stroke-dasharray="3,3" opacity=".5"/>
        </svg>
      </div>
      <p style="font-size:.8rem;color:var(--text-muted);margin-bottom:14px;">This snapshot will be saved with a timestamp and linked to the current detection event in the database.</p>
      <div class="modal-actions">
        <button class="modal-btn dismiss" onclick="closeModal('snapshotModal')">Cancel</button>
        <button class="modal-btn recover" onclick="saveSnapshot()"><i class="fa-solid fa-camera"></i> Save Snapshot</button>
      </div>
    </div>
  </div>
</div>
<div class="toast-stack" id="toastStack"></div>
<script src="../assets/js/spotit.js"></script>
<script>
startLiveClock('liveClock');
startCountup('roomTimer','2026-06-15 14:03:44');
// HUD timestamps
setInterval(function(){
  const ts = new Date().toLocaleTimeString('en-GB',{hour12:false}) + ' · 2026-06-15';
  ['feedTs1','feedTs2'].forEach(id=>{ const el=document.getElementById(id); if(el) el.textContent=ts; });
  const sEl = document.getElementById('snapshotTs'); if(sEl) sEl.textContent=new Date().toLocaleString('en-PH',{dateStyle:'short',timeStyle:'medium'});
},1000);

let paused = false;
function togglePause() {
  paused = !paused;
  document.getElementById('btnPause').innerHTML = paused
    ? '<i class="fa-solid fa-play"></i> Resume'
    : '<i class="fa-solid fa-pause"></i> Pause';
  showToast(paused?'warn':'success', paused?'Feed paused — no new detections while paused.':'Feed resumed — monitoring active.');
}

function switchRoom(val) { window.location.href='room-monitor.php?room='+val; }
function saveSnapshot() {
  showToast('success','Snapshot saved with timestamp '+ new Date().toLocaleTimeString('en-PH')+'.');
  closeModal('snapshotModal');
}
</script>
</body>
</html>
