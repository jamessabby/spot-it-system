<?php
/**
 * S.P.O.T.-IT — Desk Sandbox Testing Page
 * pages/desk-sandbox.php
 *
 * Dedicated sandbox testing environment to verify Tapo CCTV camera accuracy.
 * Allows logging of True Positive, False Positive, True Negative, and False Negative
 * trials directly in the database, automatically computing precision, recall, and accuracy metrics.
 */
require_once __DIR__ . '/../auth/service_bootstrap.php';
$active_page = 'sandbox';
$user_role   = $_SESSION['user_role'] ?? 'staff';
$uname       = $_SESSION['user_name'] ?? 'User';

// Enforce access control: Admin & Staff only
if ($user_role !== 'admin' && $user_role !== 'staff') {
    header('Location: dashboard-student.php');
    exit();
}

// 1. Fetch current status of the 'DESK' room from monitor DB
$deskRoom = null;
try {
    $stmt = $monitorPdo->prepare("SELECT * FROM rooms WHERE room_id = 'DESK' LIMIT 1");
    $stmt->execute();
    $deskRoom = $stmt->fetch();
} catch (PDOException $e) {
    // Fallback
}

$deskDetections = [];
try {
    $detStmt = $monitorPdo->prepare("
        SELECT * FROM detections 
        WHERE room_id = 'DESK' AND status IN ('pending', 'potential', 'confirmed_missing') AND is_removed = 0 
        ORDER BY detection_id DESC
    ");
    $detStmt->execute();
    $deskDetections = $detStmt->fetchAll();
} catch (PDOException $e) {
    // Fallback
}

$deskExpectedCount = $deskRoom ? (int)$deskRoom['baseline_count'] : 0;
$deskLiveCount = $deskExpectedCount;
$activeDeviation = null;
if (!empty($deskDetections)) {
    $activeDeviation = $deskDetections[0];
    $deskLiveCount = (int)$activeDeviation['live_count'];
}

// 2. Fetch classification metrics from accuracy_trials table
$tp = 0; $tn = 0; $fp = 0; $fn = 0;
try {
    $countsStmt = $monitorPdo->query("SELECT classification, COUNT(*) as qty FROM accuracy_trials GROUP BY classification");
    foreach ($countsStmt->fetchAll() as $row) {
        if ($row['classification'] === 'TP') $tp = (int)$row['qty'];
        elseif ($row['classification'] === 'TN') $tn = (int)$row['qty'];
        elseif ($row['classification'] === 'FP') $fp = (int)$row['qty'];
        elseif ($row['classification'] === 'FN') $fn = (int)$row['qty'];
    }
} catch (PDOException $e) {
    // Fallback
}

$totalTrials = $tp + $tn + $fp + $fn;

// Metrics calculation
$precision = ($tp + $fp) > 0 ? ($tp / ($tp + $fp)) * 100 : 0;
$recall = ($tp + $fn) > 0 ? ($tp / ($tp + $fn)) * 100 : 0;
$accuracy = $totalTrials > 0 ? (($tp + $tn) / $totalTrials) * 100 : 0;
$f1 = ($precision + $recall) > 0 ? 2 * (($precision * $recall) / ($precision + $recall)) : 0;

// Fetch last 10 trials
$trials = [];
try {
    $trialsStmt = $monitorPdo->query("SELECT * FROM accuracy_trials ORDER BY trial_timestamp DESC LIMIT 15");
    $trials = $trialsStmt->fetchAll();
} catch (PDOException $e) {
    // Fallback
}

// Fetch sandbox detections log
$sandboxLogs = [];
try {
    $logStmt = $monitorPdo->query("
        SELECT *, 
               TIMESTAMPDIFF(SECOND, detected_at, COALESCE(updated_at, NOW())) AS duration_seconds
        FROM detections 
        WHERE room_id = 'DESK' 
        ORDER BY detection_id DESC
    ");
    $sandboxLogs = $logStmt->fetchAll();
} catch (PDOException $e) {
    // Fallback
}

// 3. Read current tracking mode from detection_mode.json
$tracking_mode = 'registered';
$mode_file_path = __DIR__ . '/../detection_mode.json';
if (file_exists($mode_file_path)) {
    $mdata = json_decode(file_get_contents($mode_file_path), true);
    $tracking_mode = $mdata['tracking_mode'] ?? 'registered';
}

?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Desk Sandbox Test — S.P.O.T.-IT</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <style>
  @keyframes pulse-red {
    0% { transform: scale(0.95); opacity: 0.6; }
    100% { transform: scale(1.05); opacity: 1; }
  }
  .hud-loader {
    width: 36px; height: 36px;
    border: 3px solid rgba(92,255,172,0.15);
    border-top-color: #5cffac;
    border-radius: 50%;
    animation: spinner-hud 0.8s linear infinite;
  }
  @keyframes spinner-hud {
    to { transform: rotate(360deg); }
  }
  </style>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/css/skeleton.css"/>
  <style>
    .sandbox-grid {
      display: flex;
      flex-direction: column;
      gap: 18px;
      margin-top: 18px;
    }
    .metric-card {
      border-radius: var(--radius);
      padding: 16px;
      background: var(--bg-card);
      border: 1px solid var(--border);
      display: flex;
      flex-direction: column;
      justify-content: space-between;
    }
    .metric-title {
      font-size: .75rem;
      color: var(--text-dim);
      font-family: var(--font-display);
      font-weight: 700;
      text-transform: uppercase;
      letter-spacing: .08em;
      display: flex;
      align-items: center;
    }
    .info-tooltip-wrap {
      position: relative;
      display: inline-flex;
      align-items: center;
      margin-left: 5px;
      cursor: pointer;
    }
    .info-tooltip-wrap i {
      color: var(--text-dim);
      font-size: .72rem;
      transition: color 0.15s ease;
    }
    .info-tooltip-wrap:hover i {
      color: var(--green-main);
    }
    .info-tooltip-box {
      visibility: hidden;
      width: 220px;
      background-color: var(--text-primary);
      color: #ffffff;
      text-align: left;
      border-radius: 8px;
      padding: 8px 12px;
      position: absolute;
      z-index: 1000;
      bottom: 130%;
      left: 50%;
      transform: translateX(-50%);
      opacity: 0;
      transition: opacity 0.2s ease, visibility 0.2s ease;
      font-size: .68rem;
      font-family: var(--font-body);
      font-weight: 400;
      line-height: 1.4;
      box-shadow: 0 6px 20px rgba(0,0,0,0.25);
      pointer-events: none;
      text-transform: none;
      letter-spacing: normal;
    }
    .info-tooltip-box::after {
      content: "";
      position: absolute;
      top: 100%;
      left: 50%;
      margin-left: -5px;
      border-width: 5px;
      border-style: solid;
      border-color: var(--text-primary) transparent transparent transparent;
    }
    .info-tooltip-wrap:hover .info-tooltip-box {
      visibility: visible;
      opacity: 1;
    }
    .metric-value {
      font-size: 2.2rem;
      font-weight: 900;
      font-family: var(--font-display);
      margin: 8px 0;
      color: var(--text-primary);
    }
    .metric-footer {
      font-size: .7rem;
      color: var(--text-muted);
    }
    .matrix-box {
      display: grid;
      grid-template-columns: repeat(4, 1fr);
      gap: 12px;
      margin-top: 14px;
    }
    @media (max-width: 768px) {
      .matrix-box { grid-template-columns: 1fr 1fr; }
    }
    .matrix-cell {
      border: 1px solid var(--border);
      border-radius: 9px;
      padding: 12px;
      text-align: center;
      background: var(--bg-base);
    }
    .matrix-cell.tp { border-color: rgba(220,53,69,.2); background: rgba(220,53,69,.02); }
    .matrix-cell.tn { border-color: rgba(40,167,69,.2); background: rgba(40,167,69,.02); }
    .matrix-cell.fp { border-color: rgba(255,193,7,.2); background: rgba(255,193,7,.02); }
    .matrix-cell.fn { border-color: rgba(23,162,184,.2); background: rgba(23,162,184,.02); }
    
    .matrix-cell .val {
      font-size: 1.8rem;
      font-weight: 900;
      color: var(--text-primary);
      line-height: 1;
    }
    
    /* Full Screen Video Container Fix */
    #camVideoBox:fullscreen, #camVideoBox:-webkit-full-screen {
      width: 100vw !important;
      height: 100vh !important;
      max-width: none !important;
      max-height: none !important;
      border-radius: 0 !important;
      background: #000000 !important;
      margin: 0 !important;
      padding: 0 !important;
      display: flex !important;
      justify-content: center !important;
      align-items: center !important;
      z-index: 999999 !important;
    }
    #camVideoBox:fullscreen img, #camVideoBox:-webkit-full-screen img {
      width: 100vw !important;
      height: 100vh !important;
      max-width: 100% !important;
      max-height: 100% !important;
      object-fit: contain !important;
      border-radius: 0 !important;
    }  margin-bottom: 2px;
    }
    .matrix-cell .lbl {
      font-size: .62rem;
      font-weight: 700;
      color: var(--text-dim);
      text-transform: uppercase;
    }
    
    .badge-TP { background: var(--alert-bg); color: var(--alert); border: 1px solid rgba(220,53,69,.15); }
    .badge-TN { background: var(--ok-bg); color: var(--ok); border: 1px solid rgba(40,167,69,.15); }
    .badge-FP { background: var(--warn-bg); color: var(--warn); border: 1px solid rgba(255,193,7,.15); }
    .badge-FN { background: var(--info-bg); color: var(--info); border: 1px solid rgba(23,162,184,.15); }
    
    .badge-classification {
      padding: 3px 8px;
      border-radius: 5px;
      font-family: var(--font-display);
      font-weight: 800;
      font-size: .65rem;
      display: inline-block;
    }
  </style>
  <script>(function(){document.documentElement.setAttribute('data-theme',localStorage.getItem('spotit_theme')||'light')})();</script>
