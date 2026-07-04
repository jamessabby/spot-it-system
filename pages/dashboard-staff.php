<?php
/**
 * S.P.O.T.-IT — Staff Dashboard
 * pages/dashboard-staff.php
 * MICROSERVICES: No SQL here. All data comes from auth/get_detections.php via JS fetch.
 */
require_once __DIR__ . '/../auth/service_bootstrap.php';
ms_require_role('staff', 'login.php');
$active_page = 'dashboard';
$user_role   = 'staff';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Staff Dashboard — S.P.O.T.-IT</title>
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
      <div><span class="topbar-title">Staff Dashboard</span><span class="topbar-sub">— Event Verification Queue</span></div>
      <div class="live-pill"><div class="live-dot"></div>LIVE</div>
      <div class="topbar-right">
        <span style="font-family:var(--font-mono);font-size:.7rem;color:var(--text-dim);" id="liveClock"></span>
        <button class="tb-btn" onclick="toggleTheme()"><i class="fa-solid fa-circle-half-stroke"></i></button>
      </div>
    </div>

    <div class="page-body">
      <!-- Stat cards — populated by JS -->
      <div class="stat-grid" id="tourStatGrid">
        <div class="stat-card">
          <div class="stat-icon alert"><i class="fa-solid fa-circle-minus"></i></div>
          <div>
            <div class="stat-num" id="statNeeds">—</div>
            <div class="stat-label">Needs Verification</div>
            <div class="stat-delta up">Requires your action</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon warn"><i class="fa-solid fa-clock"></i></div>
          <div>
            <div class="stat-num" id="statPotential">—</div>
            <div class="stat-label">Potentially Lost</div>
            <div class="stat-delta flat">30–60 min window</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon ok"><i class="fa-solid fa-circle-check"></i></div>
          <div>
            <div class="stat-num" id="statResolved">—</div>
            <div class="stat-label">Resolved Today</div>
            <div class="stat-delta down">Dismissed or recovered</div>
          </div>
        </div>
        <div class="stat-card">
          <div class="stat-icon info"><i class="fa-solid fa-inbox"></i></div>
          <div>
            <div class="stat-num" id="statConfirmed">—</div>
            <div class="stat-label">Confirmed Missing</div>
            <div class="stat-delta flat">1-hour threshold exceeded</div>
          </div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 340px;gap:18px;align-items:start;">
        <div style="display:flex;flex-direction:column;gap:18px;">

          <!-- Verification queue — rendered by JS -->
          <div class="card" id="tourQueue">
            <div class="card-head">
              <div class="card-title"><i class="fa-solid fa-list-check"></i> Verification Queue — Pending Staff Action</div>
            </div>
            <div class="filter-tabs">
              <div class="filter-tab active" data-filter="all"     onclick="setQueueFilter('all',this)">All Pending</div>
              <div class="filter-tab"        data-filter="confirmed_missing" onclick="setQueueFilter('confirmed_missing',this)">Confirmed Missing</div>
              <div class="filter-tab"        data-filter="potential"         onclick="setQueueFilter('potential',this)">Potentially Lost</div>
              <div class="filter-tab"        data-filter="pending"           onclick="setQueueFilter('pending',this)">New</div>
            </div>
            <div id="queueBody">
              <div style="padding:28px;text-align:center;color:var(--text-dim);font-size:.82rem;">
                <i class="fa-solid fa-spinner fa-spin"></i> Loading events…
              </div>
            </div>
          </div>

          <!-- Recently resolved — rendered by JS -->
          <div class="card">
            <div class="card-head">
              <div class="card-title"><i class="fa-solid fa-circle-check"></i> Recently Resolved</div>
            </div>
            <div id="resolvedBody">
              <div style="padding:20px;text-align:center;color:var(--text-dim);font-size:.82rem;">
                <i class="fa-solid fa-spinner fa-spin"></i> Loading…
              </div>
            </div>
          </div>
        </div>

        <!-- Right: Quick actions -->
        <div style="display:flex;flex-direction:column;gap:18px;">
          <div class="card" id="tourQuickActions">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-bolt"></i> Quick Actions</div></div>
            <div style="padding:14px;display:flex;flex-direction:column;gap:8px;">
              <a href="claiming-station.php" class="btn btn-primary" style="justify-content:center;padding:12px;"><i class="fa-solid fa-hand-holding"></i> Open Claiming Station</a>
              <a href="room-monitor.php" class="btn" style="justify-content:center;padding:12px;"><i class="fa-solid fa-video"></i> View Room Monitor</a>
              <button class="btn" style="justify-content:center;padding:12px;" onclick="showToast('info','Recalibration requires access to the room setup panel.')"><i class="fa-solid fa-rotate"></i> Request Recalibration</button>
              <button class="btn" style="justify-content:center;padding:12px;" onclick="showToast('success','Daily summary report generated.')"><i class="fa-solid fa-download"></i> Export Today\'s Log</button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Verify Modal -->
