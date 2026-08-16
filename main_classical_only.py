"""
S.P.O.T.-IT — CLASSICAL-ONLY BASELINE (A/B control arm for Chapter 4)

This is the *control* in the accuracy comparison described in
md_files/AB_ACCURACY_PROTOCOL.md. It runs the original pipeline:
background subtraction -> ROI change -> expanding-search-zone template match,
with NO MobileNetV2 presence gate.

────────────────────────────────────────────────────────────────────────────
WHAT MAY BE CHANGED IN THIS FILE, AND WHAT MAY NOT
────────────────────────────────────────────────────────────────────────────
The detection logic below — check_roi(), find_item_template_match(), the
fixed-asset keyword shortcut, and the decision that uses them — is UNCHANGED
from the original and must stay that way. It is the baseline; tuning it would
make the comparison worthless.

What WAS changed (2026-08-16) is only the things that must be identical to
main.py for the comparison to be fair at all. Before this, the two scripts
were reading different ROI files against a different reference photo with a
different motion threshold and a different debounce — so any difference in
results could have come from four places that have nothing to do with ML:

  1. ROI + reference files are now room-scoped, same as main.py
     (rois_{ROOM}.json / photos/ref_image_{ROOM}.jpg). Previously hardcoded
     to rois.json + photos/ref_image.jpg, i.e. different boxes entirely.
  2. SCENE_MOTION_LIMIT 0.40 -> 0.20, matching main.py.
  3. Confirmation is wall-clock (MISSING_CONFIRM_SEC), not a frame count.
     "5 frames" and "1.0 seconds" are not the same debounce, and debounce
     alone changes the false-alarm rate.
  4. On an unstable frame the confirmation timers now HOLD instead of RESET,
     matching main.py. Resetting is a known false-negative path (BUGS.md
     2026-07-30 (c)) — removing an item is itself motion — and leaving it in
     would have penalised the baseline for a bug unrelated to the ML gate,
     which flatters our system dishonestly.
  5. API posting is OFF by default, so scoring runs never write into the live
     detections table.

If asked at the defense: the control was aligned to the treatment, never
tuned against it. Every change above makes the baseline *fairer*, not worse.
"""

import os
import sys
import time
import json
import atexit
from datetime import datetime

import cv2
import numpy as np
import requests

# Windows consoles default to cp1252, which cannot encode the arrows and dashes
# used in the status prints below — a UnicodeEncodeError there would kill a
# scoring run mid-clip. Losing a test run to a print statement is not acceptable.
try:
    sys.stdout.reconfigure(encoding="utf-8", errors="replace")
    sys.stderr.reconfigure(encoding="utf-8", errors="replace")
except Exception:
    pass

# ── ROOM / API CONFIG ───────────────────────────────────────────────────────
# Same CLI contract as main.py:  python main_classical_only.py ROOM_1
ROOM_ID = sys.argv[1].upper() if len(sys.argv) > 1 else "DESK"

API_URL = os.environ.get(
    "API_URL", "http://localhost/spotit/auth/ingest_detection.php"
)
API_KEY = os.environ.get("SPOTIT_DETECTION_KEY", "CHANGE_ME_DETECTION_KEY")

# Baseline-arm events must NOT land in the live detections table — they would be
# indistinguishable from real ones and would pollute the dataset the thesis
# reports on. Opt in explicitly if you ever want this arm to post.
POST_ENABLED = os.environ.get("SPOTIT_CLASSICAL_POST", "").strip().lower() in (
    "1",
    "true",
    "yes",
)

# ── A/B REPLAY MODE ─────────────────────────────────────────────────────────
# SPOTIT_SOURCE=<path to .mp4> replays a recorded clip instead of the live
# camera, so both arms score byte-identical frames.
AB_SOURCE = os.environ.get("SPOTIT_SOURCE", "").strip()
AB_REPLAY = bool(AB_SOURCE)
AB_CLIP = os.path.splitext(os.path.basename(AB_SOURCE))[0] if AB_REPLAY else ""
AB_EVENTS_CSV = "photos/ab_events_classical.csv"

