<?php
/**
 * S.P.O.T.-IT — End-of-year donation & retention purge
 * auth/process_donation.php
 *
 * POST, admin-only. Closes the data lifecycle documented in CLAUDE.md §3c:
 *
 *     Detected → Flagged → Recovered → Claimed  ─┐
 *                                    → Unclaimed ┴→ Donated → purged
 *
 * Per the SWAFO Director / CEAT technician interview, unclaimed personal items
 * are donated at the end of the academic year. That disposal is also this
 * system's garbage collection: once an item physically leaves, the evidence
 * gathered about it has no further purpose, and keeping CCTV imagery of
 * identifiable people longer than the process requires is exactly what a data
 * privacy review would object to.
 *
 * WHAT IS PURGED, AND WHY THAT ORDER
 *   1. An audit row is written FIRST, so the deletion itself is accountable even
 *      if a later step fails. A purge with no record of who ran it is worse than
 *      no purge at all.
 *   2. claims rows + their webcam selfies — these are photographs of students.
 *   3. detections rows + Snapshot A/B — the CCTV evidence trail.
 *   4. The recovered_items row is KEPT, marked archived, description preserved.
 *      It becomes the permanent tombstone: what the item was, when it was found,
 *      when it was donated. Enough for an audit, nothing identifying a person.
 *
 * Files are deleted only after the transaction commits. A rolled-back
 * transaction that had already unlinked its images would leave rows pointing at
 * nothing — recoverable in neither direction.
 *
 * MICROSERVICES: spotit_lf_db and spotit_monitor_db are touched through their
 * own PDO handles from service_bootstrap.php, never joined across.
 */

require_once __DIR__ . '/service_bootstrap.php';
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'POST required.'], 405);
}

ms_require_auth('../pages/login.php');

// Admin only — this is destructive and irreversible.
$role   = $_SESSION['user_role'] ?? '';
$userId = $_SESSION['user_id'] ?? null;
session_write_close();

if ($role !== 'admin') {
    ms_json(['success' => false, 'message' => 'Only administrators can process donations.'], 403);
}

// ── Input ─────────────────────────────────────────────────────────────────────
$idsRaw = $_POST['recovery_ids'] ?? '';
$ids = array_values(array_unique(array_filter(
    array_map('intval', is_array($idsRaw) ? $idsRaw : explode(',', (string) $idsRaw)),
    fn($v) => $v > 0
)));

if (!$ids) {
    ms_json(['success' => false, 'message' => 'No items selected.'], 400);
}

$note = trim((string) ($_POST['note'] ?? ''));
$placeholders = implode(',', array_fill(0, count($ids), '?'));

$filesToDelete = [];
$purged = ['items' => 0, 'claims' => 0, 'detections' => 0, 'files' => 0];

