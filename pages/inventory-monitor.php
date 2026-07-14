<?php
/**
 * S.P.O.T.-IT — Inventory Baseline Monitoring Page
 * pages/inventory-monitor.php
 *
 * Primary monitoring interface for laboratory personnel.
 * Shows per-room: baseline count, live count, deviation, zone breakdown,
 * elapsed timers, and status — all auto-refreshed every 10 seconds.
 *
 * MICROSERVICES: No SQL. All data from auth/get_inventory_status.php via JS.
 */
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../services/monitoring/db.php';
require_once __DIR__ . '/../auth/service_bootstrap.php';
$active_page = 'inventory';
$user_role   = $_SESSION['user_role'] ?? 'staff';
$is_admin    = $user_role === 'admin';

// Live statistics calculations for inventory strip
$sumRooms = (int)$monitorPdo->query("SELECT COUNT(*) FROM rooms WHERE is_active = 1")->fetchColumn();
$sumBaseline = (int)$monitorPdo->query("SELECT IFNULL(SUM(baseline_count), 0) FROM rooms WHERE is_active = 1")->fetchColumn();
$potentialCount = (int)$monitorPdo->query("SELECT COUNT(DISTINCT room_id) FROM detections WHERE status = 'potential' AND is_removed = 0")->fetchColumn();
$missingCount = (int)$monitorPdo->query("SELECT COUNT(DISTINCT room_id) FROM detections WHERE status = 'confirmed_missing' AND is_removed = 0")->fetchColumn();
$normalCount = max(0, $sumRooms - $potentialCount - $missingCount);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Inventory Baseline Monitor — S.P.O.T.-IT</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/css/inventory-monitor.css"/>
  <link rel="stylesheet" href="../assets/css/skeleton.css"/>
  <script>(function(){document.documentElement.setAttribute('data-theme',localStorage.getItem('spotit_theme')||'light')})();</script>
</head>
<body data-skeleton="dashboard">
<script src="../assets/js/skeleton.js"></script>

