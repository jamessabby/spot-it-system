<?php
/**
 * S.P.O.T.-IT — Admin Dashboard
 * pages/dashboard-admin.php
 */
require_once __DIR__ . '/../auth/service_bootstrap.php';
ms_require_role('admin', 'login.php');
$active_page = 'dashboard';
$user_role   = 'admin';

// Live statistics calculations
$statRooms = (int)$monitorPdo->query("SELECT COUNT(*) FROM rooms WHERE is_active = 1")->fetchColumn();
$statMissing = (int)$monitorPdo->query("SELECT COUNT(*) FROM detections WHERE status IN ('pending', 'potential', 'confirmed_missing') AND is_removed = 0")->fetchColumn();
$statPending = (int)$monitorPdo->query("SELECT COUNT(*) FROM detections WHERE validation_status IN ('pending_review', 'needs_review') AND is_removed = 0")->fetchColumn();
$statToday = (int)$monitorPdo->query("SELECT COUNT(*) FROM detections WHERE DATE(detected_at) = CURDATE()")->fetchColumn();

// Fetch live rooms status list
$roomStatusStmt = $monitorPdo->prepare("
    SELECT r.room_id, r.room_name, r.floor, r.room_type, r.baseline_count, r.monitoring_status, d.live_count, d.deviation, d.det_status, d.detected_at
    FROM rooms r
    LEFT JOIN (
        SELECT d2.room_id, d2.live_count, d2.deviation, d2.status AS det_status, d2.detected_at
        FROM detections d2
        INNER JOIN (
            SELECT room_id, MAX(detection_id) AS max_id
            FROM detections
            WHERE status IN ('pending','potential','confirmed_missing')
              AND is_removed = 0
            GROUP BY room_id
        ) latest ON d2.detection_id = latest.max_id
    ) d ON r.room_id = d.room_id
    WHERE r.is_active = 1
    ORDER BY r.floor, r.room_id
");
$roomStatusStmt->execute();
$liveRooms = $roomStatusStmt->fetchAll();

// Fetch recent event logs (last 5 detections)
$recentEventsStmt = $monitorPdo->query("
    SELECT d.detection_id, d.room_id, d.object_zone, d.object_type, d.detected_at, d.live_count, d.baseline_count, d.deviation, d.status, d.validation_status
    FROM detections d
    ORDER BY d.detected_at DESC LIMIT 5
");
$recentEvents = $recentEventsStmt->fetchAll();

// Fetch active alerts
$activeAlertsStmt = $monitorPdo->query("
    SELECT d.detection_id, d.room_id, d.object_zone, d.detected_at, d.status
    FROM detections d
    WHERE d.status IN ('pending','potential','confirmed_missing') AND d.is_removed = 0
    ORDER BY d.detected_at DESC LIMIT 5
");
$activeAlerts = $activeAlertsStmt->fetchAll();
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
    <!-- Topbar -->
    <div class="topbar">
      <button class="tb-btn tb-hamburger" id="hamburgerBtn" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
      <div><span class="topbar-title">Overview Dashboard</span><span class="topbar-sub">— CEAT Building, MLH</span></div>
      <div class="live-pill" id="tourLiveIndicator"><div class="live-dot"></div>LIVE</div>
      <div class="topbar-right">
        <span style="font-family:var(--font-mono);font-size:.7rem;color:var(--text-dim);" id="liveClock"></span>
        <button class="tb-btn" onclick="location.reload()" title="Refresh"><i class="fa-solid fa-rotate-right"></i></button>
        <button class="tb-btn notif-bell-wrap" id="tourNotifBtn" title="Notifications" onclick="toggleNotifPanel()">
          <i class="fa-solid fa-bell"></i>
          <div class="notif-bell-dot" id="notifDot"></div>
        </button>
        <button class="tb-btn" onclick="toggleTheme()" title="Theme"><i class="fa-solid fa-circle-half-stroke"></i></button>
      </div>
    </div>

    <div class="page-body">
      <!-- Stat cards -->
      <div class="stat-grid" id="tourStatGrid">
        <div class="stat-card"><div class="stat-icon ok"><i class="fa-solid fa-circle-check"></i></div><div><div class="stat-num" id="statRooms"><?= $statRooms ?></div><div class="stat-label">Rooms Monitoring</div><div class="stat-delta flat"><i class="fa-solid fa-minus"></i> Active</div></div></div>
        <div class="stat-card"><div class="stat-icon alert"><i class="fa-solid fa-circle-minus"></i></div><div><div class="stat-num" id="statMissing"><?= $statMissing ?></div><div class="stat-label">Active Deviations</div><div class="stat-delta <?= $statMissing > 0 ? 'up' : 'flat' ?>"><i class="fa-solid <?= $statMissing > 0 ? 'fa-arrow-up' : 'fa-minus' ?>"></i> <?= $statMissing > 0 ? 'Action required' : 'No deviations' ?></div></div></div>
        <div class="stat-card"><div class="stat-icon warn"><i class="fa-solid fa-clock"></i></div><div><div class="stat-num" id="statPending"><?= $statPending ?></div><div class="stat-label">Pending Validation</div><div class="stat-delta flat"><i class="fa-solid fa-arrow-right"></i> Awaiting staff review</div></div></div>
        <div class="stat-card"><div class="stat-icon info"><i class="fa-solid fa-box-open"></i></div><div><div class="stat-num" id="statToday"><?= $statToday ?></div><div class="stat-label">Events Today</div><div class="stat-delta flat"><i class="fa-solid fa-calendar-day"></i> Logged today</div></div></div>
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
                  <?php if (empty($liveRooms)): ?>
                  <tr>
                    <td colspan="7" class="text-center py-3" style="color:var(--text-dim);">No monitored rooms configured.</td>
                  </tr>
                  <?php else: ?>
                  <?php foreach ($liveRooms as $r):
                      $rid       = $r['room_id'];
                      $base      = (int)$r['baseline_count'];
                      $dev       = $r['deviation'] !== null ? (int)$r['deviation'] : 0;
                      $liveCount = $r['live_count'] !== null ? (int)$r['live_count'] : $base;
                      $status    = $r['det_status'] ?? 'normal';
                      if ($r['monitoring_status'] !== 'active') {
                          $status = 'offline';
                      }

                      $statusLabel = match($status) {
                          'confirmed_missing' => 'MISSING',
                          'potential'         => 'POTENTIAL',
                          'offline'           => 'OFFLINE',
                          default             => 'NORMAL',
                      };

                      $badgeClass = match($status) {
                          'confirmed_missing' => 'badge-alert',
                          'potential'         => 'badge-warn',
                          'offline'           => 'badge-muted',
                          default             => 'badge-ok',
                      };

                      $devClass = $dev < 0 ? 'dev-neg' : ($dev > 0 ? 'dev-pos' : 'dev-zero');
                      $devSign  = $dev < 0 ? '−' . abs($dev) : ($dev > 0 ? '+' . $dev : '0');
                  ?>
                  <tr>
                    <td><div class="col-id"><?= htmlspecialchars($rid) ?></div><div class="col-sub"><?= htmlspecialchars($r['room_name']) ?></div></td>
                    <td><span style="font-family:var(--font-mono);font-size:.88rem;font-weight:600;"><?= $liveCount ?></span><span class="col-mono"> / <?= $base ?></span></td>
                    <td><span class="dev-chip <?= $devClass ?>"><?= $devSign ?></span></td>
                    <td><span class="badge <?= $badgeClass ?>"><span class="bdot"></span><?= $statusLabel ?></span></td>
                    <td>
                      <?php if ($status === 'confirmed_missing' || $status === 'potential'): ?>
                        <span class="countdown <?= $status === 'confirmed_missing' ? 'alert' : 'warn' ?>" id="t-<?= strtolower($rid) ?>">--:--:--</span>
                        <script>
                          setTimeout(() => startCountup('t-<?= strtolower($rid) ?>', '<?= $r['detected_at'] ?>'), 100);
                        </script>
                      <?php else: ?>
                        <span class="col-mono">—</span>
                      <?php endif; ?>
                    </td>
                    <td><span class="col-mono"><?= $r['detected_at'] ? date('H:i:s', strtotime($r['detected_at'])) : '—' ?></span></td>
                    <td>
                      <?php if ($status === 'confirmed_missing' || $status === 'potential'): ?>
                        <button class="btn btn-primary btn-sm" onclick="openEventModal('<?= strtolower($rid) ?>')">Review</button>
                      <?php else: ?>
                        <button class="btn btn-sm" onclick="location.href='room-monitor.php?room=<?= urlencode($rid) ?>'">View</button>
                      <?php endif; ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                  <?php endif; ?>
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
            $events = [];
            foreach ($recentEvents as $e) {
                $statusLabel = match($e['status']) {
                    'confirmed_missing' => 'Confirmed Missing',
                    'potential'         => 'Potential',
                    'dismissed'         => 'Dismissed',
                    'recovered'         => 'Recovered',
                    default             => 'Pending',
                };
                $stCls = match($e['status']) {
                    'confirmed_missing' => 'est-confirmed',
                    'potential'         => 'est-pending',
                    'dismissed'         => 'est-dismissed',
                    'recovered'         => 'est-recovered',
                    default             => 'est-pending',
                };
                $dev = (int)$e['deviation'];
                $devSign = $dev < 0 ? '−' . abs($dev) : ($dev > 0 ? '+' . $dev : '0');
                $devCls = $dev < 0 ? 'neg' : ($dev > 0 ? 'pos' : 'zero');
                $cls = ($e['status'] === 'pending' || $e['status'] === 'potential') ? 'warn-event unread' : '';
                
                $events[] = [
                    'id'      => strtolower($e['room_id']),
                    'room'    => $e['room_id'],
                    'zone'    => $e['object_zone'],
                    'title'   => "{$e['object_type']} deviation in registered ROI zone",
                    'time'    => date('Y-m-d · H:i:s', strtotime($e['detected_at'])),
                    'dev'     => "{$devSign} item" . (abs($dev) > 1 ? 's' : ''),
                    'devCls'  => $devCls,
                    'status'  => $statusLabel,
                    'stCls'   => $stCls,
                    'cls'     => $cls
                ];
            }
            ?>
            <?php if (empty($events)): ?>
            <div class="p-3 text-center" style="color:var(--text-dim);font-size:.82rem;">No recent detection events logged.</div>
            <?php else: ?>
            <?php foreach ($events as $ev): ?>
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
            <?php endif; ?>
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
            $alerts = [];
            foreach ($activeAlerts as $a) {
                $statusType = match($a['status']) {
                    'confirmed_missing' => 'alert',
                    'potential'         => 'warn',
                    default             => 'info',
                };
                $icon = match($a['status']) {
                    'confirmed_missing' => 'fa-circle-minus',
                    'potential'         => 'fa-clock',
                    default             => 'fa-circle-info',
                };
                $statusLabel = match($a['status']) {
                    'confirmed_missing' => 'confirmed missing',
                    'potential'         => 'potentially lost',
                    default             => 'detected',
                };
                
                $alerts[] = [
                    't'    => $statusType,
                    'i'    => $icon,
                    'body' => "<strong>{$a['room_id']}</strong> — {$a['object_zone']} is <strong>{$statusLabel}</strong>.",
                    'ts'   => date('H:i:s', strtotime($a['detected_at']))
                ];
            }
            ?>
            <?php if (empty($alerts)): ?>
            <div class="p-3 text-center" style="color:var(--text-dim);font-size:.82rem;">No active alerts.</div>
            <?php else: ?>
            <?php foreach ($alerts as $a): ?>
            <div class="alert-item">
              <div class="alert-ico <?= $a['t'] ?>"><i class="fa-solid <?= $a['i'] ?>"></i></div>
              <div class="alert-body"><?= $a['body'] ?><span class="alert-ts"><?= $a['ts'] ?></span></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <div class="card">
            <div class="card-head">
              <div class="card-title"><i class="fa-solid fa-timeline"></i> Today's Timeline</div>
              <span style="font-size:.65rem;color:var(--text-dim);font-family:var(--font-mono);"><?= date('F j, Y') ?></span>
            </div>
            <?php
            // Query today's monitoring logs for the timeline
            $timelineLogs = [];
            try {
                $timelineLogsStmt = $monitorPdo->query("
                    SELECT room_id, event_type, event_message, logged_at 
                    FROM monitoring_logs 
                    WHERE DATE(logged_at) = CURDATE() 
                    ORDER BY logged_at DESC LIMIT 10
                ");
                $timelineLogs = $timelineLogsStmt->fetchAll();
            } catch (Throwable $e) {}

            $tl = [];
            foreach ($timelineLogs as $log) {
                $type = match($log['event_type']) {
                    'detection', 'auto_escalation', 'confirmed_missing' => 'alert',
                    'status_update', 'claim_submitted'                  => 'warn',
                    'claim_completed', 'recalibration'                  => 'ok',
                    default                                             => 'info',
                };
                $roomPrefix = $log['room_id'] ? "<strong>{$log['room_id']}</strong> — " : "";
                $tl[] = [
                    't'   => $type,
                    'lbl' => $roomPrefix . htmlspecialchars($log['event_message']),
                    'meta'=> date('H:i:s', strtotime($log['logged_at'])) . " · " . $log['event_type']
                ];
            }
            ?>
            <?php if (empty($tl)): ?>
            <div class="p-3 text-center" style="color:var(--text-dim);font-size:.82rem;">No activity logged today.</div>
            <?php else: ?>
            <?php foreach ($tl as $item): ?>
            <div class="timeline-item">
              <div class="tl-dot <?= $item['t'] ?>"></div>
              <div><div class="tl-label"><?= $item['lbl'] ?></div><div class="tl-meta"><?= $item['meta'] ?></div></div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
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

      <!-- ── Confidence Score Panel ── -->
      <div id="confidencePanel" style="
        background:var(--bg-base);border:1px solid var(--border);border-radius:10px;
        padding:14px 16px;margin:14px 0 0;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;flex-wrap:wrap;gap:8px;">
          <div style="font-family:var(--font-display);font-size:.72rem;font-weight:700;
                      letter-spacing:.1em;text-transform:uppercase;color:var(--text-dim);">
            <i class="fa-solid fa-chart-simple" style="margin-right:5px;color:var(--green-main);"></i>
            Detection Confidence
          </div>
          <div style="display:flex;align-items:center;gap:8px;">
            <span id="dConfidenceGrade" style="font-family:var(--font-display);font-size:.64rem;
                  font-weight:800;letter-spacing:.08em;padding:3px 10px;border-radius:100px;
                  background:var(--ok-bg);color:var(--ok);border:1px solid rgba(0,150,70,.2);">HIGH</span>
            <span id="dValidationStatus" style="font-size:.68rem;color:var(--warn);
                  font-family:var(--font-display);font-weight:700;display:flex;align-items:center;gap:4px;">
              <i class="fa-solid fa-clock"></i> Pending Verification
            </span>
          </div>
        </div>

        <!-- Score bar -->
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
          <div style="flex:1;height:10px;background:var(--border);border-radius:100px;overflow:hidden;position:relative;">
            <!-- Grade zones -->
            <div style="position:absolute;left:0;top:0;width:29%;height:100%;background:rgba(208,2,27,.08);"></div>
            <div style="position:absolute;left:30%;top:0;width:29%;height:100%;background:rgba(230,126,0,.08);"></div>
            <div style="position:absolute;left:60%;top:0;width:24%;height:100%;background:rgba(0,180,100,.08);"></div>
            <div style="position:absolute;left:85%;top:0;width:15%;height:100%;background:rgba(0,180,100,.14);"></div>
            <div id="dConfidenceBar" style="height:100%;width:87%;background:var(--ok);
                 border-radius:100px;transition:width .6s ease,background .4s;"></div>
          </div>
          <span id="dConfidenceScore" style="font-family:var(--font-mono);font-size:1.1rem;
                font-weight:900;color:var(--ok);min-width:40px;text-align:right;">87%</span>
        </div>

        <!-- Zone labels -->
        <div style="display:flex;justify-content:space-between;margin-bottom:12px;
                    font-family:var(--font-mono);font-size:.55rem;color:var(--text-dim);">
          <span>0 — NOISE</span><span>30 — LOW</span><span>60 — MEDIUM</span><span>85 — HIGH</span><span>100</span>
        </div>

        <!-- Signal breakdown -->
        <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:8px;" id="dSignalBreakdown">
          <div style="background:var(--bg-surface);border:1px solid var(--border);border-radius:8px;padding:9px 10px;text-align:center;">
            <div style="font-family:var(--font-mono);font-size:.82rem;font-weight:800;color:var(--text-primary);" id="dSigMatch">42.5</div>
            <div style="font-family:var(--font-display);font-size:.58rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-dim);margin-top:3px;">Template Match</div>
            <div style="font-size:.62rem;color:var(--text-dim);margin-top:1px;">Weight: 50 pts</div>
          </div>
          <div style="background:var(--bg-surface);border:1px solid var(--border);border-radius:8px;padding:9px 10px;text-align:center;">
            <div style="font-family:var(--font-mono);font-size:.82rem;font-weight:800;color:var(--text-primary);" id="dSigROI">24.5</div>
            <div style="font-family:var(--font-display);font-size:.58rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-dim);margin-top:3px;">ROI Change %</div>
            <div style="font-size:.62rem;color:var(--text-dim);margin-top:1px;">Weight: 30 pts</div>
          </div>
          <div style="background:var(--bg-surface);border:1px solid var(--border);border-radius:8px;padding:9px 10px;text-align:center;">
            <div style="font-family:var(--font-mono);font-size:.82rem;font-weight:800;color:var(--text-primary);" id="dSigDev">10</div>
            <div style="font-family:var(--font-display);font-size:.58rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:var(--text-dim);margin-top:3px;">Count Deviation</div>
            <div style="font-size:.62rem;color:var(--text-dim);margin-top:1px;">Weight: 20 pts</div>
          </div>
        </div>

        <!-- Validation action row -->
        <div style="display:flex;align-items:center;gap:8px;margin-top:12px;padding-top:10px;border-top:1px solid var(--border);">
          <span style="font-size:.72rem;color:var(--text-dim);font-family:var(--font-display);font-weight:600;">Override Validation:</span>
          <button class="btn btn-sm" onclick="submitValidation('verified')" style="font-size:.66rem;">
            <i class="fa-solid fa-circle-check" style="color:var(--ok);"></i> Verify
          </button>
          <button class="btn btn-sm" onclick="submitValidation('rejected')" style="font-size:.66rem;">
            <i class="fa-solid fa-ban" style="color:var(--text-dim);"></i> False Alarm
          </button>
          <button class="btn btn-sm" onclick="submitValidation('needs_review')" style="font-size:.66rem;">
            <i class="fa-solid fa-flag" style="color:var(--warn);"></i> Flag for Review
          </button>
        </div>
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
        <button class="modal-btn" style="background:var(--info-bg);color:var(--info);border-color:rgba(26,106,181,.25);flex:1.2;" onclick="createCommunityPost()">
          <i class="fa-brands fa-reddit"></i> Create Community Post
        </button>
        <button class="modal-btn recover" onclick="markEvent('recovered')"><i class="fa-solid fa-circle-check"></i> Mark Recovered</button>
        <button class="modal-btn confirm" onclick="markEvent('confirm')"><i class="fa-solid fa-triangle-exclamation"></i> Confirm Missing</button>
      </div>
    </div>
  </div>
</div>
<div class="toast-stack" id="toastStack"></div>

<!-- ══════════ NOTIFICATION SLIDE PANEL ══════════ -->
<div id="notifPanel" style="
  position:fixed;top:0;right:-380px;width:360px;height:100vh;
  background:var(--bg-card);border-left:1px solid var(--border);
  box-shadow:var(--shadow-lg);z-index:5000;display:flex;flex-direction:column;
  transition:right .3s cubic-bezier(.4,0,.2,1);overflow:hidden;">
  <div style="display:flex;align-items:center;justify-content:space-between;
               padding:16px 18px;border-bottom:1px solid var(--border);flex-shrink:0;">
    <div style="font-family:var(--font-display);font-size:.9rem;font-weight:800;color:var(--text-primary);">
      <i class="fa-solid fa-bell" style="color:var(--green-main);margin-right:7px;"></i>
      Notifications
      <span class="badge badge-alert" id="notifCount" style="margin-left:6px;font-size:.62rem;display:none;">0</span>
    </div>
    <div style="display:flex;gap:6px;">
      <button class="btn btn-sm" onclick="markAllRead()" style="font-size:.68rem;">
        <i class="fa-solid fa-check-double"></i> Mark all read
      </button>
      <button class="tb-btn" onclick="toggleNotifPanel()" style="width:28px;height:28px;">
        <i class="fa-solid fa-xmark"></i>
      </button>
    </div>
  </div>
  <div style="flex:1;overflow-y:auto;" id="notifList">
    <div style="padding:2rem;text-align:center;color:var(--text-dim);" id="notifEmpty">
      <i class="fa-solid fa-bell-slash" style="font-size:1.8rem;margin-bottom:.7rem;display:block;"></i>
      <div style="font-size:.82rem;">No notifications yet.</div>
    </div>
  </div>
</div>
<!-- Backdrop for notification panel -->
<div id="notifBackdrop" onclick="toggleNotifPanel()" style="
  display:none;position:fixed;inset:0;background:rgba(0,0,0,.35);
  z-index:4999;backdrop-filter:blur(2px);"></div>

<script src="../assets/js/spotit.js"></script>
<script>
startLiveClock('liveClock');
// Start countup timers from known detection times
startCountup('t-mlh306', '2026-06-15 14:03:44');
startCountup('t-mlh305', '2026-06-15 14:30:12');
startCountup('t-mlh303', '2026-06-15 14:53:05');

const modalData = {
  mlh306: {
    title:'Detection Event — MLH 306', room:'MLH 306 — Systems & App Dev', time:'2026-06-15 14:03:44',
    baseline:'30 items', live:'29 items (−1)', liveClass:'alert',
    zone:'Workstation 7 — Monitor Zone', duration:'1 hr 02 min — Escalated',
    status:'Confirmed Missing', statusClass:'alert',
    snapRoom:'CAM-01 · MLH 306', alertColor:'#ff4d4d', alertLabel:'WS-07 Monitor ✗ MISSING',
    confidence: { score:87, grade:'HIGH', gradeColor:'var(--ok)',
      validation:'Auto-Accepted', validColor:'var(--ok)', validIcon:'fa-circle-check',
      match:42.5, roi:24.5, dev:20,
      matchRaw:'0.15', roiRaw:'81.6%', devRaw:'−1' },
  },
  mlh305: {
    title:'Detection Event — MLH 305', room:'MLH 305 — Logic & Algorithms', time:'2026-06-15 14:30:12',
    baseline:'30 items', live:'28 items (−2)', liveClass:'alert',
    zone:'WS-03 & WS-04 — Keyboard Zones', duration:'34 min — Potentially Lost',
    status:'Potentially Lost', statusClass:'warn',
    snapRoom:'CAM-01 · MLH 305', alertColor:'#e67e00', alertLabel:'WS-03/WS-04 Keyboard ✗',
    confidence: { score:71, grade:'MEDIUM', gradeColor:'var(--warn)',
      validation:'Pending Verification', validColor:'var(--warn)', validIcon:'fa-clock',
      match:31.0, roi:20.0, dev:20,
      matchRaw:'0.38', roiRaw:'66.7%', devRaw:'−2' },
  },
  mlh303: {
    title:'Detection Event — MLH 303', room:'MLH 303 — Advanced Programming', time:'2026-06-15 14:53:05',
    baseline:'30 items', live:'31 items (+1)', liveClass:'warn',
    zone:'Unregistered — South Corner', duration:'12 min',
    status:'Pending — Unregistered Item', statusClass:'warn',
    snapRoom:'CAM-01 · MLH 303', alertColor:'#e67e00', alertLabel:'UNREGISTERED ITEM +1',
    confidence: { score:44, grade:'LOW', gradeColor:'var(--alert)',
      validation:'Needs Review', validColor:'var(--alert)', validIcon:'fa-triangle-exclamation',
      match:18.0, roi:16.0, dev:10,
      matchRaw:'0.64', roiRaw:'53.3%', devRaw:'+1' },
  },
};

let currentDetectionId = null;

function openEventModal(id) {
  const d = modalData[id]; if (!d) return;
  currentDetectionId = id;
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

  // ── Populate confidence panel ──
  const c = d.confidence;
  const scoreEl   = document.getElementById('dConfidenceScore');
  const barEl     = document.getElementById('dConfidenceBar');
  const gradeEl   = document.getElementById('dConfidenceGrade');
  const validEl   = document.getElementById('dValidationStatus');

  scoreEl.textContent = c.score + '%';
  scoreEl.style.color = c.gradeColor;
  barEl.style.width   = c.score + '%';
  barEl.style.background = c.gradeColor;

  gradeEl.textContent = c.grade;
  gradeEl.style.cssText = `font-family:var(--font-display);font-size:.64rem;font-weight:800;
    letter-spacing:.08em;padding:3px 10px;border-radius:100px;color:${c.gradeColor};
    background:${c.gradeColor.replace(')', '-bg)').replace('var(--','var(--')};
    border:1px solid currentColor;opacity:.9;`;

  validEl.innerHTML = `<i class="fa-solid ${c.validIcon}"></i> ${c.validation}`;
  validEl.style.color = c.validColor;

  document.getElementById('dSigMatch').textContent = c.match;
  document.getElementById('dSigROI').textContent   = c.roi;
  document.getElementById('dSigDev').textContent   = c.dev;

  // Annotate signal labels with raw values
  const breakdown = document.getElementById('dSignalBreakdown');
  const cells = breakdown.querySelectorAll('div[style]');
  if (cells[0]) cells[0].querySelector('div:first-child').title = 'match_score: ' + c.matchRaw;
  if (cells[1]) cells[1].querySelector('div:first-child').title = 'roi_change_pct: ' + c.roiRaw;
  if (cells[2]) cells[2].querySelector('div:first-child').title = 'deviation: ' + c.devRaw;

  openModal('eventModal');
}

async function submitValidation(validationStatus) {
  // In production: POST to auth/validate_detection.php
  const labels = {
    verified:     'Detection verified by staff. Validation status updated.',
    rejected:     'Marked as false alarm. Confidence flagged for model review.',
    needs_review: 'Flagged for mandatory review. Supervisor notified.',
  };
  showToast(
    validationStatus === 'verified' ? 'success' :
    validationStatus === 'rejected' ? 'warn' : 'error',
    labels[validationStatus]
  );
  // Update UI optimistically
  const validEl = document.getElementById('dValidationStatus');
  const icons   = { verified:'fa-circle-check', rejected:'fa-ban', needs_review:'fa-triangle-exclamation' };
  const colors  = { verified:'var(--ok)', rejected:'var(--text-dim)', needs_review:'var(--alert)' };
  const labels2 = { verified:'Verified by Staff', rejected:'Rejected (False Alarm)', needs_review:'Needs Review' };
  validEl.innerHTML = `<i class="fa-solid ${icons[validationStatus]}"></i> ${labels2[validationStatus]}`;
  validEl.style.color = colors[validationStatus];
}

function markEvent(action) {
  const msgs = { dismissed:'Event dismissed as false alert.', recovered:'Item marked as recovered. Record updated.', confirm:'Event confirmed as missing. Staff notified.' };
  showToast(action === 'recovered' ? 'success' : action === 'dismissed' ? 'warn' : 'error', msgs[action]);
  closeModal('eventModal');
}

function createCommunityPost() {
  const room = document.getElementById('dRoom').textContent;
  const zone = document.getElementById('dZone').textContent;
  const time = document.getElementById('dTime').textContent;
  closeModal('eventModal');
  showToast('info','Redirecting to Community Forum…');
  setTimeout(() => {
    const params = new URLSearchParams({ prefill: '1', room, item: zone, time });
    window.location.href = 'forum.php?' + params.toString();
  }, 900);
}

/* ── Notification panel ── */


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

<script>
function filterPanelType(type, btn) {
  document.querySelectorAll('.notif-filter-tab').forEach(t => t.classList.remove('active'));
  btn.classList.add('active');
  loadNotifPanel(type || undefined);
}
function toggleNotifPanel() {
  const panel    = document.getElementById('notifPanel');
  const backdrop = document.getElementById('notifBackdrop');
  window._notifPanelOpen = !window._notifPanelOpen;
  panel.classList.toggle('open', window._notifPanelOpen);
  backdrop.classList.toggle('open', window._notifPanelOpen);
  if (window._notifPanelOpen) {
    loadNotifPanel();
    document.getElementById('notifPanelTs').textContent =
      'Updated ' + new Date().toLocaleTimeString('en-PH', {hour12:true});
  }
}
</script>
</body>
</html>
