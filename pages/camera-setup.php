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
$roomsStmt = $monitorPdo->query("SELECT room_id, room_name, floor, room_type, baseline_image, last_calibrated FROM rooms WHERE is_active = 1 AND is_enabled = 1 AND room_id != 'DESK' ORDER BY room_id");
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

// Auto-sync selected room to detection_mode.json so main.py immediately switches to it
$dm_file = __DIR__ . '/../detection_mode.json';
if (file_exists($dm_file) && $selectedRoomId !== '') {
    $dm = json_decode(file_get_contents($dm_file), true) ?: [];
    if (($dm['room_id'] ?? '') !== $selectedRoomId) {
        $dm['room_id'] = $selectedRoomId;
        $dm['reset_paused'] = true;
        $dm['reset_rois'] = true;
        $dm['updated_at'] = date('Y-m-d H:i:s');
        @file_put_contents($dm_file, json_encode($dm, JSON_PRETTY_PRINT));
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
          <div class="card" id="setupFeedCard">
            <div class="card-head" style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px;">
              <div class="card-title"><i class="fa-solid fa-video"></i> Live Calibration Feed</div>
              <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                <button class="btn btn-sm" onclick="captureBaseline()" style="background:var(--bg-base); border:1px solid var(--border); color:var(--text-primary); font-size:.72rem;">
                  <i class="fa-solid fa-camera"></i> Capture Baseline
                </button>
                <button class="btn btn-primary btn-sm" onclick="openRoiEditor()" style="font-size:.72rem;">
                  <i class="fa-solid fa-pen-ruler"></i> Setup ROI Zones
                </button>
                <button class="btn btn-sm" id="btnResetSystemSetup" onclick="resetSystemSetup()" style="font-size:.72rem; background:var(--alert-bg); border:1px solid rgba(220,53,69,0.3); color:var(--alert); font-weight:700;" title="Reset ROIs and detection logs for this room">
                  <i class="fa-solid fa-rotate-left"></i> Reset System
                </button>
                <button class="btn btn-sm" onclick="clearMovementSnapshots()" style="font-size:.72rem; background:rgba(255,193,7,0.15); border:1px solid rgba(255,193,7,0.4); color:#ffc107; font-weight:700;" title="Delete generated movement snapshot images">
                  <i class="fa-solid fa-broom"></i> Clear Snapshots
                </button>
                <button class="btn btn-sm" onclick="toggleSetupFullScreen()" style="font-size:.72rem; background:var(--bg-base); border:1px solid var(--border); color:var(--text-primary);" title="Full Screen View">
                  <i class="fa-solid fa-expand"></i> Full Screen
                </button>
              </div>
            </div>
            <div style="padding:16px;">
              <!-- Live double-buffered stream container -->
              <div id="setupVideoBox" style="position:relative; width:100%; aspect-ratio:16/9; background:#000; border-radius:8px; overflow:hidden; display:flex; align-items:center; justify-content:center;">
                <!-- Canvas overlay for ROI boxes -->
                <canvas id="previewCanvas" style="position:absolute; inset:0; width:100%; height:100%; pointer-events:none; z-index:10;"></canvas>
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

      <?php
        // You are drawing on the stored baseline, which is also what main.py compares
        // every live frame against. If it is hours old the room's lighting has moved on,
        // similarity scores drop, and present items get flagged MISSING — with correctly
        // placed boxes, so it looks like the ROIs broke. Warn before that happens.
        $baselineFile = __DIR__ . "/../uploads/snapshots/{$selectedRoomId}_baseline.jpg";
        $baselineAgeMin = file_exists($baselineFile)
            ? (int) round((time() - filemtime($baselineFile)) / 60)
            : null;
      ?>
      <?php if ($baselineAgeMin === null): ?>
        <div style="font-size:.72rem; padding:9px 12px; border-radius:8px; background:rgba(220,53,69,0.1); border:1px solid rgba(220,53,69,0.25); color:#dc3545;">
          <strong>No baseline captured yet.</strong> Close this editor and click <strong>Capture Baseline</strong> first — ROIs drawn without one cannot be calibrated.
        </div>
      <?php elseif ($baselineAgeMin >= 30): ?>
        <div style="font-size:.72rem; padding:9px 12px; border-radius:8px; background:rgba(255,193,7,0.12); border:1px solid rgba(255,193,7,0.3); color:#a67c00;">
          <strong>This baseline is <?= $baselineAgeMin >= 120 ? round($baselineAgeMin/60,1).' hours' : $baselineAgeMin.' minutes' ?> old</strong>
          (captured <?= date('g:i A', filemtime($baselineFile)) ?>). The lighting has likely changed since then, which lowers similarity scores and can flag present items as MISSING.
          Close this editor, click <strong>Capture Baseline</strong>, then reopen to draw on a current frame.
        </div>
      <?php endif; ?>

      <div style="display:grid; grid-template-columns: 1fr 340px; gap:18px; align-items:start;">
        <!-- Canvas area -->
        <div style="display:flex; justify-content:center;">
          <div class="editor-canvas-wrap" id="canvasWrap">
            <canvas id="editorCanvas" style="display:block; max-width:100%; height:auto;"></canvas>
          </div>
        </div>

        <!-- Details & list area -->
        <div style="display:flex; flex-direction:column; gap:12px; height:100%; max-height:540px;">
          <!-- Automatic ROI suggestions — the primary setup workflow. Manual
               drawing (below) is for items this scan misses, not the normal
               way to register a room. Classes offered here are driven by this
               room's own configured equipment categories (auth/room_categories.php),
               never hardcoded per room — see loadSuggestClasses(). -->
          <div id="suggestPanel" style="padding:12px; border-radius:8px; border:1px solid var(--accent, #4a7dff); background:rgba(74,125,255,.06); display:flex; flex-direction:column; gap:10px;">
            <div style="display:flex; align-items:center; justify-content:space-between; gap:8px;">
              <div style="font-weight:800; font-size:.78rem; color:var(--text-primary);"><i class="fa-solid fa-wand-magic-sparkles"></i> Auto-Detect ROIs</div>
              <span style="font-size:.6rem; font-weight:800; letter-spacing:.04em; color:#4a7dff; text-transform:uppercase;">Start here</span>
            </div>
            <div style="font-size:.68rem; color:var(--text-muted); line-height:1.4;">
              Scans this room's configured equipment categories on the live camera and proposes boxes to review below. Draw boxes by hand only for items the scan misses.
            </div>
            <div id="suggestClassList" style="display:flex; flex-wrap:wrap; gap:10px; font-size:.7rem;">
              <span style="color:var(--text-muted);">Loading this room's equipment categories…</span>
            </div>
            <div id="suggestUnsupported" style="font-size:.64rem; color:var(--text-muted); display:none;"></div>
            <div style="display:flex; gap:8px;">
              <button class="btn btn-primary btn-sm" id="btnSuggest" onclick="requestRoiSuggestions()" style="flex:1; justify-content:center;"><i class="fa-solid fa-magnifying-glass"></i> Scan &amp; Suggest</button>
              <button class="btn btn-sm" onclick="discardSuggestions()" title="Remove all suggested boxes that are still unreviewed">Discard</button>
            </div>
            <div id="suggestWarning" style="display:none; font-size:.68rem; padding:8px 10px; border-radius:6px; background:rgba(255,193,7,0.12); border:1px solid rgba(255,193,7,0.3); color:#a67c00;"></div>
            <div id="suggestStatus" style="font-size:.66rem; color:var(--text-muted);">Proposals appear as dashed orange boxes on the canvas. Keep, rename, delete, or draw your own.</div>
          </div>
          <div style="display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap;">
            <div style="font-weight:800; font-size:.8rem; color:var(--text-primary);"><i class="fa-solid fa-list"></i> Target ROI Zones List</div>
            <!-- Display-only, same as Room Monitor's category filter: hides other
                 categories' zones from THIS LIST/CANVAS only. editorRois always
                 keeps every category in memory; Save always sends all of it. -->
            <select id="editorCategorySelect" onchange="selectEditorCategory(this.value)" title="Show one category at a time while editing. Saving always keeps every category — this never deletes anything hidden."
                    style="font-size:.7rem; font-weight:700; padding:4px 8px; border-radius:6px; border:1px solid var(--border); background:var(--bg-base); color:var(--text-primary);">
              <option value="All">Showing: All categories</option>
            </select>
          </div>
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
<script src="../assets/js/stream-viewer.js"></script>
<script>
startLiveClock && startLiveClock('liveClock');

const ROOM_ID = '<?= htmlspecialchars($selectedRoomId) ?>';
let rois = <?= json_encode($roisList) ?>;
let editorRois = [];
let editorImg = new Image();

// ── Category state (display-only; see editorCategorySelect above) ──────────
// editorRois ALWAYS holds every category's zones, whether or not the editor
// is currently showing them. activeEditorCategory only controls what
// renderRoiList()/redrawEditorCanvas() draw — it never removes anything from
// editorRois, and saveRoisToSystem() always sends the full array. That is
// what makes "save mice" safe without wiping previously-saved monitors.
let editorCategories = [];        // [{name, keywords}] for this room
let editorRoiCategoryMap = {};    // label -> category, resolved server-side (room_categories.php)
let activeEditorCategory = 'All';

/** Which category a zone belongs to: server-resolved first (covers explicit
 * admin assignments from the Room Monitor category editor), then the same
 * keyword-match rule room_categories.php uses for labels it has not seen yet
 * (freshly suggested or hand-drawn this session). */
function categoryOfRoi(roi) {
  if (editorRoiCategoryMap[roi.label]) return editorRoiCategoryMap[roi.label];
  const low = roi.label.toLowerCase();
  for (const cat of editorCategories) {
    if (low.includes(cat.name.toLowerCase()) || (cat.keywords || []).some(k => k && low.includes(String(k).toLowerCase()))) {
      return cat.name;
    }
  }
  return null;
}

function renderEditorCategorySelect() {
  const sel = document.getElementById('editorCategorySelect');
  if (!sel) return;
  const prev = activeEditorCategory;
  sel.innerHTML = '<option value="All">Showing: All categories</option>'
    + editorCategories.map(c => `<option value="${c.name.replace(/"/g, '&quot;')}">Showing: ${c.name}</option>`).join('');
  // Keep the admin's current focus across a reload/re-fetch when that category still exists.
  activeEditorCategory = editorCategories.some(c => c.name === prev) ? prev : 'All';
  sel.value = activeEditorCategory;
}

function selectEditorCategory(name) {
  activeEditorCategory = name;
  renderRoiList();
  redrawEditorCanvas();
  // Convenience only: focus the Auto-ROI checkboxes on this category's class.
  // The admin can still check others by hand; "All" leaves checkboxes as-is.
  if (name !== 'All') {
    const cls = suggestClassForCategory({ name, keywords: (editorCategories.find(c => c.name === name) || {}).keywords });
    document.querySelectorAll('.sugg-cls').forEach(cb => { cb.checked = !!cls && cb.value === cls; });
  }
}

function switchRoom(val) {
  window.location.href = 'camera-setup.php?room=' + val;
}

async function captureBaseline() {
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
const recaptureBaseline = captureBaseline;

// Clear preview canvas overlay so live stream handles dynamic ROI rendering without double boxes
function initPreviewCanvas() {
  const box = document.getElementById('setupVideoBox');
  const canvas = document.getElementById('previewCanvas');
  if (!box || !canvas) return;

  canvas.width = 960;
  canvas.height = 540;

  const ctx = canvas.getContext('2d');
  ctx.clearRect(0, 0, canvas.width, canvas.height);
}

function toggleSetupFullScreen() {
  const card = document.getElementById('setupFeedCard');
  if (!card) return;
  if (!document.fullscreenElement) {
    if (card.requestFullscreen) card.requestFullscreen();
    else if (card.webkitRequestFullscreen) card.webkitRequestFullscreen();
  } else {
    if (document.exitFullscreen) document.exitFullscreen();
  }
}

async function resetSystemSetup() {
  if (!confirm(`Reset system state for room ${ROOM_ID}?\n\nThis will clear ROI zones and detection logs.`)) return;
  const btn = document.getElementById('btnResetSystemSetup');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Resetting...'; }
  try {
    const res = await spotitFetch('../auth/reset_room_state.php', {
      method: 'POST',
      body: new URLSearchParams({ room_id: ROOM_ID })
    });
    if (res && res.success) {
      showToast('success', res.message || 'Room reset successfully!');
      setTimeout(() => location.reload(), 900);
    } else {
      showToast('error', res?.message || 'Reset failed.');
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-rotate-left"></i> Reset System'; }
    }
  } catch (e) {
    showToast('error', 'Network error during reset.');
    if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-rotate-left"></i> Reset System'; }
  }
}

// ── WEB ROI CANVAS EDITOR ───────────────────────────────────────────────────
function openRoiEditor() {
  openModal('roiEditorModal');
  activeEditorCategory = 'All';   // always open showing everything
  loadExistingRoisForEditor();
  loadEditorCategoryData();
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
      // While a specific category is focused, default the new box's label to
      // that category's own name so it lands in the right category (and stays
      // visible) without the admin having to rename it first.
      const stem = activeEditorCategory !== 'All'
        ? activeEditorCategory.toLowerCase().replace(/[^a-z0-9]+/g, '_').replace(/^_+|_+$/g, '') || 'zone'
        : 'zone';
      let n = 1, label = `${stem}_${n}`;
      while (editorRois.some(r => r.label === label)) label = `${stem}_${++n}`;
      const tier = detectRoiTier(label);
      editorRois.push({ label, x: rx, y: ry, w: rw, h: rh, tier: tier });
      renderRoiList();
      redrawEditorCanvas();
    }
  });
}