# ── CONFIG (values mirrored from main.py — see file header) ────────────────
STREAM_SCALE = 0.50
ROI_FILE = "rois.json" if ROOM_ID == "DESK" else f"rois_{ROOM_ID}.json"
REF_IMAGE = (
    "photos/ref_image.jpg" if ROOM_ID == "DESK" else f"photos/ref_image_{ROOM_ID}.jpg"
)
SNAPSHOT_DIR = os.environ.get("SNAPSHOT_DIR", "C:/xampp/htdocs/spotit/uploads/snapshots")
if not os.path.exists("C:/xampp") or os.name == "posix":
    SNAPSHOT_DIR = "uploads/snapshots"

THRESHOLD = 25  # delta needed to count as changed        — identical to main.py
MIN_CHANGE_PERCENT = 0.08  # ROI change that triggers a look — identical to main.py
SCENE_MOTION_LIMIT = 0.20  # was 0.40                       — aligned to main.py
MISSING_CONFIRM_SEC = 1.0  # was CONSISTENCY_FRAMES = 5     — aligned to main.py
RECOVER_CONFIRM_SEC = 0.5  #                                 — aligned to main.py

# Same env vars as main.py, so the A/B arms can never end up pointed at
# different cameras (or at the same camera through different credentials).
CAMERA_IP = os.environ.get("SPOTIT_CAMERA_IP", "192.168.18.14")
CAMERA_USER = os.environ.get("SPOTIT_CAMERA_USER", "SpotItCamera")
CAMERA_PASS = os.environ.get("SPOTIT_CAMERA_PASS", "spotitcamera232")
RTSP_URL = f"rtsp://{CAMERA_USER}:{CAMERA_PASS}@{CAMERA_IP}:554/stream1"
ROTATE_CAMERA = False  # True for portrait iPhone, False for Tapo CCTV

# ── THE ONE THING THAT DIFFERS FROM main.py — the presence test ────────────
MATCH_SCORE_THRESHOLD = 0.55  # original baseline template-match score

BASELINE_COUNT = None  # set below from number of ROIs
# ─────────────────────────────────────────────────────────────────────────

AB_STATS = {"roi_frames": 0, "changed_frames": 0, "dnn_calls": 0}


def rescaleFrame(frame, scale=STREAM_SCALE):
    width = int(frame.shape[1] * scale)
    height = int(frame.shape[0] * scale)
    return cv2.resize(frame, (width, height), interpolation=cv2.INTER_AREA)


os.makedirs(SNAPSHOT_DIR, exist_ok=True)

# ── LOAD ROIs ─────────────────────────────────────────────────────────────
if not os.path.exists(ROI_FILE):
    print(f"[ERROR] ROI file not found: {ROI_FILE}")
    print(f"        Register ROIs for room '{ROOM_ID}' first, or pass a different room.")
    sys.exit(1)

with open(ROI_FILE, "r") as f:
    roi_list = json.load(f)
print(
    f"[CLASSICAL] Room '{ROOM_ID}' | Loaded {len(roi_list)} ROIs from {ROI_FILE}: "
    f"{[r['label'] for r in roi_list]}"
)

BASELINE_COUNT = len(roi_list)  # all items present = baseline

# ── LOAD REFERENCE FRAME ──────────────────────────────────────────────────
ref_bgr = cv2.imread(REF_IMAGE)
if ref_bgr is None:
    print(f"[ERROR] Cannot load reference image: {REF_IMAGE}")
    sys.exit(1)
print(f"[CLASSICAL] Reference frame loaded: {REF_IMAGE} {ref_bgr.shape[1]}x{ref_bgr.shape[0]}")

# ref_gray / ref_blur / TARGET_SIZE are (re)built by _rebuild_reference() once the
# first live frame arrives, because the reference may need resizing to match it.
ref_gray = ref_blur = None
TARGET_SIZE = None


def _rebuild_reference():
    global ref_gray, ref_blur, TARGET_SIZE
    ref_gray = cv2.cvtColor(ref_bgr, cv2.COLOR_BGR2GRAY)
    ref_blur = cv2.GaussianBlur(ref_gray, (21, 21), 0)
    TARGET_SIZE = (ref_bgr.shape[1], ref_bgr.shape[0])


_rebuild_reference()

