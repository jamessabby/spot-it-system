/**
 * S.P.O.T.-IT — Shared JS Utilities
 * assets/js/spotit.js
 *
 * Included on every dashboard page.
 * Provides: theme toggle, toast, timers, modals, sidebar toggle,
 *           badge polling, shared notification panel controller.
 */

/* ══════════════════════════════════════
   THEME
══════════════════════════════════════ */
function toggleTheme() {
  const html = document.documentElement;
  const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  html.setAttribute('data-theme', next);
  localStorage.setItem('spotit_theme', next);
}
(function () {
  const t = localStorage.getItem('spotit_theme') || 'light';
  document.documentElement.setAttribute('data-theme', t);
})();


/* ══════════════════════════════════════
   TOAST
══════════════════════════════════════ */
function showToast(type, msg, duration = 4500) {
  let stack = document.getElementById('toastStack');
  if (!stack) {
    stack = document.createElement('div');
    stack.id = 'toastStack';
    stack.className = 'toast-stack';
    document.body.appendChild(stack);
  }
  const icons = {
    error:   'fa-circle-exclamation',
    success: 'fa-circle-check',
    warn:    'fa-triangle-exclamation',
    info:    'fa-circle-info',
  };
  const t = document.createElement('div');
  t.className = `spotit-toast t-${type}`;
  t.innerHTML = `<i class="fa-solid ${icons[type] || 'fa-circle-info'} t-icon"></i>
                 <div class="t-text">${msg}</div>`;
  stack.appendChild(t);
  setTimeout(() => {
    t.style.opacity = '0';
    t.style.transition = 'opacity .3s';
    setTimeout(() => t.remove(), 320);
  }, duration);
}


/* ══════════════════════════════════════
   LIVE CLOCK
══════════════════════════════════════ */
function startLiveClock(elementId = 'liveClock') {
  function tick() {
    const el = document.getElementById(elementId);
    if (el) el.textContent = new Date().toLocaleTimeString('en-GB', { hour12: false });
  }
  tick();
  setInterval(tick, 1000);
}


/* ══════════════════════════════════════
   COUNTUP TIMERS  (elapsed since a start time)
   Usage: startCountup('my-timer-id', '2026-06-15 14:03:44');
══════════════════════════════════════ */
const _countupIntervals = {};

function startCountup(elementId, startISOString) {
  const startMs = new Date(startISOString.replace(' ', 'T')).getTime();
  if (isNaN(startMs)) return;
  function update() {
    const el = document.getElementById(elementId);
    if (!el) { clearInterval(_countupIntervals[elementId]); return; }
    const elapsed = Math.max(0, Math.floor((Date.now() - startMs) / 1000));
    const h = String(Math.floor(elapsed / 3600)).padStart(2, '0');
    const m = String(Math.floor((elapsed % 3600) / 60)).padStart(2, '0');
    const s = String(elapsed % 60).padStart(2, '0');
    el.textContent = `${h}:${m}:${s}`;
    const mins = elapsed / 60;
    el.className = 'countdown ' + (mins >= 60 ? 'alert' : mins >= 30 ? 'warn' : 'ok');
  }
  update();
  _countupIntervals[elementId] = setInterval(update, 1000);
}

function stopCountup(elementId) {
  clearInterval(_countupIntervals[elementId]);
}


/* ══════════════════════════════════════
   MODAL
══════════════════════════════════════ */
function openModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.add('open');
}
function closeModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.remove('open');
}
document.addEventListener('click', function (e) {
  if (e.target.classList.contains('modal-overlay')) e.target.classList.remove('open');
});
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape')
    document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
});


/* ══════════════════════════════════════
   SIDEBAR TOGGLE (mobile)
══════════════════════════════════════ */
function toggleSidebar() {
  const sb = document.getElementById('sidebar');
  if (sb) sb.classList.toggle('open');
}
document.addEventListener('click', function (e) {
  const sb  = document.getElementById('sidebar');
  const btn = document.getElementById('hamburgerBtn');
  if (sb && sb.classList.contains('open')) {
    if (!sb.contains(e.target) && e.target !== btn && !btn?.contains(e.target))
      sb.classList.remove('open');
  }
});


/* ══════════════════════════════════════
   FILTER TABS
══════════════════════════════════════ */
function setFilterTab(el) {
  el.closest('.filter-tabs').querySelectorAll('.filter-tab').forEach(t => t.classList.remove('active'));
  el.classList.add('active');
}


/* ══════════════════════════════════════
   FETCH HELPER
══════════════════════════════════════ */
async function spotitFetch(url, opts = {}) {
  try {
    const res = await fetch(url, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      ...opts,
    });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return await res.json();
  } catch (err) {
    console.error('[S.P.O.T.-IT fetch]', err);
    return null;
  }
}


