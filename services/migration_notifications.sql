-- ============================================================
-- S.P.O.T.-IT — Notification System Enhancement Migration
-- File: services/migration_notifications.sql
--
-- Adds claim_id and action_url to notifications table so every
-- notification can link directly to the relevant page/record.
-- Run after migration_workflow_audit.sql.
-- ============================================================

USE `spotit_auth_db`;

-- Link to a specific claim (for claim_approved / claim_rejected notifications)
ALTER TABLE `notifications`
  ADD COLUMN IF NOT EXISTS `claim_id` int(11) DEFAULT NULL
    COMMENT 'Linked claim from spotit_lf_db.claims (denormalized)'
  AFTER `detection_id`;

-- Deep-link URL so the front-end can route directly to the relevant page
ALTER TABLE `notifications`
  ADD COLUMN IF NOT EXISTS `action_url` varchar(300) DEFAULT NULL
    COMMENT 'Relative URL the user should navigate to on click'
  AFTER `claim_id`;

ALTER TABLE `notifications`
  ADD INDEX IF NOT EXISTS `idx_claim` (`claim_id`);
