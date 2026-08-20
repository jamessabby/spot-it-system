-- ============================================================
-- S.P.O.T.-IT — Claims Soft-Archive Migration
-- File: services/migration_claims_archive.sql
--
-- Adds 'archived' to claims.status and an archived_at timestamp.
--
-- WHY: process_donation.php used to DELETE claim rows during the end-of-year
-- purge. That destroyed the only record of who collected which item — the
-- handoff evidence the claiming flow exists to create (CLAUDE.md §3c) — in
-- order to delete the selfie attached to it.
--
-- Those are two different things. The photograph is personal data with a
-- retention limit; the fact that a claim happened is an audit record the lab
-- needs to keep. So the purge now nulls webcam_snapshot, deletes the image file
-- from disk, and marks the row 'archived' instead of removing it.
--
-- NOTE ON enum: MariaDB has no "ADD VALUE", so status is re-declared in full.
-- Re-running this is harmless — MODIFY to an identical definition is a no-op.
--
-- Run order: after the lostfound base schema. Independent of the other migrations.
-- ============================================================

USE `spotit_lf_db`;

-- 'archived' = the claim happened and is on record, but its imagery has been
-- purged under the retention policy. Distinct from 'rejected', which means the
-- claim was refused.
ALTER TABLE `claims`
  MODIFY COLUMN `status`
    enum('pending','verified','claimed','rejected','archived')
    NOT NULL DEFAULT 'pending';

-- When the retention purge ran. NULL for every live claim.
ALTER TABLE `claims`
  ADD COLUMN IF NOT EXISTS `archived_at` datetime DEFAULT NULL
    COMMENT 'When the end-of-year donation purge archived this claim and removed its photo'
  AFTER `claimed_at`;

ALTER TABLE `claims`
  ADD INDEX IF NOT EXISTS `idx_archived` (`archived_at`);