function detectRoiTier(label) {
  const l = label.toLowerCase();
  const tier1Keywords = ['monitor', 'keyboard', 'mouse', 'pc', 'computer', 'ups', 'laptop'];
  if (tier1Keywords.some(kw => l.includes(kw))) {
    return 'tier1';
  }
  return 'tier2';
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
    // Display-only category filter: a hidden zone is still in editorRois (and
    // still gets saved) — it just isn't drawn while a different category is
    // being focused on.
    if (activeEditorCategory !== 'All' && categoryOfRoi(roi) !== activeEditorCategory) return;
    ctx.strokeStyle = roi.suggested ? '#ff9f40' : '#5cffac';
    ctx.lineWidth = 2;
    if (roi.suggested) ctx.setLineDash([6, 4]);
    ctx.strokeRect(roi.x, roi.y, roi.w, roi.h);
    ctx.setLineDash([]);

    ctx.fillStyle = roi.suggested ? 'rgba(255,159,64,0.85)' : 'rgba(92,255,172,0.85)';
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
  // Build from (roi, realIdx) pairs over the FULL editorRois array, then filter
  // for display only — onclick handlers below must keep using realIdx so a
  // filtered view can never point its Delete/rename buttons at the wrong zone.
  const all = editorRois.map((roi, idx) => ({ roi, idx }));
  const shown = activeEditorCategory === 'All'
    ? all
    : all.filter(({ roi }) => categoryOfRoi(roi) === activeEditorCategory);
  const hiddenCount = all.length - shown.length;
  container.innerHTML = (shown.length ? shown.map(({ roi, idx }) => `
    <div style="display:flex; flex-direction:column; gap:6px; padding:10px; border-radius:6px; border:1px solid var(--border); background:var(--bg-base);">
      <div style="display:flex; justify-content:space-between; align-items:center;">
        <span style="font-weight:800; font-size:.7rem; color:var(--text-dim);">Zone #${idx+1}${roi.suggested ? ` <span style="color:#ff9f40;">· suggested (${roi.sourceClass} ${roi.score})</span>` : ''}</span>
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
  `).join('') : '<div class="text-center py-3" style="color:var(--text-dim); font-size:.78rem;">No zones in this category yet. Run Auto-ROI or draw one.</div>')
    + (hiddenCount ? `<div style="font-size:.66rem; color:var(--text-muted); padding:6px 2px;"><i class="fa-solid fa-eye-slash"></i> ${hiddenCount} zone(s) in other categories are hidden, not deleted — they will still be saved.</div>` : '');
}

