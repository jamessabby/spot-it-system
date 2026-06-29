<?php
/**
 * S.P.O.T.-IT — Shared Sidebar Component (UPDATED)
 * pages/_sidebar.php
 */
$active  = $active_page  ?? '';
$role    = $user_role    ?? ($_SESSION['user_role'] ?? 'student');
$uname   = $_SESSION['user_name']  ?? 'User';
$uemail  = $_SESSION['user_email'] ?? '';
$initials = strtoupper(
  substr($uname, 0, 1) .
  (strpos($uname, ' ') !== false ? substr($uname, strpos($uname,' ')+1, 1) : '')
);
?>
<aside class="sidebar" id="sidebar">
  <div class="sb-brand">
    <a href="index.php" class="sb-logo">
      <div class="sb-logo-icon">S</div>
      <div>
        <div class="sb-logo-name">S.P.O.T.-IT</div>
        <div class="sb-logo-sub"><?= match($role){'admin'=>'Admin Panel','staff'=>'Staff Panel',default=>'Student Portal'} ?></div>
      </div>
    </a>
  </div>

  <?php if ($role === 'admin' || $role === 'staff'): ?>
  <div class="sb-section">
    <div class="sb-section-label">Monitoring</div>
    <a href="dashboard-admin.php" class="sb-item <?= $active==='dashboard'?'active':'' ?>">
      <i class="fa-solid fa-gauge-high"></i> Overview
      <span class="sb-badge sb-badge-alert" id="sb-badge-alerts" style="display:none">0</span>
    </a>
    <a href="room-monitor.php" class="sb-item <?= $active==='rooms'?'active':'' ?>">
      <i class="fa-solid fa-video"></i> Room Monitor
    </a>
    <a href="#" class="sb-item <?= $active==='alerts'?'active':'' ?>">
      <i class="fa-solid fa-triangle-exclamation"></i> Alerts
      <span class="sb-badge sb-badge-warn" id="sb-badge-warn" style="display:none">0</span>
    </a>
  </div>

  <div class="sb-section">
    <div class="sb-section-label">Lost &amp; Found</div>
    <a href="lost-thread.php" class="sb-item <?= $active==='thread'?'active':'' ?>">
      <i class="fa-solid fa-list-check"></i> Event Log
    </a>
    <a href="#" class="sb-item <?= $active==='surrender'?'active':'' ?>">
      <i class="fa-solid fa-inbox"></i> Surrender Log
    </a>
    <a href="claiming-station.php" class="sb-item <?= $active==='claiming'?'active':'' ?>">
      <i class="fa-solid fa-hand-holding"></i> Claiming Station
      <span class="sb-badge sb-badge-ok" id="sb-badge-claims" style="display:none">0</span>
    </a>
    <a href="#" class="sb-item <?= $active==='recovered'?'active':'' ?>">
      <i class="fa-solid fa-box-open"></i> Recovered Items
    </a>
  </div>
  <?php endif; ?>

  <?php if ($role === 'admin'): ?>
  <div class="sb-section">
    <div class="sb-section-label">Management</div>
    <a href="admin-audit.php" class="sb-item <?= $active==='audit'?'active':'' ?>">
      <i class="fa-solid fa-chart-line"></i> Analytics &amp; Audit
    </a>
    <a href="#" class="sb-item <?= $active==='setup'?'active':'' ?>">
      <i class="fa-solid fa-sliders"></i> Room Setup
    </a>
    <a href="#" class="sb-item <?= $active==='users'?'active':'' ?>">
      <i class="fa-solid fa-users"></i> Users
    </a>
    <a href="#" class="sb-item <?= $active==='logs'?'active':'' ?>">
      <i class="fa-solid fa-database"></i> System Logs
    </a>
  </div>
  <?php endif; ?>

  <?php if ($role === 'student'): ?>
  <div class="sb-section">
    <div class="sb-section-label">Lost &amp; Found</div>
    <a href="lost-thread.php" class="sb-item <?= $active==='thread'?'active':'' ?>">
      <i class="fa-solid fa-magnifying-glass"></i> Browse Items
    </a>
    <a href="dashboard-student.php" class="sb-item <?= $active==='student'?'active':'' ?>">
      <i class="fa-solid fa-clock-rotate-left"></i> My Claims
    </a>
    <a href="my-posts.php" class="sb-item <?= $active==='posts'?'active':'' ?>">
      <i class="fa-solid fa-pen-to-square"></i> My Posts
    </a>
  </div>
  <?php endif; ?>

  <?php if ($role === 'staff' || $role === 'admin'): ?>
  <div class="sb-section">
    <div class="sb-section-label">My Activity</div>
    <a href="my-posts.php" class="sb-item <?= $active==='posts'?'active':'' ?>">
      <i class="fa-solid fa-pen-to-square"></i> My Posts
    </a>
  </div>
  <?php endif; ?>

  <div class="sb-section">
    <div class="sb-section-label">Account</div>
    <a href="profile.php" class="sb-item <?= $active==='profile'?'active':'' ?>">
      <i class="fa-solid fa-circle-user"></i> Profile
    </a>
    <a href="settings.php" class="sb-item <?= $active==='settings'?'active':'' ?>">
      <i class="fa-solid fa-gear"></i> Settings
    </a>
    <a href="#" class="sb-item" onclick="toggleTheme(); return false;">
      <i class="fa-solid fa-circle-half-stroke"></i> Toggle Theme
    </a>
    <a href="../auth/logout.php" class="sb-item sb-item-logout">
      <i class="fa-solid fa-right-from-bracket"></i> Sign Out
    </a>
  </div>

  <div class="sb-footer">
    <a href="profile.php" class="sb-user" style="text-decoration:none;">
      <div class="sb-avatar"><?= htmlspecialchars($initials) ?></div>
      <div class="sb-user-info">
        <div class="sb-user-name"><?= htmlspecialchars($uname) ?></div>
        <div class="sb-user-role"><?= ucfirst($role) ?></div>
      </div>
      <i class="fa-solid fa-chevron-right" style="font-size:.6rem;color:var(--text-dim);margin-left:auto;"></i>
    </a>
  </div>
</aside>
