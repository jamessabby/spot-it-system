<?php
require_once __DIR__ . '/../auth/service_bootstrap.php';
$active_page = 'rooms'; 
$user_role = $_SESSION['user_role'] ?? 'admin';

// Fetch all active production rooms from DB (excluding system DESK room)
$activeRooms = $monitorPdo->query("SELECT room_id, room_name, baseline_count, floor FROM rooms WHERE is_active = 1 AND room_id != 'DESK' ORDER BY room_id")->fetchAll();
$room_ids = array_column($activeRooms, 'room_id');

// Read detection_mode.json state
$dm_file = __DIR__ . '/../detection_mode.json';
$dm = [];
if (file_exists($dm_file)) {
    $dm = json_decode(file_get_contents($dm_file), true) ?: [];
}

$default_room = !empty($room_ids) ? $room_ids[0] : 'ROOM_1';
$room_id = strtoupper(trim($_GET['room'] ?? ''));
if (!$room_id || !in_array($room_id, $room_ids, true)) {
    $room_id = $default_room;
}

// Auto-sync selected room to detection_mode.json so main.py immediately switches to it
if (($dm['room_id'] ?? '') !== $room_id) {
    $dm['room_id'] = $room_id;
    $dm['reset_paused'] = true;
    $dm['reset_rois'] = true;
    $dm['updated_at'] = date('Y-m-d H:i:s');
    @file_put_contents($dm_file, json_encode($dm, JSON_PRETTY_PRINT));
}

// Selected room details
$selectedRoom = null;
foreach ($activeRooms as $ar) {
    if ($ar['room_id'] === $room_id) {
        $selectedRoom = $ar;
        break;
    }
}

$room_name      = $selectedRoom ? $selectedRoom['room_name'] : $room_id;
$baseline_count = $selectedRoom ? (int)$selectedRoom['baseline_count'] : 0;
$floor_level    = $selectedRoom ? ($selectedRoom['floor'] ?? 'Ground Floor') : 'Ground Floor';

$is_production = ($dm['mode'] ?? 'testing') === 'production';
$timer_mode    = $dm['timer_mode'] ?? 'testing_speed';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Room Cameras — <?= htmlspecialchars($room_id) ?> — S.P.O.T.-IT</title>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/css/room-monitor.css"/>
  <script>(function(){document.documentElement.setAttribute('data-theme',localStorage.getItem('spotit_theme')||'light')})();</script>
