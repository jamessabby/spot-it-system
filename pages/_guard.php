<?php
/**
 * S.P.O.T.-IT — Page access guard
 * pages/_guard.php
 *
 * Include this immediately after config/env.php on every page that is not
 * deliberately public.
 *
 * WHY IT EXISTS
 * An audit on 2026-08-02 found 18 of 22 sidebar pages had no authentication
 * check at all. Anonymous visitors got the full admin UI: room names, the
 * monitoring layout, user management, system logs. The data endpoints in auth/
 * were correctly protected, so no records leaked - but on a public URL, serving
 * the administrative interface to anyone who guesses the filename is not
 * defensible.
 *
 * WHY NOT service_bootstrap.php
 * ms_require_auth() lives there, but that file eagerly opens all four service
 * database connections. Pages that render their own shell and fetch data over
 * AJAX do not need a single one of them. Checking $_SESSION costs nothing;
 * env.php has already started the session.
 *
 * ROLE MODEL (CLAUDE.md §2): 'staff' is normalised to 'admin' at login, so
 * there are only two effective roles - admin and student.
 */

if (!defined('SITE_URL')) {
    // Defensive: a page that forgot to include env.php first would otherwise
    // have no session at all and silently pass every check below.
    require_once __DIR__ . '/../config/env.php';
}

if (!function_exists('spotit_require_login')) {
    /**
     * Any authenticated user. Use on pages shared by students and admins
     * (profile, settings, forum, notifications, lost-and-found threads).
     */
    function spotit_require_login(): void
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: login.php?error=login_required');
            exit();
        }
    }

    /**
     * Admin only. Use on anything under Monitoring or Management - the room
     * dashboards, camera setup, user management, system logs, donation.
     *
     * A logged-in student who guesses an admin URL is sent to their own
     * dashboard rather than the login page: they are authenticated, just not
     * authorised, and bouncing them to a login form they have already passed is
     * confusing.
     */
    function spotit_require_admin(): void
    {
        if (empty($_SESSION['user_id'])) {
            header('Location: login.php?error=login_required');
            exit();
        }
        if (($_SESSION['user_role'] ?? '') !== 'admin') {
            header('Location: dashboard-student.php?error=unauthorized');
            exit();
        }
    }
}
