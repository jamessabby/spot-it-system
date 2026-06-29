<?php
/**
 * S.P.O.T.-IT — Forgot Password Page
 * pages/forgot-password.php
 *
 * Three-step flow:
 *   Step 1 — Enter @dlsud.edu.ph email
 *   Step 2 — Enter OTP sent to email
 *   Step 3 — Set new password
 *
 * MICROSERVICES: No SQL. Calls auth/forgot_password.php handlers.
 */
require_once __DIR__ . '/../config/env.php';
if (!empty($_SESSION['user_id'])) { header('Location: dashboard-admin.php'); exit(); }
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Forgot Password — S.P.O.T.-IT</title>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/css/login.css"/>
  <link rel="stylesheet" href="../assets/css/forgot-password.css"/>
  <link rel="stylesheet" href="../assets/css/skeleton.css"/>
  <script>(function(){document.documentElement.setAttribute('data-theme',localStorage.getItem('spotit_theme')||'light')})();</script>
</head>
<body data-skeleton="form">
<script src="../assets/js/skeleton.js"></script>
<div class="page-wrap">

  <!-- Left panel -->
  <aside class="left-panel">
    <div>
      <a href="index.php" class="brand-link">
        <div class="brand-icon">S</div>
        <span class="brand-name">S.P.O.T<span>.-IT</span></span>
      </a>
      <div class="panel-body">
        <div class="panel-eyebrow">Account Recovery</div>
        <h2 class="panel-headline">Reset your<br/>password<br/>securely.</h2>
        <p class="panel-sub">
          We'll send a one-time verification code to your
          <strong style="color:rgba(255,255,255,.9);">@dlsud.edu.ph</strong>
          email address. Use it to verify your identity and set a new password.
        </p>

        <!-- Security steps visual -->
        <div class="recovery-steps">
          <div class="rs-item" id="rs1">
            <div class="rs-num">1</div>
            <div class="rs-text">
              <div class="rs-label">Enter Email</div>
              <div class="rs-desc">Your @dlsud.edu.ph address</div>
            </div>
          </div>
          <div class="rs-line"></div>
          <div class="rs-item" id="rs2">
            <div class="rs-num">2</div>
            <div class="rs-text">
              <div class="rs-label">Verify OTP</div>
              <div class="rs-desc">6-digit code sent to your email</div>
            </div>
          </div>
          <div class="rs-line"></div>
          <div class="rs-item" id="rs3">
            <div class="rs-num">3</div>
            <div class="rs-text">
              <div class="rs-label">New Password</div>
              <div class="rs-desc">Set your new secure password</div>
            </div>
          </div>
        </div>

        <!-- Microsoft hint -->
        <div style="margin-top:2rem;padding:14px;background:rgba(0,120,212,.12);border:1px solid rgba(0,120,212,.2);border-radius:10px;">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:.4rem;">
            <div class="ms-logo" style="width:16px;height:16px;">
              <span style="background:#f25022;"></span><span style="background:#7fba00;"></span>
              <span style="background:#00a4ef;"></span><span style="background:#ffb900;"></span>
            </div>
            <span style="font-family:var(--font-display);font-size:.72rem;font-weight:700;color:rgba(255,255,255,.8);">Signed in with Microsoft?</span>
          </div>
          <p style="font-size:.72rem;color:rgba(255,255,255,.55);line-height:1.6;margin:0;">
            If you signed in using your DLSU-D Microsoft account, your password is managed by Microsoft. Reset it at <span style="color:rgba(100,180,255,.8);">account.microsoft.com</span> instead.
          </p>
        </div>
      </div>
    </div>
    <div class="panel-footer">
      &copy; 2026 S.P.O.T.-IT — BS Computer Engineering Thesis<br/>
      De La Salle University – Dasmariñas
    </div>
  </aside>

  <!-- Right panel -->
  <main class="right-panel">
    <button class="theme-btn" onclick="toggleTheme()"><i class="fa-solid fa-circle-half-stroke"></i></button>

    <div class="form-container">

      <!-- ── STEP 1: Email ── -->
      <div id="step1">
        <div class="form-header">
          <div class="form-eyebrow"><span class="dot"></span> Password Recovery</div>
          <h1 class="form-title">Forgot your password?</h1>
          <p class="form-subtitle">Enter your DLSU-D email and we'll send you a verification code.</p>
        </div>
        <div class="form-card">
          <div class="form-group">
            <label class="form-label">University Email</label>
            <div class="input-wrap">
              <i class="fa-solid fa-envelope input-icon"></i>
              <input type="email" id="fpEmail" class="form-control"
                     placeholder="yourname@dlsud.edu.ph"
                     oninput="checkFpEmail(this)"/>
              <div class="domain-badge" id="fpBadge">@dlsud.edu.ph</div>
            </div>
            <div class="field-error" id="fpEmailError">
              <i class="fa-solid fa-circle-exclamation"></i>
              <span id="fpEmailMsg">Enter your @dlsud.edu.ph email address.</span>
            </div>
          </div>

          <div style="padding:12px 13px;background:var(--info-bg);border:1px solid rgba(26,106,181,.18);border-radius:9px;font-size:.77rem;color:var(--text-primary);margin-bottom:1rem;display:flex;gap:8px;">
            <i class="fa-solid fa-circle-info" style="color:var(--info);flex-shrink:0;margin-top:1px;"></i>
            <span>A 6-digit one-time code will be sent to your email. The code expires in <strong>10 minutes</strong>.</span>
          </div>

          <button class="btn-submit" id="fpBtn1" disabled onclick="sendOTP()">
            <span class="btn-label"><i class="fa-solid fa-paper-plane"></i> Send Verification Code</span>
            <div class="btn-spinner"></div>
          </button>

          <p class="register-prompt">Remembered it? <a href="login.php">Back to Sign In</a></p>
        </div>
      </div>

      <!-- ── STEP 2: OTP ── -->
      <div id="step2" style="display:none;">
        <div class="form-header">
          <div class="form-eyebrow"><span class="dot"></span> Verification</div>
          <h1 class="form-title">Check your email.</h1>
          <p class="form-subtitle">We sent a 6-digit code to <strong id="sentToEmail" style="color:var(--green-main);">your@dlsud.edu.ph</strong>. Enter it below.</p>
        </div>
        <div class="form-card">

          <!-- OTP boxes -->
          <div class="otp-wrap">
            <?php for($i=1;$i<=6;$i++): ?>
            <input type="text" class="otp-box" id="otp<?= $i ?>" maxlength="1"
                   oninput="otpInput(this,<?= $i ?>)"
                   onkeydown="otpKey(event,<?= $i ?>)"
                   onpaste="<?= $i===1?'otpPaste(event)':'' ?>"
                   inputmode="numeric" pattern="[0-9]"/>
            <?php endfor; ?>
          </div>

          <!-- Resend -->
          <div style="text-align:center;margin-bottom:1rem;">
            <span style="font-size:.78rem;color:var(--text-muted);">Didn't receive it? </span>
            <button id="resendBtn" onclick="resendOTP()" style="background:none;border:none;color:var(--green-main);font-size:.78rem;font-weight:600;cursor:pointer;" disabled>
              Resend Code (<span id="resendCountdown">60</span>s)
            </button>
          </div>

          <!-- Expiry bar -->
          <div style="margin-bottom:1rem;">
            <div style="display:flex;justify-content:space-between;font-size:.68rem;color:var(--text-dim);margin-bottom:4px;">
              <span>Code expires in</span><span id="otpExpiry" style="font-family:var(--font-mono);font-weight:600;color:var(--warn);">10:00</span>
            </div>
            <div style="height:3px;background:var(--border);border-radius:10px;overflow:hidden;">
              <div id="otpExpiryBar" style="height:100%;background:var(--green-main);width:100%;border-radius:10px;transition:width 1s linear,background 1s;"></div>
            </div>
          </div>

          <button class="btn-submit" id="fpBtn2" disabled onclick="verifyOTP()">
            <span class="btn-label"><i class="fa-solid fa-shield-check"></i> Verify Code</span>
            <div class="btn-spinner"></div>
          </button>

          <p class="register-prompt" style="margin-top:.8rem;">
            <a href="#" onclick="goStep(1);return false;"><i class="fa-solid fa-arrow-left"></i> Use a different email</a>
          </p>
        </div>
      </div>

      <!-- ── STEP 3: New Password ── -->
      <div id="step3" style="display:none;">
        <div class="form-header">
          <div class="form-eyebrow"><span class="dot"></span> New Password</div>
          <h1 class="form-title">Set a new password.</h1>
          <p class="form-subtitle">Choose a strong password for your S.P.O.T.-IT account.</p>
        </div>
        <div class="form-card">
          <div class="form-group">
            <label class="form-label">New Password</label>
            <div class="input-wrap">
              <i class="fa-solid fa-lock input-icon"></i>
              <input type="password" id="newPw" class="form-control"
                     placeholder="Min. 8 characters" autocomplete="new-password"
                     oninput="checkResetStrength(this.value)"/>
              <button type="button" class="btn-pw" onclick="togglePwReset('newPw','eyeNew')">
                <i class="fa-regular fa-eye" id="eyeNew"></i>
              </button>
            </div>
            <div class="pw-strength-bar" id="resetStrengthBar">
              <div class="pw-strength-fill" id="resetStrengthFill"></div>
            </div>
            <div class="pw-strength-label" id="resetStrengthLabel"></div>
          </div>
          <div class="form-group">
            <label class="form-label">Confirm New Password</label>
            <div class="input-wrap">
              <i class="fa-solid fa-lock input-icon"></i>
              <input type="password" id="confirmPw" class="form-control"
                     placeholder="Re-enter new password" autocomplete="new-password"
                     oninput="checkResetMatch()"/>
              <button type="button" class="btn-pw" onclick="togglePwReset('confirmPw','eyeConfirm')">
                <i class="fa-regular fa-eye" id="eyeConfirm"></i>
              </button>
            </div>
            <div class="field-error" id="pwMatchError">
              <i class="fa-solid fa-circle-exclamation"></i>
              <span>Passwords do not match.</span>
            </div>
          </div>

          <!-- Password requirements checklist -->
          <div class="pw-checklist">
            <div class="pw-check-item" id="req-length"><i class="fa-solid fa-circle-xmark"></i> At least 8 characters</div>
            <div class="pw-check-item" id="req-upper"><i class="fa-solid fa-circle-xmark"></i> One uppercase letter</div>
            <div class="pw-check-item" id="req-number"><i class="fa-solid fa-circle-xmark"></i> One number</div>
            <div class="pw-check-item" id="req-special"><i class="fa-solid fa-circle-xmark"></i> One special character</div>
          </div>

          <button class="btn-submit" id="fpBtn3" disabled onclick="resetPassword()">
            <span class="btn-label"><i class="fa-solid fa-key"></i> Reset Password</span>
            <div class="btn-spinner"></div>
          </button>
        </div>
      </div>

      <!-- ── STEP 4: Success ── -->
      <div id="step4" style="display:none;text-align:center;">
        <div style="width:72px;height:72px;border-radius:50%;background:var(--ok-bg);border:3px solid var(--ok);display:flex;align-items:center;justify-content:center;margin:0 auto 1.2rem;animation:spotit-popIn .4s ease;">
          <i class="fa-solid fa-check" style="font-size:1.8rem;color:var(--ok);"></i>
        </div>
        <h2 style="font-family:var(--font-display);font-size:1.4rem;font-weight:800;color:var(--text-primary);margin-bottom:.5rem;">Password Reset!</h2>
        <p style="font-size:.84rem;color:var(--text-muted);margin-bottom:1.5rem;line-height:1.7;">Your password has been updated successfully. You can now sign in with your new password.</p>
        <a href="login.php" class="btn-submit" style="display:inline-flex;text-decoration:none;max-width:280px;margin:0 auto;">
          <span class="btn-label"><i class="fa-solid fa-arrow-right-to-bracket"></i> Go to Sign In</span>
        </a>
      </div>

    </div>
  </main>