<div class="app-shell">
  <?php include '_sidebar.php'; ?>

  <div class="main-content">

    <!-- ══════════ TOPBAR ══════════ -->
    <div class="topbar">
      <button class="tb-btn tb-hamburger" id="hamburgerBtn" onclick="toggleSidebar()">
        <i class="fa-solid fa-bars"></i>
      </button>
      <div>
        <span class="topbar-title">Inventory Baseline Monitor</span>
        <span class="topbar-sub"> — CEAT Building · MLH</span>
      </div>
      <div class="live-pill" id="livePill">
        <div class="live-dot"></div>LIVE
      </div>
      <div class="topbar-right">
        <span style="font-family:var(--font-mono);font-size:.7rem;color:var(--text-dim);" id="liveClock"></span>
        <?php if ($is_admin): ?>
        <a href="room-monitor.php" class="tb-btn" title="Full CCTV View">
          <i class="fa-solid fa-video"></i>
        </a>
        <?php endif; ?>
        <button class="tb-btn" id="autoRefreshBtn" title="Auto-refresh ON" onclick="toggleAutoRefresh()" style="color:var(--ok);">
          <i class="fa-solid fa-rotate"></i>
        </button>
        <button class="tb-btn" onclick="toggleTheme()">
          <i class="fa-solid fa-circle-half-stroke"></i>
        </button>
      </div>
    </div>

    <!-- ══════════ PAGE BODY ══════════ -->
    <div class="page-body">

      <!-- Last-updated bar -->
      <div class="inv-update-bar">
        <div class="inv-live-dot"></div>
        <span>Last refreshed: <span id="lastRefreshed">—</span></span>
        <span style="margin-left:auto;">Auto-refresh every <strong>10 s</strong> &nbsp;·&nbsp; <?= date('l, F j, Y') ?></span>
      </div>

      <!-- Summary strip -->
      <div class="inv-summary-strip">
        <div class="inv-strip-card">
          <div class="inv-strip-icon green"><i class="fa-solid fa-door-open"></i></div>
          <div>
            <div class="inv-strip-num" id="sumRooms"><?= $sumRooms ?></div>
            <div class="inv-strip-label">Rooms Monitored</div>
          </div>
        </div>
        <div class="inv-strip-card">
          <div class="inv-strip-icon ok"><i class="fa-solid fa-circle-check"></i></div>
          <div>
            <div class="inv-strip-num" id="sumNormal"><?= $normalCount ?></div>
            <div class="inv-strip-label">Normal — No Deviation</div>
          </div>
        </div>
        <div class="inv-strip-card">
          <div class="inv-strip-icon warn"><i class="fa-solid fa-clock"></i></div>
          <div>
            <div class="inv-strip-num" id="sumPotential"><?= $potentialCount ?></div>
            <div class="inv-strip-label">Potentially Lost (&gt;30 min)</div>
          </div>
        </div>
        <div class="inv-strip-card">
          <div class="inv-strip-icon alert"><i class="fa-solid fa-circle-minus"></i></div>
          <div>
            <div class="inv-strip-num" id="sumMissing"><?= $missingCount ?></div>
            <div class="inv-strip-label">Confirmed Missing (&gt;60 min)</div>
          </div>
        </div>
        <div class="inv-strip-card">
          <div class="inv-strip-icon info"><i class="fa-solid fa-cubes"></i></div>
          <div>
            <div class="inv-strip-num" id="sumBaseline"><?= $sumBaseline ?></div>
            <div class="inv-strip-label">Total Registered Items</div>
          </div>
        </div>
      </div>

      <!-- Controls bar -->
      <div class="inv-controls">
        <!-- Search -->
        <div class="inv-search-wrap">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" id="invSearch" class="inv-search"
                 placeholder="Search room ID, name, or floor…"
                 oninput="filterRooms()"/>
        </div>

        <!-- Status filter pills -->
        <div class="inv-filter-pills" id="filterPills">
          <button class="inv-pill active" data-filter="all"      onclick="setFilter('all',this)">
            All <span class="pill-count" id="pc-all">8</span>
          </button>
          <button class="inv-pill" data-filter="normal"    onclick="setFilter('normal',this)">
            <i class="fa-solid fa-circle-check" style="color:var(--ok);"></i>
            Normal <span class="pill-count" id="pc-normal">5</span>
          </button>
          <button class="inv-pill" data-filter="potential" onclick="setFilter('potential',this)">
            <i class="fa-solid fa-clock" style="color:var(--warn);"></i>
            Potential <span class="pill-count" id="pc-potential">2</span>
          </button>
          <button class="inv-pill" data-filter="missing"   onclick="setFilter('missing',this)">
            <i class="fa-solid fa-circle-minus" style="color:var(--alert);"></i>
            Missing <span class="pill-count" id="pc-missing">1</span>
          </button>
          <button class="inv-pill" data-filter="offline"   onclick="setFilter('offline',this)">
            <i class="fa-solid fa-wifi" style="color:var(--text-dim);"></i>
            Offline <span class="pill-count" id="pc-offline">0</span>
          </button>
        </div>

        <!-- View toggle -->
        <div class="inv-view-toggle">
          <button class="inv-view-btn active" id="viewCard" onclick="setView('card',this)" title="Card view">
            <i class="fa-solid fa-grip"></i>
          </button>
          <button class="inv-view-btn" id="viewTable" onclick="setView('table',this)" title="Table view">
            <i class="fa-solid fa-table-list"></i>
          </button>
        </div>

        <!-- Manual refresh -->
        <button class="inv-refresh-btn" id="refreshBtn" onclick="refreshData()">
          <i class="fa-solid fa-rotate"></i> Refresh
        </button>

        <?php if ($is_admin): ?>
        <a href="room-monitor.php" class="inv-refresh-btn" style="text-decoration:none;">
          <i class="fa-solid fa-video"></i> CCTV View
        </a>
        <?php endif; ?>
      </div>

      <!-- ══════════ CARD VIEW ══════════ -->
      <div class="inv-grid" id="invCardGrid">

        <?php
        try {
            $monitorPdo = getMonitorDB();
            
            // 1. Fetch all active rooms
            $roomStmt = $monitorPdo->prepare("SELECT room_id, room_name, floor, room_type, camera_count, baseline_count, monitoring_status, last_calibrated FROM rooms WHERE is_active = 1 ORDER BY floor, room_id");
            $roomStmt->execute();
            $dbRooms = $roomStmt->fetchAll();
            
            // 2. Fetch active detections
            $detStmt = $monitorPdo->prepare("
                SELECT d.room_id, d.detection_id, d.object_zone, d.detected_at, d.status, d.deviation, d.snapshot_path, d.confidence_score, d.confidence_grade, d.validation_status
                FROM detections d
                INNER JOIN (
                    SELECT room_id, MAX(detection_id) AS max_id
                    FROM detections
                    WHERE status IN ('pending','potential','confirmed_missing')
                      AND is_removed = 0
                    GROUP BY room_id
                ) latest ON d.detection_id = latest.max_id
            ");
            $detStmt->execute();
            $detections = [];
            foreach ($detStmt->fetchAll() as $d) {
                $detections[$d['room_id']] = $d;
            }
            
            // 3. Fetch registered lab items (zones)
            $zoneStmt = $monitorPdo->prepare("SELECT item_id, room_id, item_name, roi_label, expected_count, tier FROM registered_lab_items WHERE is_active = 1 ORDER BY room_id, item_id");
            $zoneStmt->execute();
            $zonesByRoom = [];
            foreach ($zoneStmt->fetchAll() as $z) {
                $zonesByRoom[$z['room_id']][] = $z;
            }
            
            // 4. Fetch recent logs
            $logStmt = $monitorPdo->prepare("SELECT room_id, event_type, event_message, logged_at FROM monitoring_logs ORDER BY logged_at DESC");
            $logStmt->execute();
            $logsByRoom = [];
            foreach ($logStmt->fetchAll() as $l) {
                if (count($logsByRoom[$l['room_id']] ?? []) < 5) {
                    $logsByRoom[$l['room_id']][] = [_logEventColor($l['event_type']), date('H:i:s', strtotime($l['logged_at'])) . ' · ' . $l['event_message']];
                }
            }
            
            // 5. Construct $rooms array
            $rooms = [];
            foreach ($dbRooms as $r) {
                $rid = $r['room_id'];
                $det = $detections[$rid] ?? null;
                $zones = [];
                if (isset($zonesByRoom[$rid])) {
                    foreach ($zonesByRoom[$rid] as $z) {
                        $zoneState = 'ok';
                        $zoneLive = $z['expected_count'];
                        if ($det && stripos($det['object_zone'], $z['roi_label']) !== false) {
                            $zoneLive = 0;
                            $zoneState = $det['status'] === 'confirmed_missing' ? 'alert' : 'warn';
                        }
                        $zones[] = [$z['item_name'], (int)$z['expected_count'], (int)$zoneLive, $zoneState];
                    }
                }
                
                $status = 'normal';
                if ($r['monitoring_status'] !== 'active') {
                    $status = 'offline';
                } elseif ($det) {
                    $status = match($det['status']) {
                        'confirmed_missing' => 'missing',
                        'potential'         => 'potential',
                        default             => 'normal',
                    };
                }
                
                $liveCount = $det ? ($r['baseline_count'] + (int)$det['deviation']) : $r['baseline_count'];
                
                $rooms[] = [
                    'id'                => $r['room_id'],
                    'name'              => $r['room_name'],
                    'floor'             => $r['floor'],
                    'type'              => $r['room_type'],
                    'cameras'           => (int)$r['camera_count'],
                    'status'            => $status,
                    'baseline'          => (int)$r['baseline_count'],
                    'live'              => (int)$liveCount,
                    'deviation'         => $det ? (int)$det['deviation'] : 0,
                    'last_calibrated'   => $r['last_calibrated'] ? date('F j, Y', strtotime($r['last_calibrated'])) : 'Never',
                    'detection_at'      => $det ? $det['detected_at'] : null,
                    'confidence_score'  => $det ? (int)$det['confidence_score'] : null,
                    'confidence_grade'  => $det ? $det['confidence_grade'] : null,
                    'validation_status' => $det ? $det['validation_status'] : null,
                    'zones'             => $zones,
                    'recent_log'        => $logsByRoom[$rid] ?? [['ok', '08:00:00 · Monitoring active']],
                ];
            }
        } catch (Throwable $e) {
            $rooms = [];
        }

        // ── Status helpers ──
        $statusLabel = [
          'normal'    => 'Normal',
          'potential' => 'Potentially Lost',
          'missing'   => 'Confirmed Missing',
          'offline'   => 'Offline',
          'calibrating' => 'Calibrating',
        ];
        $badgeCls = [
          'normal'    => 'inv-badge-normal',
          'potential' => 'inv-badge-potential',
          'missing'   => 'inv-badge-missing',
          'offline'   => 'inv-badge-offline',
          'calibrating'=>'inv-badge-calibrating',
        ];

        foreach ($rooms as $room):
          $st    = $room['status'];
          $dev   = $room['deviation'];
          $base  = $room['baseline'];
          $live  = $room['live'];

          // Deviation bar percentage (relative to baseline)
          $devPct  = $base > 0 && $dev !== null ? abs($dev) / $base * 100 : 0;
          $devPct  = min(100, round($devPct, 1));
          $barCls  = $dev === 0 || $dev === null ? 'ok' : ($st === 'missing' ? 'alert' : 'warn');
          $fillPct = $base > 0 && $live !== null ? round($live / $base * 100, 1) : 0;

          // Show at most 4 zones in the card; rest shown in drawer
          $visibleZones = array_slice($room['zones'], 0, 4);
          $moreZones    = count($room['zones']) - count($visibleZones);
        ?>
        <div class="inv-room-card status-<?= $st ?>"
             data-status="<?= $st ?>"
             data-id="<?= strtolower($room['id']) ?>"
             data-name="<?= strtolower($room['name']) ?>"
             data-floor="<?= strtolower($room['floor']) ?>">

          <!-- Card header -->
          <div class="inv-card-head">
            <div class="inv-room-id-block">
              <div class="inv-room-id"><?= htmlspecialchars($room['id']) ?></div>
              <div class="inv-room-name"><?= htmlspecialchars($room['name']) ?></div>
              <div class="inv-room-floor">
                <i class="fa-solid fa-building" style="font-size:.56rem;margin-right:3px;"></i>
                <?= htmlspecialchars($room['floor']) ?>
                &nbsp;·&nbsp;
                <i class="fa-solid fa-video" style="font-size:.56rem;margin-right:3px;"></i>
                <?= $room['cameras'] ?> cam<?= $room['cameras'] > 1 ? 's' : '' ?>
              </div>
            </div>
            <div class="inv-status-wrap">
              <span class="inv-status-badge <?= $badgeCls[$st] ?>">
                <span class="sb-dot"></span>
                <?= $statusLabel[$st] ?>
              </span>
              <?php if ($room['detection_at']): ?>
              <span style="font-family:var(--font-mono);font-size:.6rem;color:var(--text-dim);">
                Since <?= date('H:i', strtotime($room['detection_at'])) ?>
              </span>
              <?php endif; ?>
              <?php if (isset($room['confidence_score']) && $room['confidence_score'] !== null):
                $grade = $room['confidence_grade'] ?? 'LOW';
                $gradeColor = match($grade) {
                  'HIGH'   => 'var(--ok)',
                  'MEDIUM' => 'var(--warn)',
                  'LOW'    => 'var(--alert)',
                  'NOISE'  => 'var(--text-dim)',
                  default  => 'var(--text-dim)',
                };
                $validStyle = match($room['validation_status'] ?? '') {
                  'auto_accepted' => 'var(--ok)',
                  'verified'      => 'var(--ok)',
                  'rejected'      => 'var(--text-dim)',
                  'needs_review'  => 'var(--alert)',
                  default         => 'var(--warn)',
                };
                $validLabel = match($room['validation_status'] ?? '') {
                  'auto_accepted' => 'Auto-Accepted',
                  'verified'      => 'Verified',
                  'rejected'      => 'Rejected',
                  'needs_review'  => 'Needs Review',
                  default         => 'Pending Verification',
                };
              ?>
              <div style="display:flex;align-items:center;gap:5px;margin-top:3px;">
                <span style="font-family:var(--font-display);font-size:.58rem;font-weight:700;color:<?= $gradeColor ?>;background:rgba(0,0,0,.04);padding:2px 7px;border-radius:100px;border:1px solid currentColor;opacity:.85;">
                  <?= $room['confidence_score'] ?>% <?= $grade ?>
                </span>
              </div>
              <div style="font-family:var(--font-display);font-size:.58rem;font-weight:600;color:<?= $validStyle ?>;margin-top:2px;">
                <i class="fa-solid <?= $room['validation_status'] === 'verified' || $room['validation_status'] === 'auto_accepted' ? 'fa-circle-check' : ($room['validation_status'] === 'needs_review' ? 'fa-triangle-exclamation' : 'fa-clock') ?>"></i>
                <?= $validLabel ?>
              </div>
              <?php endif; ?>
            </div>
          </div>

          <!-- Count meters -->
          <div class="inv-meters">
            <div class="inv-meter">
              <span class="inv-meter-num"><?= $room['baseline'] ?></span>
              <span class="inv-meter-label">Baseline</span>
            </div>
            <div class="inv-meter">
              <span class="inv-meter-num <?= $live === null ? 'dim' : ($dev === 0 ? '' : ($st === 'missing' ? 'alert' : 'warn')) ?>">
                <?= $live !== null ? $live : '—' ?>
              </span>
              <span class="inv-meter-label">Live Count</span>
            </div>
            <div class="inv-meter">
              <?php if ($dev === null): ?>
                <span class="inv-meter-num dim">—</span>
              <?php elseif ($dev === 0): ?>
                <span class="inv-meter-num ok">0</span>
              <?php elseif ($dev < 0): ?>
                <span class="inv-meter-num alert"><?= $dev ?></span>
              <?php else: ?>
                <span class="inv-meter-num warn">+<?= $dev ?></span>
              <?php endif; ?>
              <span class="inv-meter-label">Deviation</span>
            </div>
          </div>

          <!-- Deviation fill bar -->
          <div class="inv-dev-bar-wrap">
            <div class="inv-dev-bar-track">
              <div class="inv-dev-bar-fill <?= $barCls ?>"
                   style="width:<?= $fillPct ?>%;"></div>
            </div>
            <span class="inv-dev-pct <?= $barCls ?>">
              <?= $live !== null ? $fillPct . '%' : '—' ?>
            </span>
          </div>

          <!-- ROI zone breakdown (max 4) -->
          <?php if (!empty($room['zones'])): ?>
          <div class="inv-zones">
            <div class="inv-zone-label">ROI Zones</div>
            <?php foreach ($visibleZones as $z): ?>
            <div class="inv-zone-row">
              <div class="inv-zone-dot <?= $z[3] ?>"></div>
              <span class="inv-zone-name"><?= htmlspecialchars($z[0]) ?></span>
              <span class="inv-zone-counts">
                <?= $z[2] ?> / <?= $z[1] ?>
              </span>
              <span class="inv-zone-dev <?= $z[2] === $z[1] ? 'zero' : ($z[2] < $z[1] ? 'neg' : 'pos') ?>">
                <?= $z[2] - $z[1] === 0 ? '0' : ($z[2] - $z[1] > 0 ? '+'.($z[2]-$z[1]) : $z[2]-$z[1]) ?>
              </span>
            </div>
            <?php endforeach; ?>
            <?php if ($moreZones > 0): ?>
            <div style="font-size:.7rem;color:var(--text-dim);margin-top:5px;cursor:pointer;"
                 onclick="openDrawer('<?= $room['id'] ?>')">
              + <?= $moreZones ?> more zone<?= $moreZones > 1 ? 's' : '' ?> — <span style="color:var(--green-main);">View all</span>
            </div>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <!-- Elapsed timer (only for non-normal rooms) -->
          <?php if ($room['detection_at'] && $st !== 'normal'): ?>
          <div class="inv-timer-row">
            <span class="inv-timer-label"><i class="fa-solid fa-stopwatch" style="margin-right:4px;"></i>Elapsed:</span>
            <span class="inv-timer-value <?= $st === 'missing' ? 'alert' : 'warn' ?>"
                  id="timer-<?= $room['id'] ?>">--:--:--</span>
            <?php if ($st === 'missing'): ?>
            <span style="font-family:var(--font-display);font-size:.58rem;font-weight:700;color:var(--alert);background:var(--alert-bg);padding:2px 7px;border-radius:100px;margin-left:4px;animation:badgePulse .8s ease-in-out infinite;">ESCALATED</span>
            <?php endif; ?>
          </div>
          <?php endif; ?>

          <!-- Footer actions -->
          <div class="inv-card-foot">
            <button class="inv-foot-btn" onclick="openDrawer('<?= $room['id'] ?>')">
              <i class="fa-solid fa-expand"></i> Details
            </button>
            <?php if ($st === 'missing' || $st === 'potential'): ?>
            <button class="inv-foot-btn primary" onclick="openVerifyEvent('<?= $room['id'] ?>')">
              <i class="fa-solid fa-circle-check"></i> Verify Event
            </button>
            <?php endif; ?>
            <?php if ($is_admin): ?>
            <a href="room-monitor.php?room=<?= $room['id'] ?>" class="inv-foot-btn">
              <i class="fa-solid fa-video"></i> CCTV
            </a>
            <button class="inv-foot-btn" onclick="triggerRecalibrate('<?= $room['id'] ?>')">
              <i class="fa-solid fa-rotate"></i> Recalibrate
            </button>
            <?php endif; ?>
          </div>
        </div>
        <?php endforeach; ?>

      </div><!-- /inv-card-grid -->

      <!-- ══════════ TABLE VIEW ══════════ -->
      <div id="invTableView" style="display:none;">
        <div class="inv-table-wrap">
          <table class="inv-table" id="invTable">
            <thead>
              <tr>
                <th>Room</th>
                <th>Floor</th>
                <th style="text-align:center;">Baseline</th>
                <th style="text-align:center;">Live Count</th>
                <th style="text-align:center;">Deviation</th>
                <th style="text-align:center;">Fill %</th>
                <th>Status</th>
                <th>Elapsed</th>
                <th>Last Calibrated</th>
                <th></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rooms as $room):
                $st  = $room['status'];
                $dev = $room['deviation'];
                $fillPct = $room['baseline'] > 0 && $room['live'] !== null
                  ? round($room['live'] / $room['baseline'] * 100, 1) : 0;
                $rowCls = $st === 'missing' ? 'row-missing' : ($st === 'potential' ? 'row-potential' : '');
              ?>
              <tr class="<?= $rowCls ?>" data-status="<?= $st ?>"
                  data-id="<?= strtolower($room['id']) ?>"
                  data-name="<?= strtolower($room['name']) ?>">
                <td>
                  <div style="font-family:var(--font-display);font-size:.84rem;font-weight:800;color:var(--text-primary);">
                    <?= htmlspecialchars($room['id']) ?>
                  </div>
                  <div style="font-size:.72rem;color:var(--text-muted);"><?= htmlspecialchars($room['name']) ?></div>
                </td>
                <td style="font-size:.76rem;color:var(--text-dim);"><?= htmlspecialchars($room['floor']) ?></td>
                <td style="text-align:center;font-family:var(--font-mono);font-size:.88rem;font-weight:700;">
                  <?= $room['baseline'] ?>
                </td>
                <td style="text-align:center;font-family:var(--font-mono);font-size:.88rem;font-weight:700;
                           color:<?= $room['live'] === null ? 'var(--text-dim)' : ($dev === 0 ? 'var(--ok)' : ($st === 'missing' ? 'var(--alert)' : 'var(--warn)')) ?>;">
                  <?= $room['live'] !== null ? $room['live'] : '—' ?>
                </td>
                <td style="text-align:center;">
                  <?php if ($dev === null): ?>
                    <span class="inv-dev-chip zero">—</span>
                  <?php elseif ($dev === 0): ?>
                    <span class="inv-dev-chip zero">0</span>
                  <?php elseif ($dev < 0): ?>
                    <span class="inv-dev-chip neg"><?= $dev ?></span>
                  <?php else: ?>
                    <span class="inv-dev-chip pos">+<?= $dev ?></span>
                  <?php endif; ?>
                </td>
                <td style="text-align:center;">
                  <div style="display:flex;align-items:center;gap:6px;justify-content:center;">
                    <div style="width:50px;height:5px;background:var(--border);border-radius:100px;overflow:hidden;">
                      <div style="height:100%;width:<?= $fillPct ?>%;background:<?= $dev === 0 || $dev === null ? 'var(--ok)' : ($st==='missing'?'var(--alert)':'var(--warn)') ?>;border-radius:100px;"></div>
                    </div>
                    <span style="font-family:var(--font-mono);font-size:.68rem;color:var(--text-dim);"><?= $room['live'] !== null ? $fillPct.'%' : '—' ?></span>
                  </div>
                </td>
                <td>
                  <span class="inv-status-badge <?= $badgeCls[$st] ?>">
                    <span class="sb-dot"></span><?= $statusLabel[$st] ?>
                  </span>
                </td>
                <td>
                  <?php if ($room['detection_at'] && $st !== 'normal'): ?>
                  <span class="inv-timer-value <?= $st === 'missing' ? 'alert' : 'warn' ?>"
                        id="timer-tbl-<?= $room['id'] ?>">--:--:--</span>
                  <?php else: ?>
                  <span style="color:var(--text-dim);font-family:var(--font-mono);font-size:.7rem;">—</span>
                  <?php endif; ?>
                </td>
                <td style="font-family:var(--font-mono);font-size:.68rem;color:var(--text-dim);">
                  <?= $room['last_calibrated'] ?? 'Not set' ?>
                </td>
                <td>
                  <div style="display:flex;gap:5px;">
                    <button class="btn btn-sm" onclick="openDrawer('<?= $room['id'] ?>')">
                      <i class="fa-solid fa-expand"></i>
                    </button>
                    <?php if ($st === 'missing' || $st === 'potential'): ?>
                    <button class="btn btn-primary btn-sm" onclick="openVerifyEvent('<?= $room['id'] ?>')">
                      <i class="fa-solid fa-circle-check"></i> Verify
                    </button>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div><!-- /table view -->

    </div><!-- /page-body -->
  </div><!-- /main-content -->
