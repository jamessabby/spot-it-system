-- ============================================================
-- S.P.O.T.-IT — User Service Database Schema
-- Database: spotit_user_db
-- Tables:   user_profiles, user_settings
--
-- MICROSERVICES: This DB is ONLY for user profile and preference data.
-- user_id is a denormalized reference to spotit_auth_db.users.id.
-- No FK constraints across microservice databases by design.
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+08:00";
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `spotit_user_db`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `spotit_user_db`;

-- ── user_profiles ─────────────────────────────────────────────────────────────
-- Extended profile info beyond what's stored in auth DB.
CREATE TABLE `user_profiles` (
  `id`             int(11)      NOT NULL AUTO_INCREMENT,
  `user_id`        int(11)      NOT NULL COMMENT 'Mirrors users.id from spotit_auth_db',
  `name`           varchar(200) DEFAULT NULL,
  `email`          varchar(200) DEFAULT NULL COMMENT 'Denormalized for quick lookup',
  `phone`          varchar(50)  DEFAULT NULL,
  `course`         varchar(100) DEFAULT NULL COMMENT 'e.g. BS Computer Engineering',
  `year_level`     varchar(20)  DEFAULT NULL COMMENT 'e.g. 3rd Year',
  `profile_pic`    varchar(300) DEFAULT NULL COMMENT 'URL or path to profile picture',
  `role`           varchar(30)  DEFAULT NULL COMMENT 'Denormalized from auth DB',
  `department`     varchar(100) DEFAULT NULL COMMENT 'e.g. College of Engineering',
  `created_at`     timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     timestamp    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_id` (`user_id`),
  KEY `idx_email`          (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── user_settings ─────────────────────────────────────────────────────────────
-- Per-user preferences stored as JSON for flexibility.
CREATE TABLE `user_settings` (
  `id`            int(11)  NOT NULL AUTO_INCREMENT,
  `user_id`       int(11)  NOT NULL,
  `settings_json` text     NOT NULL COMMENT 'JSON object of all user preferences',
  `updated_at`    datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_settings` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default settings template (insert when new user registers):
-- {
--   "notif_email_alerts":     true,
--   "notif_email_claims":     true,
--   "notif_dashboard_alerts": true,
--   "notif_sound_enabled":    true,
--   "ui_theme":               "light",
--   "privacy_show_in_thread": true,
--   "privacy_show_id_number": false
-- }

COMMIT;
