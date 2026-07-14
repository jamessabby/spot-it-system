-- ============================================================
-- S.P.O.T.-IT — Confidence Score & Validation Status Migration
-- File: services/migration_confidence.sql
--
-- Adds confidence scoring and validation workflow columns to the
-- detections table in spotit_monitor_db.
--
-- Run AFTER migration_workflow_audit.sql.
-- ============================================================

USE `spotit_monitor_db`;

-- ── 1. confidence_score ────────────────────────────────────────────────────────
-- Composite 0–100 score computed by ingest_detection.php from:
--   a) roi_change_pct  — how much pixel area changed in the ROI
--   b) match_score     — 0.0–1.0 template match similarity (1.0 = identical to baseline)
--   c) deviation magnitude — absolute count difference vs baseline
-- Stored as TINYINT UNSIGNED (0–100). NULL = not yet computed (legacy rows).
ALTER TABLE `detections`
  ADD COLUMN IF NOT EXISTS `confidence_score` tinyint(3) UNSIGNED DEFAULT NULL
    COMMENT 'Composite detection confidence 0-100. Computed at ingest time.'
  AFTER `match_score`;

-- ── 2. confidence_grade ────────────────────────────────────────────────────────
-- Human-readable band derived from confidence_score.
--   HIGH   = 85–100   (very clear deviation, high certainty)
--   MEDIUM = 60–84    (likely deviation, verify recommended)
--   LOW    = 30–59    (weak signal, possible false positive)
--   NOISE  = 0–29     (almost certainly a false positive)
ALTER TABLE `detections`
  ADD COLUMN IF NOT EXISTS `confidence_grade`
    ENUM('HIGH','MEDIUM','LOW','NOISE') DEFAULT NULL
    COMMENT 'Human-readable band from confidence_score.'
  AFTER `confidence_score`;

-- ── 3. confidence_factors ─────────────────────────────────────────────────────
-- JSON breakdown of individual signal contributions for audit/display.
-- Example: {"roi_pct": 72.4, "match": 0.31, "deviation": -2, "weights": {...}}
ALTER TABLE `detections`
  ADD COLUMN IF NOT EXISTS `confidence_factors` json DEFAULT NULL
    COMMENT 'JSON: individual signal values used to compute confidence_score.'
  AFTER `confidence_grade`;

-- ── 4. validation_status ──────────────────────────────────────────────────────
-- Tracks the human-in-the-loop verification workflow independently of `status`.
-- `status` tracks WHERE the item is (missing / recovered / etc.)
-- `validation_status` tracks WHETHER a human has checked the detection itself.
--
--   pending_review  — new detection, no human has looked yet
--   auto_accepted   — system confidence >= 85, auto-accepted without human review
--   verified        — staff physically inspected and confirmed the detection is real
--   rejected        — staff determined this was a false positive
--   needs_review    — low-confidence detection flagged for mandatory human review
ALTER TABLE `detections`
  ADD COLUMN IF NOT EXISTS `validation_status`
    ENUM(
      'pending_review',
      'auto_accepted',
      'verified',
      'rejected',
      'needs_review'
    ) NOT NULL DEFAULT 'pending_review'
    COMMENT 'Human-in-the-loop verification state, independent of item status.'
  AFTER `confidence_factors`;

-- ── 5. validated_by / validated_at ────────────────────────────────────────────
-- Records WHICH staff member validated and WHEN.
ALTER TABLE `detections`
  ADD COLUMN IF NOT EXISTS `validated_by` int(11) DEFAULT NULL
    COMMENT 'user_id of staff who validated (NULL if auto_accepted)'
  AFTER `validation_status`;

ALTER TABLE `detections`
  ADD COLUMN IF NOT EXISTS `validated_at` datetime DEFAULT NULL
    COMMENT 'Timestamp of human validation action'
  AFTER `validated_by`;

-- ── 6. Indexes ─────────────────────────────────────────────────────────────────
ALTER TABLE `detections`
  ADD INDEX IF NOT EXISTS `idx_confidence`        (`confidence_score`),
  ADD INDEX IF NOT EXISTS `idx_confidence_grade`  (`confidence_grade`),
  ADD INDEX IF NOT EXISTS `idx_validation_status` (`validation_status`);

-- ── 7. Backfill existing rows with a default confidence_score ─────────────────
-- Existing rows have match_score and roi_change_pct already. Compute a
-- reasonable confidence from them so legacy records are not NULL.
UPDATE `detections`
SET
  confidence_score = LEAST(100, GREATEST(0, ROUND(
    -- Weight A: template match (contributes up to 50 pts; inverted — lower match means more change)
    ((1 - COALESCE(match_score, 0.5)) * 50)
    -- Weight B: roi_change_pct (contributes up to 30 pts; capped at 100%)
    + (LEAST(COALESCE(roi_change_pct, 0), 100) / 100 * 30)
    -- Weight C: deviation magnitude (contributes up to 20 pts; each missing item = 10 pts, max 2)
    + (LEAST(ABS(COALESCE(live_count - baseline_count, 0)), 2) * 10)
  ))),
  confidence_grade = CASE
    WHEN LEAST(100, GREATEST(0, ROUND(
      ((1 - COALESCE(match_score, 0.5)) * 50)
      + (LEAST(COALESCE(roi_change_pct, 0), 100) / 100 * 30)
      + (LEAST(ABS(COALESCE(live_count - baseline_count, 0)), 2) * 10)
    ))) >= 85 THEN 'HIGH'
    WHEN LEAST(100, GREATEST(0, ROUND(
      ((1 - COALESCE(match_score, 0.5)) * 50)
      + (LEAST(COALESCE(roi_change_pct, 0), 100) / 100 * 30)
      + (LEAST(ABS(COALESCE(live_count - baseline_count, 0)), 2) * 10)
    ))) >= 60 THEN 'MEDIUM'
    WHEN LEAST(100, GREATEST(0, ROUND(
      ((1 - COALESCE(match_score, 0.5)) * 50)
      + (LEAST(COALESCE(roi_change_pct, 0), 100) / 100 * 30)
      + (LEAST(ABS(COALESCE(live_count - baseline_count, 0)), 2) * 10)
    ))) >= 30 THEN 'LOW'
    ELSE 'NOISE'
  END,
  validation_status = CASE
    WHEN LEAST(100, GREATEST(0, ROUND(
      ((1 - COALESCE(match_score, 0.5)) * 50)
      + (LEAST(COALESCE(roi_change_pct, 0), 100) / 100 * 30)
      + (LEAST(ABS(COALESCE(live_count - baseline_count, 0)), 2) * 10)
    ))) >= 85 THEN 'auto_accepted'
    WHEN status IN ('dismissed') THEN 'rejected'
    WHEN verified_by IS NOT NULL THEN 'verified'
    ELSE 'pending_review'
  END
WHERE confidence_score IS NULL;
