<?php
/**
 * S.P.O.T.-IT — Snapshot evidence filename generation.
 *
 * Pulled out of auth/ingest_detection.php so the naming rule (the actual fix for
 * the Snapshot A filename-collision bug — see CLAUDE.md "Evidence caveat",
 * 2026-11-12) is a plain function instead of inline script code, and can be
 * unit-tested directly (tests/test_snapshot_naming.php) instead of re-implemented
 * in a test file. No dependencies — safe to require from ingest_detection.php
 * without pulling in sessions or other services' DBs.
 */

/**
 * Build a unique, filesystem-safe Snapshot A filename for one detection event.
 * Never echoes the client-supplied name back verbatim: that is what let one
 * event's upload silently overwrite an earlier event's evidence file on disk
 * whenever two events shared a room+zone (main.py always sends the same name
 * for a given ROI label). The literal "_a_" marker lets uploads/.htaccess deny
 * direct access to the file without a DB lookup — mirrors the "_b_" marker
 * Snapshot B uploads already used (see the snapshot_b branch of
 * auth/ingest_detection.php, unchanged by this fix: it was already unique).
 *
 * @param string $roomId    Raw room_id from the request.
 * @param string $zone      Raw object_zone (ROI label) from the request.
 * @param string $origName  Client-supplied file name, used only for its extension.
 */
function spotit_unique_snapshot_filename(string $roomId, string $zone, string $origName): string
{
    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION) ?: 'jpg');
    if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        $ext = 'jpg';
    }
    $safeRoom = preg_replace('/[^A-Za-z0-9]+/', '', $roomId) ?: 'room';
    $safeZone = preg_replace('/[^A-Za-z0-9]+/', '', $zone) ?: 'item';

    return date('Ymd_His') . '_' . $safeRoom . '_' . $safeZone . '_a_' . uniqid() . '.' . $ext;
}
