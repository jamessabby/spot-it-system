# S.P.O.T.-IT — Prototype Plan (Verified Against Live Codebase, 2026-07-14)

This replaces the old "Claudee Plan" PDF. That plan was written early (pre-pivot,
before most of this existed). Everything below was checked directly against your
`spotit.zip` — file by file, not just from CLAUDE.md/BUGS.md descriptions — so
some of it will contradict even your own `UPDATES.md` checkboxes, which are
stale in a few places (they say "not started" for things that are actually built).

---

## 1. The real picture in one paragraph

You have a working three-layer prototype: a Python/OpenCV detection engine with
a tier-aware pipeline, a MobileNetV2 presence gatekeeper, dual-snapshot evidence
capture, and a flood-gate safety cutoff — POSTing over an API-key-authenticated
endpoint into a 5-database PHP/MySQL backend with a cron-based escalation timer
— surfaced across ~27 role-based dashboard pages. What's **not** done is almost
entirely physical/logistical, not code: real Dahua cameras, real CEAT room data,
a working web-based recalibration UI, and the S1–S8 test runs. That's a very
different risk profile than "half-built system," and the plan below is built
around that.

---

## 2. Layer-by-layer status (verified, not assumed)

### Layer 1 — Camera / Detection Engine (`main.py`, Python + OpenCV)

**Built and present in code right now:**
- Scene-stability gate, ROI pixel-diff threshold, tolerance-zone `cv2.matchTemplate`
  search — the classical pipeline.
- **Tier-aware branching is implemented** (`get_roi_tier()` at line 328): Tier 1
  fixed lab equipment skips template-match "moved" state and goes straight to
  the MobileNetV2 presence gate; other tiers keep template matching. This was
  flagged "not yet implemented" in your own UPDATES.md — it's actually done.
- **MobileNetV2 DNN gatekeeper is implemented** (`validate_presence_dnn()`,
  lines 194+): downloads the ONNX model + ImageNet labels automatically if
  missing, runs inference on the flagged crop, fails **open** (assumes present)
  if the DNN is unavailable — a sensible default for a presence gate.
- **Dual-snapshot evidence trail is implemented**: Snapshot A fires on
  confirmed-missing; Snapshot B logic (lines ~598+) continues watching a
  RED-state ROI and captures a second frame + POSTs it via
  `action=snapshot_b` to `ingest_detection.php` if the region changes again.
- **Auto-flood gate is implemented** (line ~589) — but tuned differently than
  CLAUDE.md describes: code triggers at **>75% of ROIs missing within 60s,
  and only if the room has ≥4 registered ROIs**, not the ">50%" your own docs
  say. Not wrong, just undocumented drift — update CLAUDE.md §3c to match, or
  change the code, your call, but right now the paper/docs and code disagree
  with each other on this number.
- `main_classical_only.py` exists (316 lines) as the stripped A/B baseline for
  your Chapter 4 accuracy comparison, per plan.

**Not done / still fake:**
- `RTSP_URL` and `ROOM_ID = 'DESK'` in `main.py` are still your personal Tapo
  camera at a desk (`rois.json` currently holds `keyboard`, `cup`, `mouse` —
  not lab equipment). No Dahua camera, no CEAT room, no lab-item ROIs exist
  yet anywhere in the system.
- `API_KEY` in `main.py` is still the literal placeholder string
  `'CHANGE_ME_DETECTION_KEY'` — check whether `config/env.php`'s
  `SPOTIT_DETECTION_KEY` has actually been changed from its own placeholder
  default too, or your ingestion auth is currently a no-op security-wise.

### Layer 2 — Backend API (PHP + PDO, not Node/Express)

**Built:**
- `auth/ingest_detection.php` — API-key authenticated (`hash_equals()`),
  accepts `roi_change_pct` and `match_score`, computes a **0–100 composite
  confidence score** (match-score contributes up to 50pts, change-% up to
  30pts, plus a deviation component), assigns a `confidence_grade`, and
  auto-accepts (≥85) or flags `needs_review` (<30). This is more sophisticated
  than what either the old plan or your paper currently describes.
- `auth/escalate_detections.php` — a real cron handler (CLI or HTTP with
  `SPOTIT_CRON_KEY`), does the pending→potential (`TIMER_POTENTIAL_MIN`=30min)
  and potential→confirmed_missing (`TIMER_CONFIRMED_MIN`=60min) escalation
  passes against `config/env.php` thresholds.
- 28 handlers total in `auth/` covering detections, claims, forum, auth
  (including Microsoft OAuth), notifications, announcements, tour status.

**Not verified / likely gap:**
- Nothing in the repo confirms `escalate_detections.php` is actually scheduled
  anywhere (XAMPP cron, Windows Task Scheduler, or a hosted cron on
  Hostinger). A cron handler that exists but was never registered is silent
  — nothing errors, it just never runs. Verify this before you rely on
  auto-escalation in a demo.
- No `auth/save_rois.php` or `auth/capture_frame.php` exist — the Phase 3
  web-based recalibration tool (Canvas UI) has no backend yet.

### Layer 3 — Database (5 microservices, not 3)

