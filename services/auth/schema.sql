-- ============================================================
-- S.P.O.T.-IT — Auth Service Database Schema
-- Database: spotit_auth_db
-- Tables:   users, login_attempts, sessions, microsoft_tokens
--
-- MICROSERVICES: This DB is ONLY for authentication.
-- No monitoring, lost-and-found, or profile data goes here.
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+08:00";
SET NAMES utf8mb4;

CREATE DATABASE IF NOT EXISTS `spotit_auth_db`
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE `spotit_auth_db`;

-- ── users ─────────────────────────────────────────────────────────────────────
-- Central user registry. Source of truth for all auth.
CREATE TABLE `users` (
  `id`              int(11)       NOT NULL AUTO_INCREMENT,
  `full_name`       varchar(200)  NOT NULL,
  `email`           varchar(200)  NOT NULL COMMENT 'Must end in @dlsud.edu.ph',
  `id_number`       varchar(20)   DEFAULT NULL COMMENT 'University ID e.g. 2021-00001',
  `password_hash`   varchar(255)  DEFAULT NULL COMMENT 'NULL for OAuth-only accounts',
  `role`            enum('student','staff','admin') NOT NULL DEFAULT 'student',
  `auth_provider`   enum('manual','microsoft')     NOT NULL DEFAULT 'manual',
  `microsoft_id`    varchar(100)  DEFAULT NULL COMMENT 'Azure AD object ID',
  `avatar`          varchar(300)  DEFAULT NULL COMMENT 'Profile photo URL',
  `is_active`       tinyint(1)    NOT NULL DEFAULT 1,
  `last_login_at`   datetime      DEFAULT NULL,
  `created_at`      datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      datetime      DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_email`        (`email`),
  UNIQUE KEY `uq_id_number`    (`id_number`),
  UNIQUE KEY `uq_microsoft_id` (`microsoft_id`),
  KEY `idx_role`               (`role`),
  KEY `idx_active`             (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── login_attempts ────────────────────────────────────────────────────────────
-- Rate limiting: tracks failed attempts per email identifier.
CREATE TABLE `login_attempts` (
  `id`            int(11)      NOT NULL AUTO_INCREMENT,
  `identifier`    varchar(200) NOT NULL COMMENT 'Email address being attempted',
  `attempt_count` int(11)      NOT NULL DEFAULT 1,
  `locked_until`  datetime     DEFAULT NULL COMMENT 'NULL = not locked',
  `last_attempt`  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_identifier` (`identifier`),
  KEY `idx_locked`           (`locked_until`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── sessions ──────────────────────────────────────────────────────────────────
-- Optional: server-side session tracking for single-device enforcement.
CREATE TABLE `sessions` (
  `id`          int(11)      NOT NULL AUTO_INCREMENT,
  `user_id`     int(11)      NOT NULL,
  `session_hash` varchar(128) NOT NULL,
  `ip_address`  varchar(45)  DEFAULT NULL,
  `user_agent`  varchar(300) DEFAULT NULL,
  `created_at`  datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `expires_at`  datetime     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_session_hash` (`session_hash`),
  KEY `idx_user_id`            (`user_id`),
  KEY `idx_expires`            (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── microsoft_tokens ──────────────────────────────────────────────────────────
-- Stores OAuth access/refresh tokens for Microsoft Graph API calls.
CREATE TABLE `microsoft_tokens` (
  `id`            int(11)      NOT NULL AUTO_INCREMENT,
  `user_id`       int(11)      NOT NULL,
  `access_token`  text         NOT NULL,
  `refresh_token` text         DEFAULT NULL,
  `expires_at`    datetime     NOT NULL,
  `created_at`    datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_token` (`user_id`),
  KEY `idx_expires`          (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── tour_status ───────────────────────────────────────────────────────────────
-- Tracks first-time onboarding tour completion per user, per role.
-- Role is included because a user's tour content differs by dashboard (admin/staff/student),
-- and a user could theoretically be re-assigned a role later and see that role's tour once.
CREATE TABLE `tour_status` (
  `id`            int(11)   NOT NULL AUTO_INCREMENT,
  `user_id`       int(11)   NOT NULL,
  `role`          enum('student','staff','admin') NOT NULL,
  `completed`     tinyint(1) NOT NULL DEFAULT 0,
  `completed_at`  datetime  DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_role` (`user_id`,`role`),
  KEY `idx_user`            (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Sample admin seed (password: Admin@2026!) ──────────────────────────────────
-- Change password immediately after first login.
INSERT INTO `users` (`full_name`, `email`, `id_number`, `password_hash`, `role`, `auth_provider`, `is_active`, `created_at`)
VALUES (
  'System Administrator',
  'admin@dlsud.edu.ph',
  '0000-00000',
  '$2y$12$exampleHashChangeMeImmediately000000000000000000000000',
  'admin',
  'manual',
  1,
  NOW()
);

COMMIT;
