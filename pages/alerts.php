<?php
/**
 * S.P.O.T.-IT — Alerts Triage Page
 * pages/alerts.php
 *
 * The dedicated real-time triage surface for lab personnel and admins.
 * Single screen showing every active, unresolved, high-priority event —
 * CCTV deviations, offline cameras, and pending lost & found claims —
 * ranked by priority with direct action buttons.
 *
 * Sits between "something was detected" (inventory-monitor.php / room-monitor.php)
 * and "staff resolves it" (update_event_status.php / claiming-station.php).
 *
 * MICROSERVICES: Reads from spotit_monitor_db (rooms, detections) and
 * spotit_lf_db (claims, recovered_items). Writes go through existing
 * auth/ handlers — this page never writes directly.
 */
require_once __DIR__ . '/../auth/service_bootstrap.php';
$active_page = 'alerts';
$user_role   = $_SESSION['user_role'] ?? 'staff';
$uname       = $_SESSION['user_name'] ?? 'User';

// Enforce access control: Admin & Staff only
if ($user_role !== 'admin' && $user_role !== 'staff') {
    header('Location: dashboard-student.php');
    exit();
}

// ── 1. Triage stat counts ──────────────────────────────────────────────────────
$cctvCount = (int)$monitorPdo->query(
    "SELECT COUNT(*) FROM detections WHERE status IN ('pending','potential') AND room_id != 'DESK' AND is_removed = 0"
)->fetchColumn();

$missingCount = (int)$monitorPdo->query(
    "SELECT COUNT(*) FROM detections WHERE status = 'confirmed_missing' AND room_id != 'DESK' AND is_removed = 0"
)->fetchColumn();

$claimsCount = (int)$lfPdo->query(
    "SELECT COUNT(*) FROM claims WHERE status IN ('pending','verified')"
)->fetchColumn();

$totalAlerts = $cctvCount + $missingCount + $claimsCount;

$offlineRoomsStmt = $monitorPdo->query(
    "SELECT room_id, room_name, floor, monitoring_status
     FROM rooms WHERE is_active = 1 AND room_id != 'DESK' AND monitoring_status != 'active'
     ORDER BY room_id"
);
$offlineRooms = $offlineRoomsStmt->fetchAll();
$offlineCount = count($offlineRooms);

// ── 2. Pull unresolved detections (CCTV deviations + confirmed missing) ───────
$detStmt = $monitorPdo->query(
    "SELECT d.detection_id, d.room_id, r.room_name, d.object_type, d.object_zone,
            d.detected_at, d.snapshot_path, d.snapshot_path_b,
            d.baseline_count, d.live_count, (d.live_count - d.baseline_count) AS deviation,
            d.status, d.notes,
            TIMESTAMPDIFF(MINUTE, d.detected_at, NOW()) AS elapsed_minutes
     FROM detections d
     LEFT JOIN rooms r ON r.room_id = d.room_id
     WHERE d.status IN ('pending','potential','confirmed_missing') AND d.room_id != 'DESK' AND d.is_removed = 0
     ORDER BY d.detected_at ASC"
);
$detections = $detStmt->fetchAll();

// ── 3. Pull pending / verified claims ──────────────────────────────────────────
$claimStmt = $lfPdo->query(
    "SELECT c.id AS claim_id, c.claimant_name, c.university_id, c.item_description,
            c.status, c.submitted_at,
            r.item_type, r.room_id, r.found_location, r.snapshot_path AS item_snapshot,
            TIMESTAMPDIFF(MINUTE, c.submitted_at, NOW()) AS elapsed_minutes
     FROM claims c
     LEFT JOIN recovered_items r ON r.recovery_id = c.recovery_id
     WHERE c.status IN ('pending','verified')
     ORDER BY c.submitted_at ASC"
);
$claims = $claimStmt->fetchAll();

// ── 4. Normalize everything into one priority-ranked list ─────────────────────
// Rank: Confirmed Missing(1) -> Potential(2) -> Camera Offline(3)
//       -> Verified Claim(4) -> Pending Claim(5) -> Pending Review(6)
$rows = [];

