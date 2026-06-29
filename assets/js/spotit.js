/**
 * S.P.O.T.-IT — Shared JS Utilities
 * assets/js/spotit.js
 *
 * Included on every dashboard page.
 * Provides: theme toggle, toast, countdown timers, modal helpers, sidebar toggle.
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
// Apply immediately on load (also done inline in <head> for flash prevention)
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
  const icons = { error: 'fa-circle-exclamation', success: 'fa-circle-check', warn: 'fa-triangle-exclamation', info: 'fa-circle-info' };
  const t = document.createElement('div');
  t.className = `spotit-toast t-${type}`;
  t.innerHTML = `<i class="fa-solid ${icons[type] || 'fa-circle-info'} t-icon"></i><div class="t-text">${msg}</div>`;
  stack.appendChild(t);
  setTimeout(() => { t.style.opacity = '0'; t.style.transition = 'opacity .3s'; setTimeout(() => t.remove(), 320); }, duration);
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
   COUNTDOWN TIMERS  (count UP from a start time)
   Usage:
     startCountup('my-timer-id', '2026-06-15 14:03:44');
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

    // Auto-style based on elapsed
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
// Close on overlay click
document.addEventListener('click', function (e) {
  if (e.target.classList.contains('modal-overlay')) {
    e.target.classList.remove('open');
  }
});
// Close on Escape
document.addEventListener('keydown', function (e) {
  if (e.key === 'Escape') {
    document.querySelectorAll('.modal-overlay.open').forEach(m => m.classList.remove('open'));
  }
});


/* ══════════════════════════════════════
   SIDEBAR MOBILE TOGGLE
══════════════════════════════════════ */
function toggleSidebar() {
  const sb = document.getElementById('sidebar');
  if (sb) sb.classList.toggle('open');
}

// Close sidebar when clicking outside on mobile
document.addEventListener('click', function (e) {
  const sb = document.getElementById('sidebar');
  const btn = document.getElementById('hamburgerBtn');
  if (sb && sb.classList.contains('open')) {
    if (!sb.contains(e.target) && e.target !== btn && !btn?.contains(e.target)) {
      sb.classList.remove('open');
    }
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
   FETCH HELPER (wraps fetch + JSON)
══════════════════════════════════════ */
async function spotitFetch(url, opts = {}) {
  try {
    const res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, ...opts });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return await res.json();
  } catch (err) {
    console.error('[S.P.O.T.-IT fetch]', err);
    return null;
  }
}


/* ══════════════════════════════════════
   SIDEBAR BADGE UPDATER
   Polls get_detections.php every 10s for badge counts
══════════════════════════════════════ */
async function pollBadges() {
  const data = await spotitFetch('../auth/get_detections.php?summary=1');
  if (!data) return;

  const alertBadge = document.getElementById('sb-badge-alerts');
  const warnBadge  = document.getElementById('sb-badge-warn');
  const claimBadge = document.getElementById('sb-badge-claims');

  if (alertBadge && data.confirmed_missing > 0) {
    alertBadge.textContent = data.confirmed_missing;
    alertBadge.style.display = '';
  }
  if (warnBadge && data.potential_lost > 0) {
    warnBadge.textContent = data.potential_lost;
    warnBadge.style.display = '';
  }
  if (claimBadge && data.pending_claims > 0) {
    claimBadge.textContent = data.pending_claims;
    claimBadge.style.display = '';
  }
}
// Start polling on pages that have a sidebar
if (document.getElementById('sidebar')) {
  pollBadges();
  setInterval(pollBadges, 10000);
}
