# S.P.O.T.-IT — Bug Registry & Resolution History

This file tracks active bugs, pending issues, and logs past bug diagnostic details with their root causes and resolutions. Refer to [CLAUDE.md](file:///c:/xampp/htdocs/spotit/CLAUDE.md) for core architecture rules and [updates.md](file:///c:/xampp/htdocs/spotit/md_files/updates.md) for the roadmap status.

> **2026-08-16 — `unfixable_limitations.md` was merged into this file** as §3
> ("Detection Pipeline Limitations"). That file is gone; anything that used to cite
> `unfixable_limitations.md §N` now means **BUGS.md §3.N**. The merge was verbatim except
> for the threshold values, which had drifted from the code and are now corrected against
> `main.py` — see the note at the top of §3.

---

## 1. Active / Immediate Fix Targets

These are outstanding bugs, issues, or bootstrap items that need to be resolved. Check them off as you fix them.

- [ ] **Test data initialization:** Need real room records inserted into `spotit_monitor_db.rooms` and `registered_lab_items` beyond the temporary `TESTROOM` desk setup before Table 3.5 scenarios can be run against actual CEAT laboratory rooms.
- [ ] **First-admin bootstrap setup:** Admin accounts can only be provisioned by an existing admin—but no admin exists on a fresh install.
  - **Resolution path:** Need a one-time SQL seed directly in `spotit_auth_db.users`. Check `services/auth/schema.sql` and `login_handler.php` for the hash format.
- [x] **`accuracy_trials` table has no schema file — a fresh deploy will 500.**
  *(Fixed 2026-08-21.)* Four call sites read or write it (`auth/log_sandbox_trial.php`,
  `auth/get_desk_status.php`, `auth/get_room_status.php`, `auth/reset_system_state.php`,
  plus the metrics panel in `pages/desk-sandbox.php`) but no `CREATE TABLE` existed
  anywhere in the repo — it had been made by hand in phpMyAdmin, so only this laptop had
  it. On Hostinger the sandbox metrics panel and both room-status endpoints would fatal.
  - **Resolution:** `services/migration_accuracy_trials.sql`. The definition was taken
    from `SHOW CREATE TABLE accuracy_trials` on the working local install rather than
    inferred from the INSERT, then **verified by building the table from the migration in
    a scratch database and diffing the columns against the live one — identical**. That
    matters because the recorded trials behind the Chapter 4 figures were captured on the
    local table; a migrated deploy that differed in, say, `notes` being `text` instead of
    `varchar(300)` would be a silent divergence. Added to the migration order in
    DEPLOYMENT_GUIDE.md §2 and CLAUDE.md §7a.
- [ ] **`recalibrations` is deleted but never created — dead code.**
  `auth/reset_room_state.php:30` and `auth/reset_system_state.php:60` both run
  `DELETE FROM recalibrations`, each inside a `try/catch (Throwable)` that swallows the
  error, so the missing table has never been noticed. Nothing inserts into it, selects
  from it, or defines it. Either build the table or drop the two statements — leaving a
  silenced query against a non-existent table hides real failures on the same code path.
- [x] **`spotit_monitor_db.rooms` was missing three columns from its schema file.**
  *(Fixed 2026-08-21.)* `camera_ip` / `camera_user` / `camera_pass` were added by hand in
  phpMyAdmin during the 2026-07-26 dynamic-camera work and never written back into
  `services/monitoring/schema.sql`, while six handlers already bound them
  (`add_room.php`, `edit_room.php`, `capture_frame.php`, `get_room_camera.php`,
  `tapo_stream.php`, `save_rois.php`). A fresh clone would have fataled on the first room
  creation. Same failure mode as `accuracy_trials` above, same cause: **the schema file
  was never the thing being read.** Whatever produced the Fig 3.10 ERD read the live
  database or the PHP, so the drift stayed invisible.
  - **Resolution:** columns added to `services/monitoring/schema.sql`, plus
    `services/migration_room_camera_columns.sql` for installs already created from the old
    file. Both verified against the live table.
  - **Watch for this class of bug.** Any column added through phpMyAdmin is invisible to
    every fresh deploy. If you add one, add it to the schema file in the same sitting.

- [ ] **`spotit_lf_db.claims.read_at` exists live but is in no schema file — and nothing
  reads it.** Found 2026-08-21 while auditing the same drift class as above. Unlike the
  two fixed entries this one is harmless: a repo-wide grep finds no PHP that references
  `claims.read_at`, so a fresh deploy without it breaks nothing. Left alone deliberately —
  adding it to `schema.sql` would enshrine a dead column.
  - **Decide before submission:** either drop it from the live DB so the schema file is
    the whole truth, or document why it is there. Right now the live DB and the repo
    disagree, and that is exactly what made the two bugs above so expensive to find.

- [x] **Auto-escalation was never scheduled — every detection sat at `pending` forever.**
  *(Fixed 2026-08-21.)* `auth/escalate_detections.php` was written correctly and its logic
  worked when run by hand, but **nothing invoked it on a timer** on either machine. The
  30-minute "Potentially Lost" and 60-minute "Confirmed Missing" stages therefore existed
  only in the manuscript: in the running system a detection stayed `pending` until a human
  clicked something. Easy to miss because the dashboard's `ms_detection_stage()` helper
  *displays* the stage a detection ought to be in from elapsed time, so the UI looked
  right while the persisted `status` never moved.
  - **Resolution:** two schedulers, one per environment.
    Local/Windows — `deploy/install-escalation-service.ps1` registers an NSSM service
    (`SpotItEscalation`) running `deploy/escalation-runner.ps1` every 5 min via PHP CLI,
    mirroring the detector service. Hosted — Hostinger control-panel cron fetching
    `escalate_detections.php?cron_key=<SPOTIT_CRON_KEY>`, documented in
    DEPLOYMENT_GUIDE.md §10a. **Run exactly one** — both at once double every notification.
  - **Also fixed while in there:** the HTTP path compared the supplied key against
    `getenv('SPOTIT_CRON_KEY')`, bypassing `config/env.php`'s resolution chain — so a key
    set in `env.local.php` (the only place it is actually set on Hostinger) was ignored
    and the comparison ran against the `CHANGE_ME_CRON_KEY` placeholder. Now uses the
    `SPOTIT_CRON_KEY` constant, and refuses over HTTP with 503 while the key is still that
    placeholder. CLI is unaffected.
  - **How to confirm it is running:** `nssm status SpotItEscalation` → `SERVICE_RUNNING`,
    and a timestamped `Escalation run complete:` line every 5 minutes in
    `logs/escalation.log`. On the host, recent `auto_escalation` rows in `monitoring_logs`.
    End-to-end test that does not need a real 30-minute wait: TESTING_GUIDE.md Part 5a.
  - **Verified 2026-08-21:** two detections seeded at 35 and 70 minutes old escalated to
    `potential` and `confirmed_missing` respectively in a single run, with three
    `auto_escalation` log rows and one notification per admin/staff user. Test rows removed.

- [x] **Double snapshot logic in pipeline:** `main.py` needs to implement the secondary camera snapshot (Snapshot B) when an item's empty ROI is physically interacted with or shifted while in the RED/MISSING state.

### Automatic Movement Snapshot Purge Endpoint (`auth/clear_snapshots.php`)
*   **Symptom:** Generating transient movement/detection snapshot images (`*_registered_*.jpg`, `*_snapshot_A.jpg`, `*_snapshot_B.jpg`) cluttered `uploads/snapshots/` and required manual deletion from VS Code.
*   **Root Cause:** No dedicated web service existed to clean up transient movement snapshots while leaving core stream/calibration images (`live_*.jpg`, `clean_*.jpg`, `*_baseline.jpg`) intact.
*   **Resolution:** Created `auth/clear_snapshots.php` and added the **`🧹 Clear Snapshots`** toolbar button to `pages/camera-setup.php` and `pages/room-monitor.php`.

### Human Walk-By False Alarms & Rapid Checking Snapshots
*   **Symptom:** A person walking past an ROI box caused rapid yellow/checking states and created transient snapshot files on disk.
*   **Root Cause:**
    1. Progression snapshots were being written during the yellow/checking phase before an alert was confirmed RED.
    2. Scene-wide human motion ($> 6\%$) was not suppressing the per-ROI missing counter during active pass-by frames.
*   **Resolution:** Restricted snapshot generation strictly to confirmed RED missing events, added human walk-by scene motion suppression (`motion_pct > 0.06`), and increased `CONSISTENCY_FRAMES` to 30 (~1.0 second of unbroken absence).

### False Positives / Red Boxes when Item Returned or Shifted — **SUPERSEDED 2026-07-30**
> This entry describes tuning of the template-match pipeline, which was **removed** from
> `main.py` on 2026-07-30. `PRESENCE_SCORE_THRESHOLD` and `PAD` no longer exist there. The
> underlying problem is now solved structurally — see `unfixable_limitations.md` §1. The
> template-match pipeline survives unmodified in `main_classical_only.py` as the A/B baseline.
> Note also that the values recorded here ($0.62$ / `PAD = 45`) had already drifted from the
> code, which by commit `6c47a1d` actually ran $0.52$ / `PAD = min(15, w//3, h//3)`.

*   **Symptom:** Placing a dark item (e.g. computer mouse) back on the desk required tweaking/nudging to turn green, or lifting an item left the box green.
*   **Root Cause:** Template matching threshold `PRESENCE_SCORE_THRESHOLD` was set too low ($0.38$), causing bare wood table background to match baseline crops containing table wood behind the item.
*   **Resolution:** Calibrated `PRESENCE_SCORE_THRESHOLD = 0.62` and expanded the search window (`PAD = 45`), ensuring item removal evaluates as RED (`MISSING`) instantly while returning/shifting items evaluates as GREEN (`NORMAL`).

## 2. Past Diagnosed Bugs & Root Causes

Refer to this log so you do not waste time re-investigating previously solved problems.

### 2026-08-16 — One DHCP lease change broke the system in eight places at once

*   **Symptom:** after a factory reset moved the DESK camera from `192.168.18.11` to `.14`,
    the sandbox failed in several unrelated-looking ways at the same time: the live MJPEG feed
    went to STANDBY, "Capture Baseline" timed out, ROI saving worked but monitored nothing,
    and `main.py` sat retrying the RTSP connect. Each symptom pointed at a different subsystem.
*   **Root cause:** the camera address was **pasted into 8 separate places** — three spots in
    `main.py`, one in `main_classical_only.py`, plus `auth/capture_frame.php`,
    `auth/tapo_stream.php`, `auth/save_rois.php` and `pages/desk-sandbox.php`. There was no
    single source of truth because, unlike production rooms, the DESK sandbox has no row in
    `spotit_monitor_db.rooms` to read `camera_ip` from. Nothing was wrong with any individual
    file; the defect was that the same fact was stored eight times.
*   **Resolution:** defined once — `DESK_CAMERA_IP` / `_USER` / `_PASS` / `_RTSP` in
    `config/env.php` for the PHP side, and `SPOTIT_CAMERA_IP` / `_USER` / `_PASS` environment
    variables (same defaults) in both Python scripts. Production rooms are untouched and still
    read their credentials from the `rooms` table via `auth/get_room_camera.php`.
*   **Note for next time:** the real fix is a DHCP reservation on the router (or a static IP in
    the Tapo app). Centralising the constant makes the change cheap; it does not stop the
    address from moving. Also: existing `rooms` rows keep whatever IP they were seeded with —
    ROOM_1's `camera_ip` still needs updating in the DB separately.

### 2026-08-16 — Removals parked in OCCLUDED forever (inpainting carries no signal on large items)

*   **Symptom:** an item lifted off the desk stayed **blue (OCCLUDED)** indefinitely instead of
    escalating to RED. The item could be carried out of the room and the box never changed.
*   **Root cause:** the dual-template test can only reach `ABSENT` when the inpainted
    "item gone" template wins, and `cv2.inpaint` is unreliable over a large mask — it returns a
    diffused smear resembling neither the item nor the real bare surface. Measured on the
    sandbox laptop, `sim_empty` read **0.39 with the item present and 0.41 with it removed**:
    no information at all. `sim_present` behaved correctly the whole time (0.86 → 0.46), so the
    ROI satisfied "not the item" but never "is the bare surface", which is exactly the
    definition of OCCLUDED.
*   **Resolution:** added `OCCLUDED_MAX_SEC = 5.0` and `OCCLUDED_STILL_MOTION = 0.02`. An ROI
    that has been continuously OCCLUDED **and shown no motion of its own** for 5 s resolves to
    ABSENT. **The motion condition is load-bearing** — a plain timeout would eventually alarm on
    a hand resting motionless on an item, which is the precise false positive §3.3 exists to
    prevent. Skin at this resolution is never perfectly still, so any flicker above the
    threshold resets the accumulator. No detection threshold was changed.

### 2026-08-16 — The in-process classical arm silently used the wrong stability gate

Found while building the live pipeline switch, by diffing `main.py`'s ported classical branch
against `main_classical_only.py` function by function rather than assuming the port was faithful.

*   **Symptom:** none visible. The A/B comparison would simply have been wrong, and quietly.
*   **Root cause:** `is_scene_stable()` in `main.py` measures motion **frame-to-frame**, whereas
    the original classical system measured it **against the baseline photo**. Running the
    classical presence logic behind `main.py`'s stability gate therefore fed it a different set
    of frames than the archival baseline would have seen — the two arms would have disagreed for
    a reason that had nothing to do with the pipeline being compared.
*   **Resolution:** classical mode now uses the vs-baseline gate. Verified by replaying one clip
    through both `main.py --pipeline=classical` and `main_classical_only.py` and diffing the
    event logs: **identical, event for event, to the 0.01 s.** `check_roi()` and
    `find_item_template_match()` were separately confirmed byte-identical between the two files,
    as were the 0.55 match threshold and the fixed-asset keyword shortcut.
*   **Lesson:** when porting code for an A/B comparison, the thing that invalidates the result is
    never the logic you ported — it is the surrounding context you did not.

### 2026-08-16 — Either detection script died on its first `→` on a Windows console

*   **Symptom:** `UnicodeEncodeError` killed `main.py` or `main_classical_only.py` seconds after
    startup, on the first state transition that printed a status arrow.
*   **Root cause:** the Windows console defaults to the cp1252 code page, which cannot encode
    `→` (U+2192). Python raises rather than substituting, and the exception was on the main loop
    path, so the process died outright.
*   **Resolution:** both scripts now reconfigure their streams at import time —
    `sys.stdout.reconfigure(encoding="utf-8", errors="replace")`, same for `stderr`, wrapped in
    a bare `try/except` so it is a no-op on platforms where the streams are already UTF-8. The
    `errors="replace"` is the important half: even if a console refuses UTF-8, an unencodable
    character degrades to `?` instead of raising. Console output is now free to use arrows and
    box-drawing characters; nothing needs to stay ASCII-only.

### 2026-07-31 — Every dashboard button hung forever ("Clearing…" / "Unpausing…")

*   **Symptom:** clicking **Dismiss Alerts** or **Dismiss Recalibration Alert** on
    `room-monitor.php` spun forever and never resolved.
*   **Root cause — a 404 on a script tag.**
    [`pages/room-monitor.php`](file:///c:/xampp/htdocs/spotit/pages/room-monitor.php) loaded
    `../assets/js/dashboard.js`, **which does not exist** — the shared helpers live in
    `assets/js/spotit.js`, which is what every other page in `pages/` loads. Apache answered the
    404 with an HTML error page, so the browser additionally refused to execute it on MIME
    grounds (`Refused to execute script … MIME type ('text/html') is not executable`).
*   **Why it presented as a hang rather than an error.** `spotit.js` defines both `spotitFetch`
    (line 146) and `showToast` (line 28). With the file missing:
    1. `dismissAlerts()` calls `spotitFetch(...)` → `ReferenceError: spotitFetch is not defined`
    2. control passes to the `catch` block, which calls `showToast(...)` → **also undefined** →
       throws a *second* `ReferenceError` out of the catch
    3. the button-restore line sat *after* that call, so it never ran

    The request was therefore never even sent, and the spinner was permanent. The console said
    so plainly the whole time — the lesson is to read it before theorising.
*   **Resolution:** point the script tag at `assets/js/spotit.js`. Additionally hardened both
    handlers so this class of failure cannot lock a button again: toast calls go through a
    `safeToast()` wrapper that swallows its own errors, and the button restore moved into a
    `finally` block so it runs on every path.
*   **Diagnostic note:** `curl -X POST …/auth/dismiss_alerts.php` returned **HTTP 403 in 12 ms**.
    That single measurement ruled out PHP, MySQL and the handler logic, and should have been the
    *first* step. Three earlier attempts guessed at MySQL row-lock contention with `main.py`
    instead and were all wrong; two of them removed correct DB writes from
    `dismiss_alerts.php` and `reset_recalibration.php` (leaving detections stuck at `pending`
    so the alert list never cleared) and have been reverted.

### 2026-07-31 — Latent: MJPEG stream held the PHP session lock forever

Found while chasing the bug above. **Not** the cause of it, but a genuine defect that would
have produced very similar symptoms once the JS was fixed, so it was fixed at the same time.

*   **Root cause:** [`auth/tapo_stream.php`](file:///c:/xampp/htdocs/spotit/auth/tapo_stream.php)
    serves the live feed. It calls `session_start()` (via `service_bootstrap.php`), then
    `set_time_limit(0)` and streams inside `while (!feof($proc) …)` — it never terminates while
    the feed is on screen. PHP's default session handler holds an **exclusive flock on the
    session file** from `session_start()` until the script ends or `session_write_close()` is
    called, so that lock was held for the entire life of the stream. Any other request carrying
    the same session cookie would block inside `session_start()` waiting for it.
*   **Resolution:** `session_write_close()` immediately after the auth check, before streaming
    begins. Nothing past that point touches `$_SESSION`.
*   **Rule for this codebase:** any long-running, streaming or long-polling endpoint **must**
    `session_write_close()` as soon as it has finished reading `$_SESSION`. The call must come
    *after* `require_once service_bootstrap.php` — adding it to the handler that appears to hang
    does not help, because the block happens inside the bootstrap's own `session_start()`.

### 2026-07-30 — Five defects found while rewriting the presence logic

Found by reading `main.py` end to end during the detection-pipeline rewrite. All five were
live in commit `6c47a1d`; none had visible symptoms that pointed at the real cause.

#### (a) `validate_presence_dnn()` crashed the whole script — CRITICAL
*   **Symptom:** `main.py` dies with no warning shortly after a left item appears on camera.
*   **Root cause:** [`main.py:1311`](file:///c:/xampp/htdocs/spotit/main.py) called
    `validate_presence_dnn(crop)` with one argument, but the function requires `target_label`
    positionally. Nothing wraps the contour loop or `while True:` in a `try`, so the first
    contour $\geq 4500$ px that cleared the border filter raised an uncaught `TypeError` and
    killed the process. Any run in production or sandbox-unregistered mode was affected.
*   **Resolution:** the call site's own comment describes it as an "Objectness & Texture
    Filter", and the file already contained a correct implementation of exactly that —
    `validate_presence_classical()` (Laplacian variance + edge density), previously dead code.
    Switched to it. Classification was the wrong question there anyway: ImageNet cannot name a
    small CCTV crop (see `unfixable_limitations.md` §2).

#### (b) `prev_roi_gray` clobbered mid-loop — Snapshot B never fired
*   **Symptom:** Snapshot B was never produced for registered ROIs, despite being a documented
    §3c requirement. The "wait for the hand to leave" clean frame also fired instantly.
*   **Root cause:** `prev_roi_gray[label]` was overwritten with the *current* frame's crop at
    the top of the ROI body. Both downstream watchers then diffed the current crop against that
    same-frame copy, so `motion_val` was **always exactly 0.0** — permanently below the
    Snapshot B gate (`>= 0.05`, never fires) and permanently below the clean-frame gate
    (`< 0.04`, fires immediately).
*   **Resolution:** added `prev_roi_gray_prev[label]`, captured *before* the overwrite, and
    pointed both watchers at it.

#### (c) Scene instability reset the missing counter instead of holding it
*   **Symptom:** items removed while anyone was moving nearby were never confirmed missing.
*   **Root cause:** the scene-stability gate zeroed every ROI's `consistency_count` whenever
    frame motion exceeded `SCENE_MOTION_LIMIT`. But physically removing an item *is* motion, so
    the counter was wiped on exactly the frames that mattered.
*   **Resolution:** unstable frames now **hold** the accumulators (advance nothing) rather than
    resetting them. Equally safe against false alarms, without the blind spot.

#### (d) Consistency counted loop iterations, not camera frames
*   **Symptom:** confirmation timing did not match the configured frame count.
*   **Root cause:** `VideoStream.read()` returned the cached frame with no novelty flag while
    the loop spun at ~33 Hz against a 15–20 fps stream, so roughly half of every count was a
    re-read of a frame already counted.
*   **Resolution:** added a monotonic `seq` counter incremented only on genuinely new decoded
    frames, plus `read_seq()`. Confirmation moved to wall-clock seconds
    (`MISSING_CONFIRM_SEC = 1.0`), matching the fix already applied to the left-item path
    ("Instant Stage Escalation in Loop" below).

#### (e) Production mode never monitored registered items
*   **Symptom:** registered lab equipment was not watched at all whenever production mode was on.
*   **Root cause:** the left-item branch was entered when
    `SANDBOX_TRACKING_MODE == "unregistered" **or** IS_PRODUCTION_MODE` and unconditionally
    `continue`d at the end, so the registered-ROI loop below was unreachable in production.
*   **Resolution:** only sandbox-unregistered mode short-circuits now. Production runs the
    left-item scan **and** falls through to the registered loop, merging both into the single
    `live_roi_state.json` the dashboard polls.

### Wood Grain / Similar Colored Background False Match (Mouse, Laptop, Tumbler) — **SUPERSEDED 2026-07-30**
> The Canny edge-density-ratio fix recorded here did not hold up in live testing and has been
> removed. Edge density cannot separate a glossy reflective desk from a dark item reliably, and
> the solid-colour fallback it depended on was the cause of the tumbler failure. Replaced by the
> dual-template method — see `unfixable_limitations.md` §1 and §4 for the resolution and
> measured numbers.

*   **Symptom:** Removing items (like the mouse or laptop) from a textured wood table, or a red tumbler from a reddish-brown wood chair, left the ROI bounding box GREEN.
*   **Root Cause:**
    1. Template matching (`cv2.matchTemplate`) returns high correlation scores on similar wood grains or colors.
    2. Solid-colored items like the red tumbler fall back to color difference checks. Reddish wood chairs had an average color difference below the default `20.0` threshold, tricking the fallback check.
*   **Resolution:**
    1. Replaced raw color checks with an **Edge Density Ratio** comparison using `cv2.Canny`. If the match area has edge density $< 35\%$ or $> 220\%$ of the baseline, it is rejected as empty background/wood grain.
    2. Tightened the solid-color fallback difference threshold from `20.0` to `12.0` to reject similar-toned backgrounds (like red wood seats).

### Room Monitor Unknown Column 'floor_level' Fatal Error
*   **Symptom:** Clicking "Room Cameras" (`pages/room-monitor.php`) or polling `auth/get_room_status.php` threw a fatal PDOException: `Unknown column 'floor_level' in 'field list'`.
*   **Root Cause:** The SQL query queried `floor_level`, whereas the schema column in database table `rooms` is `floor`.
*   **Resolution:** Updated `pages/room-monitor.php` and `auth/get_room_status.php` to query `floor`.

### System Reset Live Frame Persistence
*   **Symptom:** Clicking "Reset System" cleared database logs, but the live camera feed snapshot remained on screen without clearing.
*   **Root Cause:** `auth/reset_system_state.php` unlinked files, but `main.py`'s background `save_frames_atomic()` loop kept continuously overwriting `uploads/snapshots/clean_DESK.jpg` every 500ms without checking if reset was in progress.
*   **Resolution:** Created SVG standby artwork (`assets/img/standby_placeholder.svg`), updated `auth/reset_system_state.php` to copy the standby placeholder into snapshot paths, and updated `main.py`'s `save_frames_atomic()` to check `reset_paused` in `detection_mode.json` to pause writing clean frames until a new baseline snapshot is captured via `auth/capture_frame.php`.

### Reset System State Failing (Syntax Error & Missing JS)
*   **Symptom:** Clicking "Reset System" gave a failure toast message.
*   **Root Cause:**
    1. A PHP string concatenation syntax error on line 75 of `auth/reset_system_state.php` (`$e.getMessage()` instead of `$e->getMessage()`) triggered a fatal error under exceptions.
    2. The JS handler `resetSystemState()` was completely missing from `pages/desk-sandbox.php`.
*   **Resolution:** Corrected the PHP exception syntax, wrapped each database and file deletion step in isolated try-catch blocks to prevent lock crashes, and added the JS `resetSystemState()` function to `desk-sandbox.php`.

### KeyError on Reloading ROIs in main.py
*   **Symptom:** Reloading active ROIs triggered `KeyError: 'mouse'` at `ok_consistency_count[label] += 1`.
*   **Root Cause:** The dynamic reload block in `main.py` did not re-initialize the newly added Phase 2 tracking dictionaries (`ok_consistency_count`, `seq_completed`, `last_seq_snapshot_time`, `registered_seq_count`, etc.) for new labels.
*   **Resolution:** Updated the reload loop in `main.py` to initialize all per-label tracking dictionaries upon config reload.

### Left Item Timer Retained Previous Count Upon Replacement
*   **Symptom:** Placing a left item back on the desk after removal immediately escalated to Red (`CONFIRMED LOST (45.1s)`).
*   **Root Cause:** `unreg_first_seen['object1']` was never cleared from Python memory when the contour disappeared from the camera view.
*   **Resolution:** Added `unreg_absence_count` in `main.py` to monitor item absence. If an unregistered item contour is absent for >10 frames (~0.3s), its timestamp and state dictionaries are automatically popped and cleared, allowing replaced items to start fresh from `0.0s`.

### Recovered Button Clicked on Dashboard Did Not Allow Item Re-detection
*   **Symptom:** Clicking "Recovered" on the dashboard updated the database, but `main.py` ignored the item when placed down again.
*   **Root Cause:** `main.py` kept `active_events['object1'] = True` stored in its internal Python memory, preventing new Stage 1/2 alerts from firing.
*   **Resolution:** Updated `auth/update_event_status.php` to write a reset signal to `detection_mode.json` when status is marked `recovered` or `dismissed`, and updated `main.py` to clear `active_events` and unregistered state dictionaries upon receiving the signal.

### Camera Full Screen View Stuck / White Border padding
*   **Symptom:** Toggling full screen left a small video box at the top center surrounded by a giant white background.
*   **Root Cause:** The HTML5 fullscreen API scaled the container box, but the video element inside lacked styles to occupy the layout.
*   **Resolution:** Added `#camVideoBox:fullscreen` and img child CSS rule: `width: 100vw; height: 100vh; object-fit: contain; background: #000;`.

### 404 Not Found on toggle_detection_mode.php
*   **Symptom:** Changing tracking modes back to Registered failed with a console 404 error.
*   **Root Cause:** The browser requested `auth/toggle_detection_mode.php` which was missing from the server.
*   **Resolution:** Created `auth/toggle_detection_mode.php` to handle writing both `tracking_mode` and `mode` to `detection_mode.json`.

### Instant Stage Escalation in Loop
*   **Symptom:** Setting Stage 1 (Potential) to 9 frames and Stage 2 (Red) to 18 frames triggered them nearly simultaneously.
*   **Root Cause:** The Python frame processing loop executes at 30+ FPS, taking less than 0.6 seconds to iterate 18 times.
*   **Resolution:** Switched from loop counting to clock-time tracking (`time.time() - unreg_first_seen[label]`), enforcing Stage 1 at exactly 3.0s (Yellow) and Stage 2 at exactly 6.0s (Red).

### Shadow False Positives on Table Border (object2, object3 noise)
*   **Symptom:** Compression noise and shadow changes on table borders generated false-positive left item alerts.
*   **Root Cause:** The area threshold was too low (2000 px) and lacked secondary texture/edge validation.
*   **Resolution:** Increased minimum contour area threshold to `4500` pixels, filtered out border edge noise, and implemented a secondary MobileNetV2 / computer vision texture and edge density gatekeeper filter (`validate_presence_dnn`).

### Snapshot B Fired Immediately on Item Placement
*   **Symptom:** Snapshot B was captured instantly on item placement, looking identical to Snapshot A (showing the box sitting still with no hand).
*   **Root Cause:** Snapshot B compared the crop difference against the *empty desk* baseline, which was constantly different while the item sat there.
*   **Resolution:** Saved a reference gray crop of the occupied item position (`item_occupied_crops`) at Stage 1, checking difference against it when hand moves/lifts it.

### Snapshot B Skipped on Fast Removal
*   **Symptom:** Taking the box off the desk skipped Snapshot B entirely.
*   **Root Cause:** The Snapshot B check was located *inside* the contour loop. When the item contour disappeared from the desk, the loop for that label was skipped entirely.
*   **Resolution:** Moved the Snapshot B check *outside* the contour loop to evaluate every single frame, instantly capturing Snapshot B whenever `lbl not in current_seen_labels` evaluates to `True`.

### Fatal "Call to Undefined Function" `ms_detection_stage()`
*   **Symptom:** `ingest_detection.php` (called by `main.py`) crashed with a 500 fatal error.
*   **Root Cause:** The script called `ms_detection_stage()` on the response payload but only required `config/env.php` and `services/monitoring/db.php`. The function itself was declared in `auth/service_bootstrap.php`, which was never loaded.
*   **Resolution:** Moved `ms_detection_stage()` into `services/monitoring/db.php` (since it only depends on constants in `env.php`, which `db.php` already requires) and wrapped it in a `function_exists` guard to avoid duplicate declarations.

### Live Feed showing ~86% Motion / Orange Boxes Stay Unstable
*   **Symptom:** The live feed registered high motion even in an empty room, and ROI bounding boxes remained orange.
*   **Root Cause:** `capture_ref.py` rotates portrait camera frames (iPhone stand-in) 90° CCW to save `ref_image.jpg`, but the main video capture loop in `main.py` did not apply this rotation. This caused a landscape reference frame to be compared against a portrait live stream.
*   **Resolution:** Added `cv2.rotate(frame, cv2.ROTATE_90_COUNTERCLOCKWISE)` to `main.py`'s loop and resized back to `TARGET_SIZE` so the canvas dimensions match exactly.

### "Constant Red" Detections Even When Items Untouched
*   **Symptom:** The system triggered constant false removal alerts on untouched items.
*   **Root Cause:** Compounding parameters tuned too tightly:
    1.  `THRESHOLD = 10` was too sensitive, triggering on lossy RTSP video compression noise/artifacts.
    2.  `SCENE_MOTION_LIMIT = 0.15` was too low, causing compression noise across the screen to register as general "scene instability," resetting consistency counters.
    3.  `CONSISTENCY_FRAMES = 3` was too small, confirming alerts in a fraction of a second.
*   **Resolution:** Raised thresholds to `THRESHOLD = 25`, `SCENE_MOTION_LIMIT = 0.40`, and `CONSISTENCY_FRAMES = 5`.

### Items Stay "Red" / Flagged When Placed Back "Close Enough"
*   **Symptom:** Putting an item back slightly off-center (not pixel-identical) kept the alert active.
*   **Root Cause:** Pixel-by-pixel `absdiff` subtraction has zero tolerance for small shifts, rotations, or casting shadows.
*   **Resolution:** Implemented `cv2.matchTemplate` to search a wider tolerance area (the template matching search zone) to confirm if the reference object crop is findable nearby rather than pixel-identical.

### Live Feed Flicker to Black (Apache File Read Race)
*   **Symptom:** The live feed in the browser calibration view flickers to black or half-rendered gray boxes every second.
*   **Root Cause:** `main.py` used `cv2.imwrite` directly to target image files. Since `imwrite` takes time to write the JPEG top-to-bottom, the Apache server frequently read the file mid-write, serving corrupted/incomplete frames.
*   **Resolution:** Updated `main.py` to write to a temporary file (`*_tmp.jpg`) first, then atomically overwrite the destination using `os.replace`.

### Duplicate Notification & Alert Storm
*   **Symptom:** A single missing item fires countless duplicate notifications in the event log and alerts page.
*   **Root Cause:** The duplicate suppression check in `ingest_detection.php` only matched detections with states `pending` or `potential`. Once an event escalated to `confirmed_missing`, it dropped out of the check, causing the system to insert new duplicate rows. Additionally, the notification block was fired unconditionally on every request.
*   **Resolution:** Added `confirmed_missing` to the duplicate SQL search and wrapped the notification dispatcher so it only triggers on new inserts (`$action === 'inserted'`).

### False-Positive Background Template Matches on Dark Items (Umbrella)
*   **Symptom:** Taking the umbrella off the desk left its ROI box green, failing to trigger the missing state.
*   **Root Cause:** The template match search area padding (`PAD=75`) was too large. When the dark umbrella was removed, the template matcher compared the baseline crop (containing the dark umbrella) against a wide search area on the empty table and found a false positive match on the dark wood-grain bed frame border (correlation score ~0.66). Since the match threshold was `0.55`, it evaluated to present.
*   **Resolution:** Raised `MATCH_SCORE_THRESHOLD` to `0.70` (and later unified to `0.60` with a tighter `PAD=30` window) to reject background texture correlations.

### DNN False Negatives for Small CCTV Crops (Umbrella)
*   **Symptom:** Putting the umbrella back inside the ROI did not turn it green; it remained stuck in RED/MISSING.
*   **Root Cause:** When the template matching score fell below the threshold, the system fell back to the MobileNetV2 ONNX classifier. Due to low resolution, camera noise, and CCTV angles, the classifier could not match the umbrella crop with standard ImageNet classes, returning false negatives and locking the item to red.
*   **Resolution:** Unified the presence logic by removing the fragile DNN check fallback for custom/small objects. Instead, the system uses a robust shift-tolerant template search window (±30px) as the single primary gate. If the item's pattern is found anywhere in that window with a score of `≥ 0.60`, it is marked as present.

### Person in ROI Triggering False Missing Alarm
*   **Symptom:** A person walking past or standing in front of the table immediately turned the ROI boxes RED.
*   **Root Cause:** A person occluding the ROI changed almost 80–100% of the pixels, bypassing the motion-stability filter, which triggered the classical check change percent threshold and flagged the item missing.
*   **Resolution:** Added a **Transient Motion Guard** that checks if the ROI's changed pixel area exceeds 65% in a single frame. If it does, it's flagged as transient occlusion (e.g. a person or body part passing through), and the missing state escalation is skipped.

### Watch Stays Green When Hand Occluding ROI (Brightness Check Flaw)
*   **Symptom:** Removing the watch from the desk kept it green when the user's hand/arm held it.
*   **Root Cause:** The temporary brightness check expected the mean brightness to rise when the dark item was removed. However, a person's hand or sleeve entering the ROI is also dark, causing `live_mean < ref_mean` and fooling the brightness check.
*   **Resolution:** Replaced the brightness-only gate with structural template matching in a tight shift window, which evaluates fine-grained pixel structures rather than average brightness.

### Positional Deviation Mismatch / Undefined Threshold Crash
*   **Symptom:** The watch stayed red after being returned to the desk, and the console crashed.
*   **Root Cause:**
    1. The code referenced `MATCH_SCORE_THRESHOLD` which was undefined in the fallback path, causing a NameError.
    2. The recovery logic required `is_genuinely_shifted = True` (dx > 8 or dy > 8), meaning placing the item back in its exact baseline position (0px shift) failed the shift check and fell back to missing.
*   **Resolution:** Removed the shift requirement for recovery. If the template match score anywhere in the padded search area is `≥ 0.60`, the item is marked present (whether shifted or exactly in place).

### OpenCV FFmpeg Thread Assertion Crash (`pthread_frame.c:173 async_lock failed`)
*   **Symptom:** `main.py` crashed in the terminal with `Assertion fctx->async_lock failed at libavcodec/pthread_frame.c:173` or `sps_id 0 out of range`.
*   **Root Cause:** The `update()` thread in `VideoStream` was calling `self.cap.read()` outside of lock guards while another thread (`_reconnect()` or `release()`) called `self.cap.release()`, causing C++ level data races inside FFmpeg's multi-threaded H.264 decoder.
*   **Resolution:** Refactored `VideoStream` in `main.py` with synchronized lock guards (`threading.Lock()`). Reading frame references and tearing down capture handles are now executed safely without C++ memory corruption.

### Room Setup Persistence Failure & Camera IP Disappearing
*   **Symptom:** Entering camera IP/credentials in Room Setup and clicking "Save Changes" showed "Failed to update room." and reset the IP to `None`.
*   **Root Cause:**
    1. The SQL query in `pages/room-setup.php` was missing `camera_ip`, `camera_user`, and `camera_pass` from the `SELECT` list, causing `room.camera_ip` to evaluate as empty in JS.
    2. `auth/edit_room.php` called `ms_require_role('admin')`, which issued an HTML redirect to `login.php?error=unauthorized` for normalized user roles (`staff`). The JS frontend failed to parse HTML as JSON and threw a failure toast.
    3. Blank password fields sent empty strings, which overwrote existing database passwords with `NULL`.
*   **Resolution:** Added camera columns to `room-setup.php`'s `SELECT` query, updated `auth/edit_room.php` to accept `admin` and `staff` roles with proper JSON status codes, and preserved existing database passwords when form password fields are left blank.

### Foreign Key Integrity Constraint Violation (Error 1452) on Saving ROIs
*   **Symptom:** Saving ROI inspection zones in `desk-sandbox.php` threw `Database error: SQLSTATE[23000]: Integrity constraint violation: 1452 Cannot add or update a child row: a foreign key constraint fails`.
*   **Root Cause:** Deleting the `DESK` system room from the `rooms` table broke the Foreign Key constraint `registered_lab_items_ibfk_1` (`FOREIGN KEY (room_id) REFERENCES rooms(room_id)`).
*   **Resolution:** Restored and auto-healed the `DESK` system room in `spotit_monitor_db.rooms`, added auto-heal logic to `auth/save_rois.php` to verify/create room entries before inserting ROI items, and excluded the `DESK` system room from the Room Setup management list to prevent accidental deletion.

### Sandbox Feed Stealing & Production Navigation Syncing
*   **Symptom:** Visiting `Live Sandbox Test` permanently locked `main.py` to `DESK`, causing `camera-setup.php` and `room-monitor.php` to show `STANDBY` / `OFFLINE`.
*   **Root Cause:** Navigating away from `desk-sandbox.php` did not update `detection_mode.json` to the target production room, and dropdown room switches in `camera-setup.php` lacked an event handler to update `detection_mode.json`.
*   **Resolution:** Added auto-sync of `detection_mode.json` on page load in `room-monitor.php` and `camera-setup.php`, added the missing `switchRoom()` dropdown handler to `camera-setup.php`, and excluded `DESK` from production room selectors. Navigating away from the Sandbox now automatically releases the `DESK` camera and starts production room monitoring instantly.

---

## 3. Detection Pipeline Limitations: Diagnosis & Resolution

*Merged from `unfixable_limitations.md` on 2026-08-16; that file has been deleted. Cite these
as **BUGS.md §3.1 … §3.6**.*

This section tracks structural limitations of the hardware, environment, and physical setup of
the DLSU-D CEAT Laboratory Rooms, and — where they have since been solved — how they were solved.

> **2026-07-30 update.** §3.1, §3.2 and §3.3 were previously logged as *unfixable in software*.
> They have since been resolved by reformulating the presence test rather than by re-tuning
> thresholds. The original diagnoses are kept below because they are correct about *why the old
> approach failed*, and that reasoning is what motivates the new design. §3.5 records what
> genuinely remains a hardware limitation.

> **2026-08-16 threshold note — read before quoting numbers from this section.** The measured
> tables below are the original ROOM_1 calibration figures and are still the evidence for *why*
> the method works. The **shipped thresholds have since been lowered** from the values in those
> tables: `SIM_PRESENT` 0.85 → **0.80** (position tolerance — items nudged within their ROI were
> scoring just under the old bar) and `SIM_EMPTY` 0.65 → **0.55** (weak inpaints — see the
> stuck-occlusion entry in §2, where a large item's "gone" template carried no signal at all).
> `main.py` is the source of truth; CLAUDE.md §3 carries the current table.

---

## 3.1 The Wood Grain Background False-Match Problem — **SOLVED**

*   **Symptom:** Small items (computer mouse) or dark items (laptop) stayed **GREEN (`OK`)**
    even when picked up and completely removed from the table.
*   **Original root cause (still accurate):**
    *   The baseline item crop contains the object in the centre, surrounded by brown,
        textured wooden desk.
    *   When the item is removed, `cv2.matchTemplate` compares the bare desk against that
        crop. Because the desk texture is present in *both*, correlation reaches $\approx 0.65$.
    *   Since $0.65$ exceeded the presence threshold ($0.52$), the matcher concluded the
        object was still present.
*   **Why threshold tuning could never fix it:** raising the bar above $0.70$ made the box
    turn red under ordinary shadows while the item *was* present. Each adjustment simply
    traded a false negative for a false positive. The defect was in the question being
    asked, not in the number.

### The fix — give the removal a competing hypothesis

The old test was **one-sided**: it only ever asked *"does this look like the item?"*, with
nothing else to compare against. Bare desk scored 0.65 on that question and won by default,
because it was the only question on the table.

At calibration time the system now builds a **second template** by inpainting the item out
of the reference frame (`cv2.inpaint`, Telea), reconstructing the hidden patch of desk from
the texture immediately surrounding it. This is a physically meaningful prediction of what
the camera will see once the item is lifted away. Every live frame is then scored against
**both**:

```
sim_present = similarity(live, "item is here" template)
sim_empty   = similarity(live, "item is gone" template)

sim_present >= SIM_PRESENT                       -> PRESENT   (item still there)
sim_present <  SIM_PRESENT and sim_empty >= SIM_EMPTY -> ABSENT    (bare surface revealed)
both low                                          -> OCCLUDED  (something in the way)
```

Bare wood can no longer produce a false PRESENT, because it now matches the *empty*
template far better than it matches the item template. The wood is still present in both
crops — it is simply no longer the only thing being compared.

A second refinement matters for small items: the item's true outline is recovered by
differencing the reference crop against its own inpainted version, and all comparisons are
cropped to that **footprint**. The mouse box is ~47% bare desk, and that padding was
diluting the comparison; cropping to the footprint widened the mouse's removal margin from
0.03 to a clean separation.

**Measured on the real ROOM_1 reference frame** (`photos/ref_image_ROOM_1.jpg`), all three
registered items, across present / removed / ±25% lighting / nudged / hand-occluded:

| signal | item present | item removed | hand occluding |
|---|---|---|---|
| `sim_present` | 0.985 – 1.000 | 0.477 – 0.743 | 0.289 – 0.513 |
| `sim_empty`   | 0.509 – 0.685 | 0.774 – 0.829 | 0.357 – 0.521 |

Both thresholds sit in the middle of a wide empirical gap rather than on an edge:
**27/27 scenarios classified correctly.**

---

## 3.2 CCTV Resolution & Object Size Constraints (The DNN Failure) — **SOLVED**

*   **Symptom:** MobileNetV2 failed to verify presence, turning boxes RED even when items
    were present.
*   **Original root cause (still accurate for *classification*):**
    *   The cropped ROI of a mouse is only ~$80 \times 60$ px.
    *   MobileNetV2 requires $224 \times 224$ input; upscaling that crop produces a blurry,
        pixelated cluster of colour.
    *   The network could not identify features on such a crop, frequently misclassifying
        the mouse as background noise or a "container ship" with low confidence.

### The fix — stop asking the network to name things

The original diagnosis is right that **classification** is hopeless here: the network is
being asked to map a degraded crop onto an ImageNet class it never saw at that resolution.

But *comparing two crops from the same camera* is a different problem. Both crops carry
**identical** blur and identical downsampling damage, so that degradation is common-mode
and cancels in the comparison. The system now takes the penultimate global-average-pool
feature vector — layer `onnx_node!mobilenetv20_features_pool0_fwd`, output `(1, 1280, 1, 1)`
— L2-normalises it, and compares by cosine similarity. The network is used as a learned,
shift- and lighting-tolerant **feature extractor**, never as a recogniser.

Measured on 66×63 crops — precisely the size this section called hopeless:

| comparison | cosine |
|---|---|
| same item, new sensor noise | 1.000 |
| same item, lights dimmed 25% | 0.978 |
| same item, shifted 4 px | 0.996 |
| bare wood | 0.609 |
| hand / sleeve | 0.440 |

Two further points, both load-bearing for the defence:

*   **Scope guardrail intact (CLAUDE.md §8).** No class name is ever emitted. This is
    perceptual similarity, not object-identity recognition.
*   **Genuinely secondary (CLAUDE.md §3a).** The classical absdiff/threshold gate
    (`check_roi`) still decides *whether anything changed*; the network is consulted only on
    crops already flagged. On a quiet frame it never runs at all. If the model is missing,
    `classical_similarity()` — a chroma-only Lab histogram — performs the same three-way
    decision, so detection degrades rather than stopping.
*   **Cheaper than before.** Stopping at the pooling layer skips the classifier head
    entirely, so this costs *less* than the old full forward pass.
*   **Now measured, not asserted (2026-08-16).** Both scripts print an **ML duty cycle**
    summary on exit — ROI-frames seen vs forward passes actually run vs mean latency. This is
    the number that answers "isn't this just an AI system?" with evidence: on a replayed clip,
    classical mode reached `ABSENT` with **0** forward passes where the gatekeeper used 150.

---

## 3.3 Human Occlusion vs. Theft Ambiguity — **RESOLVED BY DESIGN**

*   **Symptom:** When a user typed on the laptop or held the mouse, boxes turned RED or flickered.
*   **Original root cause (still accurate):** a monocular CCTV camera cannot see through a
    hand. The template matcher saw skin/sleeve instead of the item and marked it RED.
*   **Why the old mitigation failed:** suppressing the alarm via local frame differencing
    (`local_motion > 0.05`) worked during *active* typing but failed the moment the user
    rested a hand still on the item — the exact case this section documented.

### The fix — occlusion is not a decision, it is a reason to withhold one

The physical limitation is real and unchanged: **you still cannot see through a hand.** What
changed is that the system no longer has to guess. A hand matches neither the "item here"
template nor the "item gone" template, so it lands in a distinct **OCCLUDED** state which
*holds* the confirmation timers rather than advancing them.

That is the correct epistemic response: while the view is blocked, no evidence is
accumulating in either direction, so no verdict is reached. It does not depend on the hand
moving, which is why the still-hand case is now handled.

The removal-through-hand sequence resolves correctly and in the right order:

```
hand reaches in    -> OCCLUDED -> timers hold
hand leaves w/item -> ABSENT   -> timer accumulates -> RED after 1.0s
```

Displayed as a distinct blue box, so an operator can tell at a glance that nothing is wrong —
someone's hand is simply in the way.

**Bounded 2026-08-16.** Holding forever turned out to be its own failure mode when the "item
gone" template is uninformative (see the stuck-occlusion entry in §2). The hold is now capped
at `OCCLUDED_MAX_SEC = 5.0`, but **only while the ROI is also motionless**
(`OCCLUDED_STILL_MOTION = 0.02`) — which preserves everything this section argues, because a
real hand is never perfectly still and therefore never triggers the escape.

---

## 3.4 Solid-Colour Background Bleed (Tumbler on Chair) — **SOLVED**

*   **Symptom:** A red tumbler on a reddish-brown wooden chair seat stayed GREEN after removal.
*   **Root cause:** low-edge-density items fell through to a colour-difference fallback, and
    the warm chair seat passed the absolute `color_diff < 12.0` test.
*   **Resolution:** the fallback is gone. The tumbler is handled by the same dual-template
    test as everything else — measured `sim_present` 1.000 present vs 0.614 removed,
    `sim_empty` 0.604 present vs 0.811 removed. Correct in all six lighting/removal variants.

---

## 3.5 Remaining Genuine Limitations (for Chapter 5)

These are **not** solved and should be documented honestly as findings:

1.  **Total occlusion is undecidable.** If an item is permanently hidden — a bag left on top
    of it for an hour — the system holds in OCCLUDED and reports neither present nor missing.
    This is correct behaviour rather than a bug, but it means the system cannot detect a theft
    that occurs entirely under cover and is never re-exposed. *Recommendation: physical IoT
    weight sensors or RFID under high-value equipment as a redundant modality.* (Partially
    bounded since 2026-08-16 — a **motionless** total occlusion now resolves to ABSENT after
    5 s; a moving one still holds indefinitely, by design.)
2.  **Baseline must contain the item.** Calibration derives the item footprint by comparing
    the reference frame against its inpainted version. If the baseline is captured while the
    item is already absent, no footprint is found and the ROI is skipped with a warning. This
    is a workflow constraint on the recalibration UI, not a detection failure.
3.  **ROI boxes must reasonably bound the item.** Detection weakens if a box is mostly empty
    surface. Startup warns when an item fills less than 3% of its box.
4.  **Very large flat items** that fill their entire box leave little surrounding texture for
    inpainting to reconstruct from. **Confirmed as a real failure on 2026-08-16**, no longer
    theoretical: over a large mask `cv2.inpaint` returns a diffused smear, and `sim_empty`
    measured 0.39 present vs 0.41 removed — no separation whatsoever. The stuck-occlusion
    escape works around it, but the underlying template is genuinely uninformative for such
    items. Re-check when full-size lab equipment (monitors, UPS) is registered.
5.  **Camera resolution still bounds everything.** The above works at 2K on ~60 px items.
    A 4K camera would extend the same method to smaller items at greater distance.

---

## 3.6 Reproducing These Measurements

The numbers in §3.1–§3.4 come from exercising the real functions in `main.py` (extracted by AST,
so the tested code cannot drift from the running code) against
`photos/ref_image_ROOM_1.jpg`. Removals are simulated with a *different* inpainting algorithm
(Navier-Stokes, radius 9) than the one used to build the template (Telea, radius 5), plus
sensor noise, so the comparison is not circular.

Live-run metrics accumulate in `photos/detection_metrics.csv`:

```
ts, room, label, change_pct, sim_present, sim_empty, used_dnn, state,
absent_time, present_time, scene_motion
```

Rows are written on every state transition and at most twice per second otherwise. This is
the raw material for the Chapter 4 TP/FP/FN/TN table.

For the head-to-head against `main_classical_only.py`, use the replay harness rather than two
live cameras: `SPOTIT_SOURCE=<clip.mp4>` feeds a recorded file through either script so both
arms score **byte-identical frames**, and it auto-disables API posting so a scoring run cannot
pollute `detections`. Per-arm events land in `photos/ab_events_gatekeeper.csv` /
`ab_events_classical.csv`, whose `arm` column records the pipeline that actually produced each
row. Full protocol: [AB_ACCURACY_PROTOCOL.md](file:///c:/xampp/htdocs/spotit/md_files/AB_ACCURACY_PROTOCOL.md).

