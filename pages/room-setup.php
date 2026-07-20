<?php
/**
 * S.P.O.T.-IT — Room Setup
 * pages/room-setup.php
 */
require_once __DIR__ . '/../auth/service_bootstrap.php';
$active_page = 'setup'; // fixed to match _sidebar.php check '$active===\'setup\''
$user_role   = $_SESSION['user_role'] ?? 'student';

// Fetch rooms dynamically (including DESK room for admin control)
$roomsStmt = $monitorPdo->query("SELECT room_id, room_name, floor, room_type, camera_count, baseline_count, monitoring_status, last_calibrated FROM rooms WHERE is_active = 1 ORDER BY floor, room_id");
$roomsList = $roomsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Room Setup — S.P.O.T.-IT</title>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/css/notifications.css"/>
  <link rel="stylesheet" href="../assets/css/skeleton.css"/>
  <script>(function(){document.documentElement.setAttribute('data-theme',localStorage.getItem('spotit_theme')||'light')})();</script>
</head>
<body data-skeleton="dashboard">
<script src="../assets/js/skeleton.js"></script>
<div class="app-shell">
  <?php include '_sidebar.php'; ?>
  <div class="main-content">
    <div class="topbar">
      <button class="tb-btn tb-hamburger" id="hamburgerBtn" onclick="toggleSidebar()">
        <i class="fa-solid fa-bars"></i>
      </button>
      <div>
        <span class="topbar-title">Room Setup</span>
      </div>
      <div class="topbar-right">
        <span style="font-family:var(--font-mono);font-size:.7rem;color:var(--text-dim);" id="liveClock"></span>
        <button class="tb-btn notif-bell-wrap" onclick="toggleNotifPanel()" title="Notifications">
          <i class="fa-solid fa-bell"></i>
          <div class="notif-bell-dot" id="notifDot"></div>
        </button>
        <button class="tb-btn" onclick="toggleTheme()"><i class="fa-solid fa-circle-half-stroke"></i></button>
      </div>
    </div>
    <div class="page-body">

      <!-- Page header -->
      <div style="display:flex;align-items:center;gap:14px;margin-bottom:22px;">
        <div style="width:48px;height:48px;border-radius:12px;background:var(--green-pale);
                    color:var(--green-main);display:flex;align-items:center;justify-content:center;font-size:1.1rem;">
          <i class="fa-solid fa-door-closed"></i>
        </div>
        <div>
          <div style="font-family:var(--font-display);font-size:1.05rem;font-weight:800;color:var(--text-primary);">Room Setup</div>
          <div style="font-size:.78rem;color:var(--text-muted);font-weight:300;">CEAT Building · S.P.O.T.-IT</div>
        </div>
      </div>

      
      <div class="card">
        <div class="card-head">
          <div class="card-title"><i class="fa-solid fa-door-closed"></i> Monitored Rooms Configuration</div>
          <button class="btn btn-primary btn-sm" onclick="openModal('addRoomModal')"><i class="fa-solid fa-plus"></i> Add Room</button>
        </div>
        <table class="data-table">
          <thead><tr><th>Room ID</th><th>Room Name</th><th>Floor</th><th>Type</th><th>Cameras</th><th>Baseline</th><th>Status</th><th>Last Calibrated</th><th>Actions</th></tr></thead>
          <tbody>
            <?php if (empty($roomsList)): ?>
            <tr>
              <td colspan="9" class="text-center py-3" style="color:var(--text-dim);">No monitored rooms configured.</td>
            </tr>
            <?php else: ?>
            <?php foreach ($roomsList as $r):
                $status = $r['monitoring_status'] === 'active' ? 'active' : 'offline';
                $cal = $r['last_calibrated'] ? date('M j, Y', strtotime($r['last_calibrated'])) : '—';
            ?>
            <tr>
              <td><span style="font-family:var(--font-mono);font-weight:800;"><?= htmlspecialchars($r['room_id']) ?></span></td>
              <td><?= htmlspecialchars($r['room_name']) ?></td>
              <td><?= htmlspecialchars($r['floor']) ?></td>
              <td><?= htmlspecialchars($r['room_type']) ?></td>
              <td style="text-align:center;"><?= (int)$r['camera_count'] ?></td>
              <td style="text-align:center;font-family:var(--font-mono);"><?= (int)$r['baseline_count'] ?></td>
              <td><span class="badge <?= $status==='active'?'badge-ok':'badge-alert' ?>"><?= ucfirst($status) ?></span></td>
              <td style="font-size:.72rem;color:var(--text-dim);font-family:var(--font-mono);"><?= $cal ?></td>
              <td>
                <button class="btn btn-sm" onclick="showToast('info','Edit room configuration.')" title="Edit"><i class="fa-solid fa-pencil"></i></button>
                <button class="btn btn-sm btn-alert" onclick="deleteRoom('<?= htmlspecialchars($r['room_id']) ?>')" title="Delete"><i class="fa-solid fa-trash"></i></button>
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