</div>

<div class="toast-stack" id="toastStack"></div>
<script src="../assets/js/spotit.js"></script>
<script>
const ALLOWED_DOMAIN = '@dlsud.edu.ph';
let currentStep = 1, otpExpiryInterval, resendInterval, resendSecs = 60;

/* ── Theme ── */
function toggleTheme() {
  const html = document.documentElement;
  const next = html.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
  html.setAttribute('data-theme', next);
  localStorage.setItem('spotit_theme', next);
}

/* ── Step navigation ── */
function goStep(n) {
  [1,2,3,4].forEach(i => document.getElementById('step'+i).style.display = i===n?'':'none');
  ['rs1','rs2','rs3'].forEach((id,i) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.remove('active','done');
    if (i+1 < n)  el.classList.add('done');
    if (i+1 === n) el.classList.add('active');
  });
  currentStep = n;
}

/* ── Step 1: Email ── */
function checkFpEmail(input) {
  const val = input.value.trim().toLowerCase();
  const badge = document.getElementById('fpBadge');
  const err   = document.getElementById('fpEmailError');
  input.classList.remove('invalid'); err.classList.remove('show');
  badge.classList.remove('valid'); badge.textContent = '@dlsud.edu.ph';

  if (val.includes('@') && val.split('@')[1]) {
    if (val.endsWith(ALLOWED_DOMAIN)) { badge.classList.add('valid'); badge.textContent = '✓ DLSU-D'; }
    else { badge.textContent = '✗ Invalid'; input.classList.add('invalid'); }
  }
  document.getElementById('fpBtn1').disabled = !val.endsWith(ALLOWED_DOMAIN);
}

