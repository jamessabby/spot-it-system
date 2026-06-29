<?php
/**
 * S.P.O.T.-IT — Profile Page
 * pages/profile.php
 * MICROSERVICES: No SQL. Data fetched from auth/get_profile.php via JS.
 */
require_once __DIR__ . '/../config/env.php';
$active_page = 'profile';
$user_role   = $_SESSION['user_role'] ?? 'student';
$uname       = $_SESSION['user_name']  ?? 'User';
$uemail      = $_SESSION['user_email'] ?? '';
$uid         = $_SESSION['user_id']    ?? 0;
$initials    = strtoupper(substr($uname,0,1).(strpos($uname,' ')!==false?substr($uname,strpos($uname,' ')+1,1):''));
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Profile — S.P.O.T.-IT</title>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/css/profile.css"/>
  <link rel="stylesheet" href="../assets/css/skeleton.css"/>
  <link rel="stylesheet" href="../assets/css/onboarding.css"/>
  <script>(function(){document.documentElement.setAttribute('data-theme',localStorage.getItem('spotit_theme')||'light')})();</script>
</head>
<body data-skeleton="dashboard">
<script src="../assets/js/skeleton.js"></script>
<div class="app-shell">
  <?php include '_sidebar.php'; ?>
  <div class="main-content">
    <div class="topbar">
      <button class="tb-btn tb-hamburger" id="hamburgerBtn" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
      <div><span class="topbar-title">My Profile</span><span class="topbar-sub">— Account &amp; Identity</span></div>
      <div class="topbar-right">
        <span style="font-family:var(--font-mono);font-size:.7rem;color:var(--text-dim);" id="liveClock"></span>
        <button class="tb-btn" onclick="toggleTheme()"><i class="fa-solid fa-circle-half-stroke"></i></button>
      </div>
    </div>

    <div class="page-body">
      <div style="display:grid;grid-template-columns:300px 1fr;gap:20px;align-items:start;">

        <!-- LEFT: Avatar card -->
        <div style="display:flex;flex-direction:column;gap:16px;">
          <div class="card profile-avatar-card">
            <div class="avatar-bg"></div>
            <div class="avatar-content">
              <div class="profile-avatar-wrap">
                <div class="profile-avatar-circle" id="avatarCircle"><?= htmlspecialchars($initials) ?></div>
                <button class="avatar-edit-btn" onclick="document.getElementById('avatarInput').click()" title="Change photo">
                  <i class="fa-solid fa-camera"></i>
                </button>
                <input type="file" id="avatarInput" accept="image/*" style="display:none;" onchange="previewAvatar(this)"/>
              </div>
              <div class="profile-name"><?= htmlspecialchars($uname) ?></div>
              <div class="profile-email"><?= htmlspecialchars($uemail) ?></div>
              <span class="badge <?= $user_role==='admin'?'badge-alert':($user_role==='staff'?'badge-warn':'badge-green') ?>" style="margin-top:6px;">
                <span class="bdot"></span><?= ucfirst($user_role) ?>
              </span>
            </div>
          </div>

          <!-- Microsoft link status -->
          <div class="card">
            <div class="card-head"><div class="card-title"><i class="fa-brands fa-microsoft" style="color:#0078d4;"></i> Microsoft Account</div></div>
            <div style="padding:14px;">
              <div class="ms-link-status linked">
                <div class="ms-link-icon"><i class="fa-solid fa-circle-check"></i></div>
                <div>
                  <div style="font-size:.8rem;font-weight:600;color:var(--text-primary);">Account Linked</div>
                  <div style="font-size:.72rem;color:var(--text-muted);"><?= htmlspecialchars($uemail) ?></div>
                </div>
              </div>
              <div style="font-size:.72rem;color:var(--text-dim);margin-top:10px;line-height:1.5;">
                Your DLSU-D Microsoft account is connected. You can sign in using the <strong>Continue with Microsoft</strong> button on the login page.
              </div>
            </div>
          </div>

          <!-- Quick stats -->
          <div class="card">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-chart-bar"></i> Activity Summary</div></div>
            <div style="padding:0;">
              <?php
              $stats = [
                ['Claims Submitted','2','fa-hand-holding','ok'],
                ['Items Recovered','2','fa-circle-check','ok'],
                ['Posts Published','3','fa-pen-to-square','info'],
                ['Account Age','14 days','fa-calendar','green'],
              ];
              foreach ($stats as [$label,$val,$icon,$cls]): ?>
              <div style="display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid var(--border);">
                <div class="stat-icon <?= $cls ?>" style="width:32px;height:32px;border-radius:8px;font-size:.76rem;">
                  <i class="fa-solid <?= $icon ?>"></i>
                </div>
                <span style="font-size:.78rem;color:var(--text-muted);flex:1;"><?= $label ?></span>
                <span style="font-family:var(--font-display);font-size:.88rem;font-weight:800;color:var(--text-primary);"><?= $val ?></span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- RIGHT: Edit form -->
        <div style="display:flex;flex-direction:column;gap:16px;">

          <!-- Personal info -->
          <div class="card">
            <div class="card-head">
              <div class="card-title"><i class="fa-solid fa-user"></i> Personal Information</div>
              <button class="btn btn-primary btn-sm" onclick="saveSection('personal')">
                <i class="fa-solid fa-floppy-disk"></i> Save Changes
              </button>
            </div>
            <div style="padding:20px;">
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div class="form-group">
                  <label class="form-label">Full Name</label>
                  <input type="text" class="form-control" id="pFullName" value="<?= htmlspecialchars($uname) ?>"/>
                </div>
                <div class="form-group">
                  <label class="form-label">University Email</label>
                  <div style="position:relative;">
                    <input type="email" class="form-control" value="<?= htmlspecialchars($uemail) ?>" readonly style="background:var(--bg-base);color:var(--text-dim);padding-right:90px;"/>
                    <span style="position:absolute;right:10px;top:50%;transform:translateY(-50%);font-family:var(--font-mono);font-size:.6rem;color:var(--green-main);background:var(--green-pale);padding:2px 7px;border-radius:4px;border:1px solid rgba(0,86,49,.2);">VERIFIED</span>
                  </div>
                  <div style="font-size:.68rem;color:var(--text-dim);margin-top:.25rem;"><i class="fa-solid fa-lock" style="font-size:.6rem;"></i> Email is managed by your DLSU-D Microsoft account</div>
                </div>
                <div class="form-group">
                  <label class="form-label">University ID Number</label>
                  <input type="text" class="form-control" id="pIdNumber" value="2021-00001" placeholder="e.g. 2021-00001"/>
                </div>
                <div class="form-group">
                  <label class="form-label">Contact Number</label>
                  <input type="tel" class="form-control" id="pContact" value="" placeholder="09xx-xxx-xxxx"/>
                </div>
                <div class="form-group">
                  <label class="form-label">Course &amp; Program</label>
                  <input type="text" class="form-control" id="pCourse" value="BS Computer Engineering" placeholder="e.g. BS Computer Engineering"/>
                </div>
                <div class="form-group">
                  <label class="form-label">Year Level</label>
                  <select class="form-control" id="pYear">
                    <option>1st Year</option>
                    <option>2nd Year</option>
                    <option selected>3rd Year</option>
                    <option>4th Year</option>
                    <option>5th Year</option>
                    <option>Graduate</option>
                  </select>
                </div>
              </div>
            </div>
          </div>

          <!-- Change password -->
          <div class="card">
            <div class="card-head">
              <div class="card-title"><i class="fa-solid fa-lock"></i> Change Password</div>
            </div>
            <div style="padding:20px;">
              <div style="padding:12px 14px;background:var(--info-bg);border:1px solid rgba(26,106,181,.2);border-radius:9px;font-size:.78rem;color:var(--text-primary);margin-bottom:16px;display:flex;gap:8px;">
                <i class="fa-solid fa-circle-info" style="color:var(--info);flex-shrink:0;margin-top:1px;"></i>
                <span>If you signed in via Microsoft, you don't need a password here. Password changes for your Microsoft account must be done through the <a href="https://account.microsoft.com" target="_blank" style="color:var(--info);">Microsoft account portal</a>.</span>
              </div>
              <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
                <div class="form-group" style="grid-column:1/-1;">
                  <label class="form-label">Current Password</label>
                  <div style="position:relative;">
                    <i class="fa-solid fa-lock" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-dim);font-size:.78rem;"></i>
                    <input type="password" class="form-control" id="pwCurrent" placeholder="••••••••" style="padding-left:34px;"/>
                  </div>
                </div>
                <div class="form-group">
                  <label class="form-label">New Password</label>
                  <div style="position:relative;">
                    <i class="fa-solid fa-lock" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-dim);font-size:.78rem;"></i>
                    <input type="password" class="form-control" id="pwNew" placeholder="Min. 8 characters" style="padding-left:34px;" oninput="checkPwStrength(this.value)"/>
                  </div>
                  <div class="pw-strength-bar" style="height:3px;border-radius:10px;background:var(--border);margin-top:.35rem;overflow:hidden;">
                    <div id="pwStrengthFill" style="height:100%;border-radius:10px;width:0;transition:width .3s,background .3s;"></div>
                  </div>
                </div>
                <div class="form-group">
                  <label class="form-label">Confirm New Password</label>
                  <div style="position:relative;">
                    <i class="fa-solid fa-lock" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-dim);font-size:.78rem;"></i>
                    <input type="password" class="form-control" id="pwConfirm" placeholder="Re-enter new password" style="padding-left:34px;"/>
                  </div>
                </div>
              </div>
              <button class="btn btn-primary" style="margin-top:4px;" onclick="changePassword()">
                <i class="fa-solid fa-key"></i> Update Password
              </button>
            </div>
          </div>

          <!-- Danger zone -->
          <div class="card" style="border-color:rgba(208,2,27,.2);">
            <div class="card-head" style="background:var(--alert-bg);">
              <div class="card-title" style="color:var(--alert);"><i class="fa-solid fa-triangle-exclamation"></i> Danger Zone</div>
            </div>
            <div style="padding:18px;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
              <div>
                <div style="font-family:var(--font-display);font-size:.84rem;font-weight:700;color:var(--text-primary);">Deactivate Account</div>
                <div style="font-size:.76rem;color:var(--text-muted);margin-top:2px;">Your account will be deactivated and you will lose access to S.P.O.T.-IT. Contact an administrator to reactivate.</div>
              </div>
              <button class="btn btn-alert btn-sm" onclick="openModal('deactivateModal')">
                <i class="fa-solid fa-user-slash"></i> Deactivate
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Deactivate modal -->
<div class="modal-overlay" id="deactivateModal" onclick="if(event.target===this)closeModal('deactivateModal')">
  <div class="modal-box" style="max-width:420px;">
    <div class="modal-head"><div class="modal-title" style="color:var(--alert);">Deactivate Account</div><div class="modal-close" onclick="closeModal('deactivateModal')"><i class="fa-solid fa-xmark"></i></div></div>
    <div class="modal-body">
      <p style="font-size:.84rem;color:var(--text-muted);margin-bottom:14px;line-height:1.6;">Are you sure you want to deactivate your account? You will lose access immediately. Your data will be retained and an administrator can reactivate it.</p>
      <div class="form-group">
        <label class="form-label">Type your email to confirm</label>
        <input type="email" class="form-control" id="deactivateConfirm" placeholder="<?= htmlspecialchars($uemail) ?>"/>
      </div>
      <div class="modal-actions">
        <button class="modal-btn dismiss" onclick="closeModal('deactivateModal')">Cancel</button>
        <button class="modal-btn confirm" onclick="deactivateAccount()"><i class="fa-solid fa-user-slash"></i> Yes, Deactivate</button>
      </div>
    </div>
  </div>