</head>
<body data-skeleton="dashboard">
<script src="../assets/js/skeleton.js"></script>

<div class="app-shell">
  <?php include '_sidebar.php'; ?>

  <div class="main-content">
    
    <!-- TOPBAR -->
    <div class="topbar">
      <button class="tb-btn tb-hamburger" id="hamburgerBtn" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
      <div>
        <span class="topbar-title">Desk Sandbox Test</span>
        <span class="topbar-sub">— Tapo CCTV Accuracy Calibration &amp; Analysis</span>
      </div>
      <div class="live-pill"><div class="live-dot"></div>SANDBOX</div>
      <div class="topbar-right">
        <span style="font-family:var(--font-mono);font-size:.7rem;color:var(--text-dim);" id="liveClock"></span>
        <button class="tb-btn" onclick="location.reload()" title="Refresh Page"><i class="fa-solid fa-rotate-right"></i></button>
        <button class="tb-btn" onclick="toggleTheme()"><i class="fa-solid fa-circle-half-stroke"></i></button>
      </div>
    </div>

    <!-- PAGE BODY -->
    <div class="page-body">
      
      <!-- Stats row -->
      <div class="stat-grid" style="grid-template-columns: repeat(4, 1fr);">
        <div class="metric-card">
          <div class="metric-title">
            Accuracy Rate
            <span class="info-tooltip-wrap">
              <i class="fa-solid fa-circle-info"></i>
              <span class="info-tooltip-box">Percentage of total correct predictions: (TP + TN) / Total Trials.</span>
            </span>
          </div>
          <div class="metric-value" id="metricAccuracy"><?= number_format($accuracy, 1) ?>%</div>
          <div class="metric-footer" id="metricTotalTrials">Total trials logged: <?= $totalTrials ?></div>
        </div>
        <div class="metric-card">
          <div class="metric-title">
            Precision
            <span class="info-tooltip-wrap">
              <i class="fa-solid fa-circle-info"></i>
              <span class="info-tooltip-box">False alarm resistance: TP / (TP + FP). Higher precision means fewer false alarms.</span>
            </span>
          </div>
          <div class="metric-value" id="metricPrecision"><?= number_format($precision, 1) ?>%</div>
          <div class="metric-footer">True Pos / (True Pos + False Pos)</div>
        </div>
        <div class="metric-card">
          <div class="metric-title">
            Recall
            <span class="info-tooltip-wrap">
              <i class="fa-solid fa-circle-info"></i>
              <span class="info-tooltip-box">Detection sensitivity: TP / (TP + FN). Higher recall means fewer missed items.</span>
            </span>
          </div>
          <div class="metric-value" id="metricRecall"><?= number_format($recall, 1) ?>%</div>
          <div class="metric-footer">True Pos / (True Pos + False Neg)</div>
        </div>
        <div class="metric-card">
          <div class="metric-title">
            F1-Score
            <span class="info-tooltip-wrap">
              <i class="fa-solid fa-circle-info"></i>
              <span class="info-tooltip-box">Harmonic mean balancing Precision &amp; Recall: 2 * (P * R) / (P + R).</span>
            </span>
          </div>
          <div class="metric-value" id="metricF1"><?= number_format($f1, 1) ?>%</div>
          <div class="metric-footer">Harmonic mean of P &amp; R</div>
        </div>
      </div>

      <div class="sandbox-grid">
        
        <!-- Full-Width Live CCTV Feed & Calibration -->
        <div style="display:flex;flex-direction:column;gap:18px;">
          
          <!-- Live CCTV Feed -->
          <div class="card" id="videoFeedCard" style="position:relative; overflow:hidden; border:1px solid var(--border); box-shadow:0 8px 30px rgba(0,0,0,0.15);">
            <div class="card-head" style="border-bottom:1px solid var(--border); flex-wrap:wrap; row-gap:8px;">
              <div class="card-title" style="display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-video" style="color:var(--ok);"></i> 
                <span>Desk Calibration Feed</span>
              </div>
              <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <button class="btn btn-sm" id="btnSetupRoi" onclick="openRoiEditor()" style="font-size:.7rem; padding:4px 10px; border-radius:6px; background:var(--bg-base); border:1px solid var(--border); color:var(--text-primary); transition: opacity 0.2s;" <?= $tracking_mode === 'unregistered' ? 'disabled style="opacity:0.4; cursor:not-allowed;" title="Disabled in Left Items mode"' : '' ?>>
                  <i class="fa-solid fa-pen-ruler"></i> Setup ROI Zones
                </button>
                <button class="btn btn-sm" id="btnCaptureBaseline" onclick="captureBaselineFrame()" style="font-size:.7rem; padding:4px 10px; border-radius:6px; background:var(--bg-base); border:1px solid var(--border); color:var(--text-primary); font-weight:600;" title="Capture clean reference image of desk">
                  <i class="fa-solid fa-camera"></i> Capture Baseline
                </button>
                <button class="btn btn-sm" id="btnResetSystem" onclick="resetSystemState()" style="font-size:.7rem; padding:4px 10px; border-radius:6px; background:var(--alert-bg); border:1px solid rgba(220,53,69,0.3); color:var(--alert); font-weight:700;" title="Reset reference image, clear ROIs, and truncate all logs">
                  <i class="fa-solid fa-rotate-left"></i> Reset System
                </button>
                <button class="btn btn-sm" onclick="toggleCamFullScreen()" style="font-size:.7rem; padding:4px 10px; border-radius:6px; background:var(--bg-base); border:1px solid var(--border); color:var(--text-primary); font-weight:600;" title="Full Screen View">
                  <i class="fa-solid fa-expand"></i> Full Screen
                </button>
                <!-- Tracking Mode Toggle -->
                <div id="trackingModeToggle" style="display:flex; gap:4px; background:var(--bg-base); border:1px solid var(--border); border-radius:8px; padding:3px;">
                  <button id="btnModeRegistered"
                    onclick="switchTrackingMode('registered')"
                    style="font-size:.7rem; padding:4px 12px; border-radius:6px; border:none; cursor:pointer;
                           background:<?= $tracking_mode === 'registered' ? 'var(--green-main)' : 'transparent' ?>;
                           color:<?= $tracking_mode === 'registered' ? '#ffffff' : 'var(--text-primary)' ?>; font-weight:700;">
                    <i class="fa-solid fa-box-archive"></i> Registered
                  </button>
                  <button id="btnModeUnregistered"
                    onclick="switchTrackingMode('unregistered')"
                    style="font-size:.7rem; padding:4px 12px; border-radius:6px; border:none; cursor:pointer;
                           background:<?= $tracking_mode === 'unregistered' ? 'var(--warn)' : 'transparent' ?>;
                           color:<?= $tracking_mode === 'unregistered' ? '#ffffff' : 'var(--text-primary)' ?>; font-weight:700;">
                    <i class="fa-solid fa-person-walking-luggage"></i> Left Items
                  </button>
                </div>
                <!-- Dismiss Recalibration Alert Button -->
                <button class="btn btn-sm" id="btnDismissAlert" onclick="dismissRecalibrationAlert('DESK')" style="font-size:.7rem; padding:4px 10px; border-radius:6px; background:var(--alert-bg); border:1px solid rgba(220,53,69,0.3); color:var(--alert); font-weight:700;" title="Dismiss Mass Deviation / False Alarm Pause">
                  <i class="fa-solid fa-circle-xmark"></i> Dismiss Recalibration Alert
                </button>
                <select id="streamQualitySelect" onchange="toggleQuality(this.value)" class="form-control" style="font-size:.65rem; padding:2px 6px; height:auto; width:auto; border-radius:6px; background:var(--bg-base); border-color:var(--border);">
                  <option value="high">1080p Premium</option>
                  <option value="low">480p Balanced</option>
                </select>
                <span id="streamStatusBadge" class="badge badge-warn" style="font-size:.65rem; padding:3px 10px; border-radius:6px; white-space:normal; max-width:260px; line-height:1.35; text-align:left;">
                  <span class="bdot" style="background:var(--warn);"></span> CONNECTING
                </span>
              </div>
            </div>
            
            <!-- Video Container (Balanced Compact Dimensions with Full Screen Support) -->
            <div id="camVideoBox" style="position:relative; width:100%; max-width:760px; margin:0 auto; height:380px; aspect-ratio: 16/9; background:#050d08; display:flex; justify-content:center; align-items:center; overflow:hidden; border-radius:6px;">
              <!-- TWO ALTERNATING IMAGES FOR FLICKER-FREE DOM BUFFER SWAPPING -->
              <img id="cam1Stream" style="position:absolute; inset:0; width:100%; height:100%; object-fit:contain; opacity:0; transition:opacity 0.05s ease; border-radius:6px;" />
              <img id="cam2Stream" style="position:absolute; inset:0; width:100%; height:100%; object-fit:contain; opacity:0; transition:opacity 0.05s ease; border-radius:6px;" />
              
              <!-- Live HUD Overlay -->
              <div id="liveHudOverlay" style="position:absolute; inset:0; pointer-events:none; display:flex; flex-direction:column; justify-content:space-between; padding:16px; box-sizing:border-box; display:none; z-index:5;">
                <!-- HUD Top -->
                <div style="display:flex; justify-content:space-between; width:100%;">
                  <div style="display:flex; gap:8px; align-items:center; background:rgba(0,0,0,0.65); padding:4px 10px; border-radius:5px; border:1px solid rgba(255,255,255,0.1); font-family:var(--font-mono); font-size:.62rem; color:#fff; backdrop-filter:blur(3px);">
                    <span style="display:inline-block; width:6px; height:6px; background:#ff3b30; border-radius:50%; animation: pulse-red 1s infinite alternate;"></span>
                    <span>LIVE</span>
                    <span style="opacity:0.5;">|</span>
                    <span id="hudResolution">960x540 (Real-time HUD)</span>
                  </div>
                </div>
                <!-- HUD Bottom -->
                <div style="display:flex; justify-content:space-between; align-items:flex-end; width:100%;">
                  <div style="background:rgba(0,0,0,0.65); padding:6px 12px; border-radius:5px; border:1px solid rgba(255,255,255,0.1); font-family:var(--font-mono); font-size:.62rem; color:var(--text-primary); line-height:1.4; backdrop-filter:blur(3px);">
                    <div style="color:#5cffac; font-weight:700; font-size:.68rem;">CHANNEL 01 (DESK)</div>
                    <div style="opacity:0.75;" id="hudStats">FPS: ~7-10 · Buffer: Local SSD · Protocol: HTTP</div>
                  </div>
                  <div style="background:rgba(0,0,0,0.65); padding:4px 8px; border-radius:5px; border:1px solid rgba(255,255,255,0.1); font-family:var(--font-mono); font-size:.6rem; color:#fff; opacity:0.8; backdrop-filter:blur(3px);">
                    <?= date('Y-m-d') ?>
                  </div>
                </div>
              </div>

              <!-- Premium Offline/Connecting UI -->
              <div id="cam1Offline" style="position:absolute; inset:0; display:flex; flex-direction:column; align-items:center; justify-content:center; background:#060e0a; color:rgba(100,255,160,.4); z-index:10;">
                <div class="hud-loader" style="margin-bottom:12px;"></div>
                <i class="fa-solid fa-video-slash" id="offlineIcon" style="font-size:2rem; margin-bottom:.7rem; display:none; color:var(--alert);"></i>
                <div style="font-family:var(--font-mono); font-size:.7rem; color:#5cffac; font-weight:700; text-transform:uppercase; letter-spacing:0.05em;" id="cam1OfflineMsg">Establishing secure tapo stream…</div>
              </div>
            </div>
          </div>

          <!-- Live desk status -->
          <div class="card">
            <div class="card-head">
              <div class="card-title"><i class="fa-solid fa-desktop"></i> Current Desk Status (Room ID: `DESK`)</div>
            </div>
            <div style="padding:16px;">
              <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:14px;">
                <div style="padding:12px;background:var(--bg-base);border:1px solid var(--border);border-radius:9px;text-align:center;">
                  <div style="font-family:var(--font-mono);font-size:1.8rem;font-weight:900;color:var(--text-primary);" id="statBaseline"><?= $tracking_mode === 'unregistered' ? 'N/A' : $deskExpectedCount ?></div>
                  <div style="font-size:.65rem;color:var(--text-dim);text-transform:uppercase;font-weight:700;margin-top:2px;" id="statBaselineLabel"><?= $tracking_mode === 'unregistered' ? 'Clean Baseline' : 'Baseline Count' ?></div>
                </div>
                <div style="padding:12px;background:var(--bg-base);border:1px solid var(--border);border-radius:9px;text-align:center;">
                  <div style="font-family:var(--font-mono);font-size:1.8rem;font-weight:900;color:var(--ok);" id="statLiveCount"><?= $deskExpectedCount ?></div>
                  <div style="font-size:.65rem;color:var(--text-dim);text-transform:uppercase;font-weight:700;margin-top:2px;" id="statLiveCountLabel"><?= $tracking_mode === 'unregistered' ? 'Unattended Left Items' : 'Live Count' ?></div>
                </div>
                <div style="padding:12px;background:var(--bg-base);border:1px solid var(--border);border-radius:9px;text-align:center;display:flex;flex-direction:column;justify-content:center;align-items:center;">
                  <span class="badge badge-<?= $deskLiveCount === $deskExpectedCount ? 'ok' : 'alert' ?>" id="statStatusBadge" style="font-size:.75rem;padding:4px 12px;">
                    <span class="bdot"></span><?= $deskLiveCount === $deskExpectedCount ? 'NORMAL' : 'DEVIATION' ?>
                  </span>
                  <div style="font-size:.65rem;color:var(--text-dim);text-transform:uppercase;font-weight:700;margin-top:4px;">Status</div>
                </div>
              </div>
              
              <div id="activeDeviationAlert">
                <?php if ($activeDeviation): ?>
                <div style="padding:12px 14px;background:var(--alert-bg);border:1px solid rgba(220,53,69,.15);border-radius:9px;margin-bottom:14px;display:flex;align-items:start;gap:9px;font-size:.8rem;color:var(--text-primary);">
                  <i class="fa-solid fa-triangle-exclamation" style="color:var(--alert);margin-top:2px;flex-shrink:0;"></i>
                  <div>
                    <strong>Active Deviation:</strong> Zone <strong><?= htmlspecialchars($activeDeviation['object_zone']) ?></strong> flagged at <strong><?= date('M j, Y · H:i:s', strtotime($activeDeviation['detected_at'])) ?></strong>.<br/>
                    Match Score: <code><?= htmlspecialchars($activeDeviation['match_score']) ?></code> &nbsp;·&nbsp; Change %: <code><?= htmlspecialchars($activeDeviation['roi_change_pct']) ?>%</code>.
                  </div>
                </div>
                <?php else: ?>
                <div style="padding:12px 14px;background:var(--ok-bg);border:1px solid rgba(40,167,69,.15);border-radius:9px;margin-bottom:14px;display:flex;align-items:center;gap:9px;font-size:.8rem;color:var(--text-primary);">
                  <i class="fa-solid fa-circle-check" style="color:var(--ok);flex-shrink:0;"></i>
                  <span>All items present on the baseline crop. Camera reporting 100% baseline match.</span>
                </div>
                <?php endif; ?>
              </div>
              
              <div style="font-size:.76rem;color:var(--text-muted);border-top:1px solid var(--border);padding-top:12px;display:flex;justify-content:space-between;align-items:center;">
                <span>Tapo Stream static IP: <code>192.168.18.11:554</code></span>
                <span class="badge badge-green" style="font-size:.6rem;"><i class="fa-solid fa-wifi"></i> CCTV CONNECTED</span>
              </div>
            </div>
          </div>

          <!-- Confusion matrix breakdown card (Moved further down below Camera & Status) -->
          <div class="card">
            <div class="card-head">
              <div class="card-title"><i class="fa-solid fa-chart-pie"></i> Confusion Matrix Breakdown</div>
            </div>
            <div style="padding:16px;">
              <p style="font-size:.72rem;color:var(--text-muted);line-height:1.5;margin-bottom:10px;">
                Positive Class = Absent (Missing Deviation Event) &nbsp;·&nbsp; Negative Class = Present (Normal State)
              </p>
              <div class="matrix-box">
                <div class="matrix-cell tn">
                  <div class="val" id="cellTN"><?= $tn ?></div>
                  <div class="lbl">True Neg (TN)</div>
                </div>
                <div class="matrix-cell fp">
                  <div class="val" id="cellFP"><?= $fp ?></div>
                  <div class="lbl">False Pos (FP)</div>
                </div>
                <div class="matrix-cell fn">
                  <div class="val" id="cellFN"><?= $fn ?></div>
                  <div class="lbl">False Neg (FN)</div>
                </div>
                <div class="matrix-cell tp">
                  <div class="val" id="cellTP"><?= $tp ?></div>
                  <div class="lbl">True Pos (TP)</div>
                </div>
              </div>
            </div>
          </div>

        </div>

      </div>

      <!-- Sandbox Item Detections Log (Room: DESK) -->
      <div class="card" style="margin-top: 20px;">
        <div class="card-head" style="display:flex; justify-content:space-between; align-items:center;">
          <div class="card-title"><i class="fa-solid fa-list-check"></i> Sandbox Item Detections Log (Testing)</div>
          <button class="btn btn-sm" onclick="truncateSandboxDetections()" style="font-size: .72rem; padding: 4px 10px; background: rgba(220,53,69,0.1); border: 1px solid var(--red-main); color: var(--red-main); border-radius: 6px;">
            <i class="fa-solid fa-trash-can"></i> Clear Sandbox Logs
          </button>
        </div>
        <div style="overflow-x:auto; overflow-y:auto; max-height:360px; padding: 0 12px 12px 12px;">
          <table class="data-table" style="width:100%; border-collapse: collapse; font-size: 0.78rem;">
            <thead>
              <tr style="background:var(--bg-base); border-bottom:1px solid var(--border);">
                <th style="padding:10px;">Timestamp</th>
                <th style="padding:10px;">Room</th>
                <th style="padding:10px;">Object</th>
                <th style="padding:10px;">Zone</th>
                <th style="padding:10px;">Status</th>
                <th style="padding:10px;">Duration</th>
                <th style="padding:10px; text-align:center;">Snapshot A (Missing)</th>
                <th style="padding:10px; text-align:center;">Snapshot B (Interaction)</th>
                <th style="padding:10px; text-align:center;">Actions / Update Status</th>
              </tr>
            </thead>
            <tbody id="sandboxLogsTbody">
              <?php if (empty($sandboxLogs)): ?>
              <tr>
                <td colspan="9" class="text-center py-4" style="color:var(--text-dim); padding: 20px;">No sandbox detections recorded yet.</td>
              </tr>
              <?php else: ?>
              <?php foreach ($sandboxLogs as $log): 
                $duration = '—';
                if ($log['status'] === 'dismissed' || $log['status'] === 'recovered') {
                  $sec = (int)$log['duration_seconds'];
                  if ($sec < 60) $duration = $sec . 's';
                  else {
                    $mins = floor($sec / 60);
                    $rem = $sec % 60;
                    $duration = $mins . 'm ' . $rem . 's';
                  }
                } else {
                  $sec = time() - strtotime($log['detected_at']);
                  if ($sec < 60) $duration = $sec . 's (Ongoing)';
                  else {
                    $mins = floor($sec / 60);
                    $duration = $mins . 'm (Ongoing)';
                  }
                }
                
                $badgeCls = match($log['status']) {
                  'confirmed_missing' => 'badge-alert',
                  'potential' => 'badge-warn',
                  'recovered' => 'badge-ok',
                  'dismissed' => 'badge-dim',
                  default => 'badge-info'
                };
              ?>
              <tr style="border-bottom:1px solid var(--border);" id="det-row-<?= $log['detection_id'] ?>">
                <td style="padding:10px; font-family:var(--font-mono);"><?= htmlspecialchars($log['detected_at']) ?></td>
                <td style="padding:10px; font-weight:700;"><?= htmlspecialchars($log['room_id']) ?></td>
                <td style="padding:10px; font-weight:600;"><?= htmlspecialchars($log['object_type']) ?></td>
                <td style="padding:10px; color:var(--text-dim);"><?= htmlspecialchars($log['object_zone']) ?></td>
                <td style="padding:10px;"><span class="badge <?= $badgeCls ?>"><?= ucfirst(str_replace('_', ' ', $log['status'])) ?></span></td>
                <td style="padding:10px; font-family:var(--font-mono);"><?= $duration ?></td>
                <td style="padding:10px; text-align:center;">
                  <?php if (!empty($log['snapshot_path'])): ?>
                    <a href="../uploads/snapshots/<?= htmlspecialchars($log['snapshot_path']) ?>" target="_blank">
                      <img src="../uploads/snapshots/<?= htmlspecialchars($log['snapshot_path']) ?>" style="height:36px; border-radius:4px; border:1px solid var(--border); transition: transform 0.15s; cursor:pointer;" onmouseover="this.style.transform='scale(2.5)'; this.style.zIndex='999';" onmouseout="this.style.transform='scale(1)'"/>
                    </a>
                  <?php else: ?>
                    <span style="color:var(--text-dim);">—</span>
                  <?php endif; ?>
                </td>
                <td style="padding:10px; text-align:center;">
                  <?php if (!empty($log['snapshot_path_b'])): ?>
                    <a href="../uploads/snapshots/<?= htmlspecialchars($log['snapshot_path_b']) ?>" target="_blank">
                      <img src="../uploads/snapshots/<?= htmlspecialchars($log['snapshot_path_b']) ?>" style="height:36px; border-radius:4px; border:1px solid var(--border); transition: transform 0.15s; cursor:pointer;" onmouseover="this.style.transform='scale(2.5)'; this.style.zIndex='999';" onmouseout="this.style.transform='scale(1)'"/>
                    </a>
                  <?php else: ?>
                    <span style="color:var(--text-dim);">—</span>
                  <?php endif; ?>
                </td>
                <td style="padding:10px; text-align:center;">
                  <div style="display:flex; gap:4px; justify-content:center; flex-wrap:wrap;">
                    <?php if ($log['status'] !== 'recovered'): ?>
                    <button class="btn btn-sm" onclick="changeDetectionStatus(<?= $log['detection_id'] ?>, 'recovered')" style="font-size:.65rem; padding:3px 8px; background:var(--ok-bg); color:var(--ok); border:1px solid var(--ok-border); font-weight:700;" title="Mark Recovered / Resolved">
                      <i class="fa-solid fa-check"></i> Recovered
                    </button>
                    <?php endif; ?>
                    <?php if ($log['status'] !== 'dismissed'): ?>
                    <button class="btn btn-sm" onclick="changeDetectionStatus(<?= $log['detection_id'] ?>, 'dismissed')" style="font-size:.65rem; padding:3px 8px; background:var(--alert-bg); color:var(--alert); border:1px solid var(--alert-border); font-weight:700;" title="Dismiss False Alarm">
                      <i class="fa-solid fa-xmark"></i> Dismiss
                    </button>
                    <?php endif; ?>
                    <?php if ($log['status'] !== 'confirmed_missing'): ?>
                    <button class="btn btn-sm" onclick="changeDetectionStatus(<?= $log['detection_id'] ?>, 'confirmed_missing')" style="font-size:.65rem; padding:3px 8px; background:var(--warn-bg); color:var(--warn); border:1px solid var(--warn-border); font-weight:700;" title="Confirm Missing">
                      <i class="fa-solid fa-triangle-exclamation"></i> Flag Missing
                    </button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>
