-- ============================================================
-- S.P.O.T.-IT — Lost & Found Service Database Schema
-- Database: spotit_lf_db
-- Tables:   recovered_items, surrender_logs, claims
--
-- MICROSERVICES: This DB handles ONLY lost-and-found transactions.
-- No auth, monitoring, or user profile data goes here.
-- detection_id and room_id are denormalized foreign keys
-- (no FK constraints across microservice databases by design).
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+08:00";
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `spotit_lf_db`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `spotit_lf_db`;

-- ── recovered_items ───────────────────────────────────────────────────────────
-- Items that have been secured and are available for claiming.
-- Created automatically when a detection event is marked 'recovered',
-- OR manually when a student/staff surrenders a found item.
CREATE TABLE `recovered_items` (
  `recovery_id`       int(11)      NOT NULL AUTO_INCREMENT,
  `detection_id`      int(11)      DEFAULT NULL COMMENT 'From spotit_monitor_db.detections — nullable for manual surrenders',
  `room_id`           varchar(20)  NOT NULL COMMENT 'Denormalized from rooms table',
  `item_description`  text         NOT NULL COMMENT 'Full text description of the item',
  `item_type`         varchar(100) DEFAULT NULL COMMENT 'e.g. Umbrella, Cellphone, Keyboard',
  `item_tier`         enum('tier1','tier2','tier3','tier4') DEFAULT 'tier1',
  `found_location`    varchar(200) DEFAULT NULL COMMENT 'Specific location e.g. WS-07, near door',
  `snapshot_path`     varchar(300) DEFAULT NULL COMMENT 'Reference photo of the item',
  `recovered_at`      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `recovered_by`      int(11)      DEFAULT NULL COMMENT 'user_id of staff who secured item',
  `source`            enum('cctv_auto','manual_surrender') NOT NULL DEFAULT 'cctv_auto',
  `status`            enum('recovered','pending_claim','claimed','unclaimed_archived')
                      NOT NULL DEFAULT 'recovered',
  `archived_at`       datetime     DEFAULT NULL,
  `notes`             text         DEFAULT NULL,
  PRIMARY KEY (`recovery_id`),
  KEY `idx_room`       (`room_id`),
  KEY `idx_status`     (`status`),
  KEY `idx_recovered`  (`recovered_at`),
  KEY `idx_detection`  (`detection_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── surrender_logs ────────────────────────────────────────────────────────────
-- Items physically surrendered by students or staff at the dispensing window,
-- separate from CCTV-auto-detected items.
CREATE TABLE `surrender_logs` (
  `surrender_id`      int(11)      NOT NULL AUTO_INCREMENT,
  `recovery_id`       int(11)      DEFAULT NULL COMMENT 'Links to recovered_items once created',
  `surrendered_by`    varchar(200) NOT NULL COMMENT 'Name of person who surrendered the item',
  `surrenderer_id`    varchar(20)  DEFAULT NULL COMMENT 'University ID of surrenderer',
  `item_description`  text         NOT NULL,
  `room_id`           varchar(20)  DEFAULT NULL COMMENT 'Where item was found',
  `detection_id`      int(11)      DEFAULT NULL COMMENT 'Linked detection event if any',
  `staff_accepted_by` int(11)      DEFAULT NULL COMMENT 'user_id of accepting staff',
  `surrendered_at`    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `source`            enum('student','staff','faculty') NOT NULL DEFAULT 'student',
  PRIMARY KEY (`surrender_id`),
  KEY `idx_recovery`   (`recovery_id`),
  KEY `idx_room`       (`room_id`),
  KEY `idx_surrender`  (`surrendered_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── claims ────────────────────────────────────────────────────────────────────
-- Claim requests submitted by students for recovered items.
-- Staff verify the claim at the dispensing window and update status.
CREATE TABLE `claims` (
  `id`                 int(11)      NOT NULL AUTO_INCREMENT,
  `recovery_id`        int(11)      NOT NULL,
  `user_id`            int(11)      DEFAULT NULL COMMENT 'user_id from spotit_auth_db',
  `claimant_name`      varchar(200) NOT NULL,
  `university_id`      varchar(20)  NOT NULL,
  `contact`            varchar(50)  DEFAULT NULL,
  `item_description`   text         NOT NULL COMMENT 'Claimant provided description for verification',
  `webcam_snapshot`    varchar(300) DEFAULT NULL COMMENT 'Photo taken at claiming station handoff',
  `status`             enum('pending','verified','claimed','rejected') NOT NULL DEFAULT 'pending',
  `verified_by`        int(11)      DEFAULT NULL COMMENT 'user_id of verifying staff',
  `verification_notes` text         DEFAULT NULL,
  `submitted_at`       datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `claimed_at`         datetime     DEFAULT NULL COMMENT 'Timestamp of physical handoff',
  `claim_date`         date         GENERATED ALWAYS AS (DATE(`submitted_at`)) STORED,
  PRIMARY KEY (`id`),
  KEY `idx_recovery`    (`recovery_id`),
  KEY `idx_user`        (`user_id`),
  KEY `idx_status`      (`status`),
  KEY `idx_submitted`   (`submitted_at`),
  KEY `idx_univ_id`     (`university_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
