# S.P.O.T.-IT — Bug Registry & Resolution History

This file tracks active bugs, pending issues, and logs past bug diagnostic details with their root causes and resolutions. Refer to [CLAUDE.md](file:///c:/xampp/htdocs/spotit/CLAUDE.md) for core architecture rules and [UPDATES.md](file:///c:/xampp/htdocs/spotit/UPDATES.md) for the roadmap status.

---

## 1. Active / Immediate Fix Targets

These are outstanding bugs, issues, or bootstrap items that need to be resolved. Check them off as you fix them.

- [ ] **Test data initialization:** Need real room records inserted into `spotit_monitor_db.rooms` and `registered_lab_items` beyond the temporary `TESTROOM` desk setup before Table 3.5 scenarios can be run against actual CEAT laboratory rooms.
- [ ] **First-admin bootstrap setup:** Admin accounts can only be provisioned by an existing admin—but no admin exists on a fresh install.
  - **Resolution path:** Need a one-time SQL seed directly in `spotit_auth_db.users`. Check `services/auth/schema.sql` and `login_handler.php` for the hash format.
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


