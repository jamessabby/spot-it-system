<?php
/**
 * S.P.O.T.-IT — Login Page
 * pages/login.php
 *
 * MICROSERVICES: This page has zero SQL. All auth goes through auth/ handlers.
 */
require_once __DIR__ . '/../config/env.php';

// Already logged in? redirect by role
if (!empty($_SESSION['user_id'])) {
    $role = $_SESSION['user_role'] ?? 'student';
    $dest = match($role) {
        'admin'  => 'dashboard-admin.php',
        'staff'  => 'dashboard-staff.php',
        default  => 'dashboard-student.php',
    };
    header("Location: {$dest}"); exit();
}

// Flash error from handler redirect
$flash_error = $_GET['error'] ?? '';
$flash_msg   = match($flash_error) {
    'invalid_domain'    => 'Only @dlsud.edu.ph accounts are allowed.',
    'oauth_failed'      => 'Microsoft login failed. Please try again.',
    'account_inactive'  => 'Your account is inactive. Contact an administrator.',
    'unauthorized'      => 'You do not have permission to access that page.',
    default             => '',
};
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Sign In — S.P.O.T.-IT</title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet" />
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" />
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css" />
  <link rel="stylesheet" href="../assets/css/login.css" />
  <link rel="stylesheet" href="../assets/css/skeleton.css" />
  <link rel="stylesheet" href="../assets/css/onboarding.css" />
  <script>
    // Apply saved theme before paint to prevent flash
    (function(){
      const t = localStorage.getItem('spotit_theme') || 'light';
      document.documentElement.setAttribute('data-theme', t);
    })();
  </script>
</head>
<body data-skeleton="form">
<script src="../assets/js/skeleton.js"></script>

