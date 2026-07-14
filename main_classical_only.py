import cv2
import json
import numpy as np
import os
import time
import requests
from datetime import datetime

# ── CONFIG ───────────────────────────────────────────────────────────────
STREAM_SCALE              = 0.50
ROI_FILE                   = 'rois.json'
REF_IMAGE                  = 'photos/ref_image.jpg'
SNAPSHOT_DIR               = 'C:/xampp/htdocs/spotit/uploads/snapshots'
THRESHOLD                  = 25          # delta needed to count as changed
MIN_CHANGE_PERCENT         = 0.08
SCENE_MOTION_LIMIT         = 0.40        # % of frame stable
CONSISTENCY_FRAMES         = 5           # consecutive frames to confirm
RTSP_URL                   = 'rtsp://SpotItCamera:spotittapo232@192.168.18.11:554/stream1'
ROTATE_CAMERA              = False       # Set to True for portrait iPhone, False for Tapo CCTV
MATCH_SCORE_THRESHOLD      = 0.55        # threshold score for template matching (0.45 - 0.65)

# ── ROOM / API CONFIG ───────────────────────────────────────────────────────
ROOM_ID           = 'DESK'              # changed to DESK for personal desk testing
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
print(f"[SPOT-IT][CLASSICAL] Loaded {len(roi_list)} ROIs: {[r['label'] for r in roi_list]}")

BASELINE_COUNT = len(roi_list)  # all items present = baseline

# ── LOAD REFERENCE FRAME ──────────────────────────────────────────────────
ref_bgr = cv2.imread(REF_IMAGE)
if ref_bgr is None:
    print(f"[ERROR] Cannot load reference image: {REF_IMAGE}")
    exit()
ref_gray = cv2.cvtColor(ref_bgr, cv2.COLOR_BGR2GRAY)
ref_blur = cv2.GaussianBlur(ref_gray, (21, 21), 0)
TARGET_SIZE = (ref_bgr.shape[1], ref_bgr.shape[0])
print(f"[SPOT-IT][CLASSICAL] Reference frame loaded — size: {TARGET_SIZE}")

# ── CONNECT TO RTSP STREAM ────────────────────────────────────────────────
print(f"[SPOT-IT][CLASSICAL] Connecting to stream: {RTSP_URL}")
cap = cv2.VideoCapture(RTSP_URL)

if not cap.isOpened():
    print("[ERROR] Cannot open RTSP stream. Check camera connection.")
    exit()

print("[SPOT-IT][CLASSICAL] Stream connected. Starting detection loop...")
print("[SPOT-IT][CLASSICAL] Press Q to quit.\n")

# ── PER-ROI STATE ─────────────────────────────────────────────────────────
consistency_count = {roi['label']: 0 for roi in roi_list}
active_events     = {roi['label']: False for roi in roi_list}

# State Variables
active_detection_ids = {roi['label']: None for roi in roi_list}
snapshot_b_triggered = {roi['label']: False for roi in roi_list}
prev_roi_gray        = {roi['label']: None for roi in roi_list}
shifted_locations    = {roi['label']: None for roi in roi_list}
missing_timestamps   = {}
monitoring_paused    = False

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
    filename  = f"{label}_{timestamp}_classical.jpg"
    filepath  = os.path.join(SNAPSHOT_DIR, filename)
    cv2.imwrite(filepath, frame)
    return filepath

def count_missing_items():
    return sum(1 for label in consistency_count if consistency_count[label] >= CONSISTENCY_FRAMES)

def find_item_template_match(frame, ref_crop, roi, match_threshold=MATCH_SCORE_THRESHOLD):
    h_f, w_f = frame.shape[:2]
    x, y, w, h = roi['x'], roi['y'], roi['w'], roi['h']
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
            
        search_area = frame[sy:sy+sh, sx:sx+sw]
        res = cv2.matchTemplate(search_area, ref_crop, cv2.TM_CCOEFF_NORMED)
        _, max_val, _, max_loc = cv2.minMaxLoc(res)
        
        if max_val >= match_threshold:
            found_x = sx + max_loc[0]
            found_y = sy + max_loc[1]
            return True, found_x, found_y, max_val
            
    return False, 0, 0, 0.0

