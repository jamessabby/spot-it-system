# S.P.O.T.-IT — Project Context for AI Coding Agents

Read this file fully before making any changes. It exists so you (Claude, or any
agent in Antigravity) don't need the thesis PDF or old chat history re-explained
every session. If something here conflicts with what you observe in the actual
code, **trust the code** and flag the mismatch — this file is a snapshot, not a
live source of truth.

### How to keep this file honest

This document is a **living plan, not a contract**. Sab's thesis advisers and
panelists can and will change requirements — tolerance values, tier logic, DB
fields, even scope — mid-thesis, and that's normal, not a deviation to resist.
When that happens:
- Treat adviser/panelist instructions as **higher priority than anything
  written here**, including things marked "do not re-litigate."
- After implementing a change like that, **update this file in the same
  session** (add a dated note under §9 "Change Log," and edit the relevant
  section directly) so the next session doesn't propose the old approach again.
- If asked to do something that contradicts this file, say so plainly, then do
  what Sab/the panel actually wants — don't silently follow the stale doc.

---

## 0. What this project is

**S.P.O.T.-IT** (Smart Property Object Tracking and Inventory Technology) is an
undergraduate BSCpE thesis at De La Salle University–Dasmariñas (CEAT/DLSU-D),
titled *"An IoT Integrated Surveillance System for Laboratory Item Tracking and
Lost-and-Found Management inside DLSU-D CEAT Laboratory Rooms."* Three
co-authors (Diosana, Pedrozo, Sabio); James/Sab is doing the technical
implementation with AI assistance. Target: May 2026 defense.

It is an IoT-integrated CCTV monitoring system for DLSU-D CEAT laboratory rooms
that detects **item count / presence deviations** using classical computer
vision (background subtraction, frame differencing, ROI-based comparison), logs
events to a centralized database, and surfaces them on a role-based web
dashboard for staff to verify, dismiss, or resolve — plus a lost-and-found
claiming workflow for personal items in lecture rooms.

**It is not** object recognition / identity tracking. The system detects
whether *something is present in a registered zone*, not *which specific item*
it is. This is a deliberate scope decision (see §3a, §8) — don't build features
that imply individual item identification, unless a panelist explicitly asks
for that scope expansion (see adaptability note above).

**Thesis defense / deadline pressure is real.** Sab is in the final stretch.
Prioritize working, defensible, testable code over architectural purity or
speculative features. When in doubt, ask "does this help pass Table 3.5 testing
and the panel defense" before "is this the most elegant design."

---

## 1. Repo layout (as of last sync)

```
spotit/                        ← XAMPP htdocs root: C:\xampp\htdocs\spotit\
├── main.py                    ← Python/OpenCV detection loop (runs OUTSIDE htdocs on the laptop)
├── capture_ref.py             ← one-off: captures/saves photos/ref_image.jpg from RTSP stream
├── register_roi.py            ← interactive tool: draw ROI boxes, saves rois.json
├── rois.json                  ← registered ROI list (label, x, y, w, h) — currently desk-test items
├── photos/
│   ├── ref_image.jpg          ← baseline reference frame
│   └── snapshots/
├── config/
│   └── env.php                ← site config, OAuth stubs, DB host/creds, SNAPSHOT_PATH, timer thresholds
├── services/                  ← ONE folder per microservice, ONE database each — DO NOT MIX
│   ├── auth/       db.php, schema.sql   → spotit_auth_db   (users, sessions, login_attempts)
│   ├── monitoring/ db.php, schema.sql   → spotit_monitor_db (rooms, registered_lab_items, detections, monitoring_logs)
│   ├── lostfound/  db.php, schema.sql   → spotit_lf_db     (recovered_items, surrender_logs, claims)
│   └── user/       db.php, schema.sql   → spotit_user_db   (user_profiles, user_settings)
├── auth/                      ← backend POST/GET handlers (the ONLY place SQL should be written)
│   ├── service_bootstrap.php  ← loads all 4 service DBs + shared helpers (e.g. ms_detection_stage())
│   ├── ingest_detection.php   ← POST target for main.py — writes to spotit_monitor_db.detections
│   ├── get_detections.php     ← GET, used by dashboards to poll live events
│   ├── update_event_status.php← staff verify/dismiss/resolve actions
│   ├── login_handler.php, signup_handler.php, logout.php
│   ├── microsoft_login.php, microsoft_callback.php   ← Azure AD OAuth (@dlsud.edu.ph only)
│   ├── get_tour_status.php, save_tour_status.php     ← onboarding tour persistence
│   └── submit_claim.php
├── pages/                     ← UI only, no raw SQL — call auth/ handlers via fetch or PHP includes
│   ├── login.php, signup.php, forgot-password.php, index.php
│   ├── dashboard-admin.php, dashboard-staff.php, dashboard-student.php
│   ├── room-monitor.php, claiming-station.php, lost-thread.php
│   ├── profile.php, settings.php, my-posts.php, admin-audit.php
│   ├── privacy-policy.php, terms.php
│   └── _sidebar.php
├── assets/css/, assets/js/    ← theme.js, toast.js, skeleton.js/css, onboarding.js/css (shared)
├── uploads/snapshots/         ← detection snapshot images (served from web root)
└── README.md                  ← original architecture rules doc, still accurate — read it too
```

