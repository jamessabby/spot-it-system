-- ============================================================
-- S.P.O.T.-IT — Snapshot B (Re-detection/Removal Evidence) Migration
-- File: services/migration_snapshot_b.sql
--
-- Adds snapshot_path_b to detections — the second evidence photo captured
-- when an item that's already MISSING gets touched/removed again (see
-- UPDATES.md Phase 2, Step 2.1: "Snapshot B (Re-detection/removal) logic").
-- Not part of the teammate's build — this is prep for main.py's upcoming
-- dual-snapshot capture. Safe to run even if main.py isn't writing to it yet.
-- ============================================================

USE `spotit_monitor_db`;

ALTER TABLE `detections`
  ADD COLUMN IF NOT EXISTS `snapshot_path_b` varchar(300) DEFAULT NULL
    COMMENT 'Second snapshot path, showing interaction/removal'
  AFTER `snapshot_path`;
