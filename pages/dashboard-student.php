<?php
/**
 * S.P.O.T.-IT — Student Dashboard
 * pages/dashboard-student.php
 * MICROSERVICES: No SQL here. Data comes from auth/ endpoints via JS fetch.
 */
require_once __DIR__ . '/../config/env.php';
ms_require_auth('login.php');
$active_page = 'student';
$user_role   = 'student';
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

      <!-- Stat cards — live data -->
      <div class="stat-grid" id="tourStatGrid">
        <div class="stat-card">
          <div class="stat-icon warn"><i class="fa-solid fa-clock"></i></div>
          <div><div class="stat-num" id="statPendingClaims">—</div><div class="stat-label">Pending Claims</div><div class="stat-delta flat">Submitted</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon ok"><i class="fa-solid fa-circle-check"></i></div>
          <div><div class="stat-num" id="statClaimed">—</div><div class="stat-label">Items Claimed</div><div class="stat-delta down">This semester</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon info"><i class="fa-solid fa-box-open"></i></div>
          <div><div class="stat-num" id="statRecoveredLog">—</div><div class="stat-label">Items in Recovered Log</div><div class="stat-delta flat">Available to claim</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon green"><i class="fa-solid fa-door-open"></i></div>
          <div><div class="stat-num">8</div><div class="stat-label">Rooms Monitored</div><div class="stat-delta flat">CEAT MLH Building</div></div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 320px;gap:18px;align-items:start;">
        <div style="display:flex;flex-direction:column;gap:18px;">

          <!-- My claims — live data -->
          <div class="card" id="tourClaimHistory">
            <div class="card-head">
              <div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> My Claim History</div>
              <a href="lost-thread.php" class="card-action"><i class="fa-solid fa-plus"></i> Browse Items</a>
            </div>
            <div id="claimHistoryBody">
              <div style="padding:28px;text-align:center;color:var(--text-dim);font-size:.82rem;">
                <i class="fa-solid fa-spinner fa-spin"></i> Loading your claims…
              </div>
            </div>
            <div style="padding:14px 16px;">
              <a href="lost-thread.php" class="btn btn-primary" style="width:100%;justify-content:center;">
                <i class="fa-solid fa-magnifying-glass"></i> Browse All Recovered Items
              </a>
            </div>
          </div>

          <!-- How claiming works (static — no data needed) -->
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

        <!-- Right: Recent recoveries + info -->
        <div style="display:flex;flex-direction:column;gap:18px;">
          <div class="card" id="tourRecentRecovered">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-box-open"></i> Recently Recovered</div><a href="lost-thread.php" class="card-action">See All</a></div>
            <div id="recentRecoveredBody">
              <div style="padding:20px;text-align:center;color:var(--text-dim);font-size:.82rem;">
                <i class="fa-solid fa-spinner fa-spin"></i> Loading…
              </div>
            </div>
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
      <p style="font-size:.82rem;color:var(--text-muted);margin-bottom:1rem;">You are claiming: <strong id="claimItemName" style="color:var(--text-primary);">Item</strong></p>
      <input type="hidden" id="claimRecoveryId" value=""/>
      <div class="form-group"><label class="form-label">University ID</label><input type="text" class="form-control" id="claimUnivId" placeholder="e.g. 2021-00001"/></div>
      <div class="form-group"><label class="form-label">Describe Your Item</label><textarea class="form-control" id="claimDesc" rows="2" placeholder="Unique characteristics — color, brand, contents, etc."></textarea></div>
      <div class="form-group"><label class="form-label">Contact Number</label><input type="tel" class="form-control" id="claimContact" placeholder="e.g. 09xx-xxx-xxxx"/></div>
      <div style="padding:12px;background:var(--warn-bg);border:1px solid var(--warn-border);border-radius:9px;font-size:.78rem;color:var(--text-primary);margin-bottom:1rem;">
        <i class="fa-solid fa-triangle-exclamation" style="color:var(--warn);"></i>
        Staff will verify your description matches the recovered item before releasing it.
      </div>
      <div class="modal-actions">
        <button class="modal-btn dismiss" onclick="closeModal('claimModal')">Cancel</button>
        <button class="modal-btn recover" onclick="submitClaim()"><i class="fa-solid fa-paper-plane"></i> Submit Claim</button>
      </div>
    </div>
  </div>
</div>

<div class="toast-stack" id="toastStack"></div>
<script src="../assets/js/spotit.js"></script>
<script>
// ── Config ────────────────────────────────────────────────────────────────────
const API_CLAIMS    = '../auth/get_claims.php';
const API_RECOVERED = '../auth/get_recovered_items.php';
const API_SUBMIT    = '../auth/submit_claim.php';

startLiveClock('liveClock');

function escHtml(s) { const d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }

// ── Fetch all data on load ────────────────────────────────────────────────────
function fetchAll() {
  fetchClaims();
  fetchRecentRecovered();
}

function fetchClaims() {
  fetch(API_CLAIMS + '?limit=10')
    .then(r => r.json())
    .then(data => {
      if (!data.success) throw new Error(data.message);
      renderClaims(data.claims || []);
      // Stats
      const pending = (data.claims||[]).filter(c => c.status === 'pending').length;
      const claimed = (data.claims||[]).filter(c => c.status === 'claimed').length;
      document.getElementById('statPendingClaims').textContent = pending;
      document.getElementById('statClaimed').textContent       = claimed;
    })
    .catch(() => {
      document.getElementById('claimHistoryBody').innerHTML =
        `<div style="padding:20px;text-align:center;color:var(--text-dim);font-size:.82rem;">No claim history yet.</div>`;
      document.getElementById('statPendingClaims').textContent = 0;
      document.getElementById('statClaimed').textContent       = 0;
    });
}