function updateEditorRoiLabel(idx, val) {
  const cleanLabel = val.trim().replace(/\s+/g, '_');
  editorRois[idx].label = cleanLabel;
  // Only re-guess the tier from the label while the admin has not explicitly
  // chosen one. Previously this line ran unconditionally, so typing a label
  // containing a Tier 1 keyword ("mouse", "laptop") silently discarded the tier
  // just picked from the dropdown — the UI showed Tier 2 and saved Tier 1.
  if (!editorRois[idx].tierLocked) {
    editorRois[idx].tier = detectRoiTier(cleanLabel);
  }
  renderRoiList();
  redrawEditorCanvas();
}

function updateEditorRoiTier(idx, val) {
  editorRois[idx].tier = val;
  editorRois[idx].tierLocked = true;  // admin chose deliberately — stop auto-guessing
}

function deleteEditorRoi(idx) {
  editorRois.splice(idx, 1);
  renderRoiList();
  redrawEditorCanvas();
}

// ── Automatic ROI suggestions (experimental) ────────────────────────────────
// Asks the detector (auth/suggest_rois.php -> main.py) to run YOLO on its
// full-resolution frame. Proposals are added to editorRois marked `suggested`;
// the admin keeps / renames / deletes / redraws them and saves with the normal
// save button. Nothing is stored until Save.
let suggestPollTimer = null;

