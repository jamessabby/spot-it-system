-- ============================================================
-- S.P.O.T.-IT — Room Camera Credentials Migration
-- File: services/migration_room_camera_columns.sql
--
-- Adds camera_ip / camera_user / camera_pass to spotit_monitor_db.rooms.
--
-- WHY THIS EXISTS: these three columns were added by hand in phpMyAdmin during
-- the 2026-07-26 dynamic-camera work and never written back into
-- services/monitoring/schema.sql, so every install created from the schema file
-- was missing them while six handlers already read/wrote them
-- (add_room.php, edit_room.php, capture_frame.php, get_room_camera.php,
-- tapo_stream.php, save_rois.php). schema.sql has since been corrected — this
-- migration is for installs that were already created from the old file.
--
-- Safe to run on a DB that already has the columns (IF NOT EXISTS).
-- Run order: any time after the base schemas; independent of the other four.
-- ============================================================

USE `spotit_monitor_db`;

-- Private LAN address of the room's Tapo camera, e.g. 192.168.18.14.
-- NULL means "no camera bound to this room yet".
ALTER TABLE `rooms`
  ADD COLUMN IF NOT EXISTS `camera_ip` varchar(100) DEFAULT NULL
    COMMENT 'Private LAN address of the room camera e.g. 192.168.18.14'
  AFTER `camera_count`;

-- RTSP account credentials (the camera-local account created in the Tapo app,
-- NOT the TP-Link cloud login).
ALTER TABLE `rooms`
  ADD COLUMN IF NOT EXISTS `camera_user` varchar(100) DEFAULT NULL
    COMMENT 'RTSP account username (Tapo camera account, not the TP-Link cloud login)'
  AFTER `camera_ip`;

ALTER TABLE `rooms`
  ADD COLUMN IF NOT EXISTS `camera_pass` varchar(100) DEFAULT NULL
    COMMENT 'RTSP account password'
  AFTER `camera_user`;
