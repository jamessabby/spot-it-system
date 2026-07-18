<?php
/**
 * S.P.O.T.-IT — Camera Setup (Production Level)
 * pages/camera-setup.php
 *
 * Dedicated configuration interface to set up active monitoring ROIs
 * and capture baseline frames for production CCTV laboratory cameras.
 */
require_once __DIR__ . '/../auth/service_bootstrap.php';
ms_require_role('admin', 'login.php');

$active_page = 'camerasetup';
$user_role   = 'admin';

// 1. Fetch active production rooms (excluding DESK sandbox)
$roomsStmt = $monitorPdo->query("SELECT room_id, room_name, floor, room_type, baseline_image, last_calibrated FROM rooms WHERE is_active = 1 AND room_id != 'DESK' ORDER BY room_id");
$prodRooms = $roomsStmt->fetchAll();

// 2. Select default room
$selectedRoomId = trim($_GET['room'] ?? '');
if ($selectedRoomId === '' && !empty($prodRooms)) {
    $selectedRoomId = $prodRooms[0]['room_id'];
}

$selectedRoom = null;
foreach ($prodRooms as $r) {
    if ($r['room_id'] === $selectedRoomId) {
        $selectedRoom = $r;
        break;
    }
}

// 3. Fetch registered ROIs for selected room
$roisList = [];
if ($selectedRoomId !== '') {
    try {
        $roisStmt = $monitorPdo->prepare("SELECT roi_label, tier, bounding_box FROM registered_lab_items WHERE room_id = ?");
        $roisStmt->execute([$selectedRoomId]);
        $rows = $roisStmt->fetchAll();
        foreach ($rows as $row) {
            $bbox = json_decode($row['bounding_box'], true) ?: ['x'=>0,'y'=>0,'w'=>0,'h'=>0];
            $roisList[] = [
                'label' => $row['roi_label'],
                'tier'  => $row['tier'],
                'x'     => $bbox['x'] ?? 0,
                'y'     => $bbox['y'] ?? 0,
                'w'     => $bbox['w'] ?? 0,
                'h'     => $bbox['h'] ?? 0
            ];
        }
    } catch (PDOException $e) {
        // fallback
    }
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Production Camera Setup — S.P.O.T.-IT</title>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/css/skeleton.css"/>
  <style>
    .setup-grid {
      display: grid;
      grid-template-columns: 1fr 350px;
      gap: 20px;
      margin-top: 20px;
      align-items: start;
    }
    @media (max-width: 992px) {
      .setup-grid { grid-template-columns: 1fr; }
    }
    .baseline-preview-container {
      position: relative;
      background: #0d0e12;
      border-radius: var(--radius);
      border: 1px solid var(--border);
      overflow: hidden;
      display: flex;
      justify-content: center;
      align-items: center;
    }
    .baseline-preview-canvas {
      position: absolute;
      top: 0; left: 0;
      width: 100%; height: 100%;
      pointer-events: none;
      z-index: 10;
    }
    .editor-canvas-wrap {
      position: relative;
      display: inline-block;
      cursor: crosshair;
      user-select: none;
      background: #111;
      border: 1px solid var(--border);
      border-radius: 6px;
    }
    .roi-tag {
      font-size: .65rem;
      padding: 2px 6px;
      border-radius: 4px;
      font-weight: 700;
      text-transform: uppercase;
      font-family: var(--font-mono);
    }
  </style>
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
        <span class="topbar-title">Production Camera Setup</span>
        <span class="topbar-sub"> — CCTV Calibration Panel</span>
      </div>
      <div class="topbar-right">
        <span style="font-family:var(--font-mono);font-size:.7rem;color:var(--text-dim);" id="liveClock"></span>
        <button class="tb-btn" onclick="toggleTheme()"><i class="fa-solid fa-circle-half-stroke"></i></button>
      </div>
    </div>

    <div class="page-body">
      <!-- Top selector bar -->
      <div class="card mb-3" style="padding: 16px;">
        <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:16px;">
          <div style="display:flex; align-items:center; gap:12px;">
            <label style="font-weight:700; font-size:.84rem; color:var(--text-primary);">Selected Room:</label>
            <select class="form-control" style="width: 220px; font-weight:700;" onchange="switchRoom(this.value)">
              <?php foreach ($prodRooms as $r): ?>
                <option value="<?= htmlspecialchars($r['room_id']) ?>" <?= $selectedRoomId === $r['room_id'] ? 'selected' : '' ?>>
                  <?= htmlspecialchars($r['room_id']) ?> — <?= htmlspecialchars($r['room_name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
            <a href="room-setup.php" class="btn btn-sm" style="background:var(--bg-base); border:1px solid var(--border); color:var(--text-primary); font-size:.72rem; display:inline-flex; align-items:center; gap:6px; padding:6px 10px;" title="Go to Room Setup to add or configure rooms">
              <i class="fa-solid fa-plus"></i> Add/Manage Rooms
            </a>
          </div>
          <div>
            <span class="badge badge-ok"><i class="fa-solid fa-circle-check"></i> Production Level Live Mode</span>
          </div>
        </div>
      </div>

      <?php if (!$selectedRoom): ?>
        <div class="card py-5 text-center">
          <p style="color:var(--text-dim);">No active production rooms configured in Room Setup.</p>
        </div>
      <?php else: 
        $baselineImg = $selectedRoom['baseline_image'] ? '../' . $selectedRoom['baseline_image'] : null;
        $calTimestamp = $selectedRoom['last_calibrated'] ? date('M j, Y - H:i:s', strtotime($selectedRoom['last_calibrated'])) : 'Never Calibrated';
      ?>
      <div class="setup-grid">
        <!-- Main Calibration Feed Column -->
        <div>
          <div class="card">
            <div class="card-head" style="display:flex; justify-content:space-between; align-items:center;">
              <div class="card-title"><i class="fa-solid fa-video"></i> Baseline Calibration Frame</div>
              <div style="display:flex; gap:8px;">
                <button class="btn btn-sm" onclick="recaptureBaseline()" style="background:var(--bg-base); border:1px solid var(--border); color:var(--text-primary);">
                  <i class="fa-solid fa-arrows-rotate"></i> Recapture Baseline
                </button>
                <button class="btn btn-primary btn-sm" onclick="openRoiEditor()">
                  <i class="fa-solid fa-pen-ruler"></i> Setup ROI Zones
                </button>
              </div>
            </div>
            <div style="padding:16px;">
              <div class="baseline-preview-container" id="previewContainer" style="aspect-ratio: 16/9; max-width: 100%;">
                <?php if ($baselineImg): ?>
                  <img src="<?= htmlspecialchars($baselineImg) ?>?t=<?= time() ?>" id="baselineImg" style="width:100%; height:100%; object-fit:contain;" onload="initPreviewCanvas()"/>
                  <canvas id="previewCanvas" class="baseline-preview-canvas"></canvas>
                <?php else: ?>
                  <div class="text-center py-5" style="color:var(--text-dim);">
                    <i class="fa-solid fa-image-portrait" style="font-size:2.5rem; margin-bottom:12px; opacity:0.5;"></i>
                    <div>No baseline frame captured yet.</div>
                    <button class="btn btn-primary btn-sm mt-3" onclick="recaptureBaseline()">
                      <i class="fa-solid fa-camera"></i> Capture Baseline Frame
                    </button>
                  </div>
                <?php endif; ?>
              </div>
            </div>
          </div>
        </div>

        <!-- Sidebar Config Column -->
        <div>
          <!-- Camera Info Card -->
          <div class="card mb-3">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-circle-info"></i> Room &amp; Camera Info</div></div>
            <div style="padding:16px;">
              <div style="display:flex; flex-direction:column; gap:12px; font-size:.8rem;">
                <div>
                  <span style="color:var(--text-dim);">Room ID:</span>
                  <strong style="float:right; font-family:var(--font-mono);"><?= htmlspecialchars($selectedRoom['room_id']) ?></strong>
                </div>
                <div>
                  <span style="color:var(--text-dim);">Room Type:</span>
                  <strong style="float:right;"><?= htmlspecialchars($selectedRoom['room_type'] ?: 'Lab') ?></strong>
                </div>
                <div>
                  <span style="color:var(--text-dim);">Floor:</span>
                  <strong style="float:right;"><?= htmlspecialchars($selectedRoom['floor'] ?: 'CEAT') ?></strong>
                </div>
                <div>
                  <span style="color:var(--text-dim);">Last Calibrated:</span>
                  <strong style="float:right; font-size:.72rem; color:var(--text-dim);"><?= $calTimestamp ?></strong>
                </div>
                <hr style="margin: 4px 0; border-color:var(--border);"/>
                <div>
                  <span style="color:var(--text-dim);">Configured Targets:</span>
                  <span class="badge badge-info" style="float:right;"><?= count($roisList) ?> Item Zones</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Active ROI List Card -->
          <div class="card">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-crop"></i> Active Inspection Targets</div></div>
            <div style="padding:12px; max-height:360px; overflow-y:auto; display:flex; flex-direction:column; gap:8px;">
              <?php if (empty($roisList)): ?>
                <div class="text-center py-4" style="color:var(--text-dim); font-size:.8rem;">No targets drawn. Click "Setup ROI Zones" above.</div>
              <?php else: ?>
                <?php foreach ($roisList as $idx => $roi): 
                  $tierBadge = match($roi['tier']) {
                    'tier1' => 'background:rgba(0,123,255,0.1); color:#007bff; border:1px solid rgba(0,123,255,0.2);',
                    'tier2' => 'background:rgba(40,167,69,0.1); color:#28a745; border:1px solid rgba(40,167,69,0.2);',
                    'tier3' => 'background:rgba(255,193,7,0.1); color:#ffc107; border:1px solid rgba(255,193,7,0.2);',
                    'tier4' => 'background:rgba(108,117,125,0.1); color:#6c757d; border:1px solid rgba(108,117,125,0.2);',
                  };
                  $tierLabel = match($roi['tier']) {
                    'tier1' => 'T1 (DNN)',
                    'tier2' => 'T2 (Template)',
                    'tier3' => 'T3 (Bulk)',
                    'tier4' => 'T4 (Low)',
                  };
                ?>
                  <div style="display:flex; justify-content:space-between; align-items:center; padding:10px; border-radius:8px; border:1px solid var(--border); background:var(--bg-base);">
                    <div>
                      <div style="font-weight:800; font-size:.78rem; color:var(--text-primary);"><?= htmlspecialchars($roi['label']) ?></div>
                      <div style="font-size:.66rem; color:var(--text-muted); font-family:var(--font-mono); margin-top:2px;">x:<?= $roi['x'] ?> y:<?= $roi['y'] ?> · w:<?= $roi['w'] ?> h:<?= $roi['h'] ?></div>
                    </div>
                    <span class="roi-tag" style="<?= $tierBadge ?>"><?= $tierLabel ?></span>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<!-- ══════ ROI EDITOR MODAL ══════ -->
<div class="modal-overlay" id="roiEditorModal" onclick="if(event.target===this)closeRoiEditor()">
  <div class="modal-box" style="width: 1200px; max-width: 96vw; max-height: 95vh;">
    <div class="modal-head">
      <div class="modal-title"><i class="fa-solid fa-camera"></i> Interactive ROI Zone Editor (Production: <?= htmlspecialchars($selectedRoomId) ?>)</div>
      <div class="modal-close" onclick="closeRoiEditor()"><i class="fa-solid fa-xmark"></i></div>
    </div>
    <div style="display:flex; flex-direction:column; gap:16px; padding:18px; overflow-y:auto; height:calc(100% - 60px);">
      
      <p style="font-size:.76rem; color:var(--text-muted); margin:0;">
        Click and drag on the camera snapshot to draw your detection boxes. Give each zone a descriptive label matching the item (e.g. <code>monitor_1</code>, <code>mouse_2</code>). Assign the correct inspection tier.
      </p>

      <div style="display:grid; grid-template-columns: 1fr 340px; gap:18px; align-items:start;">
        <!-- Canvas area -->
        <div style="display:flex; justify-content:center;">
          <div class="editor-canvas-wrap" id="canvasWrap">
            <canvas id="editorCanvas" style="display:block; max-width:100%; height:auto;"></canvas>
          </div>
        </div>

        <!-- Details & list area -->
        <div style="display:flex; flex-direction:column; gap:12px; height:100%; max-height:540px;">
          <div style="font-weight:800; font-size:.8rem; color:var(--text-primary);"><i class="fa-solid fa-list"></i> Target ROI Zones List</div>
          <div id="roiList" style="display:flex; flex-direction:column; gap:10px; flex:1; overflow-y:auto; min-height:200px;">
            <!-- Rendered dynamically -->
          </div>
          
          <div style="display:flex; gap:10px; margin-top:10px;">
            <button class="btn btn-primary" style="flex:1; justify-content:center; padding:10px;" onclick="saveRoisToSystem()">
              <i class="fa-solid fa-floppy-disk"></i> Save ROI Config
            </button>
            <button class="btn" style="padding:10px; border:1px solid var(--border);" onclick="closeRoiEditor()">Cancel</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="toast-stack" id="toastStack"></div>
<script src="../assets/js/spotit.js"></script>
<script>
startLiveClock && startLiveClock('liveClock');

const ROOM_ID = '<?= htmlspecialchars($selectedRoomId) ?>';
let rois = <?= json_encode($roisList) ?>;
let editorRois = [];
let editorImg = new Image();

function switchRoom(val) {
  window.location.href = 'camera-setup.php?room=' + val;
}

async function recaptureBaseline() {
  showToast('info', 'Connecting to RTSP camera stream and capturing frame...');
  try {
    const res = await spotitFetch(`../auth/capture_frame.php?room_id=${ROOM_ID}&t=${Date.now()}`);
    if (res && res.success) {
      showToast('success', res.message || 'Baseline captured successfully!');
      setTimeout(() => location.reload(), 900);
    } else {
      showToast('error', res?.message || 'Failed to capture baseline. Make sure camera is online.');
    }
  } catch (err) {
    showToast('error', 'Network error while attempting capture.');
  }
}

// Draw ROIs on static preview baseline canvas
function initPreviewCanvas() {
  const img = document.getElementById('baselineImg');
  const canvas = document.getElementById('previewCanvas');
  if (!img || !canvas) return;

  canvas.width = img.naturalWidth;
  canvas.height = img.naturalHeight;
  
  const ctx = canvas.getContext('2d');
  ctx.clearRect(0,0,canvas.width,canvas.height);

  rois.forEach((roi) => {
    ctx.strokeStyle = '#28a745';
    ctx.lineWidth = 3;
    ctx.strokeRect(roi.x, roi.y, roi.w, roi.h);
    
    // label
    ctx.fillStyle = 'rgba(40,167,69,0.85)';
    ctx.font = 'bold 16px "Outfit", sans-serif';
    const txtWidth = ctx.measureText(roi.label).width;
    ctx.fillRect(roi.x, roi.y - 25, txtWidth + 12, 25);
    
    ctx.fillStyle = '#fff';
    ctx.fillText(roi.label, roi.x + 6, roi.y - 7);
  });
}

window.addEventListener('resize', initPreviewCanvas);

// ── WEB ROI CANVAS EDITOR ───────────────────────────────────────────────────
function openRoiEditor() {
  openModal('roiEditorModal');
  loadExistingRoisForEditor();
}

function closeRoiEditor() {
  closeModal('roiEditorModal');
}

async function loadExistingRoisForEditor() {
  const canvas = document.getElementById('editorCanvas');
  if (!canvas) return;
  const ctx = canvas.getContext('2d');

  editorImg.onload = function() {
    canvas.width = editorImg.naturalWidth;
    canvas.height = editorImg.naturalHeight;
    redrawEditorCanvas();
    setupCanvasDragging();
  };
  editorImg.src = `../uploads/snapshots/${ROOM_ID}_baseline.jpg?t=${Date.now()}`;

  try {
    const response = await fetch(`../auth/get_rois.php?room_id=${ROOM_ID}&t=${Date.now()}`);
    if (response.ok) {
      const data = await response.json();
      editorRois = data.map(r => ({
        label: r.label,
        tier: r.tier || 'tier1',
        x: r.x, y: r.y, w: r.w, h: r.h
      }));
      renderRoiList();
    }
  } catch (e) {
    console.error('Failed to load existing ROIs', e);
  }
}

let activeDrawing = false;
let startX = 0, startY = 0;
let currentX = 0, currentY = 0;

function setupCanvasDragging() {
  const canvas = document.getElementById('editorCanvas');
  if (!canvas) return;
  
  canvas.addEventListener('mousedown', (e) => {
    const rect = canvas.getBoundingClientRect();
    const baseScaleX = canvas.width / rect.width;
    const baseScaleY = canvas.height / rect.height;
    
    startX = (e.clientX - rect.left) * baseScaleX;
    startY = (e.clientY - rect.top) * baseScaleY;
    currentX = startX;
    currentY = startY;
    activeDrawing = true;
  });

  canvas.addEventListener('mousemove', (e) => {
    if (!activeDrawing) return;
    const rect = canvas.getBoundingClientRect();
    const baseScaleX = canvas.width / rect.width;
    const baseScaleY = canvas.height / rect.height;

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
    ctx.strokeRect(roi.x, roi.y, roi.w, roi.h);
    
    ctx.fillStyle = 'rgba(92,255,172,0.85)';
    ctx.font = 'bold 15px sans-serif';
    ctx.fillRect(roi.x, roi.y - 20, 110, 20);
    ctx.fillStyle = '#000';
    ctx.fillText(`#${idx+1}: ${roi.label}`, roi.x + 4, roi.y - 5);
  });

  if (drawPreview && activeDrawing) {
    ctx.strokeStyle = 'rgba(255,255,255,0.7)';
    ctx.lineWidth = 1.5;
    ctx.setLineDash([5, 5]);
    ctx.strokeRect(startX, startY, currentX - startX, currentY - startY);
    ctx.setLineDash([]);
  }
}

function renderRoiList() {
  const container = document.getElementById('roiList');
  if (!container) return;
  container.innerHTML = editorRois.map((roi, idx) => `
    <div style="display:flex; flex-direction:column; gap:6px; padding:10px; border-radius:6px; border:1px solid var(--border); background:var(--bg-base);">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-weight:800; font-size:.7rem; color:var(--text-dim);">Zone #${idx+1}</span>
        <button class="btn btn-sm" onclick="deleteEditorRoi(${idx})" style="padding:2px 6px; font-size:.64rem; color:var(--red-main); background:rgba(220,53,69,0.1); border:1px solid rgba(220,53,69,0.2);">
          <i class="fa-solid fa-trash"></i>
        </button>
      </div>
      <div style="display:grid; grid-template-columns: 1fr 120px; gap:8px;">
        <input type="text" class="form-control form-control-sm" value="${roi.label}" onchange="updateEditorRoiLabel(${idx}, this.value)" style="padding:3px 6px; font-size:.7rem; font-weight:700;"/>
        <select class="form-control form-control-sm" onchange="updateEditorRoiTier(${idx}, this.value)" style="padding:3px 6px; font-size:.7rem; height:auto;">
          <option value="tier1" ${roi.tier==='tier1'?'selected':''}>Tier 1 (DNN)</option>
          <option value="tier2" ${roi.tier==='tier2'?'selected':''}>Tier 2 (Template)</option>
          <option value="tier3" ${roi.tier==='tier3'?'selected':''}>Tier 3 (Bulk)</option>
          <option value="tier4" ${roi.tier==='tier4'?'selected':''}>Tier 4 (Low)</option>
        </select>
      </div>
      <div style="font-size:.62rem; color:var(--text-muted); font-family:var(--font-mono);">
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
        room_id: ROOM_ID,
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
</script>
</body>
</html>
