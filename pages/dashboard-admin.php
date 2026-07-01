<?php
/**
 * S.P.O.T.-IT — Admin Dashboard
 * pages/dashboard-admin.php
 * MICROSERVICES: No SQL here. Data comes from auth/get_detections.php via JS fetch.
 */
require_once __DIR__ . '/../config/env.php';
ms_require_role('admin', 'login.php');
$active_page = 'dashboard';
$user_role   = 'admin';
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
        <button class="tb-btn" onclick="fetchAndRender()" title="Refresh"><i class="fa-solid fa-rotate-right"></i></button>
        <button class="tb-btn" id="tourNotifBtn" title="Notifications"><i class="fa-solid fa-bell"></i><div class="tb-notif-dot" id="notifDot" style="display:none;"></div></button>
        <button class="tb-btn" onclick="toggleTheme()" title="Theme"><i class="fa-solid fa-circle-half-stroke"></i></button>
      </div>
    </div>

    <div class="page-body">
      <!-- Stat cards -->
      <div class="stat-grid" id="tourStatGrid">
        <div class="stat-card">
          <div class="stat-icon ok"><i class="fa-solid fa-circle-check"></i></div>
          <div><div class="stat-num" id="statRooms">—</div><div class="stat-label">Rooms Monitoring</div><div class="stat-delta flat"><i class="fa-solid fa-minus"></i> Loading…</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon alert"><i class="fa-solid fa-circle-minus"></i></div>
          <div><div class="stat-num" id="statMissing">—</div><div class="stat-label">Active Deviations</div><div class="stat-delta up" id="statMissingDelta">Loading…</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon warn"><i class="fa-solid fa-clock"></i></div>
          <div><div class="stat-num" id="statPending">—</div><div class="stat-label">Pending Validation</div><div class="stat-delta flat"><i class="fa-solid fa-arrow-right"></i> Awaiting staff review</div></div>
        </div>
        <div class="stat-card">
          <div class="stat-icon info"><i class="fa-solid fa-box-open"></i></div>
          <div><div class="stat-num" id="statToday">—</div><div class="stat-label">Events Today</div><div class="stat-delta flat" id="statTodayDelta">Loading…</div></div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 360px;gap:18px;align-items:start;">

        <!-- LEFT: Room table + event log -->
        <div style="display:flex;flex-direction:column;gap:18px;">

          <!-- Room status table — live data -->
          <div class="card" id="tourRoomTable">
            <div class="card-head">
              <div class="card-title"><i class="fa-solid fa-door-open"></i> Room Status — All Monitored Rooms</div>
              <a href="room-monitor.php" class="card-action"><i class="fa-solid fa-expand"></i> Full View</a>
            </div>
            <div style="overflow-x:auto;">
              <table class="data-table">
                <thead><tr><th>Room</th><th>Live / Baseline</th><th>Deviation</th><th>Status</th><th>Active Event</th><th>Last Detection</th><th></th></tr></thead>
                <tbody id="roomTableBody">
                  <tr><td colspan="7" style="text-align:center;color:var(--text-dim);padding:20px;"><i class="fa-solid fa-spinner fa-spin"></i> Loading rooms…</td></tr>
                </tbody>
              </table>
            </div>
          </div>

          <!-- Detection event log — live data -->
          <div class="card" id="tourEventLog">
            <div class="card-head">
              <div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Detection Event Log</div>
              <a href="#" class="card-action">Export <i class="fa-solid fa-download"></i></a>
            </div>
            <div class="filter-tabs">
              <div class="filter-tab active" onclick="setLogFilter('all',this)">All</div>
              <div class="filter-tab" onclick="setLogFilter('pending',this)">Pending</div>
              <div class="filter-tab" onclick="setLogFilter('confirmed_missing',this)">Confirmed</div>
              <div class="filter-tab" onclick="setLogFilter('dismissed',this)">Dismissed</div>
              <div class="filter-tab" onclick="setLogFilter('recovered',this)">Recovered</div>
            </div>
            <div id="eventLogBody">
              <div style="padding:28px;text-align:center;color:var(--text-dim);font-size:.82rem;">
                <i class="fa-solid fa-spinner fa-spin"></i> Loading events…
              </div>
            </div>
          </div>
        </div>

        <!-- RIGHT: Alerts + Timeline -->
        <div style="display:flex;flex-direction:column;gap:18px;">
          <div class="card" id="tourAlerts">
            <div class="card-head">
              <div class="card-title"><i class="fa-solid fa-bell-ring"></i> Active Alerts</div>
              <a href="#" class="card-action">Mark all read</a>
            </div>
            <div id="alertsFeed">
              <div style="padding:20px;text-align:center;color:var(--text-dim);font-size:.82rem;">
                <i class="fa-solid fa-spinner fa-spin"></i> Loading…
              </div>
            </div>
          </div>

          <div class="card">
            <div class="card-head">
              <div class="card-title"><i class="fa-solid fa-timeline"></i> Today's Timeline</div>
              <span style="font-size:.65rem;color:var(--text-dim);font-family:var(--font-mono);" id="timelineDate"></span>
            </div>
            <div id="timelineFeed">
              <div style="padding:20px;text-align:center;color:var(--text-dim);font-size:.82rem;">
                <i class="fa-solid fa-spinner fa-spin"></i> Loading…
              </div>
            </div>
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
        <div class="snap-hud"><span id="snapRoom">CAM-01</span><span>MOTION: NONE</span><span id="snapAlert" style="color:#ff4d4d;">⚠ DEVIATION DETECTED</span></div>
        <div class="snap-ts" id="snapTs">—</div>
        <svg width="100%" height="100%" style="position:absolute;inset:0;" viewBox="0 0 640 360" preserveAspectRatio="none" id="snapSVG">
          <rect x="440" y="40" width="120" height="70" rx="3" fill="rgba(255,77,77,.1)" stroke="#ff4d4d" stroke-width="2" stroke-dasharray="6,3" id="alertROI"/>
          <text x="444" y="36" font-family="monospace" font-size="9" fill="#ff4d4d" id="alertLabel">DEVIATION DETECTED</text>
        </svg>
      </div>
      <div class="detail-grid">
        <div class="detail-cell"><div class="detail-key">Room</div><div class="detail-val" id="dRoom">—</div></div>
        <div class="detail-cell"><div class="detail-key">Detected At</div><div class="detail-val" id="dTime">—</div></div>
        <div class="detail-cell"><div class="detail-key">Baseline Count</div><div class="detail-val" id="dBaseline">—</div></div>
        <div class="detail-cell"><div class="detail-key">Live Count</div><div class="detail-val" id="dLive">—</div></div>
        <div class="detail-cell"><div class="detail-key">ROI Zone</div><div class="detail-val" id="dZone">—</div></div>
        <div class="detail-cell"><div class="detail-key">Elapsed</div><div class="detail-val" id="dDuration">—</div></div>
        <div class="detail-cell"><div class="detail-key">ROI Change</div><div class="detail-val" id="dRoi">—</div></div>
        <div class="detail-cell"><div class="detail-key">Status</div><div class="detail-val" id="dStatus">—</div></div>
      </div>
      <textarea class="form-control" style="margin-top:14px;" id="modalNotes" rows="2" placeholder="Add admin notes or remarks about this event…"></textarea>
      <div class="modal-actions">
        <button class="modal-btn dismiss" onclick="closeModal('eventModal')"><i class="fa-solid fa-xmark"></i> Close</button>
        <button class="modal-btn dismiss" onclick="markEvent('dismissed')"><i class="fa-solid fa-ban"></i> Dismiss</button>
        <button class="modal-btn recover" onclick="markEvent('recovered')"><i class="fa-solid fa-circle-check"></i> Mark Recovered</button>
        <button class="modal-btn confirm" onclick="markEvent('confirmed_missing')"><i class="fa-solid fa-triangle-exclamation"></i> Confirm Missing</button>
      </div>
    </div>
  </div>