</div>

<!-- ══════ ROI EDITOR MODAL ══════ -->
<div class="modal-overlay" id="roiEditorModal" onclick="if(event.target===this)closeRoiEditor()">
  <div class="modal-box" style="width: 1200px; max-width: 96vw; max-height: 95vh;">
    <div class="modal-head">
      <div class="modal-title"><i class="fa-solid fa-flask"></i> Interactive ROI Zone Editor</div>
      <div class="modal-close" onclick="closeRoiEditor()"><i class="fa-solid fa-xmark"></i></div>
    </div>
    <div class="modal-body" style="padding:0;">
      <div style="display:grid; grid-template-columns: 1fr 320px; gap:0;">
        
        <!-- Canvas Workspace -->
        <div style="padding:16px; background:#0f1712; display:flex; flex-direction:column; align-items:center; justify-content:center; border-right:1px solid var(--border); overflow:hidden;">
          <div style="width:100%; display:flex; gap:10px; margin-bottom:12px;">
            <button class="btn btn-sm btn-primary" onclick="captureBaselineFrame()">
              <i class="fa-solid fa-camera"></i> Capture New Baseline
            </button>
            <span style="color:#64ffa0; font-family:var(--font-mono); font-size:.7rem; display:flex; align-items:center; margin-left:auto;" id="editorStatus">
              Load an image to start drawing
            </span>
          </div>
          <div style="position:relative; width:100%; max-height:680px; overflow:auto; background:#000; border-radius:6px; display:flex; justify-content:center; align-items:center;" id="canvasContainer">
            <canvas id="editorCanvas" style="cursor:crosshair; max-width:100%; height:auto;"></canvas>
          </div>
          <p style="font-size:.68rem; color:rgba(255,255,255,.4); margin:8px 0 0 0; text-align:center; width:100%;">
            <i class="fa-solid fa-circle-info"></i> Click and drag on the image above to define an inspection zone.
          </p>
        </div>

        <!-- Right Side: Item metadata forms -->
        <div style="padding:16px; display:flex; flex-direction:column; gap:14px; background:var(--bg-card); max-height:560px; overflow-y:auto;">
          <div style="font-family:var(--font-display); font-size:.8rem; font-weight:800; text-transform:uppercase; color:var(--text-dim);">Drawn Zones</div>
          
          <div id="roiList" style="display:flex; flex-direction:column; gap:10px; flex:1; overflow-y:auto; min-height:200px;">
            <!-- Rendered by JS -->
          </div>

          <div style="border-top:1px solid var(--border); padding-top:12px; margin-top:auto;">
            <button class="btn btn-primary" style="width:100%; justify-content:center; padding:10px;" onclick="saveRoisToSystem()">
              <i class="fa-solid fa-floppy-disk"></i> Save &amp; Sync Config
            </button>
          </div>
        </div>

      </div>
    </div>
  </div>