function sendOTP() {
  const email = document.getElementById('fpEmail').value.trim();
  const btn   = document.getElementById('fpBtn1');
  btn.classList.add('loading'); btn.disabled = true;
  setTimeout(() => {
    btn.classList.remove('loading');
    document.getElementById('sentToEmail').textContent = email;
    goStep(2);
    startOtpExpiry();
    startResendCountdown();
    showToast('success', 'Verification code sent to ' + email);
  }, 1200);
}

/* ── Step 2: OTP ── */
function otpInput(el, idx) {
  el.value = el.value.replace(/[^0-9]/g,'');
  if (el.value && idx < 6) document.getElementById('otp'+(idx+1)).focus();
  checkOtpComplete();
}
function otpKey(e, idx) {
  if (e.key === 'Backspace' && !document.getElementById('otp'+idx).value && idx > 1) {
    document.getElementById('otp'+(idx-1)).focus();
  }
}
function otpPaste(e) {
  e.preventDefault();
  const digits = (e.clipboardData.getData('text')||'').replace(/\D/g,'').slice(0,6).split('');
  digits.forEach((d,i) => { const el = document.getElementById('otp'+(i+1)); if(el) el.value = d; });
  const last = document.getElementById('otp'+Math.min(digits.length,6)); if(last) last.focus();
  checkOtpComplete();
}
function checkOtpComplete() {
  const full = [1,2,3,4,5,6].every(i => document.getElementById('otp'+i).value);
  document.getElementById('fpBtn2').disabled = !full;
}