</div>
<div class="toast-stack" id="toastStack"></div>

<script src="../assets/js/spotit.js"></script>
<script>
// ── Config ────────────────────────────────────────────────────────────────────
const API_DETECTIONS   = '../auth/get_detections.php';
const API_UPDATE       = '../auth/update_event_status.php';
const POLL_INTERVAL_MS = 10000;

let allDetections = [];
let logFilter     = 'all';
let modalDetId    = null;

startLiveClock('liveClock');
document.getElementById('timelineDate').textContent = new Date().toLocaleDateString('en-PH',{month:'long',day:'numeric',year:'numeric'});

// ── Helpers ───────────────────────────────────────────────────────────────────
function escHtml(s) { const d=document.createElement('div'); d.textContent=String(s||''); return d.innerHTML; }

function stageOf(d) { return (d.stage||{}).stage || 'detected'; }
function stageLabel(d) {
  const map = { detected:'New Detection', potential:'Potentially Lost', confirmed:'Confirmed Missing' };
  return map[stageOf(d)] || 'Unknown';
}
function stageCls(d) {
  const map = { detected:'est-pending', potential:'est-pending', confirmed:'est-confirmed' };
  return map[stageOf(d)] || 'est-pending';
}
function badgeCls(st) {
  if (st==='confirmed_missing') return 'badge-alert';
  if (st==='potential')         return 'badge-warn';
  if (st==='pending')           return 'badge-warn';
  if (st==='recovered')         return 'badge-ok';
  if (st==='dismissed')         return 'badge-muted';
  return 'badge-info';
}
function badgeLabel(st, d) {
  const map = { confirmed_missing:'MISSING', potential:'POTENTIAL', pending:'PENDING', recovered:'NORMAL', dismissed:'DISMISSED' };
  return map[st] || st.toUpperCase();
}
function devChip(dev) {
  const n = parseInt(dev, 10);
  if (n < 0) return `<span class="dev-chip dev-neg">${n}</span>`;
  if (n > 0) return `<span class="dev-chip dev-pos">+${n}</span>`;
  return `<span class="dev-chip dev-zero">0</span>`;
}
function ago(ts) {
  const diff = Math.floor((Date.now() - new Date(ts).getTime()) / 1000);
  if (diff < 60)   return diff + 's ago';
  if (diff < 3600) return Math.floor(diff/60) + 'm ago';
  return Math.floor(diff/3600) + 'h ' + Math.floor((diff%3600)/60) + 'm ago';
}

