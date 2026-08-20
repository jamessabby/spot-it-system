<?php
/**
 * S.P.O.T.-IT — Year-end records export
 * auth/export_records.php
 *
 * GET, admin-only. Streams a CSV of confirmed outcomes for the lab's records.
 *
 * WHY THIS EXISTS
 * process_donation.php purges the evidence trail at the end of the academic
 * year (CLAUDE.md §3c). Before that happens the lab needs a durable copy of
 * what actually concluded: who collected which item, and which items nobody
 * ever came back for. Without this the retention policy and the lab's own
 * record-keeping obligation are in direct conflict.
 *
 * WHAT IS AND IS NOT INCLUDED
 * Confirmed outcomes only:
 *   dataset=claimants  — claims.status = 'claimed'      (a physical handoff happened)
 *   dataset=unclaimed  — recovered_items.status = 'unclaimed_archived'
 *                        (the terminal never-claimed state, set by the donation purge)
 *
 * Raw 'pending' / 'potential' detections are deliberately EXCLUDED. Those are
 * unverified machine output — a pending detection is the system's guess, not a
 * finding, and exporting guesses into a document the lab treats as a record
 * would misrepresent what the system actually established. Everything in here
 * has been through a human.
 *
 * FORMAT — CSV, not XLSX
 * A real .xlsx is a ZIP archive, and ext-zip (ZipArchive) is not enabled in this
 * PHP build. Hand-rolling a ZIP writer to avoid one dependency is not a trade
 * worth making before a defense. UTF-8 CSV with a BOM opens directly in Excel
 * with accented names intact, which is the whole requirement.
 *
 * MICROSERVICES: claims/recovered_items come from spotit_lf_db; the verifying
 * staff member's NAME is resolved separately against spotit_auth_db through
 * ms_get_users_by_ids(). No cross-database join — see CLAUDE.md §2.
 */

require_once __DIR__ . '/service_bootstrap.php';

ms_require_auth('../pages/login.php');
ms_require_role('admin', '../pages/login.php');

$dataset = strtolower(trim($_GET['dataset'] ?? 'claimants'));
if (!in_array($dataset, ['claimants', 'unclaimed'], true)) {
    ms_json(['success' => false, 'message' => 'dataset must be "claimants" or "unclaimed".'], 400);
}

// Optional calendar-year filter. Anything that is not a plausible year is
// treated as "no filter" rather than an error — this is a report, not an API.
$yearRaw = trim((string) ($_GET['year'] ?? ''));
$year    = ($yearRaw !== '' && ctype_digit($yearRaw) && (int)$yearRaw >= 2000 && (int)$yearRaw <= 2100)
    ? (int)$yearRaw
    : null;

$rangeStart = $year ? sprintf('%04d-01-01 00:00:00', $year) : null;
$rangeEnd   = $year ? sprintf('%04d-01-01 00:00:00', $year + 1) : null;

/** Blank rather than "1970-01-01" for a NULL date. */
function ex_date(?string $dt): string {
    return $dt ? date('Y-m-d H:i', strtotime($dt)) : '';
}

$rows    = [];
$headers = [];

