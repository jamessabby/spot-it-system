import cv2
import json
import numpy as np
import os
import time
import requests
from datetime import datetime

# ── CONFIG ───────────────────────────────────────────────────────────────
STREAM_SCALE        = 0.50
ROI_FILE             = 'rois.json'
REF_IMAGE            = 'photos/ref_image.jpg'
SNAPSHOT_DIR         = 'C:/xampp/htdocs/spotit/uploads/snapshots'
THRESHOLD            = 10
MIN_CHANGE_PERCENT   = 0.08
SCENE_MOTION_LIMIT   = 0.15
CONSISTENCY_FRAMES   = 3
RTSP_URL             = 'rtsp://10.169.96.20/stream'

# ── ROOM / API CONFIG ───────────────────────────────────────────────────────
ROOM_ID           = 'TESTROOM'          # your personal desk test setup
API_URL           = 'http://localhost/spotit/auth/ingest_detection.php'
API_KEY           = 'CHANGE_ME_DETECTION_KEY'   # must match env.php / DETECTION_API_KEY
BASELINE_COUNT    = None                # set below from number of ROIs
# ─────────────────────────────────────────────────────────────────────────

def rescaleFrame(frame, scale=STREAM_SCALE):
    width  = int(frame.shape[1] * scale)
    height = int(frame.shape[0] * scale)
    return cv2.resize(frame, (width, height), interpolation=cv2.INTER_AREA)

os.makedirs(SNAPSHOT_DIR, exist_ok=True)

# ── LOAD ROIs ─────────────────────────────────────────────────────────────
with open(ROI_FILE, 'r') as f:
    roi_list = json.load(f)
print(f"[SPOT-IT] Loaded {len(roi_list)} ROIs: {[r['label'] for r in roi_list]}")

BASELINE_COUNT = len(roi_list)  # all items present = baseline

# ── LOAD REFERENCE FRAME ──────────────────────────────────────────────────
ref_bgr = cv2.imread(REF_IMAGE)
if ref_bgr is None:
    print(f"[ERROR] Cannot load reference image: {REF_IMAGE}")
    exit()
ref_gray = cv2.cvtColor(ref_bgr, cv2.COLOR_BGR2GRAY)
ref_blur = cv2.GaussianBlur(ref_gray, (21, 21), 0)
TARGET_SIZE = (ref_bgr.shape[1], ref_bgr.shape[0])
print(f"[SPOT-IT] Reference frame loaded — size: {TARGET_SIZE}")

print(f"[DEBUG] Reference size: {ref_bgr.shape}")

# ── CONNECT TO RTSP STREAM ────────────────────────────────────────────────
print(f"[SPOT-IT] Connecting to stream: {RTSP_URL}")
cap = cv2.VideoCapture(RTSP_URL)

if not cap.isOpened():
    print("[ERROR] Cannot open RTSP stream. Check:")
    print("  1. iPhone and laptop are on the same WiFi network")
    print("  2. OctoStream is running on your iPhone")
    print(f"  3. URL is correct: {RTSP_URL}")
    exit()

print("[SPOT-IT] Stream connected. Starting detection loop...")
print("[SPOT-IT] Press Q to quit.\n")

# ── PER-ROI STATE ─────────────────────────────────────────────────────────
consistency_count = {roi['label']: 0 for roi in roi_list}
active_events     = {roi['label']: False for roi in roi_list}

# ── HELPERS ───────────────────────────────────────────────────────────────
def is_scene_stable(frame_blur):
    full_diff   = cv2.absdiff(ref_blur, frame_blur)
    _, full_thr = cv2.threshold(full_diff, THRESHOLD, 255, cv2.THRESH_BINARY)
    total_px    = TARGET_SIZE[0] * TARGET_SIZE[1]
    changed_px  = cv2.countNonZero(full_thr)
    motion_pct  = changed_px / total_px
    return motion_pct < SCENE_MOTION_LIMIT, motion_pct

def check_roi(roi, thresh_mask):
    x, y, w, h    = roi['x'], roi['y'], roi['w'], roi['h']
    roi_mask       = thresh_mask[y:y+h, x:x+w]
    total_pixels   = w * h
    changed_pixels = cv2.countNonZero(roi_mask)
    change_pct     = changed_pixels / total_pixels
    return change_pct >= MIN_CHANGE_PERCENT, change_pct

def save_snapshot(frame, label):
    timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
    filename  = f"{label}_{timestamp}.jpg"
    filepath  = os.path.join(SNAPSHOT_DIR, filename)
    cv2.imwrite(filepath, frame)
    return filepath

def count_missing_items():
    """Count how many ROIs are currently confirmed missing (consistency met)."""
    return sum(1 for label in consistency_count if consistency_count[label] >= CONSISTENCY_FRAMES)

def post_to_api(label, snapshot_path, change_pct):
    """
    POST detection event to ingest_detection.php
    Matches the exact fields the PHP endpoint expects.
    """
    live_count = BASELINE_COUNT - count_missing_items()

    payload = {
        'api_key':        API_KEY,
        'room_id':        ROOM_ID,
        'object_type':    label,             # e.g. "tumbler", "mouse"
        'object_zone':    label,             # using ROI label as zone name too
        'baseline_count': BASELINE_COUNT,
        'live_count':     live_count,
        'roi_change_pct': round(change_pct * 100, 1),  # PHP expects percentage e.g. 71.4
    }

    try:
        with open(snapshot_path, 'rb') as img_file:
            files = {'snapshot': (os.path.basename(snapshot_path), img_file, 'image/jpeg')}
            response = requests.post(API_URL, data=payload, files=files, timeout=5)

        if response.status_code == 200:
            result = response.json()
            if result.get('success'):
                print(f"  → API SUCCESS: detection_id={result.get('detection_id')}, "
                      f"deviation={result.get('deviation')}, stage={result.get('stage')}")
            else:
                print(f"  → API ERROR: {result.get('message')}")
        else:
            print(f"  → API HTTP ERROR: {response.status_code} — {response.text[:200]}")

    except requests.exceptions.RequestException as e:
        print(f"  → API CONNECTION FAILED: {e}")