// Category -> COCO class usable for auto-suggestion. Mirrors the slice of
// yolo_detector.py's LABEL_TO_CLASSES that auth/suggest_rois.php's
// SUGGEST_CLASSES allowlist actually accepts (keyboard, mouse, tv, laptop,
// cell phone) — NOT the full belongings list main.py uses for monitoring.
// Keep these three in sync if any of them changes.
const SUGGEST_CATEGORY_CLASSES = [
  { match: ['keyboard', 'kb'], cls: 'keyboard' },
  { match: ['mouse'], cls: 'mouse' },
  { match: ['monitor', 'screen', 'display', 'tv'], cls: 'tv' },
  { match: ['laptop'], cls: 'laptop' },
  { match: ['phone'], cls: 'cell phone' },
];

function suggestClassForCategory(cat) {
  const haystacks = [cat.name, ...(cat.keywords || [])].map(s => String(s).toLowerCase());
  for (const entry of SUGGEST_CATEGORY_CLASSES) {
    if (haystacks.some(h => entry.match.some(m => h.includes(m)))) return entry.cls;
  }
  return null;
}

// Fetches THIS room's configured equipment categories (auth/room_categories.php)
// ONCE and feeds both the Auto-ROI checkboxes and the "Showing:" category
// selector above the ROI list — so a brand-new room gets a working auto-ROI
// workflow with zero code changes, and a room whose categories are
// oscilloscopes/power supplies correctly shows none, stated plainly instead
// of silently offering nothing.
let editorCategoryLoadFailed = false;

