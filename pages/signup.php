<?php
/**
 * S.P.O.T.-IT — Sign Up Page
 * pages/signup.php
 *
 * MICROSERVICES: Zero SQL. All registration goes through auth/signup_handler.php
 */
require_once __DIR__ . '/../config/env.php';
if (!empty($_SESSION['user_id'])) { header('Location: dashboard-admin.php'); exit(); }
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Create Account — S.P.O.T.-IT</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/css/login.css"/>
  <link rel="stylesheet" href="../assets/css/signup.css"/>
  <link rel="stylesheet" href="../assets/css/skeleton.css"/>
  <script>(function(){document.documentElement.setAttribute('data-theme',localStorage.getItem('spotit_theme')||'light')})();</script>
</head>
<body data-skeleton="form">
<script src="../assets/js/skeleton.js"></script>

<div class="page-wrap">

  <!-- LEFT PANEL -->
  <aside class="left-panel">
    <div>
      <a href="index.php" class="brand-link">
        <div class="brand-icon">S</div>
        <span class="brand-name">S.P.O.T<span>.-IT</span></span>
      </a>
      <div class="panel-body">
        <div class="panel-eyebrow">Join the Network</div>
        <h2 class="panel-headline">Monitor.<br/>Detect.<br/>Recover.</h2>
        <p class="panel-sub">
          Create your S.P.O.T.-IT account to access real-time alerts when
          unattended or missing items are detected inside CEAT laboratory rooms.
        </p>
        <div class="signup-perks">
          <div class="perk"><i class="fa-solid fa-bell-ring"></i> Real-time alerts when items go missing</div>
          <div class="perk"><i class="fa-solid fa-id-card"></i> University ID linked for seamless claim</div>
          <div class="perk"><i class="fa-solid fa-gauge-high"></i> Access the centralized monitoring dashboard</div>
          <div class="perk"><i class="fa-solid fa-shield-halved"></i> Admin accounts provisioned separately</div>
        </div>
      </div>
    </div>
    <div class="panel-footer">
      &copy; 2026 S.P.O.T.-IT — BS Computer Engineering Thesis<br/>
      De La Salle University – Dasmariñas · Authorized use only.
    </div>
  </aside>

  <!-- RIGHT PANEL -->
  <main class="right-panel" style="align-items:flex-start;padding-top:2.5rem;padding-bottom:2.5rem;">
    <button class="theme-btn" onclick="toggleTheme()" aria-label="Toggle theme">
      <i class="fa-solid fa-circle-half-stroke"></i>
    </button>

    <div class="form-container" style="max-width:460px;">
      <div class="form-header">
        <div class="form-eyebrow"><span class="dot"></span> New Account</div>
        <h1 class="form-title">Create your account.</h1>
        <p class="form-subtitle">Register using your DLSU-D Microsoft account — it's the fastest way.</p>
      </div>

      <div class="form-card">

        <!-- Primary: Microsoft OAuth -->
        <a href="../auth/microsoft_login.php" class="btn-microsoft">
          <div class="ms-logo"><span></span><span></span><span></span><span></span></div>
          <div class="ms-meta">
            <span class="ms-main">Continue with Microsoft</span>
            <span class="ms-sub">Auto-registers with @dlsud.edu.ph account</span>
          </div>
        </a>

        <div class="or-divider"><span class="or-text">or register manually</span></div>

        <!-- Manual form -->
        <form id="signupForm" novalidate>
          <!-- Session-backed and actually validated by signup_handler.php. -->
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(spotit_csrf_token(), ENT_QUOTES, 'UTF-8') ?>"/>

          <!-- Personal info section -->
          <div class="form-section-label">Personal Information</div>

          <div class="form-group">
            <label class="form-label" for="full_name">Full Name</label>
            <div class="input-wrap">
              <i class="fa-solid fa-user input-icon"></i>
              <input type="text" id="full_name" name="full_name" class="form-control"
                     placeholder="e.g. Maria Santos" autocomplete="name" oninput="updateSubmit()"/>
            </div>
            <div class="field-error" id="nameError"><i class="fa-solid fa-circle-exclamation"></i><span>Full name is required.</span></div>
          </div>

          <div class="form-group">
            <label class="form-label" for="email">University Email</label>
            <div class="input-wrap">
              <i class="fa-solid fa-envelope input-icon"></i>
              <input type="email" id="email" name="email" class="form-control"
                     placeholder="e.g. m.santos@dlsud.edu.ph"
                     autocomplete="email" oninput="onEmailInput(this)"/>
              <div class="domain-badge" id="domainBadge">@dlsud.edu.ph</div>
            </div>
            <div class="field-error" id="emailError"><i class="fa-solid fa-circle-exclamation"></i><span id="emailMsg">Enter your @dlsud.edu.ph email.</span></div>
          </div>

          <div class="form-group">
            <label class="form-label" for="id_number">University ID Number</label>
            <div class="input-wrap">
              <i class="fa-solid fa-id-badge input-icon"></i>
              <input type="text" id="id_number" name="id_number" class="form-control"
                     placeholder="e.g. 2021-00001" maxlength="20" oninput="updateSubmit()"/>
            </div>
            <div style="font-size:.7rem;color:var(--text-dim);margin-top:.3rem;">Format: YYYY-NNNNN (e.g. 2021-00001)</div>
          </div>

          <!-- ROLE PICKER REMOVED 2026-08-22.
               It offered "Staff", and login_handler.php normalises the staff
               role into an admin SESSION (CLAUDE.md §2) - so this control was
               a public self-service route into the admin dashboard. Signup is
               now always a student account; signup_handler.php ignores any
               submitted role rather than validating one, so restoring a picker
               here cannot silently re-open the hole. -->
          <div class="form-section-label" style="margin-top:1.2rem;">Account Type</div>
          <div style="font-size:.72rem;color:var(--text-muted);margin-bottom:1.2rem;line-height:1.6;">
            <i class="fa-solid fa-graduation-cap" style="font-size:.66rem;color:var(--green-main);"></i>
            Registering creates a <strong>Student</strong> account &mdash; browse recovered items
            and submit claim requests.
            <br/>
            <i class="fa-solid fa-lock" style="font-size:.6rem;"></i>
            Staff and administrator access is granted by an existing administrator, never by signing up.
          </div>

          <!-- Password -->
          <div class="form-section-label">Security</div>

          <div class="form-group">
            <label class="form-label" for="password">Password</label>
            <div class="input-wrap">
              <i class="fa-solid fa-lock input-icon"></i>
              <input type="password" id="password" name="password" class="form-control"
                     placeholder="Min. 8 characters" autocomplete="new-password" oninput="checkPassword()"/>
              <button type="button" class="btn-pw" onclick="togglePw('password','pwEye')" aria-label="Toggle">
                <i class="fa-regular fa-eye" id="pwEye"></i>
              </button>
            </div>
            <!-- Strength bar -->
            <div class="pw-strength-bar" id="pwStrengthBar">
              <div class="pw-strength-fill" id="pwFill"></div>
            </div>
            <div class="pw-strength-label" id="pwStrengthLabel"></div>
            <div class="field-error" id="pwError"><i class="fa-solid fa-circle-exclamation"></i><span id="pwMsg">Password must be at least 8 characters.</span></div>
          </div>

          <div class="form-group">
            <label class="form-label" for="confirm_password">Confirm Password</label>
            <div class="input-wrap">
              <i class="fa-solid fa-lock input-icon"></i>
              <input type="password" id="confirm_password" name="confirm_password" class="form-control"
                     placeholder="Re-enter password" autocomplete="new-password" oninput="checkConfirm()"/>
              <button type="button" class="btn-pw" onclick="togglePw('confirm_password','cpwEye')" aria-label="Toggle">
                <i class="fa-regular fa-eye" id="cpwEye"></i>
              </button>
            </div>
            <div class="field-error" id="cpwError"><i class="fa-solid fa-circle-exclamation"></i><span>Passwords do not match.</span></div>
          </div>

          <!-- CAPTCHA. Verified server-side, single-use. See config/security.php. -->
          <div class="captcha-wrap" id="captchaWrap">
            <i class="fa-solid fa-shield-halved captcha-icon"></i>
            <label class="captcha-q" for="captchaAnswer">
              Security check: what is <strong id="captchaQuestion"><?= htmlspecialchars(spotit_captcha_new(), ENT_QUOTES, 'UTF-8') ?></strong>?
            </label>
            <input type="number" class="captcha-input" id="captchaAnswer" name="captcha_answer"
                   inputmode="numeric" autocomplete="off" required aria-label="Answer to the security check" />
            <button type="button" class="captcha-refresh" id="captchaRefresh"
                    onclick="refreshCaptcha()" title="Get a new question" aria-label="Get a new question">
              <i class="fa-solid fa-rotate"></i>
            </button>
          </div>

          <!-- Terms -->
          <label class="terms-label" id="termsLabel">
            <input type="checkbox" id="acceptTerms" onchange="updateSubmit()"/>
            I agree to the <a href="terms.php" target="_blank">Terms and Conditions</a> and
            <a href="privacy-policy.php" target="_blank">Privacy Policy</a>. I understand this platform is for
            authorized university monitoring only.
          </label>

          <!-- Submit -->
          <button type="submit" class="btn-submit" id="submitBtn" disabled>
            <span class="btn-label"><i class="fa-solid fa-user-plus"></i> Create Account</span>
            <div class="btn-spinner"></div>
          </button>
        </form>

        <p class="register-prompt">Already have an account? <a href="login.php">Sign in here</a></p>
      </div>

      <div class="security-note">
        <i class="fa-solid fa-lock" style="font-size:.6rem;"></i>
        Secured with Microsoft OAuth 2.0 · @dlsud.edu.ph accounts only
      </div>
    </div>
  </main>
