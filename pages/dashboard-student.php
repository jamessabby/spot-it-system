<?php
require_once __DIR__ . '/../auth/service_bootstrap.php';
ms_require_auth('login.php');
$active_page = 'student'; 
$user_role   = $_SESSION['user_role'] ?? 'student';

// Normalise staff to admin session (matches routing changes in login handlers)
if ($user_role === 'staff') {
    $user_role = 'admin';
}

$studentId = $_SESSION['user_id'] ?? 0;
$statPendingClaims = 0;
$statItemsClaimed  = 0;
$statTotalRecovered = 0;
$statRoomsMonitored = 0;
$myClaims = [];
$recentRecoveries = [];

try {
    // 1. Pending Claims count (for this user, status pending/verified)
    $stmt = $lfPdo->prepare("SELECT COUNT(*) FROM claims WHERE user_id = ? AND status IN ('pending', 'verified')");
    $stmt->execute([$studentId]);
    $statPendingClaims = (int)$stmt->fetchColumn();

    // 2. Items Claimed count (for this user, status claimed)
    $stmt = $lfPdo->prepare("SELECT COUNT(*) FROM claims WHERE user_id = ? AND status = 'claimed'");
    $stmt->execute([$studentId]);
    $statItemsClaimed = (int)$stmt->fetchColumn();

    // 3. Total recovered items available (status recovered or pending_claim)
    $statTotalRecovered = (int)$lfPdo->query("SELECT COUNT(*) FROM recovered_items WHERE status IN ('recovered', 'pending_claim')")->fetchColumn();

    // 4. Rooms monitored (from monitor DB)
    $statRoomsMonitored = (int)$monitorPdo->query("SELECT COUNT(*) FROM rooms WHERE is_active = 1")->fetchColumn();

    // 5. Fetch student claim history (join with recovered_items to get item details)
    $stmt = $lfPdo->prepare("
        SELECT c.id AS claim_id, c.status, c.submitted_at, c.item_description AS claimant_desc,
               r.item_description AS recovered_desc, r.room_id, r.item_type, r.item_tier
        FROM claims c
        LEFT JOIN recovered_items r ON c.recovery_id = r.recovery_id
        WHERE c.user_id = ?
        ORDER BY c.submitted_at DESC
        LIMIT 5
    ");
    $stmt->execute([$studentId]);
    $myClaims = $stmt->fetchAll();

    // 6. Fetch recently recovered items preview
    $recentRecoveries = $lfPdo->query("
        SELECT recovery_id, item_type, item_description, room_id, recovered_at
        FROM recovered_items
        WHERE status IN ('recovered', 'pending_claim')
        ORDER BY recovered_at DESC
        LIMIT 5
    ")->fetchAll();

} catch (Throwable $e) {
    // Fail silently, fallbacks are set to 0/empty
}
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>My Dashboard — S.P.O.T.-IT</title>
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
    <div class="topbar">
      <button class="tb-btn tb-hamburger" id="hamburgerBtn" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
      <div><span class="topbar-title">My Dashboard</span><span class="topbar-sub">— Lost &amp; Found Portal</span></div>
      <div class="topbar-right">
        <span style="font-family:var(--font-mono);font-size:.7rem;color:var(--text-dim);" id="liveClock"></span>
        <button class="tb-btn notif-bell-wrap" onclick="toggleNotifPanel()" title="Notifications">
          <i class="fa-solid fa-bell"></i>
          <div class="notif-bell-dot" id="notifDotStudent"></div>
        </button>
        <button class="tb-btn" onclick="toggleTheme()"><i class="fa-solid fa-circle-half-stroke"></i></button>
      </div>
    </div>

    <div class="page-body">
      <!-- Welcome banner -->
      <div id="tourWelcomeBanner" style="background:linear-gradient(135deg,var(--green-dark) 0%,var(--green-main) 100%);border-radius:var(--radius-lg);padding:24px 28px;margin-bottom:22px;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
        <div>
          <div style="font-family:var(--font-display);font-size:.65rem;font-weight:700;letter-spacing:.16em;text-transform:uppercase;color:rgba(255,255,255,.5);margin-bottom:.4rem;">Student Portal</div>
          <div style="font-family:var(--font-display);font-size:1.3rem;font-weight:800;color:#fff;margin-bottom:.3rem;">Hi, <?= htmlspecialchars($_SESSION['user_name'] ?? 'Student') ?>!</div>
          <div style="font-size:.82rem;color:rgba(255,255,255,.65);font-weight:300;">Check if any of your items have been recovered in the CEAT building.</div>
        </div>
        <a href="lost-thread.php" id="tourBrowseBtn" style="display:inline-flex;align-items:center;gap:8px;padding:10px 20px;border-radius:9px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.2);color:#fff;font-family:var(--font-display);font-size:.8rem;font-weight:700;text-decoration:none;transition:var(--transition);">
          <i class="fa-solid fa-magnifying-glass"></i> Browse Recovered Items
        </a>
      </div>

      <div class="stat-grid" id="tourStatGrid">
        <div class="stat-card"><div class="stat-icon warn"><i class="fa-solid fa-clock"></i></div><div><div class="stat-num"><?= $statPendingClaims ?></div><div class="stat-label">Pending Claim<?= $statPendingClaims === 1 ? '' : 's' ?></div><div class="stat-delta flat">Awaiting review</div></div></div>
        <div class="stat-card"><div class="stat-icon ok"><i class="fa-solid fa-circle-check"></i></div><div><div class="stat-num"><?= $statItemsClaimed ?></div><div class="stat-label">Item<?= $statItemsClaimed === 1 ? '' : 's' ?> Claimed</div><div class="stat-delta down">Completed</div></div></div>
        <div class="stat-card"><div class="stat-icon info"><i class="fa-solid fa-box-open"></i></div><div><div class="stat-num"><?= $statTotalRecovered ?></div><div class="stat-label">Items in Recovered Log</div><div class="stat-delta flat">Available now</div></div></div>
        <div class="stat-card"><div class="stat-icon green"><i class="fa-solid fa-door-open"></i></div><div><div class="stat-num"><?= $statRoomsMonitored ?></div><div class="stat-label">Rooms Monitored</div><div class="stat-delta flat">CEAT Building</div></div></div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 320px;gap:18px;align-items:start;">
        <div style="display:flex;flex-direction:column;gap:18px;">

          <!-- My claims -->
          <div class="card" id="tourClaimHistory">
            <div class="card-head">
              <div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> My Claim History</div>
              <a href="lost-thread.php" class="card-action"><i class="fa-solid fa-plus"></i> Browse Items</a>
            </div>
            <?php if (empty($myClaims)): ?>
            <div style="padding:24px;text-align:center;color:var(--text-dim);font-size:.82rem;">
              <i class="fa-solid fa-inbox" style="font-size:1.4rem;margin-bottom:6px;display:block;"></i>
              You haven't submitted any claim requests yet.
            </div>
            <?php else: ?>
            <?php foreach ($myClaims as $c):
                $type = strtolower($c['item_type'] ?? '');
                $icon = 'fa-box';
                if (stripos($type, 'umbrella') !== false) $icon = 'fa-umbrella';
                elseif (stripos($type, 'cable') !== false || stripos($type, 'usb') !== false) $icon = 'fa-plug';
                elseif (stripos($type, 'calculator') !== false) $icon = 'fa-calculator';
                elseif (stripos($type, 'water') !== false || stripos($type, 'bottle') !== false || stripos($type, 'tumbler') !== false) $icon = 'fa-bottle-water';
                elseif (stripos($type, 'earphone') !== false || stripos($type, 'headphone') !== false) $icon = 'fa-headphones';
                elseif (stripos($type, 'phone') !== false || stripos($type, 'mobile') !== false) $icon = 'fa-mobile-screen';
                elseif (stripos($type, 'wallet') !== false) $icon = 'fa-wallet';

                $statusLabel = match($c['status']) {
                    'pending'  => 'Pending Verification',
                    'verified' => 'Pending Pickup',
                    'claimed'  => 'Claimed',
                    'rejected' => 'Rejected',
                    default    => 'Pending',
                };
                $stCls = match($c['status']) {
                    'pending'  => 'est-pending',
                    'verified' => 'est-pending',
                    'claimed'  => 'est-recovered',
                    'rejected' => 'est-dismissed',
                    default    => 'est-pending',
                };
            ?>
            <div style="display:flex;align-items:center;gap:14px;padding:14px 16px;border-bottom:1px solid var(--border);">
              <div style="width:40px;height:40px;border-radius:10px;background:var(--green-pale);color:var(--green-main);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.9rem;">
                <i class="fa-solid <?= $icon ?>"></i>
              </div>
              <div style="flex:1;">
                <div style="font-family:var(--font-display);font-size:.84rem;font-weight:700;color:var(--text-primary);"><?= htmlspecialchars($c['item_type'] ?? 'Unknown Item') ?></div>
                <div style="font-size:.74rem;color:var(--text-muted);margin-top:2px;">
                  Recovered from <?= htmlspecialchars($c['room_id']) ?> · Description: <?= htmlspecialchars($c['recovered_desc'] ?? $c['claimant_desc']) ?>
                </div>
                <div style="display:flex;gap:8px;align-items:center;margin-top:5px;">
                  <span class="col-mono" style="font-size:.64rem;"><?= date('F j, Y · H:i', strtotime($c['submitted_at'])) ?></span>
                  <span class="event-status-tag <?= $stCls ?>"><?= $statusLabel ?></span>
                </div>
              </div>
              <?php if ($c['status'] === 'verified'): ?>
              <button class="btn btn-primary btn-sm" onclick="showToast('info','Please proceed to the dispensing window at the CEAT Building lobby.')">
                <i class="fa-solid fa-location-dot"></i> How to Claim
              </button>
              <?php endif; ?>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
            <div style="padding:14px 16px;">
              <a href="lost-thread.php" class="btn btn-primary" style="width:100%;justify-content:center;">
                <i class="fa-solid fa-magnifying-glass"></i> Browse All Recovered Items
              </a>
            </div>
          </div>

          <!-- How claiming works -->
          <div class="card">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-circle-question"></i> How to Claim a Recovered Item</div></div>
            <div style="padding:18px 20px;display:grid;grid-template-columns:repeat(3,1fr);gap:14px;">
              <?php
              $steps = [
                ['1','fa-magnifying-glass','Browse the Thread','Go to the Lost &amp; Found thread and find your item in the recovered items list.'],
                ['2','fa-hand-pointer','Submit a Claim','Click "Claim This Item" and fill in your university ID and item description for verification.'],
                ['3','fa-hand-holding','Pick Up at Window','Visit the dispensing window at the CEAT building lobby with your ID to complete the handoff.'],
              ];
              foreach ($steps as $s): ?>
              <div style="text-align:center;padding:14px;">
                <div style="width:36px;height:36px;border-radius:50%;background:var(--green-main);color:#fff;font-family:var(--font-display);font-weight:800;font-size:.82rem;display:flex;align-items:center;justify-content:center;margin:0 auto .8rem;">
                  <?= $s[0] ?>
                </div>
                <div style="width:38px;height:38px;border-radius:10px;background:var(--green-pale);color:var(--green-main);display:flex;align-items:center;justify-content:center;margin:0 auto .7rem;font-size:.85rem;">
                  <i class="fa-solid <?= $s[1] ?>"></i>
                </div>
                <div style="font-family:var(--font-display);font-size:.8rem;font-weight:700;color:var(--text-primary);margin-bottom:.3rem;"><?= $s[2] ?></div>
                <div style="font-size:.74rem;color:var(--text-muted);line-height:1.6;"><?= $s[3] ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- Right: Recent recoveries preview -->
        <div style="display:flex;flex-direction:column;gap:18px;">
          <div class="card" id="tourRecentRecovered">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-box-open"></i> Recently Recovered</div><a href="lost-thread.php" class="card-action">See All</a></div>
            <?php if (empty($recentRecoveries)): ?>
            <div style="padding:20px;text-align:center;color:var(--text-dim);font-size:.82rem;">
              No recently recovered items logged.
            </div>
            <?php else: ?>
            <?php foreach ($recentRecoveries as $r):
                $type = strtolower($r['item_type'] ?? '');
                $icon = 'fa-box';
                if (stripos($type, 'umbrella') !== false) $icon = 'fa-umbrella';
                elseif (stripos($type, 'cable') !== false || stripos($type, 'usb') !== false) $icon = 'fa-plug';
                elseif (stripos($type, 'calculator') !== false) $icon = 'fa-calculator';
                elseif (stripos($type, 'water') !== false || stripos($type, 'bottle') !== false || stripos($type, 'tumbler') !== false) $icon = 'fa-bottle-water';
                elseif (stripos($type, 'earphone') !== false || stripos($type, 'headphone') !== false) $icon = 'fa-headphones';
                elseif (stripos($type, 'phone') !== false || stripos($type, 'mobile') !== false) $icon = 'fa-mobile-screen';
                elseif (stripos($type, 'wallet') !== false) $icon = 'fa-wallet';
            ?>
            <div style="display:flex;align-items:center;gap:10px;padding:11px 16px;border-bottom:1px solid var(--border);">
              <div style="width:34px;height:34px;border-radius:8px;background:var(--ok-bg);color:var(--ok);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.8rem;">
                <i class="fa-solid <?= $icon ?>"></i>
              </div>
              <div style="flex:1;">
                <div style="font-size:.8rem;font-weight:600;color:var(--text-primary);"><?= htmlspecialchars($r['item_type'] ?? 'Unknown Item') ?></div>
                <div style="font-size:.7rem;color:var(--text-dim);"><?= htmlspecialchars($r['room_id']) ?> · <?= date('M j', strtotime($r['recovered_at'])) ?></div>
              </div>
              <button class="btn btn-sm btn-ok" onclick="openClaimModal(<?= $r['recovery_id'] ?>,'<?= htmlspecialchars(addslashes($r['item_type'] ?? 'Item')) ?>','<?= $r['room_id'] ?>')">Claim</button>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
          </div>

          <div class="card" style="background:var(--info-bg);border-color:rgba(26,106,181,.15);">
            <div style="padding:18px;">
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:.6rem;">
                <i class="fa-solid fa-circle-info" style="color:var(--info);"></i>
                <span style="font-family:var(--font-display);font-size:.8rem;font-weight:700;color:var(--text-primary);">Did you lose something?</span>
              </div>
              <p style="font-size:.78rem;color:var(--text-muted);line-height:1.6;margin-bottom:.9rem;">
                If your item is missing, it may have been detected by the S.P.O.T.-IT cameras. Browse the recovered items thread or visit the CEAT dispensing window.
              </p>
              <a href="lost-thread.php" class="btn btn-primary" style="width:100%;justify-content:center;">
                <i class="fa-solid fa-magnifying-glass"></i> Search Recovered Items
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Quick claim modal -->
<div class="modal-overlay" id="claimModal" onclick="if(event.target===this)closeModal('claimModal')">
  <div class="modal-box" style="max-width:440px;">
    <div class="modal-head"><div class="modal-title">Submit Claim Request</div><div class="modal-close" onclick="closeModal('claimModal')"><i class="fa-solid fa-xmark"></i></div></div>
    <div class="modal-body">
      <div class="selected-item-banner" style="display:flex;align-items:center;gap:10px;padding:11px 14px;border-radius:9px;background:var(--ok-bg);border:1px solid var(--ok-border);font-size:.82rem;color:var(--text-primary);margin-bottom:1rem;">
        <i class="fa-solid fa-circle-check" style="color:var(--ok);"></i>
        <span>Claiming: <strong id="claimItemName">—</strong> from <strong id="claimItemRoom">—</strong></span>
      </div>
      <div class="form-group"><label class="form-label">Full Name *</label><input type="text" id="ci_name" class="form-control" placeholder="e.g. Maria Santos"/></div>
      <div class="form-group"><label class="form-label">University ID *</label><input type="text" id="ci_id" class="form-control" placeholder="e.g. 2021-00001"/></div>
      <div class="form-group"><label class="form-label">Describe Your Item *</label><textarea id="ci_desc" class="form-control" rows="3" placeholder="Color, brand, markings, contents, damage — anything that proves it's yours."></textarea></div>
      <div class="form-group"><label class="form-label">Contact Number</label><input type="tel" id="ci_contact" class="form-control" placeholder="e.g. 09xx-xxx-xxxx"/></div>
      <div style="padding:12px;background:var(--warn-bg);border:1px solid var(--warn-border);border-radius:9px;font-size:.78rem;color:var(--text-primary);margin-bottom:1rem;">
        <i class="fa-solid fa-triangle-exclamation" style="color:var(--warn);"></i>
        Staff will verify your description matches the recovered item before releasing it. Please proceed to the dispensing window with your university ID.
      </div>
      <div class="modal-actions">
        <button class="modal-btn dismiss" onclick="closeModal('claimModal')">Cancel</button>
        <button class="modal-btn recover" onclick="submitClaim()"><i class="fa-solid fa-paper-plane"></i> Submit Claim</button>
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
let currentDetailId;

function openClaimModal(id, name, room) {
  currentDetailId = id;
  document.getElementById('claimItemName').textContent = name;
  document.getElementById('claimItemRoom').textContent = room;
  ['ci_name','ci_id','ci_desc','ci_contact'].forEach(i=>{ const el=document.getElementById(i); if(el) el.value=''; });
  openModal('claimModal');
}

async function submitClaim() {
  const name = document.getElementById('ci_name').value.trim();
  const id   = document.getElementById('ci_id').value.trim();
  const desc = document.getElementById('ci_desc').value.trim();
  const contact = document.getElementById('ci_contact') ? document.getElementById('ci_contact').value.trim() : '';
  
  if (!name || !id || !desc) { showToast('error','Please fill in all required fields.'); return; }
  
  try {
    const res = await spotitFetch('../auth/submit_claim.php', {
      method: 'POST',
      body: new URLSearchParams({
        recovery_id: currentDetailId,
        full_name: name,
        id_number: id,
        contact: contact,
        description: desc
      })
    });
    if (res && res.success) {
      showToast('success', 'Claim submitted! Please proceed to the CEAT dispensing window with your university ID.');
      closeModal('claimModal');
      setTimeout(() => location.reload(), 1000);
    } else {
      showToast('error', res ? res.message : 'Claim submission failed.');
    }
  } catch (e) {
    showToast('error', 'Network error submitting claim.');
  }
}
</script>

<script>
window.SPOTIT_USER_ROLE = 'student';
window.SPOTIT_TOUR_STEPS = [
  {
    target: '#sidebar',
    icon: 'fa-solid fa-compass',
    title: 'Welcome to S.P.O.T.-IT!',
    desc: 'From here you can browse recovered items, track your claims, and manage your posts — everything for the CEAT lost &amp; found system.',
    placement: 'right',
  },
  {
    target: '#tourBrowseBtn',
    icon: 'fa-solid fa-magnifying-glass',
    title: 'Browse Recovered Items',
    desc: 'Lost something? Click here anytime to search the full list of items detected or surrendered across all CEAT laboratory rooms.',
    placement: 'bottom',
  },
  {
    target: '#tourStatGrid',
    icon: 'fa-solid fa-gauge-high',
    title: 'Your Activity at a Glance',
    desc: 'Track your pending claims, items you\'ve successfully claimed, and how many items are currently in the recovered log.',
    placement: 'bottom',
  },
  {
    target: '#tourClaimHistory',
    icon: 'fa-solid fa-clock-rotate-left',
    title: 'My Claim History',
    desc: 'Every claim you\'ve submitted lives here, with its current status. "Pending Pickup" means it\'s ready — just bring your university ID to the dispensing window.',
    placement: 'top',
  },
  {
    target: '#tourRecentRecovered',
    icon: 'fa-solid fa-box-open',
    title: 'Recently Recovered Items',
    desc: 'A live preview of the newest items found in CEAT rooms. Recognize something of yours? Click <strong>Claim</strong> right from here.',
    placement: 'left',
  },
];
</script>
<script src="../assets/js/onboarding.js"></script>
</body></html>