</head>
<body>
<div class="app-shell">
  <?php include '_sidebar.php'; ?>
  <div class="main-content">
    
    <!-- Topbar -->
    <div class="topbar">
      <button class="tb-btn tb-hamburger" id="hamburgerBtn" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
      <div>
        <span class="topbar-title">Room Cameras</span>
        <span class="topbar-sub"> — <strong id="topRoomId"><?= htmlspecialchars($room_id) ?></strong> · <span id="topRoomName"><?= htmlspecialchars($room_name) ?></span></span>
      </div>
      <!-- Reflects the detector's actual heartbeat, not a hardcoded label.
           Once main.py runs as an unattended service there is no terminal to
           glance at, and a crashed detector otherwise looks identical to a quiet
           room: all boxes green, no alerts, nothing visibly wrong. -->
      <div class="live-pill" id="detectorPill" title="Checking detector...">
        <div class="live-dot" id="detectorDot"></div><span id="detectorLabel">LIVE FEED</span>
      </div>
      
      <div class="topbar-right">
        <!-- Room Selector -->
        <select id="roomSelect" onchange="switchRoom(this.value)" style="font-family:var(--font-display);font-size:.75rem;font-weight:700;padding:6px 12px;border-radius:8px;border:1px solid var(--border);background:var(--bg-base);color:var(--text-primary);cursor:pointer;">
          <?php foreach($activeRooms as $ar): ?>
          <option value="<?= htmlspecialchars($ar['room_id']) ?>" <?= $ar['room_id']===$room_id?'selected':'' ?>><?= htmlspecialchars($ar['room_id']) ?> — <?= htmlspecialchars($ar['room_name']) ?></option>
          <?php endforeach; ?>
        </select>

        <!-- Speed Mode Toggle Button (Testing Speed vs Production Speed) -->
        <button class="btn btn-sm" id="btnSpeedToggle" onclick="toggleSpeedMode()" style="font-size:.72rem;font-weight:700;padding:5px 12px;border-radius:8px;background:var(--bg-base);border:1px solid var(--border);color:var(--text-primary);">
          <i class="fa-solid fa-gauge-high"></i> <span id="speedToggleLabel"><?= $timer_mode==='production_speed' ? 'Production Speed (30m / 1hr)' : 'Testing Speed (3s / 6s)' ?></span>
        </button>

        <button class="tb-btn" onclick="toggleTheme()"><i class="fa-solid fa-circle-half-stroke"></i></button>
      </div>
    </div>

    <div class="page-body">
      <div style="display:grid;grid-template-columns:1fr 340px;gap:18px;align-items:start;">

        <!-- LEFT: CCTV Feed & Controls -->
        <div style="display:flex;flex-direction:column;gap:18px;">

          <!-- Stat Cards Bar -->
          <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:12px;">
            <div class="stat-card"><div class="stat-icon alert"><i class="fa-solid fa-circle-minus"></i></div><div><div class="stat-num" id="statLiveCount"><?= $liveCount ?? 0 ?></div><div class="stat-label">Live Count</div></div></div>
            <div class="stat-card"><div class="stat-icon info"><i class="fa-solid fa-bullseye"></i></div><div><div class="stat-num" id="statBaseline"><?= $baseline_count ?></div><div class="stat-label">Baseline</div></div></div>
            <div class="stat-card"><div class="stat-icon alert"><i class="fa-solid fa-triangle-exclamation"></i></div><div><div class="stat-num" id="statDeviation" style="color:var(--text-dim);">0</div><div class="stat-label">Deviations</div></div></div>
            <div class="stat-card"><div class="stat-icon warn"><i class="fa-solid fa-clock"></i></div><div><div class="stat-num" id="statStatusBadge" style="font-size:.85rem;font-weight:700;color:var(--ok);">NORMAL</div><div class="stat-label">Room Status</div></div></div>
          </div>

          <!-- CCTV Feed Card with Camera Player & Top Header Controls -->
          <div class="card">
            <div class="card-head" style="flex-wrap:wrap;gap:10px;">
              <div class="card-title">
                <i class="fa-solid fa-video"></i> Live CCTV Feed — <span id="cardRoomTitle"><?= htmlspecialchars($room_id) ?></span>
                <span id="prodModeBadge" class="badge badge-info" style="margin-left:8px;font-size:.65rem;">
                  <?= $is_production ? 'PRODUCTION MODE' : 'TESTING MODE' ?>
                </span>
              </div>

              <!-- Top Camera Controls Header -->
              <div style="display:flex;gap:6px;align-items:center;margin-left:auto;flex-wrap:wrap;">
                <?php if ($user_role === 'admin'): ?>
                <a href="camera-setup.php?room=<?= urlencode($room_id) ?>" class="btn btn-sm" style="font-size:.72rem;font-weight:700;background:var(--ok-bg);color:var(--ok);border:1px solid var(--ok-border);text-decoration:none;">
                  <i class="fa-solid fa-sliders"></i> Calibrate in Camera Setup
                </a>
                <?php endif; ?>
                <button class="btn btn-sm" id="btnDismissRecal" onclick="dismissRecalibrationAlert()" style="font-size:.72rem;font-weight:700;background:var(--alert-bg);border:1px solid rgba(220,53,69,0.3);color:var(--alert);" title="Dismiss mass deviation / false alarm pause">
                  <i class="fa-solid fa-circle-xmark"></i> Dismiss Recalibration Alert
                </button>
                <button class="btn btn-sm" id="btnDismissAlerts" onclick="dismissAlerts()" style="font-size:.72rem;font-weight:700;background:var(--warn-bg);border:1px solid rgba(255,193,7,0.3);color:var(--warn);" title="Dismiss all active alert detections for this room">
                  <i class="fa-solid fa-bell-slash"></i> Dismiss Alerts
                </button>
                <button class="btn btn-sm" onclick="clearMovementSnapshots()" style="font-size:.72rem;font-weight:700;background:rgba(255,193,7,0.15);border:1px solid rgba(255,193,7,0.4);color:#ffc107;" title="Delete generated movement snapshot images">
                  <i class="fa-solid fa-broom"></i> Clear Snapshots
                </button>
                <button class="btn btn-sm" onclick="toggleCamFullScreen()" style="font-size:.72rem;font-weight:700;background:var(--bg-base);border:1px solid var(--border);">
                  <i class="fa-solid fa-expand"></i> Full Screen
                </button>
              </div>
            </div>

            <!-- Video Player Box -->
            <div id="camVideoBox" style="position:relative;background:#000;border-radius:0 0 12px 12px;overflow:hidden;min-height:420px;display:flex;align-items:center;justify-content:center;">
              
              <!-- Stream Overlay Badge -->
              <div style="position:absolute;top:12px;left:12px;display:flex;gap:8px;align-items:center;z-index:6;">
                <span class="badge badge-ok" style="font-size:.65rem;padding:4px 10px;background:rgba(0,0,0,.65);color:#00ff88;border:1px solid rgba(0,255,136,.3);backdrop-filter:blur(4px);">
                  <span class="bdot" style="background:#00ff88;"></span> LIVE STREAM
                </span>
                <span id="timerSpeedHud" class="badge" style="font-size:.65rem;padding:4px 10px;background:rgba(0,0,0,.65);color:#ffc107;border:1px solid rgba(255,193,7,.3);backdrop-filter:blur(4px);">
                  TIMER: <?= $timer_mode==='production_speed' ? '30m / 1hr' : '3s / 6s' ?>
                </span>
              </div>
            </div>

            <div style="padding:10px 16px;background:var(--bg-base);border-top:1px solid var(--border);display:flex;align-items:center;font-size:.72rem;color:var(--text-dim);">
              <i class="fa-solid fa-circle-info" style="margin-right:6px;"></i>
              <span id="streamFooterText">Tapo RTSP Stream Proxy · Active Room: <strong><?= htmlspecialchars($room_id) ?></strong></span>
            </div>
          </div>
        </div>

        <!-- RIGHT SIDEBAR: Stage Tracker, Event Log & Room Details -->
        <div style="display:flex;flex-direction:column;gap:18px;">

          <!-- Stage Tracker Card -->
          <div class="card">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-diagram-project"></i> Detection Stage Tracker</div></div>
            <div style="padding:16px;">
              <div style="display:flex;flex-direction:column;gap:14px;">
                <div id="stageStep1" style="display:flex;gap:10px;align-items:center;opacity:0.5;">
                  <div style="width:12px;height:12px;border-radius:50%;background:var(--text-dim);"></div>
                  <div>
                    <div style="font-size:.78rem;font-weight:700;">First Detected</div>
                    <div style="font-size:.68rem;color:var(--text-dim);" id="stageTs1">No active deviation</div>
                  </div>
                </div>

                <div id="stageStep2" style="display:flex;gap:10px;align-items:center;opacity:0.5;">
                  <div style="width:12px;height:12px;border-radius:50%;background:var(--warn);"></div>
                  <div>
                    <div style="font-size:.78rem;font-weight:700;">Stage 1: Potentially Lost</div>
                    <div style="font-size:.68rem;color:var(--text-dim);" id="stageTs2">Threshold: <span id="threshText1"><?= $timer_mode==='production_speed' ? '30 mins' : '3 secs' ?></span></div>
                  </div>
                </div>

                <div id="stageStep3" style="display:flex;gap:10px;align-items:center;opacity:0.5;">
                  <div style="width:12px;height:12px;border-radius:50%;background:var(--alert);"></div>
                  <div>
                    <div style="font-size:.78rem;font-weight:700;">Stage 2: Confirmed Missing</div>
                    <div style="font-size:.68rem;color:var(--text-dim);" id="stageTs3">Threshold: <span id="threshText2"><?= $timer_mode==='production_speed' ? '1 hour' : '6 secs' ?></span></div>
                  </div>
                </div>
              </div>
            </div>
          </div>

          <!-- Room Details Metadata Card -->
          <div class="card">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-circle-info"></i> Room Info</div></div>
            <div style="padding:16px;font-size:.78rem;display:flex;flex-direction:column;gap:10px;">
              <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--border);padding-bottom:6px;">
                <span style="color:var(--text-dim);">Room ID:</span><strong id="infoRoomId"><?= htmlspecialchars($room_id) ?></strong>
              </div>
              <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--border);padding-bottom:6px;">
                <span style="color:var(--text-dim);">Room Name:</span><strong id="infoRoomName"><?= htmlspecialchars($room_name) ?></strong>
              </div>
              <div style="display:flex;justify-content:space-between;border-bottom:1px solid var(--border);padding-bottom:6px;">
                <span style="color:var(--text-dim);">Floor Level:</span><strong id="infoFloor"><?= htmlspecialchars($floor_level) ?></strong>
              </div>
              <div style="display:flex;justify-content:space-between;">
                <span style="color:var(--text-dim);">CCTV Cameras:</span><strong>1 Active Stand</strong>
              </div>
            </div>
          </div>

          <!-- Room Event Log -->
          <div class="card">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-list-check"></i> Room Event Log</div></div>
            <div style="padding:12px;max-height:260px;overflow-y:auto;" id="roomEventLogContainer">
              <div style="text-align:center;color:var(--text-dim);font-size:.75rem;padding:14px;">Loading logs…</div>
            </div>
          </div>

        </div>
      </div>
    </div>
  </div>