</div>

<div class="toast-stack" id="toastStack"></div>
<script src="../assets/js/spotit.js"></script>
<script>
startLiveClock('liveClock');

async function submitTrial(event) {
  event.preventDefault();
  const form = document.getElementById('trialForm');
  const expected = form.elements['expected'].value;
  const detected = form.elements['detected'].value;
  const notes = document.getElementById('trialNotes').value.trim();
  
  try {
    const result = await spotitFetch('../auth/log_sandbox_trial.php', {
      method: 'POST',
      body: new URLSearchParams({ expected, detected, notes })
    });
    if (result && result.success) {
      showToast('success', `Trial logged! Classification: ${result.classification}`);
      setTimeout(() => location.reload(), 900);
    } else {
      showToast('error', (result && result.message) || 'Failed to log trial.');
    }
  } catch (e) {
    showToast('error', 'Communication error.');
  }
}

async function clearLogs() {
  if (!confirm('Are you sure you want to permanently clear all logged trials?')) return;
  try {
    const result = await spotitFetch('../auth/log_sandbox_trial.php', {
      method: 'POST',
      body: new URLSearchParams({ action: 'clear' })
    });
    if (result && result.success) {
      showToast('success', 'Accuracy logs cleared.');
      setTimeout(() => location.reload(), 900);
    } else {
      showToast('error', 'Failed to clear logs.');
    }
  } catch (e) {
    showToast('error', 'Communication error.');
  }
}