<div class="page-wrap">

  <!-- ══════════ LEFT PANEL ══════════ -->
  <aside class="left-panel">
    <div>
      <a href="../index.php" class="brand-link">
        <div class="brand-icon">S</div>
        <span class="brand-name">S.P.O.T<span>.-IT</span></span>
      </a>

      <div class="panel-body">
        <div class="panel-eyebrow">Secure Access</div>
        <h2 class="panel-headline">Campus<br />intelligence,<br />on demand.</h2>
        <p class="panel-sub">
          Sign in to access the S.P.O.T.-IT monitoring dashboard —
          live room status, deviation alerts, and the full detection
          event log for CEAT laboratory rooms.
        </p>

        <!-- Live CCTV mini widget -->
        <div class="cctv-widget">
          <div class="cctv-header">
            <div class="cctv-live">
              <div class="cctv-rec"></div>
              LIVE · CEAT MLH
            </div>
            <div class="cctv-time" id="liveTime">--:--:--</div>
          </div>
          <div class="cctv-grid">
            <!-- Feed 1 — normal -->
            <div class="cctv-feed">
              <div class="feed-scan"></div>
              <svg width="100%" height="100%" viewBox="0 0 80 60" preserveAspectRatio="none" style="position:absolute;inset:0;opacity:.5">
                <rect x="5" y="8"  width="30" height="18" rx="2" fill="rgba(255,255,255,.06)"/>
                <rect x="45" y="8"  width="30" height="18" rx="2" fill="rgba(255,255,255,.06)"/>
                <rect x="5" y="34" width="30" height="18" rx="2" fill="rgba(255,255,255,.06)"/>
                <rect x="45" y="34" width="30" height="18" rx="2" fill="rgba(255,255,255,.06)"/>
              </svg>
              <div class="feed-ok" style="left:6%;top:13%;width:37%;height:30%;"></div>
              <div class="feed-ok" style="left:56%;top:13%;width:37%;height:30%;"></div>
              <div class="feed-ok" style="left:6%;top:57%;width:37%;height:30%;"></div>
              <div class="feed-ok" style="left:56%;top:57%;width:37%;height:30%;"></div>
              <div class="feed-label">MLH 304 · NORMAL</div>
            </div>
            <!-- Feed 2 — deviation alert -->
            <div class="cctv-feed">
              <div class="feed-scan"></div>
              <svg width="100%" height="100%" viewBox="0 0 80 60" preserveAspectRatio="none" style="position:absolute;inset:0;opacity:.5">
                <rect x="5" y="8"  width="30" height="18" rx="2" fill="rgba(255,255,255,.06)"/>
                <rect x="45" y="8"  width="30" height="18" rx="2" fill="rgba(255,255,255,.06)"/>
                <rect x="5" y="34" width="30" height="18" rx="2" fill="rgba(255,255,255,.06)"/>
              </svg>
              <div class="feed-ok"  style="left:6%;top:13%;width:37%;height:30%;"></div>
              <div class="feed-ok"  style="left:56%;top:13%;width:37%;height:30%;"></div>
              <div class="feed-ok"  style="left:6%;top:57%;width:37%;height:30%;"></div>
              <div class="feed-err" style="left:56%;top:57%;width:37%;height:30%;"></div>
              <div class="feed-label">MLH 306 · ⚠ DEVIATED</div>
            </div>
          </div>
          <div class="status-chips">
            <div class="chip chip-ok">7 NORMAL</div>
            <div class="chip chip-alert">1 DEVIATING</div>
          </div>
        </div>
      </div>
    </div>

    <div class="panel-footer">
      &copy; 2026 S.P.O.T.-IT — BS Computer Engineering Thesis<br />
      De La Salle University – Dasmariñas · Authorized use only.
    </div>
  </aside>

  <!-- ══════════ RIGHT PANEL (FORM) ══════════ -->
  <main class="right-panel">
    <button class="theme-btn" onclick="toggleTheme()" aria-label="Toggle theme">
      <i class="fa-solid fa-circle-half-stroke"></i>
    </button>

    <div class="form-container">
      <div class="form-header">
        <div class="form-eyebrow"><span class="dot"></span> Secure Login</div>
        <h1 class="form-title">Welcome back.</h1>
        <p class="form-subtitle">Sign in with your DLSU-D Microsoft account to continue.</p>
      </div>

      <div class="form-card">

        <?php if ($flash_msg): ?>
        <!-- Server-side flash error (from redirect) -->
        <div class="domain-warning show" style="margin-bottom:1rem;">
          <i class="fa-solid fa-circle-exclamation"></i>
          <span><?= htmlspecialchars($flash_msg) ?></span>
        </div>
        <?php endif; ?>

        <!-- ── PRIMARY: Microsoft OAuth ── -->
        <a href="../auth/microsoft_login.php" class="btn-microsoft">
          <div class="ms-logo">
            <span></span><span></span><span></span><span></span>
          </div>
          <div class="ms-meta">
            <span class="ms-main">Continue with Microsoft</span>
            <span class="ms-sub">@dlsud.edu.ph accounts only</span>
          </div>
        </a>

        <div class="or-divider">
          <span class="or-text">or sign in with email</span>
        </div>

        <!-- ── Domain warning ── -->
        <div class="domain-warning" id="domainWarning">
          <i class="fa-solid fa-circle-exclamation"></i>
          <span>Only <strong>@dlsud.edu.ph</strong> addresses are accepted.</span>
        </div>

        <!-- ── Rate-limit / lockout banner ── -->
        <div class="cooldown-banner" id="cooldownBanner">
          <i class="fa-solid fa-clock cooldown-icon"></i>
          <div>
            <div class="cooldown-title" id="cooldownTitle">Too many attempts</div>
            <div class="cooldown-sub" id="cooldownSub">Please wait before trying again.</div>
            <div class="cooldown-timer" id="cooldownTimer"></div>
          </div>
        </div>

        <!-- ── Attempt tracker dots ── -->
        <div class="attempt-dots" id="attemptDots" style="display:none;">
          <?php for($i=1;$i<=5;$i++): ?>
          <div class="attempt-dot" id="dot<?= $i ?>"></div>
          <?php endfor; ?>
        </div>

        <!-- ── Manual email/password form ── -->
        <form id="loginForm" novalidate>
          <!-- CSRF token (in production: generate server-side) -->
          <input type="hidden" name="csrf_token" id="csrfToken" value="<?= bin2hex(random_bytes(16)) ?>" />

          <!-- Email -->
          <div class="form-group">
            <label class="form-label" for="email">University Email</label>
            <div class="input-wrap">
              <i class="fa-solid fa-envelope input-icon"></i>
              <input type="email" id="email" name="email" class="form-control"
                     placeholder="yourname@dlsud.edu.ph"
                     autocomplete="email"
                     oninput="onEmailInput(this)" />
              <div class="domain-badge" id="domainBadge">@dlsud.edu.ph</div>
            </div>
            <div class="field-error" id="emailError">
              <i class="fa-solid fa-circle-exclamation"></i>
              <span id="emailErrorMsg">Enter your @dlsud.edu.ph email.</span>
            </div>
          </div>

          <!-- Password -->
          <div class="form-group">
            <label class="form-label" for="password">Password</label>
            <div class="input-wrap">
              <i class="fa-solid fa-lock input-icon"></i>
              <input type="password" id="password" name="password" class="form-control"
                     placeholder="••••••••••" autocomplete="current-password"
                     oninput="updateSubmit()" />
              <button type="button" class="btn-pw" onclick="togglePw()" aria-label="Show/hide password">
                <i class="fa-regular fa-eye" id="pwEye"></i>
              </button>
            </div>
            <div class="field-error" id="pwError">
              <i class="fa-solid fa-circle-exclamation"></i>
              <span>Password is required.</span>
            </div>
          </div>

          <!-- Options row -->
          <div class="form-options">
            <label class="remember-label">
              <input type="checkbox" id="rememberMe" name="remember" />
              Remember me
            </label>
            <a href="forgot-password.php" class="forgot-link">Forgot password?</a>
          </div>

          <!-- CAPTCHA -->
          <div class="captcha-wrap" id="captchaWrap" onclick="solveCaptcha()" role="button" aria-label="Complete CAPTCHA">
            <div class="captcha-check" id="captchaCheck">
              <div class="captcha-spinner" id="capSpinner"></div>
              <i class="fa-solid fa-check captcha-check-icon" id="capCheck"></i>
            </div>
            <span class="captcha-text">I'm not a robot</span>
            <div class="captcha-brand">
              <i class="fa-solid fa-shield-halved"></i>
              <span>reCAPTCHA</span>
            </div>
          </div>

          <!-- Submit -->
          <button type="submit" class="btn-submit" id="submitBtn" disabled>
            <span class="btn-label">
              <i class="fa-solid fa-arrow-right-to-bracket"></i> Sign In
            </span>
            <div class="btn-spinner"></div>
          </button>
        </form>

        <p class="register-prompt">
          Don't have an account? <a href="signup.php">Create one here</a>
        </p>

      </div><!-- /form-card -->

      <div class="security-note">
        <i class="fa-solid fa-lock" style="font-size:.6rem;"></i>
        Secured with Microsoft OAuth 2.0 · DLSU-D accounts only
      </div>

    </div><!-- /form-container -->
  </main>