try {
    if ($dataset === 'claimants') {
        // Same database, so this join is legal. r.* is the item as recorded by
        // staff; the claim row is the claimant's own account of it.
        $sql = "SELECT c.claimant_name, c.university_id, c.contact,
                       c.item_description AS claim_description,
                       c.claimed_at, c.submitted_at, c.verified_by,
                       r.item_description AS item, r.item_type, r.room_id
                  FROM claims c
             LEFT JOIN recovered_items r ON r.recovery_id = c.recovery_id
                 WHERE c.status = 'claimed'";
        $params = [];

        // Filter on the handoff date, falling back to submission for older rows
        // where claimed_at was never written.
        if ($year) {
            $sql .= " AND COALESCE(c.claimed_at, c.submitted_at) >= ?
                      AND COALESCE(c.claimed_at, c.submitted_at) < ?";
            $params = [$rangeStart, $rangeEnd];
        }
        $sql .= " ORDER BY COALESCE(c.claimed_at, c.submitted_at) ASC";

        $stmt = $lfPdo->prepare($sql);
        $stmt->execute($params);
        $claims = $stmt->fetchAll();

        // Cross-service lookup, resolved in PHP.
        $staff = ms_get_users_by_ids($authPdo, array_column($claims, 'verified_by'));

        $headers = [
            'Claimant Name', 'University ID', 'Contact',
            'Item', 'Item Type', 'Room', 'Date Claimed', 'Verified By',
        ];

        foreach ($claims as $c) {
            $staffId   = (int) ($c['verified_by'] ?? 0);
            $staffName = $staffId && isset($staff[$staffId])
                ? $staff[$staffId]['full_name']
                : ($staffId ? "user #{$staffId} (deleted)" : '—');

            $rows[] = [
                $c['claimant_name'],
                $c['university_id'],
                $c['contact'] ?: '',
                // Staff's description is authoritative; fall back to the
                // claimant's if the item row is gone.
                $c['item'] ?: $c['claim_description'],
                $c['item_type'] ?: '',
                $c['room_id'] ?: '',
                ex_date($c['claimed_at'] ?: $c['submitted_at']),
                $staffName,
            ];
        }

    } else {
        // 'unclaimed_archived' is the terminal never-claimed status in
        // recovered_items — read off the enum, not assumed.
        $sql = "SELECT recovery_id, item_description, item_type, item_tier,
                       room_id, found_location, recovered_at, archived_at, source
                  FROM recovered_items
                 WHERE status = 'unclaimed_archived'";
        $params = [];

        if ($year) {
            $sql .= " AND COALESCE(archived_at, recovered_at) >= ?
                      AND COALESCE(archived_at, recovered_at) < ?";
            $params = [$rangeStart, $rangeEnd];
        }
        $sql .= " ORDER BY COALESCE(archived_at, recovered_at) ASC";

        $stmt = $lfPdo->prepare($sql);
        $stmt->execute($params);

        $headers = [
            'Record ID', 'Item', 'Item Type', 'Tier', 'Room',
            'Found Location', 'Date Found', 'Date Archived', 'Source',
        ];

        foreach ($stmt->fetchAll() as $r) {
            $rows[] = [
                $r['recovery_id'],
                $r['item_description'],
                $r['item_type'] ?: '',
                $r['item_tier'] ?: '',
                $r['room_id'],
                $r['found_location'] ?: '',
                ex_date($r['recovered_at']),
                ex_date($r['archived_at']),
                $r['source'] === 'cctv_auto' ? 'CCTV (auto-detected)' : 'Manual surrender',
            ];
        }
    }

} catch (Throwable $e) {
    ms_json(['success' => false, 'message' => 'Export failed: ' . $e->getMessage()], 500);
}

// ── Audit: an export of personal data is itself worth recording ───────────────
try {
    $monitorPdo->prepare(
        "INSERT INTO monitoring_logs (room_id, event_type, event_message, triggered_by)
         VALUES (NULL, 'records_export', ?, ?)"
    )->execute([
        sprintf(
            'Exported %d %s record(s)%s.',
            count($rows),
            $dataset,
            $year ? " for {$year}" : ' (all years)'
        ),
        $_SESSION['user_id'] ?? null,
    ]);
} catch (Throwable $e) {
    // A failed audit write must not cost the admin their export.
}

// ── Stream ────────────────────────────────────────────────────────────────────
$label    = $dataset === 'claimants' ? 'confirmed_claims' : 'unclaimed_donated_items';
$filename = sprintf('spotit_%s_%s.csv', $label, $year ?: 'all');

header('Content-Type: text/csv; charset=utf-8');
header("Content-Disposition: attachment; filename=\"{$filename}\"");
header('Cache-Control: no-store');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');

// UTF-8 BOM. Without it Excel reads the file as the system codepage and mangles
// any accented character in a student's name.
fwrite($out, "\xEF\xBB\xBF");

// Provenance block — a bare CSV of names with no context is not a record.
fputcsv($out, ['S.P.O.T.-IT — ' . ($dataset === 'claimants'
    ? 'Confirmed Item Claims'
    : 'Unclaimed Items Processed for Donation')]);
fputcsv($out, [
    'Period: ' . ($year ? "calendar year {$year}" : 'all records'),
    'Generated: ' . date('Y-m-d H:i'),
    'Generated by: ' . ($_SESSION['user_name'] ?? 'admin'),
]);
fputcsv($out, []);

fputcsv($out, $headers);
foreach ($rows as $row) {
    fputcsv($out, $row);
}

if (!$rows) {
    fputcsv($out, ['(no records for this period)']);
}

fclose($out);
exit;