/* ══════════════════════════════════════
   TRACKING MODE TOGGLE
══════════════════════════════════════ */
async function switchTrackingMode(mode) {
  const btnReg   = document.getElementById('btnModeRegistered');
  const btnUnreg = document.getElementById('btnModeUnregistered');
  try {
    const res = await spotitFetch('../auth/save_tracking_mode.php', {
      method: 'POST',
      body: new URLSearchParams({ tracking_mode: mode })
    });
    if (res && res.success) {
      // Update button styles with high contrast
      if (mode === 'registered') {
        btnReg.style.background   = 'var(--green-main)';
        btnReg.style.color        = '#ffffff';
        btnUnreg.style.background = 'transparent';
        btnUnreg.style.color      = 'var(--text-primary)';
      } else {
        btnUnreg.style.background = 'var(--warn)';
        btnUnreg.style.color      = '#ffffff';
        btnReg.style.background   = 'transparent';
        btnReg.style.color        = 'var(--text-primary)';
      }
      const label = mode === 'registered' ? 'Registered Items' : 'Left Items / Unregistered';
      showToast('success', `Tracking mode set to: ${label}. Config updated dynamically.`);
    } else {
      showToast('error', res ? res.message : 'Failed to set tracking mode.');
    }
  } catch (e) {
    showToast('error', 'Network error setting tracking mode.');
  }
}

/* ══════════════════════════════════════
   RECALIBRATION ALERT DISMISSAL
══════════════════════════════════════ */
async function dismissRecalibrationAlert(roomId = 'DESK') {
  if (!confirm('Dismiss mass deviation / recalibration alert and unpause live monitoring?')) return;
  
  const btn = document.getElementById('btnDismissAlert');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Unpausing...';
  }

  try {
    const fd = new FormData();
    fd.append('room_id', roomId);
    const res = await spotitFetch('../auth/reset_recalibration.php', { method: 'POST', body: fd });
    
    if (res && res.success) {
      showToast('success', res.message || 'Recalibration alert dismissed! Live monitoring unpaused.');
      setTimeout(() => location.reload(), 1000);
    } else {
      showToast('error', res?.message || 'Failed to dismiss recalibration alert.');
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> Dismiss Recalibration Alert';
      }
    }
  } catch (e) {
    showToast('error', 'Network error resetting recalibration state.');
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-circle-xmark"></i> Dismiss Recalibration Alert';
    }
  }
}

/* ══════════════════════════════════════
   FULL SCREEN TOGGLE
══════════════════════════════════════ */
function toggleCamFullScreen() {
  const container = document.getElementById('videoFeedCard');
  if (!container) return;
  if (!document.fullscreenElement) {
    if (container.requestFullscreen) container.requestFullscreen();
    else if (container.webkitRequestFullscreen) container.webkitRequestFullscreen();
  } else {
    if (document.exitFullscreen) document.exitFullscreen();
  }
}

/* ══════════════════════════════════════
   MASTER SYSTEM STATE RESET
══════════════════════════════════════ */
async function resetSystemState() {
  if (!confirm('Are you sure you want to RESET the system state?\n\nThis will clear:\n- Reference baseline image (photos/ref_image.jpg)\n- All bounded ROI zones (rois.json)\n- All snapshot image files (uploads/snapshots/)\n- All sandbox detection logs & trial metrics')) return;

  const btn = document.getElementById('btnResetSystem');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Resetting...';
  }

  try {
    const res = await spotitFetch('../auth/reset_system_state.php', { method: 'POST' });
    if (res && res.success) {
      showToast('success', res.message || 'System state reset successfully!');
      setTimeout(() => location.reload(), 1000);
    } else {
      showToast('error', res?.message || 'Failed to reset system state.');
      if (btn) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-rotate-left"></i> Reset System';
      }
    }
  } catch (e) {
    showToast('error', 'Network error during reset.');
    if (btn) {
      btn.disabled = false;
      btn.innerHTML = '<i class="fa-solid fa-rotate-left"></i> Reset System';
    }
  }
}

/* ══════════════════════════════════════
   LIVE CCTV SANDBOX INTEGRATION
══════════════════════════════════════ */
const ROOM_ID = 'DESK';
let currentQuality = 'high';
const camState = { 1: 'connecting' };
let livePollInterval = null;

function setCamState(state, reason) {
  camState[1] = state;
  const badge = document.getElementById('streamStatusBadge');
  const hud = document.getElementById('liveHudOverlay');
  if (!badge) return;

  if (state === 'online') {
    badge.className = 'badge badge-ok';
    badge.innerHTML = '<span class="bdot" style="background:var(--ok);"></span> ONLINE';
    if (hud) hud.style.display = 'flex';
  } else if (state === 'offline') {
    badge.className = 'badge badge-alert';
    badge.innerHTML = `<span class="bdot" style="background:var(--alert);"></span> OFFLINE: ${reason || 'Disconnected'}`;
    if (hud) hud.style.display = 'none';
  } else {
    badge.className = 'badge badge-warn';
    badge.innerHTML = '<span class="bdot" style="background:var(--warn);"></span> CONNECTING';
    if (hud) hud.style.display = 'none';
  }
}

