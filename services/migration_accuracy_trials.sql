-- ============================================================
-- S.P.O.T.-IT — Accuracy Trials Migration
-- File: services/migration_accuracy_trials.sql
--
-- Creates spotit_monitor_db.accuracy_trials — the manual TP/FP/FN/TN tally
-- logged from the desk sandbox during Table 3.5 testing. This is the raw
-- material behind the Chapter 4 accuracy figures.
--
-- WHY THIS EXISTS: the table was created by hand in phpMyAdmin and never
-- written into any schema or migration file, while four call sites already
-- read or write it (log_sandbox_trial.php, get_desk_status.php,
-- get_room_status.php, reset_system_state.php) plus the sandbox metrics panel
-- in pages/desk-sandbox.php. A fresh deploy therefore fataled on both room
-- status endpoints and the sandbox. Flagged in md_files/BUGS.md §1.
--
-- Column definitions were taken from SHOW CREATE TABLE on the working local
-- install, not from the INSERT statement alone, so a migrated database matches
-- the one the recorded trials were captured on.
--
-- Run order: after the monitoring base schema. Independent of the others.
-- Safe to re-run.
-- ============================================================

USE `spotit_monitor_db`;

CREATE TABLE IF NOT EXISTS `accuracy_trials` (
  `trial_id`        int(11)  NOT NULL AUTO_INCREMENT,
  `trial_timestamp` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,

  -- Ground truth, set by the tester before the trial.
  `expected_state`  enum('present','absent') NOT NULL
                    COMMENT 'What the tester knows to be true',

  -- What the detector actually reported for that trial.
  `detected_state`  enum('present','absent') NOT NULL
                    COMMENT 'What the system reported',

  -- Derived from the pair above by log_sandbox_trial.php. "Positive" is ABSENT
  -- (a deviation event occurred), so: absent/absent = TP, present/absent = FP,
  -- present/present = TN, absent/present = FN.
  `classification`  enum('TP','FP','TN','FN') NOT NULL
                    COMMENT 'Confusion-matrix cell. Positive class = absent (deviation).',

  `notes`           varchar(300) DEFAULT NULL
                    COMMENT 'Free-text trial condition e.g. "hand occlusion, 5s"',

  PRIMARY KEY (`trial_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
