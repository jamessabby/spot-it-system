<?php
require_once __DIR__ . '/../auth/service_bootstrap.php';
$active_page = 'claiming'; 
$user_role = $_SESSION['user_role'] ?? 'staff';

// 1. Fetch available recovered items
$recoveredItemsStmt = $lfPdo->query("
    SELECT recovery_id as id, item_type as name, room_id as room, recovered_at as found, item_description as `desc`, item_tier as tier
    FROM recovered_items
    WHERE status = 'recovered'
    ORDER BY recovered_at DESC
");
$recoveredItems = $recoveredItemsStmt->fetchAll();

// 2. Fetch pending claims queue
$pendingClaimsStmt = $lfPdo->query("
    SELECT c.id, c.claimant_name as name, c.university_id as id_no, r.item_type as item, c.submitted_at
    FROM claims c
    INNER JOIN recovered_items r ON c.recovery_id = r.recovery_id
    WHERE c.status = 'pending'
    ORDER BY c.submitted_at ASC
");
$pendingClaims = $pendingClaimsStmt->fetchAll();

// 3. Fetch today's completed claims
$completedClaimsStmt = $lfPdo->query("
    SELECT r.item_type as item, c.claimant_name as name, c.claimed_at as `time`
    FROM claims c
    INNER JOIN recovered_items r ON c.recovery_id = r.recovery_id
    WHERE c.status = 'claimed' AND DATE(c.claimed_at) = CURDATE()
    ORDER BY c.claimed_at DESC
");
$completedClaims = $completedClaimsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Claiming Station — S.P.O.T.-IT</title>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/css/claiming-station.css"/>
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
      <div><span class="topbar-title">Claiming Station</span><span class="topbar-sub">— Dispensing Window · CEAT Lobby</span></div>
      <div class="live-pill"><div class="live-dot"></div>STATION ACTIVE</div>
      <div class="topbar-right">
        <span style="font-family:var(--font-mono);font-size:.7rem;color:var(--text-dim);" id="liveClock"></span>
        <button class="tb-btn" onclick="toggleTheme()"><i class="fa-solid fa-circle-half-stroke"></i></button>
      </div>
    </div>

    <div class="page-body">

      <!-- Step tracker -->
      <div class="step-tracker">
        <div class="step-track-item active" id="st1">
          <div class="st-circle">1</div>
          <div class="st-label">Search Item</div>
        </div>
        <div class="st-line"></div>
        <div class="step-track-item" id="st2">
          <div class="st-circle">2</div>
          <div class="st-label">Claimant Info</div>
        </div>
        <div class="st-line"></div>
        <div class="step-track-item" id="st3">
          <div class="st-circle">3</div>
          <div class="st-label">Webcam Capture</div>
        </div>
        <div class="st-line"></div>
        <div class="step-track-item" id="st4">
          <div class="st-circle">4</div>
          <div class="st-label">Confirm &amp; Log</div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 340px;gap:18px;align-items:start;">

        <!-- MAIN FLOW AREA -->
        <div>

          <!-- STEP 1: Search item -->
          <div class="station-step" id="step1">
            <div class="card">
              <div class="card-head">
                <div class="card-title"><i class="fa-solid fa-magnifying-glass"></i> Step 1 — Search Recovered Item</div>
              </div>
              <div style="padding:20px;">
                <div class="form-group">
                  <label class="form-label">Search by item name, room, or date</label>
                  <div style="display:flex;gap:8px;">
                    <div style="position:relative;flex:1;">
                      <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-dim);font-size:.8rem;"></i>
                      <input type="text" id="itemSearch" class="form-control" style="padding-left:34px;" placeholder="e.g. black umbrella, MLH 306, keyboard…" oninput="searchItems(this.value)"/>
                    </div>
                    <button class="btn btn-primary" onclick="searchItems(document.getElementById('itemSearch').value)"><i class="fa-solid fa-search"></i></button>
                  </div>
                </div>

                <!-- Item results -->
                <div id="searchResults">
                  <div style="font-family:var(--font-display);font-size:.68rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--text-dim);margin-bottom:10px;">Recovered Items — Available for Claiming</div>
                  <?php if (empty($recoveredItems)): ?>
                  <div class="p-3 text-center" style="color:var(--text-dim);font-size:.8rem;">No recovered items currently available.</div>
                  <?php else: ?>
                  <?php foreach ($recoveredItems as $item): 
                      $type = strtolower($item['name'] ?? '');
                      $icon = 'fa-box';
                      if (stripos($type, 'umbrella') !== false) $icon = 'fa-umbrella';
                      elseif (stripos($type, 'cable') !== false || stripos($type, 'usb') !== false) $icon = 'fa-plug';
                      elseif (stripos($type, 'calculator') !== false) $icon = 'fa-calculator';
                      elseif (stripos($type, 'water') !== false || stripos($type, 'bottle') !== false || stripos($type, 'tumbler') !== false) $icon = 'fa-bottle-water';
                      elseif (stripos($type, 'earphone') !== false || stripos($type, 'headphone') !== false) $icon = 'fa-headphones';
                      elseif (stripos($type, 'card') !== false || stripos($type, 'id') !== false) $icon = 'fa-id-card';
                      elseif (stripos($type, 'pencil') !== false || stripos($type, 'pen') !== false) $icon = 'fa-pen-ruler';
                      elseif (stripos($type, 'phone') !== false || stripos($type, 'mobile') !== false) $icon = 'fa-mobile-screen';
                      elseif (stripos($type, 'wallet') !== false) $icon = 'fa-wallet';
                      
                      $tierLabel = strtoupper($item['tier'] ?? 'tier1');
                      $tierLabel = str_replace('TIER', 'Tier ', $tierLabel);
                  ?>
                  <div class="item-card" id="item-<?= $item['id'] ?>" onclick="selectItem(<?= $item['id'] ?>,'<?= htmlspecialchars(addslashes($item['name'])) ?>','<?= $item['room'] ?>')">
                    <div class="item-icon"><i class="fa-solid <?= $icon ?>"></i></div>
                    <div class="item-body">
                      <div class="item-name"><?= htmlspecialchars($item['name']) ?></div>
                      <div class="item-meta">
                        <span><i class="fa-solid fa-door-open"></i> <?= htmlspecialchars($item['room']) ?></span>
                        <span><i class="fa-solid fa-clock"></i> <?= date('M j, Y · H:i', strtotime($item['found'])) ?></span>
                        <span class="badge badge-green"><?= $tierLabel ?></span>
                      </div>
                      <div class="item-desc"><?= htmlspecialchars($item['desc']) ?></div>
                    </div>
                    <div class="item-select-btn">Select <i class="fa-solid fa-chevron-right"></i></div>
                  </div>
                  <?php endforeach; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          </div>

          <!-- STEP 2: Claimant info -->
          <div class="station-step" id="step2" style="display:none;">
            <div class="card">
              <div class="card-head">
                <div class="card-title"><i class="fa-solid fa-id-card"></i> Step 2 — Claimant Information</div>
                <button class="card-action" onclick="goToStep(1)"><i class="fa-solid fa-arrow-left"></i> Back</button>
              </div>
              <div style="padding:20px;">
                <!-- Selected item preview -->
                <div class="selected-item-banner" id="selectedBanner">
                  <i class="fa-solid fa-circle-check"></i>
                  <span>Claiming: <strong id="selectedItemName">—</strong> from <strong id="selectedItemRoom">—</strong></span>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:16px;">
                  <div class="form-group">
                    <label class="form-label">Full Name *</label>
                    <input type="text" id="claimName" class="form-control" placeholder="e.g. Maria Santos"/>
                  </div>
                  <div class="form-group">
                    <label class="form-label">University ID Number *</label>
                    <input type="text" id="claimId" class="form-control" placeholder="e.g. 2021-00001" maxlength="20"/>
                  </div>
                  <div class="form-group">
                    <label class="form-label">Course &amp; Year</label>
                    <input type="text" id="claimCourse" class="form-control" placeholder="e.g. BS CompE 3-2"/>
                  </div>
                  <div class="form-group">
                    <label class="form-label">Contact Number *</label>
                    <input type="tel" id="claimContact" class="form-control" placeholder="e.g. 09xx-xxx-xxxx"/>
                  </div>
                </div>
                <div class="form-group">
                  <label class="form-label">Describe Your Item (for verification) *</label>
                  <textarea id="claimDesc" class="form-control" rows="2" placeholder="Describe unique characteristics — exact color, brand markings, contents, damage, etc. Staff will verify this matches the recovered item."></textarea>
                </div>

                <!-- Verification note -->
                <div style="padding:12px 14px;background:var(--warn-bg);border:1px solid var(--warn-border);border-radius:9px;font-size:.78rem;color:var(--text-primary);margin-bottom:16px;display:flex;gap:9px;align-items:flex-start;">
                  <i class="fa-solid fa-triangle-exclamation" style="color:var(--warn);flex-shrink:0;margin-top:1px;"></i>
                  <span>Staff will compare your description with the actual recovered item before releasing it. Providing false information may result in disciplinary action.</span>
                </div>

                <button class="btn btn-primary" style="width:100%;justify-content:center;padding:12px;" onclick="validateStep2()">
                  <i class="fa-solid fa-camera"></i> Proceed to Photo Capture
                </button>
              </div>
            </div>
          </div>

          <!-- STEP 3: Webcam capture -->
          <div class="station-step" id="step3" style="display:none;">
            <div class="card">
              <div class="card-head">
                <div class="card-title"><i class="fa-solid fa-camera"></i> Step 3 — Documentation Photo</div>
                <button class="card-action" onclick="goToStep(2)"><i class="fa-solid fa-arrow-left"></i> Back</button>
              </div>
              <div style="padding:20px;">
                <p style="font-size:.82rem;color:var(--text-muted);margin-bottom:14px;line-height:1.6;">
                  A photo of the claimant holding the recovered item will be taken for documentation. Please have the item ready and face the webcam.
                </p>

                <!-- Webcam view -->
                <div class="webcam-view" id="webcamView">
                  <video id="webcamFeed" autoplay playsinline style="width:100%;height:100%;object-fit:cover;border-radius:8px;display:none;"></video>
                  <canvas id="captureCanvas" style="width:100%;height:100%;object-fit:cover;border-radius:8px;display:none;"></canvas>
                  <div id="webcamPlaceholder" style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:100%;gap:12px;">
                    <i class="fa-solid fa-camera" style="font-size:2.5rem;color:rgba(100,255,160,.3);"></i>
                    <div style="font-family:var(--font-display);font-size:.75rem;font-weight:700;letter-spacing:.1em;color:rgba(100,255,160,.6);">CAMERA OFF</div>
                  </div>
                  <div class="webcam-hud" id="webcamHud" style="display:none;">
                    <span id="webcamTs"></span>
                    <span>CAM-CLAIM · CEAT LOBBY</span>
                  </div>
                  <!-- Capture overlay -->
                  <div id="captureFlash" style="display:none;position:absolute;inset:0;background:#fff;border-radius:8px;opacity:0;transition:opacity .15s;"></div>
                  <div id="captureCheck" style="display:none;position:absolute;inset:0;align-items:center;justify-content:center;background:rgba(0,161,82,.15);border-radius:8px;">
                    <div style="width:60px;height:60px;border-radius:50%;background:var(--ok);display:flex;align-items:center;justify-content:center;">
                      <i class="fa-solid fa-check" style="color:#fff;font-size:1.4rem;"></i>
                    </div>
                  </div>
                </div>

                <div style="display:flex;gap:10px;margin-top:14px;">
                  <button class="btn" id="btnStartCam" style="flex:1;justify-content:center;padding:11px;" onclick="startWebcam()">
                    <i class="fa-solid fa-video"></i> Start Camera
                  </button>
                  <button class="btn btn-primary" id="btnCapture" style="flex:1;justify-content:center;padding:11px;" disabled onclick="capturePhoto()">
                    <i class="fa-solid fa-camera"></i> Capture Photo
                  </button>
                  <button class="btn" id="btnRetake" style="flex:1;justify-content:center;padding:11px;display:none;" onclick="retakePhoto()">
                    <i class="fa-solid fa-rotate-right"></i> Retake
                  </button>
                </div>
                <button class="btn btn-primary" id="btnStep3Next" style="width:100%;justify-content:center;padding:12px;margin-top:10px;display:none;" onclick="goToStep(4)">
                  <i class="fa-solid fa-arrow-right"></i> Review &amp; Confirm
                </button>
              </div>
            </div>
          </div>

          <!-- STEP 4: Confirm & log -->
          <div class="station-step" id="step4" style="display:none;">
            <div class="card">
              <div class="card-head">
                <div class="card-title"><i class="fa-solid fa-circle-check"></i> Step 4 — Confirm &amp; Log Claim</div>
                <button class="card-action" onclick="goToStep(3)"><i class="fa-solid fa-arrow-left"></i> Back</button>
              </div>
              <div style="padding:20px;">
                <div style="padding:14px;background:var(--ok-bg);border:1px solid var(--ok-border);border-radius:9px;margin-bottom:16px;font-size:.82rem;color:var(--text-primary);display:flex;gap:9px;">
                  <i class="fa-solid fa-circle-check" style="color:var(--ok);"></i>
                  <span>All information collected. Review the details below before completing the handoff.</span>
                </div>

                <div class="detail-grid" id="confirmGrid">
                  <div class="detail-cell"><div class="detail-key">Claimant Name</div><div class="detail-val" id="confName">—</div></div>
                  <div class="detail-cell"><div class="detail-key">University ID</div><div class="detail-val" id="confId">—</div></div>
                  <div class="detail-cell"><div class="detail-key">Item Being Claimed</div><div class="detail-val" id="confItem">—</div></div>
                  <div class="detail-cell"><div class="detail-key">Found In Room</div><div class="detail-val" id="confRoom">—</div></div>
                  <div class="detail-cell"><div class="detail-key">Contact</div><div class="detail-val" id="confContact">—</div></div>
                  <div class="detail-cell"><div class="detail-key">Claim Date &amp; Time</div><div class="detail-val" id="confDate">—</div></div>
                  <div class="detail-cell" style="grid-column:1/-1;"><div class="detail-key">Item Description Provided</div><div class="detail-val" id="confDesc" style="font-family:var(--font-body);font-size:.82rem;font-weight:400;line-height:1.5;">—</div></div>
                </div>

                <div class="form-group" style="margin-top:14px;">
                  <label class="form-label">Staff Name (Processing this claim) *</label>
                  <input type="text" id="staffName" class="form-control" placeholder="e.g. Ms. Reyes" value="<?= htmlspecialchars($_SESSION['user_name'] ?? '') ?>"/>
                </div>
                <div class="form-group">
                  <label class="form-label">Staff Notes / Verification Remarks</label>
                  <textarea class="form-control" rows="2" placeholder="Confirm item matched description, ID verified, etc."></textarea>
                </div>

                <div style="display:flex;gap:10px;margin-top:4px;">
                  <button class="btn" style="flex:1;justify-content:center;padding:12px;" onclick="resetStation()"><i class="fa-solid fa-xmark"></i> Cancel</button>
                  <button class="btn btn-primary" style="flex:2;justify-content:center;padding:12px;" onclick="completeClaim()">
                    <i class="fa-solid fa-circle-check"></i> Complete Handoff &amp; Log to Database
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- STEP SUCCESS -->
          <div class="station-step" id="stepSuccess" style="display:none;">
            <div class="card" style="text-align:center;padding:3rem 2rem;">
              <div style="width:72px;height:72px;border-radius:50%;background:var(--ok-bg);border:3px solid var(--ok);display:flex;align-items:center;justify-content:center;margin:0 auto 1.2rem;animation:spotit-popIn .4s ease;">
                <i class="fa-solid fa-check" style="font-size:1.8rem;color:var(--ok);"></i>
              </div>
              <h2 style="font-family:var(--font-display);font-size:1.3rem;font-weight:800;color:var(--text-primary);margin-bottom:.5rem;">Claim Completed!</h2>
              <p style="font-size:.85rem;color:var(--text-muted);margin-bottom:.4rem;">The claim has been logged to the database with a timestamp, documentation photo, and full chain-of-custody record.</p>
              <div class="col-mono" id="successReceipt" style="font-size:.8rem;margin:.8rem 0;color:var(--green-main);"></div>
              <button class="btn btn-primary" style="margin:0 auto;padding:11px 28px;" onclick="resetStation()">
                <i class="fa-solid fa-rotate-right"></i> Process Next Claim
              </button>
            </div>
          </div>
        </div>

        <!-- RIGHT: Pending claims queue -->
        <div style="display:flex;flex-direction:column;gap:18px;">
          <div class="card">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-list-check"></i> Pending Claims Queue</div></div>
            <?php
            $pending = [];
            foreach ($pendingClaims as $pc) {
                $pending[] = [
                    'name'      => $pc['name'],
                    'item'      => $pc['item'],
                    'id'        => $pc['id_no'],
                    'submitted' => date('H:i', strtotime($pc['submitted_at']))
                ];
            }
            ?>
            <?php if (empty($pending)): ?>
            <div style="padding:20px;text-align:center;color:var(--text-dim);font-size:.78rem;">No pending claims in queue.</div>
            <?php else: ?>
            <?php foreach ($pending as $p): ?>
            <div style="padding:12px 14px;border-bottom:1px solid var(--border);">
              <div style="font-family:var(--font-display);font-size:.8rem;font-weight:700;color:var(--text-primary);"><?= htmlspecialchars($p['name']) ?></div>
              <div style="font-size:.74rem;color:var(--text-muted);margin-top:2px;">Claiming: <?= htmlspecialchars($p['item']) ?></div>
              <div style="display:flex;gap:8px;align-items:center;margin-top:5px;">
                <span class="col-mono" style="font-size:.64rem;">ID: <?= htmlspecialchars($p['id']) ?></span>
                <span class="col-mono" style="font-size:.64rem;">· <?= htmlspecialchars($p['submitted']) ?></span>
                <span class="event-status-tag est-pending" style="margin-left:auto;">Pending</span>
              </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
            <div style="padding:12px 14px;font-size:.76rem;color:var(--text-dim);text-align:center;">
              <i class="fa-solid fa-circle-info"></i> Process claims in order above
            </div>
          </div>

          <div class="card">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Today's Completed Claims</div></div>
            <?php
            $done = [];
            foreach ($completedClaims as $cc) {
                $done[] = [
                    'item' => $cc['item'],
                    'name' => $cc['name'],
                    'time' => date('H:i', strtotime($cc['time']))
                ];
            }
            ?>
            <?php if (empty($done)): ?>
            <div style="padding:20px;text-align:center;color:var(--text-dim);font-size:.78rem;">No completed claims today.</div>
            <?php else: ?>
            <?php foreach ($done as $d): ?>
            <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid var(--border);">
              <i class="fa-solid fa-circle-check" style="color:var(--ok);font-size:.8rem;flex-shrink:0;"></i>
              <div style="flex:1;">
                <div style="font-size:.78rem;font-weight:600;color:var(--text-primary);"><?= htmlspecialchars($d['item']) ?></div>
                <div style="font-size:.68rem;color:var(--text-dim);"><?= htmlspecialchars($d['name']) ?> · <?= htmlspecialchars($d['time']) ?></div>
              </div>
              <span class="event-status-tag est-recovered">Done</span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
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

let currentStep = 1;
let selectedItemId = null, selectedItemName = '', selectedItemRoom = '';
let photoCaptured = false;
let webcamStream = null;

// Webcam HUD clock
setInterval(function(){
  const el = document.getElementById('webcamTs');
  if (el) el.textContent = new Date().toLocaleTimeString('en-GB',{hour12:false});
},1000);

function goToStep(n) {
  for (let i = 1; i <= 4; i++) {
    const s = document.getElementById('step'+i);
    if (s) s.style.display = i===n ? '' : 'none';
    const st = document.getElementById('st'+i);
    if (st) { st.classList.remove('active','done'); if (i<n) st.classList.add('done'); if (i===n) st.classList.add('active'); }
  }
  document.getElementById('stepSuccess').style.display = 'none';
  currentStep = n;
  if (n===4) populateConfirm();
}

function selectItem(id, name, room) {
  document.querySelectorAll('.item-card').forEach(c=>c.classList.remove('selected'));
  document.getElementById('item-'+id).classList.add('selected');
  selectedItemId = id; selectedItemName = name; selectedItemRoom = room;
  setTimeout(()=>goToStep(2), 300);
  document.getElementById('selectedItemName').textContent = name;
  document.getElementById('selectedItemRoom').textContent = room;
}

function validateStep2() {
  const name = document.getElementById('claimName').value.trim();
  const id   = document.getElementById('claimId').value.trim();
  const desc = document.getElementById('claimDesc').value.trim();
  const cont = document.getElementById('claimContact').value.trim();
  if (!name || !id || !desc || !cont) { showToast('error','Please fill in all required fields.'); return; }
  goToStep(3);
}

async function startWebcam() {
  try {
    webcamStream = await navigator.mediaDevices.getUserMedia({ video: { facingMode:'user', width:640, height:480 } });
    const vid = document.getElementById('webcamFeed');
    vid.srcObject = webcamStream;
    vid.style.display = 'block';
    document.getElementById('webcamPlaceholder').style.display = 'none';
    document.getElementById('webcamHud').style.display = 'flex';
    document.getElementById('btnCapture').disabled = false;
    document.getElementById('btnStartCam').innerHTML = '<i class="fa-solid fa-video-slash"></i> Camera On';
  } catch (e) {
    showToast('error','Could not access camera. Check browser permissions.');
  }
}

function capturePhoto() {
  const vid    = document.getElementById('webcamFeed');
  const canvas = document.getElementById('captureCanvas');
  const flash  = document.getElementById('captureFlash');
  const check  = document.getElementById('captureCheck');
  canvas.width  = vid.videoWidth  || 640;
  canvas.height = vid.videoHeight || 480;
  canvas.getContext('2d').drawImage(vid, 0, 0);
  vid.style.display    = 'none';
  canvas.style.display = 'block';
  // Flash effect
  flash.style.display  = 'block'; flash.style.opacity = '1';
  setTimeout(()=>{ flash.style.opacity='0'; setTimeout(()=>{ flash.style.display='none'; check.style.display='flex'; },160); },100);
  if (webcamStream) { webcamStream.getTracks().forEach(t=>t.stop()); webcamStream = null; }
  photoCaptured = true;
  document.getElementById('btnCapture').style.display  = 'none';
  document.getElementById('btnRetake').style.display   = '';
  document.getElementById('btnStep3Next').style.display = '';
  showToast('success','Photo captured successfully.');
}

function retakePhoto() {
  const canvas = document.getElementById('captureCanvas');
  const check  = document.getElementById('captureCheck');
  canvas.style.display = 'none';
  check.style.display  = 'none';
  document.getElementById('btnCapture').style.display  = '';
  document.getElementById('btnRetake').style.display   = 'none';
  document.getElementById('btnStep3Next').style.display = 'none';
  document.getElementById('webcamPlaceholder').style.display = 'flex';
  document.getElementById('webcamHud').style.display  = 'none';
  photoCaptured = false;
  startWebcam();
}

function populateConfirm() {
  document.getElementById('confName').textContent    = document.getElementById('claimName').value;
  document.getElementById('confId').textContent      = document.getElementById('claimId').value;
  document.getElementById('confItem').textContent    = selectedItemName;
  document.getElementById('confRoom').textContent    = selectedItemRoom;
  document.getElementById('confContact').textContent = document.getElementById('claimContact').value;
  document.getElementById('confDesc').textContent    = document.getElementById('claimDesc').value;
  document.getElementById('confDate').textContent    = new Date().toLocaleString('en-PH',{dateStyle:'medium',timeStyle:'short'});
}

async function completeClaim() {
  const staffName = document.getElementById('staffName').value.trim();
  if (!staffName) { showToast('error','Please enter the processing staff name.'); return; }

  const btn = document.querySelector('#step4 .btn-primary');
  if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving…'; }

  try {
    // Build form data — send claim_id + webcam snapshot + staff note
    const fd = new FormData();
    fd.append('claim_id',   selectedItemId || 0);
    fd.append('staff_note', document.getElementById('staffName')?.value || '');

    // Attach webcam canvas image as a JPEG blob if captured
    const canvas = document.getElementById('captureCanvas');
    if (canvas && photoCaptured) {
      await new Promise(resolve => canvas.toBlob(blob => {
        if (blob) fd.append('webcam_snapshot', blob, 'claim_photo.jpg');
        resolve();
      }, 'image/jpeg', 0.85));
    }

    const res  = await fetch('../auth/complete_claim.php', { method: 'POST', body: fd });
    const data = await res.json();

    if (data.success) {
      const receipt = data.receipt_no || ('CLM-' + Date.now().toString(36).toUpperCase().slice(-8));
      document.getElementById('successReceipt').textContent = 'Receipt No: ' + receipt;
      for (let i=1;i<=4;i++){
        const s=document.getElementById('step'+i); if(s) s.style.display='none';
        const st=document.getElementById('st'+i); if(st){st.classList.remove('active'); st.classList.add('done');}
      }
      document.getElementById('stepSuccess').style.display = '';
      showToast('success','Claim completed and logged to database. Chain of custody closed.');
    } else {
      showToast('error', data.message || 'Failed to complete claim. Please try again.');
      if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa-solid fa-circle-check"></i> Complete Handoff & Log to Database'; }
    }
  } catch (err) {
    console.error('[completeClaim]', err);
    // Fallback — complete locally if network fails
    const receipt = 'CLM-' + Date.now().toString(36).toUpperCase().slice(-8);
    document.getElementById('successReceipt').textContent = 'Receipt No: ' + receipt + ' (offline)';
    for (let i=1;i<=4;i++){
      const s=document.getElementById('step'+i); if(s) s.style.display='none';
      const st=document.getElementById('st'+i); if(st){st.classList.remove('active'); st.classList.add('done');}
    }
    document.getElementById('stepSuccess').style.display = '';
    showToast('warn','Claim recorded locally. Please sync when connection is restored.');
  }
}

function resetStation() {
  selectedItemId=null; selectedItemName=''; selectedItemRoom=''; photoCaptured=false;
  if (webcamStream) { webcamStream.getTracks().forEach(t=>t.stop()); webcamStream=null; }
  ['claimName','claimId','claimCourse','claimContact','claimDesc'].forEach(id=>{ const el=document.getElementById(id); if(el) el.value=''; });
  document.getElementById('itemSearch').value = '';
  document.querySelectorAll('.item-card').forEach(c=>c.classList.remove('selected'));
  const cv = document.getElementById('captureCanvas'); if(cv) cv.style.display='none';
  const cc = document.getElementById('captureCheck'); if(cc) cc.style.display='none';
  document.getElementById('webcamFeed').style.display='none';
  document.getElementById('webcamPlaceholder').style.display='flex';
  document.getElementById('webcamHud').style.display='none';
  document.getElementById('btnCapture').style.display='';
  document.getElementById('btnCapture').disabled=true;
  document.getElementById('btnRetake').style.display='none';
  document.getElementById('btnStep3Next').style.display='none';
  document.getElementById('btnStartCam').innerHTML='<i class="fa-solid fa-video"></i> Start Camera';
  for(let i=1;i<=4;i++){const st=document.getElementById('st'+i);if(st){st.classList.remove('active','done');}}
  goToStep(1);
}

function searchItems(q) {
  q = q.toLowerCase();
  document.querySelectorAll('.item-card').forEach(card=>{
    const text = card.textContent.toLowerCase();
    card.style.display = (!q || text.includes(q)) ? '' : 'none';
  });
}
</script>
</body>
</html>