<div class="modal-overlay" id="verifyModal" onclick="if(event.target===this)closeModal('verifyModal')">
  <div class="modal-box">
    <div class="modal-head">
      <div class="modal-title" id="verifyModalTitle">Verify Detection Event</div>
      <div class="modal-close" onclick="closeModal('verifyModal')"><i class="fa-solid fa-xmark"></i></div>
    </div>
    <div class="modal-body">
      <div id="verifyModalMeta" style="padding:12px 14px;background:var(--bg-base);border-radius:9px;border:1px solid var(--border);margin-bottom:14px;font-size:.82rem;color:var(--text-muted);line-height:1.7;"></div>
      <div style="padding:14px;background:var(--bg-base);border-radius:9px;border:1px solid var(--border);margin-bottom:16px;font-size:.84rem;color:var(--text-muted);line-height:1.6;">
        <strong style="color:var(--text-primary);">Staff Verification Checklist</strong><br/>
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
        <button class="modal-btn dismiss" id="btnDismiss"  onclick="submitStatus('dismissed')"><i class="fa-solid fa-ban"></i> False Alert</button>
        <button class="modal-btn recover" id="btnRecover"  onclick="submitStatus('recovered')"><i class="fa-solid fa-circle-check"></i> Item Found</button>
        <button class="modal-btn confirm" id="btnEscalate" onclick="submitStatus('confirmed_missing')"><i class="fa-solid fa-triangle-exclamation"></i> Confirm Missing</button>
      </div>
    </div>
  </div>
</div>

<div class="toast-stack" id="toastStack"></div>
<script src="../assets/js/spotit.js"></script>
<script>
// ── Live polling ──────────────────────────────────────────────────────────────
const API_DETECTIONS   = '../auth/get_detections.php';
const API_UPDATE       = '../auth/update_event_status.php';
const POLL_INTERVAL_MS = 10000;

let allDetections   = [];
let activeQueueFilter = 'all';
let verifyingId     = null;

startLiveClock('liveClock');

function stageLabel(stage) {
  if (!stage) return 'New Detection';
  const map = { detected:'New Detection', potential:'Potentially Lost', confirmed:'Confirmed Missing' };
  return map[stage.stage] || stage.label || 'Unknown';
}
function stageCls(stage) {
  if (!stage) return 'est-pending';
  const map = { detected:'est-pending', potential:'est-pending', confirmed:'est-confirmed' };
  return map[stage.stage] || 'est-pending';
}
function priorityCls(stage) {
  if (!stage) return 'info';
  const map = { detected:'info', potential:'warn', confirmed:'alert' };
  return map[stage.stage] || 'info';
}
function priorityLabel(stage) {
  if (!stage) return 'NEW';
  const map = { detected:'NEW', potential:'MED', confirmed:'HIGH' };
  return map[stage.stage] || 'NEW';
}
function devChip(deviation) {
  const n = parseInt(deviation, 10);
  if (n < 0) return `<span class="dev-chip dev-neg">${n}</span>`;
  if (n > 0) return `<span class="dev-chip dev-pos">+${n}</span>`;
  return `<span class="dev-chip dev-zero">0</span>`;
}
function ago(detectedAt) {
  const diff = Math.floor((Date.now() - new Date(detectedAt).getTime()) / 1000);
  if (diff < 60) return diff + 's';
  if (diff < 3600) return Math.floor(diff/60) + 'm';
  return Math.floor(diff/3600) + 'h ' + Math.floor((diff%3600)/60) + 'm';
}

// ── Fetch and render ─────────────────────────────────────────────────────────
function fetchAndRender() {
  fetch(API_DETECTIONS + '?limit=100')
    .then(r => r.json())
    .then(data => {
      if (!data.success) return;
      allDetections = data.detections || [];
      renderStats();
      renderQueue();
      renderResolved();
    })
    .catch(() => { /* silently retry on next interval */ });
}

