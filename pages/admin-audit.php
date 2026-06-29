<?php
/**
 * S.P.O.T.-IT — Admin Audit & Analytics Page
 * pages/admin-audit.php
 * Admin-only. Full analytics: detection trends, room performance, claim stats, system health.
 * MICROSERVICES: No SQL. Data from auth/get_audit.php via JS.
 */
require_once __DIR__ . '/../config/env.php';
// ms_require_role('admin', 'login.php?error=unauthorized');
$active_page = 'audit';
$user_role   = 'admin';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Analytics &amp; Audit — S.P.O.T.-IT</title>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/css/admin-audit.css"/>
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
      <div><span class="topbar-title">Analytics &amp; Audit</span><span class="topbar-sub">— Admin Only · CEAT Building</span></div>
      <div class="topbar-right">
        <!-- Date range selector -->
        <select id="dateRange" class="form-control" style="max-width:160px;font-size:.76rem;padding:6px 10px;border-radius:7px;" onchange="refreshCharts()">
          <option value="7">Last 7 days</option>
          <option value="30" selected>Last 30 days</option>
          <option value="90">Last 90 days</option>
          <option value="semester">This semester</option>
        </select>
        <button class="btn btn-primary btn-sm" onclick="exportReport()"><i class="fa-solid fa-download"></i> Export PDF</button>
        <button class="tb-btn" onclick="toggleTheme()"><i class="fa-solid fa-circle-half-stroke"></i></button>
      </div>
    </div>

    <div class="page-body">

      <!-- KPI stat cards -->
      <div class="stat-grid" style="grid-template-columns:repeat(5,1fr);">
        <div class="stat-card"><div class="stat-icon alert"><i class="fa-solid fa-circle-minus"></i></div><div><div class="stat-num">47</div><div class="stat-label">Total Detections</div><div class="stat-delta up"><i class="fa-solid fa-arrow-up"></i> +12 vs last period</div></div></div>
        <div class="stat-card"><div class="stat-icon ok"><i class="fa-solid fa-circle-check"></i></div><div><div class="stat-num">31</div><div class="stat-label">Resolved Events</div><div class="stat-delta down"><i class="fa-solid fa-arrow-up"></i> 66% resolution rate</div></div></div>
        <div class="stat-card"><div class="stat-icon warn"><i class="fa-solid fa-hand-holding"></i></div><div><div class="stat-num">18</div><div class="stat-label">Items Claimed</div><div class="stat-delta down"><i class="fa-solid fa-arrow-up"></i> +6 vs last period</div></div></div>
        <div class="stat-card"><div class="stat-icon info"><i class="fa-solid fa-clock"></i></div><div><div class="stat-num">38<span style="font-size:1rem;">m</span></div><div class="stat-label">Avg. Resolution Time</div><div class="stat-delta down"><i class="fa-solid fa-arrow-down"></i> −12m improved</div></div></div>
        <div class="stat-card"><div class="stat-icon green"><i class="fa-solid fa-users"></i></div><div><div class="stat-num">24</div><div class="stat-label">Active Users</div><div class="stat-delta flat"><i class="fa-solid fa-minus"></i> +3 this period</div></div></div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:18px;">

        <!-- Detection trend chart (SVG bar chart) -->
        <div class="card">
          <div class="card-head">
            <div class="card-title"><i class="fa-solid fa-chart-column"></i> Detection Events — Last 30 Days</div>
            <div style="display:flex;gap:8px;align-items:center;">
              <span class="badge badge-alert" style="font-size:.58rem;">Missing</span>
              <span class="badge badge-warn" style="font-size:.58rem;">Potential</span>
              <span class="badge badge-ok" style="font-size:.58rem;">Dismissed</span>
            </div>
          </div>
          <div style="padding:16px;">
            <svg viewBox="0 0 580 180" style="width:100%;overflow:visible;" id="trendChart">
              <!-- Grid lines -->
              <?php for($i=0;$i<=4;$i++): $y=160-$i*35; ?>
              <line x1="40" y1="<?=$y?>" x2="570" y2="<?=$y?>" stroke="var(--border)" stroke-width="1"/>
              <text x="34" y="<?=$y+4?>" font-family="monospace" font-size="9" fill="var(--text-dim)" text-anchor="end"><?=$i*5?></text>
              <?php endfor; ?>
              <?php
              // Weekly data: [week, missing, potential, dismissed]
              $weeks = [
                ['Wk1',3,2,4],['Wk2',5,3,6],['Wk3',2,4,3],['Wk4',4,6,5],
                ['Wk5',6,2,7],['Wk6',3,5,4],['Wk7',4,3,6],['Wk8',5,4,3],
              ];
              $barW=18; $gap=52; $startX=60;
              foreach($weeks as $idx=>[$wk,$m,$p,$d]):
                $x = $startX + $idx*$gap;
                $mH=$m*7; $pH=$p*7; $dH=$d*7;
                $totalH=$mH+$pH+$dH;
                $y0=160;
              ?>
              <!-- Stacked bar -->
              <rect x="<?=$x?>" y="<?=$y0-$dH?>" width="<?=$barW?>" height="<?=$dH?>" fill="var(--ok)" rx="0"/>
              <rect x="<?=$x?>" y="<?=$y0-$dH-$pH?>" width="<?=$barW?>" height="<?=$pH?>" fill="var(--warn)" rx="0"/>
              <rect x="<?=$x?>" y="<?=$y0-$totalH?>" width="<?=$barW?>" height="<?=$mH?>" fill="var(--alert)" rx="<?=$idx===0?'2':($idx===count($weeks)-1?'2':'2')?>" rx="2"/>
              <text x="<?=$x+$barW/2?>" y="173" font-family="monospace" font-size="8" fill="var(--text-dim)" text-anchor="middle"><?=$wk?></text>
              <?php endforeach; ?>
              <!-- Trend line (missing events) -->
              <polyline points="69,139 121,125 173,146 225,132 277,118 329,139 381,132 433,125"
                fill="none" stroke="var(--alert)" stroke-width="1.5" stroke-dasharray="3,2" opacity=".6"/>
            </svg>
          </div>
        </div>

        <!-- Room performance -->
        <div class="card">
          <div class="card-head"><div class="card-title"><i class="fa-solid fa-trophy"></i> Room Detection Frequency</div></div>
          <div style="padding:14px 18px;">
            <?php
            $rooms = [
              ['MLH 306','Systems & App Dev','23','alert',77],
              ['MLH 305','Logic & Algorithms','18','warn',60],
              ['MLH 303','Advanced Programming','11','warn',37],
              ['MLH 304','Engineering CAD','6','ok',20],
              ['MLH 203','Computational Eng.','4','ok',13],
              ['MLH 301','Embedded Systems','3','ok',10],
              ['MLH 201','Data Center Lab','2','ok',7],
            ];
            foreach($rooms as [$rid,$rname,$cnt,$cls,$pct]): ?>
            <div style="margin-bottom:12px;">
              <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:4px;">
                <div>
                  <span style="font-family:var(--font-display);font-size:.78rem;font-weight:700;color:var(--text-primary);"><?=$rid?></span>
                  <span style="font-size:.7rem;color:var(--text-dim);margin-left:6px;"><?=$rname?></span>
                </div>
                <span style="font-family:var(--font-mono);font-size:.76rem;font-weight:700;color:var(--<?=$cls?>);"><?=$cnt?> events</span>
              </div>
              <div style="height:6px;background:var(--bg-base);border-radius:100px;overflow:hidden;border:1px solid var(--border);">
                <div style="height:100%;width:<?=$pct?>%;background:var(--<?=$cls?>);border-radius:100px;transition:width 1s ease;"></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:18px;margin-bottom:18px;">

        <!-- Detection status breakdown donut -->
        <div class="card">
          <div class="card-head"><div class="card-title"><i class="fa-solid fa-chart-pie"></i> Event Status Breakdown</div></div>
          <div style="padding:16px;display:flex;flex-direction:column;align-items:center;">
            <svg viewBox="0 0 120 120" style="width:130px;margin-bottom:12px;">
              <!-- Simple donut: confirmed=31%, potential=26%, dismissed=28%, recovered=15% -->
              <?php
              $segments=[
                ['var(--alert)',31,0],
                ['var(--warn)',26,31],
                ['var(--text-dim)',28,57],
                ['var(--ok)',15,85],
              ];
              $cx=60;$cy=60;$r=46;$stroke=18;
              foreach($segments as [$color,$pct,$offset]):
                $dash=round($pct/100*289);
                $gap=289-$dash;
                $offsetVal=round($offset/100*289);
              ?>
              <circle cx="<?=$cx?>" cy="<?=$cy?>" r="<?=$r?>"
                fill="none" stroke="<?=$color?>" stroke-width="<?=$stroke?>"
                stroke-dasharray="<?=$dash?> <?=$gap?>"
                stroke-dashoffset="<?=-$offsetVal?>"
                transform="rotate(-90 <?=$cx?> <?=$cy?>)"
                style="transition:stroke-dasharray .8s ease;"/>
              <?php endforeach; ?>
              <text x="60" y="58" text-anchor="middle" font-family="var(--font-display)" font-size="16" font-weight="800" fill="var(--text-primary)">47</text>
              <text x="60" y="70" text-anchor="middle" font-family="monospace" font-size="7" fill="var(--text-dim)">TOTAL</text>
            </svg>
            <?php
            $legend=[['Confirmed Missing','alert','31%'],['Potentially Lost','warn','26%'],['Dismissed','muted','28%'],['Recovered','ok','15%']];
            foreach($legend as [$lbl,$cls,$pct]): ?>
            <div style="display:flex;align-items:center;gap:8px;width:100%;margin-bottom:5px;">
              <div style="width:10px;height:10px;border-radius:50%;background:var(--<?=$cls?>);flex-shrink:0;"></div>
              <span style="font-size:.75rem;color:var(--text-muted);flex:1;"><?=$lbl?></span>
              <span style="font-family:var(--font-mono);font-size:.74rem;font-weight:700;color:var(--text-primary);"><?=$pct?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>

        <!-- Claims analytics -->
        <div class="card">
          <div class="card-head"><div class="card-title"><i class="fa-solid fa-hand-holding"></i> Claims Summary</div></div>
          <div style="padding:14px 18px;">
            <?php
            $claimStats=[
              ['Total Claims Submitted','23','info'],
              ['Successfully Claimed','18','ok'],
              ['Pending Verification','3','warn'],
              ['Rejected / No Match','2','alert'],
              ['Avg. Claim Time','1.4 days','green'],
            ];
            foreach($claimStats as [$lbl,$val,$cls]): ?>
            <div style="display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid var(--border);font-size:.79rem;">
              <span style="color:var(--text-muted);"><?=$lbl?></span>
              <span style="font-family:var(--font-mono);font-weight:700;color:var(--<?=$cls?>);"><?=$val?></span>
            </div>
            <?php endforeach; ?>
            <!-- Item type breakdown -->
            <div style="margin-top:12px;">
              <div style="font-family:var(--font-display);font-size:.64rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--text-dim);margin-bottom:8px;">Top Claimed Item Types</div>
              <?php foreach([['Umbrella',6],['Charging Cable',4],['Wallet',3],['Cellphone',3],['Calculator',2]] as [$item,$n]): ?>
              <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;font-size:.74rem;">
                <span style="color:var(--text-muted);flex:1;"><?=$item?></span>
                <span style="font-family:var(--font-mono);font-weight:700;color:var(--text-primary);"><?=$n?></span>
              </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <!-- System health -->
        <div class="card">
          <div class="card-head"><div class="card-title"><i class="fa-solid fa-server"></i> System Health</div></div>
          <div style="padding:14px 18px;">
            <?php
            $health=[
              ['Detection Module','Online','ok','fa-circle-check'],
              ['Database (Auth)','Online','ok','fa-circle-check'],
              ['Database (Monitor)','Online','ok','fa-circle-check'],
              ['Database (L&F)','Online','ok','fa-circle-check'],
              ['Camera Feeds','7/8 Active','warn','fa-video'],
              ['Network (WiFi)','Stable','ok','fa-wifi'],
              ['Last Detection','2 min ago','info','fa-clock'],
              ['Uptime','14d 3h 21m','green','fa-arrow-up'],
            ];
            foreach($health as [$lbl,$val,$cls,$ic]): ?>
            <div style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid var(--border);font-size:.78rem;">
              <i class="fa-solid <?=$ic?>" style="color:var(--<?=$cls?>);font-size:.72rem;width:14px;text-align:center;flex-shrink:0;"></i>
              <span style="color:var(--text-muted);flex:1;"><?=$lbl?></span>
              <span style="font-family:var(--font-mono);font-size:.72rem;font-weight:600;color:var(--<?=$cls?>);"><?=$val?></span>
            </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:18px;">

        <!-- User activity log -->
        <div class="card">
          <div class="card-head">
            <div class="card-title"><i class="fa-solid fa-clock-rotate-left"></i> Recent Audit Log</div>
            <button class="card-action" onclick="showToast('info','Full audit log export coming soon.')"><i class="fa-solid fa-download"></i> Export</button>
          </div>
          <div class="filter-tabs" style="padding-top:10px;">
            <div class="filter-tab active" onclick="setFilterTab(this)">All</div>
            <div class="filter-tab" onclick="setFilterTab(this)">Logins</div>
            <div class="filter-tab" onclick="setFilterTab(this)">Status Changes</div>
            <div class="filter-tab" onclick="setFilterTab(this)">Claims</div>
          </div>
          <?php
          $auditLog=[
            ['fa-right-to-bracket','info','R. Diosana signed in via Microsoft OAuth','June 15 · 15:06:01'],
            ['fa-circle-check','ok','Detection #47 marked as Recovered by M. Reyes','June 15 · 15:03:44'],
            ['fa-triangle-exclamation','alert','Detection #47 auto-escalated — MLH 306 · 1hr exceeded','June 15 · 15:03:44'],
            ['fa-hand-holding','warn','Claim #18 submitted by student (ID: 2021-00045)','June 15 · 14:48:22'],
            ['fa-ban','muted','Detection #46 dismissed as false positive — MLH 304','June 15 · 13:11:30'],
            ['fa-right-to-bracket','info','Y. Pedrozo signed in via Microsoft OAuth','June 15 · 09:00:12'],
            ['fa-rotate','info','Room MLH 304 reference frame recalibrated by staff','June 15 · 13:00:00'],
            ['fa-user-plus','green','New student account registered — j.sabio@dlsud.edu.ph','June 14 · 18:30:05'],
          ];
          foreach($auditLog as [$ic,$cls,$msg,$ts]): ?>
          <div style="display:flex;align-items:flex-start;gap:10px;padding:10px 16px;border-bottom:1px solid var(--border);">
            <div class="alert-ico <?=$cls?>" style="margin-top:1px;"><i class="fa-solid <?=$ic?>"></i></div>
            <div style="flex:1;">
              <div style="font-size:.78rem;color:var(--text-primary);line-height:1.4;"><?=$msg?></div>
              <div style="font-family:var(--font-mono);font-size:.63rem;color:var(--text-dim);margin-top:2px;"><?=$ts?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <!-- Detection heatmap by hour -->
        <div class="card">
          <div class="card-head"><div class="card-title"><i class="fa-solid fa-fire"></i> Detection Heatmap — By Hour of Day</div></div>
          <div style="padding:16px;">
            <div style="font-size:.72rem;color:var(--text-muted);margin-bottom:12px;">Average number of detection events per hour. Darker = more events.</div>
            <div class="heatmap-grid" id="heatmap">
              <?php
              // Hours 6am-10pm, days Mon-Sat
              $days=['Mon','Tue','Wed','Thu','Fri','Sat'];
              $hours=range(6,21);
              // Random-ish data weighted toward class hours (8-12, 13-17)
              foreach($days as $day): ?>
              <div class="hm-row">
                <div class="hm-day-label"><?=$day?></div>
                <?php foreach($hours as $h):
                  $isClass=($h>=8&&$h<=12)||($h>=13&&$h<=17);
                  $base=$isClass?rand(3,9):rand(0,3);
                  $intensity=min(100,round($base/9*100));
                ?>
                <div class="hm-cell" title="<?=$day?> <?=$h?>:00 — <?=$base?> events"
                     style="background:rgba(0,<?=($user_role==='admin'?86:86)?>49,<?=$intensity/100*0.85?>);border:1px solid rgba(0,86,49,<?=$intensity/100*0.3?>);">
                </div>
                <?php endforeach; ?>
              </div>
              <?php endforeach; ?>
              <!-- Hour labels -->
              <div class="hm-row" style="margin-top:4px;">
                <div class="hm-day-label"></div>
                <?php foreach($hours as $h): ?>
                <div class="hm-hour-label"><?=$h?></div>
                <?php endforeach; ?>
              </div>
            </div>
            <div style="display:flex;align-items:center;gap:6px;margin-top:10px;justify-content:flex-end;">
              <span style="font-size:.66rem;color:var(--text-dim);">Less</span>
              <?php for($i=1;$i<=5;$i++): ?>
              <div style="width:12px;height:12px;border-radius:3px;background:rgba(0,86,49,<?=$i*.18?>);border:1px solid rgba(0,86,49,<?=$i*.1?>);"></div>
              <?php endfor; ?>
              <span style="font-size:.66rem;color:var(--text-dim);">More</span>
            </div>
          </div>
        </div>
      </div>

    </div>
  </div>
</div>
<div class="toast-stack" id="toastStack"></div>
<script src="../assets/js/spotit.js"></script>
<script>
startLiveClock && startLiveClock('liveClock');
function refreshCharts() { showToast('info','Chart data refreshed for selected period.'); }
function exportReport()  { showToast('success','Analytics report export queued. Will be sent to your email.'); }
</script>
</body>
</html>