function toggleQuality(val) {
  currentQuality = val;
  initCamera();
}

async function initCamera() {
  setCamState('connecting');
  const img1 = document.getElementById('cam1Stream');
  const img2 = document.getElementById('cam2Stream');
  const offline = document.getElementById('cam1Offline');
  const loader = offline.querySelector('.hud-loader');
  const icon = document.getElementById('offlineIcon');
  const msg = document.getElementById('cam1OfflineMsg');
  const resLabel = document.getElementById('hudResolution');
  const statsLabel = document.getElementById('hudStats');
  
  if (!img1 || !img2 || !offline) return;
  
  if (livePollInterval) clearTimeout(livePollInterval);
  
  img1.style.opacity = 0;
  img2.style.opacity = 0;
  offline.style.display = 'flex';
  loader.style.display = 'block';
  icon.style.display = 'none';
  msg.textContent = 'Establishing secure tapo stream…';

  try {
    const data = await spotitFetch(`../auth/tapo_stream.php?action=status&room_id=${ROOM_ID}&cam=1`);
    if (data && data.status === 'online') {
      
      setCamState('online');
      offline.style.display = 'none';
      
      resLabel.textContent = '960x540 (Real-time Model Detection HUD)';
      statsLabel.textContent = 'FPS: ~7-10 · Buffer: Local Solid State Drive · Protocol: HTTP';

      let activeImgId = 1;
      // Fetch initial frame
      img1.src = `../uploads/snapshots/live_${ROOM_ID}.jpg?t=${Date.now()}`;
      img1.onload = () => {
        img1.style.opacity = 1;
      };

      function pollNext() {
        if (camState[1] !== 'online') return;
        const nextUrl = `../uploads/snapshots/live_${ROOM_ID}.jpg?t=${Date.now()}`;
        
        const targetImg = document.getElementById(activeImgId === 1 ? 'cam2Stream' : 'cam1Stream');
        const currentImg = document.getElementById(activeImgId === 1 ? 'cam1Stream' : 'cam2Stream');
        
        targetImg.onload = () => {
          targetImg.style.opacity = 1;
          currentImg.style.opacity = 0;
          activeImgId = (activeImgId === 1) ? 2 : 1;
          livePollInterval = setTimeout(pollNext, 120);
        };
        targetImg.onerror = () => {
          livePollInterval = setTimeout(pollNext, 200);
        };
        targetImg.src = nextUrl;
      }
      pollNext();
    } else {
      setCamState('offline', data ? data.reason : 'Unreachable');
      loader.style.display = 'none';
      icon.style.display = 'block';
      msg.textContent = data ? data.reason : 'Camera offline.';
    }
  } catch (e) {
    setCamState('offline', 'Connection error');
    loader.style.display = 'none';
    icon.style.display = 'block';
    msg.textContent = 'Network error.';
  }
}

/* ══════════════════════════════════════
   WEB-BASED INTERACTIVE ROI CANVAS EDITOR
══════════════════════════════════════ */
let editorRois = [];
let activeDrawing = false;
let startX = 0, startY = 0;
let currentX = 0, currentY = 0;
let editorImg = new Image();
let baseScaleX = 1, baseScaleY = 1;

function openRoiEditor() {
  openModal('roiEditorModal');
  loadExistingRoisForEditor();
  loadBaselineImageForEditor();
}

function closeRoiEditor() {
  closeModal('roiEditorModal');
}

async function loadExistingRoisForEditor() {
  try {
    const response = await fetch('../auth/get_rois.php?t=' + Date.now());
    if (response.ok) {
      const data = await response.json();
      editorRois = data.map(r => ({
        label: r.label,
        x: r.x,
        y: r.y,
        w: r.w,
        h: r.h,
        tier: r.tier || 'tier1'
      }));
      renderRoiList();
      redrawEditorCanvas();
    }
  } catch (e) {
    console.error('Failed to load existing ROIs', e);
  }
}

function loadBaselineImageForEditor() {
  editorImg.src = "../uploads/snapshots/DESK_baseline.jpg?t=" + Date.now();
  editorImg.onload = () => {
    const canvas = document.getElementById('editorCanvas');
    canvas.width = editorImg.naturalWidth || 960;
    canvas.height = editorImg.naturalHeight || 540;
    document.getElementById('editorStatus').textContent = `Baseline loaded: ${canvas.width}x${canvas.height}`;
    redrawEditorCanvas();
    initCanvasEvents();
  };
  editorImg.onerror = () => {
    document.getElementById('editorStatus').textContent = 'No baseline captured yet. Snap one first.';
  };
}

async function captureBaselineFrame() {
  const status = document.getElementById('editorStatus');
  status.textContent = 'Snapping baseline frame from Tapo...';
  try {
    const res = await spotitFetch('../auth/capture_frame.php?room_id=DESK');
    if (res && res.success) {
      showToast('success', 'Baseline frame captured!');
      loadBaselineImageForEditor();
    } else {
      showToast('error', res ? res.message : 'Capture failed');
      status.textContent = 'Capture failed.';
    }
  } catch (e) {
    showToast('error', 'Network error snapping frame.');
    status.textContent = 'Network error.';
  }
}

function initCanvasEvents() {
  const canvas = document.getElementById('editorCanvas');
  if (canvas.dataset.initialized) return;
  canvas.dataset.initialized = "true";

  canvas.addEventListener('mousedown', (e) => {
    const rect = canvas.getBoundingClientRect();
    baseScaleX = canvas.width / rect.width;
    baseScaleY = canvas.height / rect.height;
    
    startX = (e.clientX - rect.left) * baseScaleX;
    startY = (e.clientY - rect.top) * baseScaleY;
    activeDrawing = true;
  });

  canvas.addEventListener('mousemove', (e) => {
    if (!activeDrawing) return;
    const rect = canvas.getBoundingClientRect();
    currentX = (e.clientX - rect.left) * baseScaleX;
    currentY = (e.clientY - rect.top) * baseScaleY;
    redrawEditorCanvas(true);
  });

  canvas.addEventListener('mouseup', () => {
    if (!activeDrawing) return;
    activeDrawing = false;
    
    const rx = Math.round(Math.min(startX, currentX));
    const ry = Math.round(Math.min(startY, currentY));
    const rw = Math.round(Math.abs(currentX - startX));
    const rh = Math.round(Math.abs(currentY - startY));

    if (rw > 10 && rh > 10) {
      const label = `zone_${editorRois.length + 1}`;
      editorRois.push({ label, x: rx, y: ry, w: rw, h: rh, tier: 'tier1' });
      renderRoiList();
      redrawEditorCanvas();
    }
  });
}

function redrawEditorCanvas(drawPreview = false) {
  const canvas = document.getElementById('editorCanvas');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');
  ctx.clearRect(0, 0, canvas.width, canvas.height);
  
  if (editorImg.complete) {
    ctx.drawImage(editorImg, 0, 0);
  }

  editorRois.forEach((roi, idx) => {
    ctx.strokeStyle = '#5cffac';
    ctx.lineWidth = 2;
    ctx.fillStyle = 'rgba(92,255,172,0.1)';
    ctx.fillRect(roi.x, roi.y, roi.w, roi.h);
    ctx.strokeRect(roi.x, roi.y, roi.w, roi.h);
    
    ctx.fillStyle = '#5cffac';
    ctx.font = 'bold 12px monospace';
    ctx.fillText(`#${idx+1}: ${roi.label}`, roi.x + 4, roi.y + 16);
  });

  if (drawPreview) {
    ctx.strokeStyle = '#ff4d4d';
    ctx.lineWidth = 2;
    ctx.setLineDash([6, 3]);
    const px = Math.min(startX, currentX);
    const py = Math.min(startY, currentY);
    const pw = Math.abs(currentX - startX);
    const ph = Math.abs(currentY - startY);
    ctx.strokeRect(px, py, pw, ph);
    ctx.setLineDash([]);
  }
}