async function loadEditorCategoryData() {
  let data = null;
  try {
    data = await (await fetch(`../auth/room_categories.php?room_id=${ROOM_ID}&t=${Date.now()}`)).json();
  } catch (e) { /* handled below */ }
  editorCategoryLoadFailed = !data || !data.success;
  editorCategories = editorCategoryLoadFailed ? [] : (Array.isArray(data.categories) ? data.categories : []);
  editorRoiCategoryMap = {};
  if (!editorCategoryLoadFailed) {
    (data.rois || []).forEach(r => { if (r.category) editorRoiCategoryMap[r.label] = r.category; });
  }
  renderSuggestClassList();
  renderEditorCategorySelect();
}

function renderSuggestClassList() {
  const list = document.getElementById('suggestClassList');
  const unsupported = document.getElementById('suggestUnsupported');
  const esc = s => String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;');
  unsupported.style.display = 'none';
  if (editorCategoryLoadFailed) {
    list.innerHTML = '<span style="color:var(--red-main);">Could not load this room\'s equipment categories. Configure them from Room Monitor, or select classes manually.</span>'
      + ['keyboard','mouse','tv','laptop'].map(c => `<label><input type="checkbox" class="sugg-cls" value="${c}" ${c==='keyboard'||c==='mouse'?'checked':''}> ${c}</label>`).join('');
    return;
  }
  const seen = new Set();
  const boxes = [];
  const skipped = [];
  for (const cat of editorCategories) {
    const cls = suggestClassForCategory(cat);
    if (!cls) { skipped.push(cat.name); continue; }
    if (seen.has(cls)) continue; // e.g. "Monitor" and "Screen" both map to the tv checkbox
    seen.add(cls);
    const checked = cls === 'keyboard' || cls === 'mouse' ? 'checked' : '';
    boxes.push(`<label title="Category: ${esc(cat.name)}"><input type="checkbox" class="sugg-cls" value="${cls}" ${checked}> ${esc(cat.name)}</label>`);
  }
  list.innerHTML = boxes.length ? boxes.join('')
    : '<span style="color:var(--text-muted);">None of this room\'s configured categories can be auto-detected by camera — draw all boxes manually below.</span>';
  if (skipped.length) {
    unsupported.style.display = 'block';
    unsupported.innerHTML = `<i class="fa-solid fa-circle-info"></i> Not auto-detectable by camera (no object-detection class for these): <strong>${skipped.map(esc).join(', ')}</strong>. Draw these manually.`;
  }
}

