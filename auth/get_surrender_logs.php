<?php
/**
 * S.P.O.T.-IT — Get Surrender Logs API
 * auth/get_surrender_logs.php
 *
 * GET endpoint. Returns JSON. Admin/staff only (same audience as
 * pages/surrender-log.php, which is the only caller).
 *
 * surrender_logs and recovered_items are both in spotit_lf_db, so the status
 * join below is a normal same-database SQL join, not a cross-service one —
 * CLAUDE.md's microservices rule only forbids joining across the five
 * separate databases. staff_accepted_by is a spotit_auth_db user_id, which
 * IS cross-service, so that name is resolved with a second, separate query
 * against $authPdo and merged in PHP (the sanctioned pattern — see
 * service_bootstrap.php's own cross-service helpers).
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

ms_require_auth('../pages/login.php');
if (($_SESSION['user_role'] ?? '') !== 'admin') {
    ms_json(['success' => false, 'message' => 'Unauthorized.'], 403);
}

$limit = min((int) ($_GET['limit'] ?? 50), 200);

try {
    $stmt = $lfPdo->prepare(
        "SELECT sl.surrender_id, sl.recovery_id, sl.surrendered_by, sl.item_description,
                sl.room_id, sl.staff_accepted_by, sl.surrendered_at, sl.source,
                ri.found_location, ri.status, ri.notes
         FROM surrender_logs sl
         LEFT JOIN recovered_items ri ON ri.recovery_id = sl.recovery_id
         ORDER BY sl.surrendered_at DESC
         LIMIT ?"
    );
    $stmt->execute([$limit]);
    $rows = $stmt->fetchAll();

    // Resolve staff_accepted_by -> name via spotit_auth_db (cross-service read,
    // done as a separate query — never a cross-database JOIN).
    $staffIds = array_values(array_unique(array_filter(
        array_map(fn($r) => (int) ($r['staff_accepted_by'] ?? 0), $rows)
    )));
    $namesById = [];
    if ($staffIds) {
        $ph = implode(',', array_fill(0, count($staffIds), '?'));
        $nameStmt = $authPdo->prepare("SELECT id, full_name FROM users WHERE id IN ({$ph})");
        $nameStmt->execute($staffIds);
        foreach ($nameStmt->fetchAll() as $u) {
            $namesById[(int) $u['id']] = $u['full_name'];
        }
    }

    $items = [];
    foreach ($rows as $r) {
        // notes holds "Condition: X" for every manual_surrender row (see
        // auth/record_surrender.php) — parsed back out for display rather
        // than adding a dedicated column.
        $condition = 'Good';
        if (preg_match('/^Condition:\s*(Good|Damaged|Incomplete)$/', (string) $r['notes'], $m)) {
            $condition = $m[1];
        }
        $items[] = [
            'surrender_id'   => (int) $r['surrender_id'],
            'recovery_id'    => (int) $r['recovery_id'],
            'surrendered_by' => $r['surrendered_by'],
            'item_description' => $r['item_description'],
            'found_location' => $r['found_location'],
            'condition'      => $condition,
            'processed_by'   => $namesById[(int) $r['staff_accepted_by']] ?? 'Unknown',
            'surrendered_at' => $r['surrendered_at'],
            // null when the recovered_items row cannot be found (should not
            // happen for rows this handler creates, but a LEFT JOIN protects
            // the page from a hard error if one is ever missing).
            'status'         => $r['status'] ?? 'unknown',
        ];
    }

    ms_json(['success' => true, 'count' => count($items), 'items' => $items]);
} catch (Throwable $e) {
    ms_json(['success' => false, 'message' => 'Failed to fetch surrender records.'], 500);
}