# ── MAIN DETECTION LOOP ───────────────────────────────────────────────────
while True:
    ret, frame = cap.read()

    if not ret:
        print("[WARN] Frame read failed — retrying connection...")
        cap.release()
        time.sleep(2)
        cap = cv2.VideoCapture(RTSP_URL)
        continue

    frame = cv2.rotate(frame, cv2.ROTATE_90_COUNTERCLOCKWISE)
    frame = rescaleFrame(frame)
    print(f"[DEBUG] Live frame size: {frame.shape}")

    gray  = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
    blur  = cv2.GaussianBlur(gray, (21, 21), 0)

    full_diff = cv2.absdiff(ref_blur, blur)
    _, thresh = cv2.threshold(full_diff, THRESHOLD, 255, cv2.THRESH_BINARY)

    output = frame.copy()

    # ── GATE 1: SCENE STABILITY ───────────────────────────────────────────
    stable, motion_pct = is_scene_stable(blur)

    if not stable:
        for roi in roi_list:
            consistency_count[roi['label']] = 0

        cv2.putText(output, f"SCENE UNSTABLE — paused ({motion_pct:.1%} motion)",
                    (10, 30), cv2.FONT_HERSHEY_SIMPLEX, 0.55, (0, 165, 255), 2)

        for roi in roi_list:
            x, y, w, h = roi['x'], roi['y'], roi['w'], roi['h']
            cv2.rectangle(output, (x, y), (x+w, y+h), (0, 165, 255), 2)
            cv2.putText(output, roi['label'], (x, y - 5),
                        cv2.FONT_HERSHEY_SIMPLEX, 0.45, (0, 165, 255), 1)

        cv2.imshow("S.P.O.T.-IT Live Detection", output)
        if cv2.waitKey(1) & 0xFF == ord('q'):
            break
        continue

    # ── GATE 2 + 3: ROI CHANGE + FRAME CONSISTENCY ───────────────────────
    missing_count = 0

    for roi in roi_list:
        x, y, w, h = roi['x'], roi['y'], roi['w'], roi['h']
        label      = roi['label']

        changed, change_pct = check_roi(roi, thresh)

        if changed:
            consistency_count[label] += 1
        else:
            consistency_count[label] = 0
            active_events[label]     = False

        confirmed_missing = (
            consistency_count[label] >= CONSISTENCY_FRAMES
            and not active_events[label]
        )

        if confirmed_missing:
            active_events[label] = True
            snapshot_path = save_snapshot(frame, label)

            print(f"\n[CANDIDATE EVENT DETECTED]")
            print(f"  ROI Label    : {label}")
            print(f"  Room ID      : {ROOM_ID}")
            print(f"  Detected At  : {datetime.now().isoformat()}")
            print(f"  Snapshot     : {snapshot_path}")
            print(f"  Change       : {change_pct:.1%} over {CONSISTENCY_FRAMES} consecutive frames")

            post_to_api(label, snapshot_path, change_pct)
            print()

        # ── DRAW ROI BOXES ────────────────────────────────────────────────
        if consistency_count[label] >= CONSISTENCY_FRAMES:
            missing_count += 1
            cv2.rectangle(output, (x, y), (x+w, y+h), (0, 0, 255), 2)
            cv2.putText(output, f"MISSING: {label}", (x, y - 10),
                        cv2.FONT_HERSHEY_SIMPLEX, 0.5, (0, 0, 255), 2)
        elif consistency_count[label] > 0:
            cv2.rectangle(output, (x, y), (x+w, y+h), (0, 255, 255), 2)
            cv2.putText(output, f"CHECKING: {label} ({consistency_count[label]}/{CONSISTENCY_FRAMES})",
                        (x, y - 10), cv2.FONT_HERSHEY_SIMPLEX, 0.45, (0, 255, 255), 1)
        else:
            cv2.rectangle(output, (x, y), (x+w, y+h), (0, 255, 0), 2)
            cv2.putText(output, f"OK: {label}", (x, y - 10),
                        cv2.FONT_HERSHEY_SIMPLEX, 0.5, (0, 255, 0), 1)

    # ── STATUS BAR ────────────────────────────────────────────────────────
    if missing_count > 0:
        status_text  = f"ALERT: {missing_count} item(s) missing"
        status_color = (0, 0, 255)
    else:
        status_text  = "All items present"
        status_color = (0, 255, 0)

    cv2.putText(output, status_text, (10, 30),
                cv2.FONT_HERSHEY_SIMPLEX, 0.8, status_color, 2)
    cv2.putText(output, f"Scene motion: {motion_pct:.1%}", (10, TARGET_SIZE[1] - 10),
                cv2.FONT_HERSHEY_SIMPLEX, 0.4, (200, 200, 200), 1)

    cv2.imshow("S.P.O.T.-IT Live Detection", output)

    if cv2.waitKey(1) & 0xFF == ord('q'):
        break

cap.release()
cv2.destroyAllWindows()
print("[SPOT-IT] Stream closed.")