**Two physical machines' worth of code live in one repo conceptually:**
- `main.py` + the register/capture scripts run on the laptop directly (not through
  a web server), reading an RTSP stream and POSTing to `ingest_detection.php`.
- Everything else runs under XAMPP Apache at `http://localhost/spotit/`.

---

## 2. Hard architecture rules (from README.md — do not violate without adviser sign-off)

1. **Strict microservices.** Every service has its own database. A page/handler
   for one service must not open a connection to another service's DB directly.
   Cross-service reads go through `service_bootstrap.php` helpers.
2. **No SQL in `pages/*.php`.** Pages render UI and call `auth/*.php` handlers
   (via server-side include+function call, or client-side fetch). All queries
   live in `auth/` handlers or `services/*/db.php`.
3. **Auth:** Microsoft OAuth (`@dlsud.edu.ph` domain only) is primary login;
   manual email/password is fallback (same domain restriction, CAPTCHA
   required). Rate limiting: 3 fails → 30s cooldown, 5 fails → 5 min lockout,
   tracked in `login_attempts`. DB role values are `student`, `staff`,
   `admin`, but **`staff` is normalized to `admin` at login** (both
   `login_handler.php` and `microsoft_callback.php`) — there is no separate
   staff dashboard or nav anymore. The thesis only has two real user types:
   admin (lab-in-charge/IT staff/faculty who view the dashboard) and student
   (owners who claim items). Housekeepers who physically verify items don't
   need a web login — they go to the room when alerted. `dashboard-staff.php`
   is kept only as a redirect stub for old links; `_sidebar.php` gates the
   monitoring/admin nav on `$role === 'admin'` only. The `staff` value is
   left in the `users` table itself (no DB/schema change) in case advisers
   ask about it — only the session/routing layer merges it into admin.
   Admin is provisioned only by an existing admin, never self-signup (see §4
   for the bootstrap problem this creates and how to solve it).
4. **Detection ingestion is API-key authenticated, not session-authenticated**
   (Python has no browser session). Key comes from `SPOTIT_DETECTION_KEY` env
   var / `env.php`, checked with `hash_equals()`.

---

## 3. Detection pipeline — current logic in `main.py`

Pipeline per frame, per registered ROI:

1. **Scene stability gate** — compare full frame against `ref_image.jpg`
   (blurred grayscale absdiff). If motion % across the whole frame exceeds
   `SCENE_MOTION_LIMIT`, monitoring pauses for that frame (someone's walking
   through, class is active, etc).
2. **ROI change check** — within a stable scene, diff the ROI's pixels against
   its reference crop; if change exceeds `THRESHOLD`/`MIN_CHANGE_PERCENT`, it's
   a candidate.
3. **Tolerance-zone template match** (`cv2.matchTemplate`) — search a wider
   margin box around the ROI for the original reference crop. If found with
   score ≥ `MATCH_SCORE_THRESHOLD`, the item is judged "still present, just
   shifted" (thesis Fig. 3.9 / Section 3.6.7 behavior).
4. If neither the original ROI nor the tolerance zone finds it for
   `CONSISTENCY_FRAMES` consecutive frames, a candidate event fires: snapshot
   saved, POSTed to `ingest_detection.php`.

