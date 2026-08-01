<?php
/**
 * S.P.O.T.-IT — End-of-Year Donation & Data Retention
 * pages/donation.php
 *
 * Admin-only. Closes the lifecycle in CLAUDE.md §3c: unclaimed personal items
 * are donated at the end of the academic year, and that disposal doubles as the
 * system's garbage collection — the CCTV evidence and claim selfies gathered
 * about an item are purged once the item itself is gone.
 *
 * MICROSERVICES: No SQL. Reads via auth/get_recovered_items.php, writes via
 * auth/process_donation.php.
 */
require_once __DIR__ . '/../config/env.php';

// Hard gate — this page triggers irreversible deletion.
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    header('Location: dashboard-admin.php');
    exit();
}

$active_page = 'donation';
$user_role   = $_SESSION['user_role'] ?? 'student';
?>
<!DOCTYPE html>
<html lang="en" data-theme="light">
<head>
  <meta charset="UTF-8"/><meta name="viewport" content="width=device-width,initial-scale=1.0"/>
  <title>Donation &amp; Retention — S.P.O.T.-IT</title>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/css/dashboard.css"/>
  <link rel="stylesheet" href="../assets/css/notifications.css"/>
  <link rel="stylesheet" href="../assets/css/skeleton.css"/>
  <script>(function(){document.documentElement.setAttribute('data-theme',localStorage.getItem('spotit_theme')||'light')})();</script>
</head>
<body data-skeleton="dashboard">
<script src="../assets/js/skeleton.js"></script>
<div class="app-shell">
  <?php include '_sidebar.php'; ?>
  <div class="main-content">
    <div class="topbar">
      <button class="tb-btn tb-hamburger" id="hamburgerBtn" onclick="toggleSidebar()">
        <i class="fa-solid fa-bars"></i>
      </button>
      <div>
        <span class="topbar-title">Donation &amp; Retention</span>
        <span class="topbar-sub"> — End-of-Year Disposal</span>
      </div>
      <div class="topbar-right">
        <span style="font-family:var(--font-mono);font-size:.7rem;color:var(--text-dim);" id="liveClock"></span>
        <button class="tb-btn" onclick="toggleTheme()"><i class="fa-solid fa-circle-half-stroke"></i></button>
      </div>
    </div>
    <div class="page-body">

      <div style="display:flex;align-items:center;gap:14px;margin-bottom:22px;">
        <div style="width:48px;height:48px;border-radius:12px;background:var(--green-pale);
                    color:var(--green-main);display:flex;align-items:center;justify-content:center;font-size:1.1rem;">
          <i class="fa-solid fa-hand-holding-heart"></i>
        </div>
        <div>
          <div style="font-family:var(--font-display);font-size:1.05rem;font-weight:800;color:var(--text-primary);">Donation &amp; Data Retention</div>
          <div style="font-size:.78rem;color:var(--text-muted);font-weight:300;">Unclaimed items · CEAT Building · S.P.O.T.-IT</div>
        </div>
      </div>

      <!-- What this page actually does. Stated plainly because it is
           irreversible and because the retention story is defence material. -->
      <div class="card" style="margin-bottom:18px;border-left:3px solid var(--green-main);">
        <div style="padding:14px 16px;font-size:.8rem;color:var(--text-muted);line-height:1.65;">
          <strong style="color:var(--text-primary);">What happens when you process a donation</strong><br/>
          Per the SWAFO Director and CEAT technician interview, personal items left unclaimed at the
          end of the academic year are donated. Processing them here also completes this system's
          data retention cycle:
          <ul style="margin:9px 0 4px 18px;padding:0;">
            <li>The item is marked <strong>archived</strong> — its description, room and dates are kept as a permanent record.</li>
            <li>Its <strong>CCTV evidence is deleted</strong> — Snapshot A, Snapshot B and the detection rows.</li>
            <li>Its <strong>claim records and claimant selfies are deleted</strong> — these are photographs of students, and once the item is gone there is no reason to hold them.</li>
            <li>An <strong>audit entry</strong> naming you and the items is written first, so the deletion itself is on record.</li>
          </ul>
          <span style="color:var(--alert);font-weight:700;">This cannot be undone.</span>
        </div>
      </div>

      <div class="stat-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:18px;">
        <div class="stat-card"><div class="stat-icon warn"><i class="fa-solid fa-box-open"></i></div><div><div class="stat-num" id="statEligible">—</div><div class="stat-label">Eligible for Donation</div></div></div>
        <div class="stat-card"><div class="stat-icon info"><i class="fa-solid fa-check-double"></i></div><div><div class="stat-num" id="statSelected">0</div><div class="stat-label">Selected</div></div></div>
        <div class="stat-card"><div class="stat-icon ok"><i class="fa-solid fa-hand-holding-heart"></i></div><div><div class="stat-num" id="statArchived">—</div><div class="stat-label">Already Donated</div></div></div>
      </div>

      <div class="card">
        <div class="card-head">
          <div class="card-title"><i class="fa-solid fa-boxes-stacked"></i> Unclaimed Items</div>
          <div style="display:flex;gap:8px;align-items:center;">
            <label style="font-size:.72rem;color:var(--text-muted);font-weight:600;">Held at least</label>
            <select id="ageFilter" class="form-control" style="width:130px;font-size:.75rem;" onchange="loadItems()">
              <option value="0">Any age</option>
              <option value="30">30 days</option>
              <option value="90">90 days</option>
              <option value="180" selected>180 days</option>
              <option value="365">1 year</option>
            </select>
            <button class="btn btn-sm" onclick="toggleAll()" style="font-size:.72rem;background:var(--bg-base);border:1px solid var(--border);color:var(--text-primary);">
              <i class="fa-solid fa-check-double"></i> Select All
            </button>
            <button class="btn btn-sm" id="btnDonate" onclick="processDonation()" disabled
                    style="font-size:.72rem;font-weight:700;background:var(--alert-bg);border:1px solid rgba(220,53,69,.3);color:var(--alert);">
              <i class="fa-solid fa-hand-holding-heart"></i> Process Donation
            </button>
          </div>
        </div>
        <div style="overflow-x:auto;">
          <table class="data-table" style="width:100%;">
            <thead>
              <tr>
                <th style="width:38px;"></th>
                <th style="width:64px;">Photo</th>
                <th>Item</th>
                <th>Room</th>
                <th>Found</th>
                <th>Age</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody id="itemsBody">
              <tr><td colspan="7" style="text-align:center;padding:28px;color:var(--text-muted);font-size:.8rem;">Loading…</td></tr>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </div>
