-- ============================================================
-- S.P.O.T.-IT — Room Enable/Disable Migration
-- File: services/migration_room_enabled.sql
--
-- Adds `is_enabled` to spotit_monitor_db.rooms and an index on camera_ip.
--
-- WHY A NEW COLUMN INSTEAD OF REUSING is_active:
--   is_active is the row-lifecycle (soft-delete) flag. Every listing query in
--   the app filters `is_active = 1`, and add_room.php treats an is_active = 0
--   row as "a deleted room being re-created" — it silently reactivates it.
--   If Disable set is_active = 0 then the disabled room would vanish from
--   room-setup.php's own table (so there would be no Enable button to click),
--   edit_room.php would refuse to update it (`AND is_active = 1`), and
--   re-adding the same room id would quietly undo the admin's decision.
--
--   So the two flags mean different things and both are needed:
--     is_active  = does this row exist at all      (0 = deleted)
--     is_enabled = is this room in service         (0 = parked by an admin)
--
--   A disabled room keeps its baseline, registered items and detection
--   history, and is still listed on room-setup.php so it can be re-enabled.
--   What it loses is its claim on camera_ip — see the exclusivity check in
--   ms_camera_ip_conflict() (auth/service_bootstrap.php), which only counts
--   enabled rooms as holding a camera.
--
-- The camera_ip index backs that same exclusivity lookup.
--
-- Run order: after migration_room_camera_columns.sql (needs camera_ip to exist).
-- Safe to re-run.
-- ============================================================

USE `spotit_monitor_db`;

ALTER TABLE `rooms`
  ADD COLUMN IF NOT EXISTS `is_enabled` tinyint(1) NOT NULL DEFAULT 1
    COMMENT 'Service state: 0 = taken out of service by an admin. Keeps all data, releases its camera_ip claim'
  AFTER `is_active`;

ALTER TABLE `rooms`
  ADD INDEX IF NOT EXISTS `idx_enabled` (`is_enabled`);

-- Backs the "is this camera already claimed by another room?" lookup.
ALTER TABLE `rooms`
  ADD INDEX IF NOT EXISTS `idx_camera_ip` (`camera_ip`);