def post_to_api(label, snapshot_path, change_pct):
    live_count = BASELINE_COUNT - count_missing_items()
    payload = {
        'api_key':        API_KEY,
        'room_id':        ROOM_ID,
        'object_type':    label,
        'object_zone':    label,
        'baseline_count': BASELINE_COUNT,
        'live_count':     live_count,
        'roi_change_pct': round(change_pct * 100, 1),
    }
    try:
        with open(snapshot_path, 'rb') as img_file:
            files = {'snapshot': (os.path.basename(snapshot_path), img_file, 'image/jpeg')}
            response = requests.post(API_URL, data=payload, files=files, timeout=5)
        if response.status_code == 200:
            result = response.json()
            if result.get('success'):
                detection_id = result.get('detection_id')
                print(f"  → API SUCCESS: detection_id={detection_id}, stage={result.get('stage')}")
                return detection_id
    except Exception as e:
        print(f"  → API CONNECTION FAILED: {e}")
    return None

def post_snapshot_b(label, detection_id, snapshot_path_b):
    if not detection_id:
        return
    payload = {
        'api_key':      API_KEY,
        'action':       'snapshot_b',
        'detection_id': detection_id,
    }
    try:
        with open(snapshot_path_b, 'rb') as img_file:
            files = {'snapshot': (os.path.basename(snapshot_path_b), img_file, 'image/jpeg')}
            requests.post(API_URL, data=payload, files=files, timeout=5)
    except Exception as e:
        print(f"  → Snapshot B API FAILED: {e}")

def post_mass_deviation():
    payload = {
        'api_key': API_KEY,
        'action':  'mass_deviation',
        'room_id': ROOM_ID,
    }
    try:
        requests.post(API_URL, data=payload, timeout=5)
    except Exception as e:
        print(f"  → Mass Deviation API FAILED: {e}")