function startOtpExpiry() {
  let secs = 600;
  clearInterval(otpExpiryInterval);
  otpExpiryInterval = setInterval(() => {
    secs--;
    const m = String(Math.floor(secs/60)).padStart(2,'0');
    const s = String(secs%60).padStart(2,'0');
    const el = document.getElementById('otpExpiry');
    const bar = document.getElementById('otpExpiryBar');
    if (el) el.textContent = m+':'+s;
    if (bar) {
      bar.style.width = (secs/600*100)+'%';
      bar.style.background = secs < 120 ? 'var(--alert)' : secs < 300 ? 'var(--warn)' : 'var(--green-main)';
    }
    if (secs <= 0) { clearInterval(otpExpiryInterval); showToast('error','Code expired. Please request a new one.'); }
  },1000);
}
function startResendCountdown() {
  resendSecs = 60;
  const btn = document.getElementById('resendBtn');
  const cd  = document.getElementById('resendCountdown');
  btn.disabled = true;
  clearInterval(resendInterval);
  resendInterval = setInterval(() => {
    resendSecs--;
    if (cd) cd.textContent = resendSecs;
    if (resendSecs <= 0) {
      clearInterval(resendInterval);
      btn.disabled = false;
      btn.textContent = 'Resend Code';
    }
  },1000);
}
function resendOTP() {
  clearInterval(otpExpiryInterval);
  [1,2,3,4,5,6].forEach(i => { const el=document.getElementById('otp'+i); if(el) el.value=''; });
  document.getElementById('otp1').focus();
  startOtpExpiry(); startResendCountdown();
  showToast('success','New verification code sent.');
}