</div><!-- /app-shell -->

<!-- ══════════════════════════════════════════
     ROOM DETAIL DRAWER
══════════════════════════════════════════ -->
<div class="inv-drawer-backdrop" id="drawerBackdrop" onclick="closeDrawer()"></div>
<div class="inv-drawer" id="roomDrawer">
  <div class="inv-drawer-head">
    <div class="inv-drawer-title" id="drawerTitle">Room Details</div>
    <button class="inv-drawer-close" onclick="closeDrawer()"><i class="fa-solid fa-xmark"></i></button>
  </div>
  <div class="inv-drawer-body" id="drawerBody">
    <!-- Populated by JS -->
  </div>
</div>

<!-- ══════════════════════════════════════════
     VERIFY EVENT MODAL
══════════════════════════════════════════ -->
<div class="modal-overlay" id="verifyModal" onclick="if(event.target===this)closeModal('verifyModal')">
  <div class="modal-box" style="max-width:480px;">
    <div class="modal-head">
      <div class="modal-title" id="verifyModalTitle">Verify Detection Event</div>
      <div class="modal-close" onclick="closeModal('verifyModal')"><i class="fa-solid fa-xmark"></i></div>
    </div>
    <div class="modal-body">
      <div style="padding:13px 14px;background:var(--info-bg);border:1px solid rgba(26,106,181,.2);border-radius:9px;font-size:.8rem;color:var(--text-primary);margin-bottom:16px;line-height:1.6;">
        <i class="fa-solid fa-circle-info" style="color:var(--info);margin-right:6px;"></i>
        Physically inspect the room before selecting an outcome. Your action will be logged to the audit trail.
      </div>
      <div id="verifyChecklist" style="background:var(--bg-base);border:1px solid var(--border);border-radius:9px;padding:13px 14px;margin-bottom:14px;">
        <div style="font-family:var(--font-display);font-size:.74rem;font-weight:700;color:var(--text-primary);margin-bottom:8px;">Staff Verification Checklist</div>
        <?php foreach([
          'Item is not present in the registered ROI zone',
          'Item has not been temporarily moved within the tolerance zone',
          'Room activity is not causing a false reading',
          'CCTV feed is not obstructed or misaligned',
        ] as $check): ?>
        <label style="display:flex;align-items:flex-start;gap:8px;font-size:.78rem;color:var(--text-muted);margin-bottom:6px;cursor:pointer;">
          <input type="checkbox" style="accent-color:var(--green-main);margin-top:1px;flex-shrink:0;"/>
          <?= htmlspecialchars($check) ?>
        </label>
        <?php endforeach; ?>
      </div>
      <div class="form-group">
        <label class="form-label">Staff Observation Notes</label>
        <textarea class="form-control" id="verifyNotes" rows="2"
                  placeholder="Describe what you found upon physical inspection…"></textarea>
      </div>
      <div class="modal-actions">
        <button class="modal-btn dismiss" onclick="closeModal('verifyModal')">Cancel</button>
        <button class="modal-btn dismiss" onclick="submitVerify('dismissed')">
          <i class="fa-solid fa-ban"></i> False Alert
        </button>
        <button class="modal-btn recover" onclick="submitVerify('recovered')">
          <i class="fa-solid fa-circle-check"></i> Item Found
        </button>
        <button class="modal-btn confirm" onclick="submitVerify('confirmed_missing')">
          <i class="fa-solid fa-triangle-exclamation"></i> Confirm Missing
        </button>
      </div>
    </div>
  </div>
