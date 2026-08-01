<?php
/**
 * S.P.O.T.-IT — Get Recovered Items API
 * auth/get_recovered_items.php
 *
 * GET endpoint. Returns JSON.
 * Returns recently recovered / available items from spotit_lf_db.recovered_items.
 * Used by the student dashboard "Recently Recovered" panel and lost-thread.php.
 * MICROSERVICES: Reads from spotit_lf_db only.
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

ms_require_auth('../pages/login.php');

$limit  = min((int)($_GET['limit'] ?? 20), 100);
$offset = (int)($_GET['offset'] ?? 0);
$status = $_GET['status'] ?? 'recovered'; // default: claimable items only

$where  = ['1=1'];
$params = [];

if ($status === 'donatable') {
    // Everything still awaiting an owner — the candidate pool for the
    // end-of-year donation run (pages/donation.php). Claimed and already
    // archived items are excluded because there is nothing left to dispose of.
    $where[] = "ri.status IN ('recovered','pending_claim')";
} elseif ($status !== 'all') {
    $where[]  = 'ri.status = ?';
    $params[] = $status;
}

// Age filter, used by the donation page so staff cannot accidentally dispose of
// something found last week. Days rather than a date so the caller does not have
// to care about timezones.
$minAgeDays = (int) ($_GET['min_age_days'] ?? 0);
if ($minAgeDays > 0) {
    $where[]  = 'ri.recovered_at <= DATE_SUB(NOW(), INTERVAL ? DAY)';
    $params[] = $minAgeDays;
}

$whereSQL = implode(' AND ', $where);

try {
    $stmt = $lfPdo->prepare(
        "SELECT
             ri.recovery_id,
             ri.room_id,
             ri.item_description,
             ri.item_type,
             ri.found_location,
             ri.recovered_at,
             ri.status,
             ri.snapshot_path,
             ri.source
         FROM recovered_items ri
         WHERE {$whereSQL}
         ORDER BY ri.recovered_at DESC
         LIMIT ? OFFSET ?"
    );
    $params[] = $limit;
    $params[] = $offset;
    $stmt->execute($params);
    $items = $stmt->fetchAll();

    // Attach snapshot URL
    foreach ($items as &$it) {
        $it['snapshot_url'] = $it['snapshot_path']
            ? SNAPSHOT_URL . basename($it['snapshot_path'])
            : null;
    }

    ms_json([
        'success' => true,
        'count'   => count($items),
        'items'   => $items,
    ]);
} catch (Throwable $e) {
    ms_json(['success' => false, 'message' => 'Failed to fetch recovered items.'], 500);
}