</div><!-- /page-wrap -->

<!-- Toast container -->
<div class="toast-stack" id="toastStack"></div>

<script>
/* ── Constants ── */
const ALLOWED_DOMAIN = '@dlsud.edu.ph';
const MAX_ATTEMPTS   = 5;
const WARN_AT        = 3;

/* ── State ── */
let captchaDone    = false;
let failedAttempts = 0;
let cooldownActive = false;
let cooldownSecs   = 0;
let cooldownTimer  = null;

/* ── Theme ── */
function toggleTheme() {
  const html = document.documentElement;
  const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  html.setAttribute('data-theme', next);
  localStorage.setItem('spotit_theme', next);
}
// Apply on load
document.documentElement.setAttribute(
  'data-theme', localStorage.getItem('spotit_theme') || 'light'
);

/* ── Live clock ── */
function tick() {
  const el = document.getElementById('liveTime');
  if (el) el.textContent = new Date().toLocaleTimeString('en-GB', { hour12: false });
}
setInterval(tick, 1000); tick();

/* ── Email domain check ── */
function onEmailInput(input) {
  const val = input.value.trim().toLowerCase();
  const badge   = document.getElementById('domainBadge');
  const warning = document.getElementById('domainWarning');
  const errEl   = document.getElementById('emailError');

  input.classList.remove('invalid');
  errEl.classList.remove('show');
  warning.classList.remove('show');

  if (!val) { badge.classList.remove('valid'); badge.textContent = '@dlsud.edu.ph'; updateSubmit(); return; }

  if (val.includes('@')) {
    const domain = '@' + val.split('@').slice(1).join('@');
    if (domain === ALLOWED_DOMAIN) {
      badge.classList.add('valid'); badge.textContent = '✓ DLSU-D';
    } else if (val.split('@')[1]) {
      badge.classList.remove('valid'); badge.textContent = '✗ Invalid';
      warning.classList.add('show'); input.classList.add('invalid');
    } else {
      badge.classList.remove('valid'); badge.textContent = '@dlsud.edu.ph';
    }
  } else {
    badge.classList.remove('valid'); badge.textContent = '@dlsud.edu.ph';
  }
  updateSubmit();
}

