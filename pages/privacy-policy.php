<?php
/**
 * S.P.O.T.-IT — Privacy Policy
 * pages/privacy-policy.php
 * Public page. No auth required.
 */
require_once __DIR__ . '/../config/env.php';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Privacy Policy — S.P.O.T.-IT</title>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/css/variables.css"/>
  <link rel="stylesheet" href="../assets/css/legal.css"/>
  <link rel="stylesheet" href="../assets/css/skeleton.css"/>
  <script>(function(){document.documentElement.setAttribute('data-theme',localStorage.getItem('spotit_theme')||'light')})();</script>
</head>
<body data-skeleton="legal">
<script src="../assets/js/skeleton.js"></script>

<nav class="legal-nav">
  <a href="index.php" class="legal-brand">
    <div class="legal-brand-icon">S</div>
    <span>S.P.O.T.-IT</span>
  </a>
  <div style="display:flex;align-items:center;gap:10px;">
    <button class="legal-theme-btn" onclick="toggleTheme()"><i class="fa-solid fa-circle-half-stroke"></i></button>
    <a href="index.php" class="legal-back"><i class="fa-solid fa-arrow-left"></i> Back to Home</a>
  </div>
</nav>

<div class="legal-wrap">
  <aside class="legal-sidebar">
    <div class="legal-side-label">On This Page</div>
    <a href="#overview" class="legal-side-link active">1. Overview</a>
    <a href="#collect" class="legal-side-link">2. Information We Collect</a>
    <a href="#cctv" class="legal-side-link">3. CCTV &amp; Visual Data</a>
    <a href="#use" class="legal-side-link">4. How We Use Your Data</a>
    <a href="#sharing" class="legal-side-link">5. Data Sharing</a>
    <a href="#storage" class="legal-side-link">6. Data Storage &amp; Security</a>
    <a href="#retention" class="legal-side-link">7. Data Retention</a>
    <a href="#rights" class="legal-side-link">8. Your Rights</a>
    <a href="#cookies" class="legal-side-link">9. Cookies &amp; Sessions</a>
    <a href="#updates" class="legal-side-link">10. Policy Updates</a>

    <div class="legal-related-card">
      <i class="fa-solid fa-file-contract"></i>
      <div>
        <div style="font-weight:700;font-size:.78rem;">Terms &amp; Conditions</div>
        <div style="font-size:.7rem;color:var(--text-dim);">Platform usage terms</div>
      </div>
      <a href="terms.php" class="legal-related-link"><i class="fa-solid fa-arrow-right"></i></a>
    </div>
  </aside>

  <main class="legal-content">
    <div class="legal-header">
      <div class="legal-eyebrow"><i class="fa-solid fa-shield-halved"></i> Legal Document</div>
      <h1>Privacy Policy</h1>
      <p class="legal-meta">Last updated: June 1, 2026 &nbsp;·&nbsp; Applies to all data processed by S.P.O.T.-IT</p>
    </div>

    <div class="legal-intro">
      This Privacy Policy explains how S.P.O.T.-IT collects, uses, stores, and protects information
      when you use our IoT-integrated laboratory monitoring and lost-and-found platform. We are
      committed to handling your data responsibly and in compliance with the Data Privacy Act of 2012
      (Republic Act No. 10173) and DLSU-D's institutional data privacy guidelines.
    </div>

    <section id="overview">
      <h2>1. Overview</h2>
      <p>S.P.O.T.-IT is built using a strict microservices architecture, meaning your data is segmented across four independently secured databases:</p>
      <div class="legal-db-grid">
        <div class="legal-db-card">
          <i class="fa-solid fa-user-shield"></i>
          <div class="legal-db-name">Auth Database</div>
          <div class="legal-db-desc">Login credentials, session data, account roles</div>
        </div>
        <div class="legal-db-card">
          <i class="fa-solid fa-video"></i>
          <div class="legal-db-name">Monitoring Database</div>
          <div class="legal-db-desc">Detection events, room data, CCTV snapshots</div>
        </div>
        <div class="legal-db-card">
          <i class="fa-solid fa-box-open"></i>
          <div class="legal-db-name">Lost &amp; Found Database</div>
          <div class="legal-db-desc">Recovered items, claims, surrender logs</div>
        </div>
        <div class="legal-db-card">
          <i class="fa-solid fa-id-card"></i>
          <div class="legal-db-name">User Database</div>
          <div class="legal-db-desc">Profile information, preferences, settings</div>
        </div>
      </div>
    </section>

    <section id="collect">
      <h2>2. Information We Collect</h2>
      <p>We collect the following categories of information:</p>
      <table class="legal-table">
        <thead><tr><th>Category</th><th>Examples</th><th>Source</th></tr></thead>
        <tbody>
          <tr><td>Identity Information</td><td>Full name, university email, ID number</td><td>Registration / Microsoft OAuth</td></tr>
          <tr><td>Contact Information</td><td>Phone number (optional)</td><td>Profile settings</td></tr>
          <tr><td>Authentication Data</td><td>Password hash (manual accounts only), OAuth tokens</td><td>Login / signup process</td></tr>
          <tr><td>Activity Data</td><td>Claims submitted, posts created, login history</td><td>Platform usage</td></tr>
          <tr><td>Visual Data</td><td>CCTV snapshots, claiming station documentation photos</td><td>Detection system / webcam capture</td></tr>
          <tr><td>Technical Data</td><td>IP address, browser type, session tokens</td><td>Automatic collection</td></tr>
        </tbody>
      </table>
    </section>

    <section id="cctv">
      <h2>3. CCTV &amp; Visual Data</h2>
      <p>Our IP CCTV cameras inside monitored CEAT laboratory rooms use <strong>classical computer vision</strong> (background subtraction, frame differencing, contour-based counting) — not facial recognition or AI-based personal identification. The system detects changes in equipment count within predefined Regions of Inspection (ROI), not individual people.</p>
      <p>Important clarifications:</p>
      <ul>
        <li>The system does not track, identify, or log the identity of individuals appearing in camera frames</li>
        <li>Snapshot images may incidentally capture people present in the room at the time of a detection event</li>
        <li>These snapshots are used exclusively for staff verification of equipment deviations and item recovery documentation</li>
        <li>Webcam photos at the claiming station are taken with the claimant's knowledge, for chain-of-custody documentation only</li>
      </ul>
    </section>

    <section id="use">
      <h2>4. How We Use Your Data</h2>
      <p>We use collected information to:</p>
      <ul>
        <li>Authenticate your identity and enforce @dlsud.edu.ph domain restrictions</li>
        <li>Operate the lost-and-found claiming and verification workflow</li>
        <li>Send notifications about detection alerts and claim status updates</li>
        <li>Generate analytics and audit reports for administrators (aggregated, not individually identifying beyond role-based access)</li>
        <li>Improve detection accuracy and system reliability for thesis research purposes</li>
      </ul>
    </section>

    <section id="sharing">
      <h2>5. Data Sharing</h2>
      <p>We do <strong>not</strong> sell, rent, or share your personal data with third parties for marketing purposes. Data may only be shared with:</p>
      <ul>
        <li>Authorized DLSU-D laboratory staff and administrators, for the purpose of verifying detection events and processing claims</li>
        <li>The Student Welfare and Formation Office (S.W.A.F.O.), in cases involving disciplinary referral for fraudulent claims</li>
        <li>Microsoft Azure AD, solely for OAuth authentication (we do not store your Microsoft password)</li>
      </ul>
    </section>

    <section id="storage">
      <h2>6. Data Storage &amp; Security</h2>
      <p>Passwords are hashed using industry-standard bcrypt before storage — we never store plaintext passwords. Each microservice database uses isolated credentials, limiting the impact of any single point of compromise. Sessions use HTTP-only, same-site cookies to prevent common web attacks.</p>
      <p>Rate limiting (CAPTCHA + progressive lockouts) protects against brute-force login attempts.</p>
    </section>

    <section id="retention">
      <h2>7. Data Retention</h2>
      <p>Detection event records, claim history, and audit logs are retained for the duration of the thesis research period and subsequent evaluation. Account data is retained until you request deactivation or as required for academic record-keeping purposes related to this thesis project.</p>
    </section>

    <section id="rights">
      <h2>8. Your Rights</h2>
      <p>Under the Data Privacy Act of 2012, you have the right to:</p>
      <ul>
        <li>Access the personal data we hold about you (via Settings → Privacy → Export My Data)</li>
        <li>Request correction of inaccurate information (via your Profile page)</li>
        <li>Request deactivation of your account</li>
        <li>Object to certain processing of your data, where applicable</li>
      </ul>
    </section>

    <section id="cookies">
      <h2>9. Cookies &amp; Sessions</h2>
      <p>We use essential session cookies to keep you signed in and remember your theme preference (light/dark mode). We do not use third-party advertising or tracking cookies.</p>
    </section>

    <section id="updates">
      <h2>10. Policy Updates</h2>
      <p>This Privacy Policy may be updated as the S.P.O.T.-IT system develops through its thesis phases. Material changes will be reflected with an updated "Last updated" date at the top of this page.</p>
      <div class="legal-authors">
        <div>Ryan Robert C. Diosana</div>
        <div>Yiannis L. Pedrozo</div>
        <div>James Jacob E. Sabio</div>
      </div>
    </section>

    <div class="legal-footer-nav">
      <a href="terms.php" class="legal-footer-link">
        <i class="fa-solid fa-file-contract"></i>
        <div><div style="font-weight:700;">Terms &amp; Conditions</div><div style="font-size:.72rem;color:var(--text-dim);">Platform usage terms</div></div>
        <i class="fa-solid fa-arrow-right" style="margin-left:auto;"></i>
      </a>
      <a href="signup.php" class="legal-footer-link">
        <i class="fa-solid fa-user-plus"></i>
        <div><div style="font-weight:700;">Create Account</div><div style="font-size:.72rem;color:var(--text-dim);">Back to registration</div></div>
        <i class="fa-solid fa-arrow-right" style="margin-left:auto;"></i>
      </a>
    </div>
  </main>
</div>

<script src="../assets/js/spotit.js"></script>
<script>
const sections = document.querySelectorAll('main.legal-content section');
const links = document.querySelectorAll('.legal-side-link');
window.addEventListener('scroll', () => {
  let current = '';
  sections.forEach(sec => { if (window.scrollY >= sec.offsetTop - 120) current = sec.id; });
  links.forEach(l => l.classList.toggle('active', l.getAttribute('href') === '#'+current));
});
</script>
</body>
</html>