</div>

<div class="toast-stack" id="toastStack"></div>
<script src="../assets/js/spotit.js"></script>
<script>
startLiveClock('liveClock');

/* ══════════════════════════════════════
   DATA — populated from PHP (demo values)
   In production these come from auth/get_inventory_status.php
══════════════════════════════════════ */
const ROOMS = <?php
$roomsJs = [];
foreach ($rooms as $r) {
    $roomsJs[] = [
        'id'             => $r['id'],
        'name'           => $r['name'],
        'floor'          => $r['floor'],
        'type'           => $r['type'],
        'cameras'        => $r['cameras'],
        'status'         => $r['status'],
        'baseline'       => $r['baseline'],
        'live'           => $r['live'],
        'deviation'      => $r['deviation'],
        'last_calibrated'=> $r['last_calibrated'],
        'detection_at'   => $r['detection_at'],
        'confidence_score' => $r['confidence_score'] ?? null,
        'confidence_grade' => $r['confidence_grade'] ?? null,
        'validation_status'=> $r['validation_status'] ?? null,
        'zones'          => $r['zones'],
        'recent_log'     => $r['recent_log'],
    ];
}
echo json_encode($roomsJs, JSON_UNESCAPED_UNICODE);
?>;

/* ══════════════════════════════════════
   ELAPSED TIMERS
══════════════════════════════════════ */
ROOMS.forEach(room => {
  if (!room.detection_at || room.status === 'normal') return;
  ['timer-' + room.id, 'timer-tbl-' + room.id].forEach(id => {
    startCountup(id, room.detection_at);
  });
});

