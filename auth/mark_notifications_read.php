<?php
/**
 * S.P.O.T.-IT — Mark Notifications Read  [FIXES G9 — part 2]
 * auth/mark_notifications_read.php
 *
 * POST endpoint. Marks one specific notification OR all unread as read.
 *
 * POST body:
 *   notification_id  (int, optional) — mark just this one
 *   all=1            — mark all unread for current user
 *
 * MICROSERVICES: Writes to spotit_auth_db.notifications only.
 */
require_once __DIR__ . '/service_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ms_json(['success' => false, 'message' => 'Method not allowed.'], 405);
}

ms_require_auth('../pages/login.php');

$userId = (int)$_SESSION['user_id'];
$markAll = isset($_POST['all']) && $_POST['all'];
$notifId = (int)($_POST['notification_id'] ?? 0);

try {
    if ($markAll) {
        $authPdo->prepare(
            "UPDATE notifications SET is_read = 1, read_at = NOW()
             WHERE user_id = ? AND is_read = 0"
        )->execute([$userId]);
        ms_json(['success' => true, 'marked' => 'all']);

    } elseif ($notifId) {
        // Only allow marking your own notifications
        $authPdo->prepare(
            "UPDATE notifications SET is_read = 1, read_at = NOW()
             WHERE notification_id = ? AND user_id = ?"
        )->execute([$notifId, $userId]);
        ms_json(['success' => true, 'marked' => $notifId]);

    } else {
        ms_json(['success' => false, 'message' => 'Provide notification_id or all=1.']);
    }

} catch (Throwable $e) {
    ms_json(['success' => false, 'message' => 'Failed to mark notification.'], 500);
}