**Known current tuning values** (raised once already to kill RTSP compression-
noise false positives):
```
THRESHOLD = 25          # was 10 — pixel delta needed to count as "changed"
SCENE_MOTION_LIMIT = 0.40   # was 0.15 — % of frame that must be stable
CONSISTENCY_FRAMES = 5      # was 3 — consecutive frames needed to confirm
MATCH_SCORE_THRESHOLD ≈ 0.55 (tune 0.45–0.65 as needed)
TOLERANCE_MARGIN ≈ 0.25 for Tier 1 fixed lab equipment (tighter than the 0.6
  default — lab equipment shouldn't drift far), wider for personal items.
```
These are tuned for the iPhone/OctoStream desk-test setup, not the final Dahua
CCTV hardware. Expect to re-tune once real cameras are deployed (see §5, Phase 3).

### 3a. Open design decision — DO NOT re-litigate from scratch, just implement

This was already worked through with Sab and agreed on. If a future session
proposes reinventing this, point back here first — but if an adviser/panelist
pushes back on it directly, that overrides this note (see "How to keep this
file honest" at the top).

- **Tier-aware pipeline**, matching the thesis's own Table 3.2/3.3 tier system:
  - **Tier 1 fixed lab equipment** (monitor, keyboard, mouse, PC, UPS): skip
    template-match "moved" state entirely. Pipeline should be: scene stable →
    ROI changed? → **MobileNetV2/MobileNet-SSD presence check** (is *any*
    object still visually present in the ROI box, regardless of exact
    position) → if yes, **GREEN**, full stop, no orange/"moved" state. If no,
    start consistency counter → MISSING. Rationale: housekeepers/students
    nudging a monitor after class must never require pixel-perfect
    repositioning to stay green — that's not realistic and would flood staff
    with false "item moved" states after every session.
  - **Tier 2/3/4 personal/lost items** (lecture room logic): keep template
    matching + expanding-search-zone logic, since here "where did it move to"
    is genuinely useful info for recovery, not noise.
  - **MobileNetV2 is a secondary validation gate only**, per the thesis's own
    literature-justified constraint: *"ML is allowed ONLY as a secondary
    validation filter (gatekeeper pattern) on top of the classical pipeline,
    using lightweight MobileNetV2 pre-trained on ImageNet."* It must never become
    the primary detector — classical absdiff/threshold/contour still does the
    first-pass "did anything change" job; MobileNetV2 only answers "is an
    object still there" on the flagged crop. This framing is what makes the ML
  use defensible to the panel (disambiguates repositioning from genuine
  removal; classical pipeline alone cannot).
- **Expanding search zone** (a panel suggestion, grounded in thesis §3.6.7):
  when an item isn't found in the original ROI, progressively widen the
  `cv2.matchTemplate` search area (e.g. 1.5x → 2x → full frame) instead of one
  fixed tolerance box, and report an approximate found-location instead of a
  binary in/out result. Useful for personal-item tracking, not required for
  Tier 1 fixed equipment (which just needs presence, not location).
- **System scope honesty (for the defense):** the system does zone-based
  presence detection, not item identity recognition. If Monitor A is swapped
  for Monitor B in the same registered zone, the system correctly reports
  "present" — that's accurate to Objective 2 ("detect item count deviations"),
  not overclaiming. State this plainly if a panelist asks "how does it know
  which computer is which" — don't try to make the system do something it
  isn't designed to do.

**Status check needed:** the most recently uploaded `main.py` in this repo is
still the *tolerance-only* version (no MobileNetV2 gate, no tier split). The
tier-aware + MobileNetV2 version was drafted in a prior chat but may not be the
version currently deployed — verify by checking for `mobilenet`/`dnn`/`tier` in
`main.py` before assuming it's live. If missing, that's the next real coding
task, not already-done.

### 3c. Finalized scope & behavior decisions (2026-07-04 discussion with team)

These were discussed and agreed on by Sab and his groupmate. They are project
requirements now, not open questions. Future sessions should implement against
these — don't re-propose alternatives unless an adviser/panelist explicitly
asks for a change.

#### No cross-room item tracking

- Detection and template matching operate **within a single camera frame only**.
  The expanding search zone (1.5x → 2x → full frame) scans a wider rectangle
  of the *same camera's current image*. There is no matching of items across
  different rooms or cameras.
- If Room 1 has an iPhone 13 and Room 2 also has an iPhone 13, the system
  treats them as completely independent zones. No attempt to correlate or
  identify "which specific iPhone" — that would require object identity
  recognition, which is explicitly out of scope (§8).
- This is a deliberate, non-negotiable scope decision, not a limitation to
  apologize for. If a panelist asks about it, state it plainly: the system
  tracks *presence in registered zones*, not *individual item identity*.

#### Item labeling convention

- **Registered items** (expected to be in the room, entered during ROI
  registration): named descriptively — `computer1`, `keyboard3`, `monitor5`,
  etc. These labels are stored in `rois.json` and the `registered_lab_items`
  table.
- **Unregistered / left items** (detected by the system but not part of the
  room's registered inventory): auto-labeled `object1`, `object2`, etc.
  This is a **dynamic display label**, not a permanent DB column — computed
  on-the-fly by querying unlabeled items in `recovered_items`, ordered by
  `recovered_at`, numbered sequentially.
- **Admin relabeling workflow**: Admin/staff should relabel unregistered items
  with a descriptive name (e.g. "red Jansport bag with keychain") by end of
  day. If unlabeled items exist, the dashboard shows a notification nag.
  When an item is relabeled, it drops out of the `objectN` pool and the
  remaining auto-labels shift down naturally (no renumbering code needed —
  just a `COUNT` query against still-unlabeled items).

#### Claiming flow — owner-initiated, selfie required

1. System detects item missing → flagged RED → staff/admin notified
2. Item is eventually recovered (housekeeper finds it, or it reappears) →
   brought to the dispensing room
3. Staff marks item as available for claiming in the dashboard
4. **Owner** goes to the claiming station (kiosk/device at the dispensing
   window) and opens the claiming page in the web app
5. Owner clicks "Claim" → enters university ID → describes the item →
   **takes a selfie holding the retrieved item** via the web app's webcam
   capture → clicks submit
6. Status changes to **Claimed**

The owner is the one who presses the "Claimed" button — no staff proxy for
this step. This is a deliberate design: the selfie + button press by the
owner creates an undeniable record that the item was physically handed off.
The `claims.webcam_snapshot` column already exists for this.

#### Dual-snapshot evidence trail ("who took the item")

Every detection event produces **two snapshots**:

| Snapshot | Trigger | Purpose |
|----------|---------|--------|
| **Snapshot A** | Item first confirmed missing (consistency counter met) | Evidence of the empty ROI — proves the item was gone |
| **Snapshot B** | ROI changes *again* while item is in RED/MISSING state (someone physically interacts with or removes the flagged item) | Evidence of *who* touched it — supports recovery or theft investigation |

This creates a chain of evidence:
- Housekeeper took it → turned it over to dispensing room → good (normal flow)
- Someone took it → item never reaches dispensing room → **Snapshot B shows
  who interacted with the item + timestamp** → evidence for possible theft

**Snapshot B requires new detection logic in `main.py`:** after an item
enters RED/MISSING state, the system must continue monitoring that ROI.
When it detects another significant change (a person reaching into the
frame, the object disappearing from where it was last sitting), it captures
a second snapshot and logs the timestamp. This is **not yet implemented**
and is tracked as a Phase 2 task.

#### Left-item event log fields

When an unregistered/left item is detected, the system logs:
- Timestamp (detected_at)
- Room location (room_id)
- Detected object label (auto-generated `objectN` or admin-assigned name)
- Notification status (pending → potential → confirmed_missing → etc.)
- Event duration (computed from detected_at to resolution)
- Snapshot image of the item (Snapshot A)
- Snapshot of whoever took it (Snapshot B, when triggered)

These map to existing columns in `spotit_monitor_db.detections` plus the
snapshot files on disk.

#### Storage & hosting plan

- **Hosting:** Hostinger Cloud Startup plan (₱409/mo) — 100 GB NVMe storage,
  PHP + MySQL, deployed at a public URL (not localhost) for the defense demo.
- **Snapshot compression:** All snapshots resized to 640×480 JPEG before
  saving (~50–100 KB each). At 2 snapshots per event × ~100 events/day
  across all rooms = ~20 MB/day = ~600 MB/month. Well within 100 GB.
- **No automatic image deletion.** Snapshots are kept as long as the item
  record exists — owners searching for their items need to see the photos.
  Deduplication: only 1 snapshot per event trigger (not per frame).
- **Data retention lifecycle:**
  Detected → Flagged → Recovered → Claimed **or** Unclaimed → Donated
  (end of academic year, per SWAFO Director + CEAT technician interview).
  When an item is processed as donated on the donation page, associated
  records (detection logs, snapshots, claim records) are purged. This is
  the system's garbage collection — no manual cleanup needed.
- **Donation page:** A separate UI page for admin to process end-of-year
  donations of unclaimed items. When items are donated, their DB records
  and snapshot files are archived or deleted. (Not yet built — Phase 5+
  scope.)

#### Camera hardware (TBD — pending team clarification)

- **Planned:** Dahua 5MP Full-color Eyeball CCTV — but the specific model
  listed is analog (coaxial output). The system needs an **IP camera** that
  serves RTSP natively, or an analog camera connected through a DVR/NVR
  that outputs RTSP. Team is clarifying this.
- **Budget:** 2 cameras total. Prototyping with 1 camera first; once the
  system works with 1, the second camera is added.
- **Cannot access school CCTV** — project buys its own hardware regardless
  of whether DLSU-D CEAT has existing cameras.
- **Current desk-test stand-in:** iPhone + OctoStream RTSP app. This works
  fine for development and will continue to be used until real hardware is
  procured. Tuning values will need adjustment when switching (see §3,
  closing note).

#### MobileNetV2 A/B Accuracy Comparison

- **Purpose:** To defend the use of AI/ML to the panel and provide concrete evidence
  for Chapter 4 (Results). We need to prove that MobileNetV2 is necessary to eliminate
  false positives from shadows, slight nudges, or camera compression noise.
- **Two Parallel Scripts:**
  - `main.py`: The live system with the full pipeline (Classical CV + MobileNetV2 gatekeeper).
  - `main_classical_only.py`: The baseline system with only background subtraction +
    tolerance-zone template matching (MobileNetV2 presence validation code is stripped).
- **Execution:** Both scripts run against the same live stream and `rois.json` configuration.
  For the defense demo, a keyboard nudged a few inches will stay green in `main.py`
  but turn red (false alarm) in `main_classical_only.py`.
- **Metrics:** Compare accuracy using TP/FP/FN/TN rates side-by-side in Chapter 4.

#### Calibration & Database Flood Prevention

- **Problem:** If a lab room is rearranged (e.g., desks moved for summer or cleaning), the
  system would trigger simultaneous "MISSING" alerts for all items. This would generate dozens of concurrent
  unnecessary database entries, snapshot files, and dashboard alerts.
- **Auto-Flood Gate:** In the Python detection logic, if more than **50% of registered ROIs**
  in a single room simultaneously enter the "MISSING" state within 60 seconds, monitoring for
  that room **automatically pauses**. A single system alert is logged: *"Mass deviation detected.
  Room monitoring paused. Recalibration required."* This protects the DB and file system.
- **Web-Based Recalibration Tool (Option B):** Instead of running the command-line Python
  registration tool (`register_roi.py`), the admin dashboard will host a polished, visually-pleasing
  web interface:
  - Admin selects a paused/rearranged room and clicks "Recalibrate".
  - The system requests a fresh camera snapshot from the live RTSP feed.
  - The web page loads this snapshot onto an HTML5 Canvas-based annotation tool.
  - Admin draws/adjusts bounding boxes directly on the web browser.
  - Clicking "Save" updates the backend `rois.json` (or database equivalent) and automatically
    dismisses all stale pending alerts for that room before resuming live monitoring.



### 3b. Bug Diagnosis & Resolution History

The detailed history of past resolved bugs (RTSP orientation issues, constant-red triggers, compression noise, etc.) and their diagnostic root causes has been moved to [BUGS.md](file:///c:/xampp/htdocs/spotit/BUGS.md) to keep this rulebook clean. Refer to that file before debugging repeated issues.


---


## 4. Active / Immediate Fix Targets

The list of active bugs and immediate development fix targets (like setting up real test rooms and the first admin account) is tracked in [BUGS.md](file:///c:/xampp/htdocs/spotit/BUGS.md). Refer to that file to see outstanding bug tasks.


## 5. Active Roadmap & Progress Checklists

The project roadmap, phase-by-phase implementation checklists, and current task progress have been moved to [UPDATES.md](file:///c:/xampp/htdocs/spotit/UPDATES.md) to keep this rulebook lightweight. Refer to that file to check off completed items and see what to work on next.


---

## 6. Tooling — what to use for what

Sab has access to multiple AI coding tools; don't assume only one is in play.

- **This assistant (Claude, in Antigravity or Claude Code)**: primary tool for
  anything touching `main.py`, PHP backend logic, database schema, or existing
  code that needs precise reasoning about interdependencies (rotation fix,
  microservice boundaries, tier logic, etc). Default choice for this project.
- **Antigravity's own agent mode**: fine for isolated, well-scoped, greenfield
  tasks (e.g. "wire this one page's polling to that one endpoint" as a
  self-contained delegated task) where the agent doesn't need deep context
  about the rest of the PHP/Python hybrid codebase. Not recommended for
  cross-cutting changes (anything touching the detection pipeline, DB schema,
  or auth) — those need the fuller context this file provides.
- **Stitch (AI UI design tool)**: useful if new dashboard pages or a redesign
  are needed — generates high-fidelity HTML/CSS starting points fast. Export
  and adapt into the existing PHP page structure and `assets/css/variables.css`
  design tokens rather than treating Stitch output as final.
- **General rule**: don't switch primary tools mid-task. Pick one for a given
  piece of work and finish it there — bouncing a half-finished change between
  tools is how context gets lost and bugs like the stale-`main.py`-file issue
  (§3b) happen again.

---

## 7. Conventions / working style for this project

- **Give complete working files, not fragments.** Sab pastes full files into
  VS Code / Antigravity and replaces wholesale — partial diffs/snippets create
  more confusion than they save, given the file interdependencies here.
- **Make decisive calls on creative/architectural judgment calls** rather than
  presenting a menu of options — this is a solo-implementer thesis on a
  deadline, not a team design review. State the reasoning briefly, then ship
  the code. (Exception: if an adviser/panelist requirement is genuinely
  ambiguous, it's fine to ask — just don't manufacture open questions that
  aren't actually there.)
- **Always explain *why* a fix works**, not just what changed — Sab needs to
  defend every design decision to a thesis panel, so "trust me" fixes without
  rationale are actively unhelpful even if they're correct.
- **Test steps must be explicit and concrete** (exact file paths, exact SQL,
  exact terminal commands) — Sab is testing on a live XAMPP + physical
  camera/phone setup, not a CI pipeline.
- **Don't silently reintroduce Node.js/Express.** An earlier session scaffolded
  a Node.js/Express backend layer; the project has since pivoted fully to
  PHP/XAMPP/phpMyAdmin. Node.js is not part of the current stack anywhere.
- **RTSP source is an iPhone via the OctoStream app** acting as a stand-in
  CCTV camera for desk-scale testing (`rtsp://<phone-ip>/stream`, IP changes
  with WiFi network — always confirm current IP before assuming the old one is
  valid). The eventual real deployment target is a Dahua 5MP IP CCTV camera per
  the thesis hardware spec — don't assume iPhone-specific quirks (portrait
  rotation, OctoStream compression artifacts) apply to the final hardware.
  See §3c "Camera hardware" for current procurement status.
- **Deployment target is Hostinger Cloud Startup** (₱409/mo, 100 GB NVMe),
  not localhost/XAMPP. The defense demo will run against the hosted URL.
  `main.py` will POST to the hosted `ingest_detection.php` instead of
  `localhost`. XAMPP remains the local dev environment only.
- **`capture_ref.py` rotates 90° CCW and rescales 50%** to correct the iPhone's
  portrait orientation; `main.py` must apply the same rotation to live frames
  or the reference/live comparison breaks (see §3b for the full root-cause
  history — this has already happened once, watch for regressions if either
  script is touched independently).

---

## 8. Non-negotiable thesis-scope guardrails (unless overridden by adviser/panel)

- Do not add real object-identity recognition / classification beyond the
  MobileNetV2 presence-only gatekeeper — it would contradict the thesis's own
  stated forbidden-tech boundary and undermine the "ML is secondary validation
  only" defense framing.
- Do not swap the DB/backend stack (currently PHP/MySQL/XAMPP) — this has
  already changed once (Node.js → PHP) and thesis documentation, diagrams, and
  defense materials are now written around PHP/XAMPP.
- Do not merge the 4 microservice databases into one, even if it seems simpler
  — it's an explicit thesis design requirement (Fig. 3.10 database design) and
  part of Objective 3.
- **These guardrails exist to protect thesis defensibility, not because the
  alternatives are technically wrong.** If Sab's adviser or a panelist
  explicitly directs a change here, follow that direction, implement it, and
  update this section to reflect the new reality — don't cite this document
  back at Sab as a reason to resist a legitimate requirement change.

---


## 9. Change Log & Project History

The historical change log documenting past development sessions and pivots has been moved to the end of [UPDATES.md](file:///c:/xampp/htdocs/spotit/UPDATES.md) to keep this rulebook focused. Refer to that file to trace past modifications.

