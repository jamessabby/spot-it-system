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
      display: grid;
      grid-template-columns: 1fr 340px;
      gap: 18px;
      margin-top: 18px;
      align-items: start;
    }
    @media (max-width: 992px) {
      .sandbox-grid { grid-template-columns: 1fr; }
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
      grid-template-columns: 1fr 1fr;
      gap: 10px;
      margin-top: 14px;
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
      margin-bottom: 2px;
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
          <div class="metric-title">Accuracy Rate</div>
          <div class="metric-value"><?= number_format($accuracy, 1) ?>%</div>
          <div class="metric-footer">Total trials logged: <?= $totalTrials ?></div>
        </div>
        <div class="metric-card">
          <div class="metric-title">Precision</div>
          <div class="metric-value"><?= number_format($precision, 1) ?>%</div>
          <div class="metric-footer">True Pos / (True Pos + False Pos)</div>
        </div>
        <div class="metric-card">
          <div class="metric-title">Recall</div>
          <div class="metric-value"><?= number_format($recall, 1) ?>%</div>
          <div class="metric-footer">True Pos / (True Pos + False Neg)</div>
        </div>
        <div class="metric-card">
          <div class="metric-title">F1-Score</div>
          <div class="metric-value"><?= number_format($f1, 1) ?>%</div>
          <div class="metric-footer">Harmonic mean of P &amp; R</div>
        </div>
      </div>

      <div class="sandbox-grid">
        
        <!-- LEFT: Logs & Calibration details -->
        <div style="display:flex;flex-direction:column;gap:18px;">
          
          <!-- Live CCTV Feed -->
          <div class="card" style="position:relative; overflow:hidden; border:1px solid var(--border); box-shadow:0 8px 30px rgba(0,0,0,0.15);">
            <div class="card-head" style="border-bottom:1px solid var(--border);">
              <div class="card-title" style="display:flex; align-items:center; gap:8px;">
                <i class="fa-solid fa-video" style="color:var(--ok);"></i> 
                <span>Desk Calibration Feed</span>
              </div>
              <div style="display:flex; gap:8px; align-items:center;">
                <button class="btn btn-sm" onclick="openRoiEditor()" style="font-size:.7rem; padding:4px 10px; border-radius:6px; background:var(--bg-base); border:1px solid var(--border); color:var(--text-primary);">
                  <i class="fa-solid fa-pen-ruler"></i> Setup ROI Zones
                </button>
                <select id="streamQualitySelect" onchange="toggleQuality(this.value)" class="form-control" style="font-size:.65rem; padding:2px 6px; height:auto; width:auto; border-radius:6px; background:var(--bg-base); border-color:var(--border);">
                  <option value="high">1080p Premium</option>
                  <option value="low">480p Balanced</option>
                </select>
                <span id="streamStatusBadge" class="badge badge-warn" style="font-size:.65rem; padding:3px 10px; border-radius:6px;">
                  <span class="bdot" style="background:var(--warn);"></span> CONNECTING
                </span>
              </div>
            </div>
            
            <!-- Video Container -->
            <div style="position:relative; width:100%; height:auto; max-height: 520px; aspect-ratio: 960/540; background:#050d08; display:flex; justify-content:center; align-items:center; overflow:hidden; border-radius:6px;">
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
                  <div style="font-family:var(--font-mono);font-size:1.5rem;font-weight:900;color:var(--text-primary);"><?= $deskExpectedCount ?></div>
                  <div style="font-size:.65rem;color:var(--text-dim);text-transform:uppercase;font-weight:700;margin-top:2px;">Baseline Count</div>
                </div>
                <div style="padding:12px;background:var(--bg-base);border:1px solid var(--border);border-radius:9px;text-align:center;">
                  <div style="font-family:var(--font-mono);font-size:1.5rem;font-weight:900;color:<?= $deskLiveCount === $deskExpectedCount ? 'var(--ok)' : 'var(--alert)' ?>;"><?= $deskLiveCount ?></div>
                  <div style="font-size:.65rem;color:var(--text-dim);text-transform:uppercase;font-weight:700;margin-top:2px;">Live Count</div>
                </div>
                <div style="padding:12px;background:var(--bg-base);border:1px solid var(--border);border-radius:9px;text-align:center;display:flex;flex-direction:column;justify-content:center;align-items:center;">
                  <span class="badge badge-<?= $deskLiveCount === $deskExpectedCount ? 'ok' : 'alert' ?>" style="font-size:.7rem;padding:4px 10px;">
                    <span class="bdot"></span><?= $deskLiveCount === $deskExpectedCount ? 'NORMAL' : 'DEVIATION' ?>
                  </span>
                  <div style="font-size:.65rem;color:var(--text-dim);text-transform:uppercase;font-weight:700;margin-top:4px;">Status</div>
                </div>
              </div>
              
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
              
              <div style="font-size:.76rem;color:var(--text-muted);border-top:1px solid var(--border);padding-top:12px;display:flex;justify-content:space-between;align-items:center;">
                <span>Tapo Stream static IP: <code>192.168.18.11:554</code></span>
                <span class="badge badge-green" style="font-size:.6rem;"><i class="fa-solid fa-wifi"></i> CCTV CONNECTED</span>
              </div>
            </div>
          </div>

          <!-- Trials log -->
          <div class="card">
            <div class="card-head">
              <div class="card-title"><i class="fa-solid fa-list-check"></i> Accuracy Logs Matrix</div>
              <button class="btn btn-sm btn-alert" onclick="clearLogs()" style="padding:4px 8px;font-size:.68rem;">
                <i class="fa-solid fa-trash"></i> Clear Logs
              </button>
            </div>
            <div style="padding:0;">
              <?php if (empty($trials)): ?>
              <div style="padding:40px 20px;text-align:center;color:var(--text-dim);font-size:.8rem;">
                <i class="fa-solid fa-flask" style="font-size:2rem;color:var(--text-dim);margin-bottom:10px;display:block;"></i>
                No accuracy trials logged yet. Log your first calibration observation on the right panel.
              </div>
              <?php else: ?>
              <table style="width:100%;border-collapse:collapse;font-size:.8rem;">
                <thead>
                  <tr style="border-bottom:1px solid var(--border);background:var(--bg-base);">
                    <th style="padding:10px 14px;text-align:left;font-family:var(--font-display);font-size:.64rem;font-weight:700;color:var(--text-dim);text-transform:uppercase;">Time</th>
                    <th style="padding:10px 14px;text-align:left;font-family:var(--font-display);font-size:.64rem;font-weight:700;color:var(--text-dim);text-transform:uppercase;">Expected</th>
                    <th style="padding:10px 14px;text-align:left;font-family:var(--font-display);font-size:.64rem;font-weight:700;color:var(--text-dim);text-transform:uppercase;">System Output</th>
                    <th style="padding:10px 14px;text-align:center;font-family:var(--font-display);font-size:.64rem;font-weight:700;color:var(--text-dim);text-transform:uppercase;">Classification</th>
                    <th style="padding:10px 14px;text-align:left;font-family:var(--font-display);font-size:.64rem;font-weight:700;color:var(--text-dim);text-transform:uppercase;">Notes</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($trials as $t): ?>
                  <tr style="border-bottom:1px solid var(--border);">
                    <td style="padding:12px 14px;font-family:var(--font-mono);font-size:.7rem;color:var(--text-dim);"><?= date('H:i:s · M j', strtotime($t['trial_timestamp'])) ?></td>
                    <td style="padding:12px 14px;font-weight:600;color:<?= $t['expected_state']==='absent'?'var(--alert)':'var(--text-primary)' ?>;"><?= ucfirst($t['expected_state']) ?></td>
                    <td style="padding:12px 14px;font-weight:600;color:<?= $t['detected_state']==='absent'?'var(--alert)':'var(--text-primary)' ?>;"><?= ucfirst($t['detected_state']) ?></td>
                    <td style="padding:12px 14px;text-align:center;">
                      <span class="badge-classification badge-<?= $t['classification'] ?>"><?= $t['classification'] ?></span>
                    </td>
                    <td style="padding:12px 14px;color:var(--text-muted);font-size:.74rem;"><?= htmlspecialchars($t['notes'] ?: '—') ?></td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
              <?php endif; ?>
            </div>
          </div>
        </div>

        <!-- RIGHT: Matrix controls & calibration tools -->
        <div style="display:flex;flex-direction:column;gap:18px;">
          
          <!-- Log trial card -->
          <div class="card">
            <div class="card-head">
              <div class="card-title"><i class="fa-solid fa-pen-to-square"></i> Record Observation</div>
            </div>
            <div style="padding:16px;">
              <form id="trialForm" onsubmit="submitTrial(event)">
                <div class="form-group" style="margin-bottom:14px;">
                  <label class="form-label" style="font-weight:700;">Expected Physical State</label>
                  <div style="display:flex;flex-direction:column;gap:6px;margin-top:4px;">
                    <label style="display:flex;align-items:center;gap:8px;font-size:.8rem;color:var(--text-primary);cursor:pointer;">
                      <input type="radio" name="expected" value="present" checked style="accent-color:var(--green-main);"/>
                      Present (Item is physically on desk)
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;font-size:.8rem;color:var(--text-primary);cursor:pointer;">
                      <input type="radio" name="expected" value="absent" style="accent-color:var(--green-main);"/>
                      Absent (Item has been removed)
                    </label>
                  </div>
                </div>

                <div class="form-group" style="margin-bottom:14px;">
                  <label class="form-label" style="font-weight:700;">System Detected State</label>
                  <div style="display:flex;flex-direction:column;gap:6px;margin-top:4px;">
                    <label style="display:flex;align-items:center;gap:8px;font-size:.8rem;color:var(--text-primary);cursor:pointer;">
                      <input type="radio" name="detected" value="present" <?= !$activeDeviation ? 'checked' : '' ?> style="accent-color:var(--green-main);"/>
                      Present (No active system deviation)
                    </label>
                    <label style="display:flex;align-items:center;gap:8px;font-size:.8rem;color:var(--text-primary);cursor:pointer;">
                      <input type="radio" name="detected" value="absent" <?= $activeDeviation ? 'checked' : '' ?> style="accent-color:var(--green-main);"/>
                      Absent (System triggers red deviation)
                    </label>
                  </div>
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                  <label class="form-label">Calibration Notes / Remarks</label>
                  <input type="text" id="trialNotes" class="form-control" placeholder="e.g. Sunny lighting, high shadow..."/>
                </div>

                <button type="submit" class="btn btn-primary" style="width:100%;justify-content:center;padding:10px;">
                  <i class="fa-solid fa-floppy-disk"></i> Save &amp; Recalculate
                </button>
              </form>
            </div>
          </div>

          <!-- Confusion matrix breakdown card -->
          <div class="card">
            <div class="card-head">
              <div class="card-title"><i class="fa-solid fa-chart-pie"></i> Confusion Matrix (Thesis)</div>
            </div>
            <div style="padding:16px;">
              <p style="font-size:.72rem;color:var(--text-muted);line-height:1.5;margin-bottom:10px;">
                Positive Class = Absent (Missing Event)<br/>
                Negative Class = Present (Normal)
              </p>
              <div class="matrix-box">
                <div class="matrix-cell tn">
                  <div class="val"><?= $tn ?></div>
                  <div class="lbl">True Neg (TN)</div>
                </div>
                <div class="matrix-cell fp">
                  <div class="val"><?= $fp ?></div>
                  <div class="lbl">False Pos (FP)</div>
                </div>
                <div class="matrix-cell fn">
                  <div class="val"><?= $fn ?></div>
                  <div class="lbl">False Neg (FN)</div>
                </div>
                <div class="matrix-cell tp">
                  <div class="val"><?= $tp ?></div>
                  <div class="lbl">True Pos (TP)</div>
                </div>
              </div>
            </div>
          </div>

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

document.addEventListener('DOMContentLoaded', () => {
  initCamera();
});
</script>
</body>
</html>
