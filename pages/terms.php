<?php
/**
 * S.P.O.T.-IT — Terms and Conditions
 * pages/terms.php
 * Public page. No auth required.
 */
require_once __DIR__ . '/../config/env.php';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Terms &amp; Conditions — S.P.O.T.-IT</title>
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

<!-- Simple top nav -->
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
    <a href="#acceptance" class="legal-side-link active">1. Acceptance of Terms</a>
    <a href="#eligibility" class="legal-side-link">2. Eligibility</a>
    <a href="#accounts" class="legal-side-link">3. Account Registration</a>
    <a href="#monitoring" class="legal-side-link">4. Surveillance &amp; Monitoring</a>
    <a href="#claiming" class="legal-side-link">5. Lost &amp; Found Claims</a>
    <a href="#conduct" class="legal-side-link">6. User Conduct</a>
    <a href="#data" class="legal-side-link">7. Data Collection</a>
    <a href="#liability" class="legal-side-link">8. Limitation of Liability</a>
    <a href="#changes" class="legal-side-link">9. Changes to Terms</a>
    <a href="#contact" class="legal-side-link">10. Contact</a>

    <div class="legal-related-card">
      <i class="fa-solid fa-shield-halved"></i>
      <div>
        <div style="font-weight:700;font-size:.78rem;">Privacy Policy</div>
        <div style="font-size:.7rem;color:var(--text-dim);">See how we handle your data</div>
      </div>
      <a href="privacy-policy.php" class="legal-related-link"><i class="fa-solid fa-arrow-right"></i></a>
    </div>
  </aside>

  <main class="legal-content">
    <div class="legal-header">
      <div class="legal-eyebrow"><i class="fa-solid fa-file-contract"></i> Legal Document</div>
      <h1>Terms and Conditions</h1>
      <p class="legal-meta">Last updated: June 1, 2026 &nbsp;·&nbsp; Effective for all S.P.O.T.-IT users</p>
    </div>

    <div class="legal-intro">
      Welcome to S.P.O.T.-IT, an IoT-integrated surveillance system developed as a BS Computer Engineering
      thesis project for laboratory item tracking and lost-and-found management inside the CEAT Building
      of De La Salle University – Dasmariñas. By accessing or using this platform, you agree to be bound
      by the following Terms and Conditions.
    </div>

    <section id="acceptance">
      <h2>1. Acceptance of Terms</h2>
      <p>By creating an account, signing in, or otherwise accessing S.P.O.T.-IT ("the System", "the Platform"), you acknowledge that you have read, understood, and agree to be bound by these Terms and Conditions and our Privacy Policy. If you do not agree, please do not use the Platform.</p>
      <p>This Platform is operated as an academic thesis project and is intended solely for authorized use by De La Salle University – Dasmariñas ("DLSU-D") students, faculty, and staff within the CEAT Building.</p>
    </section>

    <section id="eligibility">
      <h2>2. Eligibility</h2>
      <p>Access to S.P.O.T.-IT is restricted to individuals holding a valid <strong>@dlsud.edu.ph</strong> email address. This includes:</p>
      <ul>
        <li>Currently enrolled DLSU-D students</li>
        <li>CEAT faculty members and laboratory personnel</li>
        <li>Authorized administrative staff (Student Welfare and Formation Office, Security Personnel)</li>
      </ul>
      <p>The System will reject registration or sign-in attempts using non-DLSU-D email domains. Attempting to circumvent this restriction is a violation of these Terms.</p>
    </section>

    <section id="accounts">
      <h2>3. Account Registration</h2>
      <p>You may register using either:</p>
      <ul>
        <li><strong>Microsoft OAuth (recommended)</strong> — using your DLSU-D Microsoft 365 account. Account creation is automatic upon first sign-in.</li>
        <li><strong>Manual registration</strong> — using your university email, ID number, and a self-chosen password, subject to CAPTCHA verification.</li>
      </ul>
      <p>You are responsible for maintaining the confidentiality of your account credentials. Admin and staff accounts with elevated dashboard access are provisioned manually by system administrators and are not available through self-registration.</p>
      <p>Repeated failed login attempts will result in a temporary cooldown (30 seconds after 3 failed attempts, 5 minutes after 5 failed attempts) to prevent unauthorized access attempts.</p>
    </section>

    <section id="monitoring">
      <h2>4. Surveillance &amp; Monitoring</h2>
      <p>S.P.O.T.-IT utilizes IP CCTV cameras and classical computer vision techniques (background subtraction and contour-based counting — <em>no facial recognition or machine learning identification</em>) to monitor laboratory equipment inside designated CEAT laboratory rooms.</p>
      <p>By being present in a monitored laboratory room, you acknowledge that:</p>
      <ul>
        <li>Visual footage is captured for the sole purpose of detecting equipment count deviations</li>
        <li>The System does not identify individuals, track faces, or analyze personal biometric data</li>
        <li>Snapshot images may incidentally include visible persons as part of detection event documentation</li>
        <li>Captured snapshots are used solely for monitoring, verification, and documentation by authorized personnel</li>
      </ul>
    </section>

    <section id="claiming">
      <h2>5. Lost &amp; Found Claims</h2>
      <p>When submitting a claim for a recovered item, you agree to provide accurate and truthful information, including your university ID number and a genuine description of the item. Providing false information to fraudulently claim an item that does not belong to you may result in:</p>
      <ul>
        <li>Rejection of your claim</li>
        <li>Suspension of your S.P.O.T.-IT account</li>
        <li>Referral to the Student Welfare and Formation Office (S.W.A.F.O.) for disciplinary action</li>
      </ul>
      <p>All physical item handoffs occur at the designated dispensing window and include a documentation photo for chain-of-custody purposes.</p>
    </section>

    <section id="conduct">
      <h2>6. User Conduct</h2>
      <p>You agree not to:</p>
      <ul>
        <li>Attempt to access, modify, or interfere with the detection system, database, or any other user's account</li>
        <li>Submit false lost or found reports</li>
        <li>Use automated scripts, bots, or scrapers against the Platform</li>
        <li>Share your account credentials with any other person</li>
        <li>Attempt to bypass the @dlsud.edu.ph domain restriction</li>
      </ul>
    </section>

    <section id="data">
      <h2>7. Data Collection</h2>
      <p>Information collected through your use of S.P.O.T.-IT — including your name, email, university ID, claim history, and any submitted post content — is governed by our <a href="privacy-policy.php">Privacy Policy</a>, which forms part of these Terms.</p>
    </section>

    <section id="liability">
      <h2>8. Limitation of Liability</h2>
      <p>S.P.O.T.-IT is a prototype academic research system developed for thesis purposes. While reasonable efforts are made to ensure detection accuracy, the System:</p>
      <ul>
        <li>Cannot guarantee 100% detection accuracy under all lighting, occlusion, or room activity conditions</li>
        <li>Does not independently verify ownership of detected or claimed items beyond the staff verification process</li>
        <li>Is not liable for items lost, misplaced, or stolen that were not detected by the monitoring system</li>
      </ul>
      <p>The developers and De La Salle University – Dasmariñas shall not be held liable for any direct, indirect, incidental, or consequential damages arising from use of, or inability to use, the Platform.</p>
    </section>

    <section id="changes">
      <h2>9. Changes to Terms</h2>
      <p>These Terms may be updated periodically as the System evolves during its thesis development and potential future deployment phases. Continued use of the Platform after changes are posted constitutes acceptance of the revised Terms.</p>
    </section>

    <section id="contact">
      <h2>10. Contact</h2>
      <p>For questions regarding these Terms, please contact the development team or the CEAT Computer Engineering Department at De La Salle University – Dasmariñas.</p>
      <div class="legal-authors">
        <div>Ryan Robert C. Diosana</div>
        <div>Yiannis L. Pedrozo</div>
        <div>James Jacob E. Sabio</div>
      </div>
    </section>

    <div class="legal-footer-nav">
      <a href="privacy-policy.php" class="legal-footer-link">
        <i class="fa-solid fa-shield-halved"></i>
        <div><div style="font-weight:700;">Privacy Policy</div><div style="font-size:.72rem;color:var(--text-dim);">How we handle your data</div></div>
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
// Highlight active section in sidebar on scroll
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