</div>

<div class="toast-stack" id="toastStack"></div>

<script src="../assets/js/spotit.js"></script>
<script>
const ALLOWED_DOMAIN = '@dlsud.edu.ph';
let captchaDone = false;
let pwOk = false, cpwOk = false, emailOk = false;

function onEmailInput(input) {
  const val = input.value.trim().toLowerCase();
  const badge = document.getElementById('domainBadge');
  const warn  = document.getElementById('domainWarning');
  const err   = document.getElementById('emailError');
  input.classList.remove('invalid'); err.classList.remove('show');

  if (!val) { badge.classList.remove('valid'); badge.textContent = '@dlsud.edu.ph'; emailOk = false; updateSubmit(); return; }
  if (val.includes('@')) {
    const domain = '@' + val.split('@').slice(1).join('@');
    if (domain === ALLOWED_DOMAIN) {
      badge.classList.add('valid'); badge.textContent = '✓ DLSU-D'; emailOk = true;
    } else if (val.split('@')[1]) {
      badge.classList.remove('valid'); badge.textContent = '✗ Invalid';
      input.classList.add('invalid');
      document.getElementById('emailMsg').textContent = 'Only @dlsud.edu.ph addresses are accepted.';
      err.classList.add('show'); emailOk = false;
    } else { badge.classList.remove('valid'); badge.textContent = '@dlsud.edu.ph'; emailOk = false; }
  } else { badge.classList.remove('valid'); badge.textContent = '@dlsud.edu.ph'; emailOk = false; }
  updateSubmit();
}

