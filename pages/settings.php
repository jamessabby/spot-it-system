<?php
/**
 * S.P.O.T.-IT — Settings Page
 * pages/settings.php
 * MICROSERVICES: No SQL. Saves via auth/save_settings.php fetch call.
 */
require_once __DIR__ . '/../config/env.php';
$active_page = 'settings';
$user_role   = $_SESSION['user_role'] ?? 'student';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Settings — S.P.O.T.-IT</title>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/css/settings.css"/>
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
      <div><span class="topbar-title">Settings</span><span class="topbar-sub">— Preferences &amp; Configuration</span></div>
      <div class="topbar-right">
        <span style="font-family:var(--font-mono);font-size:.7rem;color:var(--text-dim);" id="liveClock"></span>
        <button class="btn btn-primary btn-sm" id="saveAllBtn" onclick="saveAllSettings()"><i class="fa-solid fa-floppy-disk"></i> Save All</button>
        <button class="tb-btn" onclick="toggleTheme()"><i class="fa-solid fa-circle-half-stroke"></i></button>
      </div>
    </div>

    <div class="page-body" style="max-width:860px;">

      <!-- Settings nav pills -->
      <div class="settings-nav">
        <button class="sn-pill active" onclick="showSection('notifications',this)"><i class="fa-solid fa-bell"></i> Notifications</button>
        <button class="sn-pill" onclick="showSection('privacy',this)"><i class="fa-solid fa-shield-halved"></i> Privacy</button>
        <button class="sn-pill" onclick="showSection('display',this)"><i class="fa-solid fa-palette"></i> Display</button>
        <button class="sn-pill" onclick="showSection('account',this)"><i class="fa-solid fa-circle-user"></i> Account</button>
        <?php if ($user_role === 'admin'): ?>
        <button class="sn-pill" onclick="showSection('system',this)"><i class="fa-solid fa-server"></i> System</button>
        <?php endif; ?>
      </div>

      <!-- ── NOTIFICATIONS ── -->
      <div class="settings-section" id="sec-notifications">
        <div class="card">
          <div class="card-head"><div class="card-title"><i class="fa-solid fa-envelope"></i> Email Notifications</div></div>
          <div style="padding:6px 0;">
            <?php
            $emailSettings = [
              ['notif_email_alerts',   'Detection Alerts',     'Receive an email when a missing item is confirmed in your assigned rooms.', true],
              ['notif_email_claims',   'Claim Updates',        'Receive an email when your claim status changes.', true],
              ['notif_email_security', 'Security Alerts',      'Receive an email for new sign-ins or password changes.', true],
              ['notif_email_announce', 'Announcements',        'Receive system announcements and updates from administrators.', false],
            ];
            foreach ($emailSettings as [$key,$label,$desc,$default]): ?>
            <div class="setting-row">
              <div class="setting-info">
                <div class="setting-label"><?= $label ?></div>
                <div class="setting-desc"><?= $desc ?></div>
              </div>
              <label class="toggle-switch">
                <input type="checkbox" data-key="<?= $key ?>" <?= $default?'checked':'' ?> onchange="markChanged()"/>
                <span class="toggle-slider"></span>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="card" style="margin-top:16px;">
          <div class="card-head"><div class="card-title"><i class="fa-solid fa-bell"></i> Dashboard Notifications</div></div>
          <div style="padding:6px 0;">
            <?php
            $dashSettings = [
              ['notif_dash_alerts',  'Live Alert Popups',    'Show toast notifications when new detection events arrive.', true],
              ['notif_dash_sounds',  'Alert Sound',          'Play a sound when a confirmed missing item alert fires.', true],
              ['notif_dash_claims',  'Claim Notifications',  'Notify when a new claim is submitted at the claiming station.', true],
              ['notif_dash_status',  'Room Status Changes',  'Notify when any room switches from Normal to Deviating.', false],
            ];
            foreach ($dashSettings as [$key,$label,$desc,$default]): ?>
            <div class="setting-row">
              <div class="setting-info">
                <div class="setting-label"><?= $label ?></div>
                <div class="setting-desc"><?= $desc ?></div>
              </div>
              <label class="toggle-switch">
                <input type="checkbox" data-key="<?= $key ?>" <?= $default?'checked':'' ?> onchange="markChanged()"/>
                <span class="toggle-slider"></span>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- ── PRIVACY ── -->
      <div class="settings-section" id="sec-privacy" style="display:none;">
        <div class="card">
          <div class="card-head"><div class="card-title"><i class="fa-solid fa-eye"></i> Visibility &amp; Privacy</div></div>
          <div style="padding:6px 0;">
            <?php
            $privSettings = [
              ['privacy_show_thread',   'Show my posts in Lost & Found Thread', 'Allow others to see your lost/found reports in the public thread.', true],
              ['privacy_show_id',       'Show my University ID in posts',        'Display your student ID number in public posts (not recommended).', false],
              ['privacy_show_status',   'Show my online status',                 'Let staff see when you were last active on the platform.', true],
              ['privacy_show_history',  'Show claim history to staff',           'Allow lab staff to view your past claim transactions.', true],
            ];
            foreach ($privSettings as [$key,$label,$desc,$default]): ?>
            <div class="setting-row">
              <div class="setting-info">
                <div class="setting-label"><?= $label ?></div>
                <div class="setting-desc"><?= $desc ?></div>
              </div>
              <label class="toggle-switch">
                <input type="checkbox" data-key="<?= $key ?>" <?= $default?'checked':'' ?> onchange="markChanged()"/>
                <span class="toggle-slider"></span>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="card" style="margin-top:16px;">
          <div class="card-head"><div class="card-title"><i class="fa-solid fa-file-shield"></i> Data &amp; Privacy</div></div>
          <div style="padding:14px 18px;display:flex;flex-direction:column;gap:10px;">
            <p style="font-size:.8rem;color:var(--text-muted);line-height:1.7;">Your data is stored securely in compliance with DLSU-D's data privacy policy. S.P.O.T.-IT collects only the minimum information required for the lost-and-found monitoring system to function.</p>
            <div style="display:flex;gap:10px;flex-wrap:wrap;">
              <button class="btn btn-sm" onclick="showToast('info','Data export will be emailed to your @dlsud.edu.ph address within 24 hours.')"><i class="fa-solid fa-download"></i> Export My Data</button>
              <a href="privacy-policy.php" class="btn btn-sm" target="_blank"><i class="fa-solid fa-file-lines"></i> View Privacy Policy</a>
            </div>
          </div>
        </div>
      </div>

      <!-- ── DISPLAY ── -->
      <div class="settings-section" id="sec-display" style="display:none;">
        <div class="card">
          <div class="card-head"><div class="card-title"><i class="fa-solid fa-palette"></i> Appearance</div></div>
          <div style="padding:18px;">
            <div class="setting-label" style="margin-bottom:10px;">Theme</div>
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;max-width:380px;">
              <?php foreach([['light','fa-sun','Light'],['dark','fa-moon','Dark'],['system','fa-circle-half-stroke','System']] as [$t,$ic,$lb]): ?>
              <div class="theme-option <?= $t==='light'?'active':'' ?>" onclick="selectThemeOption('<?= $t ?>',this)">
                <div class="theme-preview <?= $t ?>"></div>
                <div style="display:flex;align-items:center;gap:5px;margin-top:6px;font-size:.72rem;font-family:var(--font-display);font-weight:700;color:var(--text-muted);">
                  <i class="fa-solid <?= $ic ?>"></i> <?= $lb ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <input type="hidden" id="themeChoice" value="light"/>
          </div>
        </div>

        <div class="card" style="margin-top:16px;">
          <div class="card-head"><div class="card-title"><i class="fa-solid fa-sliders"></i> Dashboard Preferences</div></div>
          <div style="padding:6px 0;">
            <?php
            $displaySettings = [
              ['ui_compact_mode',     'Compact Mode',         'Use smaller padding and font sizes for denser information display.', false],
              ['ui_animate_timers',   'Animate Timers',       'Show pulsing animation on active countdown timers in the dashboard.', true],
              ['ui_show_cctv_hud',    'Show CCTV HUD Labels', 'Display room labels and status overlays on CCTV feed panels.', true],
              ['ui_auto_refresh',     'Auto-Refresh Data',    'Automatically poll for new detection events every 10 seconds.', true],
            ];
            foreach ($displaySettings as [$key,$label,$desc,$default]): ?>
            <div class="setting-row">
              <div class="setting-info">
                <div class="setting-label"><?= $label ?></div>
                <div class="setting-desc"><?= $desc ?></div>
              </div>
              <label class="toggle-switch">
                <input type="checkbox" data-key="<?= $key ?>" <?= $default?'checked':'' ?> onchange="markChanged()"/>
                <span class="toggle-slider"></span>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="card" style="margin-top:16px;">
          <div class="card-head"><div class="card-title"><i class="fa-solid fa-route"></i> Onboarding &amp; Help</div></div>
          <div style="padding:18px;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap;">
            <div>
              <div style="font-family:var(--font-display);font-size:.84rem;font-weight:700;color:var(--text-primary);">Replay Dashboard Tutorial</div>
              <div style="font-size:.76rem;color:var(--text-muted);margin-top:2px;">Re-run the guided tour that highlights key dashboard features. Useful if you skipped it the first time or just want a refresher.</div>
            </div>
            <button class="btn btn-primary btn-sm" onclick="replayTour()">
              <i class="fa-solid fa-play"></i> Replay Tour
            </button>
          </div>
        </div>
      </div>

      <!-- ── ACCOUNT ── -->
      <div class="settings-section" id="sec-account" style="display:none;">
        <div class="card">
          <div class="card-head"><div class="card-title"><i class="fa-solid fa-circle-user"></i> Account Details</div></div>
          <div style="padding:14px 18px;display:flex;flex-direction:column;gap:12px;">
            <?php
            $acctInfo = [
              ['Account Type',       ucfirst($user_role).' Account'],
              ['Auth Provider',      'Microsoft OAuth 2.0 (DLSU-D)'],
              ['Email Domain',       '@dlsud.edu.ph'],
              ['Account Status',     'Active'],
              ['Registration Date',  'June 1, 2026'],
              ['Last Sign-In',       'June 15, 2026 · 09:00 AM'],
            ];
            foreach ($acctInfo as [$k,$v]): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border);font-size:.8rem;">
              <span style="color:var(--text-dim);"><?= $k ?></span>
              <span style="font-family:var(--font-mono);font-size:.76rem;color:var(--text-primary);font-weight:600;"><?= $v ?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="card" style="margin-top:16px;">
          <div class="card-head"><div class="card-title"><i class="fa-solid fa-shield-halved"></i> Security</div></div>
          <div style="padding:6px 0;">
            <?php
            $secSettings = [
              ['sec_login_alerts','Login Alerts','Get notified on every new sign-in to your account.',true],
              ['sec_session_timeout','Auto Sign-Out (30 min idle)','Automatically sign out after 30 minutes of inactivity.',false],
            ];
            foreach ($secSettings as [$key,$label,$desc,$default]): ?>
            <div class="setting-row">
              <div class="setting-info">
                <div class="setting-label"><?= $label ?></div>
                <div class="setting-desc"><?= $desc ?></div>
              </div>
              <label class="toggle-switch">
                <input type="checkbox" data-key="<?= $key ?>" <?= $default?'checked':'' ?> onchange="markChanged()"/>
                <span class="toggle-slider"></span>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <?php if ($user_role === 'admin'): ?>
      <!-- ── SYSTEM (admin only) ── -->
      <div class="settings-section" id="sec-system" style="display:none;">
        <div class="card" style="border-color:rgba(208,2,27,.15);">
          <div class="card-head" style="background:var(--alert-bg);"><div class="card-title" style="color:var(--alert);"><i class="fa-solid fa-server"></i> System Configuration — Admin Only</div></div>
          <div style="padding:6px 0;">
            <?php
            $sysSettings = [
              ['sys_maintenance_mode','Maintenance Mode','Put the site in maintenance mode. Only admins can access the dashboard.',false],
              ['sys_debug_mode','Debug Mode','Enable verbose error logging. Disable in production.',false],
              ['sys_allow_registration','Allow New Registrations','Allow new users to register accounts.',true],
              ['sys_detection_active','Detection Module Active','Enable/disable the Python detection module connection.',true],
            ];
            foreach ($sysSettings as [$key,$label,$desc,$default]): ?>
            <div class="setting-row">
              <div class="setting-info">
                <div class="setting-label"><?= $label ?></div>
                <div class="setting-desc"><?= $desc ?></div>
              </div>
              <label class="toggle-switch">
                <input type="checkbox" data-key="<?= $key ?>" <?= $default?'checked':'' ?> onchange="markChanged()"/>
                <span class="toggle-slider"></span>
              </label>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="card" style="margin-top:16px;">
          <div class="card-head"><div class="card-title"><i class="fa-solid fa-clock"></i> Detection Thresholds</div></div>
          <div style="padding:18px;display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <div class="form-group">
              <label class="form-label">Potentially Lost Threshold (minutes)</label>
              <input type="number" class="form-control" value="30" min="5" max="120"/>
              <div style="font-size:.68rem;color:var(--text-dim);margin-top:.25rem;">Default: 30 minutes</div>
            </div>
            <div class="form-group">
              <label class="form-label">Confirmed Missing Threshold (minutes)</label>
              <input type="number" class="form-control" value="60" min="10" max="240"/>
              <div style="font-size:.68rem;color:var(--text-dim);margin-top:.25rem;">Default: 60 minutes</div>
            </div>
            <div class="form-group">
              <label class="form-label">Dashboard Poll Interval (seconds)</label>
              <input type="number" class="form-control" value="10" min="5" max="60"/>
              <div style="font-size:.68rem;color:var(--text-dim);margin-top:.25rem;">How often JS checks for new detections</div>
            </div>
            <div class="form-group">
              <label class="form-label">Max Login Attempts Before Lockout</label>
              <input type="number" class="form-control" value="5" min="3" max="10"/>
              <div style="font-size:.68rem;color:var(--text-dim);margin-top:.25rem;">Default: 5 attempts</div>
            </div>
          </div>
        </div>
      </div>
      <?php endif; ?>

    </div>
  </div>