function isValidEmail(email) {
  return email.toLowerCase().endsWith(ALLOWED_DOMAIN) && email.includes('@');
}

/* ── CAPTCHA simulation ── */
function solveCaptcha() {
  if (captchaDone || cooldownActive) return;
  const wrap = document.getElementById('captchaWrap');
  wrap.classList.add('loading');
  setTimeout(() => {
    wrap.classList.remove('loading');
    wrap.classList.add('done');
    captchaDone = true;
    updateSubmit();
  }, 700 + Math.random() * 500);
}

function resetCaptcha() {
  const wrap = document.getElementById('captchaWrap');
  wrap.classList.remove('done', 'loading');
  captchaDone = false;
  updateSubmit();
}

/* ── Submit button state ── */
function updateSubmit() {
  const email = document.getElementById('email').value.trim();
  const pw    = document.getElementById('password').value;
  const btn   = document.getElementById('submitBtn');
  btn.disabled = !(isValidEmail(email) && pw.length >= 1 && captchaDone && !cooldownActive);
}

document.getElementById('email').addEventListener('input', updateSubmit);
document.getElementById('password').addEventListener('input', updateSubmit);

/* ── Attempt dots ── */
function updateDots() {
  const wrap = document.getElementById('attemptDots');
  if (failedAttempts > 0) wrap.style.display = 'flex';
  for (let i = 1; i <= MAX_ATTEMPTS; i++) {
    const d = document.getElementById('dot' + i);
    d.className = 'attempt-dot';
    if (i <= failedAttempts) d.classList.add(failedAttempts >= WARN_AT ? 'used' : 'warn');
  }
}

/* ── Cooldown ── */
function startCooldown(seconds, isLockout = false) {
  cooldownActive = true; cooldownSecs = seconds;
  const banner = document.getElementById('cooldownBanner');
  const title  = document.getElementById('cooldownTitle');
  const sub    = document.getElementById('cooldownSub');
  const timerEl= document.getElementById('cooldownTimer');

  banner.classList.remove('lockout');
  if (isLockout) { banner.classList.add('lockout'); title.textContent = 'Account Temporarily Locked'; }
  else { title.textContent = 'Too Many Attempts'; }
  sub.textContent = 'Please wait before trying again.';
  banner.classList.add('show');
  updateSubmit();

  clearInterval(cooldownTimer);
  cooldownTimer = setInterval(() => {
    cooldownSecs--;
    const m = String(Math.floor(cooldownSecs / 60)).padStart(2,'0');
    const s = String(cooldownSecs % 60).padStart(2,'0');
    timerEl.textContent = `Wait ${m}:${s}`;
    if (cooldownSecs <= 0) {
      clearInterval(cooldownTimer);
      cooldownActive = false;
      banner.classList.remove('show','lockout');
      timerEl.textContent = '';
      resetCaptcha();
      if (isLockout) { failedAttempts = 0; updateDots(); }
      updateSubmit();
    }
  }, 1000);
  const m = String(Math.floor(seconds/60)).padStart(2,'0');
  const s = String(seconds%60).padStart(2,'0');
  timerEl.textContent = `Wait ${m}:${s}`;
}