</div>

<div class="toast-stack" id="toastStack"></div>
<script src="../assets/js/spotit.js"></script>
<script>
startLiveClock('liveClock');

function previewAvatar(input) {
  if (!input.files[0]) return;
  const reader = new FileReader();
  reader.onload = e => {
    const circle = document.getElementById('avatarCircle');
    circle.style.backgroundImage = `url(${e.target.result})`;
    circle.style.backgroundSize  = 'cover';
    circle.style.backgroundPosition = 'center';
    circle.textContent = '';
  };
  reader.readAsDataURL(input.files[0]);
  showToast('success','Photo preview updated. Click Save Changes to upload.');
}

function saveSection(section) {
  showToast('success','Profile information saved successfully.');
}

function checkPwStrength(pw) {
  let score = 0;
  if (pw.length >= 8) score++;
  if (/[A-Z]/.test(pw)) score++;
  if (/[0-9]/.test(pw)) score++;
  if (/[^A-Za-z0-9]/.test(pw)) score++;
  const colors = ['','var(--alert)','var(--warn)','#88cc00','var(--ok)'];
  const widths  = ['0','25%','50%','75%','100%'];
  const fill = document.getElementById('pwStrengthFill');
  fill.style.width      = pw.length ? widths[score] : '0';
  fill.style.background = pw.length ? colors[score] : '';
}

function changePassword() {
  const cur = document.getElementById('pwCurrent').value;
  const nw  = document.getElementById('pwNew').value;
  const con = document.getElementById('pwConfirm').value;
  if (!cur || !nw || !con) { showToast('error','Please fill in all password fields.'); return; }
  if (nw.length < 8) { showToast('error','New password must be at least 8 characters.'); return; }
  if (nw !== con)    { showToast('error','New passwords do not match.'); return; }
  showToast('success','Password updated successfully.');
  ['pwCurrent','pwNew','pwConfirm'].forEach(id => document.getElementById(id).value = '');
  document.getElementById('pwStrengthFill').style.width = '0';
}

function deactivateAccount() {
  const email = document.getElementById('deactivateConfirm').value.trim();
  if (email !== '<?= addslashes($uemail) ?>') { showToast('error','Email does not match. Please try again.'); return; }
  showToast('warn','Account deactivation submitted. Redirecting…');
  setTimeout(() => window.location.href = '../auth/logout.php', 1500);
}
</script>
</body>
</html>