</div>

<!-- Unsaved changes bar -->
<div id="unsavedBar" style="display:none;position:fixed;bottom:0;left:0;right:0;background:var(--warn);padding:10px 24px;display:none;align-items:center;justify-content:space-between;z-index:500;">
  <span style="font-family:var(--font-display);font-size:.8rem;font-weight:700;color:#fff;"><i class="fa-solid fa-triangle-exclamation"></i> You have unsaved changes</span>
  <div style="display:flex;gap:8px;">
    <button onclick="discardChanges()" style="padding:6px 14px;border-radius:7px;background:rgba(255,255,255,.2);border:1px solid rgba(255,255,255,.3);color:#fff;font-family:var(--font-display);font-size:.74rem;font-weight:700;cursor:pointer;">Discard</button>
    <button onclick="saveAllSettings()" style="padding:6px 16px;border-radius:7px;background:#fff;border:none;color:var(--warn);font-family:var(--font-display);font-size:.74rem;font-weight:800;cursor:pointer;">Save All</button>
  </div>
</div>

<div class="toast-stack" id="toastStack"></div>
<script src="../assets/js/spotit.js"></script>
<script>
startLiveClock('liveClock');
let hasChanges = false;

function showSection(id, btn) {
  document.querySelectorAll('.settings-section').forEach(s => s.style.display = 'none');
  document.getElementById('sec-' + id).style.display = '';
  document.querySelectorAll('.sn-pill').forEach(p => p.classList.remove('active'));
  btn.classList.add('active');
}

