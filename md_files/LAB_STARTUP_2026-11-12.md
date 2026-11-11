# Laboratory startup guide — 12 November 2026

Build: local checkpoint made the evening of 11 Nov 2026 (see `git log -1`).
Everything below assumes the laptop runs XAMPP + the detector (`SPOTIT_DEPLOY=local`).
Backup of code, calibration and databases taken before the checkpoint:
`C:\SPOTIT_Test_Backups\final_demo_20261111_214140\`.

## 0. Before leaving for the lab (5 min)

1. Confirm the checkpoint is the code on disk: `git status` shows no unexpected changes.
2. `models\mobilenetv2-7.onnx` and `models\yolox_s.onnx` exist (YOLOX is NOT in git — copy it
   from the backup/laptop if the repo was re-cloned).
3. `config\env.local.php` exists (camera + Turnstile + Microsoft values). It is gitignored;
   a fresh clone has no login until it is restored.

## 1. Start the web app

1. XAMPP Control Panel → Start **Apache** and **MySQL**.
2. Open `http://localhost/spotit/pages/login.php` and sign in with the admin account
   (Microsoft login, or email + password + Turnstile).
3. Admin Dashboard should load with the four KPI cards. If the bell icon shows a count,
   open it once — rows now show the event's current state (`Now: Recovered` etc.).

## 2. Camera and room settings (do this BEFORE starting the detector)

Only **one** room may be enabled per camera.

1. **Room Setup** (`pages/room-setup.php`): the room you are testing (MLH301 or MLH305) must be
   **Enabled**; the other one Disabled. Tonight's state: MLH301 enabled, MLH305 disabled.
2. Open the room → edit camera: IP `10.62.74.79`, user `SpotItCamera`, and the **current**
   camera password. The stored password could not be checked offline — if the detector
   console shows `401`/`Unauthorized` or "camera unreachable", this is the first thing to fix.
3. The stream must be the main stream (2304×1296). The saved ROI files were drawn on the
   1152×648 half-size frame of that stream; if the camera delivers 1080p the detector holds
   with **RECALIBRATION REQUIRED** (by design). Fix: in the Tapo app set the video quality to
   2K/3MP, or recapture the baseline and redraw the ROIs in Camera Setup.

## 3. Start the detector

Open a terminal in `C:\xampp\htdocs\spotit` and run:

```
set SPOTIT_DEPLOY=local
python main.py MLH301
```

(replace `MLH301` with the enabled room). Giving the room on the command line locks it; the
dashboard's room switch then cannot redirect the detector mid-test. Omit it to let the
dashboard choose (`detection_mode.json`).

API key: the detector's `API_KEY` environment variable must equal the server's
`SPOTIT_DETECTION_KEY`. On this laptop neither is set, so both use the same default and
ingestion works; on a hosted server set both to the same real value.

Expected console within ~10 s: stream opened at 2304×1296 (`stream1`), "templates ready" for
every ROI, then `[HEARTBEAT]`/state lines. The Room Monitor pill turns **LIVE**.

If the service was installed earlier (`deploy\install-service.ps1`) do **not** also start it by
hand — the Tapo allows only a couple of RTSP clients and a refused client silently drops to
the 640×360 sub-stream.

## 4. Room Monitor checks (2 min, before the test proper)

`pages/room-monitor.php?room=MLH301`

- Pill: **LIVE** (not RECONNECTING / CAMERA OFFLINE / DETECTOR OFFLINE).
- **Mode** dropdown: keep **OpenCV + MobileNetV2** for the test. "Classical" and "YOLO
  (experimental)" are demonstration arms; switching resets the confirmation timers.
- **Show** dropdown: set to **All equipment** so every ROI box is visible. Choosing a category
  only hides the other boxes on the video — every ROI stays monitored. The tag button opens
  the category editor (admin): each saved ROI can be assigned a category; nothing there
  touches the ROI files.
- Speed toggle: **Testing speed** for the protocol (same as the Nov 3 session).

## 5. During the test — what each action produces

| Action in the room | Console | Dashboard / Alerts | Bell |
|---|---|---|---|
| Remove a registered item | `ROI Label : <label>` + Snapshot A | new Pending event; KPI "Active Deviations" +1 | "New Deviation Detected — label" for every admin |
| Put it back | `'<label>' confirmed returned to OK state` | event → Recovered automatically | same row now shows `Now: Recovered` |
| Staff "Recovered" on equipment | — | event Recovered; log "returned to the lab"; **not** added to Lost & Found | — |
| Leave a bag/phone (unregistered mode) | `UNATTENDED ITEM DETECTED: objectN` | Pending event for objectN | admins notified |
| Staff "Recovered" on objectN | — | one claimable Lost & Found record (never two) | students notified |
| Student claims at the Claiming Station | — | claim Pending → staff completes with selfie → Claimed | claimant notified |

Bell rows open the event pop-up (`dashboard-admin.php?event=<id>`). Clicking a row marks it
read for that user only; "Mark all read" clears the badge immediately.

## 6. Shutdown

1. `Ctrl+C` in the detector terminal (it prints the ML duty-cycle summary).
2. Leave Apache/MySQL running until the records have been reviewed/exported
   (Admin Dashboard → Export records).

## 7. Rollback

**Code — undo tonight's checkpoint only (keeps the previous tested detector):**
```
cd C:\xampp\htdocs\spotit
git stash            # only if there are uncommitted edits you want to keep
git checkout ada2719 -- .      # files as of the previously committed build
```
or, keeping history: `git revert <checkpoint-sha>`.

**Detector only:** copy `C:\SPOTIT_Test_Backups\final_demo_20261111_214140\code\main.py.HEAD_ada2719`
over `main.py` (previous tested version) — or `main.py.current` (tonight's version).

**Calibration (ROIs/baselines):** copy the files from
`...\final_demo_20261111_214140\calibration\` back into the repo root / `photos\`.

**Databases:** in phpMyAdmin or the shell:
```
C:\xampp\mysql\bin\mysql.exe -u root spotit_auth_db      < ...\db\spotit_auth_db.sql
C:\xampp\mysql\bin\mysql.exe -u root spotit_monitor_db   < ...\db\spotit_monitor_db.sql
C:\xampp\mysql\bin\mysql.exe -u root spotit_lf_db        < ...\db\spotit_lf_db.sql
C:\xampp\mysql\bin\mysql.exe -u root spotit_user_db      < ...\db\spotit_user_db.sql
C:\xampp\mysql\bin\mysql.exe -u root spotit_community_db < ...\db\spotit_community_db.sql
```
(restores the 11 Nov 21:41 state; anything recorded afterwards is lost — export first).

## 8. Known limits to state plainly if asked

- The lab camera password in the DB was not verifiable offline (see §2).
- Live camera disconnect/reconnect was not exercised tonight; the detector's offline/
  reconnecting states are unit-tested and were shown with no camera attached.
- YOLO mode and ROI suggestions name object classes — experimental, pending adviser approval.
- `spotit_test_*` databases on this laptop are the isolated test copies; they are not used
  by the site and can be dropped later (ask before deleting anything).