function renderRoiList() {
  const container = document.getElementById('roiList');
  if (!container) return;
  
  container.innerHTML = editorRois.map((roi, idx) => `
    <div style="background:var(--bg-base); border:1px solid var(--border); padding:10px; border-radius:6px; font-size:.76rem; display:flex; flex-direction:column; gap:6px;">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-weight:700; color:var(--text-primary);">Zone #${idx + 1}</span>
        <button class="btn btn-sm btn-alert" onclick="deleteEditorRoi(${idx})" style="padding:2px 6px; font-size:.64rem;">
          <i class="fa-solid fa-trash"></i> Delete
        </button>
      </div>
      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:6px;">
        <div>
          <label style="font-size:.62rem; color:var(--text-dim); display:block; margin-bottom:2px;">Label</label>
          <input type="text" class="form-control form-control-sm" value="${roi.label}" onchange="updateEditorRoiLabel(${idx}, this.value)" style="padding:3px 6px; font-size:.7rem;"/>
        </div>
        <div>
          <label style="font-size:.62rem; color:var(--text-dim); display:block; margin-bottom:2px;">Asset Tier</label>
          <select class="form-control form-control-sm" onchange="updateEditorRoiTier(${idx}, this.value)" style="padding:3px 6px; font-size:.7rem; height:auto;">
            <option value="tier1" ${roi.tier==='tier1'?'selected':''}>Tier 1 (Fixed Asset)</option>
            <option value="tier2" ${roi.tier==='tier2'?'selected':''}>Tier 2 (Medium Value)</option>
            <option value="tier3" ${roi.tier==='tier3'?'selected':''}>Tier 3 (Bulk Equipment)</option>
            <option value="tier4" ${roi.tier==='tier4'?'selected':''}>Tier 4 (Low Cost)</option>
          </select>
        </div>
      </div>
      <div style="font-family:var(--font-mono); font-size:.62rem; color:var(--text-dim);">
        Coords: x:${roi.x}, y:${roi.y} · Size: w:${roi.w}, h:${roi.h}
      </div>
    </div>
  `).join('');
}

function updateEditorRoiLabel(idx, val) {
  editorRois[idx].label = val.trim().replace(/\s+/g, '_');
  redrawEditorCanvas();
}

function updateEditorRoiTier(idx, val) {
  editorRois[idx].tier = val;
}

function deleteEditorRoi(idx) {
  editorRois.splice(idx, 1);
  renderRoiList();
  redrawEditorCanvas();
}

async function saveRoisToSystem() {
  if (editorRois.length === 0) {
    if (!confirm('You have no ROI zones drawn. Saving will clear all monitoring targets. Proceed?')) return;
  }
  
  try {
    const res = await spotitFetch('../auth/save_rois.php', {
      method: 'POST',
      body: new URLSearchParams({
        room_id: 'DESK',
        rois: JSON.stringify(editorRois)
      })
    });
    if (res && res.success) {
      showToast('success', 'ROI configurations saved successfully!');
      closeRoiEditor();
      setTimeout(() => location.reload(), 900);
    } else {
      showToast('error', res ? res.message : 'Save failed');
    }
  } catch (e) {
    showToast('error', 'Network error saving configurations.');
  }
}

async function truncateSandboxDetections() {
  if (!confirm("Are you sure you want to delete all sandbox test detections? This will clear the table logs.")) return;
  try {
    const res = await spotitFetch('../auth/truncate_sandbox.php', { method: 'POST' });
    if (res && res.success) {
      showToast('success', 'Sandbox detections cleared!');
      setTimeout(() => location.reload(), 900);
    } else {
      showToast('error', res?.message || 'Failed to clear sandbox logs.');
    }
  } catch (err) {
    showToast('error', 'Network error.');
  }
}

async function resetSystemState() {
  if (!confirm("Are you sure you want to RESET the system? This will clear the reference image, bounded ROIs, snapshot images, and database detection logs.")) return;
  try {
    const res = await spotitFetch('../auth/reset_system_state.php', { method: 'POST' });
    if (res && res.success) {
      showToast('success', 'System reset completed successfully!');
      setTimeout(() => location.reload(), 900);
    } else {
      showToast('error', res?.message || 'Failed to reset system state.');
    }
  } catch (err) {
    showToast('error', 'Network error resetting system state.');
  }
}

function toggleCamFullScreen() {
  const box = document.getElementById('camVideoBox');
  if (!box) return;
  if (!document.fullscreenElement && !document.webkitFullscreenElement) {
    if (box.requestFullscreen) box.requestFullscreen();
    else if (box.webkitRequestFullscreen) box.webkitRequestFullscreen();
    else if (box.msRequestFullscreen) box.msRequestFullscreen();
  } else {
    if (document.exitFullscreen) document.exitFullscreen();
    else if (document.webkitExitFullscreen) document.webkitExitFullscreen();
  }
}

async function switchTrackingMode(newMode) {
  try {
    const fd = new FormData();
    fd.append('mode', 'testing');
    fd.append('tracking_mode', newMode);
    
    const res = await spotitFetch('../auth/toggle_detection_mode.php', {
      method: 'POST',
      body: fd
    });
    
    if (res && res.success) {
      showToast('success', `Tracking mode updated to: ${newMode.toUpperCase()}`);
      
      const btnSetup = document.getElementById('btnSetupRoi');
      if (btnSetup) {
        if (newMode === 'unregistered') {
          btnSetup.disabled = true;
          btnSetup.style.opacity = '0.4';
          btnSetup.style.cursor = 'not-allowed';
          btnSetup.title = 'Disabled in Left Items mode';
        } else {
          btnSetup.disabled = false;
          btnSetup.style.opacity = '1';
          btnSetup.style.cursor = 'pointer';
          btnSetup.title = '';
        }
      }
      
      setTimeout(() => location.reload(), 600);
    } else {
      showToast('error', res?.message || 'Failed to toggle mode.');
    }
  } catch (err) {
    showToast('error', 'Network error changing mode.');
  }
}

async function relabelItem(detectionId, currentLabel) {
  const newName = prompt(`Configure item details / Rename label for Detection #${detectionId}:`, currentLabel);
  if (!newName || newName.trim() === '' || newName.trim() === currentLabel) return;

  try {
    const fd = new FormData();
    fd.append('detection_id', detectionId);
    fd.append('new_name', newName.trim());

    const res = await spotitFetch('../auth/relabel_detection.php', {
      method: 'POST',
      body: fd
    });

    if (res && res.success) {
      showToast('success', `Item relabeled to: '${newName.trim()}'`);
      pollDeskLiveStatus();
    } else {
      showToast('error', res?.message || 'Failed to relabel item.');
    }
  } catch (e) {
    showToast('error', 'Network error relabeling item.');
  }
}

async function changeDetectionStatus(detectionId, newStatus) {
  try {
    const fd = new FormData();
    fd.append('detection_id', detectionId);
    fd.append('status', newStatus);
    fd.append('notes', `Status set to ${newStatus} from Sandbox Log`);

    const res = await spotitFetch('../auth/update_event_status.php', {
      method: 'POST',
      body: fd
    });

    if (res && res.success) {
      showToast('success', `Status updated to: ${newStatus.replace('_', ' ')}`);
      pollDeskLiveStatus();
    } else {
      showToast('error', res?.message || 'Failed to update status.');
    }
  } catch (e) {
    showToast('error', 'Network error updating status.');
  }
}