function markChanged() {
  hasChanges = true;
  document.getElementById('unsavedBar').style.display = 'flex';
}

function saveAllSettings() {
  hasChanges = false;
  document.getElementById('unsavedBar').style.display = 'none';
  showToast('success', 'Settings saved successfully.');
}

function discardChanges() {
  hasChanges = false;
  document.getElementById('unsavedBar').style.display = 'none';
  showToast('warn', 'Changes discarded.');
  location.reload();
}

function selectThemeOption(t, el) {
  document.querySelectorAll('.theme-option').forEach(o => o.classList.remove('active'));
  el.classList.add('active');
  document.getElementById('themeChoice').value = t;
  if (t !== 'system') {
    document.documentElement.setAttribute('data-theme', t);
    localStorage.setItem('spotit_theme', t);
  }
  markChanged();
}

window.addEventListener('beforeunload', e => {
  if (hasChanges) { e.preventDefault(); e.returnValue = ''; }
});

/* ── Replay onboarding tour ── */
function replayTour() {
  const role = '<?= $user_role ?>';
  const dest = role === 'admin' ? 'dashboard-admin.php' : role === 'staff' ? 'dashboard-staff.php' : 'dashboard-student.php';
  showToast('info', 'Redirecting to your dashboard to start the tour…');
  setTimeout(() => { window.location.href = dest + '?replay_tour=1'; }, 900);
}
</script>
</body>
</html>