# ── CONNECT TO SOURCE ─────────────────────────────────────────────────────
if AB_REPLAY:
    if not os.path.exists(AB_SOURCE):
        print(f"[ERROR] A/B replay file not found: {AB_SOURCE}")
        sys.exit(1)
    print(f"[CLASSICAL][A/B] REPLAY MODE — source: {AB_SOURCE}")
    cap = cv2.VideoCapture(AB_SOURCE)
    _fps = cap.get(cv2.CAP_PROP_FPS)
    CLIP_FPS = _fps if _fps and 1.0 < _fps < 120.0 else 15.0
    FRAME_DT = 1.0 / CLIP_FPS
    _total = int(cap.get(cv2.CAP_PROP_FRAME_COUNT))
    print(
        f"[CLASSICAL][A/B] Clip: {_total} frames @ {CLIP_FPS:.1f} fps "
        f"(~{_total / CLIP_FPS:.1f}s) | API posting DISABLED"
    )
else:
    print(f"[CLASSICAL] Connecting to stream: {RTSP_URL}")
    cap = cv2.VideoCapture(RTSP_URL)
    CLIP_FPS, FRAME_DT = 0.0, 0.0

if not cap.isOpened():
    print("[ERROR] Cannot open video source. Check camera connection / file path.")
    sys.exit(1)

if not POST_ENABLED:
    print("[CLASSICAL] API posting is OFF (set SPOTIT_CLASSICAL_POST=1 to enable).")

print("[CLASSICAL] Source ready. Starting detection loop...")
print("[CLASSICAL] Press Q to quit.\n")

# ── PER-ROI STATE ─────────────────────────────────────────────────────────
absent_time = {roi["label"]: 0.0 for roi in roi_list}
present_time = {roi["label"]: 0.0 for roi in roi_list}
active_events = {roi["label"]: False for roi in roi_list}

active_detection_ids = {roi["label"]: None for roi in roi_list}
snapshot_b_triggered = {roi["label"]: False for roi in roi_list}
prev_roi_gray = {roi["label"]: None for roi in roi_list}
shifted_locations = {roi["label"]: None for roi in roi_list}
missing_timestamps = {}
monitoring_paused = False

clip_seq = 0
last_tick_time = time.time()
_ab_last_state = {}
_ab_events_header = False


# ── A/B INSTRUMENTATION ───────────────────────────────────────────────────
def ab_log_event(label, state):
    """Append one row per state change to photos/ab_events_classical.csv.

    Same schema as the gatekeeper arm's file, so the two can be read straight
    across against each other with no post-processing.
    """
    global _ab_events_header
    if not AB_REPLAY:
        return
    if _ab_last_state.get(label) == state:
        return
    _ab_last_state[label] = state
    elapsed = clip_seq * FRAME_DT
    try:
        if not _ab_events_header:
            _ab_events_header = True
            if not os.path.exists(AB_EVENTS_CSV) or os.path.getsize(AB_EVENTS_CSV) == 0:
                with open(AB_EVENTS_CSV, "w", encoding="utf-8") as f:
                    f.write("clip,arm,label,state,clip_time_sec\n")
        with open(AB_EVENTS_CSV, "a", encoding="utf-8") as f:
            f.write(f"{AB_CLIP},classical,{label},{state},{elapsed:.2f}\n")
        print(f"    [A/B] t={elapsed:6.2f}s  {label:<14} -> {state}")
    except Exception:
        pass


def ab_print_summary():
    """Mirror of main.py's duty-cycle summary. The zero on the ML line is the
    point: this arm reaches its verdict with no neural network at all."""
    r = AB_STATS["roi_frames"]
    if r == 0:
        return
    print("\n" + "=" * 68)
    print("  ML DUTY CYCLE — CLASSICAL-ONLY BASELINE")
    print("=" * 68)
    print(f"  ROI-frames handled by classical stage : {r:,} (100.0%)")
    print(
        f"  ...flagged as changed                  : {AB_STATS['changed_frames']:,} "
        f"({AB_STATS['changed_frames'] / r:.2%})"
    )
    print(f"  MobileNetV2 forward passes            : {AB_STATS['dnn_calls']:,} (0.00%)")
    print("=" * 68 + "\n")


atexit.register(ab_print_summary)