/* ── Form submit ── */
document.getElementById('loginForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  if (cooldownActive) return;

  const email = document.getElementById('email').value.trim();
  const pw    = document.getElementById('password').value;
  const btn   = document.getElementById('submitBtn');

  // Clear previous errors
  document.getElementById('emailError').classList.remove('show');
  document.getElementById('pwError').classList.remove('show');
  document.getElementById('email').classList.remove('invalid');
  document.getElementById('password').classList.remove('invalid');

  let valid = true;
  if (!isValidEmail(email)) {
    document.getElementById('email').classList.add('invalid');
    document.getElementById('emailErrorMsg').textContent = email.includes('@')
      ? 'Only @dlsud.edu.ph addresses are accepted.'
      : 'Enter your @dlsud.edu.ph email address.';
    document.getElementById('emailError').classList.add('show');
    valid = false;
  }
  if (!pw) {
    document.getElementById('password').classList.add('invalid');
    document.getElementById('pwError').classList.add('show');
    valid = false;
  }
  if (!captchaDone) { showToast('error', 'Please complete the CAPTCHA first.'); return; }
  if (!valid) return;

  btn.classList.add('loading'); btn.disabled = true;

  try {
    const fd = new FormData(this);
    const res = await fetch('../auth/login_handler.php', { method: 'POST', body: fd });
    const data = await res.json();

    btn.classList.remove('loading');

    if (data.success) {
      showToast('success', 'Signed in successfully. Redirecting…');
      setTimeout(() => { window.location.href = data.redirect || 'dashboard-admin.php'; }, 900);
    } else {
      failedAttempts++;
      updateDots();
      resetCaptcha();
      btn.disabled = true;

      if (data.locked) {
        const secs = data.seconds_left || 300;
        startCooldown(secs, secs >= 60);
        showToast('error', secs >= 60
          ? `Account locked for ${Math.ceil(secs/60)} min after ${MAX_ATTEMPTS} failed attempts.`
          : `Too many attempts. Wait ${secs}s.`
        );
      } else {
        const left = MAX_ATTEMPTS - failedAttempts;
        showToast('error', data.message || `Incorrect credentials. ${left} attempt(s) left.`);
        if (failedAttempts >= WARN_AT) startCooldown(30);
        else updateSubmit();
      }
    }
  } catch (err) {
    btn.classList.remove('loading'); btn.disabled = false;
    showToast('error', 'Network error. Please try again.');
  }
});

/* ── Password toggle ── */
function togglePw() {
  const inp = document.getElementById('password');
  const ico = document.getElementById('pwEye');
  if (inp.type === 'password') { inp.type = 'text'; ico.className = 'fa-regular fa-eye-slash'; }
  else { inp.type = 'password'; ico.className = 'fa-regular fa-eye'; }
}

/* ── Toast ── */
function showToast(type, msg) {
  const stack = document.getElementById('toastStack');
  const t = document.createElement('div');
  t.className = `spotit-toast t-${type}`;
  const icon = type === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check';
  t.innerHTML = `<i class="fa-solid ${icon} t-icon"></i><div class="t-text">${msg}</div>`;
  stack.appendChild(t);
  setTimeout(() => t.remove(), 4500);
}
</script>
</body>
</html>