`spotit_auth_db`, `spotit_monitor_db`, `spotit_lf_db`, `spotit_user_db`, and
`spotit_community_db` (forum/announcements) — five, not the three originally
scoped. `rooms` and `registered_lab_items` schemas already support multi-tier,
multi-camera-per-room, tolerance zones — the schema is ready for real room
data, it just doesn't have any yet (only `TESTROOM`/`DESK` rows exist, per
your `uploads/snapshots/` filenames).

### Layer 4 — Website / Dashboard (PHP pages)

27 pages exist, including all three role dashboards (student/staff/admin,
already rewritten to poll live data instead of hardcoded arrays per your
changelog), `room-monitor.php`, `claiming-station.php`, `lost-thread.php`,
`alerts.php` (the one we just built and wired into `_sidebar.php`),
`inventory-monitor.php`, `admin-audit.php`, `analytics.php`,
`surrender-log.php`, `system-logs.php`, `user-management.php`, plus the forum
and announcements pages tied to the 5th database.

`room-setup.php` exists but is a **plain room list/settings view** (queries
`rooms` and renders a table) — it is not the HTML5 Canvas ROI-drawing tool
described in CLAUDE.md §3c. That tool (Phase 3 of your own roadmap) hasn't
been started: no canvas code, no `save_rois.php`/`capture_frame.php` backend.
ROI registration today still means running `register_roi.py` locally and
hand-editing `rois.json`.

---

## 3. What's actually still missing, ranked by what blocks a working demo

1. **Cron verification** — confirm `escalate_detections.php` is actually
   scheduled. Zero code changes needed, just server configuration + a test.
2. **Real camera + real ROI data** — even a single Dahua camera (or continued
   Tapo stand-in) pointed at an actual CEAT room, with `rois.json`/
   `registered_lab_items` populated for real lab equipment instead of
   keyboard/cup/mouse on your desk. This is deployment work, not coding.
3. **API key hygiene** — `main.py`'s `API_KEY` and `env.php`'s
   `SPOTIT_DETECTION_KEY` need to actually match a real generated secret, not
   the shared placeholder string, before this goes anywhere off localhost.
4. **Web-based recalibration tool (Phase 3)** — genuinely unbuilt: needs
   `auth/capture_frame.php`, `auth/save_rois.php`, and the Canvas UI in
   `room-setup.php` or a new modal. This is the single largest remaining
   *coding* gap in the whole system.
5. **Flood-gate threshold reconciliation** — code says 75%/≥4 items, docs say
   50%. Pick one, update the other.
6. **S1–S8 controlled test runs + Likert questionnaire** — not code, can't be
   sprinted, needs the real room + scheduled time with people.

---

## 4. Suggested sequencing

**Can be done today, at a desk, no new hardware:**
- Verify the end-to-end chain fires live: `main.py` → `ingest_detection.php`
  → dashboard/`alerts.php` updates, including forcing an escalation by
  temporarily lowering `TIMER_POTENTIAL_MIN`/`TIMER_CONFIRMED_MIN` to test
  without waiting an hour.
- Confirm or set up the `escalate_detections.php` cron schedule.
- Generate and set a real `SPOTIT_DETECTION_KEY` / `SPOTIT_CRON_KEY` in both
  `env.php` and `main.py`/the cron trigger.
- Reconcile the flood-gate 50% vs 75% mismatch (one file edit either way).

**Needs a decision, then is codeable this week:**
- Build the Phase 3 Canvas recalibration tool — this is real, scoped,
  greenfield work with no ambiguity about what exists already.

**Needs the physical room / hardware, can't be coded around:**
- Procure/borrow the Dahua camera (or continue with Tapo as the documented
  stand-in) and get it pointed at an actual CEAT lab.
- Populate `rooms`/`registered_lab_items` with real equipment and re-run
  `register_roi.py` (or the new Canvas tool once built) on that live feed.
- Re-tune `THRESHOLD`/`SCENE_MOTION_LIMIT`/`MATCH_SCORE_THRESHOLD` against the
  real camera's lighting/compression characteristics.

**Needs lead time and other people, schedule now, don't cram:**
- S1–S8 scenario trials (≥5 each) in the real room, logged as TP/FP/FN/TN.
- Likert usability questionnaire to 30–50 respondents.
- Expert/IT-evaluator sign-off.

---

## 5. Quick file-to-function map (for your own reference while studying the codebase)

| What | File |
|---|---|
| Detection loop, tier logic, MobileNetV2 gate, flood gate, snapshot A/B | `main.py` |
| A/B accuracy baseline (no MobileNetV2) | `main_classical_only.py` |
| Confidence scoring, auto-accept/needs-review | `auth/ingest_detection.php` |
| Escalation cron (30/60 min timers) | `auth/escalate_detections.php` |
| Shared DB bootstrap + cross-service helpers | `auth/service_bootstrap.php` |
| Live event feed for dashboards | `auth/get_detections.php` |
| Room list/settings (not the Canvas tool) | `pages/room-setup.php` |
| Newest page, alerts feed | `pages/alerts.php` + `assets/css/alerts.css` |
| Timer thresholds, DB creds, API key placeholder | `config/env.php` |
| Current (desk-test) ROI zones | `rois.json` |

---

Want me to start on the Phase 3 Canvas recalibration tool next — that's the
one piece here that's a clean, well-scoped coding task with nothing physical
blocking it?
