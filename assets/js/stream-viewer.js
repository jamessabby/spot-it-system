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
  if (!offlineOverlay) {
    offlineOverlay = document.createElement('div');
    offlineOverlay.id = 'camOfflineOverlay';
    offlineOverlay.style.cssText = 'position:absolute; inset:0; display:none; flex-direction:column; align-items:center; justify-content:center; background:#060e0a; color:rgba(100,255,160,.4); z-index:10; border-radius:8px;';
    offlineOverlay.innerHTML = `
      <div class="hud-loader" style="margin-bottom:12px; width:36px; height:36px; border:3px solid rgba(92,255,172,0.15); border-top-color:#5cffac; border-radius:50%; animation:spinner-hud 0.8s linear infinite;"></div>
      <i class="fa-solid fa-video-slash" style="font-size:2rem; margin-bottom:.7rem; color:var(--alert); display:none;"></i>
      <div style="font-family:var(--font-mono); font-size:.7rem; color:#5cffac; font-weight:700; text-transform:uppercase; letter-spacing:0.05em;" class="msg">Daemon Offline (Standby)</div>
    `;
    container.appendChild(offlineOverlay);
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

  function setOfflineState(isOffline, message = 'Daemon Offline (Standby)') {
    const loader = offlineOverlay.querySelector('.hud-loader');
    const icon = offlineOverlay.querySelector('i');
    const msg = offlineOverlay.querySelector('.msg');
    
    if (isOffline) {
      offlineOverlay.style.display = 'flex';
      if (message.includes('Offline') || message.includes('Failed') || message.includes('Standby')) {
        if (loader) loader.style.display = 'none';
        if (icon) icon.style.display = 'block';
      } else {
        if (loader) loader.style.display = 'block';
        if (icon) icon.style.display = 'none';
      }
      if (msg) msg.textContent = message;
    } else {
      offlineOverlay.style.display = 'none';
    }
  }

  function pollNext() {
    const timestamp = Date.now();
    const liveSrc = `../uploads/snapshots/live_${roomId}.jpg?t=${timestamp}`;

    const targetImg = activeImgId === 1 ? img2 : img1;
    const currentImg = activeImgId === 1 ? img1 : img2;

    targetImg.onload = () => {
      consecutiveErrors = 0;
      setOfflineState(false);

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
      if (consecutiveErrors > 5) {
        setOfflineState(true, 'Monitoring Daemon Offline — Standby');
      }
      livePollInterval = setTimeout(pollNext, 300);
    };

    targetImg.src = liveSrc;
  }

  // Start polling
  setOfflineState(true, 'Connecting to stream feed...');
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