async function pollDeskLiveStatus() {
  try {
    const res = await spotitFetch('../auth/get_desk_status.php');
    if (!res || !res.success) return;

    const baseEl = document.getElementById('statBaseline');
    const liveEl = document.getElementById('statLiveCount');
    const badgeEl = document.getElementById('statStatusBadge');
    const alertBox = document.getElementById('activeDeviationAlert');
    const tbody = document.getElementById('sandboxLogsTbody');

    // Top Classification Report Metrics
    const accEl = document.getElementById('metricAccuracy');
    const precEl = document.getElementById('metricPrecision');
    const recEl = document.getElementById('metricRecall');
    const f1El = document.getElementById('metricF1');
    const totalTrialsEl = document.getElementById('metricTotalTrials');

    if (accEl && res.accuracy !== undefined) accEl.innerText = `${parseFloat(res.accuracy).toFixed(1)}%`;
    if (precEl && res.precision !== undefined) precEl.innerText = `${parseFloat(res.precision).toFixed(1)}%`;
    if (recEl && res.recall !== undefined) recEl.innerText = `${parseFloat(res.recall).toFixed(1)}%`;
    if (f1El && res.f1 !== undefined) f1El.innerText = `${parseFloat(res.f1).toFixed(1)}%`;
    if (totalTrialsEl && res.total_trials !== undefined) totalTrialsEl.innerText = `Total trials logged: ${res.total_trials}`;

    // Confusion Matrix Breakdown Cells
    const tnEl = document.getElementById('cellTN');
    const fpEl = document.getElementById('cellFP');
    const fnEl = document.getElementById('cellFN');
    const tpEl = document.getElementById('cellTP');

    if (tnEl && res.tn !== undefined) tnEl.innerText = res.tn;
    if (fpEl && res.fp !== undefined) fpEl.innerText = res.fp;
    if (fnEl && res.fn !== undefined) fnEl.innerText = res.fn;
    if (tpEl && res.tp !== undefined) tpEl.innerText = res.tp;

    const baseLabel = document.getElementById('statBaselineLabel');
    const liveLabel = document.getElementById('statLiveCountLabel');

    if (res.tracking_mode === 'unregistered') {
      if (baseLabel) baseLabel.innerText = 'Clean Baseline';
      if (liveLabel) liveLabel.innerText = 'Unattended Left Items';
      if (baseEl) baseEl.innerText = 'N/A';
    } else {
      if (baseLabel) baseLabel.innerText = 'Baseline Count';
      if (liveLabel) liveLabel.innerText = 'Live Count';
      if (baseEl) baseEl.innerText = res.baseline_count;
    }

    if (liveEl) {
      liveEl.innerText = res.live_count;
      liveEl.style.color = (res.status === 'NORMAL') ? 'var(--ok)' : 'var(--alert)';
    }
    if (badgeEl) {
      if (res.status === 'NORMAL') {
        badgeEl.className = 'badge badge-ok';
        badgeEl.innerHTML = '<span class="bdot"></span>NORMAL';
      } else {
        badgeEl.className = 'badge badge-alert';
        const stText = res.tracking_mode === 'unregistered' ? 'UNATTENDED DEVIATION' : 'DEVIATION';
        badgeEl.innerHTML = `<span class="bdot"></span>${stText}`;
      }
    }

    if (alertBox) {
      if (res.active_deviation) {
        const d = res.active_deviation;
        alertBox.innerHTML = `
          <div style="padding:12px 14px;background:var(--alert-bg);border:1px solid rgba(220,53,69,.15);border-radius:9px;margin-bottom:14px;display:flex;align-items:start;gap:9px;font-size:.8rem;color:var(--text-primary);">
            <i class="fa-solid fa-triangle-exclamation" style="color:var(--alert);margin-top:2px;flex-shrink:0;"></i>
            <div>
              <strong>Active Deviation:</strong> Zone <strong>${escapeHtml(d.object_zone)}</strong> flagged at <strong>${escapeHtml(d.detected_at)}</strong>.<br/>
              Match Score: <code>${escapeHtml(d.match_score || '—')}</code> &nbsp;·&nbsp; Change %: <code>${escapeHtml(d.roi_change_pct || '0')}%</code>.
            </div>
          </div>`;
      } else {
        alertBox.innerHTML = `
          <div style="padding:12px 14px;background:var(--ok-bg);border:1px solid rgba(40,167,69,.15);border-radius:9px;margin-bottom:14px;display:flex;align-items:center;gap:9px;font-size:.8rem;color:var(--text-primary);">
            <i class="fa-solid fa-circle-check" style="color:var(--ok);flex-shrink:0;"></i>
            <span>All items present on the baseline crop. Camera reporting 100% baseline match.</span>
          </div>`;
      }
    }

    if (tbody && Array.isArray(res.logs)) {
      if (res.logs.length === 0) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center py-4" style="color:var(--text-dim); padding: 20px;">No sandbox detections recorded yet.</td></tr>';
      } else {
        tbody.innerHTML = res.logs.map(log => {
          let badgeCls = 'badge-info';
          if (log.status === 'confirmed_missing') badgeCls = 'badge-alert';
          else if (log.status === 'potential') badgeCls = 'badge-warn';
          else if (log.status === 'recovered') badgeCls = 'badge-ok';
          else if (log.status === 'dismissed') badgeCls = 'badge-dim';

          let dur = '—';
          if (log.duration_seconds) {
            const sec = parseInt(log.duration_seconds);
            if (log.status === 'dismissed' || log.status === 'recovered') {
              dur = sec < 60 ? `${sec}s` : `${Math.floor(sec/60)}m ${sec%60}s`;
            } else {
              dur = sec < 60 ? `${sec}s (Ongoing)` : `${Math.floor(sec/60)}m (Ongoing)`;
            }
          }

          const imgA = log.snapshot_path ? `<a href="../uploads/snapshots/${log.snapshot_path}" target="_blank"><img src="../uploads/snapshots/${log.snapshot_path}" style="height:36px; border-radius:4px; border:1px solid var(--border); transition: transform 0.15s; cursor:pointer;" onmouseover="this.style.transform='scale(2.5)'; this.style.zIndex='999';" onmouseout="this.style.transform='scale(1)'"/></a>` : '—';
          const imgB = log.snapshot_path_b ? `<a href="../uploads/snapshots/${log.snapshot_path_b}" target="_blank"><img src="../uploads/snapshots/${log.snapshot_path_b}" style="height:36px; border-radius:4px; border:1px solid var(--border); transition: transform 0.15s; cursor:pointer;" onmouseover="this.style.transform='scale(2.5)'; this.style.zIndex='999';" onmouseout="this.style.transform='scale(1)'"/></a>` : '—';

          const isRec = log.status === 'recovered';
          const isDis = log.status === 'dismissed';
          const isMis = log.status === 'confirmed_missing' || log.status === 'pending' || log.status === 'potential';

          const btnRec = `<button class="btn btn-sm" onclick="changeDetectionStatus(${log.detection_id}, 'recovered')" style="font-size:.65rem; padding:3px 8px; background:${isRec ? 'var(--ok)' : 'var(--ok-bg)'}; color:${isRec ? '#fff' : 'var(--ok)'}; border:1px solid var(--ok-border); font-weight:700;" title="Verify item is physically restored"><i class="fa-solid fa-check"></i> ${isRec ? 'Recovered ✓' : 'Recovered'}</button>`;
          const btnDis = `<button class="btn btn-sm" onclick="changeDetectionStatus(${log.detection_id}, 'dismissed')" style="font-size:.65rem; padding:3px 8px; background:${isDis ? 'var(--alert)' : 'var(--alert-bg)'}; color:${isDis ? '#fff' : 'var(--alert)'}; border:1px solid var(--alert-border); font-weight:700;" title="Dismiss false alarm"><i class="fa-solid fa-xmark"></i> ${isDis ? 'Dismissed ✓' : 'Dismiss'}</button>`;
          const btnMis = `<button class="btn btn-sm" onclick="changeDetectionStatus(${log.detection_id}, 'confirmed_missing')" style="font-size:.65rem; padding:3px 8px; background:${isMis ? 'var(--warn)' : 'var(--warn-bg)'}; color:${isMis ? '#fff' : 'var(--warn)'}; border:1px solid var(--warn-border); font-weight:700;" title="Confirm item is missing"><i class="fa-solid fa-triangle-exclamation"></i> ${isMis ? 'Missing ✓' : 'Flag Missing'}</button>`;
          const btnRen = `<button class="btn btn-sm" onclick="relabelItem(${log.detection_id}, '${escapeHtml(log.object_zone)}')" style="font-size:.65rem; padding:3px 8px; background:var(--bg-base); color:var(--text-primary); border:1px solid var(--border); font-weight:700;" title="Configure item details / rename label"><i class="fa-solid fa-pen"></i> Rename</button>`;

          return `
            <tr style="border-bottom:1px solid var(--border);" id="det-row-${log.detection_id}">
              <td style="padding:10px; font-family:var(--font-mono);">${escapeHtml(log.detected_at)}</td>
              <td style="padding:10px; font-weight:700;">${escapeHtml(log.room_id)}</td>
              <td style="padding:10px; font-weight:600;">${escapeHtml(log.object_type)}</td>
              <td style="padding:10px; color:var(--text-dim);">${escapeHtml(log.object_zone)}</td>
              <td style="padding:10px;"><span class="badge ${badgeCls}">${escapeHtml(log.status.replace('_', ' '))}</span></td>
              <td style="padding:10px; font-family:var(--font-mono);">${dur}</td>
              <td style="padding:10px; text-align:center;">${imgA}</td>
              <td style="padding:10px; text-align:center;">${imgB}</td>
              <td style="padding:10px; text-align:center;">
                <div style="display:flex; gap:4px; justify-content:center; flex-wrap:wrap;">
                  ${btnRec} ${btnDis} ${btnMis} ${btnRen}
                </div>
              </td>
            </tr>`;
        }).join('');
      }
    }
  } catch (e) {
    // Silent polling error handling
  }
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

document.addEventListener('DOMContentLoaded', () => {
  initCamera();
  pollDeskLiveStatus();
  setInterval(pollDeskLiveStatus, 2500);
});
</script>
</body>
</html>