# ── HELPERS (classical detection logic — UNCHANGED) ───────────────────────
def is_scene_stable(frame_blur):
    full_diff = cv2.absdiff(ref_blur, frame_blur)
    _, full_thr = cv2.threshold(full_diff, THRESHOLD, 255, cv2.THRESH_BINARY)
    total_px = TARGET_SIZE[0] * TARGET_SIZE[1]
    changed_px = cv2.countNonZero(full_thr)
    motion_pct = changed_px / total_px
    return motion_pct < SCENE_MOTION_LIMIT, motion_pct


def check_roi(roi, thresh_mask):
    x, y, w, h = roi["x"], roi["y"], roi["w"], roi["h"]
    roi_mask = thresh_mask[y : y + h, x : x + w]
    total_pixels = w * h
    changed_pixels = cv2.countNonZero(roi_mask)
    change_pct = changed_pixels / total_pixels
    return change_pct >= MIN_CHANGE_PERCENT, change_pct


def save_snapshot(frame, label):
    timestamp = datetime.now().strftime("%Y%m%d_%H%M%S")
    filename = f"{label}_{timestamp}_classical.jpg"
    filepath = os.path.join(SNAPSHOT_DIR, filename)
    cv2.imwrite(filepath, frame)
    return filepath


def count_missing_items():
    return sum(
        1 for label in absent_time if absent_time[label] >= MISSING_CONFIRM_SEC
    )


