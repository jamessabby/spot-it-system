-- ============================================================
-- S.P.O.T.-IT — Workflow Audit Migration
-- File: services/migration_workflow_audit.sql
--
-- Run this AFTER your base schemas to fix all gaps identified
-- in the core monitoring workflow audit.
--
-- Databases affected:
--   spotit_auth_db     — notifications table, user_activity table, read_at column
--   spotit_monitor_db  — is_removed column on detections, triggered_by on logs
-- ============================================================

-- ── 1. spotit_auth_db — notifications table  [FIXES G9] ──────────────────────
USE `spotit_auth_db`;

CREATE TABLE IF NOT EXISTS `notifications` (
  `notification_id` int(11)      NOT NULL AUTO_INCREMENT,
  `user_id`         int(11)      NOT NULL COMMENT 'Recipient — from users table',
  `type`            varchar(60)  NOT NULL COMMENT 'e.g. potential_lost, confirmed_missing, item_recovered, new_claim, claim_completed',
  `title`           varchar(300) NOT NULL,
  `body`            text         NOT NULL,
  `detection_id`    int(11)      DEFAULT NULL COMMENT 'Linked detection from spotit_monitor_db (denormalized)',
  `room_id`         varchar(20)  DEFAULT NULL,
  `is_read`         tinyint(1)   NOT NULL DEFAULT 0,
  `read_at`         datetime     DEFAULT NULL,
  `created_at`      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`notification_id`),
  KEY `idx_user_unread` (`user_id`, `is_read`),
  KEY `idx_user`        (`user_id`),
  KEY `idx_type`        (`type`),
  KEY `idx_created`     (`created_at`),
  KEY `idx_detection`   (`detection_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 2. spotit_auth_db — user_activity table ───────────────────────────────────
-- Tracks per-user page visit timestamps for "unread" announcement badge logic.
CREATE TABLE IF NOT EXISTS `user_activity` (
  `id`                         int(11)  NOT NULL AUTO_INCREMENT,
  `user_id`                    int(11)  NOT NULL,
  `last_viewed_announcements`  datetime DEFAULT NULL,
  `last_viewed_forum`          datetime DEFAULT NULL,
  `last_login`                 datetime DEFAULT NULL,
  `updated_at`                 datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── 3. spotit_monitor_db — add missing columns  [FIXES G13] ──────────────────
USE `spotit_monitor_db`;

-- Add is_removed to detections if not already present
ALTER TABLE `detections`
  ADD COLUMN IF NOT EXISTS `is_removed` tinyint(1) NOT NULL DEFAULT 0 AFTER `notes`;

-- Add triggered_by to monitoring_logs if not present  [FIXES G6]
ALTER TABLE `monitoring_logs`
  ADD COLUMN IF NOT EXISTS `triggered_by` int(11) DEFAULT NULL
      COMMENT '0 = system, user_id = human actor' AFTER `event_message`;

-- Add room_id to monitoring_logs if not present  [FIXES G6]
ALTER TABLE `monitoring_logs`
  ADD COLUMN IF NOT EXISTS `room_id` varchar(20) DEFAULT NULL
      COMMENT 'Denormalized from detections.room_id' AFTER `log_id`;

-- Index for triggered_by lookups
ALTER TABLE `monitoring_logs`
  ADD INDEX IF NOT EXISTS `idx_triggered` (`triggered_by`);

-- ── 4. spotit_lf_db — add source/item_type/item_tier if missing  [FIXES G7] ──
USE `spotit_lf_db`;

ALTER TABLE `recovered_items`
  ADD COLUMN IF NOT EXISTS `source`    enum('cctv_auto','manual_surrender')
      NOT NULL DEFAULT 'cctv_auto' AFTER `recovered_at`;

ALTER TABLE `recovered_items`
  ADD COLUMN IF NOT EXISTS `item_type` varchar(100) DEFAULT NULL
      COMMENT 'e.g. Monitor, Keyboard, Umbrella' AFTER `item_description`;

ALTER TABLE `recovered_items`
  ADD COLUMN IF NOT EXISTS `item_tier`
      enum('tier1','tier2','tier3','tier4') DEFAULT 'tier1'
      AFTER `item_type`;

-- Add read_at column to claims if missing
ALTER TABLE `claims`
  ADD COLUMN IF NOT EXISTS `read_at` datetime DEFAULT NULL AFTER `claimed_at`;

-- ── 5. spotit_community_db — user_activity read tracking ─────────────────────
CREATE DATABASE IF NOT EXISTS `spotit_community_db`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `spotit_community_db`;

-- Confirmation view log (tracks who viewed which announcement)
CREATE TABLE IF NOT EXISTS `announcement_views` (
  `id`              int(11) NOT NULL AUTO_INCREMENT,
  `announcement_id` int(11) NOT NULL,
  `user_id`         int(11) NOT NULL,
  `viewed_at`       datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_ann` (`user_id`, `announcement_id`),
  KEY `idx_ann` (`announcement_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
