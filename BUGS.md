# S.P.O.T.-IT — Bug Registry & Resolution History

This file tracks active bugs, pending issues, and logs past bug diagnostic details with their root causes and resolutions. Refer to [CLAUDE.md](file:///c:/xampp/htdocs/spotit/CLAUDE.md) for core architecture rules and [UPDATES.md](file:///c:/xampp/htdocs/spotit/UPDATES.md) for the roadmap status.

---

## 1. Active / Immediate Fix Targets

These are outstanding bugs, issues, or bootstrap items that need to be resolved. Check them off as you fix them.

- [ ] **Test data initialization:** Need real room records inserted into `spotit_monitor_db.rooms` and `registered_lab_items` beyond the temporary `TESTROOM` desk setup before Table 3.5 scenarios can be run against actual CEAT laboratory rooms.
- [ ] **First-admin bootstrap setup:** Admin accounts can only be provisioned by an existing admin—but no admin exists on a fresh install.
  - **Resolution path:** Need a one-time SQL seed directly in `spotit_auth_db.users`. Check `services/auth/schema.sql` and `login_handler.php` for the hash format.
- [ ] **Double snapshot logic in pipeline:** `main.py` needs to implement the secondary camera snapshot (Snapshot B) when an item's empty ROI is physically interacted with or shifted while in the RED/MISSING state.

---

## 2. Past Diagnosed Bugs & Root Causes

Refer to this log so you do not waste time re-investigating previously solved problems.

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

