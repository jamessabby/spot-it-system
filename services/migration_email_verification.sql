-- ============================================================
-- S.P.O.T.-IT — Email Verification & Password Reset Migration
-- File: services/migration_email_verification.sql
--
-- Adds signup email verification to spotit_auth_db.users, and creates the
-- password_resets table that the reset flow needs.
--
-- WHY: before 2026-08-22 an account was active and loginable the instant it was
-- created, with no proof the person owned the address — and password reset was
-- a client-side mock that never sent anything, never stored anything, and told
-- the user their password had been changed when it had not. There was no
-- password_resets table anywhere in the repo, and no code had ever sent mail.
--
-- Run order: after the auth base schema. Independent of the others.
-- Safe to re-run.
-- ============================================================

USE `spotit_auth_db`;

-- ── users: verification state ────────────────────────────────────────────────

-- Existing accounts are grandfathered as verified by the DEFAULT below, then
-- the backfill at the bottom makes that explicit. Flipping real users to
-- unverified would lock out the admin running this migration.
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `email_verified` tinyint(1) NOT NULL DEFAULT 0
    COMMENT '1 = the address was proven by clicking the emailed link'
  AFTER `is_active`;

-- Nullable and single-use: cleared the moment it is redeemed, so a link that
-- leaks from a mailbox later cannot be replayed.
ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `verification_token` varchar(64) DEFAULT NULL
    COMMENT 'Single-use signup token. NULL once redeemed.'
  AFTER `email_verified`;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `verification_sent_at` datetime DEFAULT NULL
    COMMENT 'When the current token was issued — drives expiry and resend throttling'
  AFTER `verification_token`;

ALTER TABLE `users`
  ADD COLUMN IF NOT EXISTS `email_verified_at` datetime DEFAULT NULL
  AFTER `verification_sent_at`;

ALTER TABLE `users`
  ADD INDEX IF NOT EXISTS `idx_verify_token` (`verification_token`);

-- ── password_resets ──────────────────────────────────────────────────────────
-- One row per reset request.
--
-- Two-phase on purpose, matching the existing 4-step UI: the emailed 6-digit
-- code proves mailbox control, and redeeming it issues a separate random
-- reset_token that authorises the actual password change. Without that second
-- phase the final step would have to re-send the code, so the code would end up
-- in the browser's request log twice and stay valid across both.
CREATE TABLE IF NOT EXISTS `password_resets` (
  `id`           int(11)      NOT NULL AUTO_INCREMENT,
  `email`        varchar(200) NOT NULL,

  -- Hashed, never stored in the clear: a leaked DB should not hand out live
  -- reset codes for every pending request.
  `code_hash`    varchar(255) NOT NULL COMMENT 'password_hash() of the 6-digit code',

  -- Issued only after the code is verified. NULL until then.
  `reset_token`  varchar(64)  DEFAULT NULL COMMENT 'Authorises the password change itself',

  `attempts`     tinyint(4)   NOT NULL DEFAULT 0 COMMENT 'Wrong-code tries; row dies at 5',
  `expires_at`   datetime     NOT NULL,
  `used_at`      datetime     DEFAULT NULL COMMENT 'Set when the password is actually changed',
  `requested_ip` varchar(45)  DEFAULT NULL,
  `created_at`   datetime     NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  KEY `idx_email`   (`email`),
  KEY `idx_token`   (`reset_token`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Backfill ─────────────────────────────────────────────────────────────────
-- Everyone who already had an account keeps their access. Only accounts created
-- after this migration have to verify.
UPDATE `users`
   SET `email_verified` = 1,
       `email_verified_at` = COALESCE(`email_verified_at`, `created_at`)
 WHERE `email_verified` = 0;