</div>

<script src="../assets/js/spotit.js"></script>
<script>
let currentRoomId = "<?= htmlspecialchars($room_id) ?>";
let currentTimerMode = "<?= htmlspecialchars($timer_mode) ?>";

async function pollRoomStatus() {
  try {
    const res = await spotitFetch(`../auth/get_room_status.php?room_id=${currentRoomId}`);
    if (res && res.success) {
      document.getElementById('statLiveCount').innerText = res.live_count;
      document.getElementById('statBaseline').innerText = res.baseline_count;
      document.getElementById('statDeviation').innerText = res.missing_count;
      
      const stBadge = document.getElementById('statStatusBadge');
      if (stBadge) {
        stBadge.innerText = res.status;
        stBadge.style.color = (res.status === 'NORMAL') ? 'var(--ok)' : 'var(--alert)';
      }

      // Refresh camera image element
      const feedImg = document.getElementById('camFeedImg');
      if (feedImg) {
        feedImg.src = `../uploads/snapshots/clean_${res.room_id}.jpg?t=${Date.now()}`;
      }

      // Update Stage Tracker
      const s1 = document.getElementById('stageStep1');
      const s2 = document.getElementById('stageStep2');
      const s3 = document.getElementById('stageStep3');
      const ts1 = document.getElementById('stageTs1');
      const ts2 = document.getElementById('stageTs2');
      const ts3 = document.getElementById('stageTs3');

      if (res.active_deviation) {
        const d = res.active_deviation;
        if (s1) s1.style.opacity = '1';
        if (ts1) ts1.innerText = `Flagged at ${d.detected_at}`;

        if (d.status === 'potential') {
          if (s2) s2.style.opacity = '1';
          if (ts2) ts2.innerText = `Stage 1 active (${d.object_zone})`;
        } else if (d.status === 'confirmed_missing') {
          if (s2) s2.style.opacity = '1';
          if (s3) s3.style.opacity = '1';
          if (ts3) ts3.innerText = `Stage 2 Escalated (${d.object_zone})`;
        }
      } else {
        if (s1) s1.style.opacity = '0.4';
        if (s2) s2.style.opacity = '0.4';
        if (s3) s3.style.opacity = '0.4';
        if (ts1) ts1.innerText = 'No active deviation';
        if (ts2) ts2.innerText = `Threshold: ${res.timer_mode === 'production_speed' ? '30 mins' : '3 secs'}`;
        if (ts3) ts3.innerText = `Threshold: ${res.timer_mode === 'production_speed' ? '1 hour' : '6 secs'}`;
      }

      // Populate Event Log
      const logBox = document.getElementById('roomEventLogContainer');
      if (logBox && Array.isArray(res.logs)) {
        if (res.logs.length === 0) {
          logBox.innerHTML = '<div style="text-align:center;color:var(--text-dim);font-size:.75rem;padding:14px;">No events recorded for this room.</div>';
        } else {
          logBox.innerHTML = res.logs.slice(0, 5).map(l => `
            <div style="padding:8px 0;border-bottom:1px solid var(--border);display:flex;justify-content:space-between;align-items:center;font-size:.75rem;">
              <div>
                <strong>${escapeHtml(l.object_zone)}</strong> <span class="badge ${l.status==='confirmed_missing'?'badge-alert':(l.status==='potential'?'badge-warn':'badge-ok')}" style="font-size:.6rem;">${escapeHtml(l.status)}</span>
                <div style="font-size:.65rem;color:var(--text-dim);">${escapeHtml(l.detected_at)}</div>
              </div>
            </div>
          `).join('');
        }
      }
    }
  } catch (err) {
    console.warn("Polling room error:", err);
  }
}

