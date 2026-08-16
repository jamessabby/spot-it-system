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
├── main_classical_only.py     ← archival pre-ML baseline, standalone; A/B arm for Chapter 4
├── capture_ref.py             ← one-off: captures/saves photos/ref_image.jpg from RTSP stream
├── register_roi.py            ← interactive tool: draw ROI boxes, saves rois.json
├── rois.json                  ← registered ROI list (label, x, y, w, h) — DESK sandbox items
├── rois_<ROOM>.json           ← per-room ROI lists for production rooms
├── detection_mode.json        ← runtime control file main.py mtime-polls: room_id, mode,
│                                tracking_mode, timer_mode, pipeline, reset flags
├── photos/
│   ├── ref_image.jpg          ← baseline reference frame (ref_image_<ROOM>.jpg per room)
│   ├── detection_metrics.csv  ← per-decision instrumentation (Chapter 4 raw material)
│   ├── ab_events_*.csv        ← A/B harness event logs, one per arm
│   └── snapshots/
├── config/
│   ├── env.php                ← tracked, credential-free: site config, DESK camera, timers
│   └── env.local.php          ← real secrets, gitignored
├── services/                  ← ONE folder per microservice, ONE database each — DO NOT MIX
│   ├── auth/       db.php, schema.sql   → spotit_auth_db   (users, sessions, login_attempts,
│   │                                       microsoft_tokens, tour_status, notifications, user_activity)
│   ├── monitoring/ db.php, schema.sql   → spotit_monitor_db (rooms, registered_lab_items, detections,
│   │                                       monitoring_logs, + accuracy_trials — see §7a, no migration yet)
│   ├── lostfound/  db.php, schema.sql   → spotit_lf_db     (recovered_items, surrender_logs, claims)
│   ├── user/       db.php, schema.sql   → spotit_user_db   (user_profiles, user_settings)
│   ├── community/  schema.sql           → spotit_community_db (announcements, forum_posts,
│   │                                       forum_comments, forum_votes, announcement_views)
│   └── migration_*.sql        ← four migrations, run in the §7a order
├── auth/                      ← backend POST/GET handlers (the ONLY place SQL should be written)
│   ├── service_bootstrap.php  ← loads the service DBs + shared helpers (e.g. ms_detection_stage())
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

### 1a. Deployment architecture — two machines, one direction (2026-08-01)

In production this is **not** one machine. It cannot be, and the reason drives a lot of
the code:

```
   CEAT lab (mini-PC, always on)              Hostinger (public URL)
   ┌──────────────────────────┐               ┌────────────────────────┐
   │ main.py                  │ ── HTTPS ──▶  │ PHP + MySQL            │
   │   ← RTSP ← Tapo TC65     │               │ dashboards, claiming   │
   └──────────────────────────┘               └────────────────────────┘
              (server can never call in — NAT)
```

The camera is at a **private address** (`192.168.x.x`) behind the campus NAT. A hosted
server cannot route to it, and the router blocks unsolicited inbound connections anyway.
So detection must run on-premise, and **every exchange has to be started by the
detector.** The server leaves notes; the detector picks them up.

`SPOTIT_DEPLOY` (`config/env.php`, and the matching env var for `main.py`) selects:

- **`local`** — one machine, shared filesystem. Original behaviour: config read off disk,
  MJPEG proxy live, `capture_frame.php` shells out to ffmpeg. This is XAMPP dev **and the
  offline fallback for the defense demo** — keep it working.
- **`remote`** — split. `main.py` polls `auth/get_detector_config.php` and pushes to
  `ingest_live_frame.php` / `ingest_baseline.php` / `detector_heartbeat.php`.
  `tapo_stream.php` returns 501 (it cannot reach the camera, and it pinned one Apache
  worker per viewer, which shared hosting cannot absorb).

**Design note worth preserving:** in remote mode `sync_remote_config()` writes the fetched
config into *the same local files* local mode already reads. The existing mtime-driven
reload path is therefore reused rather than duplicated — the two modes cannot drift — and
those files double as an offline cache, so a network blip degrades the dashboard without
stopping detection.

