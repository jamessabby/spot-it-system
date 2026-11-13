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
│   ├── detection_metrics.csv  ← per-decision instrumentation, Nov 2026 lab evidence (frozen, 11 cols)
│   ├── detection_metrics_v2.csv ← current instrumentation; adds frame_w/frame_h/stream/roi_res
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
│   └── migration_*.sql        ← eight migrations; four are ordered, see §7a
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
   **This rule is now actually enforced (2026-08-22).** It previously was not:
   the signup form offered a "staff" role and `signup_handler.php` accepted it,
   which — via the staff→admin normalisation above — made public signup a route
   into the admin dashboard. `signup_handler.php` now hardcodes `student` and
   **ignores** any submitted role, so putting a role picker back on the form
   cannot silently re-open it. If a panelist ever asks for staff self-signup,
   the normalisation is what has to change, not the whitelist.
   CAPTCHA and CSRF are also real as of the same date — both were UI-only
   before (see BUGS.md §1). They live in `config/security.php`, which
   `config/env.php` loads for every page and handler.
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
  records (detection logs, snapshots, claimant selfies) are purged. **Claim
  rows themselves are kept, marked `archived`** (changed 2026-08-21): who
  collected which item is an audit record the lab needs; the photograph is the
  personal data with a retention limit. Export the year's records first —
  `auth/export_records.php`, reachable from the admin dashboard. This is
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
2. **DB migrations**, if schema changed. All live in `services/`. The first four are
   ordered — never skip or reorder them:
   ```
   migration_workflow_audit.sql → migration_confidence.sql →
   migration_notifications.sql → migration_snapshot_b.sql
   ```
   The rest are independent, but **check which database each targets** — they are not all
   `spotit_monitor_db`:
   ```
   migration_room_camera_columns.sql → migration_room_enabled.sql   (monitor; in this order)
   migration_accuracy_trials.sql                                     (monitor)
   migration_email_verification.sql                                  (AUTH db)
   migration_claims_archive.sql                                      (LOST & FOUND db)
   ```
   All are safe to re-run (`IF NOT EXISTS`, or an idempotent `MODIFY COLUMN`).
   Full commands: DEPLOYMENT_GUIDE.md §2.

   **The recurring failure here is schema drift, not ordering.** `accuracy_trials` and
   the `rooms.camera_*` columns were each created by hand in phpMyAdmin and never written
   back, so they existed on the dev laptop and nowhere else; both would have fataled a
   fresh deploy. Both are fixed (2026-08-21). If you add a column through phpMyAdmin, add
   it to the schema file **in the same sitting** — see BUGS.md §1.
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