<!-- ══════════ ADD ROOM MODAL ══════════ -->
<div class="modal-overlay" id="addRoomModal" onclick="if(event.target===this)closeModal('addRoomModal')">
  <div class="modal-box" style="max-width:500px;">
    <div class="modal-head">
      <div class="modal-title"><i class="fa-solid fa-plus"></i> Add New Room</div>
      <div class="modal-close" onclick="closeModal('addRoomModal')"><i class="fa-solid fa-xmark"></i></div>
    </div>
    <div class="modal-body">
      <div class="form-group mb-3">
        <label class="form-label">Room ID / Code *</label>
        <input type="text" class="form-control text-uppercase" id="roomAddId" placeholder="e.g. MLH306" required />
      </div>
      <div class="form-group mb-3">
        <label class="form-label">Room Name *</label>
        <input type="text" class="form-control" id="roomAddName" placeholder="e.g. Systems & Application Lab" required />
      </div>
      <div class="form-group mb-3">
        <label class="form-label">Floor *</label>
        <input type="text" class="form-control" id="roomAddFloor" placeholder="e.g. 3rd Floor MLH" required />
      </div>
      <div class="form-group mb-3">
        <label class="form-label">Room Type *</label>
        <input type="text" class="form-control" id="roomAddType" placeholder="e.g. Computer Lab" required />
      </div>
      <div class="form-group mb-3">
        <label class="form-label">Camera Count *</label>
        <input type="number" class="form-control" id="roomAddCameras" value="2" min="1" max="10" required />
      </div>
      <div class="modal-actions mt-4">
        <button class="modal-btn dismiss" onclick="closeModal('addRoomModal')"><i class="fa-solid fa-xmark"></i> Cancel</button>
        <button class="modal-btn recover" onclick="submitAddRoom()"><i class="fa-solid fa-plus"></i> Add Room</button>
      </div>
    </div>
  </div>
</div>

<!-- Notification Panel -->
<div class="notif-panel" id="notifPanel">
  <div class="notif-panel-head">
    <div class="notif-panel-title"><i class="fa-solid fa-bell"></i> Notifications
      <span class="notif-count-badge" id="notifCount">0</span></div>
    <div class="notif-panel-actions">
      <button class="btn btn-sm" onclick="markAllNotifsRead()" style="font-size:.66rem;"><i class="fa-solid fa-check-double"></i> All read</button>
      <a href="notifications.php" class="btn btn-sm" style="font-size:.66rem;"><i class="fa-solid fa-expand"></i> View all</a>
      <button class="tb-btn" onclick="toggleNotifPanel()" style="width:28px;height:28px;"><i class="fa-solid fa-xmark"></i></button>
    </div>
  </div>
  <div class="notif-panel-body" id="notifList">
    <div class="notif-empty" id="notifEmpty"><i class="fa-solid fa-bell-slash"></i><h4>All caught up!</h4></div>
  </div>
  <div class="notif-panel-foot">
    <a href="notifications.php" style="font-family:var(--font-display);font-size:.7rem;font-weight:700;color:var(--green-main);text-decoration:none;margin-left:auto;">View full history <i class="fa-solid fa-arrow-right" style="font-size:.6rem;"></i></a>
  </div>
</div>
<div class="notif-backdrop" id="notifBackdrop" onclick="toggleNotifPanel()"></div>
<div class="toast-stack" id="toastStack"></div>
<script src="../assets/js/spotit.js"></script>
<script>
startLiveClock('liveClock');
function toggleNotifPanel() {
  const p = document.getElementById('notifPanel');
  const b = document.getElementById('notifBackdrop');
  window._notifPanelOpen = !window._notifPanelOpen;
  p.classList.toggle('open', window._notifPanelOpen);
  b.classList.toggle('open', window._notifPanelOpen);
  if (window._notifPanelOpen) loadNotifPanel();
}

async function submitAddRoom() {
  const roomId = document.getElementById('roomAddId').value.trim();
  const roomName = document.getElementById('roomAddName').value.trim();
  const floor = document.getElementById('roomAddFloor').value.trim();
  const roomType = document.getElementById('roomAddType').value.trim();
  const cameraCount = document.getElementById('roomAddCameras').value.trim();

  if (!roomId || !roomName || !floor || !roomType || !cameraCount) {
    showToast('error', 'Please fill in all fields.');
    return;
  }

  const btn = document.querySelector('#addRoomModal .modal-btn.recover');
  if (btn) {
    btn.disabled = true;
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Adding...';
  }

  const fd = new FormData();
  fd.append('room_id', roomId);
  fd.append('room_name', roomName);
  fd.append('floor', floor);
  fd.append('room_type', roomType);
  fd.append('camera_count', cameraCount);

  const data = await spotitFetch('../auth/add_room.php', { method: 'POST', body: fd });
  
  if (btn) {
    btn.disabled = false;
    btn.innerHTML = '<i class="fa-solid fa-plus"></i> Add Room';
  }

  if (data && data.success) {
    showToast('success', data.message || 'Room added successfully.');
    closeModal('addRoomModal');
    // Clear inputs
    ['roomAddId', 'roomAddName', 'roomAddFloor', 'roomAddType'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('roomAddCameras').value = '2';
    // Reload page to update list
    setTimeout(() => location.reload(), 1000);
  } else {
    showToast('error', data?.message || 'Failed to add room.');
  }
}

async function deleteRoom(roomId) {
  if (!confirm(`Are you sure you want to delete room "${roomId}"? This will delete all its registered inspection items, logs, and detections. This action cannot be undone.`)) {
    return;
  }

  const fd = new FormData();
  fd.append('room_id', roomId);

  const data = await spotitFetch('../auth/delete_room.php', { method: 'POST', body: fd });

  if (data && data.success) {
    showToast('success', data.message || 'Room deleted successfully.');
    setTimeout(() => location.reload(), 1000);
  } else {
    showToast('error', data?.message || 'Failed to delete room.');
  }
}
</script>
</body>
</html>