/* ══════════════════════════════════════
   TIME AGO HELPER
══════════════════════════════════════ */
function spotitTimeAgo(dateStr) {
  const diff = Math.floor((Date.now() - new Date(dateStr.replace(' ', 'T'))) / 1000);
  if (diff < 5)    return 'just now';
  if (diff < 60)   return diff + 's ago';
  if (diff < 3600) return Math.floor(diff / 60) + 'm ago';
  if (diff < 86400)return Math.floor(diff / 3600) + 'h ago';
  if (diff < 604800)return Math.floor(diff / 86400) + 'd ago';
  return new Date(dateStr.replace(' ', 'T')).toLocaleDateString('en-PH', { month: 'short', day: 'numeric' });
}


/* ══════════════════════════════════════
   NOTIFICATION TYPE METADATA
   Centralised so panel + page use the same config.
══════════════════════════════════════ */
const NOTIF_META = {
  // Detection events
  potential_lost:     { icon: 'fa-clock',                  color: 'warn',  label: 'Potentially Lost',    url: 'inventory-monitor.php' },
  confirmed_missing:  { icon: 'fa-triangle-exclamation',   color: 'alert', label: 'Confirmed Missing',   url: 'inventory-monitor.php' },
  auto_escalation:    { icon: 'fa-arrow-up-right-dots',    color: 'warn',  label: 'Auto-Escalated',      url: 'inventory-monitor.php' },
  // Acknowledgment / validation
  detection_verified: { icon: 'fa-circle-check',           color: 'ok',    label: 'Detection Verified',  url: 'dashboard-admin.php'   },
  detection_rejected: { icon: 'fa-ban',                    color: 'dim',   label: 'False Alarm',         url: 'dashboard-admin.php'   },
  // Item recovery
  item_recovered:     { icon: 'fa-box-open',               color: 'ok',    label: 'Item Recovered',      url: 'lost-thread.php'       },
  // Claims
  new_claim:          { icon: 'fa-hand-holding',           color: 'info',  label: 'New Claim',           url: 'claiming-station.php'  },
  claim_approved:     { icon: 'fa-star',                   color: 'ok',    label: 'Claim Approved',      url: 'claiming-station.php'  },
  claim_rejected:     { icon: 'fa-circle-xmark',           color: 'alert', label: 'Claim Rejected',      url: 'lost-thread.php'       },
  claim_completed:    { icon: 'fa-circle-check',           color: 'ok',    label: 'Claim Completed',     url: 'claiming-station.php'  },
  // Announcements
  new_announcement:   { icon: 'fa-bullhorn',               color: 'green', label: 'New Announcement',    url: 'announcements.php'     },
};

function _notifMeta(type) {
  return NOTIF_META[type] || { icon: 'fa-bell', color: 'info', label: 'Notification', url: '#' };
}


/* ══════════════════════════════════════
   BADGE POLLING — every 10s
   Updates sidebar badges AND topbar bell dot.
══════════════════════════════════════ */
async function pollBadges() {
  // Detection summary badges (admin/staff sidebar)
  const detData = await spotitFetch('../auth/get_detections.php?summary=1');
  if (detData) {
    _setBadge('sb-badge-alerts', detData.confirmed_missing);
    _setBadge('sb-badge-warn',   detData.potential_lost);
    _setBadge('sb-badge-claims', detData.pending_claims);
  }

  // Notification unread count → topbar bell dot + count badge
  const notifData = await spotitFetch('../auth/get_notifications.php?unread=1');
  if (notifData) {
    const count = notifData.unread_count || 0;
    // Topbar bell dot (all dashboards)
    ['notifDot', 'notifDotStaff', 'notifDotStudent'].forEach(id => {
      const dot = document.getElementById(id);
      if (dot) dot.style.display = count > 0 ? '' : 'none';
    });
    // Topbar bell count badge (notifications page uses this)
    const bellCount = document.getElementById('bellUnreadCount');
    if (bellCount) {
      bellCount.textContent = count;
      bellCount.style.display = count > 0 ? '' : 'none';
    }
    // Update open panel header count if panel is open
    const panelCount = document.getElementById('notifCount');
    if (panelCount) {
      panelCount.textContent = count;
      panelCount.style.display = count > 0 ? '' : 'none';
    }
  }

  // Sidebar notifications badge
  _setBadge('sb-badge-notif', (notifData?.unread_count || 0));

  // Announcement unread badge
  const annData = await spotitFetch('../auth/get_announcements.php?unread=1');
  if (annData) {
    const annBadge = document.getElementById('sb-badge-ann');
    if (annBadge) {
      annBadge.textContent = annData.unread_count || 0;
      annBadge.style.display = annData.unread_count > 0 ? '' : 'none';
      if (annData.unread_count > 0) annBadge.classList.add('sb-badge-new');
    }
  }
}