function fetchRecentRecovered() {
  fetch(API_RECOVERED + '?limit=5')
    .then(r => r.json())
    .then(data => {
      if (!data.success) throw new Error(data.message);
      renderRecentRecovered(data.items || []);
      document.getElementById('statRecoveredLog').textContent = data.count || 0;
    })
    .catch(() => {
      document.getElementById('recentRecoveredBody').innerHTML =
        `<div style="padding:20px;text-align:center;color:var(--text-dim);font-size:.82rem;">No recovered items yet.</div>`;
      document.getElementById('statRecoveredLog').textContent = 0;
    });
}

// ── Render: claim history ─────────────────────────────────────────────────────
function renderClaims(claims) {
  const body = document.getElementById('claimHistoryBody');
  if (!claims.length) {
    body.innerHTML = `<div style="padding:20px;text-align:center;color:var(--text-dim);font-size:.82rem;">
      You haven't submitted any claims yet. Browse the <a href="lost-thread.php" style="color:var(--green-main);">recovered items thread</a> to get started.</div>`;
    return;
  }

  const stMap = { pending:'Pending Pickup', verified:'Ready for Pickup', claimed:'Claimed', rejected:'Rejected' };
  const stCls = { pending:'est-pending', verified:'est-potential', claimed:'est-recovered', rejected:'est-dismissed' };
  const icMap = { pending:'fa-clock', verified:'fa-check', claimed:'fa-box-open', rejected:'fa-ban' };

  body.innerHTML = claims.map(c => `
    <div style="display:flex;align-items:center;gap:14px;padding:14px 16px;border-bottom:1px solid var(--border);">
      <div style="width:40px;height:40px;border-radius:10px;background:var(--green-pale);color:var(--green-main);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.9rem;">
        <i class="fa-solid ${icMap[c.status]||'fa-box'}"></i>
      </div>
      <div style="flex:1;">
        <div style="font-family:var(--font-display);font-size:.84rem;font-weight:700;color:var(--text-primary);">${escHtml(c.item_type || 'Item')}</div>
        <div style="font-size:.74rem;color:var(--text-muted);margin-top:2px;">${escHtml(c.recovered_item_desc || c.item_description || '—')}</div>
        <div style="display:flex;gap:8px;align-items:center;margin-top:5px;">
          <span class="col-mono" style="font-size:.64rem;">${(c.submitted_at||'').slice(0,10)}</span>
          <span class="event-status-tag ${stCls[c.status]||'est-pending'}">${stMap[c.status]||c.status}</span>
        </div>
      </div>
      ${c.status === 'verified' ? `<button class="btn btn-primary btn-sm" onclick="showToast('info','Please proceed to the dispensing window at the CEAT Building lobby.')"><i class="fa-solid fa-location-dot"></i> How to Claim</button>` : ''}
    </div>
  `).join('');
}

// ── Render: recently recovered ────────────────────────────────────────────────
function renderRecentRecovered(items) {
  const body = document.getElementById('recentRecoveredBody');
  if (!items.length) {
    body.innerHTML = `<div style="padding:20px;text-align:center;color:var(--text-dim);font-size:.82rem;">No recovered items available right now.</div>`;
    return;
  }
  body.innerHTML = items.map(r => `
    <div style="display:flex;align-items:center;gap:10px;padding:11px 16px;border-bottom:1px solid var(--border);">
      <div style="width:34px;height:34px;border-radius:8px;background:var(--ok-bg);color:var(--ok);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.8rem;">
        <i class="fa-solid fa-box-open"></i>
      </div>
      <div style="flex:1;">
        <div style="font-size:.8rem;font-weight:600;color:var(--text-primary);">${escHtml(r.item_type || 'Item')}</div>
        <div style="font-size:.7rem;color:var(--text-dim);">${escHtml(r.room_id)} · ${(r.recovered_at||'').slice(0,10)}</div>
      </div>
      <button class="btn btn-sm btn-ok" onclick="openClaimModal('${escHtml(r.item_type||'Item')}','${r.recovery_id}')">Claim</button>
    </div>
  `).join('');
}

// ── Claim modal ───────────────────────────────────────────────────────────────
function openClaimModal(name, recoveryId) {
  document.getElementById('claimItemName').textContent  = name;
  document.getElementById('claimRecoveryId').value      = recoveryId || '';
  document.getElementById('claimUnivId').value          = '';
  document.getElementById('claimDesc').value            = '';
  document.getElementById('claimContact').value         = '';
  openModal('claimModal');
}

function submitClaim() {
  const recoveryId = document.getElementById('claimRecoveryId').value;
  const univId     = document.getElementById('claimUnivId').value.trim();
  const desc       = document.getElementById('claimDesc').value.trim();
  const contact    = document.getElementById('claimContact').value.trim();

  if (!univId || !desc) {
    showToast('warn','Please fill in your University ID and item description.');
    return;
  }

  const body = new URLSearchParams({
    recovery_id:  recoveryId,
    full_name:    window.SPOTIT_USER_NAME || '',  // injected below from PHP session
    id_number:    univId,
    description:  desc,
    contact:      contact,
  });

  fetch(API_SUBMIT, { method:'POST', body })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        showToast('success','Claim submitted! Please visit the dispensing window with your university ID.');
        closeModal('claimModal');
        fetchClaims(); // refresh claim history
      } else {
        showToast('error', data.message || 'Submission failed. Try again.');
      }
    })
    .catch(() => showToast('error', 'Network error. Check your connection.'));
}

// ── Boot ──────────────────────────────────────────────────────────────────────
window.SPOTIT_USER_NAME = <?= json_encode($_SESSION['user_name'] ?? '') ?>;
fetchAll();
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