# ── MAIN DETECTION LOOP ───────────────────────────────────────────────────
while True:
    ret, frame = cap.read()

    if not ret:
        print("[WARN] Frame read failed — retrying connection...")
        cap.release()
        time.sleep(2)
        cap = cv2.VideoCapture(RTSP_URL)
        continue

    if ROTATE_CAMERA:
        frame = cv2.rotate(frame, cv2.ROTATE_90_COUNTERCLOCKWISE)
    frame = rescaleFrame(frame)

    gray  = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
    blur  = cv2.GaussianBlur(gray, (21, 21), 0)

    full_diff = cv2.absdiff(ref_blur, blur)
    _, thresh = cv2.threshold(full_diff, THRESHOLD, 255, cv2.THRESH_BINARY)

    output = frame.copy()

    # ── CHECK MONITORING PAUSED ───────────────────────────────────────────
    if monitoring_paused:
        cv2.putText(output, "MASS DEVIATION DETECTED — paused. (CLASSICAL)",
                    (10, 30), cv2.FONT_HERSHEY_SIMPLEX, 0.55, (0, 0, 255), 2)
        cv2.imshow("S.P.O.T.-IT Live Detection (CLASSICAL)", output)
        if cv2.waitKey(1) & 0xFF == ord('q'):
            break
        continue

    # ── GATE 1: SCENE STABILITY ───────────────────────────────────────────
    stable, motion_pct = is_scene_stable(blur)

    if not stable:
        for roi in roi_list:
            consistency_count[roi['label']] = 0
        cv2.imshow("S.P.O.T.-IT Live Detection (CLASSICAL)", output)
        if cv2.waitKey(1) & 0xFF == ord('q'):
            break
        continue

    # ── GATE 2 + 3: ROI CHANGE + FRAME CONSISTENCY ───────────────────────
    missing_count = 0

    for roi in roi_list:
        x, y, w, h = roi['x'], roi['y'], roi['w'], roi['h']
        label      = roi['label']

        changed, change_pct = check_roi(roi, thresh)
        is_actually_missing = changed

        # CLASSICAL-ONLY: Standard template matching for personal items, but no MobileNetV2 gate for fixed assets
        # Fixed assets (Tier 1) go straight to checking pixel change.
        fixed_keywords = ['monitor', 'keyboard', 'mouse', 'pc', 'ups', 'computer', 'fan', 'headphones', 'mini_fan']
        is_fixed = any(kw in label.lower() for kw in fixed_keywords)

        if changed and not is_fixed:
            # Run template matching for other items
            ref_crop = ref_bgr[y:y+h, x:x+w]
            found_match, fx, fy, f_score = find_item_template_match(frame, ref_crop, roi)
            if found_match:
                is_actually_missing = False
                shifted_locations[label] = (fx, fy, w, h)

        if is_actually_missing:
            consistency_count[label] += 1
        else:
            consistency_count[label] = 0
            active_events[label]     = False
            active_detection_ids[label] = None
            snapshot_b_triggered[label] = False
            shifted_locations[label] = None

        confirmed_missing = (
            consistency_count[label] >= CONSISTENCY_FRAMES
            and not active_events[label]
        )

        if confirmed_missing:
            active_events[label] = True
            snapshot_path = save_snapshot(frame, label)

            print(f"[CANDIDATE EVENT (CLASSICAL-ONLY)] ROI: {label}")
            det_id = post_to_api(label, snapshot_path, change_pct)
            active_detection_ids[label] = det_id
            missing_timestamps[label] = time.time()

            # Auto-Flood gate check
            recent_missing = [lbl for lbl, ts in missing_timestamps.items() if time.time() - ts <= 60]
            # Only trigger flood gate if we have at least 4 items and more than 75% of them go missing
            if len(roi_list) >= 4 and len(recent_missing) > 0.75 * len(roi_list):
                monitoring_paused = True
                post_mass_deviation()
                break

        # Snapshot B logic (classical)
        if active_events[label] and not snapshot_b_triggered[label]:
            roi_gray = gray[y:y+h, x:x+w]
            if prev_roi_gray[label] is not None:
                ftf_diff = cv2.absdiff(prev_roi_gray[label], roi_gray)
                _, ftf_thresh = cv2.threshold(ftf_diff, 15, 255, cv2.THRESH_BINARY)
                motion_val = cv2.countNonZero(ftf_thresh) / (w * h)
                if motion_val >= 0.05:
                    snapshot_b_triggered[label] = True
                    snapshot_path_b = save_snapshot(frame, f"{label}_b")
                    post_snapshot_b(label, active_detection_ids[label], snapshot_path_b)
            prev_roi_gray[label] = roi_gray

        # Draw box
        if consistency_count[label] >= CONSISTENCY_FRAMES:
            missing_count += 1
            cv2.rectangle(output, (x, y), (x+w, y+h), (0, 0, 255), 2)
        elif consistency_count[label] > 0:
            cv2.rectangle(output, (x, y), (x+w, y+h), (0, 255, 255), 2)
        elif shifted_locations[label] is not None:
            cv2.rectangle(output, (x, y), (x+w, y+h), (0, 255, 0), 1)
            sx, sy, sw, sh = shifted_locations[label]
            cv2.rectangle(output, (sx, sy), (sx+sw, sy+sh), (0, 165, 255), 2)
        else:
            cv2.rectangle(output, (x, y), (x+w, y+h), (0, 255, 0), 2)

    cv2.imshow("S.P.O.T.-IT Live Detection (CLASSICAL)", output)
    if cv2.waitKey(1) & 0xFF == ord('q'):
        break

cap.release()
cv2.destroyAllWindows()
