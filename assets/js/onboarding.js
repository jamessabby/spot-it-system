/**
 * S.P.O.T.-IT — First-Time Onboarding Tour
 * assets/js/onboarding.js
 *
 * Highlights key dashboard elements one by one with a tooltip popup.
 * Auto-runs only on a user's first login (checked via auth/get_tour_status.php,
 * with localStorage as an instant client-side fallback/cache).
 *
 * Each page defines its own step list via: window.SPOTIT_TOUR_STEPS = [...]
 * before including this script. If undefined, the tour does not run.
 *
 * Step shape:
 *   {
 *     target: '#cssSelector',      // element to highlight
 *     title:  'Step title',
 *     desc:   'Explanation text',
 *     icon:   'fa-solid fa-gauge-high',
 *     placement: 'bottom' | 'top' | 'left' | 'right'  (optional, auto if omitted)
 *   }
 */

(function () {
  const STORAGE_KEY = 'spotit_tour_completed';
  const ROLE_KEY     = (window.SPOTIT_USER_ROLE || 'guest');
  const TOUR_ID       = `${STORAGE_KEY}_${ROLE_KEY}`;

  let steps = [];
  let currentIndex = 0;
  let overlayEl, spotlightEl, tooltipEl;

  /* ══════════════════════════════════════
     Persistence — localStorage cache + server sync
  ══════════════════════════════════════ */
  function hasCompletedLocally() {
    return localStorage.getItem(TOUR_ID) === '1';
  }
  function markCompletedLocally() {
    localStorage.setItem(TOUR_ID, '1');
  }
  function clearCompletedLocally() {
    localStorage.removeItem(TOUR_ID);
  }

  async function checkServerStatus() {
    // Server is the source of truth; localStorage is just a fast first-paint cache.
    try {
      const res = await fetch('../auth/get_tour_status.php', {
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      });
      if (!res.ok) return null;
      const data = await res.json();
      return !!data.completed;
    } catch (e) {
      return null; // fall back to localStorage-only behavior
    }
  }

  async function persistCompletion() {
    markCompletedLocally();
    try {
      await fetch('../auth/save_tour_status.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'completed=1',
      });
    } catch (e) {
      // Non-blocking — localStorage already has it
    }
  }

  /* ══════════════════════════════════════
     DOM construction
  ══════════════════════════════════════ */
  function buildDOM() {
    overlayEl = document.createElement('div');
    overlayEl.className = 'tour-overlay';
    document.body.appendChild(overlayEl);

    spotlightEl = document.createElement('div');
    spotlightEl.className = 'tour-spotlight';
    spotlightEl.style.display = 'none';
    document.body.appendChild(spotlightEl);

    tooltipEl = document.createElement('div');
    tooltipEl.className = 'tour-tooltip';
    document.body.appendChild(tooltipEl);
  }

  function buildWelcomeModal() {
    const wrap = document.createElement('div');
    wrap.className = 'tour-welcome-overlay';
    wrap.id = 'tourWelcomeOverlay';
    wrap.innerHTML = `
      <div class="tour-welcome-box">
        <div class="tour-welcome-icon"><i class="fa-solid fa-route"></i></div>
        <div class="tour-welcome-title">Welcome to S.P.O.T.-IT!</div>
        <div class="tour-welcome-desc">
          Let's take a quick 60-second tour of your dashboard so you know exactly
          where everything is — room monitoring, alerts, and the lost &amp; found system.
        </div>
        <div class="tour-welcome-actions">
          <button class="tour-btn" onclick="SpotitTour.skip()">Skip for now</button>
          <button class="tour-btn tour-btn-primary" onclick="SpotitTour.startFromWelcome()">
            <i class="fa-solid fa-play"></i> Start Tour
          </button>
        </div>
      </div>
    `;
    document.body.appendChild(wrap);
    return wrap;
  }

  /* ══════════════════════════════════════
     Positioning logic
  ══════════════════════════════════════ */
  function positionSpotlight(target) {
    const rect = target.getBoundingClientRect();
    const pad = 8;
    spotlightEl.style.display = 'block';
    spotlightEl.style.top    = (rect.top - pad) + 'px';
    spotlightEl.style.left   = (rect.left - pad) + 'px';
    spotlightEl.style.width  = (rect.width + pad * 2) + 'px';
    spotlightEl.style.height = (rect.height + pad * 2) + 'px';
    return rect;
  }

  function positionTooltip(rect, placement) {
    tooltipEl.classList.remove('visible');
    // Measure tooltip after content set, before final positioning
    const ttRect = tooltipEl.getBoundingClientRect();
    const gap = 16;
    let top, left, arrowPos;

    const spaceBelow = window.innerHeight - rect.bottom;
    const spaceAbove = rect.top;
    const spaceRight = window.innerWidth - rect.right;

    let finalPlacement = placement;
    if (!finalPlacement) {
      if (spaceBelow > ttRect.height + gap) finalPlacement = 'bottom';
      else if (spaceAbove > ttRect.height + gap) finalPlacement = 'top';
      else if (spaceRight > ttRect.width + gap) finalPlacement = 'right';
      else finalPlacement = 'left';
    }

    switch (finalPlacement) {
      case 'bottom':
        top  = rect.bottom + gap;
        left = Math.min(Math.max(8, rect.left), window.innerWidth - ttRect.width - 8);
        arrowPos = 'bottom';
        break;
      case 'top':
        top  = rect.top - ttRect.height - gap;
        left = Math.min(Math.max(8, rect.left), window.innerWidth - ttRect.width - 8);
        arrowPos = 'top';
        break;
      case 'right':
        top  = Math.min(Math.max(8, rect.top), window.innerHeight - ttRect.height - 8);
        left = rect.right + gap;
        arrowPos = 'right';
        break;
      case 'left':
      default:
        top  = Math.min(Math.max(8, rect.top), window.innerHeight - ttRect.height - 8);
        left = rect.left - ttRect.width - gap;
        arrowPos = 'left';
        break;
    }

    tooltipEl.style.top  = top + 'px';
    tooltipEl.style.left = left + 'px';

    const arrow = tooltipEl.querySelector('.tour-arrow');
    if (arrow) {
      arrow.className = 'tour-arrow pos-' + arrowPos;
      if (arrowPos === 'bottom' || arrowPos === 'top') {
        const arrowLeft = Math.min(Math.max(20, rect.left + rect.width/2 - left - 7), ttRect.width - 28);
        arrow.style.left = arrowLeft + 'px';
      } else {
        const arrowTop = Math.min(Math.max(20, rect.top + rect.height/2 - top - 7), ttRect.height - 28);
        arrow.style.top = arrowTop + 'px';
      }
    }

    requestAnimationFrame(() => tooltipEl.classList.add('visible'));
  }

  /* ══════════════════════════════════════
     Step rendering
  ══════════════════════════════════════ */
  function renderStep(index) {
    const step = steps[index];
    const target = document.querySelector(step.target);

    if (!target) {
      // Target not found on this page — skip to next step gracefully
      if (index < steps.length - 1) renderStep(index + 1);
      else endTour(true);
      return;
    }

    target.scrollIntoView({ behavior: 'smooth', block: 'center' });

    // Slight delay to let scroll settle before measuring position
    setTimeout(() => {
      const rect = positionSpotlight(target);

      const isFirst = index === 0;
      const isLast  = index === steps.length - 1;

      tooltipEl.innerHTML = `
        <div class="tour-arrow"></div>
        <div class="tour-header">
          <div class="tour-icon"><i class="${step.icon || 'fa-solid fa-circle-info'}"></i></div>
          <div>
            <div class="tour-step-badge">Step ${index + 1} of ${steps.length}</div>
          </div>
          <button class="tour-close" onclick="SpotitTour.skip()" aria-label="Close tour">
            <i class="fa-solid fa-xmark"></i>
          </button>
        </div>
        <div class="tour-body">
          <div class="tour-title">${step.title}</div>
          <div class="tour-desc">${step.desc}</div>
        </div>
        <div class="tour-progress">
          ${steps.map((s, i) => `<div class="tour-dot ${i < index ? 'done' : i === index ? 'active' : ''}"></div>`).join('')}
        </div>
        <div class="tour-footer">
          <button class="tour-skip" onclick="SpotitTour.skip()">Skip Tutorial</button>
          <div class="tour-nav-btns">
            <button class="tour-btn" id="tourPrevBtn" onclick="SpotitTour.prev()" ${isFirst ? 'disabled' : ''}>
              <i class="fa-solid fa-arrow-left"></i> Previous
            </button>
            <button class="tour-btn tour-btn-primary" onclick="SpotitTour.next()">
              ${isLast ? '<i class="fa-solid fa-check"></i> Finish' : 'Next <i class="fa-solid fa-arrow-right"></i>'}
            </button>
          </div>
        </div>
      `;

      positionTooltip(rect, step.placement);
    }, 280);
  }

  /* ══════════════════════════════════════
     Public control API
  ══════════════════════════════════════ */
  window.SpotitTour = {
    init(stepList) {
      steps = stepList || window.SPOTIT_TOUR_STEPS || [];
      if (!steps.length) return;

      checkServerStatus().then(serverCompleted => {
        const completed = serverCompleted !== null ? serverCompleted : hasCompletedLocally();
        if (completed) return;

        buildWelcomeModal();
        requestAnimationFrame(() => {
          document.getElementById('tourWelcomeOverlay').classList.add('active');
        });
      });
    },

    startFromWelcome() {
      const welcome = document.getElementById('tourWelcomeOverlay');
      if (welcome) { welcome.classList.remove('active'); setTimeout(() => welcome.remove(), 300); }
      this.start();
    },

    start() {
      if (!steps.length) return;
      currentIndex = 0;
      buildDOM();
      document.body.classList.add('tour-locked');
      requestAnimationFrame(() => overlayEl.classList.add('active'));
      renderStep(currentIndex);
      window.addEventListener('resize', this._reposition);
    },

    next() {
      if (currentIndex >= steps.length - 1) { this.finish(); return; }
      currentIndex++;
      tooltipEl.classList.remove('visible');
      renderStep(currentIndex);
    },

    prev() {
      if (currentIndex <= 0) return;
      currentIndex--;
      tooltipEl.classList.remove('visible');
      renderStep(currentIndex);
    },

    skip() {
      endTour(true);
      showTourToast('Tutorial skipped. You can replay it anytime from your account menu.');
    },

    finish() {
      endTour(true);
      showTourToast('Tutorial complete! You\'re all set.', 'success');
    },

    /** Manually re-trigger the tour, ignoring completion status (Settings > Replay Tour) */
    replay(stepList) {
      clearCompletedLocally();
      steps = stepList || window.SPOTIT_TOUR_STEPS || [];
      this.start();
    },

    _reposition() {
      if (!steps.length || !overlayEl || !overlayEl.classList.contains('active')) return;
      const step = steps[currentIndex];
      const target = document.querySelector(step.target);
      if (!target) return;
      const rect = positionSpotlight(target);
      positionTooltip(rect, step.placement);
    },
  };

  function endTour(persist) {
    document.body.classList.remove('tour-locked');
    window.removeEventListener('resize', window.SpotitTour._reposition);
    if (overlayEl)   overlayEl.classList.remove('active');
    if (spotlightEl) spotlightEl.style.display = 'none';
    if (tooltipEl)   tooltipEl.classList.remove('visible');
    setTimeout(() => {
      overlayEl?.remove();
      spotlightEl?.remove();
      tooltipEl?.remove();
    }, 300);
    if (persist) persistCompletion();
  }

  function showTourToast(msg, type = 'info') {
    if (typeof showToast === 'function') {
      showToast(type, msg);
    }
  }

  /* ══════════════════════════════════════
     Auto-init on page load if steps are defined
  ══════════════════════════════════════ */
  document.addEventListener('DOMContentLoaded', () => {
    if (!window.SPOTIT_TOUR_STEPS || !window.SPOTIT_TOUR_STEPS.length) return;

    const urlParams = new URLSearchParams(window.location.search);
    const forceReplay = urlParams.get('replay_tour') === '1';

    setTimeout(() => {
      if (forceReplay) {
        window.SpotitTour.replay();
        // Clean the URL so refresh doesn't re-trigger
        const cleanUrl = window.location.pathname;
        window.history.replaceState({}, document.title, cleanUrl);
      } else {
        window.SpotitTour.init();
      }
    }, 700);
  });
})();