function renderStats() {
  const pending   = allDetections.filter(d => ['pending','potential'].includes(d.status));
  const potential = allDetections.filter(d => d.status === 'potential');
  const confirmed = allDetections.filter(d => d.status === 'confirmed_missing');
  // "resolved today" = dismissed or recovered with updated_at today
  const today     = new Date().toISOString().slice(0,10);
  const resolved  = allDetections.filter(d =>
    ['dismissed','recovered'].includes(d.status) &&
    (d.updated_at || '').startsWith(today)
  );

  document.getElementById('statNeeds').textContent    = pending.length;
  document.getElementById('statPotential').textContent= potential.length;
  document.getElementById('statResolved').textContent = resolved.length;
  document.getElementById('statConfirmed').textContent= confirmed.length;
}

function renderQueue() {
  const pendingStatuses = ['pending','potential','confirmed_missing'];
  let items = allDetections.filter(d => pendingStatuses.includes(d.status));

  if (activeQueueFilter !== 'all') {
    items = items.filter(d => d.status === activeQueueFilter);
  }

  const body = document.getElementById('queueBody');
  if (!items.length) {
    body.innerHTML = `<div style="padding:28px;text-align:center;color:var(--text-dim);font-size:.82rem;">
      <i class="fa-solid fa-circle-check" style="color:var(--ok);"></i> No pending events${activeQueueFilter !== 'all' ? ' for this filter' : ''}.</div>`;
    return;
  }

  body.innerHTML = items.map(d => `
    <div style="padding:14px 16px;border-bottom:1px solid var(--border);display:flex;gap:14px;align-items:flex-start;">
      <div style="display:flex;flex-direction:column;align-items:center;gap:4px;flex-shrink:0;">
        <span class="badge badge-${priorityCls(d.stage)}">${priorityLabel(d.stage)}</span>
        ${devChip(d.deviation)}
      </div>
      <div style="flex:1;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:4px;">
          <span style="font-family:var(--font-display);font-size:.82rem;font-weight:700;color:var(--text-primary);">${d.room_name || d.room_id}</span>
          <span style="font-size:.72rem;color:var(--text-muted);">· ${escHtml(d.object_zone || d.object_type || '—')}</span>
        </div>
        <p style="font-size:.8rem;color:var(--text-muted);margin-bottom:6px;line-height:1.5;">
          ${escHtml(d.object_type || 'Item')} deviation detected (ROI change: ${d.roi_change_pct ?? '—'}%)
        </p>
        <div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
          <span class="col-mono">${(d.detected_at||'').slice(11,19)}</span>
          <span style="font-size:.68rem;color:var(--text-dim);">· ${ago(d.detected_at)} ago</span>
          <span class="event-status-tag ${stageCls(d.stage)}">${stageLabel(d.stage)}</span>
        </div>
      </div>
      <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0;">
        <button class="btn btn-primary btn-sm" onclick="openVerifyModal(${d.detection_id})">
          <i class="fa-solid fa-eye"></i> Verify
        </button>
        <button class="btn btn-sm" onclick="quickDismiss(${d.detection_id})">
          <i class="fa-solid fa-ban"></i> Dismiss
        </button>
      </div>
    </div>
  `).join('');
}

function renderResolved() {
  const today   = new Date().toISOString().slice(0,10);
  const items   = allDetections.filter(d =>
    ['dismissed','recovered'].includes(d.status)
  ).slice(0, 8); // show up to 8 recent

  const body = document.getElementById('resolvedBody');
  if (!items.length) {
    body.innerHTML = `<div style="padding:20px;text-align:center;color:var(--text-dim);font-size:.82rem;">No resolved events yet.</div>`;
    return;
  }

  const stMap = { dismissed:'Dismissed', recovered:'Recovered' };
  const clMap = { dismissed:'est-dismissed', recovered:'est-recovered' };
  const icMap = { dismissed:'<svg width="52" height="40" style="position:absolute;inset:0;"><polyline points="14,22 22,29 38,14" stroke="var(--warn)" stroke-width="2" fill="none" stroke-linecap="round"/></svg>',
                  recovered:'<svg width="52" height="40" style="position:absolute;inset:0;"><polyline points="14,22 22,29 38,14" stroke="#5cffac" stroke-width="2" fill="none" stroke-linecap="round"/></svg>' };

  body.innerHTML = items.map(d => `
    <div class="event-row" style="cursor:default;">
      <div class="event-thumb">${icMap[d.status]||''}</div>
      <div class="event-body">
        <div class="event-tag">${d.room_name || d.room_id}</div>
        <div class="event-title">${escHtml(d.object_type || 'Item')} — ${escHtml(d.object_zone || '')}</div>
        <div class="event-meta">
          <span class="event-time">${(d.detected_at||'').slice(11,19)}</span>
          <span class="event-status-tag ${clMap[d.status]||''}">${stMap[d.status]||d.status}</span>
        </div>
      </div>
    </div>
  `).join('');
}