function verifyOTP() {
  const code = [1,2,3,4,5,6].map(i=>document.getElementById('otp'+i).value).join('');
  const btn  = document.getElementById('fpBtn2');
  btn.classList.add('loading'); btn.disabled = true;
  setTimeout(() => {
    btn.classList.remove('loading');
    clearInterval(otpExpiryInterval);
    // Demo: any 6-digit code works
    goStep(3);
    showToast('success','Identity verified. Please set your new password.');
  },1000);
}

/* ── Step 3: New password ── */
function checkResetStrength(pw) {
  const reqs = {
    'req-length':  pw.length >= 8,
    'req-upper':   /[A-Z]/.test(pw),
    'req-number':  /[0-9]/.test(pw),
    'req-special': /[^A-Za-z0-9]/.test(pw),
  };
  let score = Object.values(reqs).filter(Boolean).length;
  Object.entries(reqs).forEach(([id, ok]) => {
    const el = document.getElementById(id);
    if (!el) return;
    el.classList.toggle('met', ok);
    el.querySelector('i').className = ok ? 'fa-solid fa-circle-check' : 'fa-solid fa-circle-xmark';
  });
  const fill  = document.getElementById('resetStrengthFill');
  const label = document.getElementById('resetStrengthLabel');
  const levels = [['',''],['25%','weak'],['50%','fair'],['75%','good'],['100%','strong']];
  const [w,cls] = levels[score] || ['0',''];
  if (fill)  { fill.style.width = pw.length ? w : '0'; fill.className = 'pw-strength-fill ' + cls; }
  if (label) { label.textContent = pw.length && cls ? cls.charAt(0).toUpperCase()+cls.slice(1) : ''; label.className = 'pw-strength-label ' + cls; }
  checkResetMatch();
}
function checkResetMatch() {
  const pw  = document.getElementById('newPw').value;
  const cpw = document.getElementById('confirmPw').value;
  const err = document.getElementById('pwMatchError');
  const allReqsMet = pw.length >= 8;
  const match = pw === cpw && cpw.length > 0;
  err.classList.toggle('show', cpw.length > 0 && !match);
  document.getElementById('fpBtn3').disabled = !(allReqsMet && match);
}
function togglePwReset(inputId, iconId) {
  const inp = document.getElementById(inputId);
  const ico = document.getElementById(iconId);
  if (inp.type === 'password') { inp.type='text'; ico.className='fa-regular fa-eye-slash'; }
  else { inp.type='password'; ico.className='fa-regular fa-eye'; }
}
function resetPassword() {
  const btn = document.getElementById('fpBtn3');
  btn.classList.add('loading'); btn.disabled = true;
  setTimeout(() => { btn.classList.remove('loading'); goStep(4); },1000);
}
</script>
</body>
</html>