try {
    // ── Gather everything first, inside no transaction ────────────────────────
    // We need the file paths and detection ids before the rows are gone.
    $stmt = $lfPdo->prepare(
        "SELECT recovery_id, detection_id, item_description, room_id, snapshot_path
           FROM recovered_items
          WHERE recovery_id IN ($placeholders)
            AND status IN ('recovered', 'pending_claim')"
    );
    $stmt->execute($ids);
    $items = $stmt->fetchAll();

    if (!$items) {
        ms_json([
            'success' => false,
            'message' => 'None of the selected items are eligible — already claimed or archived.',
        ], 409);
    }

    $eligibleIds  = array_column($items, 'recovery_id');
    $detectionIds = array_values(array_filter(array_column($items, 'detection_id')));

    foreach ($items as $it) {
        if (!empty($it['snapshot_path'])) {
            $filesToDelete[] = $it['snapshot_path'];
        }
    }

    $ep = implode(',', array_fill(0, count($eligibleIds), '?'));

    // Claim selfies — photographs of real students, so these matter most.
    $cs = $lfPdo->prepare("SELECT webcam_snapshot FROM claims WHERE recovery_id IN ($ep)");
    $cs->execute($eligibleIds);
    foreach ($cs->fetchAll() as $c) {
        if (!empty($c['webcam_snapshot'])) {
            $filesToDelete[] = $c['webcam_snapshot'];
        }
    }

    // Snapshot A / B from the monitoring service.
    if ($detectionIds) {
        $dp = implode(',', array_fill(0, count($detectionIds), '?'));
        $ds = $monitorPdo->prepare(
            "SELECT snapshot_path, snapshot_path_b FROM detections WHERE detection_id IN ($dp)"
        );
        $ds->execute($detectionIds);
        foreach ($ds->fetchAll() as $d) {
            foreach (['snapshot_path', 'snapshot_path_b'] as $col) {
                if (!empty($d[$col])) {
                    $filesToDelete[] = $d[$col];
                }
            }
        }
    }

    // ── 1. Audit first ────────────────────────────────────────────────────────
    $monitorPdo->prepare(
        "INSERT INTO monitoring_logs (room_id, event_type, event_message, triggered_by)
         VALUES (?, 'donation_purge', ?, ?)"
    )->execute([
        $items[0]['room_id'] ?? null,
        sprintf(
            'Donated and purged %d unclaimed item(s): [%s]. Detections purged: %d. %s',
            count($eligibleIds),
            implode(', ', array_map(fn($i) => $i['item_description'], $items)),
            count($detectionIds),
            $note !== '' ? "Note: {$note}" : ''
        ),
        $userId,
    ]);

    // ── 2. Lost & Found service ───────────────────────────────────────────────
    $lfPdo->beginTransaction();

    $cd = $lfPdo->prepare("DELETE FROM claims WHERE recovery_id IN ($ep)");
    $cd->execute($eligibleIds);
    $purged['claims'] = $cd->rowCount();

    // Tombstone, not deletion: what/when/where survives, the imagery does not.
    // 'unclaimed_archived' is the existing terminal status — no schema change is
    // needed to record a donation, and archived_at is when it happened.
    $ru = $lfPdo->prepare(
        "UPDATE recovered_items
            SET status = 'unclaimed_archived',
                archived_at = NOW(),
                snapshot_path = NULL,
                notes = CONCAT(COALESCE(notes,''), ?)
          WHERE recovery_id IN ($ep)"
    );
    $ru->execute(array_merge(
        ["\n[DONATED " . date('Y-m-d H:i') . "] Evidence purged." . ($note !== '' ? " {$note}" : '')],
        $eligibleIds
    ));
    $purged['items'] = $ru->rowCount();

    $lfPdo->commit();

    // ── 3. Monitoring service ─────────────────────────────────────────────────
    if ($detectionIds) {
        $dp = implode(',', array_fill(0, count($detectionIds), '?'));
        $monitorPdo->beginTransaction();
        $dd = $monitorPdo->prepare("DELETE FROM detections WHERE detection_id IN ($dp)");
        $dd->execute($detectionIds);
        $purged['detections'] = $dd->rowCount();
        $monitorPdo->commit();
    }

} catch (Throwable $e) {
    foreach ([$lfPdo, $monitorPdo] as $pdo) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    }
    // Nothing has been unlinked yet — file deletion happens only past this point.
    ms_json(['success' => false, 'message' => 'Purge aborted, no files removed: ' . $e->getMessage()], 500);
}

// ── 4. Files, only now that the database is committed ─────────────────────────
foreach (array_unique($filesToDelete) as $rel) {
    $name = basename($rel);              // stored values are bare filenames
    if ($name === '' || $name === '.' || $name === '..') {
        continue;
    }
    $path = SNAPSHOT_PATH . $name;
    // realpath() confirms the resolved file really sits inside the snapshot
    // directory, so a malformed stored value cannot reach outside it.
    $real = realpath($path);
    $base = realpath(SNAPSHOT_PATH);
    if ($real && $base && strncmp($real, $base, strlen($base)) === 0 && is_file($real)) {
        if (@unlink($real)) {
            $purged['files']++;
        }
    }
}

ms_json([
    'success' => true,
    'message' => sprintf(
        'Donated %d item(s). Purged %d claim record(s), %d detection(s) and %d image file(s).',
        $purged['items'], $purged['claims'], $purged['detections'], $purged['files']
    ),
    'purged'  => $purged,
]);
