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
  `email_verified`       tinyint(1)   NOT NULL DEFAULT 0 COMMENT '1 = address proven by clicking the emailed link',
  `verification_token`   varchar(64)  DEFAULT NULL COMMENT 'Single-use signup token. NULL once redeemed.',
  `verification_sent_at` datetime     DEFAULT NULL COMMENT 'When the current token was issued — drives expiry and resend throttling',
  `email_verified_at`    datetime     DEFAULT NULL,
  `last_login_at`   datetime      DEFAULT NULL,
  `created_at`      datetime      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      datetime      DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_verify_token` (`verification_token`),

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

-- ── password_resets ───────────────────────────────────────────────────────────
-- One row per password-reset request. Two-phase: the emailed 6-digit code
-- proves mailbox control, then redeeming it issues a separate reset_token that
-- authorises the password change itself.
--
-- ADDED 2026-08-22 with the users.email_verified columns above. Before that,
-- pages/forgot-password.php was a pure client-side mock — no table, no handler,
-- no email, and it showed a success screen without changing anything.
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`           int(11)      NOT NULL AUTO_INCREMENT,
  `email`        varchar(200) NOT NULL,
  `code_hash`    varchar(255) NOT NULL COMMENT 'password_hash() of the 6-digit code — never stored in the clear',
  `reset_token`  varchar(64)  DEFAULT NULL COMMENT 'Issued only after the code verifies; authorises the change',
  `attempts`     tinyint(4)   NOT NULL DEFAULT 0 COMMENT 'Wrong-code tries; row dies at 5',
  `expires_at`   datetime     NOT NULL,
  `used_at`      datetime     DEFAULT NULL,
  `requested_ip` varchar(45)  DEFAULT NULL,
  `created_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_email`   (`email`),
  KEY `idx_token`   (`reset_token`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── notifications ─────────────────────────────────────────────────────────────
-- In-app notification feed. Written by ms_notify() in auth/service_bootstrap.php
-- and by auth/escalate_detections.php; read by auth/get_notifications.php.
--
-- ADDED TO THIS FILE 2026-08-22. It existed only inside
-- services/migration_workflow_audit.sql, so a database built from this schema
-- alone had no notifications table at all — and migration_notifications.sql
-- would then ALTER a table that was never created. Definition below is the
-- full current shape (base + the claim_id/action_url columns that migration
-- adds), so schema.sql is correct on its own and both migrations become
-- harmless no-ops on a fresh install.
CREATE TABLE IF NOT EXISTS `notifications` (
  `notification_id` int(11)      NOT NULL AUTO_INCREMENT,
  `user_id`         int(11)      NOT NULL COMMENT 'Recipient — from users table',
  `type`            varchar(60)  NOT NULL COMMENT 'e.g. potential_lost, confirmed_missing, item_recovered, new_claim, claim_completed',
  `title`           varchar(300) NOT NULL,
  `body`            text         NOT NULL,
  `detection_id`    int(11)      DEFAULT NULL COMMENT 'Linked detection from spotit_monitor_db (denormalized)',
  `claim_id`        int(11)      DEFAULT NULL COMMENT 'Linked claim from spotit_lf_db.claims (denormalized)',
  `action_url`      varchar(300) DEFAULT NULL COMMENT 'Relative URL the user should navigate to on click',
  `room_id`         varchar(20)  DEFAULT NULL,
  `is_read`         tinyint(1)   NOT NULL DEFAULT 0,
  `read_at`         datetime     DEFAULT NULL,
  `created_at`      datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`notification_id`),
  KEY `idx_user_unread` (`user_id`, `is_read`),
  KEY `idx_user`        (`user_id`),
  KEY `idx_type`        (`type`),
  KEY `idx_created`     (`created_at`),
  KEY `idx_detection`   (`detection_id`),
  KEY `idx_claim`       (`claim_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── user_activity ─────────────────────────────────────────────────────────────
-- Per-user "last seen" timestamps, used for the unread badges on announcements
-- and the forum.
--
-- ADDED TO THIS FILE 2026-08-22. This table appeared in NO .sql file anywhere in
-- the repo — it was created by hand and existed only on the dev laptop. Same
-- failure class as rooms.camera_* and accuracy_trials (BUGS.md §1).
CREATE TABLE IF NOT EXISTS `user_activity` (
  `id`                        int(11)  NOT NULL AUTO_INCREMENT,
  `user_id`                   int(11)  NOT NULL,
  `last_viewed_announcements` datetime DEFAULT NULL,
  `last_viewed_forum`         datetime DEFAULT NULL,
  `last_login`                datetime DEFAULT NULL,
  `updated_at`                datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Bootstrap admin seed ──────────────────────────────────────────────────────
-- WARNING: the hash below is a PLACEHOLDER, not a real bcrypt digest. It can
-- never match any password — password_verify() will always return false — so
-- this row exists to reserve the account, not to be logged into. An older
-- comment here claimed the password was "Admin@2026!"; it never was.
--
-- Admins are never self-signup (CLAUDE.md §2), so after importing this schema
-- replace the hash with a real one before you can log in at all:
--
--   php -r "echo password_hash('YourStrongPassword', PASSWORD_BCRYPT).PHP_EOL;"
--   UPDATE users SET password_hash = '<paste>' WHERE email = 'admin@dlsud.edu.ph';
--
-- Full walkthrough: md_files/DEPLOYMENT_GUIDE.md §4.
-- Change password immediately after first login.
-- email_verified = 1: the bootstrap admin has no mailbox to click a link from,
-- and gating it on verification would make a fresh install unusable.
INSERT INTO `users` (`full_name`, `email`, `id_number`, `password_hash`, `role`, `auth_provider`, `is_active`, `email_verified`, `email_verified_at`, `created_at`)
VALUES (
  'System Administrator',
  'admin@dlsud.edu.ph',
  '0000-00000',
  '$2y$12$exampleHashChangeMeImmediately000000000000000000000000',
  'admin',
  'manual',
  1,
  1,
  NOW(),
  NOW()
);

COMMIT;