// ── Fetch & render ────────────────────────────────────────────────────────────
function fetchAndRender() {
  fetch(API_DETECTIONS + '?limit=100')
    .then(r => r.json())
    .then(data => {
      if (!data.success) return;
      allDetections = data.detections || [];
      renderStats();
      renderRoomTable();
      renderEventLog();
      renderAlerts();
      renderTimeline();
      const active = allDetections.filter(d => ['pending','potential','confirmed_missing'].includes(d.status));
      document.getElementById('notifDot').style.display = active.length ? '' : 'none';
    })
    .catch(() => {});
}

function renderStats() {
  const today     = new Date().toISOString().slice(0,10);
  const active    = allDetections.filter(d => ['pending','potential','confirmed_missing'].includes(d.status));
  const pending   = allDetections.filter(d => ['pending','potential'].includes(d.status));
  const todayAll  = allDetections.filter(d => (d.detected_at||'').startsWith(today));
  const confirmed = allDetections.filter(d => d.status === 'confirmed_missing');

  // Count unique rooms from monitoring schema seed — 8 active rooms
  document.getElementById('statRooms').textContent   = 8;
  document.getElementById('statMissing').textContent  = active.length;
  document.getElementById('statPending').textContent  = pending.length;
  document.getElementById('statToday').textContent    = todayAll.length;

  document.getElementById('statMissingDelta').innerHTML =
    confirmed.length ? `<i class="fa-solid fa-triangle-exclamation"></i> ${confirmed.length} confirmed missing` :
    `<i class="fa-solid fa-arrow-right"></i> All under review`;
  document.getElementById('statTodayDelta').innerHTML =
    `<i class="fa-solid fa-calendar-day"></i> ${todayAll.filter(d=>d.status==='recovered').length} resolved`;
}