foreach ($detections as $d) {
    $rank = match ($d['status']) {
        'confirmed_missing' => 1,
        'potential'          => 2,
        default              => 6, // pending — new detection, not yet escalated
    };
    $rows[] = [
        'rank'      => $rank,
        'type'      => 'cctv',
        'sub_id'    => (int)$d['detection_id'],
        'icon'      => $d['status'] === 'confirmed_missing' ? 'fa-triangle-exclamation' : 'fa-magnifying-glass',
        'type_label'=> $d['status'] === 'confirmed_missing' ? 'Confirmed Missing' : ($d['status'] === 'potential' ? 'CCTV Deviation' : 'New Detection'),
        'type_sub'  => 'CCTV Auto-Detection',
        'subject'   => $d['object_type'] ?: 'Unregistered item',
        'subject_sub' => $d['object_zone'] ?: '—',
        'room'      => $d['room_id'],
        'room_name' => $d['room_name'],
        'ts'        => $d['detected_at'],
        'elapsed'   => (int)$d['elapsed_minutes'],
        'severity'  => $d['status'] === 'confirmed_missing' ? 'alert' : ($d['status'] === 'potential' ? 'warn' : 'info'),
        'snap_a'    => $d['snapshot_path'] ? SNAPSHOT_URL . basename($d['snapshot_path']) : null,
        'snap_b'    => $d['snapshot_path_b'] ? SNAPSHOT_URL . basename($d['snapshot_path_b']) : null,
        'deviation' => (int)$d['deviation'],
        'notes'     => $d['notes'],
    ];
}

foreach ($offlineRooms as $r) {
    $rows[] = [
        'rank'      => 3,
        'type'      => 'offline',
        'sub_id'    => 0,
        'icon'      => 'fa-wifi',
        'type_label'=> 'Camera Offline',
        'type_sub'  => ucfirst($r['monitoring_status']),
        'subject'   => $r['room_name'],
        'subject_sub' => $r['floor'] ?: '—',
        'room'      => $r['room_id'],
        'room_name' => $r['room_name'],
        'ts'        => null,
        'elapsed'   => null,
        'severity'  => 'dim',
        'snap_a'    => null, 'snap_b' => null,
        'notes'     => null,
    ];
}

foreach ($claims as $c) {
    $rank = $c['status'] === 'verified' ? 4 : 5;
    $rows[] = [
        'rank'      => $rank,
        'type'      => 'claim',
        'sub_id'    => (int)$c['claim_id'],
        'icon'      => 'fa-inbox',
        'type_label'=> $c['status'] === 'verified' ? 'Verified Claim' : 'Claim Request',
        'type_sub'  => 'Lost & Found',
        'subject'   => $c['claimant_name'],
        'subject_sub' => ($c['item_type'] ?: 'Item') . ' — ' . ($c['found_location'] ?: 'Claiming Station'),
        'room'      => $c['room_id'] ?: 'DESK',
        'room_name' => null,
        'ts'        => $c['submitted_at'],
        'elapsed'   => (int)$c['elapsed_minutes'],
        'severity'  => $c['status'] === 'verified' ? 'info' : 'warn',
        'snap_a'    => $c['item_snapshot'] ? SNAPSHOT_URL . basename($c['item_snapshot']) : null,
        'snap_b'    => null,
        'notes'     => $c['item_description'],
    ];
}

usort($rows, function ($a, $b) {
    if ($a['rank'] !== $b['rank']) return $a['rank'] <=> $b['rank'];
    return ($a['ts'] ?? '9999') <=> ($b['ts'] ?? '9999');
});