function _iou(a, b) {
  const ix = Math.max(0, Math.min(a.x + a.w, b.x + b.w) - Math.max(a.x, b.x));
  const iy = Math.max(0, Math.min(a.y + a.h, b.y + b.h) - Math.max(a.y, b.y));
  const inter = ix * iy;
  return inter / (a.w * a.h + b.w * b.h - inter || 1);
}

async function requestRoiSuggestions() {
  const classes = [...document.querySelectorAll('.sugg-cls:checked')].map(c => c.value);
  const status = document.getElementById('suggestStatus');
  if (!classes.length) { showToast('warn', 'Choose at least one equipment type.'); return; }
  const btn = document.getElementById('btnSuggest');
  btn.disabled = true;
  status.textContent = 'Asking the detector for proposals…';
  document.getElementById('suggestWarning').style.display = 'none';
  let res = null;
  try {
    const r = await fetch('../auth/suggest_rois.php', {
      method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-Token': window.SPOTIT_CSRF || '' },
      body: new URLSearchParams({ room_id: ROOM_ID, classes: classes.join(',') }),
    });
    res = await r.json().catch(() => null);
  } catch (e) { /* handled below */ }
  if (!res || !res.success) {
    btn.disabled = false;
    status.textContent = (res && res.message) || 'Could not request suggestions.';
    showToast('error', status.textContent);
    return;
  }
  const started = Date.now();
  clearInterval(suggestPollTimer);
  suggestPollTimer = setInterval(async () => {
    let g = null;
    try { g = await (await fetch(`../auth/suggest_rois.php?room_id=${ROOM_ID}&t=${Date.now()}`)).json(); } catch (e) {}
    if (g && g.result) {
      clearInterval(suggestPollTimer);
      btn.disabled = false;
      applySuggestions(g.result);
    } else if (Date.now() - started > 60000) {
      clearInterval(suggestPollTimer);
      btn.disabled = false;
      status.textContent = 'No answer from the detector within 60 s. Is main.py running for this room?';
      showToast('error', status.textContent);
    }
  }, 1500);
}

