<?php
require_once __DIR__ . '/../auth/service_bootstrap.php';
$active_page = 'rooms'; 
$user_role = $_SESSION['user_role'] ?? 'admin';

// Fetch all active rooms
$activeRooms = $monitorPdo->query("SELECT room_id, room_name FROM rooms WHERE is_active = 1 ORDER BY room_id")->fetchAll();
$room_ids = array_column($activeRooms, 'room_id');

$room_id = $_GET['room'] ?? '';
if (!in_array($room_id, $room_ids) && !empty($room_ids)) {
    $room_id = $room_ids[0];
}

// Fetch selected room details
$selectedRoom = null;
if ($room_id) {
    $stmt = $monitorPdo->prepare("SELECT * FROM rooms WHERE room_id = ? AND is_active = 1 LIMIT 1");
    $stmt->execute([$room_id]);
    $selectedRoom = $stmt->fetch();
}

$room_name = $selectedRoom ? $selectedRoom['room_name'] : 'Laboratory Room';
$baseline_count = $selectedRoom ? (int)$selectedRoom['baseline_count'] : 0;

// Current room stats
$liveCount = $baseline_count;
$deviation = 0;
$status = 'normal';
$detected_at = '';

if ($room_id) {
    // Check if there is an active deviation for this room
    $detStmt = $monitorPdo->prepare("SELECT live_count, deviation, status, detected_at FROM detections WHERE room_id = ? AND status IN ('pending', 'potential', 'confirmed_missing') AND is_removed = 0 ORDER BY detection_id DESC LIMIT 1");
    $detStmt->execute([$room_id]);
    $det = $detStmt->fetch();
    if ($det) {
        $liveCount = (int)$det['live_count'];
        $deviation = (int)$det['deviation'];
        $status = $det['status'];
        $detected_at = $det['detected_at'];
    }
}

