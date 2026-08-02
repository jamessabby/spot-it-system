/* =============================================================
   S.P.O.T.-IT — Reusable Live Stream Viewer Library
   Enforces the Single RTSP Owner Pattern with Double Buffering
   ============================================================= */

function initLiveFrameStream(containerId, roomId) {
  const container = document.getElementById(containerId);
  if (!container) return;

  // Ensure double-buffered images exist inside the container
  let img1 = container.querySelector('#cam1Stream');
  let img2 = container.querySelector('#cam2Stream');
  let offlineOverlay = container.querySelector('#camOfflineOverlay');

  // Create image elements if they don't exist
  if (!img1) {
    img1 = document.createElement('img');
    img1.id = 'cam1Stream';
    img1.style.cssText = 'position:absolute; inset:0; width:100%; height:100%; object-fit:contain; opacity:0; z-index:1; border-radius:8px;';
    container.appendChild(img1);
  }
  if (!img2) {
    img2 = document.createElement('img');
    img2.id = 'cam2Stream';
    img2.style.cssText = 'position:absolute; inset:0; width:100%; height:100%; object-fit:contain; opacity:0; z-index:1; border-radius:8px;';
    container.appendChild(img2);
  }
  // ── Placeholder shown whenever there is no live frame ──────────────────────
  // The old version said "Daemon Offline (Standby)" over a dark green box, which
  // told an operator nothing about what had gone wrong or what to do about it.
  // It also decided which icon to show by string-matching the message text,
  // which broke the moment the wording changed.
  //
  // Now there are three explicit states, each saying what happened and what the
  // next action is. Styled with the dashboard's own theme variables so it works
  // in light and dark mode instead of being hardcoded near-black.
  if (!offlineOverlay) {
    offlineOverlay = document.createElement('div');
    offlineOverlay.id = 'camOfflineOverlay';
    offlineOverlay.style.cssText =
      'position:absolute; inset:0; display:none; flex-direction:column; align-items:center;' +
      'justify-content:center; gap:2px; text-align:center; padding:24px; border-radius:8px;' +
      'z-index:10; background:var(--bg-elevated,#12181f);' +
      'background-image:radial-gradient(circle at 50% 42%, rgba(255,255,255,.05), transparent 62%);';
    offlineOverlay.innerHTML = `
      <div class="ov-icon" style="width:62px; height:62px; border-radius:50%; display:flex;
           align-items:center; justify-content:center; margin-bottom:16px;
           background:var(--bg-base,rgba(255,255,255,.05));
           border:1px solid var(--border,rgba(255,255,255,.12));">
        <div class="ov-spin" style="width:26px; height:26px; border-radius:50%;
             border:2.5px solid var(--border,rgba(255,255,255,.15));
             border-top-color:var(--green-main,#2ea86a);
             animation:spotit-ov-spin .9s linear infinite;"></div>
        <i class="ov-glyph fa-solid fa-video-slash"
           style="display:none; font-size:1.5rem; color:var(--text-muted,#8b98a5);"></i>
      </div>
      <div class="ov-title" style="font-family:var(--font-display,inherit); font-size:.95rem;
           font-weight:800; color:var(--text-primary,#e8eef4); margin-bottom:6px;">Connecting</div>
      <div class="ov-desc" style="font-size:.79rem; color:var(--text-muted,#8b98a5);
           max-width:340px; line-height:1.55;">Waiting for the first frame from the camera.</div>
      <div class="ov-hint" style="display:none; margin-top:14px; padding:7px 13px; border-radius:7px;
           font-family:var(--font-mono,monospace); font-size:.68rem; line-height:1.5;
           color:var(--text-muted,#8b98a5); background:var(--bg-base,rgba(255,255,255,.04));
           border:1px solid var(--border,rgba(255,255,255,.1));"></div>
    `;
    container.appendChild(offlineOverlay);

    if (!document.getElementById('spotit-ov-style')) {
      const st = document.createElement('style');
      st.id = 'spotit-ov-style';
      st.textContent = '@keyframes spotit-ov-spin{to{transform:rotate(360deg)}}';
      document.head.appendChild(st);
    }
  }

  let activeImgId = 1;
  let livePollInterval = null;
  let consecutiveErrors = 0;
  let presenceTimer = null;

  // ── Tell the server somebody is watching ───────────────────────────────────
  // In a remote deployment the detector runs in the lab and pushes frames here
  // over the internet. Pushing 1 fps continuously would be roughly 5 GB per room
  // per day, almost all of it while nobody is looking. So the detector asks the
  // server "is anyone watching?" and only pushes at full rate when the answer is
  // yes. This ping is what makes the answer yes.
  //
  // Deliberately less frequent than the frame poll — the server keeps the flag
  // alive for LIVE_VIEWER_TTL_SEC (15s), so once every 10s is enough, and it
  // stops as soon as the tab is hidden or the viewer is torn down.
  function pingPresence() {
    if (document.hidden) return;
    fetch(`../auth/detector_heartbeat.php?room_id=${encodeURIComponent(roomId)}&watching=1`,
          { cache: 'no-store' }).catch(() => {});
  }

  // Each state says what happened and what to do about it. The hint line carries
  // the actual fix, because the person looking at a dead feed is usually the
  // person who has to go and restart something.
  const OVERLAY_STATES = {
    connecting: {
      spin: true,
      title: 'Connecting',
      desc: 'Waiting for the first frame from the camera.',
      hint: ''
    },
    offline: {
      spin: false,
      glyph: 'fa-video-slash',
      title: 'No live feed',
      desc: 'The detector for this room is not sending frames. Monitoring is paused, '
          + 'so items are not being checked right now.',
      hint: 'Check that the detector is running on the lab PC.\n'
          + 'Windows service:  nssm restart SpotItDetector'
    },
    standby: {
      spin: false,
      glyph: 'fa-camera-rotate',
      title: 'Waiting for calibration',
      desc: 'The system was reset and has no baseline photo yet. Capture one to '
          + 'start monitoring this room again.',
      hint: 'Camera Setup  ->  Capture Baseline'
    }
  };

  function showOverlay(stateName) {
    if (stateName === null) { offlineOverlay.style.display = 'none'; return; }

    const s = OVERLAY_STATES[stateName] || OVERLAY_STATES.connecting;
    const spin  = offlineOverlay.querySelector('.ov-spin');
    const glyph = offlineOverlay.querySelector('.ov-glyph');
    const hint  = offlineOverlay.querySelector('.ov-hint');

    spin.style.display  = s.spin ? 'block' : 'none';
    glyph.style.display = s.spin ? 'none'  : 'block';
    if (s.glyph) glyph.className = 'ov-glyph fa-solid ' + s.glyph;

    offlineOverlay.querySelector('.ov-title').textContent = s.title;
    offlineOverlay.querySelector('.ov-desc').textContent  = s.desc;

    if (s.hint) {
      hint.style.display = 'block';
      hint.textContent = s.hint;
      hint.style.whiteSpace = 'pre-line';
    } else {
      hint.style.display = 'none';
    }

    offlineOverlay.style.display = 'flex';
  }

  // Kept so existing callers keep working; maps the old boolean API onto states.
  function setOfflineState(isOffline, message = '') {
    if (!isOffline) return showOverlay(null);
    showOverlay(/connect/i.test(message) ? 'connecting' : 'offline');
  }

  function pollNext() {
    const timestamp = Date.now();
    const liveSrc = `../uploads/snapshots/live_${roomId}.jpg?t=${timestamp}`;

    const targetImg = activeImgId === 1 ? img2 : img1;
    const currentImg = activeImgId === 1 ? img1 : img2;

    targetImg.onload = () => {
      consecutiveErrors = 0;
      showOverlay(null);

      targetImg.style.zIndex = 2;
      targetImg.style.opacity = 1;
      currentImg.style.zIndex = 1;

      setTimeout(() => {
        currentImg.style.opacity = 0;
      }, 50);

      activeImgId = activeImgId === 1 ? 2 : 1;
      livePollInterval = setTimeout(pollNext, 120);
    };

    targetImg.onerror = () => {
      consecutiveErrors++;
      // A few misses are normal - the file is being rewritten as we read it.
      // Only call it offline once the frame has genuinely stopped arriving.
      if (consecutiveErrors > 5) {
        // No frame file at all usually means the system was reset and is waiting
        // for a baseline, rather than the detector being dead. Ask the server
        // which it is so the overlay gives the right instruction.
        fetch(`../auth/detector_heartbeat.php?room_id=${encodeURIComponent(roomId)}`,
              { cache: 'no-store' })
          .then(r => r.json())
          .then(d => showOverlay(d && d.health && d.health.online ? 'standby' : 'offline'))
          .catch(() => showOverlay('offline'));
      }
      livePollInterval = setTimeout(pollNext, 300);
    };

    targetImg.src = liveSrc;
  }

  // Start polling
  showOverlay('connecting');
  pollNext();

  // Presence: immediately, then every 10s while the tab is visible.
  pingPresence();
  presenceTimer = setInterval(pingPresence, 10000);
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden) pingPresence();
  });

  // Return controls to stop stream if needed
  return {
    stop: () => {
      if (livePollInterval) clearTimeout(livePollInterval);
      if (presenceTimer) clearInterval(presenceTimer);
    }
  };
}