async function switchRoom(newRoomId) {
  try {
    const res = await spotitFetch('../auth/toggle_detection_mode.php', {
      method: 'POST',
      body: new URLSearchParams({ room_id: newRoomId })
    });
    if (res && res.success) {
      window.location.href = `room-monitor.php?room=${newRoomId}`;
    }
  } catch (err) {
    showToast('error', 'Failed to switch room');
  }
}

async function toggleSpeedMode() {
  const newSpeed = (currentTimerMode === 'production_speed') ? 'testing_speed' : 'production_speed';
  try {
    const res = await spotitFetch('../auth/toggle_detection_mode.php', {
      method: 'POST',
      body: new URLSearchParams({ timer_mode: newSpeed })
    });
    if (res && res.success) {
      showToast('success', `Timer speed changed to ${newSpeed === 'production_speed' ? 'Production (30m / 1hr)' : 'Testing (3s / 6s)'}`);
      setTimeout(() => location.reload(), 600);
    }
  } catch (err) {
    showToast('error', 'Failed to toggle speed mode');
  }
}

async function captureBaseline() {
  try {
    const res = await spotitFetch('../auth/capture_frame.php', {
      method: 'POST',
      body: new URLSearchParams({ room_id: currentRoomId })
    });
    if (res && res.success) {
      showToast('success', 'Fresh baseline snapshot captured!');
      setTimeout(() => location.reload(), 800);
    } else {
      showToast('error', res?.message || 'Failed to capture baseline');
    }
  } catch (err) {
    showToast('error', 'Network error capturing baseline');
  }
}

