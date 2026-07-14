<?php
require_once __DIR__ . '/../config/env.php';
require_once __DIR__ . '/../services/lostfound/db.php';
$active_page = 'thread';
$user_role   = $_SESSION['user_role'] ?? 'student';

$lfPdo = getLostFoundDB();
$dbItemsStmt = $lfPdo->query("
    SELECT recovery_id, room_id, item_description, item_type, item_tier,
           found_location, snapshot_path, recovered_at, source, status, notes
    FROM recovered_items
    ORDER BY recovered_at DESC
");
$dbItems = $dbItemsStmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Lost &amp; Found Thread — S.P.O.T.-IT</title>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/css/lost-thread.css"/>
  <link rel="stylesheet" href="../assets/css/skeleton.css"/>
  <link rel="stylesheet" href="../assets/css/onboarding.css"/>
  <script>(function(){document.documentElement.setAttribute('data-theme',localStorage.getItem('spotit_theme')||'light')})();</script>
</head>
<body data-skeleton="thread">
<script src="../assets/js/skeleton.js"></script>
<div class="app-shell">
  <?php include '_sidebar.php'; ?>
  <div class="main-content">
    <div class="topbar">
      <button class="tb-btn tb-hamburger" id="hamburgerBtn" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></button>
      <div><span class="topbar-title">Lost &amp; Found Thread</span><span class="topbar-sub">— CEAT Building · MLH</span></div>
      <div class="topbar-right">
        <span style="font-family:var(--font-mono);font-size:.7rem;color:var(--text-dim);" id="liveClock"></span>
        <button class="tb-btn" onclick="toggleTheme()"><i class="fa-solid fa-circle-half-stroke"></i></button>
      </div>
    </div>

    <div class="page-body">

      <!-- Search + filter bar -->
      <div class="thread-search-bar">
        <div style="position:relative;flex:1;">
          <i class="fa-solid fa-magnifying-glass" style="position:absolute;left:12px;top:50%;transform:translateY(-50%);color:var(--text-dim);font-size:.8rem;"></i>
          <input type="text" id="threadSearch" class="form-control" style="padding-left:36px;border-radius:var(--radius-sm);" placeholder="Search by item name, room, description…" oninput="filterThread()"/>
        </div>
        <select id="roomFilter" class="form-control" style="max-width:150px;" onchange="filterThread()">
          <option value="">All Rooms</option>
          <?php foreach(['MLH 306','MLH 305','MLH 304','MLH 303','MLH 203','MLH 301','MLH 201'] as $r): ?>
          <option><?= $r ?></option>
          <?php endforeach; ?>
        </select>
        <select id="statusFilter" class="form-control" style="max-width:150px;" onchange="filterThread()">
          <option value="">All Statuses</option>
          <option>Available</option>
          <option>Claimed</option>
          <option>Pending Claim</option>
        </select>
      </div>

      <!-- Filter tabs -->
      <div class="filter-tabs" style="border:none;border-bottom:1px solid var(--border);padding-bottom:0;margin-bottom:18px;">
        <div class="filter-tab active" onclick="setFilterTab(this);filterByTab('all')">All Items (14)</div>
        <div class="filter-tab" onclick="setFilterTab(this);filterByTab('available')">Available (9)</div>
        <div class="filter-tab" onclick="setFilterTab(this);filterByTab('pending')">Pending Claim (2)</div>
        <div class="filter-tab" onclick="setFilterTab(this);filterByTab('claimed')">Claimed (3)</div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 300px;gap:20px;align-items:start;">

        <!-- MAIN: Item cards thread -->
        <div id="threadGrid">
          <?php
          $items = [];
          foreach ($dbItems as $i) {
              $type = strtolower($i['item_type'] ?? '');
              $icon = 'fa-box';
              if (stripos($type, 'umbrella') !== false) $icon = 'fa-umbrella';
              elseif (stripos($type, 'cable') !== false || stripos($type, 'usb') !== false) $icon = 'fa-plug';
              elseif (stripos($type, 'calculator') !== false) $icon = 'fa-calculator';
              elseif (stripos($type, 'water') !== false || stripos($type, 'bottle') !== false || stripos($type, 'tumbler') !== false) $icon = 'fa-bottle-water';
              elseif (stripos($type, 'earphone') !== false || stripos($type, 'headphone') !== false) $icon = 'fa-headphones';
              elseif (stripos($type, 'card') !== false || stripos($type, 'id') !== false) $icon = 'fa-id-card';
              elseif (stripos($type, 'pencil') !== false || stripos($type, 'pen') !== false) $icon = 'fa-pen-ruler';
              elseif (stripos($type, 'phone') !== false || stripos($type, 'mobile') !== false) $icon = 'fa-mobile-screen';
              elseif (stripos($type, 'wallet') !== false) $icon = 'fa-wallet';

              $status = match($i['status']) {
                  'pending_claim' => 'pending',
                  'claimed'       => 'claimed',
                  default         => 'available',
              };

              $color = match($status) {
                  'pending' => 'warn',
                  'claimed' => 'muted',
                  default   => 'ok',
              };

              $items[] = [
                  'id'          => $i['recovery_id'],
                  'name'        => $i['item_type'] ?? 'Unknown Item',
                  'room'        => $i['room_id'],
                  'zone'        => $i['found_location'],
                  'found'       => date('F j, Y · H:i', strtotime($i['recovered_at'])),
                  'desc'        => $i['item_description'],
                  'icon'        => $icon,
                  'tier'        => strtoupper($i['item_tier'] ?? 'TIER1'),
                  'status'      => $status,
                  'color'       => $color,
                  'auto'        => $i['source'] === 'cctv_auto',
                  'img_icon'    => $icon,
              ];
          }
          ?>
          <?php if (empty($items)): ?>
          <div class="p-4 text-center card w-100" style="color:var(--text-dim);">No items currently logged in Lost &amp; Found.</div>
          <?php else: ?>
          <?php foreach ($items as $item):
            $statusLabel = ['available'=>'Available','pending'=>'Pending Claim','claimed'=>'Claimed'][$item['status']];
            $stCls = ['available'=>'badge-ok','pending'=>'badge-warn','claimed'=>'badge-muted'][$item['status']];
          ?>
          <div class="thread-card <?= $item['status'] ?>" data-name="<?= strtolower(htmlspecialchars($item['name'])) ?>" data-room="<?= htmlspecialchars($item['room']) ?>" data-status="<?= $item['status'] ?>">
            <!-- Icon area -->
            <div class="tc-icon-wrap <?= $item['status']==='claimed'?'claimed':'' ?>">
              <i class="fa-solid <?= $item['icon'] ?>"></i>
              <?php if ($item['auto']): ?>
              <div class="tc-auto-badge"><i class="fa-solid fa-robot"></i> AUTO-DETECTED</div>
              <?php endif; ?>
            </div>

            <!-- Content -->
            <div class="tc-body">
              <div class="tc-head-row">
                <div>
                  <div class="tc-name"><?= htmlspecialchars($item['name']) ?></div>
                  <div class="tc-meta">
                    <span><i class="fa-solid fa-door-open"></i> <?= $item['room'] ?></span>
                    <span><i class="fa-solid fa-vector-square"></i> <?= htmlspecialchars($item['zone']) ?></span>
                    <span><i class="fa-solid fa-clock"></i> <?= $item['found'] ?></span>
                  </div>
                </div>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:6px;">
                  <span class="badge <?= $stCls ?>"><span class="bdot"></span><?= $statusLabel ?></span>
                  <span class="badge badge-muted" style="font-size:.56rem;"><?= $item['tier'] ?></span>
                </div>
              </div>
              <p class="tc-desc"><?= htmlspecialchars($item['desc']) ?></p>
              <div class="tc-actions">
                <?php if ($item['status'] === 'available'): ?>
                  <button class="btn btn-primary btn-sm" onclick="openClaimModal(<?= $item['id'] ?>,'<?= htmlspecialchars(addslashes($item['name'])) ?>','<?= $item['room'] ?>')">
                    <i class="fa-solid fa-hand-holding"></i> Claim This Item
                  </button>
                <?php elseif ($item['status'] === 'pending'): ?>
                  <span class="btn btn-sm btn-warn" style="cursor:default;"><i class="fa-solid fa-clock"></i> Claim Pending</span>
                <?php else: ?>
                  <span class="btn btn-sm" style="cursor:default;opacity:.6;"><i class="fa-solid fa-circle-check"></i> Already Claimed</span>
                <?php endif; ?>
                <button class="btn btn-sm" onclick="openDetailModal(<?= $item['id'] ?>,'<?= htmlspecialchars(addslashes($item['name'])) ?>')">
                  <i class="fa-solid fa-eye"></i> View Details
                </button>
                <?php if ($item['auto']): ?>
                <span style="font-size:.65rem;color:var(--green-main);display:flex;align-items:center;gap:4px;margin-left:auto;">
                  <i class="fa-solid fa-robot"></i> CCTV Auto-Detected
                </span>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>

          <div id="noResults" style="display:none;text-align:center;padding:3rem;color:var(--text-dim);">
            <i class="fa-solid fa-magnifying-glass" style="font-size:2rem;margin-bottom:.8rem;display:block;"></i>
            No items match your search. Try a different keyword or filter.
          </div>
        </div>

        <!-- RIGHT: Info sidebar -->
        <div style="display:flex;flex-direction:column;gap:18px;">
          <div class="card">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-chart-pie"></i> Thread Summary</div></div>
            <div style="padding:14px;display:flex;flex-direction:column;gap:10px;">
              <?php
              $cAvailable = (int)$lfPdo->query("SELECT COUNT(*) FROM recovered_items WHERE status = 'recovered'")->fetchColumn();
              $cPending   = (int)$lfPdo->query("SELECT COUNT(*) FROM recovered_items WHERE status = 'pending_claim'")->fetchColumn();
              $cClaimed   = (int)$lfPdo->query("SELECT COUNT(*) FROM recovered_items WHERE status = 'claimed'")->fetchColumn();
              $cTotal     = $cAvailable + $cPending + $cClaimed;
              
              $summary = [
                  [$cAvailable, 'Available for claiming', 'ok'],
                  [$cPending,   'Pending claim review',   'warn'],
                  [$cClaimed,   'Successfully claimed',   'muted'],
                  [$cTotal,     'Total items logged',     'info']
              ];
              foreach ($summary as [$n,$l,$c]): ?>
              <div style="display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:8px;background:var(--bg-base);border:1px solid var(--border);">
                <div style="font-family:var(--font-display);font-size:1.4rem;font-weight:800;color:var(--text-primary);min-width:30px;"><?= $n ?></div>
                <div style="font-size:.76rem;color:var(--text-muted);"><?= $l ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="card">
            <div class="card-head"><div class="card-title"><i class="fa-solid fa-circle-question"></i> How to Claim</div></div>
            <div style="padding:14px;display:flex;flex-direction:column;gap:10px;">
              <?php
              $howto = [
                ['1','Find your item above and click "Claim This Item"'],
                ['2','Fill in your university ID and describe the item'],
                ['3','Staff verify your description matches the actual item'],
                ['4','Visit the dispensing window at CEAT lobby with your ID'],
              ];
              foreach ($howto as [$n,$t]): ?>
              <div style="display:flex;gap:9px;align-items:flex-start;">
                <div style="width:22px;height:22px;border-radius:50%;background:var(--green-main);color:#fff;font-family:var(--font-display);font-weight:800;font-size:.68rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;margin-top:1px;"><?= $n ?></div>
                <div style="font-size:.78rem;color:var(--text-muted);line-height:1.55;"><?= $t ?></div>
              </div>
              <?php endforeach; ?>
            </div>
          </div>

          <div class="card" style="background:var(--green-pale);border-color:rgba(0,86,49,.18);">
            <div style="padding:16px;">
              <div style="display:flex;align-items:center;gap:7px;margin-bottom:.5rem;">
                <i class="fa-solid fa-robot" style="color:var(--green-main);"></i>
                <span style="font-family:var(--font-display);font-size:.8rem;font-weight:700;color:var(--text-primary);">Auto-detected Items</span>
              </div>
              <p style="font-size:.76rem;color:var(--text-muted);line-height:1.6;margin-bottom:.8rem;">Items marked <strong>AUTO-DETECTED</strong> were flagged automatically by the S.P.O.T.-IT camera system — no manual report needed.</p>
              <div style="font-size:.7rem;color:var(--green-main);font-weight:600;display:flex;align-items:center;gap:4px;"><i class="fa-solid fa-video"></i> Powered by OpenCV + IP CCTV</div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<!-- Claim Modal -->
<div class="modal-overlay" id="claimModal" onclick="if(event.target===this)closeModal('claimModal')">
  <div class="modal-box" style="max-width:460px;">
    <div class="modal-head"><div class="modal-title">Claim Item</div><div class="modal-close" onclick="closeModal('claimModal')"><i class="fa-solid fa-xmark"></i></div></div>
    <div class="modal-body">
      <div class="selected-item-banner" id="claimBanner" style="display:flex;align-items:center;gap:10px;padding:11px 14px;border-radius:9px;background:var(--ok-bg);border:1px solid var(--ok-border);font-size:.82rem;color:var(--text-primary);margin-bottom:1rem;">
        <i class="fa-solid fa-circle-check" style="color:var(--ok);"></i>
        <span>Claiming: <strong id="claimItemName">—</strong> from <strong id="claimItemRoom">—</strong></span>
      </div>
      <div class="form-group"><label class="form-label">Full Name *</label><input type="text" id="ci_name" class="form-control" placeholder="e.g. Maria Santos"/></div>
      <div class="form-group"><label class="form-label">University ID *</label><input type="text" id="ci_id" class="form-control" placeholder="e.g. 2021-00001"/></div>
      <div class="form-group"><label class="form-label">Describe Your Item (unique characteristics) *</label><textarea id="ci_desc" class="form-control" rows="3" placeholder="Color, brand, markings, contents, damage — anything that proves it's yours."></textarea></div>
      <div class="form-group"><label class="form-label">Contact Number</label><input type="tel" id="ci_contact" class="form-control" placeholder="09xx-xxx-xxxx"/></div>
      <div style="padding:11px 13px;background:var(--warn-bg);border:1px solid var(--warn-border);border-radius:9px;font-size:.76rem;color:var(--text-primary);margin-bottom:14px;">
        <i class="fa-solid fa-triangle-exclamation" style="color:var(--warn);"></i>
        Staff will verify your description against the actual item. Please visit the dispensing window with your university ID to complete the claim.
      </div>
      <div class="modal-actions">
        <button class="modal-btn dismiss" onclick="closeModal('claimModal')">Cancel</button>
        <button class="modal-btn recover" onclick="submitClaim()"><i class="fa-solid fa-paper-plane"></i> Submit Claim</button>
      </div>
    </div>
  </div>
</div>

<!-- Detail Modal -->
<div class="modal-overlay" id="detailModal" onclick="if(event.target===this)closeModal('detailModal')">
  <div class="modal-box" style="max-width:480px;">
    <div class="modal-head"><div class="modal-title" id="detailTitle">Item Details</div><div class="modal-close" onclick="closeModal('detailModal')"><i class="fa-solid fa-xmark"></i></div></div>
    <div class="modal-body">
      <div class="detail-grid">
        <div class="detail-cell"><div class="detail-key">Item</div><div class="detail-val" id="d_name">—</div></div>
        <div class="detail-cell"><div class="detail-key">Found In</div><div class="detail-val" id="d_room">—</div></div>
        <div class="detail-cell"><div class="detail-key">Detection Method</div><div class="detail-val" id="d_method">CCTV Auto-Detection</div></div>
        <div class="detail-cell"><div class="detail-key">Status</div><div class="detail-val ok" id="d_status">Available</div></div>
        <div class="detail-cell" style="grid-column:1/-1;"><div class="detail-key">Description</div><div class="detail-val" id="d_desc" style="font-family:var(--font-body);font-size:.82rem;font-weight:400;line-height:1.5;">—</div></div>
      </div>
      <div style="margin-top:14px;"><p style="font-size:.8rem;color:var(--text-muted);">To claim this item, please click "Claim This Item" below. Staff will verify your identity before releasing the item at the dispensing window.</p></div>
      <div class="modal-actions" style="margin-top:12px;">
        <button class="modal-btn dismiss" onclick="closeModal('detailModal')">Close</button>
        <button class="modal-btn recover" id="detailClaimBtn" onclick="closeModal('detailModal');openClaimModal(currentDetailId,currentDetailName,currentDetailRoom)">
          <i class="fa-solid fa-hand-holding"></i> Claim This Item
        </button>
      </div>
    </div>
  </div>
</div>

<div class="toast-stack" id="toastStack"></div>
<script src="../assets/js/spotit.js"></script>
<script>
startLiveClock('liveClock');
let currentDetailId, currentDetailName, currentDetailRoom;

// Stored item detail data for modal
const itemDetails = {
  1:{name:'Black Umbrella',room:'MLH 306',method:'CCTV Auto-Detection',status:'Available',desc:'Medium-sized black umbrella with a hook handle. Found on the floor near the CCTV-flagged workstation area after a session ended.'},
  2:{name:'Charging Cable (USB-C)',room:'MLH 305',method:'CCTV Auto-Detection',status:'Pending Claim',desc:'White braided USB-C cable approximately 1 meter. Left beside a keyboard after a class session.'},
  3:{name:'Scientific Calculator (Casio)',room:'MLH 303',method:'Manual Surrender',status:'Available',desc:'Casio fx-991EX scientific calculator. Black. Has small scratch on back cover.'},
  4:{name:'Water Tumbler (Blue)',room:'MLH 304',method:'Manual Surrender',status:'Available',desc:'Blue stainless steel tumbler, 500ml. No stickers. Found near the rear wall after a class.'},
  5:{name:'Earphones',room:'MLH 203',method:'Manual Surrender',status:'Available',desc:'White wired in-ear earphones. No case. 3.5mm jack type.'},
  6:{name:'Student ID (DLSU-D)',room:'MLH 306',method:'Manual Surrender',status:'Available',desc:'DLSU-D student ID card. Name partially visible. Surrendered by classmate.'},
  7:{name:'Pencil Case (Gray)',room:'MLH 301',method:'Manual Surrender',status:'Pending Claim',desc:'Gray zipper pencil case. Contains a ruler, eraser, and pens.'},
  8:{name:'Cellphone (OPPO)',room:'MLH 305',method:'Manual Surrender',status:'Available',desc:'Black OPPO smartphone. Cracked screen protector. Turned off when found.'},
  9:{name:'Wallet (Brown Leather)',room:'MLH 201',method:'Manual Surrender',status:'Claimed',desc:'Brown bifold leather wallet. No cash found. Contains old receipts.'},
  10:{name:'USB Flash Drive',room:'MLH 304',method:'Manual Surrender',status:'Claimed',desc:'16GB black USB flash drive. No label.'},
};

function openClaimModal(id, name, room) {
  document.getElementById('claimItemName').textContent = name;
  document.getElementById('claimItemRoom').textContent = room;
  ['ci_name','ci_id','ci_desc','ci_contact'].forEach(i=>{ const el=document.getElementById(i); if(el) el.value=''; });
  openModal('claimModal');
}

function openDetailModal(id, name) {
  const d = itemDetails[id]; if (!d) return;
  currentDetailId = id; currentDetailName = d.name; currentDetailRoom = d.room;
  document.getElementById('detailTitle').textContent = 'Item Details — ' + d.name;
  document.getElementById('d_name').textContent   = d.name;
  document.getElementById('d_room').textContent   = d.room;
  document.getElementById('d_method').textContent = d.method;
  document.getElementById('d_status').textContent = d.status;
  document.getElementById('d_desc').textContent   = d.desc;
  const claimBtn = document.getElementById('detailClaimBtn');
  claimBtn.style.display = d.status === 'Available' ? '' : 'none';
  openModal('detailModal');
}

function submitClaim() {
  const name = document.getElementById('ci_name').value.trim();
  const id   = document.getElementById('ci_id').value.trim();
  const desc = document.getElementById('ci_desc').value.trim();
  if (!name || !id || !desc) { showToast('error','Please fill in all required fields.'); return; }
  closeModal('claimModal');
  showToast('success','Claim submitted! Please proceed to the CEAT dispensing window with your university ID.');
}

function filterThread() {
  const q    = document.getElementById('threadSearch').value.toLowerCase();
  const room = document.getElementById('roomFilter').value.toLowerCase();
  const stat = document.getElementById('statusFilter').value.toLowerCase();
  let visible = 0;
  document.querySelectorAll('.thread-card').forEach(card => {
    const matchQ    = !q    || card.dataset.name.includes(q) || card.textContent.toLowerCase().includes(q);
    const matchRoom = !room || card.dataset.room.toLowerCase().includes(room);
    const matchStat = !stat || card.dataset.status.includes(stat.replace(' ',''));
    const show = matchQ && matchRoom && matchStat;
    card.style.display = show ? '' : 'none';
    if (show) visible++;
  });
  document.getElementById('noResults').style.display = visible===0 ? '' : 'none';
}

function filterByTab(tab) {
  document.querySelectorAll('.thread-card').forEach(card => {
    card.style.display = (tab==='all' || card.dataset.status===tab) ? '' : 'none';
  });
  document.getElementById('noResults').style.display = 'none';
}
</script>
</body>
</html>