function _setBadge(id, count) {
  const el = document.getElementById(id);
  if (!el) return;
  if (count > 0) { el.textContent = count; el.style.display = ''; }
  else el.style.display = 'none';
}

if (document.getElementById('sidebar')) {
  pollBadges();
  setInterval(pollBadges, 10000);
}


/* ══════════════════════════════════════
   SHARED NOTIFICATION PANEL CONTROLLER
   Works with any page that has #notifPanel + #notifList.
   All dashboards and the dedicated notifications page use this.
══════════════════════════════════════ */
window._notifPanelOpen = false;

function toggleNotifPanel() {
  const panel    = document.getElementById('notifPanel');
  const backdrop = document.getElementById('notifBackdrop');
  if (!panel) return;
  window._notifPanelOpen = !window._notifPanelOpen;
  panel.style.right      = window._notifPanelOpen ? '0' : '-400px';
  if (backdrop) backdrop.style.display = window._notifPanelOpen ? 'block' : 'none';
  if (window._notifPanelOpen) loadNotifPanel();
}

async function loadNotifPanel(typeFilter) {
  const list   = document.getElementById('notifList');
  const empty  = document.getElementById('notifEmpty');
  const loader = document.getElementById('notifLoader');
  if (!list) return;

  if (loader) loader.style.display = '';
  list.style.opacity = '.5';

  const url = '../auth/get_notifications.php?limit=30' + (typeFilter ? '&type=' + typeFilter : '');
  const data = await spotitFetch(url);
  if (loader) loader.style.display = 'none';
  list.style.opacity = '1';

  if (!data || !data.success) return;

  // Update badge counts
  const count    = data.unread_count || 0;
  const countEl  = document.getElementById('notifCount');
  if (countEl) { countEl.textContent = count; countEl.style.display = count > 0 ? '' : 'none'; }
  ['notifDot','notifDotStaff','notifDotStudent'].forEach(id => {
    const d = document.getElementById(id);
    if (d) d.style.display = count > 0 ? '' : 'none';
  });

  if (!data.notifications.length) {
    if (empty) empty.style.display = '';
    list.innerHTML = '';
    return;
  }
  if (empty) empty.style.display = 'none';

  list.innerHTML = data.notifications.map(n => _renderNotifRow(n)).join('');
}

function _renderNotifRow(n) {
  const meta   = _notifMeta(n.type);
  const unread = !parseInt(n.is_read);
  const ago    = spotitTimeAgo(n.created_at);
  const colorVar = {
    ok: 'var(--ok)', alert: 'var(--alert)', warn: 'var(--warn)',
    info: 'var(--info)', green: 'var(--green-main)', dim: 'var(--text-dim)',
  }[meta.color] || 'var(--info)';

  return `<div class="notif-row ${unread ? 'notif-unread' : ''}"
               onclick="handleNotifClick(${n.notification_id}, '${n.action_url || meta.url}', this)"
               role="button" tabindex="0">
    <div class="notif-icon-wrap" style="background:${colorVar}1a;border:1px solid ${colorVar}33;">
      <i class="fa-solid ${meta.icon}" style="color:${colorVar};font-size:.82rem;"></i>
    </div>
    <div class="notif-text-wrap">
      <div class="notif-title">${_esc(n.title)}</div>
      <div class="notif-body">${_esc(n.body)}</div>
      <div class="notif-meta">
        <span class="notif-type-chip" style="color:${colorVar};">${meta.label}</span>
        <span class="notif-ago">${ago}</span>
        ${n.room_id ? `<span class="notif-room">${_esc(n.room_id)}</span>` : ''}
      </div>
    </div>
    ${unread ? '<div class="notif-unread-dot"></div>' : ''}
  </div>`;
}

function _esc(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g,'&amp;').replace(/</g,'&lt;')
    .replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

async function handleNotifClick(id, url, el) {
  // Mark as read immediately
  el.classList.remove('notif-unread');
  el.querySelector('.notif-unread-dot')?.remove();
  await spotitFetch('../auth/mark_notifications_read.php', {
    method: 'POST',
    body: new URLSearchParams({ notification_id: id }),
  });
  // Navigate if url provided
  if (url && url !== '#') {
    setTimeout(() => { window.location.href = url; }, 120);
  }
}

async function markAllNotifsRead() {
  await spotitFetch('../auth/mark_notifications_read.php', {
    method: 'POST',
    body: new URLSearchParams({ all: '1' }),
  });
  document.querySelectorAll('.notif-row').forEach(r => {
    r.classList.remove('notif-unread');
    r.querySelector('.notif-unread-dot')?.remove();
  });
  const countEl = document.getElementById('notifCount');
  if (countEl) countEl.style.display = 'none';
  ['notifDot','notifDotStaff','notifDotStudent'].forEach(id => {
    const d = document.getElementById(id);
    if (d) d.style.display = 'none';
  });
  showToast('success', 'All notifications marked as read.');
}
