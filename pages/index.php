<?php
/**
 * S.P.O.T.-IT — Landing / Index Page
 * pages/index.php  (or root index.php)
 *
 * Public page. No auth required.
 * Lab-only monitoring logic — no dual lecture-room / lab split.
 */
$already_logged_in = !empty($_SESSION['user_id'] ?? null);
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>S.P.O.T.-IT — IoT Lab Monitoring System</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"/>
  <link rel="stylesheet" href="assets/css/variables.css"/>
  <link rel="stylesheet" href="assets/css/index.css"/>
  <script>(function(){document.documentElement.setAttribute('data-theme',localStorage.getItem('spotit_theme')||'light')})();</script>
</head>
<body data-skeleton="none">

<!-- ══════════════════════════════════════════
     NAV
══════════════════════════════════════════ -->
<nav class="nav">
  <a href="#" class="nav-brand">
    <div class="nav-logo-icon">S</div>
    <div>
      <div class="nav-logo-name">S.P.O.T.-IT</div>
      <div class="nav-logo-sub">DLSU-D CEAT</div>
    </div>
  </a>
  <div class="nav-links">
    <a href="#features">Features</a>
    <a href="#how">How It Works</a>
    <a href="#about">About</a>
  </div>
  <div class="nav-actions">
    <button class="nav-theme-btn" onclick="toggleTheme()" aria-label="Toggle theme">
      <i class="fa-solid fa-circle-half-stroke"></i>
    </button>
    <?php if ($already_logged_in): ?>
      <a href="pages/dashboard-admin.php" class="btn-nav-outline">Dashboard</a>
    <?php else: ?>
      <a href="pages/login.php"  class="btn-nav-outline">Sign In</a>
      <a href="pages/signup.php" class="btn-nav-solid">Register</a>
    <?php endif; ?>
  </div>
  <button class="nav-hamburger" onclick="toggleMobileNav()" aria-label="Menu">
    <i class="fa-solid fa-bars"></i>
  </button>
</nav>
<div class="mobile-nav" id="mobileNav">
  <a href="#features" onclick="toggleMobileNav()">Features</a>
  <a href="#how"      onclick="toggleMobileNav()">How It Works</a>
  <a href="#about"    onclick="toggleMobileNav()">About</a>
  <a href="pages/login.php"  class="mn-login">Sign In</a>
  <a href="pages/signup.php" class="mn-register">Register</a>
</div>

<!-- ══════════════════════════════════════════
     HERO
══════════════════════════════════════════ -->
<section class="hero">
  <div class="hero-bg-dots"></div>
  <div class="hero-glow"></div>

  <div class="hero-content">
    <div class="hero-eyebrow">
      <span class="hero-rec"></span>
      IoT-Integrated Surveillance · DLSU-D CEAT Building
    </div>

    <h1 class="hero-headline">
      Automated Lab<br />
      Item Tracking &amp;<br />
      <span class="hero-highlight">Lost-and-Found.</span>
    </h1>

    <p class="hero-sub">
      S.P.O.T.-IT uses CCTV-based computer vision to continuously monitor
      laboratory equipment in CEAT rooms — automatically detecting missing or
      misplaced items and alerting personnel in real time.
      No manual inspection required.
    </p>

    <div class="hero-ctas">
      <a href="pages/login.php"  class="cta-primary"><i class="fa-solid fa-arrow-right-to-bracket"></i> Access Dashboard</a>
      <a href="#how"             class="cta-secondary"><i class="fa-solid fa-play"></i> See How It Works</a>
    </div>

    <div class="hero-stats">
      <div class="hstat"><span class="hstat-n">99%</span><span class="hstat-l">Detection Accuracy</span></div>
      <div class="hstat-div"></div>
      <div class="hstat"><span class="hstat-n">&lt;2s</span><span class="hstat-l">Alert Latency</span></div>
      <div class="hstat-div"></div>
      <div class="hstat"><span class="hstat-n">24/7</span><span class="hstat-l">Continuous Monitoring</span></div>
      <div class="hstat-div"></div>
      <div class="hstat"><span class="hstat-n">0</span><span class="hstat-l">Manual Check-ins</span></div>
    </div>
  </div>

  <!-- CCTV Dashboard Preview -->
  <div class="hero-preview">
    <div class="preview-bar">
      <div class="preview-dots"><span></span><span></span><span></span></div>
      <div class="preview-title">S.P.O.T.-IT · Live Dashboard</div>
      <div class="preview-live"><span class="prev-dot"></span>LIVE</div>
    </div>
    <div class="preview-body">

      <!-- Mini stat row -->
      <div class="prev-stats">
        <div class="prev-stat ok"><i class="fa-solid fa-circle-check"></i><span>8 Rooms</span></div>
        <div class="prev-stat alert"><i class="fa-solid fa-circle-minus"></i><span>1 Missing</span></div>
        <div class="prev-stat warn"><i class="fa-solid fa-clock"></i><span>2 Pending</span></div>
      </div>

      <!-- Room rows -->
      <div class="prev-rooms">
        <div class="prev-room-row alert-row">
          <span class="prev-room-id">MLH 306</span>
          <span class="prev-room-name">Systems &amp; App Dev</span>
          <span class="prev-dev dev-neg">−1</span>
          <span class="prev-badge b-alert">MISSING</span>
          <span class="prev-timer" id="prevTimer">01:03:22</span>
        </div>
        <div class="prev-room-row warn-row">
          <span class="prev-room-id">MLH 305</span>
          <span class="prev-room-name">Logic &amp; Algorithms</span>
          <span class="prev-dev dev-neg">−2</span>
          <span class="prev-badge b-warn">POTENTIAL</span>
          <span class="prev-timer warn">00:35:14</span>
        </div>
        <div class="prev-room-row">
          <span class="prev-room-id">MLH 304</span>
          <span class="prev-room-name">Engineering CAD</span>
          <span class="prev-dev dev-zero">0</span>
          <span class="prev-badge b-ok">NORMAL</span>
          <span class="prev-timer ok">—</span>
        </div>
        <div class="prev-room-row">
          <span class="prev-room-id">MLH 303</span>
          <span class="prev-room-name">Advanced Programming</span>
          <span class="prev-dev dev-zero">0</span>
          <span class="prev-badge b-ok">NORMAL</span>
          <span class="prev-timer ok">—</span>
        </div>
        <div class="prev-room-row">
          <span class="prev-room-id">MLH 203</span>
          <span class="prev-room-name">Computational Eng.</span>
          <span class="prev-dev dev-zero">0</span>
          <span class="prev-badge b-ok">NORMAL</span>
          <span class="prev-timer ok">—</span>
        </div>
      </div>

      <!-- Mini alert -->
      <div class="prev-alert">
        <i class="fa-solid fa-triangle-exclamation"></i>
        <span><strong>MLH 306</strong> — Monitor missing for over 1 hour. Confirmed missing. Auto-escalated.</span>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════
     FEATURES