function checkPassword() {
  const pw = document.getElementById('password').value;
  const fill = document.getElementById('pwFill');
  const label = document.getElementById('pwStrengthLabel');
  const err = document.getElementById('pwError');
  err.classList.remove('show'); document.getElementById('password').classList.remove('invalid');

  let score = 0;
  if (pw.length >= 8)  score++;
  if (/[A-Z]/.test(pw)) score++;
  if (/[0-9]/.test(pw)) score++;
  if (/[^A-Za-z0-9]/.test(pw)) score++;

  const levels = [
    { pct: '25%', cls: 'weak',   txt: 'Weak' },
    { pct: '50%', cls: 'fair',   txt: 'Fair' },
    { pct: '75%', cls: 'good',   txt: 'Good' },
    { pct: '100%',cls: 'strong', txt: 'Strong' },
  ];
  const lvl = levels[Math.max(0, score - 1)] || levels[0];
  fill.style.width = pw.length ? lvl.pct : '0';
  fill.className = 'pw-strength-fill ' + (pw.length ? lvl.cls : '');
  label.textContent = pw.length ? lvl.txt : '';
  label.className = 'pw-strength-label ' + (pw.length ? lvl.cls : '');

  pwOk = pw.length >= 8;
  if (!pwOk && pw.length) {
    document.getElementById('password').classList.add('invalid');
    document.getElementById('pwMsg').textContent = 'Password must be at least 8 characters.';
    err.classList.add('show');
  }
  checkConfirm();
  updateSubmit();
}