- **2026-11-13 (Sidebar simplification):** `pages/_sidebar.php`'s admin side had grown to
  22 links across 6 sections (Monitoring/Lost & Found/Management/My Activity/Community/
  Account) with no distinction between pages opened every shift and pages opened a few
  times a semester. Regrouped, same destinations/permissions, no page merges:
  - **Daily-use pages promoted to an unlabeled top group** (the most visually prominent
    position): Overview, Room Cameras, Alerts, Claiming Station.
  - **Lost & Found** keeps Event Log, Surrender Log, Recovered Items, Donation &
    Retention — day-to-day lost-and-found work, one level down.
  - **Setup & Management** (new, demoted group): Inventory Monitor, Camera Setup, Room
    Setup, Live Sandbox Test, Analytics & Audit, Users, System Logs — infrequent
    setup/admin tools that were previously mixed in at the same visual weight as Alerts.
  - Student sidebar already led with Lost & Found (Browse Items/My Claims/My Posts) —
    left that structure, just reordered (Browse Items, My Posts, My Claims) to put the
    two most-used pages first.
  - Admin's "My Posts" moved from its own one-item "My Activity" section into the shared
    Community section (next to Announcements/Forum, which it's actually part of).
  No `active_page` keys, hrefs, or role gates changed — verified by re-sweeping every
  sidebar link on both roles (correct URL + correct active-highlight) in the same
  browser-harness run as the fixes above.

- **2026-11-13 (Website polish pass — workflow/label fixes, pre-sidebar):** Walked both
  roles' navigation looking for dead buttons, broken links, misleading labels, and pages
  disagreeing about the same record. Confirmed and fixed, each a real bug not just a
  cosmetic nit:
  - **Notification deep links 404'd.** `auth/complete_claim.php`, `reject_claim.php`, and
    `create_announcement.php` stored `action_url` with a `pages/` prefix, but every
    consumer (`handleNotifClick` in `assets/js/spotit.js`) already runs from inside
    `/pages/`, so the link resolved to `/pages/pages/...` → 404. `complete_claim.php`'s
    `claim_approved` notification also pointed students at `claiming-station.php`, which
    is `spotit_require_admin()`-gated — even a working link would have bounced them
    straight back out. All three now point students at `lost-thread.php` /
    `announcements.php` with no prefix, matching the one call site that already did this
    correctly (`submit_claim.php`'s `new_claim` notification).
  - **Dead Claim button.** `pages/dashboard-student.php`'s "Recently Recovered" preview
    showed a Claim button for `pending_claim` items too, which `auth/submit_claim.php`
    always rejects (`status != 'recovered'`) — a generic "already claimed" error with no
    indication the button shouldn't have been there. Now shows a "Claim Pending" badge
    instead for that status, same pattern `pages/lost-thread.php` already used correctly.
  - **Room Setup status wording regressed.** `pages/room-setup.php` computed its own
    Active/Offline binary instead of using `ms_room_connectivity_status()` (the helper
    every other page already migrated to — see the 2026-08-21-era fix this helper itself
    documents), so a room paused by the mass-deviation auto-gate or mid-calibration showed
    the same red "Offline" as an actually disconnected camera. Now uses the shared helper.
  - **Alerts' "Mark Recovered" hid the registered-vs-unregistered outcome.** Clicking
    Recovered on CCTV Deviation/Confirmed Missing rows always said "Item marked as
    recovered," regardless of whether the item was registered lab equipment (never
    claimable) or an unregistered item (becomes claimable) — `dashboard-admin.php`'s event
    pop-up already showed this distinction via `ms_action_prompt()`; Alerts' own Verify
    modal had no equivalent. Added the same hint before the click and made the toast use
    `update_event_status.php`'s own `message` field after it.
  - **lost-thread.php disagreed with itself.** Filter-tab counts ("All Items (14)" etc.)
    were hardcoded literals next to a Thread Summary card computing the real counts from
    the same table — now both read from one set of real queries. The "Pending Claim"
    status-filter dropdown also always returned zero results (`"pending".includes(
    "pendingclaim")` is never true); fixed the comparison direction.
  - **inventory-monitor.php's filter pills showed wrong counts for up to 10s** on every
    load — `startAutoRefresh()` only started the 10s interval, never calling `refreshData()`
    immediately first. Now does both.
  - **forum.php's linked-detection status never changed.** Posts auto-created for a
    detection event hardcoded `'status' => 'Detected'` forever, even after staff recovered
    or dismissed it — the same staleness `auth/get_notifications.php` already re-derives
    for its own notifications. Now joins live state via `ms_detection_status_map()`, and
    the status color only reads alert-red while the event is still open. Also: `sharePost()`
    claimed "Link copied to clipboard" without copying anything (now really copies via
    `navigator.clipboard`), and `savePost()` claimed "saved to your bookmarks" when no
    bookmark feature exists anywhere in the app (now says so honestly).
  - **Settings' System tab and Profile's edit/password/deactivate actions were UI
    theater.** The System tab's 4 toggles and the 4 threshold inputs were already
    (correctly) excluded from `save_settings.php`'s whitelist, but nothing on the page
    *said* that — toggling one still showed the unsaved-changes bar and a "Preferences
    saved" success toast. Now shown disabled with a "Not Yet Supported" badge instead.
    `pages/profile.php` was worse: its own docblock claimed data came from
    `auth/get_profile.php`, which didn't exist — every account saw identical hardcoded
    fake values (ID "2021-00001", "Claims Submitted 2", "Account Age 14 days", ...), and
    Save/Change Password/Deactivate Account all faked success with zero backend calls
    (Deactivate's toast said "deactivation submitted" and just logged the user out,
    leaving the account fully active — actively misleading for a security action). Built
    the real `auth/get_profile.php` (read-only: real `users`/`user_profiles` data and real
    claims/posts/account-age counts, no new editable field or write path) and reused it on
    `settings.php`'s Account tab (which had the identical hardcoded-date problem — "Account
    Status: Active", "Registration Date: June 1, 2026" for every user). The three write
    actions are now honestly labeled "Not Yet Supported" rather than faked — building real
    `update_profile.php`/`change_password.php`/`deactivate_account.php` endpoints was
    treated as a new feature, out of scope for this polish pass, consistent with "don't
    build new settings features" extended to Profile's analogous case.

  Verified via the same isolated `spotit_test_*` DB + headless-browser harness as the Nov
  13 claiming-station fix (see below) — 116/116 real click-through checks pass, including
  this round's fixes alongside a full sidebar re-sweep (next entry). No production data,
  `detection_mode.json`, or the real snapshot folder were touched (verified and restored
  where the test run did touch `detection_mode.json` — `auth/update_event_status.php`
  writes it unconditionally regardless of which DB is active, same caveat as the Nov 11
  harness notes).

- **2026-11-13 (Integrated regression pass — Claiming Station could not complete a real
  student claim):** Full integrated regression across manual surrender, simulated CCTV
  events, and notifications/snapshots, run against isolated `spotit_test_*` DBs (`php -S`
  router + env-var DB overrides, same harness as the Nov 11/12 runs — see memory
  `isolated-e2e-harness`) plus a real headless-browser click-through (Edge, CDP, two
  isolated browser contexts for the admin and student sessions — no selenium/playwright
  install available this session, so a minimal CDP driver was written instead).
  34/34 server+DB checks and 19/19 real click-through checks passed after one fix:
  `pages/claiming-station.php`'s Pending Claims Queue fetched `claims.id` (the only id
  `auth/complete_claim.php` accepts) in its SQL but **dropped it before rendering**, and
  the queue rows had no click handler at all — staff had no way to open a specific pending
  claim. The page's only working "Complete Handoff" path (Step 1 → 4) sent `claim_id =
  recovery_id` instead, a different autoincrement sequence that only matches `claims.id`
  by coincidence (confirmed on live test data: recovery_id 21 vs its own claim's id 8).
  So a student-submitted claim could essentially never be completed through the actual
  UI — `complete_claim.php` would return "Claim not found." Fixed by keeping `claims.id`
  through to the row (`$pending[]` now carries `claim_id`, plus `room`/`contact`/`desc`
  columns added to the query) and adding `selectPendingClaim()`, wired to each queue row's
  onclick, which sets `selectedItemId` to the real claim id and prefills Step 2 from the
  student's own submission. The old Step-1 "browse available items" path is left in place
  for visibility, but `completeClaim()` now refuses to submit for an item selected that way
  (`selectedViaClaim` flag) with a clear toast instead of a dead-end "Claim not found" at
  Step 4 — confirmed blocked in the browser suite. No backend file changed; `complete_claim.php`,
  `submit_claim.php`, `record_surrender.php`, and the registered-vs-unregistered event-kind
  logic (`auth/event_kinds.php`) all verified correct as-is (CCTV-simulated registered
  equipment stays out of Lost & Found; an unregistered item becomes claimable exactly once;
  two detections on the same zone no longer collide on disk, confirming the Nov 12 snapshot
  fix still holds). `detection_mode.json` was touched by the test run (`update_event_status.php`
  writes to it unconditionally, not just in local mode) and restored via `git checkout` —
  worth knowing if a future isolated test run needs to snapshot/restore it deliberately.

- **2026-11-13 (Phase 2C — My Posts made real; no CCTV involved):** `pages/my-posts.php`'s
  listing and empty state were already real (queried `forum_posts` by session `user_id`), but
  every action button on the page was fake: `submitPost()` never called any endpoint and just
  showed a success toast, `editPost()` said "coming soon," and `deletePost()` faded the DOM row
  and showed a toast without touching the database. Two endpoints didn't exist anywhere in the
  app (not even on `pages/forum.php`, whose own `removePost()`/`removeComment()` are the same
  kind of fake and are **not** touched here — out of this phase's scope): `auth/forum_delete_post.php`
  (soft-deletes via the existing `forum_posts.is_removed` column) and `auth/forum_update_post.php`
  (updates title/content/category/flair). Both check `user_id` ownership (403 on mismatch) and
  refuse an admin-locked thread (`is_locked`) even for its own author, mirroring the lock check
  `forum_submit_comment.php` already does. `my-posts.php`'s Edit button now reuses the existing
  "New Post" modal pre-filled from a `postsData` map embedded in the page (no new UI, per scope);
  Save/Submit routes to update-vs-create based on whether an edit is in progress, then reloads to
  re-render from the DB. Verified over HTTP only (no browser) against the `spotit_test_*` DB clones
  via a scratch `php -S` router with a `/__login` session shortcut (session-only; no CSRF/Turnstile,
  which are UI concerns unrelated to this fix) — see [[isolated-e2e-harness]] in memory. Confirmed:
  honest empty state for two fresh accounts; a created post appears only on its author's page;
  edit persists across a reload; delete is a soft-delete (`is_removed=1`, row kept) and the page
  returns to the empty state; a locked thread blocks edit/delete for its own author; and a second
  account's direct POST to update/delete someone else's post is rejected 403 and leaves the row
  unchanged. The actual on-screen click-through (opening the modal, clicking Edit/Delete in a
  real browser) was **not** run — Selenium/Playwright weren't available in this session and
  installing one was declined by the permission layer — so that layer is UNVERIFIED, though the
  rendered HTML was confirmed to carry the right `onclick="editPost(<id>)"` /
  `deletePost(<id>)` wiring.

- **2026-11-12 (Snapshot A filenames made unique per event — the "unique per-event
  names" follow-up noted below):** `auth/ingest_detection.php` used to "preserve" the
  filename main.py sent (`{label}_registered_N.jpg` / `{label}_snapshot_A.jpg`) verbatim
  whenever it matched a safe-characters regex — which it always did, since main.py always
  sends exactly that shape. Because main.py reuses the same name for every event on a given
  ROI, a later event silently overwrote an earlier event's evidence file on disk while the
  DB still pointed a detection row at it (this is the "Evidence caveat" below). Fixed in
  `auth/snapshot_naming.php` (`spotit_unique_snapshot_filename()`): every upload now gets
  `{date}_{room}_{zone}_a_<uniqid>.jpg`, the client-supplied name discarded except for its
  extension. **main.py's own local capture naming is deliberately untouched** — it still
  reuses the same local filename for its progression-sequence lookup
  (`registered_seq_count`), and that is fine: this fix only changes what the file is
  persisted under server-side, which is the copy that actually matters for the evidence
  trail. `uploads/.htaccess`'s evidence-deny `<FilesMatch>` gained `_a_[a-f0-9]+\.` (the new
  shape) and `_snapshot_[AB]\.` (a real gap found while fixing this: legacy tier-2/
  unregistered-item Snapshot A/B files — `{label}_snapshot_A.jpg` — never matched the old
  `_registered_`-only pattern and were served publicly). Snapshot B's own naming
  (`{date}_{detection_id}_b_<uniqid>.jpg`, in `ingest_detection.php`'s `snapshot_b` action)
  was already unique per event and is unchanged. Verified against `spotit_test_*` DBs (the
  isolated e2e harness, `ZZT1` room): two detections for the same room+zone, with the first
  resolved before the second fires, now produce two distinct files, and the first file's
  original pixels are provably still intact (read back and diffed) after the second upload —
  reproducing and then disproving the collision the old code had. 21/21 new checks in
  `tests/test_snapshot_names.php`; existing `test_event_kinds.php` (51), `test_feed_state.php`
  (13) and `check_inline_scripts.php` unaffected. Historical rows from before this fix still
  collide on disk exactly as the Evidence caveat describes — this fix prevents new collisions,
  it does not reconstruct lost history.

- **2026-11-12 (CCTV snapshot evidence viewer; evidence files no longer public):**
  - `auth/snapshot.php?f=<file>` is the only way to fetch detection snapshots and claim
    selfies: session required; admin/staff any file; students only the snapshot of an item
    currently claimable; bare file names only, realpath confined to `uploads/snapshots/`.
    `uploads/.htaccess` denies direct requests for `*_registered_*`, Snapshot B `*_<id>_b_*`
    and `claim_*` (placed AFTER the media allowlist — Apache applies the last matching
    `<FilesMatch>`, which is why a first attempt placed earlier had no effect). Live, clean
    and `*_baseline.jpg` frames stay public (live viewer / ROI editor).
    `ms_snapshot_url()` / `ms_snapshot_info()` in `service_bootstrap.php`; every page and
    endpoint that showed a snapshot now goes through them (dashboard, Alerts, Inventory,
    Lost & Found items, Desk Sandbox, donation).
  - Admin dashboard pop-up: the decorative SVG "camera view" is replaced by the real
    Snapshot A / B with room, label, status and timestamps; thumbnails open a shared
    full-resolution lightbox (`spotitOpenImage()` in `spotit.js`); a missing B reads
    "No follow-up snapshot available". Event-log rows show a real Snapshot A thumbnail.
    Alerts' snapshot modal reuses the lightbox and wording.
  - **Evidence caveat, surfaced in the UI:** the detector names files
    `{label}_registered_{n}.jpg` and reuses the name, so only 6 of the 47 Nov 3 rows still
    point at their own event's pixels; 41 point at a file rewritten by a later event (last
    writes 14:47 MLH305, 15:35 MLH301). The viewer shows the file's write time and a note
    when it post-dates the event. Capture naming was NOT changed (frozen for the lab test);
    unique per-event names are the follow-up.
  - Found while testing: `inventory-monitor.php` called `_logEventColor()`, a private helper
    of `get_inventory_status.php` it never loads, so its whole data load threw and the page
    showed "No detection log records found" (the catch was silent; it now logs). Single
    definition `ms_log_event_color()` in `auth/event_kinds.php`.

- **2026-11-11 (Final demo stabilisation — end-to-end acceptance on an isolated environment):**
  Verified the complete workflow with REAL writes on `spotit_test_*` databases (structure
  cloned from the live DBs, seeded with the two rooms + a `ZZT1` test room), served by a
  separate `php -S` process whose environment overrides the `*_DB_NAME` values — Apache and
  the real DBs were never touched. 48 API/DB/page checks + 23 headless-Chrome checks on the
  real pages pass. Fixed on the way (all small, all verified by re-running the suites):
  - `complete_claim.php` printed `Undefined variable $receipt` before its JSON (computed
    after the notification that quoted it) → the Claiming Station could not parse a
    successful hand-off. Receipt now computed first.
  - `submit_claim.php` stored `claim_id = NULL` on the staff "new claim" notification.
  - `get_notifications.php`: `type=a,b,c` is now an `IN` list (the notifications page's
    category pills sent a comma list that matched nothing); every notification about a
    detection now carries the event's CURRENT state (`event_status`, `event_status_label`,
    `event_closed`, `event_missing`) and a `link` to `dashboard-admin.php?event=<id>`, which
    opens that event's pop-up (`openLinkedEvent()`); ties on `created_at` ordered by id.
  - Admin dashboard: bell panel never opened (inline-styled panel toggled a class that only
    `.notif-panel` has; `#notifPanelTs` missing → TypeError; "Mark all read" called an
    undefined `markAllRead()`); validation buttons (`submitValidation`) showed a toast
    without saving — now POST `validate_detection.php`; KPI cards are links (WEB-04) and
    "Pending Validation" counts only OPEN events awaiting review (WEB-14; it used to include
    41 already-recovered events). `ms_dashboard_kpis()` in `services/monitoring/db.php`.
  - `inventory-monitor.php`: `#alerts` handler ran before `let currentFilter` (TDZ error).
  - `my-posts.php` (WEB-08): missing `.stat-grid` wrapper closed `.page-body` early so only
    the stat cards scrolled; "Post History" was hardcoded, now the user's own months.
  - `spotit.js`: `markAllNotifsRead()` refreshes the sidebar badge immediately.
  - Room Monitor: second dropdown = room-specific equipment categories (DISPLAY ONLY — the
    detector keeps monitoring every ROI; verified by replay: only the selected label's box
    is drawn and it still fires MISSING). `auth/room_categories.php` stores categories and
    per-ROI assignments in `room_categories.json` (never in the ROI files); the editor is a
    modal listing the room's saved ROIs (`registered_lab_items` ∪ `rois_<ROOM>.json`), no
    `prompt()`. `display_filter.labels` is an exact list; `main.py` prefers it over keywords.
  - YOLO ROI suggestions: cross-class duplicates (tv + laptop on one monitor) deduped.
  Not verified tonight (needs hardware): live camera disconnect/reconnect (WEB-07 UI states
  are unit-tested and the no-camera run passed), the real Turnstile login, and the lab
  camera password stored for MLH301/MLH305. Startup/rollback: `md_files/LAB_STARTUP_2026-11-12.md`.