function al_elapsed_label(?int $mins): string {
    if ($mins === null) return '—';
    if ($mins < 60)  return $mins . 'm ago';
    $h = intdiv($mins, 60); $m = $mins % 60;
    return $h . 'h ' . $m . 'm ago';
}
function al_elapsed_class(string $severity): string {
    return match ($severity) {
        'alert' => 'el-alert',
        'warn'  => 'el-warn',
        'info'  => 'el-info',
        default => 'el-dim',
    };
}
// Safe for embedding inside single- or double-quoted HTML attributes —
// escapes quotes/tags so claimant names or descriptions can't break onclick="".
function al_js($value): string {
    return json_encode($value, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP);
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Alerts — S.P.O.T.-IT</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/css/notifications.css"/>
  <link rel="stylesheet" href="../assets/css/alerts.css"/>
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
        <span class="topbar-title">Alerts</span>
        <span class="topbar-sub"> — Live Triage Queue</span>
      </div>
      <div class="live-pill"><div class="live-dot"></div>LIVE</div>
      <div class="topbar-right">
        <span style="font-family:var(--font-mono);font-size:.7rem;color:var(--text-dim);" id="liveClock"></span>
        <button class="tb-btn" onclick="location.reload()" title="Refresh"><i class="fa-solid fa-rotate-right"></i></button>
        <button class="tb-btn" onclick="toggleTheme()" title="Theme"><i class="fa-solid fa-circle-half-stroke"></i></button>
        <button class="tb-btn notif-bell-wrap" onclick="toggleNotifPanel()" title="Notifications" style="position:relative;">
          <i class="fa-solid fa-bell"></i>
          <div class="notif-bell-dot" id="notifDot"></div>
        </button>
      </div>
    </div>

    <!-- ══════════ PAGE BODY ══════════ -->
    <div class="page-body">

      <!-- Stat cards -->
      <div class="stat-grid">
        <div class="stat-card">
          <div class="stat-icon alert"><i class="fa-solid fa-bell-ring"></i></div>
          <div><div class="stat-num"><?= $totalAlerts ?></div><div class="stat-label">Total Alerts</div>
            <div class="stat-delta <?= $totalAlerts > 0 ? 'up' : 'flat' ?>"><?= $totalAlerts > 0 ? 'Needs attention' : 'All clear' ?></div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon warn"><i class="fa-solid fa-magnifying-glass"></i></div>
          <div><div class="stat-num"><?= $cctvCount ?></div><div class="stat-label">CCTV Deviations</div>
            <div class="stat-delta flat">Pending &amp; potential</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon alert"><i class="fa-solid fa-triangle-exclamation"></i></div>
          <div><div class="stat-num"><?= $missingCount ?></div><div class="stat-label">Confirmed Missing</div>
            <div class="stat-delta <?= $missingCount > 0 ? 'up' : 'flat' ?>"><?= $missingCount > 0 ? 'Action required' : 'None' ?></div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon info"><i class="fa-solid fa-inbox"></i></div>
          <div><div class="stat-num"><?= $claimsCount ?></div><div class="stat-label">Pending Claims</div>
            <div class="stat-delta flat">At claiming station</div>
          </div>
        </div>
      </div>

      <!-- Search + filter controls -->
      <div class="al-controls">
        <div class="al-search-wrap">
          <i class="fa-solid fa-magnifying-glass"></i>
          <input type="text" id="alertSearch" class="al-search"
                 placeholder="Search by room, item, or claimant…" oninput="alFilter()"/>
        </div>
        <div class="al-last-updated">
          <div class="al-live-dot"></div>
          Last refreshed: <span id="alLastRefreshed"><?= date('h:i:s A') ?></span>
        </div>
      </div>

      <div class="filter-tabs" style="margin-bottom:14px;">
        <div class="filter-tab active" data-scope="all" onclick="alSetScope(this)">All (<?= count($rows) ?>)</div>
        <div class="filter-tab" data-scope="cctv" onclick="alSetScope(this)">CCTV Alerts (<?= $cctvCount + $missingCount ?>)</div>
        <div class="filter-tab" data-scope="offline" onclick="alSetScope(this)">Offline (<?= $offlineCount ?>)</div>
        <div class="filter-tab" data-scope="claim" onclick="alSetScope(this)">Claim Requests (<?= $claimsCount ?>)</div>
      </div>

      <!-- Triage table -->
      <div class="card" id="tourAlertsTable">
        <div class="card-head">
          <div class="card-title"><i class="fa-solid fa-list-check"></i> Live Alert Triage — Sorted by Priority</div>
        </div>

        <?php if (empty($rows)): ?>
          <div class="al-empty">
            <i class="fa-solid fa-circle-check"></i>
            <h4>All caught up!</h4>
            <p>No active CCTV deviations, offline cameras, or pending claims right now.</p>
          </div>
        <?php else: ?>
        <div class="al-table-wrap">
          <table class="al-table">
            <thead>
              <tr>
                <th>Alert Type</th>
                <th>Subject / Item</th>
                <th>Room / Location</th>
                <th>Detected / Elapsed</th>
                <th>Severity</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="alertTableBody">
              <?php foreach ($rows as $row):
                $searchBlob = strtolower($row['room'] . ' ' . ($row['room_name'] ?? '') . ' ' . $row['subject'] . ' ' . $row['subject_sub']);
              ?>
              <tr class="al-row" data-type="<?= $row['type'] ?>" data-search="<?= htmlspecialchars($searchBlob) ?>">
                <td>
                  <div class="al-type-cell">
                    <div class="al-type-icon tp-<?= $row['type'] ?>"><i class="fa-solid <?= $row['icon'] ?>"></i></div>
                    <div>
                      <div class="al-type-label"><?= htmlspecialchars($row['type_label']) ?></div>
                      <div class="al-type-sub"><?= htmlspecialchars($row['type_sub']) ?></div>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="al-subject"><?= htmlspecialchars($row['subject']) ?></div>
                  <div class="al-subject-sub"><?= htmlspecialchars($row['subject_sub']) ?></div>
                </td>
                <td><span class="al-room-badge"><?= htmlspecialchars($row['room']) ?></span></td>
                <td>
                  <div class="al-time-wrap">
                    <span class="al-time-ts"><?= $row['ts'] ? date('H:i:s · M j', strtotime($row['ts'])) : '—' ?></span>
                    <span class="al-elapsed <?= al_elapsed_class($row['severity']) ?>"><?= al_elapsed_label($row['elapsed']) ?></span>
                  </div>
                </td>
                <td>
                  <span class="badge badge-<?= $row['severity'] === 'dim' ? 'muted' : $row['severity'] ?>">
                    <span class="bdot"></span><?= strtoupper($row['type'] === 'cctv' ? ($row['severity'] === 'alert' ? 'high' : ($row['severity'] === 'warn' ? 'med' : 'low')) : ($row['type'] === 'offline' ? 'offline' : ($row['severity'] === 'info' ? 'verified' : 'pending'))) ?>
                  </span>
                </td>
                <td>
                  <div class="al-actions">
                  <?php if ($row['type'] === 'cctv'): ?>
                    <button class="btn btn-primary btn-sm"
                      onclick='alOpenVerify(<?= (int)$row['sub_id'] ?>, <?= al_js($row['room']) ?>, <?= al_js($row['subject_sub']) ?>, <?= al_js($row['subject']) ?>)'>
                      <i class="fa-solid fa-eye"></i> Verify Event
                    </button>
                    <?php if ($row['snap_a'] || $row['snap_b']): ?>
                    <button class="btn btn-sm"
                      onclick='alOpenSnapshot(<?= al_js($row['snap_a']) ?>, <?= al_js($row['snap_b']) ?>, <?= al_js($row['subject'] . ' — ' . $row['room']) ?>)'>
                      <i class="fa-solid fa-camera"></i> View Snapshot
                    </button>
                    <?php endif; ?>
                  <?php elseif ($row['type'] === 'claim'): ?>
                    <a href="claiming-station.php" class="btn btn-primary btn-sm">
                      <i class="fa-solid fa-hand-holding"></i> Process Handoff
                    </a>
                  <?php else: ?>
                    <a href="room-monitor.php" class="btn btn-sm">
                      <i class="fa-solid fa-video"></i> Room Monitor
                    </a>
                  <?php endif; ?>
                  </div>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

    </div>
  </div>
</div>

<!-- ══════ VERIFY EVENT MODAL ══════ -->
<div class="modal-overlay" id="verifyModal" onclick="if(event.target===this)closeModal('verifyModal')">
  <div class="modal-box">
    <div class="modal-head">
      <div class="modal-title" id="verifyModalTitle">Verify Detection Event</div>
      <div class="modal-close" onclick="closeModal('verifyModal')"><i class="fa-solid fa-xmark"></i></div>
    </div>
    <div class="modal-body">
      <div style="padding:14px;background:var(--bg-base);border-radius:9px;border:1px solid var(--border);margin-bottom:16px;font-size:.84rem;color:var(--text-muted);line-height:1.6;">
        <strong style="color:var(--text-primary);" id="verifyModalRoomZone">—</strong><br/>
        Before marking this event, physically verify the room and confirm:
        <ul style="margin-top:.5rem;padding-left:1.2rem;display:flex;flex-direction:column;gap:4px;">
          <li>The item is not present in the registered ROI zone</li>
          <li>The item is not temporarily moved within the tolerance zone</li>
          <li>Room activity / class is not causing the false reading</li>
          <li>The CCTV feed is not obstructed</li>
        </ul>
      </div>
      <div class="form-group">
        <label class="form-label">Staff Notes / Observation</label>
        <textarea class="form-control" id="verifyNotes" rows="3" placeholder="Describe what you found upon physical inspection…"></textarea>
      </div>
      <div class="modal-actions">
        <button class="modal-btn dismiss" onclick="closeModal('verifyModal')">Cancel</button>
        <button class="modal-btn dismiss" onclick="alStaffAction('dismissed')"><i class="fa-solid fa-ban"></i> False Alarm</button>
        <button class="modal-btn recover" onclick="alStaffAction('recovered')"><i class="fa-solid fa-circle-check"></i> Mark Recovered</button>
        <button class="modal-btn confirm" onclick="alStaffAction('confirmed_missing')"><i class="fa-solid fa-triangle-exclamation"></i> Confirm Missing</button>
      </div>
    </div>
  </div>
</div>

<!-- ══════ SNAPSHOT MODAL ══════ -->
<div class="modal-overlay" id="snapshotModal" onclick="if(event.target===this)closeModal('snapshotModal')">
  <div class="modal-box" style="max-width:640px;">
    <div class="modal-head">
      <div class="modal-title" id="snapModalTitle">CCTV Snapshot Evidence</div>
      <div class="modal-close" onclick="closeModal('snapshotModal')"><i class="fa-solid fa-xmark"></i></div>
    </div>
    <div class="modal-body">
      <div class="al-snap-grid">
        <div class="al-snap-box" id="snapBoxA">
          <div class="al-snap-empty"><i class="fa-solid fa-image"></i>No snapshot available</div>
          <div class="al-snap-label">Snapshot A — Baseline / Detection</div>
        </div>
        <div class="al-snap-box" id="snapBoxB">
          <div class="al-snap-empty"><i class="fa-solid fa-image"></i>No snapshot available</div>
          <div class="al-snap-label">Snapshot B — Interaction / Removal</div>
        </div>
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

/* -- Notification panel (matches current dashboard-admin.php convention) -- */
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

/* ══════════════════════════════════════
   SEARCH + FILTER PILLS
══════════════════════════════════════ */
let alScope = 'all';
function alSetScope(btn) {
  document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
  btn.classList.add('active');
  alScope = btn.dataset.scope;
  alFilter();
}
function alFilter() {
  const q = document.getElementById('alertSearch').value.trim().toLowerCase();
  document.querySelectorAll('#alertTableBody .al-row').forEach(row => {
    const matchesScope = (alScope === 'all') || (row.dataset.type === alScope);
    const matchesSearch = !q || row.dataset.search.includes(q);
    row.classList.toggle('al-hidden', !(matchesScope && matchesSearch));
  });
}

/* ══════════════════════════════════════
   VERIFY EVENT MODAL
══════════════════════════════════════ */
let alActiveDetectionId = null;
function alOpenVerify(detectionId, room, zone, itemName) {
  alActiveDetectionId = detectionId;
  document.getElementById('verifyModalTitle').textContent = `Verify Detection Event — ${itemName}`;
  document.getElementById('verifyModalRoomZone').textContent = `${room} — ${zone}`;
  document.getElementById('verifyNotes').value = '';
  openModal('verifyModal');
}
async function alStaffAction(status) {
  if (!alActiveDetectionId) return;
  const notes = document.getElementById('verifyNotes').value.trim();
  const result = await spotitFetch('../auth/update_event_status.php', {
    method: 'POST',
    body: new URLSearchParams({ detection_id: alActiveDetectionId, status, notes }),
  });
  const messages = {
    dismissed:         ['warn',    'Event dismissed as false alarm.'],
    recovered:         ['success', 'Item marked as recovered.'],
    confirmed_missing: ['error',   'Escalated — confirmed missing.'],
  };
  const [toastType, toastMsg] = messages[status] || ['info', 'Status updated.'];
  if (result && result.success) {
    showToast(toastType, toastMsg);
    closeModal('verifyModal');
    setTimeout(() => location.reload(), 900);
  } else {
    showToast('error', (result && result.message) || 'Failed to update event status.');
  }
}

/* ══════════════════════════════════════
   SNAPSHOT MODAL
══════════════════════════════════════ */
function alOpenSnapshot(urlA, urlB, title) {
  document.getElementById('snapModalTitle').textContent = `CCTV Snapshot — ${title}`;
  _alFillSnapBox('snapBoxA', urlA, 'Snapshot A — Baseline / Detection');
  _alFillSnapBox('snapBoxB', urlB, 'Snapshot B — Interaction / Removal');
  openModal('snapshotModal');
}
function _alFillSnapBox(boxId, url, label) {
  const box = document.getElementById(boxId);
  box.innerHTML = url
    ? `<img src="${url}" alt="${label}" onerror="this.parentElement.innerHTML='<div class=al-snap-empty><i class=\\'fa-solid fa-triangle-exclamation\\'></i>Failed to load</div><div class=al-snap-label>${label}</div>'"/><div class="al-snap-label">${label}</div>`
    : `<div class="al-snap-empty"><i class="fa-solid fa-image"></i>No snapshot available</div><div class="al-snap-label">${label}</div>`;
}
</script>
</body>
</html>