function checkConfirm() {
  const pw  = document.getElementById('password').value;
  const cpw = document.getElementById('confirm_password').value;
  const err = document.getElementById('cpwError');
  err.classList.remove('show'); document.getElementById('confirm_password').classList.remove('invalid');
  if (cpw && cpw !== pw) {
    document.getElementById('confirm_password').classList.add('invalid');
    err.classList.add('show'); cpwOk = false;
  } else { cpwOk = cpw.length > 0 && cpw === pw; }
  updateSubmit();
}

/* Each challenge is single-use server-side, so a spent one must be replaced
   after any submit attempt. */
async function refreshCaptcha() {
  const btn = document.getElementById('captchaRefresh');
  const q   = document.getElementById('captchaQuestion');
  const ans = document.getElementById('captchaAnswer');
  btn.classList.add('spinning');
  try {
    const res  = await fetch('../auth/captcha.php', { cache: 'no-store' });
    const data = await res.json();
    if (data && data.question) q.textContent = data.question;
  } catch (e) { /* keep the current question rather than blanking it */ }
  btn.classList.remove('spinning');
  ans.value = '';
  document.getElementById('captchaWrap').classList.remove('invalid');
  updateSubmit();
}

function updateSubmit() {
  const name  = document.getElementById('full_name').value.trim();
  const idNum = document.getElementById('id_number').value.trim();
  const terms = document.getElementById('acceptTerms').checked;
  captchaDone = document.getElementById('captchaAnswer').value.trim() !== '';
  document.getElementById('submitBtn').disabled = !(name && emailOk && idNum && pwOk && cpwOk && captchaDone && terms);
}

document.getElementById('captchaAnswer').addEventListener('input', updateSubmit);

function togglePw(inputId, iconId) {
  const inp = document.getElementById(inputId);
  const ico = document.getElementById(iconId);
  if (inp.type === 'password') { inp.type = 'text'; ico.className = 'fa-regular fa-eye-slash'; }
  else { inp.type = 'password'; ico.className = 'fa-regular fa-eye'; }
}

document.getElementById('signupForm').addEventListener('submit', async function(e) {
  e.preventDefault();
  const btn = document.getElementById('submitBtn');
  btn.classList.add('loading'); btn.disabled = true;

  const fd = new FormData(this);
  try {
    const res  = await fetch('../auth/signup_handler.php', { method: 'POST', body: fd });
    const data = await res.json();
    btn.classList.remove('loading');
    if (data.success) {
      showToast('success', 'Account created! Redirecting to login…');
      setTimeout(() => { window.location.href = 'login.php?registered=1'; }, 1200);
    } else {
      /* The CAPTCHA is spent whether or not the rest of the form was valid, so
         a new one is needed before the user can retry anything. */
      if (data.csrf_invalid) {
        showToast('error', data.message || 'Your session expired. Reloading…');
        setTimeout(() => window.location.reload(), 1600);
        return;
      }
      await refreshCaptcha();
      if (data.captcha_failed) {
        document.getElementById('captchaWrap').classList.add('invalid');
      }
      showToast('error', data.message || 'Registration failed. Please try again.');
      btn.disabled = false;
    }
  } catch {
    btn.classList.remove('loading'); btn.disabled = false;
    showToast('error', 'Network error. Please try again.');
  }
});
</script>
</body>
</html>