function renderRoomTable() {
  // Group active (non-resolved) detections by room_id — take the worst status per room
  const roomMap = {};
  allDetections.forEach(d => {
    if (['dismissed','recovered'].includes(d.status)) return;
    if (!roomMap[d.room_id] || statusWeight(d.status) > statusWeight(roomMap[d.room_id].status)) {
      roomMap[d.room_id] = d;
    }
  });

  const rooms = [
    { id:'MLH306', name:'Systems & App Dev Lab',         baseline:30 },
    { id:'MLH305', name:'Logic and Algorithms Lab',       baseline:30 },
    { id:'MLH304', name:'Engineering CAD Lab',            baseline:25 },
    { id:'MLH303', name:'Advanced Programming Lab',       baseline:30 },
    { id:'MLH301', name:'Embedded Systems Lab',           baseline:18 },
    { id:'MLH203', name:'Computational Engineering Lab',  baseline:20 },
    { id:'MLH201', name:'Structured / Data Center Lab',   baseline:22 },
    { id:'MLH401', name:'Architectural CAD Lab',          baseline:30, offline:true },
  ];

  const body = document.getElementById('roomTableBody');
  body.innerHTML = rooms.map(rm => {
    if (rm.offline) {
      return `<tr>
        <td><div class="col-id">${rm.id}</div><div class="col-sub">${escHtml(rm.name)}</div></td>
        <td><span class="col-mono" style="color:var(--text-dim);">— / ${rm.baseline}</span></td>
        <td><span class="dev-chip" style="background:var(--bg-base);color:var(--text-dim);">—</span></td>
        <td><span class="badge badge-muted"><span class="bdot"></span>OFFLINE</span></td>
        <td><span class="col-mono">—</span></td>
        <td><span class="col-mono" style="color:var(--text-dim);">No feed</span></td>
        <td><button class="btn btn-sm">Setup</button></td>
      </tr>`;
    }
    const det = roomMap[rm.id];
    const live = det ? rm.baseline + (det.live_count - det.baseline_count) : rm.baseline;
    const dev  = det ? (det.live_count - det.baseline_count) : 0;
    const st   = det ? det.status : 'normal';

    return `<tr>
      <td><div class="col-id">${rm.id}</div><div class="col-sub">${escHtml(rm.name)}</div></td>
      <td><span style="font-family:var(--font-mono);font-size:.88rem;font-weight:600;">${live}</span><span class="col-mono"> / ${rm.baseline}</span></td>
      <td>${devChip(dev)}</td>
      <td><span class="badge ${badgeCls(st)}"><span class="bdot"></span>${badgeLabel(st)}</span></td>
      <td>${det ? `<span class="col-mono" style="font-size:.72rem;">${ago(det.detected_at)}</span>` : '<span class="col-mono">—</span>'}</td>
      <td><span class="col-mono">${det ? (det.detected_at||'').slice(11,19) : '—'}</span></td>
      <td>${det ? `<button class="btn btn-primary btn-sm" onclick="openEventModal(${det.detection_id})">Review</button>` : `<button class="btn btn-sm">View</button>`}</td>
    </tr>`;
  }).join('');
}

function statusWeight(st) {
  return { confirmed_missing:4, potential:3, pending:2, recovered:0, dismissed:0 }[st] || 1;
}

function renderEventLog() {
  let items = [...allDetections];
  if (logFilter !== 'all') items = items.filter(d => d.status === logFilter);
  items = items.slice(0, 20);

  const body = document.getElementById('eventLogBody');
  if (!items.length) {
    body.innerHTML = `<div style="padding:28px;text-align:center;color:var(--text-dim);font-size:.82rem;">No events${logFilter !== 'all' ? ' for this filter' : ''}.</div>`;
    return;
  }

  const stMap = { pending:'Pending', potential:'Potentially Lost', confirmed_missing:'Confirmed Missing', dismissed:'Dismissed', recovered:'Recovered' };
  const stCls = { pending:'est-pending', potential:'est-pending', confirmed_missing:'est-confirmed', dismissed:'est-dismissed', recovered:'est-recovered' };

  body.innerHTML = items.map(d => {
    const dev     = parseInt(d.deviation, 10);
    const devCls  = dev < 0 ? 'neg' : dev > 0 ? 'pos' : 'zero';
    const devStr  = dev < 0 ? `${dev} item${Math.abs(dev)>1?'s':''}` : dev > 0 ? `+${dev} item${dev>1?'s':''}` : '0';
    const canClick= !['dismissed','recovered'].includes(d.status);
    return `<div class="event-row${canClick?'':''}"${canClick?` onclick="openEventModal(${d.detection_id})"`:' style="opacity:.65;cursor:default;"'}>
      <div class="event-thumb">
        <svg width="52" height="40" style="position:absolute;inset:0;opacity:.7">
          ${dev < 0
            ? `<rect x="28" y="28" width="20" height="8" rx="1" fill="rgba(255,77,77,.12)" stroke="#ff4d4d" stroke-width="1" stroke-dasharray="2,2"/>`
            : dev > 0
              ? `<rect x="28" y="28" width="20" height="8" rx="1" fill="rgba(230,126,0,.12)" stroke="#e67e00" stroke-width="1.5"/>`
              : `<polyline points="14,22 22,29 38,14" stroke="#5cffac" stroke-width="2" fill="none" stroke-linecap="round"/>`
          }
        </svg>
      </div>
      <div class="event-body">
        <div class="event-tag">${escHtml(d.room_name || d.room_id)} · ${escHtml(d.object_zone || d.object_type || '—')}</div>
        <div class="event-title">${escHtml(d.object_type || 'Item')} deviation detected</div>
        <div class="event-meta">
          <span class="event-time">${escHtml((d.detected_at||'').slice(0,16).replace('T',' · '))}</span>
          <span class="event-dev ${devCls}">${devStr}</span>
          <span class="event-status-tag ${stCls[d.status]||'est-pending'}">${stMap[d.status]||d.status}</span>
        </div>
      </div>
    </div>`;
  }).join('');
}

