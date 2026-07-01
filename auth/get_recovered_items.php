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

if ($status !== 'all') {
    $where[]  = 'ri.status = ?';
    $params[] = $status;
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