// Fetch registered items and overlay active deviations
$finalZones = [];
if ($room_id) {
    $zonesStmt = $monitorPdo->prepare("
        SELECT item_name as zone_label, tier as item_type, expected_count as expected
        FROM registered_lab_items
        WHERE room_id = ?
    ");
    $zonesStmt->execute([$room_id]);
    $zonesList = $zonesStmt->fetchAll();

    $activeDetsStmt = $monitorPdo->prepare("
        SELECT object_zone, status, detected_at, deviation
        FROM detections
        WHERE room_id = ? AND status IN ('pending', 'potential', 'confirmed_missing') AND is_removed = 0
    ");
    $activeDetsStmt->execute([$room_id]);
    $activeDets = [];
    foreach ($activeDetsStmt->fetchAll() as $ad) {
        $activeDets[$ad['object_zone']] = $ad;
    }

    foreach ($zonesList as $z) {
        $lbl = $z['zone_label'];
        $z['live'] = $z['expected'];
        $z['deviation'] = 0;
        $z['status'] = 'ok';
        $z['status_label'] = 'PRESENT';
        $z['last_change'] = '—';

        if (isset($activeDets[$lbl])) {
            $det = $activeDets[$lbl];
            $dev = (int)$det['deviation'];
            $detStatus = $det['status'];
            $statusLabel = match($detStatus) {
                'confirmed_missing' => 'MISSING',
                'potential'         => 'POTENTIAL',
                default             => 'DEVIATED',
            };
            $badgeStatus = match($detStatus) {
                'confirmed_missing' => 'alert',
                'potential'         => 'warn',
                default             => 'warn',
            };
            $z['live'] = max(0, $z['expected'] + $dev);
            $z['deviation'] = $dev;
            $z['status'] = $badgeStatus;
            $z['status_label'] = $statusLabel;
            $z['last_change'] = date('H:i:s', strtotime($det['detected_at']));
        }
        $finalZones[] = $z;
    }
}

// Detection Stage calculation
$stage = 0; // 0: None, 1: First Detected, 2: Potentially Lost, 3: Confirmed Missing, 4: Resolved
$stageFirst = '—';
$stagePot = '—';
$stageConf = '—';
$stageRes = '—';

if ($room_id) {
    $latestDetStmt = $monitorPdo->prepare("SELECT * FROM detections WHERE room_id = ? ORDER BY detection_id DESC LIMIT 1");
    $latestDetStmt->execute([$room_id]);
    $latestDet = $latestDetStmt->fetch();
    if ($latestDet) {
        $detectedTime = strtotime($latestDet['detected_at']);
        $stageFirst = date('H:i:s', $detectedTime) . ' · ' . htmlspecialchars($latestDet['object_zone']);
        if ($latestDet['status'] === 'recovered' || $latestDet['status'] === 'dismissed') {
            $stage = 4;
            $stageRes = date('H:i:s', strtotime($latestDet['updated_at'])) . ' · Resolved';
        } elseif ($latestDet['status'] === 'confirmed_missing') {
            $stage = 3;
            $stagePot = date('H:i:s', $detectedTime + 1800) . ' · Auto-flagged';
            $stageConf = date('H:i:s', strtotime($latestDet['updated_at'])) . ' · Escalated to admin';
        } elseif ($latestDet['status'] === 'potential') {
            $stage = 2;
            $stagePot = date('H:i:s', $detectedTime + 1800) . ' · Auto-flagged';
        } else {
            $stage = 1;
        }
    }
}

// Room Events Log
$mappedEvents = [];
if ($room_id) {
    $roomLogsStmt = $monitorPdo->prepare("
        SELECT object_zone, object_type, detected_at, deviation, status
        FROM detections
        WHERE room_id = ?
        ORDER BY detected_at DESC
        LIMIT 5
    ");
    $roomLogsStmt->execute([$room_id]);
    $roomLogs = $roomLogsStmt->fetchAll();

    foreach ($roomLogs as $rl) {
        $statusLabel = match($rl['status']) {
            'confirmed_missing' => 'Confirmed',
            'potential'         => 'Potential',
            'dismissed'         => 'Dismissed',
            'recovered'         => 'Recovered',
            default             => 'Pending',
        };
        $stCls = match($rl['status']) {
            'confirmed_missing' => 'est-confirmed',
            'potential'         => 'est-pending',
            'dismissed'         => 'est-dismissed',
            'recovered'         => 'est-recovered',
            default             => 'est-pending',
        };
        $dev = (int)$rl['deviation'];
        $devCls = $dev < 0 ? 'neg' : ($dev > 0 ? 'pos' : 'zero');
        $devSign = $dev < 0 ? '−' . abs($dev) : ($dev > 0 ? '+' . $dev : '0');
        $msg = $dev < 0 ? "{$rl['object_type']} missing — deviation detected" : "Unexpected {$rl['object_type']} detected";
        
        $mappedEvents[] = [
            'zone'        => $rl['object_zone'],
            'message'     => $msg,
            'time'        => date('H:i:s', strtotime($rl['detected_at'])),
            'devSign'     => $devSign,
            'devCls'      => $devCls,
            'stCls'       => $stCls,
            'statusLabel' => $statusLabel
        ];
    }
}
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
        <span class="topbar-title">Room Cameras</span>
        <span class="topbar-sub"> — <?= htmlspecialchars($room_id) ?> · <?= htmlspecialchars($room_name) ?></span>
      </div>
      <div class="live-pill"><div class="live-dot"></div>LIVE FEED</div>
      <div class="topbar-right">
        <span style="font-family:var(--font-mono);font-size:.7rem;color:var(--text-dim);" id="liveClock"></span>
        <!-- Room selector -->
        <select id="roomSelect" onchange="switchRoom(this.value)" style="font-family:var(--font-display);font-size:.72rem;font-weight:700;padding:6px 10px;border-radius:7px;border:1px solid var(--border);background:var(--bg-base);color:var(--text-primary);cursor:pointer;">
          <?php foreach($activeRooms as $ar): ?>
          <option value="<?= htmlspecialchars($ar['room_id']) ?>" <?= $ar['room_id']===$room_id?'selected':'' ?>><?= htmlspecialchars($ar['room_id']) ?></option>
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
            <div class="stat-card"><div class="stat-icon alert"><i class="fa-solid fa-circle-minus"></i></div><div><div class="stat-num" id="liveCount"><?= $liveCount ?></div><div class="stat-label">Live Count</div></div></div>
            <div class="stat-card"><div class="stat-icon info"><i class="fa-solid fa-bullseye"></i></div><div><div class="stat-num"><?= $baseline_count ?></div><div class="stat-label">Baseline</div></div></div>
            <div class="stat-card"><div class="stat-icon alert"><i class="fa-solid <?= $deviation < 0 ? 'fa-arrow-down' : ($deviation > 0 ? 'fa-arrow-up' : 'fa-minus') ?>"></i></div><div><div class="stat-num" style="color:<?= $deviation < 0 ? 'var(--alert)' : ($deviation > 0 ? 'var(--green-main)' : 'var(--text-dim)') ?>;"><?= $deviation < 0 ? '−' . abs($deviation) : ($deviation > 0 ? '+' . $deviation : '0') ?></div><div class="stat-label">Deviation</div></div></div>
            <div class="stat-card"><div class="stat-icon warn"><i class="fa-solid fa-clock"></i></div><div><div class="stat-num countdown <?= $status==='confirmed_missing' ? 'alert' : 'warn' ?>" id="roomTimer" style="font-size:1.2rem;">--:--:--</div><div class="stat-label">Elapsed</div></div></div>
          </div>

          <!-- Tapo CCTV Live Feeds -->
          <div class="card">
            <div class="card-head">
              <div class="card-title"><i class="fa-solid fa-video"></i> Live CCTV Feeds — <?= htmlspecialchars($room_id) ?></div>
              <div style="display:flex;gap:6px;align-items:center;">
                <span id="streamStatusBadge" class="badge" style="font-size:.62rem;padding:3px 9px;background:var(--warn-bg);color:var(--warn);">
                  <span class="bdot" style="background:var(--warn);"></span> Connecting…
                </span>
                <button class="btn btn-sm" id="btnSnapshot" onclick="captureSnapshot()">
                  <i class="fa-solid fa-camera"></i> Snapshot
                </button>
                <button class="btn btn-sm" id="btnFullscreen" onclick="goFullscreen('cam1Canvas')">
                  <i class="fa-solid fa-expand"></i>
                </button>
              </div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:0;">

              <!-- CAM 1 — Tapo Live Feed -->
              <div class="cctv-panel" id="feed1" style="position:relative;min-height:220px;cursor:pointer;" onclick="goFullscreen('cam1Canvas')">
                <div class="cctv-label" id="cam1Label">CAM-01 · <?= htmlspecialchars($room_id) ?> · NORTH</div>
                <div class="cctv-ts" id="feedTs1"></div>
                <div class="cctv-motion" id="motionBadge1"><span class="cctv-rec"></span>CONNECTING</div>
                <!-- Canvas for MJPEG / snapshot rendering -->
                <canvas id="cam1Canvas" style="width:100%;height:100%;object-fit:cover;display:none;"></canvas>
                <!-- ROI overlay drawn on top of live feed -->
                <canvas id="cam1ROI" style="position:absolute;inset:0;width:100%;height:100%;pointer-events:none;"></canvas>
                <!-- Offline placeholder -->
                <div id="cam1Offline" style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;background:#0a1510;color:rgba(100,255,160,.3);">
                  <i class="fa-solid fa-video-slash" style="font-size:2rem;margin-bottom:.5rem;"></i>
                  <div style="font-family:var(--font-mono);font-size:.7rem;" id="cam1OfflineMsg">Connecting to Tapo camera…</div>
                </div>
                <div class="cctv-scanline" id="cam1Scanline" style="display:none;"></div>
              </div>

              <!-- CAM 2 — Tapo Live Feed -->
              <div class="cctv-panel" id="feed2" style="position:relative;min-height:220px;border-left:1px solid rgba(0,0,0,.2);cursor:pointer;" onclick="goFullscreen('cam2Canvas')">
                <div class="cctv-label" id="cam2Label">CAM-02 · <?= htmlspecialchars($room_id) ?> · SOUTH</div>
                <div class="cctv-ts" id="feedTs2"></div>
                <div class="cctv-motion" id="motionBadge2"><span class="cctv-rec"></span>CONNECTING</div>
                <canvas id="cam2Canvas" style="width:100%;height:100%;object-fit:cover;display:none;"></canvas>
                <canvas id="cam2ROI" style="position:absolute;inset:0;width:100%;height:100%;pointer-events:none;"></canvas>
                <div id="cam2Offline" style="position:absolute;inset:0;display:flex;flex-direction:column;align-items:center;justify-content:center;background:#0a1510;color:rgba(100,255,160,.3);">
                  <i class="fa-solid fa-video-slash" style="font-size:2rem;margin-bottom:.5rem;"></i>
                  <div style="font-family:var(--font-mono);font-size:.7rem;" id="cam2OfflineMsg">Connecting to Tapo camera…</div>
                </div>
                <div class="cctv-scanline" id="cam2Scanline" style="display:none;"></div>
              </div>
            </div>

            <!-- Feed controls & stream info -->
            <div style="display:flex;gap:8px;padding:10px 14px;background:var(--bg-base);border-top:1px solid var(--border);align-items:center;flex-wrap:wrap;">
              <span style="font-family:var(--font-mono);font-size:.64rem;color:var(--text-dim);" id="streamInfo">
                STREAM: Tapo RTSP → MJPEG Proxy · Polling every 1s
              </span>
              <div style="margin-left:auto;display:flex;gap:6px;">
                <button class="btn btn-sm" onclick="showToast('info','Recalibration requires admin access.')">
                  <i class="fa-solid fa-rotate"></i> Recalibrate
                </button>
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
                  <?php if (empty($finalZones)): ?>
                  <tr>
                    <td colspan="7" class="text-center py-3" style="color:var(--text-dim);">No registered zones for this room.</td>
                  </tr>
                  <?php else: ?>
                  <?php foreach ($finalZones as $z):
                      $dev = (int)$z['deviation'];
                      $devClass = $dev < 0 ? 'neg' : ($dev > 0 ? 'pos' : 'zero');
                      $devSign = $dev < 0 ? '−' . abs($dev) : ($dev > 0 ? '+' . $dev : '0');
                  ?>
                  <tr <?= $z['status']==='alert'?'style="background:rgba(208,2,27,.03);"':'' ?>>
                    <td><span class="col-id"><?= htmlspecialchars($z['zone_label']) ?></span></td>
                    <td><span class="col-mono"><?= htmlspecialchars($z['item_type']) ?></span></td>
                    <td><span class="col-mono"><?= (int)$z['expected'] ?></span></td>
                    <td><span class="col-mono <?= $z['status']==='alert'?'alert':'' ?>"><?= (int)$z['live'] ?></span></td>
                    <td><span class="dev-chip dev-<?= $devClass ?>"><?= $devSign ?></span></td>
                    <td><span class="badge badge-<?= $z['status'] ?>"><span class="bdot"></span><?= $z['status_label'] ?></span></td>
                    <td><span class="col-mono"><?= htmlspecialchars($z['last_change']) ?></span></td>
                  </tr>
                  <?php endforeach; ?>
                  <?php endif; ?>
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
                <!-- First Detected -->
                <div class="stage-row <?= $stage >= 1 ? 'done' : 'inactive' ?>">
                  <div class="stage-r-dot"></div>
                  <div>
                    <div class="stage-r-label">First Detected</div>
                    <div class="stage-r-ts"><?= htmlspecialchars($stageFirst) ?></div>
                  </div>
                </div>
                <div class="stage-connector <?= $stage >= 2 ? 'done' : '' ?>"></div>
                
                <!-- Potentially Lost -->
                <div class="stage-row <?= $stage >= 2 ? ($stage >= 3 ? 'done warn' : 'active warn') : 'inactive' ?>">
                  <div class="stage-r-dot warn"></div>
                  <div>
                    <div class="stage-r-label">Potentially Lost (30 min)</div>
                    <div class="stage-r-ts"><?= htmlspecialchars($stagePot) ?></div>
                  </div>
                </div>
                <div class="stage-connector <?= $stage >= 3 ? 'done' : '' ?>"></div>
                
                <!-- Confirmed Missing -->
                <div class="stage-row <?= $stage >= 3 ? ($stage >= 4 ? 'done' : 'active') : 'inactive' ?>">
                  <div class="stage-r-dot alert <?= $stage == 3 ? 'pulse' : '' ?>"></div>
                  <div>
                    <div class="stage-r-label">Confirmed Missing (1 hr)</div>
                    <div class="stage-r-ts"><?= htmlspecialchars($stageConf) ?></div>
                  </div>
                </div>
                <div class="stage-connector <?= $stage >= 4 ? 'done' : '' ?>"></div>
                
                <!-- Resolved / Recovered -->
                <div class="stage-row <?= $stage >= 4 ? 'done' : 'inactive' ?>">
                  <div class="stage-r-dot ok"></div>
                  <div>
                    <div class="stage-r-label">Resolved / Recovered</div>
                    <div class="stage-r-ts"><?= htmlspecialchars($stageRes) ?></div>
                  </div>
                </div>
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
            <?php if (empty($mappedEvents)): ?>
            <div class="p-3 text-center" style="color:var(--text-dim);font-size:.8rem;">No events recorded for this room.</div>
            <?php else: ?>
            <?php foreach ($mappedEvents as $re): ?>
            <div class="event-row" style="cursor:default;">
              <div class="event-body">
                <div class="event-tag"><?= htmlspecialchars($re['zone']) ?></div>
                <div class="event-title" style="font-size:.76rem;"><?= htmlspecialchars($re['message']) ?></div>
                <div class="event-meta">
                  <span class="event-time"><?= $re['time'] ?></span>
                  <span class="event-dev <?= $re['devCls'] ?>"><?= $re['devSign'] ?></span>
                  <span class="event-status-tag <?= $re['stCls'] ?>"><?= $re['statusLabel'] ?></span>
                </div>
              </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <!-- Room info -->
          <div class="card">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-circle-info"></i> Room Info</div></div>
            <div style="padding:14px;display:flex;flex-direction:column;gap:8px;">
              <?php
              $info = [
                  ['Room ID', $room_id],
                  ['Room Name', $room_name],
                  ['Floor', $selectedRoom ? $selectedRoom['floor'] : '—'],
                  ['Cameras', ($selectedRoom ? $selectedRoom['camera_count'] : 0) . ' × CCTV camera(s)'],
                  ['Registered Items', $baseline_count],
                  ['Monitoring Since', '08:00:00 today'],
                  ['Last Calibrated', $selectedRoom && $selectedRoom['last_calibrated'] ? date('F j, Y', strtotime($selectedRoom['last_calibrated'])) : '—']
              ];
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

/* ══════════════════════════════════════
   TAPO CCTV INTEGRATION ENGINE
   Three-tier approach:
   1. Try MJPEG stream via auth/tapo_stream.php?action=stream
   2. Fall back to snapshot polling (1s interval) if stream fails
   3. Show offline placeholder if camera unreachable
══════════════════════════════════════ */
const ROOM_ID   = '<?= htmlspecialchars($room_id) ?>';
const CAMS      = [1, 2];
const camState  = { 1: 'connecting', 2: 'connecting' };
let snapTimers  = {};

// Check camera status on load, then start appropriate feed method
document.addEventListener('DOMContentLoaded', () => {
  CAMS.forEach(camNum => initCamera(camNum));
  // Update clock stamps every second
  setInterval(updateTimestamps, 1000);
});

async function initCamera(camNum) {
  setCamState(camNum, 'connecting');
  try {
    const data = await spotitFetch(
      `../auth/tapo_stream.php?action=status&room_id=${ROOM_ID}&cam=${camNum}`
    );
    if (!data) { setCamState(camNum, 'offline', 'No response from stream handler'); return; }

    if (data.status === 'offline') {
      setCamState(camNum, 'offline', data.reason || 'Camera offline');
      return;
    }

    // Camera is online — attempt MJPEG stream first, fall back to snapshots
    if (data.hls_url) {
      startHLSFeed(camNum, data.hls_url);
    } else {
      startSnapshotPolling(camNum);
    }

  } catch (e) {
    setCamState(camNum, 'offline', 'Connection error');
  }
}

/* ── HLS playback (if nginx-rtmp/HLS is configured on server) ── */
function startHLSFeed(camNum, hlsUrl) {
  const canvas = document.getElementById(`cam${camNum}Canvas`);
  // Create a <video> element for HLS
  const video = document.createElement('video');
  video.style.cssText = 'width:100%;height:100%;object-fit:cover;position:absolute;inset:0;';
  video.muted = true;
  video.autoplay = true;
  video.playsInline = true;

  if (video.canPlayType('application/vnd.apple.mpegurl')) {
    // Safari native HLS
    video.src = hlsUrl;
    document.getElementById(`feed${camNum}`).appendChild(video);
    video.play().catch(() => startSnapshotPolling(camNum));
    setCamState(camNum, 'online');
  } else if (window.Hls) {
    const hls = new Hls({ lowLatencyMode: true, liveSyncDurationCount: 2 });
    hls.loadSource(hlsUrl);
    hls.attachMedia(video);
    hls.on(Hls.Events.MANIFEST_PARSED, () => {
      document.getElementById(`feed${camNum}`).appendChild(video);
      video.play();
      setCamState(camNum, 'online');
    });
    hls.on(Hls.Events.ERROR, (_, d) => {
      if (d.fatal) { hls.destroy(); startSnapshotPolling(camNum); }
    });
  } else {
    startSnapshotPolling(camNum);
  }
}

/* ── Snapshot polling fallback — fetches a JPEG every 1 second ── */
function startSnapshotPolling(camNum) {
  setCamState(camNum, 'polling');
  const canvas  = document.getElementById(`cam${camNum}Canvas`);
  const offline = document.getElementById(`cam${camNum}Offline`);
  const ctx     = canvas.getContext('2d');
  let failCount = 0;

  async function poll() {
    try {
      const data = await spotitFetch(
        `../auth/tapo_stream.php?action=snapshot&room_id=${ROOM_ID}&cam=${camNum}&_=${Date.now()}`
      );
      if (data && data.success && data.data) {
        const img = new Image();
        img.onload = () => {
          canvas.width  = img.naturalWidth  || 640;
          canvas.height = img.naturalHeight || 360;
          ctx.drawImage(img, 0, 0);
          canvas.style.display = '';
          offline.style.display = 'none';
          document.getElementById(`cam${camNum}Scanline`).style.display = '';
          setCamState(camNum, 'online');
          failCount = 0;
          drawROIOverlay(camNum, canvas);
        };
        img.src = data.data;
      } else {
        failCount++;
        if (failCount >= 3) setCamState(camNum, 'offline', 'Snapshot unavailable');
      }
    } catch (e) { failCount++; }
    if (camState[camNum] !== 'offline' || failCount < 10) {
      snapTimers[camNum] = setTimeout(poll, 1000);
    }
  }
  poll();
}

/* ── ROI overlay — draws zone boxes on top of live frame ── */
function drawROIOverlay(camNum, sourceCanvas) {
  const overlay = document.getElementById(`cam${camNum}ROI`);
  if (!overlay || !sourceCanvas.width) return;
  overlay.width  = sourceCanvas.width;
  overlay.height = sourceCanvas.height;
  const ctx = overlay.getContext('2d');
  ctx.clearRect(0, 0, overlay.width, overlay.height);

  // ROI definitions — in production these come from registered_lab_items
  const rois = [
    { label:'WS-01', x:.05, y:.08, w:.14, h:.20, state:'ok'  },
    { label:'WS-02', x:.20, y:.08, w:.14, h:.20, state:'ok'  },
    { label:'WS-03', x:.35, y:.08, w:.14, h:.20, state:'ok'  },
    { label:'WS-04', x:.50, y:.08, w:.14, h:.20, state:'ok'  },
    { label:'WS-05', x:.65, y:.08, w:.14, h:.20, state:'ok'  },
    { label:'WS-07', x:.65, y:.35, w:.14, h:.20, state:'alert'},
  ];

  const W = overlay.width, H = overlay.height;
  rois.forEach(roi => {
    const color = roi.state === 'ok' ? '#5cffac' : '#ff4d4d';
    ctx.strokeStyle = color;
    ctx.lineWidth   = roi.state === 'alert' ? 2.5 : 1.5;
    ctx.fillStyle   = roi.state === 'alert' ? 'rgba(255,77,77,.12)' : 'rgba(0,200,120,.08)';
    if (roi.state === 'alert') {
      ctx.setLineDash([8, 4]);
    } else {
      ctx.setLineDash([]);
    }
    const rx = roi.x*W, ry = roi.y*H, rw = roi.w*W, rh = roi.h*H;
    ctx.fillRect(rx, ry, rw, rh);
    ctx.strokeRect(rx, ry, rw, rh);
    ctx.setLineDash([]);
    ctx.fillStyle = color;
    ctx.font      = `bold ${Math.max(10, W*0.022)}px monospace`;
    ctx.fillText(roi.label + (roi.state === 'ok' ? ' ✓' : ' ✗'), rx + 4, ry + 14);
  });
}

/* ── Snapshot capture button ── */
async function captureSnapshot() {
  const canvas = document.getElementById('cam1Canvas');
  if (!canvas || !canvas.width) {
    showToast('warn', 'No live feed available — cannot capture snapshot.');
    return;
  }
  const link = document.createElement('a');
  link.download = `spotit_${ROOM_ID}_${Date.now()}.jpg`;
  link.href = canvas.toDataURL('image/jpeg', 0.9);
  link.click();
  showToast('success', 'Snapshot saved to downloads.');
}

/* ── Fullscreen ── */
function goFullscreen(canvasId) {
  const el = document.getElementById(canvasId);
  if (!el) return;
  if (el.requestFullscreen)        el.requestFullscreen();
  else if (el.webkitRequestFullscreen) el.webkitRequestFullscreen();
}

/* ── Camera state management ── */
function setCamState(camNum, state, reason) {
  camState[camNum] = state;
  const badge   = document.getElementById(`motionBadge${camNum}`);
  const offline = document.getElementById(`cam${camNum}Offline`);
  const msg     = document.getElementById(`cam${camNum}OfflineMsg`);
  const statusBadge = document.getElementById('streamStatusBadge');

  const stateMap = {
    connecting: { text:'CONNECTING', bg:'rgba(26,106,181,.2)',  color:'rgba(130,190,255,.8)' },
    polling:    { text:'LIVE (1fps)', bg:'rgba(0,150,100,.2)',  color:'rgba(100,255,160,.8)' },
    online:     { text:'LIVE',        bg:'rgba(0,150,100,.2)',  color:'rgba(100,255,160,.8)' },
    offline:    { text:'OFFLINE',     bg:'rgba(208,2,27,.2)',   color:'rgba(255,120,120,.8)' },
  };
  const s = stateMap[state] || stateMap.offline;
  if (badge) {
    badge.style.background = s.bg;
    badge.style.color      = s.color;
    badge.style.borderColor= s.color.replace('.8)',',.2)');
    badge.innerHTML        = `<span style="width:5px;height:5px;border-radius:50%;background:${s.color};display:inline-block;margin-right:4px;${state!=='offline'?'animation:spotit-blink 1s infinite':''}"></span>${s.text}`;
  }
  if (offline) offline.style.display = state === 'offline' ? 'flex' : 'none';
  if (msg && reason) msg.textContent = reason;

  // Update overall status badge
  if (statusBadge) {
    const anyOnline = Object.values(camState).some(s => s === 'online' || s === 'polling');
    const anyOffline = Object.values(camState).every(s => s === 'offline');
    if (anyOnline) {
      statusBadge.style.background = 'var(--ok-bg)';
      statusBadge.style.color      = 'var(--ok)';
      statusBadge.innerHTML        = '<span class="bdot" style="background:var(--ok);animation:spotit-blink 1s infinite;"></span> LIVE';
    } else if (anyOffline) {
      statusBadge.style.background = 'var(--alert-bg)';
      statusBadge.style.color      = 'var(--alert)';
      statusBadge.innerHTML        = '<span class="bdot" style="background:var(--alert);"></span> OFFLINE';
    }
  }
}

function updateTimestamps() {
  const ts = new Date().toLocaleTimeString('en-GB', { hour12: false });
  ['feedTs1','feedTs2'].forEach(id => {
    const el = document.getElementById(id);
    if (el) el.textContent = ts;
  });
}

function switchRoom(val) { window.location.href = 'room-monitor.php?room=' + val; }
function saveSnapshot()  { captureSnapshot(); closeModal('snapshotModal'); }
</script>
</body>
</html>