══════════════════════════════════════════ -->
<section class="features-section" id="features">
  <div class="section-container">
    <div class="section-eyebrow">Core Capabilities</div>
    <h2 class="section-headline">Intelligent Campus<br />Surveillance at a Glance</h2>
    <p class="section-sub">Four integrated modules work together to keep every laboratory room accountable, every moment of the day.</p>

    <div class="features-grid">
      <div class="feature-card">
        <div class="feat-num">01</div>
        <div class="feat-icon"><i class="fa-solid fa-camera"></i></div>
        <h3>Equipment Deviation Detection</h3>
        <p>IP CCTV cameras stream live footage. Python + OpenCV compares each frame against a registered baseline count. When a monitor, keyboard, or system unit disappears from its ROI zone, the system instantly flags it.</p>
        <div class="feat-tag">Background Subtraction · Contour Count</div>
      </div>
      <div class="feature-card">
        <div class="feat-num">02</div>
        <div class="feat-icon alert"><i class="fa-solid fa-bell-ring"></i></div>
        <h3>Two-Stage Alert System</h3>
        <p>No motion detected in the room + deviation persists for <strong>30 minutes</strong> → "Potentially Lost" alert. After <strong>1 hour</strong> with no resolution → "Confirmed Missing" with auto-escalation to admin.</p>
        <div class="feat-tag">30 min Potential · 60 min Confirmed</div>
      </div>
      <div class="feature-card">
        <div class="feat-num">03</div>
        <div class="feat-icon info"><i class="fa-solid fa-gauge-high"></i></div>
        <h3>Centralized Web Dashboard</h3>
        <p>A unified web interface gives admins and staff real-time feed access, incident logs, alert histories, and room status — all from any browser. Role-based access for students, staff, and administrators.</p>
        <div class="feat-tag">Admin · Staff · Student Roles</div>
      </div>
      <div class="feature-card">
        <div class="feat-num">04</div>
        <div class="feat-icon warn"><i class="fa-solid fa-hand-holding"></i></div>
        <h3>Smart Claiming Station</h3>
        <p>Students self-register via university ID for instant claim and verification workflows. Webcam captures a documentation photo at point of handoff. All records auto-stored in the centralized database.</p>
        <div class="feat-tag">ID Verification · Webcam Capture</div>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════
     HOW IT WORKS