</div>

<script src="../assets/js/theme.js"></script>
<script src="../assets/js/spotit.js"></script>
<script>
let allItems = [];

function ageDays(iso) {
  return Math.floor((Date.now() - new Date(iso.replace(' ', 'T')).getTime()) / 86400000);
}

async function loadItems() {
  const minAge = document.getElementById('ageFilter').value;
  const body = document.getElementById('itemsBody');
  try {
    const res = await spotitFetch(
      `../auth/get_recovered_items.php?status=donatable&limit=100&min_age_days=${minAge}`
    );
    allItems = (res && res.items) || [];

    document.getElementById('statEligible').textContent = allItems.length;

    if (!allItems.length) {
      body.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:28px;color:var(--text-muted);font-size:.8rem;">
        No unclaimed items match this filter. Nothing to donate.</td></tr>`;
      updateSelection();
      return;
    }

    body.innerHTML = allItems.map(it => {
      const age = ageDays(it.recovered_at);
      const img = it.snapshot_url
        ? `<img src="${it.snapshot_url}" style="height:34px;width:44px;object-fit:cover;border-radius:4px;border:1px solid var(--border);"/>`
        : `<span style="color:var(--text-dim);font-size:.7rem;">—</span>`;
      return `<tr>
        <td><input type="checkbox" class="pick" value="${it.recovery_id}" onchange="updateSelection()"/></td>
        <td>${img}</td>
        <td style="font-weight:600;">${escapeHtml(it.item_description || '(no description)')}
            <div style="font-size:.68rem;color:var(--text-muted);font-weight:400;">${escapeHtml(it.item_type || '')}</div></td>
        <td style="font-family:var(--font-mono);font-size:.72rem;">${escapeHtml(it.room_id)}</td>
        <td style="font-size:.74rem;">${escapeHtml(it.recovered_at)}</td>
        <td style="font-size:.74rem;font-weight:700;color:${age > 180 ? 'var(--alert)' : 'var(--text-muted)'};">${age}d</td>
        <td><span class="roi-tag">${escapeHtml(it.status)}</span></td>
      </tr>`;
    }).join('');
  } catch (e) {
    body.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:28px;color:var(--alert);font-size:.8rem;">
      Could not load items.</td></tr>`;
  }
  updateSelection();
}

async function loadArchivedCount() {
  try {
    const res = await spotitFetch('../auth/get_recovered_items.php?status=unclaimed_archived&limit=100');
    document.getElementById('statArchived').textContent = ((res && res.items) || []).length;
  } catch (e) { /* non-critical stat */ }
}

function escapeHtml(s) {
  return String(s == null ? '' : s).replace(/[&<>"']/g,
    c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function selectedIds() {
  return Array.from(document.querySelectorAll('.pick:checked')).map(c => c.value);
}

function updateSelection() {
  const n = selectedIds().length;
  document.getElementById('statSelected').textContent = n;
  document.getElementById('btnDonate').disabled = (n === 0);
}

function toggleAll() {
  const boxes = Array.from(document.querySelectorAll('.pick'));
  const target = !boxes.every(b => b.checked);
  boxes.forEach(b => { b.checked = target; });
  updateSelection();
}

async function processDonation() {
  const ids = selectedIds();
  if (!ids.length) return;

  // Two-step confirmation: this deletes photographs of identifiable students and
  // cannot be reversed, so a single mis-click must not be enough to trigger it.
  if (!confirm(
    `Donate ${ids.length} item(s)?\n\n` +
    `This permanently deletes their CCTV snapshots, detection records and claim ` +
    `selfies. Item descriptions and dates are kept.\n\nThis cannot be undone.`
  )) return;

  const note = prompt('Optional note for the audit log (e.g. "Turned over to SWAFO, AY 2026-2027"):', '');
  if (note === null) return;

  const btn = document.getElementById('btnDonate');
  btn.disabled = true;
  btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Processing…';

  try {
    const res = await spotitFetch('../auth/process_donation.php', {
      method: 'POST',
      body: new URLSearchParams({ recovery_ids: ids.join(','), note: note || '' })
    });
    if (res && res.success) {
      showToast('success', res.message);
      await loadItems();
      await loadArchivedCount();
    } else {
      showToast('error', (res && res.message) || 'Donation failed.');
    }
  } catch (e) {
    showToast('error', 'Network error while processing donation.');
  } finally {
    btn.innerHTML = '<i class="fa-solid fa-hand-holding-heart"></i> Process Donation';
    updateSelection();
  }
}

startLiveClock('liveClock');
document.addEventListener('DOMContentLoaded', () => { loadItems(); loadArchivedCount(); });
</script>
</body>
</html>
