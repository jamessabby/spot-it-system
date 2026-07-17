<?php
/**
 * S.P.O.T.-IT — Staff Dashboard (DEPRECATED — merged into admin dashboard)
 * pages/dashboard-staff.php
 *
 * The thesis has only two real dashboard roles: admin and student. "Staff"
 * (housekeepers who physically verify items) never needs a web login — they
 * go to the room when alerted. The `staff` DB role value is kept for now in
 * case advisers ask about it, but it's routed to the exact same dashboard as
 * admin. See CLAUDE.md §2 and §3c for the full rationale.
 *
 * login_handler.php and microsoft_callback.php already normalize a staff
 * login's SESSION role to 'admin', so they never reach this file at all.
 * This stub only matters for stale bookmarks/links pointing here directly.
 */
require_once __DIR__ . '/../auth/service_bootstrap.php';
header('Location: dashboard-admin.php');
exit();