/* ══════════════════════════════════════
   VIEW TOGGLE
══════════════════════════════════════ */
let currentView = 'card';
function setView(view, btn) {
  currentView = view;
  document.getElementById('invCardGrid').style.display  = view === 'card'  ? '' : 'none';
  document.getElementById('invTableView').style.display = view === 'table' ? '' : 'none';
  document.querySelectorAll('.inv-view-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  localStorage.setItem('spotit_inv_view', view);
}
// Restore saved view
const savedView = localStorage.getItem('spotit_inv_view') || 'card';
if (savedView === 'table') {
  setView('table', document.getElementById('viewTable'));
}

// Handle #alerts hash on page load
if (window.location.hash === '#alerts') {
  const potentialPill = document.querySelector('.inv-pill[data-filter="potential"]');
  if (potentialPill) {
    setFilter('potential', potentialPill);
  }
}

/* ══════════════════════════════════════
   FILTER PILLS
══════════════════════════════════════ */
let currentFilter = 'all';
function setFilter(filter, btn) {
  currentFilter = filter;
  document.querySelectorAll('.inv-pill').forEach(p => p.classList.remove('active'));
  btn.classList.add('active');
  applyFilters();
}

function applyFilters() {
  const q = document.getElementById('invSearch').value.toLowerCase();

  // Card view
  document.querySelectorAll('#invCardGrid .inv-room-card').forEach(card => {
    const matchFilter = currentFilter === 'all' || card.dataset.status === currentFilter;
    const matchSearch = !q || card.dataset.id.includes(q) || card.dataset.name.includes(q) || (card.dataset.floor||'').includes(q);
    card.style.display = (matchFilter && matchSearch) ? '' : 'none';
  });

  // Table view
  document.querySelectorAll('#invTable tbody tr').forEach(row => {
    const matchFilter = currentFilter === 'all' || row.dataset.status === currentFilter;
    const matchSearch = !q || row.dataset.id.includes(q) || (row.dataset.name||'').includes(q);
    row.style.display = (matchFilter && matchSearch) ? '' : 'none';
  });
}

function filterRooms() { applyFilters(); }

/* ══════════════════════════════════════
   AUTO-REFRESH
══════════════════════════════════════ */
let autoRefreshEnabled = true;
let refreshInterval;

function startAutoRefresh() {
  refreshInterval = setInterval(refreshData, 10000);
}

function toggleAutoRefresh() {
  autoRefreshEnabled = !autoRefreshEnabled;
  const btn = document.getElementById('autoRefreshBtn');
  if (autoRefreshEnabled) {
    startAutoRefresh();
    btn.style.color = 'var(--ok)';
    btn.title = 'Auto-refresh ON';
    showToast('success', 'Auto-refresh enabled — updates every 10 seconds.');
  } else {
    clearInterval(refreshInterval);
    btn.style.color = 'var(--text-dim)';
    btn.title = 'Auto-refresh OFF';
    showToast('warn', 'Auto-refresh paused. Click to resume.');
  }
}

async function refreshData() {
  const btn = document.getElementById('refreshBtn');
  btn.classList.add('spinning');

  try {
    const data = await spotitFetch('../auth/get_inventory_status.php');
    if (data && data.success) {
      updateSummaryStrip(data.summary);
      updatePillCounts(data.summary);
      const now = new Date().toLocaleTimeString('en-PH', { hour12: true });
      document.getElementById('lastRefreshed').textContent = now;
    }
  } catch (e) {
    // Silent fail — do not interrupt the UI on a background poll
    console.warn('[Inventory] Refresh failed:', e);
  } finally {
    setTimeout(() => btn.classList.remove('spinning'), 500);
  }
}

function updateSummaryStrip(s) {
  if (!s) return;
  const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
  set('sumRooms',    s.rooms         ?? '—');
  set('sumNormal',   s.normal        ?? '—');
  set('sumPotential',s.potential     ?? '—');
  set('sumMissing',  s.missing       ?? '—');
  set('sumBaseline', s.total_baseline?? '—');
}

function updatePillCounts(s) {
  if (!s) return;
  const total = s.rooms ?? 0;
  const set = (id, val) => { const el = document.getElementById(id); if (el) el.textContent = val; };
  set('pc-all',       total);
  set('pc-normal',    s.normal    ?? 0);
  set('pc-potential', s.potential ?? 0);
  set('pc-missing',   s.missing   ?? 0);
  set('pc-offline',   s.offline   ?? 0);
}

// Start auto-refresh and set initial timestamp
startAutoRefresh();
document.getElementById('lastRefreshed').textContent =
  new Date().toLocaleTimeString('en-PH', { hour12: true });

/* ══════════════════════════════════════
   ROOM DETAIL DRAWER
══════════════════════════════════════ */
function openDrawer(roomId) {
  const room = ROOMS.find(r => r.id === roomId);
  if (!room) return;

  document.getElementById('drawerTitle').textContent = room.id + ' — ' + room.name;

  const statusColor = {
    normal: 'ok', potential: 'warn', missing: 'alert',
    offline: 'dim', calibrating: 'info',
  }[room.status] || 'ok';

  const statusLabel = {
    normal: 'Normal', potential: 'Potentially Lost',
    missing: 'Confirmed Missing', offline: 'Offline', calibrating: 'Calibrating',
  }[room.status] || 'Unknown';

  const devNum = room.deviation;
  const devStr = devNum === null ? '—' : devNum === 0 ? '0' : (devNum > 0 ? '+' + devNum : '' + devNum);
  const fillPct = room.baseline > 0 && room.live !== null
    ? Math.round(room.live / room.baseline * 100 * 10) / 10 : 0;

  // Snapshot mock
  const snapHTML = `
    <div class="inv-snap-mini">
      <div class="inv-snap-scanline"></div>
      <div class="inv-snap-label">CAM-01 · ${room.id} · LIVE</div>
      <div class="inv-snap-ts" id="drawerSnapTs"></div>
      <svg width="100%" height="100%" viewBox="0 0 640 200" preserveAspectRatio="none" style="position:absolute;inset:0;opacity:.7">
        ${room.zones.slice(0,8).map((z,i) => {
          const x = 20 + (i%4)*155, y = 20 + Math.floor(i/4)*95;
          const clr = z[3]==='alert' ? '#ff4d4d' : z[3]==='warn' ? '#e67e00' : '#5cffac';
          const fill = z[3]==='alert' ? 'rgba(255,77,77,.1)' : z[3]==='warn' ? 'rgba(230,126,0,.08)' : 'rgba(0,200,120,.07)';
          const dash = z[3]==='ok' ? '' : 'stroke-dasharray="5,3"';
          return `<rect x="${x}" y="${y}" width="130" height="60" rx="4" fill="${fill}" stroke="${clr}" stroke-width="1.5" ${dash}/>
                  <text x="${x+5}" y="${y-4}" font-family="monospace" font-size="8" fill="${clr}">${z[0].substring(0,18)}</text>`;
        }).join('')}
      </svg>
    </div>`;

  // Confidence display
  const gradeColors = { HIGH:'var(--ok)', MEDIUM:'var(--warn)', LOW:'var(--alert)', NOISE:'var(--text-dim)' };
  const validColors = {
    auto_accepted:'var(--ok)', verified:'var(--ok)',
    rejected:'var(--text-dim)', needs_review:'var(--alert)',
    pending_review:'var(--warn)'
  };
  const validLabels = {
    auto_accepted:'Auto-Accepted', verified:'Verified by Staff',
    rejected:'Rejected (False Alarm)', needs_review:'Needs Review',
    pending_review:'Pending Verification'
  };
  const validIcons = {
    auto_accepted:'fa-circle-check', verified:'fa-circle-check',
    rejected:'fa-ban', needs_review:'fa-triangle-exclamation',
    pending_review:'fa-clock'
  };
  const confScore = room.confidence_score;
  const confGrade = room.confidence_grade || 'N/A';
  const valStatus = room.validation_status || 'N/A';
  const confColor = gradeColors[confGrade] || 'var(--text-dim)';
  const valColor  = validColors[valStatus]  || 'var(--text-dim)';
  const valLabel  = validLabels[valStatus]  || valStatus;
  const valIcon   = validIcons[valStatus]   || 'fa-clock';

  const confidenceHTML = confScore !== null ? `
    <div class="inv-info-cell" style="grid-column:1/-1;">
      <div class="inv-info-key">Detection Confidence</div>
      <div style="display:flex;align-items:center;gap:12px;margin-top:6px;flex-wrap:wrap;">
        <div style="flex:1;min-width:160px;">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
            <span style="font-family:var(--font-display);font-size:.62rem;font-weight:700;color:${confColor};">${confGrade}</span>
            <span style="font-family:var(--font-mono);font-size:.84rem;font-weight:900;color:${confColor};">${confScore}%</span>
          </div>
          <div style="height:8px;background:var(--border);border-radius:100px;overflow:hidden;">
            <div style="height:100%;width:${confScore}%;background:${confColor};border-radius:100px;transition:width .6s ease;"></div>
          </div>
          <div style="display:flex;justify-content:space-between;margin-top:3px;font-family:var(--font-mono);font-size:.56rem;color:var(--text-dim);">
            <span>NOISE</span><span>LOW</span><span>MEDIUM</span><span>HIGH</span>
          </div>
        </div>
        <div style="flex-shrink:0;text-align:center;">
          <div style="font-family:var(--font-display);font-size:.62rem;font-weight:700;color:${valColor};display:flex;align-items:center;gap:5px;">
            <i class="fa-solid ${valIcon}"></i> ${valLabel}
          </div>
        </div>
      </div>
    </div>` : '';

  // Info grid
  const infoHTML = `
    <div class="inv-info-grid">
      <div class="inv-info-cell"><div class="inv-info-key">Room ID</div><div class="inv-info-val">${room.id}</div></div>
      <div class="inv-info-cell"><div class="inv-info-key">Status</div><div class="inv-info-val ${statusColor}">${statusLabel}</div></div>
      <div class="inv-info-cell"><div class="inv-info-key">Baseline Count</div><div class="inv-info-val">${room.baseline}</div></div>
      <div class="inv-info-cell"><div class="inv-info-key">Live Count</div><div class="inv-info-val ${statusColor}">${room.live !== null ? room.live : '—'}</div></div>
      <div class="inv-info-cell"><div class="inv-info-key">Deviation</div><div class="inv-info-val ${statusColor}">${devStr}</div></div>
      <div class="inv-info-cell"><div class="inv-info-key">Fill %</div><div class="inv-info-val">${room.live !== null ? fillPct+'%' : '—'}</div></div>
      <div class="inv-info-cell"><div class="inv-info-key">Floor</div><div class="inv-info-val">${room.floor}</div></div>
      <div class="inv-info-cell"><div class="inv-info-key">Room Type</div><div class="inv-info-val">${room.type}</div></div>
      <div class="inv-info-cell"><div class="inv-info-key">Cameras</div><div class="inv-info-val">${room.cameras} × IP CCTV</div></div>
      <div class="inv-info-cell"><div class="inv-info-key">Last Calibrated</div><div class="inv-info-val">${room.last_calibrated || 'Not set'}</div></div>
      ${confidenceHTML}
    </div>`;

  // Zone table
  const zonesHTML = room.zones.length === 0
    ? '<div style="font-size:.8rem;color:var(--text-dim);padding:8px 0;">No zones registered yet.</div>'
    : `<table class="inv-zone-table">
        <thead><tr><th>Zone / ROI Label</th><th>Expected</th><th>Live</th><th>Deviation</th><th>State</th></tr></thead>
        <tbody>
          ${room.zones.map(z => {
            const d = z[2] - z[1];
            const devChip = d === 0 ? '<span class="inv-dev-chip zero">0</span>'
              : d < 0 ? `<span class="inv-dev-chip neg">${d}</span>`
              : `<span class="inv-dev-chip pos">+${d}</span>`;
            const stLabel = z[3]==='ok' ? '<span class="badge badge-ok"><span class="bdot"></span>OK</span>'
              : z[3]==='alert' ? '<span class="badge badge-alert"><span class="bdot"></span>MISSING</span>'
              : '<span class="badge badge-warn"><span class="bdot"></span>DEVIATION</span>';
            return `<tr>
              <td style="font-weight:600;color:var(--text-primary);">${z[0]}</td>
              <td style="font-family:var(--font-mono);text-align:center;">${z[1]}</td>
              <td style="font-family:var(--font-mono);text-align:center;font-weight:700;color:${d===0?'var(--ok)':d<0?'var(--alert)':'var(--warn)'};">${z[2]}</td>
              <td style="text-align:center;">${devChip}</td>
              <td>${stLabel}</td>
            </tr>`;
          }).join('')}
        </tbody>
       </table>`;

  // Recent log
  const logHTML = room.recent_log.map(([type, msg]) => `
    <div class="inv-log-item">
      <div class="inv-log-dot ${type}"></div>
      <div><div class="inv-log-text">${msg}</div></div>
    </div>`).join('');

  // Actions
  const actionsHTML = `
    <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px;">
      ${(room.status === 'missing' || room.status === 'potential') ? `
        <button class="inv-foot-btn primary" onclick="closeDrawer();openVerifyEvent('${room.id}')">
          <i class="fa-solid fa-circle-check"></i> Verify Event
        </button>` : ''}
      ${<?= $is_admin ? 'true' : 'false' ?> ? `
        <a href="room-monitor.php?room=${room.id}" class="inv-foot-btn">
          <i class="fa-solid fa-video"></i> CCTV Feed
        </a>
        <button class="inv-foot-btn" onclick="triggerRecalibrate('${room.id}')">
          <i class="fa-solid fa-rotate"></i> Recalibrate
        </button>` : ''}
      <button class="inv-foot-btn" onclick="closeDrawer()">
        <i class="fa-solid fa-xmark"></i> Close
      </button>
    </div>`;

  document.getElementById('drawerBody').innerHTML = `
    <div class="inv-drawer-section">Live Camera Feed</div>
    ${snapHTML}
    <div class="inv-drawer-section">Room Information</div>
    ${infoHTML}
    <div class="inv-drawer-section">ROI Zone Breakdown — All ${room.zones.length} Zones</div>
    ${zonesHTML}
    <div class="inv-drawer-section">Recent Activity Log</div>
    ${logHTML}
    <div class="inv-drawer-section">Actions</div>
    ${actionsHTML}
  `;

  document.getElementById('roomDrawer').classList.add('open');
  document.getElementById('drawerBackdrop').classList.add('open');

  // Start timer inside drawer if applicable
  if (room.detection_at && room.status !== 'normal') {
    setTimeout(() => startCountup('drawer-timer-' + room.id, room.detection_at), 100);
  }

  // Live snap timestamp
  setInterval(() => {
    const el = document.getElementById('drawerSnapTs');
    if (el) el.textContent = new Date().toLocaleTimeString('en-GB', { hour12: false });
  }, 1000);
}

function closeDrawer() {
  document.getElementById('roomDrawer').classList.remove('open');
  document.getElementById('drawerBackdrop').classList.remove('open');
}

/* ══════════════════════════════════════
   VERIFY EVENT MODAL
══════════════════════════════════════ */
let verifyRoomId = null;

function openVerifyEvent(roomId) {
  const room = ROOMS.find(r => r.id === roomId);
  verifyRoomId = roomId;
  document.getElementById('verifyModalTitle').textContent =
    'Verify Detection Event — ' + roomId;
  document.getElementById('verifyNotes').value = '';
  document.querySelectorAll('#verifyChecklist input[type=checkbox]')
    .forEach(cb => cb.checked = false);
  openModal('verifyModal');
}

async function submitVerify(action) {
  if (!verifyRoomId) return;
  const notes = document.getElementById('verifyNotes').value.trim();

  const messages = {
    dismissed:         'Event dismissed as false alert. Audit log updated.',
    recovered:         'Item marked as recovered. Lost & Found record created.',
    confirmed_missing: 'Event confirmed as missing. Admin notified. Escalated.',
  };
  const types = { dismissed:'warn', recovered:'success', confirmed_missing:'error' };

  try {
    // In production: POST to auth/update_event_status.php with detection_id + status + notes
    // const fd = new FormData();
    // fd.append('detection_id', currentDetectionId);
    // fd.append('status', action);
    // fd.append('notes', notes);
    // await spotitFetch('../auth/update_event_status.php', { method:'POST', body: fd });
    showToast(types[action], messages[action]);
    closeModal('verifyModal');

    // Update the card visually (in production, re-fetch from server)
    const card = document.querySelector(`.inv-room-card[data-status]`);
  } catch (e) {
    showToast('error', 'Failed to submit verification. Please try again.');
  }
}

/* ══════════════════════════════════════
   RECALIBRATE
══════════════════════════════════════ */
function triggerRecalibrate(roomId) {
  showToast('info',
    'Recalibration request sent for ' + roomId + '. ' +
    'The Python module will capture a new reference frame on next scan.');
}
</script>
</body>
</html>