- **2026-11-11 (Nov 12 lab readiness — UNCOMMITTED at time of writing):**
  - **YOLO** (`yolo_detector.py`, `models/yolox_s.onnx` = OpenCV Model Zoo YOLOX-S, COCO-80,
    Apache-2.0, run via cv2.dnn — no PyTorch). Names object classes → beyond §8's
    presence-only gate: **experimental, needs adviser sign-off**. Uses: (1) `pipeline=yolo`
    mode (expected class on ROI → PRESENT, person over ROI → OCCLUDED, else ABSENT; ROIs YOLO
    cannot see on their own baseline, or with no COCO class — oscilloscopes, power supplies —
    fall back to the MobileNetV2 gatekeeper and the console says so); (2) **person guard**
    (`SPOTIT_PERSON_GUARD`, on; gatekeeper + yolo modes): ABSENT held as OCCLUDED while a
    person covers ≥20% of the ROI, and the stuck-occlusion escape cannot fire — evidence:
    obstruction trials 0.6–1.0 person coverage throughout, real removals only brief bursts;
    (3) left-item person filter; (4) ROI suggestions.
  - **Mode selector**: `pipeline` ∈ classical | gatekeeper | yolo — Room Monitor dropdown,
    Desk Sandbox toggle, whitelisted in `toggle_detection_mode.php`.
  - **ROI suggestions**: `auth/suggest_rois.php` → `runtime/suggest_{ROOM}.request.json` →
    `main.py` runs tiled YOLO on the FULL-res frame → `runtime/suggest_{ROOM}.result.json` in
    detection-frame coordinates → Camera Setup ROI editor (dashed orange, review, normal
    Save). Refused on resolution mismatch. Local mode only (shared runtime/ folder).
  - **Texture check limited** (`SPOTIT_TEXTURE_KEYWORDS`, default keyboard/oscilloscope/
    power supply): Fix 3A had broken mouse removals (lifted mouse leaves pad edges) —
    mouse2 removal caught again on the recordings.
  - **Unregistered branch rewritten**: registered ROIs masked out; YOLO person filter;
    IoU tracking so `objectN` ids/timers are stable; "taken" (Snapshot B) only after 1.5 s
    gone; replay-aware clock (was wall-clock, so replays of this branch were meaningless).
  - Also: `main.py` puts its own folder on `sys.path` (under `python -I` the YOLO import
    silently failed); FileStream `__init__` repaired (WEB-07 edit had broken replay mode).