function setLogFilter(f, el) {
  document.querySelectorAll('#tourEventLog .filter-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
  logFilter = f;
  renderEventLog();
}

function renderAlerts() {
  const active = allDetections
    .filter(d => ['pending','potential','confirmed_missing'].includes(d.status))
    .slice(0, 5);

  const body = document.getElementById('alertsFeed');
  if (!active.length) {
    body.innerHTML = `<div style="padding:20px;text-align:center;color:var(--text-dim);font-size:.82rem;">
      <i class="fa-solid fa-circle-check" style="color:var(--ok);"></i> No active alerts.</div>`;
    return;
  }

  const icoMap = { confirmed_missing:'fa-circle-minus', potential:'fa-clock', pending:'fa-clock' };
  const tMap   = { confirmed_missing:'alert', potential:'warn', pending:'warn' };

  body.innerHTML = active.map(d => `
    <div class="alert-item" onclick="openEventModal(${d.detection_id})" style="cursor:pointer;">
      <div class="alert-ico ${tMap[d.status]||'info'}"><i class="fa-solid ${icoMap[d.status]||'fa-circle-info'}"></i></div>
      <div class="alert-body">
        <strong>${escHtml(d.room_name || d.room_id)}</strong> — ${escHtml(d.object_type||'Item')} in ${escHtml(d.object_zone||'unknown zone')} (${stageLabel(d)})
        <span class="alert-ts">${(d.detected_at||'').slice(11,19)} · ${ago(d.detected_at)}</span>
      </div>
    </div>
  `).join('');
}

function renderTimeline() {
  const today = new Date().toISOString().slice(0,10);
  const items = allDetections
    .filter(d => (d.detected_at||'').startsWith(today))
    .slice(0, 8);

  const body = document.getElementById('timelineFeed');
  if (!items.length) {
    body.innerHTML = `<div style="padding:20px;text-align:center;color:var(--text-dim);font-size:.82rem;">No events yet today.</div>`;
    return;
  }

  const tMap  = { confirmed_missing:'alert', potential:'warn', pending:'warn', recovered:'ok', dismissed:'info' };
  body.innerHTML = items.map(d => `
    <div class="timeline-item">
      <div class="tl-dot ${tMap[d.status]||'info'}"></div>
      <div>
        <div class="tl-label"><strong>${escHtml(d.room_name||d.room_id)}</strong> — ${escHtml(d.object_type||'Item')} deviation (${escHtml(d.object_zone||'—')})</div>
        <div class="tl-meta">${(d.detected_at||'').slice(11,19)} · ${stageLabel(d)}</div>
      </div>
    </div>
  `).join('');
}

// ── Event modal ───────────────────────────────────────────────────────────────
function openEventModal(id) {
  const d = allDetections.find(x => x.detection_id === id);
  modalDetId = id;
  if (!d) return;

  const dev    = parseInt(d.deviation, 10);
  const devStr = dev < 0 ? `${d.live_count} items (${dev})` : dev > 0 ? `${d.live_count} items (+${dev})` : `${d.live_count} items`;
  const liveCls= dev < 0 ? 'alert' : dev > 0 ? 'warn' : '';

  document.getElementById('modalTitle').textContent = `Detection Event — ${d.room_name||d.room_id}`;
  document.getElementById('snapRoom').textContent   = `CAM-01 · ${d.room_id}`;
  document.getElementById('snapTs').textContent     = d.detected_at || '—';
  document.getElementById('alertLabel').textContent = `${d.object_zone||d.object_type||'ZONE'} ⚠`;
  document.getElementById('dRoom').textContent      = d.room_name || d.room_id;
  document.getElementById('dTime').textContent      = d.detected_at || '—';
  document.getElementById('dBaseline').textContent  = (d.baseline_count||0) + ' items';
  document.getElementById('dLive').textContent      = devStr;
  document.getElementById('dLive').className        = 'detail-val ' + liveCls;
  document.getElementById('dZone').textContent      = d.object_zone || d.object_type || '—';
  document.getElementById('dDuration').textContent  = ago(d.detected_at) + ' — ' + stageLabel(d);
  document.getElementById('dDuration').className    = 'detail-val ' + (liveCls||'');
  document.getElementById('dRoi').textContent       = (d.roi_change_pct ?? '—') + '%';
  document.getElementById('dStatus').textContent    = stageLabel(d);
  document.getElementById('dStatus').className      = 'detail-val ' + (liveCls||'');
  document.getElementById('modalNotes').value       = '';
  openModal('eventModal');
}

function markEvent(newStatus) {
  if (!modalDetId) return;
  const notes = (document.getElementById('modalNotes').value || '').trim();
  const body  = new URLSearchParams({ detection_id: modalDetId, status: newStatus, notes });

  fetch('../auth/update_event_status.php', { method:'POST', body })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        const msgs = {
          dismissed:'Event dismissed as false alert.',
          recovered:'Item marked as recovered. Record updated.',
          confirmed_missing:'Event confirmed as missing. Staff notified.',
        };
        showToast(
          newStatus==='recovered' ? 'success' : newStatus==='dismissed' ? 'warn' : 'error',
          msgs[newStatus] || 'Status updated.'
        );
        closeModal('eventModal');
        modalDetId = null;
        fetchAndRender();
      } else {
        showToast('error', data.message || 'Update failed.');
      }
    })
    .catch(() => showToast('error', 'Network error.'));
}

