# S.P.O.T.-IT — Roadmap & Change Log

This file acts as a living checklist of completed and upcoming tasks, along with a historical log of decisions and updates. Refer to [CLAUDE.md](file:///c:/xampp/htdocs/spotit/CLAUDE.md) for the core project architecture, conventions, and behavior rules.

---

## 1. Full Roadmap — start to finish of the thesis

Phases are sequenced by dependency, not calendar time — treat this as a checklist. Update checkboxes and notes here as things are completed.

### Phase 1 — Backend correctness (do first, everything else depends on this)
- [x] Fix `ingest_detection.php` `ms_detection_stage()` bug (§4.1 in CLAUDE.md) — moved fn into `services/monitoring/db.php` with `function_exists` guard; bootstrap also guarded so no redeclaration fatal on shared request paths.
- [x] Seed one real admin account via SQL (§4.4 in CLAUDE.md) — bcrypt hash generated via `php -r "echo password_hash(...)"` on the XAMPP PHP CLI; SQL provided in CLAUDE.md comment block.
- [x] `dashboard-staff.php` fully rewritten — hardcoded `$queue`/`$resolved`/`$myRooms` arrays replaced with live JS polling to `get_detections.php` every 10s. Stat cards, filter tabs, and Verify/Dismiss buttons all live.
- [x] `dashboard-admin.php` fully rewritten — all hardcoded `$events`/`$alerts`/`$ok_rooms`/`$tl` arrays replaced with live JS polling. Room table, event log, alerts feed, timeline all live.
- [x] `dashboard-student.php` fully rewritten — `$claims`/`$recent` replaced with live fetch to new `get_claims.php` and `get_recovered_items.php`. Two new read endpoints created to support this.
- [x] `update_event_status.php` end-to-end wired — staff/admin dashboards now POST to it from Verify/Dismiss/Recover/Confirm buttons with notes. `room_id` added to `monitoring_logs` INSERT (was missing). UI refreshes on success.

### Phase 2 — Detection pipeline correctness

#### Step 2.1: Python Detection Engine Upgrades (`main.py`)
- [x] Add MobileNetV2 / DNN loader to `main.py` using OpenCV's DNN module
- [x] Implement the **Tier-Aware Pipeline logic** in the frame processing loop:
  - **Tier 1 (Fixed Assets):** If change detected, crop and send to MobileNetV2 presence validation. Skip template matching.
  - **Tier 2/3/4 (Personal Items):** Use standard background subtraction + template matching (1.5x → 2x → full frame search).
- [x] Add **Snapshot B (Re-detection/removal) logic**:
  - Once a target enters RED/MISSING, continue monitoring its ROI pixels.
  - If a sudden frame delta (hand reaching in or object shifting again) is detected, capture a second snapshot (Snapshot B) and upload it to the DB as evidence of who interacted with the item.
- [x] Implement the **Auto-Flood Gate protection**:
  - Track count of items that enter MISSING state in the room.
  - If >50% of the room's registered ROIs trigger MISSING within 60s, automatically toggle an `is_monitoring_paused` flag to `true` and log a single mass-rearrangement alert.

#### Step 2.2: Live Comparison Engine (A/B testing)
- [x] Create `main_classical_only.py` by duplicating `main.py` and stripping out the MobileNetV2 presence validation blocks.
- [x] Verify both scripts read successfully from the same `rois.json` and local camera RTSP feed.
- [x] Setup a testing spreadsheet (or SQL table) to log accuracy differences (TP, FP, FN, TN) between both scripts under various test conditions (lighting shift, slight nudge, actual theft).

#### Step 2.3: Ingestion & Backend Updates
- [x] Update `auth/ingest_detection.php` to accept and process the two separate snapshots (Snapshot A: Empty frame, Snapshot B: Retaking frame).
- [x] Add `is_monitoring_paused` status and mass-alert logging endpoints to `services/monitoring/db.php`.

### Phase 3 — Web-Based Recalibration Tool (Canvas UI)

#### Step 3.1: Backend Calibration API
- [x] Create `auth/capture_frame.php` to request/retrieve a fresh, rotated, and scaled frame from the room's camera stream, saving it temporarily for drawing.
- [x] Create `auth/save_rois.php` to accept a JSON payload of drawn coordinates and update `rois.json` (or the database).

#### Step 3.2: HTML5 Canvas Annotation Interface
- [x] Add a "Recalibrate Room" modal/page to the Admin Dashboard.
- [x] Build the interactive HTML5 Canvas element:
  - Load the captured fresh camera snapshot as the canvas background.
  - Add JS event listeners to allow drawing rectangular bounding boxes with click-and-drag.
  - Allow labeling boxes (e.g., `computer1`, `object1`).
- [x] Add a "Save Config" button that sends the drawn ROI boxes back to `auth/save_rois.php`, clears the old alerts for that room, and triggers `main.py` to reload its configuration.

### Phase 3.5 — Real room + item data
- [ ] Insert real `rooms` + `registered_lab_items` records for the CEAT rooms actually being tested (see thesis Table 3.2 for list)
- [ ] Physically deploy the prototype IP camera stream inside the laboratory test environment
- [ ] Perform live ROI calibration on the final CCTV stream using the newly built Canvas dashboard tool
- [ ] Re-tune `THRESHOLD`, `SCENE_MOTION_LIMIT`, and `MATCH_SCORE_THRESHOLD` on the physical Tapo TC65 camera stream (due to changes in lighting/compression)

### Phase 4 — Controlled testing (thesis §3.5, Table 3.5)
Run each scenario ≥5 trials per the methodology, recording results as you go:
- [ ] **S1 — Unattended Item Persistence**: item intentionally left → expect notification event generated
- [ ] **S2 — Minor Object Movement**: item slightly moved within ROI tolerance → expect no notification
- [ ] **S3 — Continuous Room Activity**: multiple people in room → expect monitoring temporarily pauses (scene-stability gate working)
- [ ] **S4 — Temporary Object Obstruction**: item briefly covered/blocked → expect no immediate false alert
- [ ] **S5 — Item Retrieval and Claiming**: detected item goes through claiming verification → expect claim record stored in DB
- [ ] **S6 — Dashboard Notification Test**: valid event → expect it appears on dashboard
- [ ] **S7 — Flood Gate Verification**: simulate a mass rearrangement → confirm the system automatically pauses and doesn't crash the database
- [ ] **S8 — A/B Comparison run**: run the same set of trials with `main_classical_only.py` vs `main.py` and record the accuracy results (TP / FP / FN / TN) for the thesis paper
- [ ] For each trial, record classification as **TP / FP / FN / TN** per thesis §3.5.2 (Accuracy Test) — this data is the core evidence for the accuracy/functionality chapter, keep it organized (spreadsheet or DB table) as you go rather than reconstructing it later

### Phase 5 — Usability & expert evaluation
- [ ] Distribute the Likert-scale questionnaire (thesis §3.9 has the exact sample items — dashboard usability, notification clarity, claiming process usability, overall usefulness) to ~30–50 respondents (students, lab staff, IT-related evaluators) via convenience sampling
- [ ] Run the expert evaluation with IT faculty/advisers using a structured form covering functionality, notification reliability, visual monitoring capability, dashboard usability, practicality (thesis §3.5.4)
- [ ] Compile descriptive statistics (frequency counts, percentages, mean scores) from both — this becomes Chapter 4 results

### Phase 6 — Defense prep
- [ ] Make sure the live system can be demoed end-to-end without manual workarounds (real camera or convincing desk-test stand-in → detection → dashboard → claiming)
- [ ] Prepare answers grounded in CLAUDE.md/UPDATES.md for the likely hard questions: "how does it tell apart items," "why ML only as a gatekeeper," "what happens when housekeepers move equipment," "why microservices instead of one database" — all already answered in CLAUDE.md §3a/§8
- [ ] Reconcile anything advisers/panelists changed along the way — update CLAUDE.md/UPDATES.md relevant sections and the actual thesis document (Chapter 3 methodology text) to match what was actually built, not what was originally planned, if they diverged

---

## 2. Change Log

Keep this short — one line per meaningful change to project direction, newest first.

- **2026-07-23** — Transitioned S.P.O.T.-IT to Production Level Multi-Room Camera Monitoring. Added Speed Mode Toggle (**Testing Speed 3s/6s** vs **Production Speed 30m/1hr**) on `pages/room-monitor.php`. Enabled dual simultaneous tracking (Registered assets + Unregistered left items) in `main.py` when Production Mode is active. Created `auth/get_room_status.php` API endpoint for multi-room polling, updated `pages/alerts.php` to display alerts across all active user-created rooms, and aggregated `pages/dashboard-admin.php` stats across all monitored rooms.
- **2026-07-22** — Fixed per-label state dictionary initialization in `main.py` on ROI reloads to prevent `KeyError`. Implemented `unreg_absence_count` state cleanup when left items are removed from the desk (>10 frames absence), ensuring replaced objects start timer counting cleanly from 0.0s. Wired `auth/update_event_status.php` to signal `detection_mode.json` on status recovery/dismissal, syncing Python memory in real-time. Created interactive testing/presentation guide artifact `sandbox_demo_guide.md`.
- **2026-07-21** — Marked all Phase 2 tasks as complete: MobileNetV2 Tier-Aware logic, Snapshot B logic, and Auto-Flood Gate protection are fully implemented and verified in the live `main.py` pipeline.
- **2026-07-20** — Implemented Left Items (Unregistered Mode) sandbox engine and UI features. Fixed video Full Screen sizing using CSS object-fit contain. Replaced frame-based loop iteration consistency checking with real-world clock time tracking (`time.time()`) for 3.0s Yellow (potential) and 6.0s Red (confirmed_missing) escalation. Added a secondary MobileNetV2 / computer vision texture and edge density gatekeeper to reject shadow false positives. Rewrote Snapshot B triggers to fire reliably upon physical object removal or hand interaction outside the main contour loop. Added JS `resetSystemState` handler to resolve sandbox master resets.
- **2026-07-15** — Resolved live feed flicker by implementing atomic image replacement (`save_frames_atomic` in `main.py`). Eliminated duplicate notification flood by fixing the status matching logic in `ingest_detection.php` duplicate queries and gating user notifications on `$action === 'inserted'`. Implemented dynamic Testing/Production mode toggle dropdown on the Desk Sandbox dashboard and hot-reloaded values dynamically inside `main.py`.
- **2026-07-04** — Split roadmap and changelog into a separate `UPDATES.md` file to keep `CLAUDE.md` lightweight.
- **2026-07-04** — Added §3c: finalized scope/behavior decisions from team discussion.
  Documented: no cross-room tracking (single-camera-frame only), item labeling convention (registered = named, unregistered = auto `objectN`), claiming flow (owner clicks + selfie), dual-snapshot evidence trail (Snapshot A = empty ROI, Snapshot B = who took it), storage plan (Hostinger Cloud Startup 100GB NVMe, compressed snapshots, no auto-delete, purge on donation), data retention tied to end-of-year donation cycle, camera hardware TBD.
  Added: MobileNetV2 A/B comparison script strategy (`main_classical_only.py`) and Calibration/Flood Prevention strategy (mass-alert auto-pause gate + HTML5 Canvas web recalibration UI).
  Segregated future roadmap items into checkbox-based tasks to allow granular development.
- **2026-07-01** — Phase 1 backend correctness complete. Fixed `ingest_detection.php` fatal (`ms_detection_stage()` undefined) by moving fn to `services/monitoring/db.php` with `function_exists` guard. Provided real bcrypt admin seed SQL. Rewrote all three dashboards (staff/admin/student) to replace hardcoded PHP arrays with live JS polling to `get_detections.php`, `get_claims.php` (new), and `get_recovered_items.php` (new). Wired all verify/dismiss/resolve/recover buttons to `update_event_status.php` with notes. Added `room_id` to `monitoring_logs` audit INSERT. Also created `get_claims.php` and `get_recovered_items.php` (were in README but never built).
