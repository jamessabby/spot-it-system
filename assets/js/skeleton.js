/**
 * S.P.O.T.-IT — Skeleton Loading System
 * assets/js/skeleton.js
 *
 * Renders a full-page skeleton overlay that mimics the real layout
 * (sidebar + topbar + stat cards + table/list) while the page or its
 * data is loading. Auto-fades out once content is ready.
 *
 * Usage: include this script before spotit.js. It auto-runs on DOMContentLoaded
 * and also exposes window.SpotitSkeleton for manual control during AJAX calls.
 */

(function () {
  // Page "type" controls which skeleton shape to render.
  // Set via <body data-skeleton="dashboard|form|legal|thread|none">
  function getPageType() {
    return document.body.getAttribute('data-skeleton') || 'dashboard';
  }

  function buildDashboardSkeleton() {
    return `
      <div class="sk-shell">
        <div class="sk-sidebar">
          <div class="sk-sidebar-brand">
            <div class="sk sk-circle-32"></div>
            <div class="sk-sidebar-brand-text">
              <div class="sk" style="width:80px;height:11px;"></div>
              <div class="sk" style="width:55px;height:8px;"></div>
            </div>
          </div>
          ${[1,2,3].map(() => `
            <div class="sk-nav-group">
              <div class="sk sk-nav-label"></div>
              <div class="sk sk-nav-item"></div>
              <div class="sk sk-nav-item"></div>
              <div class="sk sk-nav-item"></div>
            </div>
          `).join('')}
        </div>
        <div class="sk-main">
          <div class="sk-topbar">
            <div class="sk sk-topbar-title"></div>
            <div class="sk-topbar-right">
              <div class="sk sk-pill-sm"></div>
              <div class="sk sk-circle-34"></div>
              <div class="sk sk-circle-34"></div>
            </div>
          </div>
          <div class="sk-page-body">
            <div class="sk-stat-grid">
              ${[1,2,3,4].map(() => `
                <div class="sk-stat-card">
                  <div class="sk sk-stat-icon"></div>
                  <div class="sk-stat-text">
                    <div class="sk sk-stat-num"></div>
                    <div class="sk sk-stat-label"></div>
                  </div>
                </div>
              `).join('')}
            </div>
            <div class="sk-content-grid">
              <div class="sk-card">
                <div class="sk-card-head">
                  <div class="sk sk-card-title"></div>
                  <div class="sk sk-card-action"></div>
                </div>
                ${[1,2,3,4,5].map(() => `
                  <div class="sk-row">
                    <div class="sk sk-row-thumb"></div>
                    <div class="sk-row-lines">
                      <div class="sk sk-line-w70"></div>
                      <div class="sk sk-line-w40"></div>
                    </div>
                    <div class="sk sk-row-badge"></div>
                  </div>
                `).join('')}
              </div>
              <div class="sk-card">
                <div class="sk-card-head">
                  <div class="sk sk-card-title"></div>
                </div>
                ${[1,2,3,4].map(() => `
                  <div class="sk-list-item">
                    <div class="sk sk-list-icon"></div>
                    <div class="sk-list-text">
                      <div class="sk" style="width:95%;height:10px;"></div>
                      <div class="sk" style="width:60%;height:10px;"></div>
                      <div class="sk" style="width:35%;height:8px;"></div>
                    </div>
                  </div>
                `).join('')}
              </div>
            </div>
          </div>
        </div>
      </div>
      <div class="sk-loading-label"><span class="sk-loading-dot"></span> Loading S.P.O.T.-IT…</div>
    `;
  }

  function buildFormSkeleton() {
    return `
      <div class="sk-shell" style="align-items:center;justify-content:center;">
        <div class="sk-form-wrap">
          <div class="sk-form-card">
            <div class="sk sk-form-title"></div>
            <div class="sk sk-form-sub"></div>
            <div class="sk sk-form-btn"></div>
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:18px;">
              <div class="sk" style="flex:1;height:1px;"></div>
              <div class="sk" style="width:90px;height:8px;"></div>
              <div class="sk" style="flex:1;height:1px;"></div>
            </div>
            <div class="sk sk-form-field"></div>
            <div class="sk sk-form-field"></div>
            <div class="sk sk-form-btn" style="margin-top:8px;"></div>
          </div>
        </div>
      </div>
      <div class="sk-loading-label"><span class="sk-loading-dot"></span> Loading…</div>
    `;
  }

  function buildThreadSkeleton() {
    return `
      <div class="sk-shell">
        <div class="sk-sidebar">
          <div class="sk-sidebar-brand">
            <div class="sk sk-circle-32"></div>
            <div class="sk-sidebar-brand-text">
              <div class="sk" style="width:80px;height:11px;"></div>
              <div class="sk" style="width:55px;height:8px;"></div>
            </div>
          </div>
          ${[1,2].map(() => `
            <div class="sk-nav-group">
              <div class="sk sk-nav-label"></div>
              <div class="sk sk-nav-item"></div>
              <div class="sk sk-nav-item"></div>
            </div>
          `).join('')}
        </div>
        <div class="sk-main">
          <div class="sk-topbar"><div class="sk sk-topbar-title"></div></div>
          <div class="sk-page-body">
            <div class="sk" style="width:100%;height:42px;border-radius:9px;margin-bottom:18px;"></div>
            ${[1,2,3,4].map(() => `
              <div class="sk-thread-card">
                <div class="sk sk-thread-icon"></div>
                <div class="sk-thread-body">
                  <div class="sk" style="width:50%;height:14px;"></div>
                  <div class="sk" style="width:80%;height:10px;"></div>
                  <div class="sk" style="width:65%;height:10px;"></div>
                  <div style="display:flex;gap:8px;margin-top:4px;">
                    <div class="sk" style="width:90px;height:24px;border-radius:7px;"></div>
                    <div class="sk" style="width:90px;height:24px;border-radius:7px;"></div>
                  </div>
                </div>
              </div>
            `).join('')}
          </div>
        </div>
      </div>
      <div class="sk-loading-label"><span class="sk-loading-dot"></span> Loading items…</div>
    `;
  }

  function buildLegalSkeleton() {
    return `
      <div class="sk-shell" style="flex-direction:column;align-items:center;padding:60px 20px;overflow-y:auto;">
        <div style="width:100%;max-width:700px;">
          <div class="sk" style="width:140px;height:24px;border-radius:100px;margin-bottom:18px;"></div>
          <div class="sk" style="width:60%;height:32px;margin-bottom:30px;"></div>
          ${[1,2,3,4,5].map(() => `
            <div style="margin-bottom:26px;">
              <div class="sk" style="width:35%;height:16px;margin-bottom:12px;"></div>
              <div class="sk" style="width:100%;height:10px;margin-bottom:8px;"></div>
              <div class="sk" style="width:95%;height:10px;margin-bottom:8px;"></div>
              <div class="sk" style="width:80%;height:10px;"></div>
            </div>
          `).join('')}
        </div>
      </div>
      <div class="sk-loading-label"><span class="sk-loading-dot"></span> Loading…</div>
    `;
  }

  function buildSkeletonHTML(type) {
    switch (type) {
      case 'form':  return buildFormSkeleton();
      case 'thread':return buildThreadSkeleton();
      case 'legal': return buildLegalSkeleton();
      case 'none':  return '';
      default:      return buildDashboardSkeleton();
    }
  }

  function injectOverlay() {
    if (document.getElementById('skeletonOverlay')) return;
    const type = getPageType();
    if (type === 'none') return;

    const overlay = document.createElement('div');
    overlay.id = 'skeletonOverlay';
    overlay.className = 'skeleton-overlay';
    overlay.innerHTML = buildSkeletonHTML(type);
    // Insert as the very first element so it visually covers everything immediately
    document.body.insertBefore(overlay, document.body.firstChild);
  }

  function hideOverlay(minDelay) {
    const overlay = document.getElementById('skeletonOverlay');
    if (!overlay) return;
    const delay = typeof minDelay === 'number' ? minDelay : 0;
    setTimeout(() => {
      overlay.classList.add('fade-out');
      setTimeout(() => {
        overlay.classList.add('hidden');
        overlay.remove();
      }, 380);
    }, delay);
  }

  // Inject as early as possible (before DOMContentLoaded paints real content)
  injectOverlay();

  // Hide once page fully loads (images, fonts, etc.) — with a small minimum
  // display time so the skeleton doesn't "flash" on fast connections.
  const MIN_DISPLAY_MS = 450;
  const pageLoadStart = performance.now();

  function scheduleHide() {
    const elapsed = performance.now() - pageLoadStart;
    const remaining = Math.max(0, MIN_DISPLAY_MS - elapsed);
    hideOverlay(remaining);
  }

  if (document.readyState === 'complete') {
    scheduleHide();
  } else {
    window.addEventListener('load', scheduleHide);
  }

  // Safety net: never show skeleton longer than 4s even if something hangs
  setTimeout(() => hideOverlay(0), 4000);

  /* ══════════════════════════════════════════════════
     Public API — for manual control during AJAX/fetch swaps
     within a page (e.g. switching rooms, filtering tables)
  ══════════════════════════════════════════════════ */
  window.SpotitSkeleton = {
    show(type) {
      const existing = document.getElementById('skeletonOverlay');
      if (existing) return;
      const overlay = document.createElement('div');
      overlay.id = 'skeletonOverlay';
      overlay.className = 'skeleton-overlay';
      overlay.innerHTML = buildSkeletonHTML(type || getPageType());
      document.body.insertBefore(overlay, document.body.firstChild);
    },
    hide(delay) {
      hideOverlay(delay || 0);
    },
    /**
     * Inject inline component skeleton into a specific container
     * @param {string|HTMLElement} target - CSS selector or Element
     * @param {'rows'|'cards'|'stats'|'list'|'stream'} type
     * @param {number} count - number of placeholder items
     */
    showComponent(target, type = 'rows', count = 3) {
      const el = typeof target === 'string' ? document.querySelector(target) : target;
      if (!el) return;

      let html = '';
      const items = Array.from({ length: count });

      if (type === 'rows') {
        html = items.map(() => `
          <div class="sk-row sk-inline-block">
            <div class="sk sk-row-thumb"></div>
            <div class="sk-row-lines">
              <div class="sk sk-line-w70"></div>
              <div class="sk sk-line-w40"></div>
            </div>
            <div class="sk sk-row-badge"></div>
          </div>
        `).join('');
      } else if (type === 'cards' || type === 'thread') {
        html = items.map(() => `
          <div class="sk-thread-card sk-inline-block">
            <div class="sk sk-thread-icon"></div>
            <div class="sk-thread-body">
              <div class="sk" style="width:55%;height:14px;"></div>
              <div class="sk" style="width:85%;height:10px;"></div>
              <div class="sk" style="width:40%;height:10px;"></div>
            </div>
          </div>
        `).join('');
      } else if (type === 'stats') {
        html = items.map(() => `
          <div class="sk-stat-card sk-inline-block">
            <div class="sk sk-stat-icon"></div>
            <div class="sk-stat-text">
              <div class="sk sk-stat-num"></div>
              <div class="sk sk-stat-label"></div>
            </div>
          </div>
        `).join('');
      } else if (type === 'list') {
        html = items.map(() => `
          <div class="sk-list-item sk-inline-block">
            <div class="sk sk-list-icon"></div>
            <div class="sk-list-text">
              <div class="sk" style="width:90%;height:10px;"></div>
              <div class="sk" style="width:50%;height:8px;"></div>
            </div>
          </div>
        `).join('');
      } else if (type === 'stream') {
        html = `
          <div class="sk sk-inline-block" style="width:100%;height:320px;border-radius:12px;display:flex;align-items:center;justify-content:center;">
            <div class="sk-loading-label"><span class="sk-loading-dot"></span> Loading Stream...</div>
          </div>
        `;
      }

      el.setAttribute('data-sk-original', el.innerHTML);
      el.innerHTML = html;
      el.classList.add('sk-fade-in');
    },

    hideComponent(target) {
      const el = typeof target === 'string' ? document.querySelector(target) : target;
      if (!el) return;
      if (el.hasAttribute('data-sk-original')) {
        el.innerHTML = el.getAttribute('data-sk-original');
        el.removeAttribute('data-sk-original');
      }
      el.classList.remove('sk-fade-in');
    },

    /**
     * Wrap a target container with an inline shimmer skeleton while
     * an async function runs, then swap in the real content smoothly.
     * Usage: SpotitSkeleton.wrapAsync('#myTable', renderRowsHtml, fetchRows, 'rows');
     */
    async wrapAsync(selector, renderFn, fetchFn, componentType = 'rows', count = 3) {
      const el = typeof selector === 'string' ? document.querySelector(selector) : selector;
      if (!el) return;
      const original = el.innerHTML;
      
      this.showComponent(el, componentType, count);

      try {
        const data = await fetchFn();
        el.innerHTML = renderFn(data);
        el.classList.add('sk-fade-in');
        setTimeout(() => el.classList.remove('sk-fade-in'), 350);
      } catch (e) {
        el.innerHTML = original;
        console.error('[SpotitSkeleton.wrapAsync]', e);
      }
    }
  };
})();
