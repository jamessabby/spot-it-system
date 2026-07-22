# S.P.O.T.-IT — Bug Registry & Resolution History

This file tracks active bugs, pending issues, and logs past bug diagnostic details with their root causes and resolutions. Refer to [CLAUDE.md](file:///c:/xampp/htdocs/spotit/CLAUDE.md) for core architecture rules and [UPDATES.md](file:///c:/xampp/htdocs/spotit/UPDATES.md) for the roadmap status.

---

## 1. Active / Immediate Fix Targets

These are outstanding bugs, issues, or bootstrap items that need to be resolved. Check them off as you fix them.

- [ ] **Test data initialization:** Need real room records inserted into `spotit_monitor_db.rooms` and `registered_lab_items` beyond the temporary `TESTROOM` desk setup before Table 3.5 scenarios can be run against actual CEAT laboratory rooms.
- [ ] **First-admin bootstrap setup:** Admin accounts can only be provisioned by an existing admin—but no admin exists on a fresh install.
  - **Resolution path:** Need a one-time SQL seed directly in `spotit_auth_db.users`. Check `services/auth/schema.sql` and `login_handler.php` for the hash format.
- [x] **Double snapshot logic in pipeline:** `main.py` needs to implement the secondary camera snapshot (Snapshot B) when an item's empty ROI is physically interacted with or shifted while in the RED/MISSING state.

---

## 2. Past Diagnosed Bugs & Root Causes

Refer to this log so you do not waste time re-investigating previously solved problems.

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