- **2026-11-11 (Admin dashboard event pop-up made real; stage label fixed):**
  - The Admin Dashboard event pop-up was entirely mock: rows passed a room code into a
    hardcoded `modalData` object of three invented June events, and Recovered / Dismiss /
    Confirm only showed a toast. It now loads the real event from `get_detections.php?id=`
    (new, additive filter) and posts to `update_event_status.php` — the same authenticated
    endpoint the Alerts page uses — after a confirmation whose wording comes from
    `ms_action_prompt()` (equipment: "will NOT be added to Lost & Found"; unregistered:
    "taken into custody… becomes claimable"). Closed events disable the buttons.
  - Event-log tabs: counts were hardcoded ("All (11)…") and tabs did not filter. They now
    count and filter the latest 20 real events (`ms_status_bucket()`).
  - `get_detections.php` `stage` ignored the status, so 41 recovered events showed
    "Confirmed Missing". `ms_detection_stage()` now takes the status (and whether staff set
    it); timer escalations read "Escalated — awaiting staff check". `stage` keys unchanged.
  - `ms_detection_stage()` now has ONE definition, in `auth/event_kinds.php`;
    `services/monitoring/db.php` and `service_bootstrap.php` load it instead of each
    keeping a copy (the two copies had already drifted).
  - Still mock in the same pop-up: the validation buttons (`submitValidation`) and the
    snapshot drawing.
  - **Follow-up fix (browser test failed):** the dashboard's main inline `<script>` had no
    closing tag (since at least commit 2ab5707), so the browser parsed the next HTML comment
    as JavaScript, threw a SyntaxError and discarded the entire block — no inline dashboard
    function ever existed. Added the missing `</script>`. Side effect: the onboarding tour
    config in that region now loads too, so the first-time tour may appear once.
    `tests/check_inline_scripts.php` now fails any page with an unclosed/nested `<script>`.
    PHP lint cannot catch this class of bug — verify page JS in a browser.