def find_item_template_match(frame, ref_crop, roi, match_threshold=MATCH_SCORE_THRESHOLD):
    h_f, w_f = frame.shape[:2]
    x, y, w, h = roi["x"], roi["y"], roi["w"], roi["h"]
    cx, cy = x + w // 2, y + h // 2

    scales = [1.5, 2.0, 0.0]

    for scale in scales:
        if scale > 0.0:
            sw = int(w * scale)
            sh = int(h * scale)
            sx = max(0, cx - sw // 2)
            sy = max(0, cy - sh // 2)
            if sx + sw > w_f:
                sw = w_f - sx
            if sy + sh > h_f:
                sh = h_f - sy
        else:
            sx, sy, sw, sh = 0, 0, w_f, h_f

        if sw < w or sh < h:
            continue

        search_area = frame[sy : sy + sh, sx : sx + sw]
        res = cv2.matchTemplate(search_area, ref_crop, cv2.TM_CCOEFF_NORMED)
        _, max_val, _, max_loc = cv2.minMaxLoc(res)

        if max_val >= match_threshold:
            found_x = sx + max_loc[0]
            found_y = sy + max_loc[1]
            return True, found_x, found_y, max_val

    return False, 0, 0, 0.0


# ── API (suppressed unless SPOTIT_CLASSICAL_POST=1) ───────────────────────
def post_to_api(label, snapshot_path, change_pct):
    if not POST_ENABLED:
        print(f"  → [A/B] event suppressed (posting off): {label} MISSING")
        return None

    live_count = BASELINE_COUNT - count_missing_items()
    payload = {
        "api_key": API_KEY,
        "room_id": ROOM_ID,
        "object_type": label,
        "object_zone": label,
        "baseline_count": BASELINE_COUNT,
        "live_count": live_count,
        "roi_change_pct": round(change_pct * 100, 1),
    }
    try:
        with open(snapshot_path, "rb") as img_file:
            files = {
                "snapshot": (os.path.basename(snapshot_path), img_file, "image/jpeg")
            }
            response = requests.post(API_URL, data=payload, files=files, timeout=5)
        if response.status_code == 200:
            result = response.json()
            if result.get("success"):
                detection_id = result.get("detection_id")
                print(
                    f"  → API SUCCESS: detection_id={detection_id}, stage={result.get('stage')}"
                )
                return detection_id
    except Exception as e:
        print(f"  → API CONNECTION FAILED: {e}")
    return None


def post_snapshot_b(label, detection_id, snapshot_path_b):
    if not POST_ENABLED or not detection_id:
        return
    payload = {"api_key": API_KEY, "action": "snapshot_b", "detection_id": detection_id}
    try:
        with open(snapshot_path_b, "rb") as img_file:
            files = {
                "snapshot": (os.path.basename(snapshot_path_b), img_file, "image/jpeg")
            }
            requests.post(API_URL, data=payload, files=files, timeout=5)
    except Exception as e:
        print(f"  → Snapshot B API FAILED: {e}")


def post_mass_deviation():
    if not POST_ENABLED:
        return
    payload = {"api_key": API_KEY, "action": "mass_deviation", "room_id": ROOM_ID}
    try:
        requests.post(API_URL, data=payload, timeout=5)
    except Exception as e:
        print(f"  → Mass Deviation API FAILED: {e}")


# ── MAIN DETECTION LOOP ───────────────────────────────────────────────────
while True:
    ret, frame = cap.read()

    if not ret or frame is None:
        if AB_REPLAY:
            print(f"\n[CLASSICAL][A/B] Clip finished: {AB_CLIP} ({clip_seq} frames scored)")
            break
        print("[WARN] Frame read failed — retrying connection...")
        cap.release()
        time.sleep(2)
        cap = cv2.VideoCapture(RTSP_URL)
        continue

    clip_seq += 1

    if ROTATE_CAMERA:
        frame = cv2.rotate(frame, cv2.ROTATE_90_COUNTERCLOCKWISE)
    frame = rescaleFrame(frame)

    # Reference and live frame must be the same size or absdiff throws. main.py
    # already did this; without it this script died on any resolution mismatch
    # between the saved baseline and the stream.
    if ref_bgr.shape[:2] != frame.shape[:2]:
        print(
            f"[CLASSICAL] Resizing reference {ref_bgr.shape[1]}x{ref_bgr.shape[0]} "
            f"-> {frame.shape[1]}x{frame.shape[0]} to match source."
        )
        ref_bgr = cv2.resize(ref_bgr, (frame.shape[1], frame.shape[0]))
        _rebuild_reference()

    gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
    blur = cv2.GaussianBlur(gray, (21, 21), 0)

    full_diff = cv2.absdiff(ref_blur, blur)
    _, thresh = cv2.threshold(full_diff, THRESHOLD, 255, cv2.THRESH_BINARY)

    output = frame.copy()

    # Elapsed time since the previous frame. In replay this is the clip's own
    # frame interval, so a 1.0 s confirmation means 1.0 s of recorded footage
    # regardless of how fast the file decodes — same treatment as main.py.
    _now = time.time()
    if AB_REPLAY:
        tick_dt = FRAME_DT
    else:
        tick_dt = min(0.5, max(0.0, _now - last_tick_time))
    last_tick_time = _now

    # ── CHECK MONITORING PAUSED ───────────────────────────────────────────
    if monitoring_paused:
        cv2.putText(
            output,
            "MASS DEVIATION DETECTED — paused. (CLASSICAL)",
            (10, 30),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.55,
            (0, 0, 255),
            2,
        )
        cv2.imshow("S.P.O.T.-IT Live Detection (CLASSICAL)", output)
        if cv2.waitKey(1) & 0xFF == ord("q"):
            break
        continue

    # ── GATE 1: SCENE STABILITY ───────────────────────────────────────────
    stable, motion_pct = is_scene_stable(blur)

    if not stable:
        # Timers HOLD, they do not reset — see file header, change (4).
        cv2.imshow("S.P.O.T.-IT Live Detection (CLASSICAL)", output)
        if cv2.waitKey(1) & 0xFF == ord("q"):
            break
        continue

    # ── GATE 2 + 3: ROI CHANGE + TEMPLATE MATCH (the classical baseline) ──
    missing_count = 0

    for roi in roi_list:
        x, y, w, h = roi["x"], roi["y"], roi["w"], roi["h"]
        label = roi["label"]

        changed, change_pct = check_roi(roi, thresh)
        AB_STATS["roi_frames"] += 1
        if changed:
            AB_STATS["changed_frames"] += 1

        is_actually_missing = changed

        # CLASSICAL-ONLY: Standard template matching for personal items, but no MobileNetV2 gate for fixed assets
        # Fixed assets (Tier 1) go straight to checking pixel change.
        fixed_keywords = [
            "monitor", "keyboard", "mouse", "pc", "laptop", "ups",
            "computer", "fan", "headphones", "mini_fan",
        ]
        is_fixed = any(kw in label.lower() for kw in fixed_keywords)

        if changed and not is_fixed:
            # Run template matching for other items
            ref_crop = ref_bgr[y : y + h, x : x + w]
            found_match, fx, fy, f_score = find_item_template_match(frame, ref_crop, roi)
            if found_match:
                is_actually_missing = False
                shifted_locations[label] = (fx, fy, w, h)

        # ── WALL-CLOCK CONFIRMATION (aligned to main.py) ─────────────────
        if is_actually_missing:
            absent_time[label] += tick_dt
            present_time[label] = 0.0
        else:
            present_time[label] += tick_dt
            if present_time[label] >= RECOVER_CONFIRM_SEC:
                absent_time[label] = 0.0
                active_events[label] = False
                active_detection_ids[label] = None
                snapshot_b_triggered[label] = False
                shifted_locations[label] = None

        ab_log_event(label, "ABSENT" if is_actually_missing else "PRESENT")

        confirmed_missing = (
            absent_time[label] >= MISSING_CONFIRM_SEC and not active_events[label]
        )

        if confirmed_missing:
            active_events[label] = True
            snapshot_path = save_snapshot(frame, label)

            print(f"[CANDIDATE EVENT (CLASSICAL-ONLY)] ROI: {label}")
            det_id = post_to_api(label, snapshot_path, change_pct)
            active_detection_ids[label] = det_id
            missing_timestamps[label] = time.time()

            # Auto-Flood gate check
            recent_missing = [
                lbl for lbl, ts in missing_timestamps.items() if time.time() - ts <= 60
            ]
            # Only trigger flood gate if we have at least 4 items and more than 75% of them go missing
            if len(roi_list) >= 4 and len(recent_missing) > 0.75 * len(roi_list):
                monitoring_paused = True
                post_mass_deviation()
                break

        # Snapshot B logic (classical)
        if active_events[label] and not snapshot_b_triggered[label]:
            roi_gray = gray[y : y + h, x : x + w]
            if (
                prev_roi_gray[label] is not None
                and prev_roi_gray[label].shape == roi_gray.shape
            ):
                ftf_diff = cv2.absdiff(prev_roi_gray[label], roi_gray)
                _, ftf_thresh = cv2.threshold(ftf_diff, 15, 255, cv2.THRESH_BINARY)
                motion_val = cv2.countNonZero(ftf_thresh) / (w * h)
                if motion_val >= 0.05:
                    snapshot_b_triggered[label] = True
                    snapshot_path_b = save_snapshot(frame, f"{label}_b")
                    post_snapshot_b(label, active_detection_ids[label], snapshot_path_b)
            prev_roi_gray[label] = roi_gray.copy()

        # Draw box
        if absent_time[label] >= MISSING_CONFIRM_SEC:
            missing_count += 1
            cv2.rectangle(output, (x, y), (x + w, y + h), (0, 0, 255), 2)
        elif absent_time[label] > 0:
            cv2.rectangle(output, (x, y), (x + w, y + h), (0, 255, 255), 2)
        elif shifted_locations[label] is not None:
            cv2.rectangle(output, (x, y), (x + w, y + h), (0, 255, 0), 1)
            sx, sy, sw, sh = shifted_locations[label]
            cv2.rectangle(output, (sx, sy), (sx + sw, sy + sh), (0, 165, 255), 2)
        else:
            cv2.rectangle(output, (x, y), (x + w, y + h), (0, 255, 0), 2)

        cv2.putText(
            output, label, (x, y - 5),
            cv2.FONT_HERSHEY_SIMPLEX, 0.4, (255, 255, 255), 1,
        )

    cv2.putText(
        output,
        f"CLASSICAL-ONLY (no ML) | {ROOM_ID} | missing: {missing_count}/{BASELINE_COUNT}",
        (10, 20),
        cv2.FONT_HERSHEY_SIMPLEX,
        0.5,
        (255, 255, 255),
        1,
    )

    cv2.imshow("S.P.O.T.-IT Live Detection (CLASSICAL)", output)
    if cv2.waitKey(1) & 0xFF == ord("q"):
        break

cap.release()
cv2.destroyAllWindows()