// ── Filter tabs ───────────────────────────────────────────────────────────────
function setQueueFilter(filter, el) {
  document.querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  activeQueueFilter = filter;
  renderQueue();
}

// ── Verify modal ──────────────────────────────────────────────────────────────
function openVerifyModal(id) {
  verifyingId = id;
  const d = allDetections.find(x => x.detection_id === id);
  document.getElementById('verifyModalTitle').textContent = `Verify — ${d ? (d.room_name || d.room_id) : 'Event #'+id}`;
  document.getElementById('verifyModalMeta').innerHTML = d ? `
    <strong>Room:</strong> ${d.room_name || d.room_id} &nbsp;·&nbsp;
    <strong>Zone:</strong> ${escHtml(d.object_zone || d.object_type || '—')} &nbsp;·&nbsp;
    <strong>Deviation:</strong> ${d.deviation >= 0 ? '+':''}<strong>${d.deviation}</strong><br/>
    <strong>Detected:</strong> ${d.detected_at} &nbsp;·&nbsp;
    <strong>Status:</strong> ${stageLabel(d.stage)}
  ` : `Detection ID: ${id}`;
  document.getElementById('verifyNotes').value = '';
  openModal('verifyModal');
}

function quickDismiss(id) {
  if (!confirm('Dismiss this event as a false alert?')) return;
  verifyingId = id;
  submitStatus('dismissed', '');
}

function submitStatus(newStatus) {
  if (!verifyingId) return;
  const notes = (document.getElementById('verifyNotes')?.value || '').trim();
  const body  = new URLSearchParams({
    detection_id: verifyingId,
    status:        newStatus,
    notes:         notes,
  });

  fetch(API_UPDATE, { method:'POST', body })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        const msgs = {
          dismissed:          'Event dismissed as false alert.',
          recovered:          'Item marked as found and recovered.',
          confirmed_missing:  'Event confirmed as missing. Chain of custody updated.',
        };
        showToast(
          newStatus === 'recovered' ? 'success' : newStatus === 'dismissed' ? 'warn' : 'error',
          msgs[newStatus] || 'Status updated.'
        );
        closeModal('verifyModal');
        verifyingId = null;
        fetchAndRender(); // refresh immediately
      } else {
        showToast('error', data.message || 'Update failed. Try again.');
      }
    })
    .catch(() => showToast('error', 'Network error. Check your connection.'));
}

// ── Utilities ─────────────────────────────────────────────────────────────────
function escHtml(s) {
  const d = document.createElement('div');
  d.textContent = s;
  return d.innerHTML;
}

// ── Boot ──────────────────────────────────────────────────────────────────────
fetchAndRender();
setInterval(fetchAndRender, POLL_INTERVAL_MS);
</script>

<script>
window.SPOTIT_USER_ROLE = 'staff';
window.SPOTIT_TOUR_STEPS = [
  {
    target: '#sidebar',
    icon: 'fa-solid fa-compass',
    title: 'Your Navigation Hub',
    desc: 'Quick access to room monitoring, the lost &amp; found event log, and the claiming station — all from one place.',
    placement: 'right',
  },
  {
    target: '#tourStatGrid',
    icon: 'fa-solid fa-gauge-high',
    title: 'Your Daily Snapshot',
    desc: 'See what needs your attention right away: items awaiting verification, pending claims, and what you\'ve already resolved today.',
    placement: 'bottom',
  },
  {
    target: '#tourQueue',
    icon: 'fa-solid fa-list-check',
    title: 'Verification Queue',
    desc: 'This is your main task list. Each flagged event shows priority, the deviation, and how long it\'s been pending. Click <strong>Verify</strong> to physically check the room and confirm what happened.',
    placement: 'top',
  },
  {
    target: '#tourQuickActions',
    icon: 'fa-solid fa-bolt',
    title: 'Quick Actions',
    desc: 'Jump straight to the Claiming Station or Room Monitor, request a recalibration, or export today\'s log — all in one click.',
    placement: 'left',
  },
];
</script>
<script src="../assets/js/onboarding.js"></script>
</body></html>