function applySuggestions(result) {
  const status = document.getElementById('suggestStatus');
  const warnBox = document.getElementById('suggestWarning');
  if (result.warning) {
    warnBox.style.display = 'block';
    warnBox.innerHTML = `<i class="fa-solid fa-triangle-exclamation"></i> ${result.warning}`;
  } else {
    warnBox.style.display = 'none';
  }
  if (result.error) { status.textContent = result.error; showToast('error', result.error); return; }
  // Resolution guard (Fix 1): boxes are in the detector frame's coordinates and
  // must match the baseline this editor draws on, or they would land elsewhere.
  if (result.frame_w !== editorImg.naturalWidth || result.frame_h !== editorImg.naturalHeight) {
    status.textContent = `Proposals were made on a ${result.frame_w}x${result.frame_h} frame, but this baseline is `
      + `${editorImg.naturalWidth}x${editorImg.naturalHeight}. Recapture the baseline, then try again.`;
    showToast('error', 'Resolution mismatch — suggestions not applied.');
    return;
  }
  const used = new Set(editorRois.map(r => r.label));
  let added = 0, skipped = 0;
  for (const s of result.suggestions) {
    if (editorRois.some(r => _iou(r, s) > 0.5)) { skipped++; continue; }   // already registered
    let label = s.label, n = 2;
    while (used.has(label)) label = `${s.label}_${n++}`;
    used.add(label);
    editorRois.push({ label, tier: s.tier || detectRoiTier(label), x: s.x, y: s.y, w: s.w, h: s.h,
                      suggested: true, sourceClass: s.class, score: s.score });
    added++;
  }
  renderRoiList();
  redrawEditorCanvas();
  status.textContent = `${added} proposal(s) added from a ${result.source_w}x${result.source_h} frame`
    + (skipped ? `, ${skipped} skipped (already registered)` : '')
    + '. Review each one, then Save. Draw any missed items manually.';
  showToast(added ? 'success' : 'warn', added ? `${added} ROI proposal(s) added for review.` : 'No new equipment found.');
}

function discardSuggestions() {
  // Scoped to what's currently shown: discarding while focused on Mouse must
  // not also wipe an earlier, still-unreviewed Monitor scan hidden by the
  // filter — that would break "keep unsaved suggestions when switching
  // categories" just as surely as a bad Save would.
  const before = editorRois.length;
  editorRois = editorRois.filter(r => {
    if (!r.suggested) return true;
    if (activeEditorCategory === 'All') return false; // discard every unreviewed suggestion
    return categoryOfRoi(r) !== activeEditorCategory;  // keep other categories' pending suggestions
  });
  renderRoiList();
  redrawEditorCanvas();
  const removed = before - editorRois.length;
  document.getElementById('suggestStatus').textContent = activeEditorCategory === 'All'
    ? `${removed} suggestion(s) discarded.`
    : `${removed} "${activeEditorCategory}" suggestion(s) discarded. Other categories' pending suggestions are untouched.`;
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

async function switchRoom(newRoomId) {
  try {
    const fd = new FormData();
    fd.append('room_id', newRoomId);
    await spotitFetch('../auth/toggle_detection_mode.php', { method: 'POST', body: fd });
    window.location.href = `camera-setup.php?room=${newRoomId}`;
  } catch (err) {
    window.location.href = `camera-setup.php?room=${newRoomId}`;
  }
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

document.addEventListener('DOMContentLoaded', () => {
  // Start the same seamless double-buffered live stream used in desk-sandbox.php
  initLiveFrameStream('setupVideoBox', ROOM_ID);
  // Draw ROI overlay on top of the live stream
  initPreviewCanvas();
});
</script>
</body>
</html>