// ── Boot ──────────────────────────────────────────────────────────────────────
fetchAndRender();
setInterval(fetchAndRender, POLL_INTERVAL_MS);
</script>

<!-- First-Time Onboarding Tour — Admin Dashboard -->
<script>
window.SPOTIT_USER_ROLE = 'admin';
window.SPOTIT_TOUR_STEPS = [
  {
    target: '#sidebar',
    icon: 'fa-solid fa-compass',
    title: 'Your Navigation Hub',
    desc: 'Everything lives here — room monitoring, alerts, lost &amp; found, claims, and admin management tools.',
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
    desc: 'Four key numbers: rooms monitored, active deviations, pending validation, and total events today.',
    placement: 'bottom',
  },
  {
    target: '#tourRoomTable',
    icon: 'fa-solid fa-door-open',
    title: 'Room Status Table',
    desc: 'See every monitored room\'s live item count vs. baseline. Click <strong>Review</strong> to investigate an event.',
    placement: 'top',
  },
  {
    target: '#tourEventLog',
    icon: 'fa-solid fa-clock-rotate-left',
    title: 'Detection Event Log',
    desc: 'A running history of every detection. Use filter tabs to narrow by Pending, Confirmed, Dismissed, or Recovered.',
    placement: 'top',
  },
  {
    target: '#tourAlerts',
    icon: 'fa-solid fa-bell-ring',
    title: 'Active Alerts Feed',
    desc: 'Your priority inbox. The most urgent, unresolved alerts appear here first.',
    placement: 'left',
  },
  {
    target: '#tourNotifBtn',
    icon: 'fa-solid fa-bell',
    title: 'Notification Bell',
    desc: 'Quick access to system notifications. The red dot means there\'s something new to check.',
    placement: 'bottom',
  },
];
</script>
<script src="../assets/js/onboarding.js"></script>
</body>
</html>