async function dismissRecalibrationAlert() {
  if (!confirm(`Dismiss mass deviation / recalibration alert for ${currentRoomId} and unpause monitoring?`)) return;
  const btn = document.getElementById('btnDismissRecal');
  const restore = () => { if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> Dismiss Recalibration Alert'; } };
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Unpausing...'; }
  let reloading = false;
  try {
    const fd = new FormData();
    fd.append('room_id', currentRoomId);
    const res = await spotitFetch('../auth/reset_recalibration.php', { method: 'POST', body: fd });
    if (res && res.success) {
      reloading = true;
      safeToast('success', res.message || 'Recalibration alert dismissed!');
      setTimeout(() => location.reload(), 800);
    } else {
      safeToast('error', (res && res.message) || 'Failed to dismiss.');
    }
  } catch (e) {
    safeToast('error', 'Network error.');
  } finally {
    if (!reloading) restore();
  }
}

// Never let a missing helper leave a button spinning forever: toast failures must
// not escape, and the button is restored in finally so it resets on every path.
// (A missing assets/js/spotit.js once made showToast throw inside a catch block,
// which skipped the restore line — see BUGS.md 2026-07-31.)
function safeToast(type, msg) {
  try { showToast(type, msg); } catch (_) { console.warn('[toast]', type, msg); }
}

async function dismissAlerts() {
  if (!confirm(`Dismiss all active alerts for room ${currentRoomId}? This will mark them as recovered.`)) return;
  const btn = document.getElementById('btnDismissAlerts');
  const restore = () => { if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-bell-slash"></i> Dismiss Alerts'; } };
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Clearing...'; }
  let reloading = false;
  try {
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 8000);
    const res = await spotitFetch('../auth/dismiss_alerts.php', {
      method: 'POST',
      body: new URLSearchParams({ room_id: currentRoomId }),
      signal: controller.signal
    });
    clearTimeout(timeout);
    if (res && res.success) {
      reloading = true;
      safeToast('success', res.message || 'Alerts dismissed!');
      setTimeout(() => location.reload(), 800);
    } else {
      safeToast('error', (res && res.message) || 'Failed to dismiss alerts.');
    }
  } catch (e) {
    safeToast('error', e.name === 'AbortError' ? 'Request timed out after 8s.' : 'Network error.');
  } finally {
    if (!reloading) restore();
  }
}

async function resetSystemState() {
  if (!confirm(`Are you sure you want to RESET system state for room ${currentRoomId}? This clears baseline images, ROIs, and detection logs.`)) return;
  try {
    const res = await spotitFetch('../auth/reset_system_state.php', { method: 'POST' });
    if (res && res.success) {
      const feedImg = document.getElementById('camFeedImg');
      if (feedImg) feedImg.src = `../photos/ref_image.jpg?reset=${Date.now()}`;
      const stBadge = document.getElementById('statStatusBadge');
      if (stBadge) { stBadge.innerText = 'CALIBRATING'; stBadge.style.color = 'var(--warn)'; }
      showToast('success', 'System state reset successfully!');
      setTimeout(() => location.reload(), 800);
    } else {
      showToast('error', res?.message || 'Failed to reset system state');
    }
  } catch (err) {
    showToast('error', 'Network error');
  }
}

async function switchRoom(newRoomId) {
  try {
    const fd = new FormData();
    fd.append('room_id', newRoomId);
    await spotitFetch('../auth/toggle_detection_mode.php', { method: 'POST', body: fd });
    window.location.href = `room-monitor.php?room=${newRoomId}`;
  } catch (err) {
    window.location.href = `room-monitor.php?room=${newRoomId}`;
  }
}

function openRoiEditor() {
  window.location.href = `room-setup.php?room_id=${currentRoomId}`;
}

async function clearMovementSnapshots() {
  if (!confirm("Delete all generated movement snapshot pictures from the uploads/snapshots directory? Critical system calibration and live feed images will be preserved.")) {
    return;
  }
  try {
    const res = await spotitFetch('../auth/clear_snapshots.php', { method: 'POST' });
    if (res && res.success) {
      showToast('success', res.message || 'Movement snapshots cleared!');
    } else {
      showToast('error', res ? res.message : 'Failed to clear snapshots.');
    }
  } catch (err) {
    showToast('error', 'Error clearing snapshots.');
  }
}

function toggleCamFullScreen() {
  const box = document.getElementById('camVideoBox');
  if (!box) return;
  if (!document.fullscreenElement) {
    box.requestFullscreen();
  } else {
    document.exitFullscreen();
  }
}
</script>
<script src="../assets/js/stream-viewer.js"></script>
<script>
// ── Detector health ─────────────────────────────────────────────────────────
// main.py posts a heartbeat every 10s; the server calls it offline after 30s.
// Shows FPS as well as up/down, because a detector crawling at 2 fps is a real
// fault that a binary badge would hide.
async function pollDetectorHealth() {
  const pill  = document.getElementById('detectorPill');
  const dot   = document.getElementById('detectorDot');
  const label = document.getElementById('detectorLabel');
  if (!pill) return;
  try {
    const res = await fetch(
      `../auth/detector_heartbeat.php?room_id=${encodeURIComponent(currentRoomId)}`,
      { cache: 'no-store' }
    );
    const data = await res.json();
    const h = (data && data.health) || {};
    if (h.online) {
      dot.style.background = '#00ff88';
      label.textContent = h.fps ? `LIVE · ${h.fps} FPS` : 'LIVE FEED';
      pill.style.opacity = '1';
      pill.title = `Detector online — last seen ${h.seconds_ago}s ago (${data.deploy} mode)`;
    } else {
      dot.style.background = '#dc3545';
      label.textContent = 'DETECTOR OFFLINE';
      pill.style.opacity = '1';
      pill.title = h.last_seen
        ? `No heartbeat since ${h.last_seen}. Check that main.py is running on the lab PC.`
        : 'This detector has never checked in. Start main.py on the lab PC.';
    }
  } catch (e) {
    // Leave the badge as-is: a failed poll means OUR connection is unhappy,
    // which says nothing about whether the detector is running.
  }
}

document.addEventListener('DOMContentLoaded', () => {
  pollRoomStatus();
  setInterval(pollRoomStatus, 2000);
  pollDetectorHealth();
  setInterval(pollDetectorHealth, 10000);
  initLiveFrameStream('camVideoBox', currentRoomId);
});

// Stop polling while the tab is hidden. With a 2s interval and several staff
// leaving dashboards open all day, background tabs are a meaningful share of
// total requests against shared hosting.
document.addEventListener('visibilitychange', () => {
  if (!document.hidden) { pollRoomStatus(); pollDetectorHealth(); }
});
</script>
</body>
</html>