- **2026-11-11 (Registered equipment kept out of lost & found):** `update_event_status.php`
  used to create a student-claimable `recovered_items` row for ANY event staff marked
  *recovered* — including lab keyboards and oscilloscopes. Now (`auth/event_kinds.php`):
  - An event is **registered equipment** when its room has a `registered_lab_items` row with
    `roi_label` = the event's zone — any tier (MLH301's equipment is tier2). Otherwise it is an
    **unregistered item** (`objectN` or a relabelled left item).
  - Staff "recovered" on equipment: status still `recovered` (API unchanged), but no
    lost-and-found row, no student notification; logged as returned to the lab.
  - Staff "recovered" on an unregistered item = custody confirmation → claimable (once only).
  - A `confirmed_missing` with no `verified_by` was set by the 30/60-min timer, not a person:
    the admin event log and `get_detections.php` (`staff_confirmed`, `status_label`) show it
    as "Escalated — awaiting staff check". Other pages still say "Confirmed Missing".
  - **No migration.** Known gap: kind is derived from the *current* ROI list, so an event for
    an ROI later deleted would read as unregistered. Storing the kind at ingest needs a column.
  - **Demo note:** DESK's registered phone/laptop/tumbler are now equipment too — demo the
    claiming flow with an unregistered left item (`objectN`) or a manual surrender.
  - Tests: `C:\xampp\php\php.exe tests/test_event_kinds.php` (31 pure-function checks).