Full runbook: [DEPLOYMENT_GUIDE.md](file:///c:/xampp/htdocs/spotit/md_files/DEPLOYMENT_GUIDE.md).

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

**Rewritten 2026-07-30.** Template matching was removed from the registered-ROI path; it
survives unmodified in `main_classical_only.py` as the A/B baseline. Rationale and measured
results in [BUGS.md §3](file:///c:/xampp/htdocs/spotit/md_files/BUGS.md).

Pipeline per frame, per registered ROI:

1. **Scene stability gate** — frame-to-frame blurred grayscale absdiff. If motion exceeds
   `SCENE_MOTION_LIMIT`, this frame is skipped and all confirmation timers **hold**
   (they are *not* reset — resetting created a false-negative path, since removing an item
   is itself motion; see BUGS.md 2026-07-30 (c)).
2. **Classical change check** (`check_roi`) — absdiff of the ROI against the baseline. Below
   `MIN_CHANGE_PERCENT` the item is unchanged and the frame ends there. This is the classical
   first pass that keeps the ML strictly secondary: on a quiet frame the network never runs.
3. **Dual-template presence test** (`evaluate_roi_presence`) — only on changed ROIs. Scores
   the item's footprint against **two** templates built at calibration time: the reference
   crop ("item is here") and an inpainted reconstruction of the surface beneath it
   ("item is gone"). Yields `PRESENT`, `ABSENT`, or `OCCLUDED`.
4. **Wall-clock confirmation** — `ABSENT` accumulates time; at `MISSING_CONFIRM_SEC` the event
   fires (snapshot + POST to `ingest_detection.php`). `PRESENT` accumulates separately and
   auto-resolves at `RECOVER_CONFIRM_SEC`. `OCCLUDED` freezes both — while the view is blocked
   no evidence accrues either way, which is what makes a motionless hand on the mouse safe.

**Why two templates.** The old test was one-sided — it only asked *"does this look like the
item?"* — so bare desk scored ~0.65 against a crop that was itself mostly desk and won by
default. Giving the removal its own hypothesis to match resolves this without threshold games.

5. **Stuck-occlusion escape** — an ROI that stays `OCCLUDED` **and motionless** for
   `OCCLUDED_MAX_SEC` resolves to `ABSENT`. Added 2026-08-16 because the "item gone" template
   is uninformative for large items (measured `sim_empty` 0.39 present vs 0.41 removed), which
   parked genuine removals in OCCLUDED forever. The motionless condition is load-bearing —
   without it this degenerates into a plain timeout that eventually alarms on a resting hand.

**Current tuning values** (re-verified against `main.py`, 2026-08-16):
```
THRESHOLD = 25              # pixel delta counted as "changed"
MIN_CHANGE_PERCENT = 0.08   # ROI change fraction that triggers the presence test
SCENE_MOTION_LIMIT = 0.20   # frame-to-frame motion above which we hold
SIM_PRESENT = 0.80          # >= this vs "item here" template  -> PRESENT   (was 0.85)
SIM_EMPTY = 0.55            # >= this vs "item gone" template  -> ABSENT    (was 0.65)
MISSING_CONFIRM_SEC = 1.0   # continuous ABSENT time before RED
RECOVER_CONFIRM_SEC = 0.5   # continuous PRESENT time before auto-resolve
FOOTPRINT_MIN_FRAC = 0.03   # item smaller than this fraction of its box -> warn
OCCLUDED_MAX_SEC = 5.0      # continuous STILL occlusion before it counts as absence
OCCLUDED_STILL_MOTION = 0.02  # ROI motion below which it counts as "not moving"
CLASSICAL_MATCH_THRESHOLD = 0.55  # used only when pipeline == "classical"
```
**Both similarity thresholds were lowered from the 2026-07-30 calibration figures** —
`SIM_PRESENT` for position tolerance (items nudged inside their ROI scored just under 0.85),
`SIM_EMPTY` because weak inpaints on large items never reached 0.65. The measured tables in
BUGS.md §3.1 still record the original calibration and remain the evidence for *why* the
dual-template method works; they are not the shipped numbers. **`main.py` is the source of
truth — re-read it before quoting any threshold in the manuscript.**

`CONSISTENCY_FRAMES` (25) still exists but is **derived** from the wall-clock timers purely to
keep the `live_roi_state.json` contract stable for the PHP dashboard — it no longer drives any
decision. `MATCH_SCORE_THRESHOLD` (0.48 in `main.py`) is dead there and retained only as a
historical marker; the live classical arm uses `CLASSICAL_MATCH_THRESHOLD = 0.55`, which is
`main_classical_only.py`'s own value, so the in-process A/B arm behaves exactly like the
archival baseline. `TOLERANCE_MARGIN` never existed in the code despite earlier versions of
this document.

Tuned for the Tapo TC65. `photos/detection_metrics.csv` records the inputs to every decision
if re-tuning is needed.

**Runtime pipeline switch (2026-08-16).** `detection_mode.json` carries a `pipeline` field
(`gatekeeper` | `classical`) that `main.py` picks up on its existing mtime poll — no restart.
`gatekeeper` is the dual-template + MobileNetV2 path above; `classical` runs
`evaluate_roi_presence_classical()`, the pre-ML template-match baseline, in the same process.
Exposed **only** on `pages/desk-sandbox.php` — classical-only is a demonstration baseline, never
a production option. Switching resets the confirmation timers so a verdict from one pipeline can
never confirm an event under the other, and the active pipeline is burned into the video frame
so screenshots are self-evidencing. Run the two arms **one at a time**: the Tapo allows only a
couple of concurrent RTSP clients, and a refused second client silently drops to `/stream2` at
640×360, which would compare the pipelines at different resolutions without saying so.

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
  - **Tier 2/3/4 personal/lost items** (backup tracking logic): keep template
    matching logic, since here "where did it move to" is useful info for recovery.
  - **Direct search zone matching**: The expanding search zone (`1.5x -> 2x -> full frame`) template matching was removed to keep the pipeline clean, robust, and mathematically defensible. Template matching uses the direct boundaries of the target ROI box.
  - **MobileNetV2 is a secondary validation gate only**, per the thesis's own
    literature-justified constraint: *"ML is allowed ONLY as a secondary
    validation filter (gatekeeper pattern) on top of the classical pipeline,
    using lightweight MobileNetV2 pre-trained on ImageNet."* It must never become
    the primary detector — classical absdiff/threshold/contour still does the
    first-pass "did anything change" job; MobileNetV2 only answers "is an
    object still there" on the flagged crop. This framing is what makes the ML
    use defensible to the panel (disambiguates repositioning from genuine
    removal; classical pipeline alone cannot).
- **System scope honesty (for the defense):** the system does zone-based
  presence detection, not item identity recognition. If Monitor A is swapped
  for Monitor B in the same registered zone, the system correctly reports
  "present" — that's accurate to Objective 2 ("detect item count deviations"),
  not overclaiming. State this plainly if a panelist asks "how does it know
  which computer is which" — don't try to make the system do something it
  isn't designed to do.

**Status update (2026-07-30):** `main.py` runs the dual-template presence test with real
clock-time confirmation, Snapshot A/B evidence capture, and unregistered state cleanup.

The MobileNetV2 gatekeeper is still in place but is used **differently** than originally
planned, and the change is worth understanding before touching it. It is no longer a
classifier — asking ImageNet to name an 80×60 CCTV crop does not work and never will. It now
supplies a **perceptual similarity metric**: the penultimate 1280-d feature vector
(`onnx_node!mobilenetv20_features_pool0_fwd`), compared by cosine between two crops from the
same camera. Because both crops carry identical blur, that degradation cancels.

This *strengthens* the §3a framing rather than weakening it:
- It never emits a class name, so the §8 no-identity-recognition guardrail holds strictly.
- The classical absdiff gate still decides whether anything changed; the network is consulted
  only on already-flagged crops and never runs on a quiet frame.
- `classical_similarity()` (chroma-only Lab histogram) performs the same three-way decision if
  the model is absent, so detection degrades rather than stopping.

If a panelist asks why ML is needed at all: the classical stage cannot distinguish *"the item
was removed"* from *"someone's hand is covering it"*. Both look like "not the item". The
embedding separates them — measured 0.98 for the item under lighting change vs 0.44 for a
hand. That disambiguation is exactly the gatekeeper role §3a describes.

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

#### Camera Hardware — TP-Link Tapo TC65

- **Deployed Camera:** **TP-Link Tapo TC65 (2K / 3MP Ultra-HD)**
  - **Resolution:** 2304 × 1296 pixels (3MP), ~44% more detail than 1080p. Excellent for distant small object / ROI tracking.
  - **Lens & FOV:** 1/2.7" sensor, F1.6 super-aperture lens, ~85°–90° horizontal field of view (ideal for 8m × 10m CEAT lab rooms).
  - **RTSP Native Stream:** Native RTSP streaming (`rtsp://username:password@<ip>:554/stream1` at 2304x1296 15-20fps, or `/stream2` at 640x360).
  - **Night Vision & Lighting:** 98 ft / 30 m IR night vision, F1.6 aperture handles dimmed lab light during projector presentations.
- **Quantity:** Deployed in laboratory rooms.
- **RTSP Feed:** Serves direct RTSP over campus Wi-Fi / local network to `main.py`.

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

The detailed history of past resolved bugs (RTSP orientation issues, constant-red triggers, compression noise, etc.) and their diagnostic root causes has been moved to [BUGS.md](file:///c:/xampp/htdocs/spotit/md_files/BUGS.md) to keep this rulebook clean. Refer to that file before debugging repeated issues.


---


## 4. Active / Immediate Fix Targets

The list of active bugs and immediate development fix targets (like setting up real test rooms and the first admin account) is tracked in [BUGS.md](file:///c:/xampp/htdocs/spotit/md_files/BUGS.md). Refer to that file to see outstanding bug tasks.


## 5. Active Roadmap & Progress Checklists

The project roadmap, phase-by-phase implementation checklists, and current task progress have been moved to [updates.md](file:///c:/xampp/htdocs/spotit/md_files/updates.md) to keep this rulebook lightweight. Refer to that file to check off completed items and see what to work on next.


---

## 6. Tooling — what to use for what

Sab works inside **Google Antigravity** (VS Code-based IDE), switching between
models in the same Agent panel rather than juggling separate apps.

- **Claude (Sonnet/Opus, logged in via Claude Pro subscription)**: primary
  agent for this repo. Default choice for anything touching `main.py`, PHP
  backend logic, database schema, or existing code that needs precise
  reasoning about interdependencies (rotation fix, microservice boundaries,
  tier logic, etc) — this is where Sab's actual thesis code work happens.
- **Gemini (built into Antigravity, no separate login/billing)**: useful for
  isolated, well-scoped, greenfield tasks, or for reading/summarizing large
  swaths of the codebase quickly, where the agent doesn't need deep context
  about the rest of the PHP/Python hybrid codebase. Good default for "cheap
  first pass" tasks to save Claude usage for the harder cross-cutting work.
  Not recommended for cross-cutting changes (anything touching the detection
  pipeline, DB schema, or auth) — those need the fuller context this file
  provides and are worth spending Claude on.
- **Stitch (AI UI design tool)**: useful if new dashboard pages or a redesign
  are needed — generates high-fidelity HTML/CSS starting points fast. Export
  and adapt into the existing PHP page structure and `assets/css/variables.css`
  design tokens rather than treating Stitch output as final.
- **General rule**: don't split one piece of work across both models
  mid-task. Pick Gemini or Claude for a given piece of work based on how
  cross-cutting it is, and finish it there — bouncing a half-finished change
  between models is how context gets lost and bugs like the
  stale-`main.py`-file issue (§3b) happen again.

---

## 7. Conventions / working style for this project

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
  camera/phone setup, not a CI pipeline (see §7a Validation Loop below).
- **Don't silently reintroduce Node.js/Express.** An earlier session scaffolded
  a Node.js/Express backend layer; the project has since pivoted fully to
  PHP/XAMPP/phpMyAdmin. Node.js is not part of the current stack anywhere.
- **RTSP source is a TP-Link Tapo TC65 2K/3MP IP camera** serving native RTSP
  over the local network to `main.py`.
- **Deployment target is Hostinger Cloud Startup** (₱409/mo, 100 GB NVMe),
  not localhost/XAMPP. The defense demo will run against the hosted URL.
  `main.py` will POST to the hosted `ingest_detection.php` instead of
  `localhost`. XAMPP remains the local dev environment only.
- **`capture_ref.py` rotates 90° CCW and rescales 50%** to correct the iPhone's
  portrait orientation; `main.py` must apply the same rotation to live frames
  or the reference/live comparison breaks (see §3b for the full root-cause
  history — this has already happened once, watch for regressions if either
  script is touched independently).

### 7a. Validation loop (no CI/automated test suite exists — this is manual)

Run these after any change, in this order, before considering a fix done:

1. **PHP syntax check** any touched PHP file(s) before testing in-browser:
   ```
   php -l auth/<file>.php
   php -l pages/<file>.php
   ```
2. **DB migrations**, if schema changed, run in this exact order (never skip
   or reorder) — all four live in `services/`:
   ```
   migration_workflow_audit.sql → migration_confidence.sql →
   migration_notifications.sql → migration_snapshot_b.sql
   ```
   **`migration_accuracy_trials.sql` was listed here but has never existed.** The
   `accuracy_trials` table is read/written by four call sites yet has no `CREATE TABLE`
   anywhere in the repo — it works locally only because it was made by hand in phpMyAdmin.
   A fresh deploy will fatal on the sandbox metrics panel and both room-status endpoints.
   Tracked in BUGS.md §1; write the migration before deploying or submitting the §3.6.8
   database-design text, which now names this table.
3. **Manual browser pass** against the live XAMPP instance
   (`http://localhost/spotit/`) — exact page(s) and click path to hit, plus
   the expected resulting DB row/state, should be spelled out per-fix (see
   §7 "Test steps must be explicit and concrete").
4. **Detection pipeline changes** (`main.py`) — run against the live Tapo
   RTSP stream (or `main_classical_only.py` side-by-side for an A/B
   comparison, per §3c) and confirm the specific ROI(s) affected behave as
   expected across a few real trigger/reset cycles, not just one.
5. **Check `BUGS.md`** before debugging anything that looks like a repeat —
   RTSP orientation, false-positive/false-negative tuning, and snapshot
   cleanup issues have each been root-caused before; don't re-diagnose from
   scratch.
6. **Detection-pipeline changes: follow
   [TESTING_GUIDE.md](file:///c:/xampp/htdocs/spotit/md_files/TESTING_GUIDE.md)** — the
   full physical test script (removal, return, nudge, hand occlusion, lighting,
   walk-past, Snapshot A/B, production dual-path), expected console/DB/disk output
   for each, the A/B protocol against `main_classical_only.py`, and threshold
   troubleshooting from `photos/detection_metrics.csv`.

---

## 8. Non-negotiable thesis-scope guardrails (unless overridden by adviser/panel)

- Do not add real object-identity recognition / classification beyond the
  MobileNetV2 presence-only gatekeeper — it would contradict the thesis's own
  stated forbidden-tech boundary and undermine the "ML is secondary validation
  only" defense framing.
- Do not swap the DB/backend stack (currently PHP/MySQL/XAMPP) — this has
  already changed once (Node.js → PHP) and thesis documentation, diagrams, and
  defense materials are now written around PHP/XAMPP.
- Do not merge the 5 microservice databases into one, even if it seems simpler
  — it's an explicit thesis design requirement (Fig. 3.10 database design) and
  part of Objective 3.
- **These guardrails exist to protect thesis defensibility, not because the
  alternatives are technically wrong.** If Sab's adviser or a panelist
  explicitly directs a change here, follow that direction, implement it, and
  update this section to reflect the new reality — don't cite this document
  back at Sab as a reason to resist a legitimate requirement change.

---


## 9. Change Log & Project History

- **2026-08-16 (Sandbox camera address centralised):** The DESK camera moved
  `192.168.18.11 → .14` after a factory reset, and the address turned out to be pasted into
  **8 places** (3 in `main.py`, 1 in `main_classical_only.py`, `capture_frame.php`,
  `tapo_stream.php`, `save_rois.php`, `desk-sandbox.php`) — so one DHCP change broke the system
  in several places at once, each with a different symptom. Now defined once: `DESK_CAMERA_IP` /
  `_USER` / `_PASS` / `_RTSP` in `config/env.php` for PHP, and `SPOTIT_CAMERA_IP` / `_USER` /
  `_PASS` env vars (same defaults) in both Python scripts. Production rooms are unaffected —
  they still read `camera_ip` from `spotit_monitor_db.rooms`. **Note:** existing `rooms` rows
  keep whatever IP they were seeded with; update ROOM_1's `camera_ip` in the DB separately.

- **2026-08-16 (Live Pipeline Switch — A/B in the sandbox):** Added a `pipeline` field to
  `detection_mode.json` (`gatekeeper` | `classical`) and a segmented toggle on
  **`pages/desk-sandbox.php` only**, so the live feed can be flipped between the MobileNetV2 gatekeeper
  and the pre-ML classical baseline **without restarting anything** — it rides the existing
  mtime-poll path alongside `mode` / `tracking_mode` / `timer_mode`. Implemented as a branch
  *inside* `main.py` (`evaluate_roi_presence_classical()`, ported verbatim from
  `main_classical_only.py` and reusing the already-present but previously unused
  `find_item_template_match()`), **not** by running the second script alongside: the Tapo
  allows only a couple of concurrent RTSP clients, and when the second is refused `main.py`
  silently falls back to `/stream2` at 640×360 — a side-by-side demo would have compared
  pipelines at different resolutions without ever saying so. One process, one stream, one set
  of ROIs; switching resets the confirmation timers so a verdict from one pipeline can never
  confirm an event under the other. The active pipeline is burned into the video frame so
  screenshots are self-evidencing. `main_classical_only.py` remains the standalone archival
  baseline for offline clip scoring. Verified on a replayed clip: classical → `ABSENT` with 0
  forward passes, gatekeeper → 150 forward passes, same frames, same process.
  **Deliberately not exposed on `room-monitor.php`:** classical-only is a demonstration
  baseline, never a production option — production always runs the gatekeeper. Run the two
  arms **one at a time**, not concurrently (RTSP client limit, see above).
  **Fidelity of the in-process classical arm (verified, not asserted):** `check_roi()` and
  `find_item_template_match()` are byte-identical between `main.py` and
  `main_classical_only.py`; the decision rule, the 0.55 match threshold and the fixed-asset
  keyword shortcut match. One divergence was found and fixed — `is_scene_stable()` in
  `main.py` measures motion frame-to-frame, the old system measured it against the baseline
  photo, so classical mode now uses the vs-baseline gate. Proven by replaying one clip
  through both and diffing the event logs: **identical, event for event, to the 0.01 s**.
  The A/B event CSV's `arm` column now records the pipeline that actually produced the row.

- **2026-08-16 (Stuck-occlusion escape):** The dual-template test could only reach ABSENT when
  the inpainted "item gone" template won, and inpainting is unreliable for large items — over
  a big mask `cv2.inpaint` returns a diffused smear. Measured on the sandbox laptop,
  `sim_empty` read 0.39 with the item present and 0.41 with it removed (no information), so
  removals parked in OCCLUDED indefinitely while `sim_present` correctly collapsed 0.86 → 0.46.
  Added `OCCLUDED_MAX_SEC = 5.0` / `OCCLUDED_STILL_MOTION = 0.02`: an ROI that stays OCCLUDED
  **and motionless** for 5 s resolves to ABSENT. The motion condition is load-bearing — a plain
  timeout would eventually alarm on a motionless hand, the exact false positive
  BUGS.md §3.3 exists to prevent. No detection threshold was changed.

- **2026-08-16 (A/B Accuracy Harness — Chapter 4 evidence):** Made the MobileNetV2-vs-classical
  comparison actually runnable and fair. `SPOTIT_SOURCE=<clip.mp4>` now replays a recorded file
  through either script instead of the camera, so both arms score **byte-identical frames** —
  this also dodges the Tapo's concurrent-RTSP-client limit, which silently drops one arm to
  `/stream2` at 640×360 when both run live. Replay advances the wall-clock timers by the clip's
  frame interval, so `MISSING_CONFIRM_SEC` means the same thing in replay as live, and it
  auto-disables all API posting so a scoring run cannot pollute `detections`. Both scripts now
  print an **ML duty cycle** summary on exit (ROI-frames vs forward passes vs mean latency) —
  this is the number that answers "isn't this just an AI system?" with a measurement.
  `main_classical_only.py` was aligned to `main.py` on the four things that were confounding the
  comparison (room-scoped ROI/reference files, `SCENE_MOTION_LIMIT` 0.40→0.20, frame-count→
  wall-clock confirmation, hold-instead-of-reset on unstable frames) while its **presence logic
  — `find_item_template_match`, `MATCH_SCORE_THRESHOLD`, the fixed-asset shortcut — was left
  untouched**; §9's "deliberately unchanged" note means don't improve the baseline's detection,
  not don't align its inputs. Also fixed a `UnicodeEncodeError` that killed either script the
  first time it printed a `→` on a cp1252 Windows console. Protocol, scoring method, and the
  "why not YOLOv8" argument: [AB_ACCURACY_PROTOCOL.md](file:///c:/xampp/htdocs/spotit/md_files/AB_ACCURACY_PROTOCOL.md).

- **2026-08-01 (Deployment Readiness — Two-Machine Architecture):** Made everything except
  detection deployable. Added an HTTP control plane (`auth/get_detector_config.php`,
  `ingest_live_frame.php`, `ingest_baseline.php`, `detector_heartbeat.php`,
  `detector_state.php`) so `main.py` can run on a lab mini-PC while the site is hosted —
  previously the two halves communicated through a shared filesystem, so on Hostinger the
  live feed and *every dashboard control* would have failed silently. `config/env.php` is
  now credential-free and tracked (it was gitignored, so a fresh deploy had no config at
  all); real values live in `config/env.local.php`. Security hardening verified by request:
  uploaded `.php` in `uploads/` → 403, `env.php`/`main.py`/`rois_*.json` → 403, HTTPS
  redirect enabled. Built the donation/retention page closing the §3c lifecycle. Added
  NSSM service scripts so nobody types `python main.py`. Fixed the Docker artifacts
  (Windows-absolute paths, missing `ROOM_ID`, baked-in stale baselines) and dropped
  `onnxruntime` — never imported, ~200 MB. See §1a and
  [DEPLOYMENT_GUIDE.md](file:///c:/xampp/htdocs/spotit/md_files/DEPLOYMENT_GUIDE.md).

- **2026-07-30 (Detection Pipeline Rewrite — Dual-Template Presence):** Replaced the
  template-match + Canny-edge-ratio + colour-diff presence gate in `main.py` with a
  dual-template test: each ROI is calibrated with both an "item here" crop and an inpainted
  "item gone" reconstruction of the surface beneath it, compared via MobileNetV2 feature-space
  cosine (classical chroma-histogram fallback). Adds an `OCCLUDED` hold state so hand occlusion
  can no longer raise or clear alarms, moves confirmation to wall-clock
  (`MISSING_CONFIRM_SEC = 1.0`), and adds `photos/detection_metrics.csv` instrumentation for
  tuning and Chapter 4 metrics. Verified 27/27 on the real ROOM_1 reference frame across
  present/removed/nudged/±25%-lighting/occluded cases for all three items. Also fixed five
  live defects found while reading the code — including an uncaught `TypeError` that killed
  the process whenever a left item appeared, and a variable clobber that meant Snapshot B had
  never once fired for registered ROIs. Full detail in
  [BUGS.md](file:///c:/xampp/htdocs/spotit/md_files/BUGS.md) and
  [BUGS.md §3](file:///c:/xampp/htdocs/spotit/md_files/BUGS.md).
  `main_classical_only.py` deliberately left unchanged as the A/B baseline.

- **2026-07-26 (Dynamic Room & Camera DB Integration + Crash-Proof Threading):** Added DB-driven camera credentials (`camera_ip`, `camera_user`, `camera_pass`) in `spotit_monitor_db.rooms`, created [`auth/get_room_camera.php`](file:///c:/xampp/htdocs/spotit/auth/get_room_camera.php), dynamic auto-switching RTSP reader in `main.py` (zero terminal restarts), thread-safe `VideoStream` locking to prevent C++ FFmpeg crashes, auto-heal logic in `save_rois.php` for `DESK` system room (prevents FK 1452), auto-sync of `detection_mode.json` on room navigation, and Docker deployment artifacts (`Dockerfile`, `docker-compose.yml`, `requirements.txt`, `FUTURE_SCALING_GUIDE.md`).

The historical change log documenting past development sessions and pivots has been moved to the end of [updates.md](file:///c:/xampp/htdocs/spotit/md_files/updates.md) to keep this rulebook focused. Refer to that file to trace past modifications.