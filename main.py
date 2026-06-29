import cv2
import json
import numpy as np
import os
import time
import requests
from datetime import     datetime

# ── CONFIG ───────────────────────────────────────────────────────────────
TARGET_SIZE         = (640, 480)
ROI_FILE            = 'rois.json'
REF_IMAGE           = 'photos/ref_image.jpg'
SNAPSHOT_DIR        = 'photos/snapshots'    # save photos where item goes missing
THRESHOLD           = 25    # If a pixel shifts in brightness by more than 25 units, it counts as a real visual change.
MIN_CHANGE_PERCENT  = 0.08   # 8% of ROI pixels = significant change
SCENE_MOTION_LIMIT  = 0.40   # 40% of full frame moving = scene unstable
CONSISTENCY_FRAMES  = 3      # change must persist this many frames to trigger
RTSP_URL            = 'rtsp://192.168.1.163/stream'
ROOM_ID             = 1      # matches rooms.room_id in your DB
API_URL             = 'http://localhost:3000/detections'
# ─────────────────────────────────────────────────────────────────────────

os.makedirs(SNAPSHOT_DIR, exist_ok=True)

# ── LOAD ROIs ─────────────────────────────────────────────────────────────
with open(ROI_FILE, 'r') as f:
    roi_list = json.load(f)
print(f"[SPOT-IT] Loaded {len(roi_list)} ROIs: {[r['label'] for r in roi_list]}")

# ── LOAD REFERENCE FRAME ──────────────────────────────────────────────────
ref_bgr = cv2.imread(REF_IMAGE)
if ref_bgr is None:
    print(f"[ERROR] Cannot load reference image: {REF_IMAGE}")
    exit()
ref_bgr   = cv2.resize(ref_bgr, TARGET_SIZE)
ref_gray  = cv2.cvtColor(ref_bgr, cv2.COLOR_BGR2GRAY)
ref_blur  = cv2.GaussianBlur(ref_gray, (21, 21), 0)
print(f"[SPOT-IT] Reference frame loaded from {REF_IMAGE}")

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

# ── PER-ROI CONSISTENCY COUNTERS ──────────────────────────────────────────
consistency_count = {roi['label']: 0 for roi in roi_list}
active_events     = {roi['label']: False for roi in roi_list}

# ── HELPERS ───────────────────────────────────────────────────────────────
def is_scene_stable(frame_blur):
    full_diff   = cv2.absdiff(ref_blur, frame_blur) # Subtracts your clean baseline reference image from the current live video frame.
    _, full_thr = cv2.threshold(full_diff, THRESHOLD, 255, cv2.THRESH_BINARY)   # Turns everything black except for areas where movement occurred, which turn bright white.
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

def build_candidate_event(label, roi, snapshot_path, change_pct):
    return {
        "room_id":       ROOM_ID,
        "object_type":   "missing_item",
        "object_zone":   label,
        "detected_at":   datetime.now().isoformat(),
        "snapshot_path": snapshot_path,
        "status":        "pending",
        "notes":         f"ROI change: {change_pct:.1%} over {CONSISTENCY_FRAMES} consecutive frames"
    }

def post_to_api(event):
    """
    POST the candidate event to the Node.js backend.
    Fails silently so a network error never crashes the detection loop.
    """
    try:
        response = requests.post(API_URL, json=event, timeout=3)
        if response.status_code == 201:
            data = response.json()
            print(f"  → API accepted. Detection ID: {data['detection']['id']}")
        else:
            print(f"  → API returned {response.status_code}: {response.text}")
    except requests.exceptions.ConnectionError:
        print(f"  → API offline (server.js not running). Event logged locally only.")
    except Exception as e:
        print(f"  → API error: {e}")

# ── MAIN DETECTION LOOP ───────────────────────────────────────────────────
while True:
    ret, frame = cap.read()

    if not ret:
        print("[WARN] Frame read failed — retrying connection...")
        cap.release()
        time.sleep(2)
        cap = cv2.VideoCapture(RTSP_URL)
        continue

    frame     = cv2.resize(frame, TARGET_SIZE)
    frame     = cv2.rotate(frame, cv2.ROTATE_90_COUNTERCLOCKWISE)
    frame     = cv2.resize(frame, TARGET_SIZE)  # re-resize after rotate to lock to 640x480
    gray      = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
    blur      = cv2.GaussianBlur(gray, (21, 21), 0)

    full_diff  = cv2.absdiff(ref_blur, blur)    # core pixel subtraction
    _, thresh  = cv2.threshold(full_diff, THRESHOLD, 255, cv2.THRESH_BINARY)    # applies threshold filter

    output = frame.copy()

    # ── GATE 1: SCENE STABILITY ───────────────────────────────────────────
    stable, motion_pct = is_scene_stable(blur)

    if not stable:
        for roi in roi_list:
            consistency_count[roi['label']] = 0

        cv2.putText(output, f"SCENE UNSTABLE — monitoring paused ({motion_pct:.1%} motion)",
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
            event = build_candidate_event(label, roi, snapshot_path, change_pct)

            print(f"\n[CANDIDATE EVENT DETECTED]")
            print(f"  ROI Label    : {event['object_zone']}")
            print(f"  Room ID      : {event['room_id']}")
            print(f"  Detected At  : {event['detected_at']}")
            print(f"  Snapshot     : {event['snapshot_path']}")
            print(f"  Change       : {event['notes']}")
            print(f"  Status       : {event['status']}")

            # ── POST to Node.js API ──────────────────────────────────────
            post_to_api(event)

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

# ── CLEANUP ───────────────────────────────────────────────────────────────
cap.release()
cv2.destroyAllWindows()
print("[SPOT-IT] Stream closed.")