- **2026-11-11 (Fix 3 — texture check + LOW VISIBILITY, experimental):** In `main.py`.
  - **3A texture check (`SPOTIT_TEXTURE_GATE`, on):** PRESENT now also needs the item's
    Canny edge density to be ≥ 0.75 of its baseline value. Reason: MobileNetV2 scored bare
    desk 0.79–0.88 against keyboard2's template (RD-01 flicker). Safeguard: the
    stuck-occlusion escape only fires once texture is < 0.50 of baseline, so a resting hand
    cannot time out into MISSING.
  - **3B LOW VISIBILITY (`SPOTIT_LOW_VIS`, on):** ROIs whose here/gone templates are ≥ 0.75
    similar at calibration (on the lab data: only mouse1, 0.79–0.89) are shown grey, never
    alert, and are left out of `live_roi_state.json`.
  - **Offline evaluation** (main.py's own code on the Nov 3 screen recordings — not raw
    footage): genuine removals caught 8/9 (was 9/9 — mouse2 at 14:39:27 lost; it was
    borderline in both versions), obstruction false alarms 1/2 (was 2/2 — oscilloscope_mid
    fixed, keyboard1 14:06:30 remains), no alert raised that the old code did not.
  - **Not done:** 3C (longer confirmation) — on the lab protocol, real removals produced
    unbroken ABSENT runs of 1.1–3.9 s vs 4.6 s for the obstruction, so a longer threshold
    would lose removals. `MISSING_CONFIRM_SEC` stays 1.0.