══════════════════════════════════════════ -->
<section class="how-section" id="how">
  <div class="section-container">
    <div class="section-eyebrow">System Pipeline</div>
    <h2 class="section-headline">From Camera Feed<br />to Resolution</h2>

    <div class="steps-grid">
      <div class="step">
        <div class="step-num">1</div>
        <div class="step-icon"><i class="fa-solid fa-video"></i></div>
        <h4>Continuous Video Capture</h4>
        <p>IP CCTV cameras stream live footage through the campus local network to the central Python detection module running on a Lenovo LOQ laptop.</p>
      </div>
      <div class="step-arrow"><i class="fa-solid fa-arrow-right"></i></div>
      <div class="step">
        <div class="step-num">2</div>
        <div class="step-icon alert"><i class="fa-solid fa-magnifying-glass"></i></div>
        <h4>Real-Time Object Detection</h4>
        <p>OpenCV compares each frame against the registered reference frame within defined Regions of Inspection (ROI). Count deviations are flagged immediately.</p>
      </div>
      <div class="step-arrow"><i class="fa-solid fa-arrow-right"></i></div>
      <div class="step">
        <div class="step-num">3</div>
        <div class="step-icon warn"><i class="fa-solid fa-bolt"></i></div>
        <h4>Automated Alert Dispatch</h4>
        <p>Detection events are POSTed to the Node.js/Express backend API. The system immediately notifies responsible staff via the dashboard and logs a timestamped snapshot.</p>
      </div>
      <div class="step-arrow"><i class="fa-solid fa-arrow-right"></i></div>
      <div class="step">
        <div class="step-num">4</div>
        <div class="step-icon ok"><i class="fa-solid fa-circle-check"></i></div>
        <h4>Claim &amp; Resolution</h4>
        <p>The owner is identified through the claiming station. Staff verify and close the event. The chain of custody is fully documented with photo evidence, timestamps, and room logs.</p>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════
     ABOUT
══════════════════════════════════════════ -->
<section class="about-section" id="about">
  <div class="section-container about-inner">
    <div class="about-text">
      <div class="section-eyebrow">About the Project</div>
      <h2 class="section-headline" style="font-size:1.8rem;">Built for DLSU-D CEAT.<br/>Designed for scale.</h2>
      <p>S.P.O.T.-IT is a BS Computer Engineering thesis project developed at De La Salle University – Dasmariñas. It addresses the gap between automated visual surveillance and localized laboratory asset management — replacing slow, manual inventory checks with a continuous, intelligent monitoring system.</p>
      <p style="margin-top:1rem;">The system uses classical image processing (no machine learning) making it lightweight enough to run on standard campus hardware while remaining accurate and reliable.</p>
      <div class="about-authors">
        <div class="author">Ryan Robert C. Diosana</div>
        <div class="author">Yiannis L. Pedrozo</div>
        <div class="author">James Jacob E. Sabio</div>
      </div>
      <div class="about-tags">
        <span>Python · OpenCV</span>
        <span>PHP · MySQL</span>
        <span>Node.js · Express</span>
        <span>IoT · IP CCTV</span>
        <span>DLSU-D · May 2026</span>
      </div>
    </div>
    <div class="about-cta-box">
      <div class="acta-label">Ready to get started?</div>
      <h3>Access the monitoring dashboard</h3>
      <p>Sign in with your DLSU-D Microsoft account to view live room status, detection events, and the lost-and-found management system.</p>
      <a href="pages/login.php" class="cta-primary" style="display:inline-flex;margin-top:1.2rem;">
        <i class="fa-brands fa-microsoft"></i> Sign in with Microsoft
      </a>
      <div style="font-size:.72rem;color:rgba(255,255,255,.5);margin-top:.8rem;">@dlsud.edu.ph accounts only</div>
    </div>
  </div>
</section>

<!-- ══════════════════════════════════════════
     FOOTER
══════════════════════════════════════════ -->
<footer class="site-footer">
  <div class="footer-inner">
    <div class="footer-brand">
      <div class="sb-logo-icon" style="width:28px;height:28px;font-size:.75rem;">S</div>
      <div>
        <div style="font-family:var(--font-display);font-weight:800;font-size:.88rem;">S.P.O.T.-IT</div>
        <div style="font-size:.64rem;color:var(--text-dim);">DLSU-D · BS Computer Engineering</div>
      </div>
    </div>
    <div style="font-size:.72rem;color:var(--text-dim);display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
      <span>&copy; 2026 Diosana · Pedrozo · Sabio · De La Salle University – Dasmariñas</span>
      <a href="terms.php" style="color:var(--text-dim);text-decoration:underline;text-underline-offset:2px;">Terms &amp; Conditions</a>
      <a href="privacy-policy.php" style="color:var(--text-dim);text-decoration:underline;text-underline-offset:2px;">Privacy Policy</a>
    </div>
  </div>
</footer>

<script src="assets/js/spotit.js"></script>
<script>
// Fake hero timer countup
let heroSecs = 3802;
setInterval(function () {
  heroSecs++;
  const el = document.getElementById('prevTimer');
  if (!el) return;
  const h = String(Math.floor(heroSecs / 3600)).padStart(2,'0');
  const m = String(Math.floor((heroSecs%3600)/60)).padStart(2,'0');
  const s = String(heroSecs%60).padStart(2,'0');
  el.textContent = `${h}:${m}:${s}`;
}, 1000);

function toggleMobileNav() {
  document.getElementById('mobileNav').classList.toggle('open');
}
</script>
</body>
</html>