- **2026-11-11 (Fix 2 — obstruction handling, controlled experiment):** Two changes in `main.py`:
  - **2a, on:** OCCLUDED now *resets* the absence timer, so MISSING needs an unbroken
    ABSENT streak of `MISSING_CONFIRM_SEC` (still 1.0 s). Previously OCCLUDED only paused it,
    so flicker during an obstruction added up (MLH305 keyboard2 14:06:25 → false MISSING).
  - **2b, ring guard, OFF by default** (`SPOTIT_RING_GUARD=1` enables): an ABSENT verdict is
    held as OCCLUDED while a ring around the ROI differs from a slow (~30 s) running-average
    background; with it on, the stuck-occlusion escape also requires a clear ring instead of
    in-box stillness. `ring_fg` and `ring_guard` are logged in `detection_metrics_v2.csv`
    either way.
  - **Why off:** offline evaluation on the Nov 3 recordings (8 analyst-labelled removals,
    2 obstructions): old rule 8/8 removals, 2/2 obstruction false alarms; 2a alone 7/8, 1/2;
    2a + guard at 0.10 only 3/8 removals — in the lab protocol the tester stays at the desk,
    so the ring stays busy for the whole removal. Synthetic replay confirms the guard's
    mechanism (static occluder never → MISSING; clean removal still → MISSING) but that is
    not lab evidence. The remaining obstruction false alarm (keyboard1 14:06:29) is 7 s of
    *direct* ABSENT — a presence-model problem no timer fixes.
  - Evaluation scripts were run from a scratchpad, not committed; the method (clock
    alignment, ORB registration of screen recordings, overlay masking) is in memory notes.

- **2026-11-11 (Calibration resolution guard — Fix 1 of the RD investigation):** `main.py`
  used to "fix" a live/baseline size mismatch by stretching the baseline photo, while the
  ROI boxes and presence templates stayed at the old resolution — every verdict afterwards
  compared the wrong pixels, silently. Triggered by the Tapo delivering 1920×1080 at home
  against 2304×1296 baselines (halved: 960×540 vs 1152×648); also reachable through the
  silent `/stream2` fallback. Now:
  - `check_calibration()` holds detection (no verdicts, timers frozen, `live_roi_state.json`
    not rewritten) and shows **RECALIBRATION REQUIRED** whenever the live frame, baseline
    and ROI coordinate space disagree. **No automatic ROI scaling, by design** — a different
    resolution can also be a different crop, and nothing here can prove alignment.
    Recapturing the baseline and redrawing ROIs clears it.
  - `save_rois.php` / `register_roi.py` stamp each ROI with `frame_w`/`frame_h` (the size
    of the baseline image the editor drew on). Unstamped files are **legacy**: accepted only
    if baseline == live and every box fits, and logged as `roi_res=legacy`.
  - ROIs outside the frame are skipped individually (never judged on a truncated or
    wrapped crop); in a legacy file they invalidate the whole file.
  - `VideoStream.stream_name` records stream1/stream2; metrics moved to
    `photos/detection_metrics_v2.csv` with `frame_w,frame_h,stream,roi_res` columns. The old
    CSV is untouched as the Nov 2026 evidence.
  - `main_classical_only.py` was **not** changed (frozen A/B baseline) and still has the old
    stretch-on-mismatch behaviour — replay it only on clips that match its baseline.

- **2026-08-21 (Room enable/disable + one-camera-one-room; claims archived not deleted;
  escalation actually scheduled):** Four structural changes worth knowing before touching
  the affected code.

  **`rooms.is_enabled` is a NEW flag and is not `is_active`.** There is one physical Tapo
  and several candidate rooms, so staff need to move the camera between rooms during
  testing. `ms_camera_ip_conflict()` (`auth/service_bootstrap.php`) now refuses to save a
  `camera_ip` that an *enabled* room already holds, naming the conflicting room; disabling
  a room releases that claim while keeping its baseline, registered items and detection
  history. `is_active` was deliberately **not** reused: it is the soft-delete flag that
  every listing query filters on and that `add_room.php` treats as "a deleted room being
  re-created", so disabling through it would have hidden the room from the only page that
  can re-enable it. Rule of thumb: `is_active` = does the row exist; `is_enabled` = is the
  room in service. `room-setup.php` lists disabled rooms (it has the Enable button);
  every other room list filters them out. `get_room_camera.php` returns
  `configured:false` for a disabled room, which is what actually stops `main.py` — note
  that `get_detector_config.php` reads no database at all and gets its room from
  `detection_mode.json`, so the gate could not go there.

  **Claim rows are archived, not deleted, by the donation purge.** `process_donation.php`
  used to `DELETE FROM claims`, which destroyed the handoff record in order to delete the
  selfie attached to it. Those are different things with different retention needs, so it
  now sets `status='archived'`, `archived_at=NOW()`, `webcam_snapshot=NULL` and still
  unlinks the image file. `claims.status` gained `'archived'`
  (`migration_claims_archive.sql`) — any code matching on that enum needs to expect it.
  `auth/export_records.php` is the companion: admin-only CSV of confirmed claimants and
  unclaimed/donated items, optional `?year=`, **confirmed outcomes only** — raw
  `pending`/`potential` detections are excluded on purpose, because exporting the system's
  unverified guesses into a document the lab treats as a record would misrepresent what
  the system established. CSV rather than XLSX because `ZipArchive` is not enabled in this
  PHP build.

  **Auto-escalation now has a caller.** `auth/escalate_detections.php` was correct but
  nothing ran it on a timer, so the 30/60-minute stages existed only in the manuscript —
  masked by `ms_detection_stage()`, which *displays* the stage from elapsed time whether
  or not the DB status ever moved. Local: NSSM service via
  `deploy/install-escalation-service.ps1`. Hosted: control-panel cron, DEPLOYMENT_GUIDE.md
  §10a. **Exactly one, never both.** Its HTTP path also read `getenv('SPOTIT_CRON_KEY')`
  directly, bypassing `env.php`'s resolution chain and therefore ignoring the real key in
  `env.local.php`; it now uses the constant and returns 503 while the key is still the
  placeholder.

  **Schema drift is the recurring bug here, not any one column.** `rooms.camera_*` and
  `accuracy_trials` were both created by hand in phpMyAdmin and never written back, so
  they existed on the dev laptop and nowhere else; each would have fataled a fresh deploy
  with a different symptom. Both now have schema entries and migrations. A third
  (`claims.read_at`) is logged in BUGS.md §1 and left alone because nothing reads it.
  **If you add a column through phpMyAdmin, add it to the schema file in the same
  sitting.**

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