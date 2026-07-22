import os
import time

# ── FFMPEG RTSP SOCKET TIMEOUT ──────────────────────────────────────────────
# Must be set before cv2 is imported so OpenCV's backend respects this variable!
os.environ['OPENCV_FFMPEG_CAPTURE_OPTIONS'] = 'rtsp_transport;tcp|stimeout;5000000'

import cv2
import json
import numpy as np
import requests
from datetime import datetime

# ── CONFIG ───────────────────────────────────────────────────────────────
STREAM_SCALE              = 0.50
ROI_FILE                   = 'rois.json'
REF_IMAGE                  = 'photos/ref_image.jpg'
SNAPSHOT_DIR               = 'C:/xampp/htdocs/spotit/uploads/snapshots'
THRESHOLD                  = 25          # delta needed to count as changed (was 10)
MIN_CHANGE_PERCENT         = 0.08
SCENE_MOTION_LIMIT         = 0.40        # % of frame stable (was 0.15)
CONSISTENCY_FRAMES         = 5           # consecutive frames to confirm (was 3)
RTSP_URL                   = 'rtsp://SpotItCamera:spotittapo232@192.168.18.11:554/stream1'
ROTATE_CAMERA              = False       # Set to True for portrait iPhone, False for Tapo CCTV
MATCH_SCORE_THRESHOLD      = 0.55        # threshold score for template matching (calibrated for RTSP stream)

# ── ROOM / API CONFIG ───────────────────────────────────────────────────────
ROOM_ID           = 'DESK'              # changed to DESK for personal desk testing
API_URL           = 'http://localhost/spotit/auth/ingest_detection.php'
API_KEY           = 'CHANGE_ME_DETECTION_KEY'   # must match env.php / DETECTION_API_KEY
SHOW_GUI          = False                # Set to False to run headless (no floating window)
BASELINE_COUNT    = None                # set below from number of ROIs
# ─────────────────────────────────────────────────────────────────────────

# ── MOBILENETV2 DNN GATEKEEPER CONFIG (Phase 2, Step 2.1) ──────────────────
# Per CLAUDE.md §3a: MobileNetV2 is a SECONDARY validation gate only. The
# classical absdiff/threshold pipeline above still does the first-pass
# "did anything change" job — this DNN only answers "is the target object
# still visually present in the flagged ROI crop, regardless of exact pose."
DNN_MODEL_DIR             = 'models'
DNN_MODEL_PATH            = os.path.join(DNN_MODEL_DIR, 'mobilenetv2-7.onnx')
DNN_RAW_LABELS_PATH       = os.path.join(DNN_MODEL_DIR, 'synset_raw.txt')
DNN_LABELS_PATH           = os.path.join(DNN_MODEL_DIR, 'imagenet_classes.txt')

DNN_INPUT_SIZE            = (224, 224)   # MobileNetV2 expects 224x224 RGB
DNN_TOP_K                 = 5            # target_label must appear in top-K predictions
DNN_CONFIDENCE_THRESHOLD  = 0.15         # min softmax confidence to count as "present"

# ImageNet normalization constants (torchvision/ONNX MobileNetV2 preprocessing)
IMAGENET_MEAN = np.array([0.485, 0.456, 0.406], dtype=np.float32)
IMAGENET_STD  = np.array([0.229, 0.224, 0.225], dtype=np.float32)

# Multiple sources tried in order — onnx/models moved to Git LFS-only hosting
# and Hugging Face mirrors it without requiring LFS on the client side, so
# it's listed first for reliability.
MOBILENET_MODEL_URLS = [
    'https://huggingface.co/onnxmodelzoo/mobilenetv2-7/resolve/main/mobilenetv2-7.onnx',
    'https://github.com/onnx/models/raw/main/validated/vision/classification/mobilenet/model/mobilenetv2-7.onnx',
]
MOBILENET_LABELS_URLS = [
    'https://raw.githubusercontent.com/onnx/models/main/validated/vision/classification/synset.txt',
]
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
    print(f"[SPOT-IT] Reference image missing! Auto-capturing baseline from stream...")
    cap_temp = cv2.VideoCapture(RTSP_URL)
    ret_temp, frame_temp = cap_temp.read()
    if ret_temp:
        width = int(frame_temp.shape[1] * STREAM_SCALE)
        height = int(frame_temp.shape[0] * STREAM_SCALE)
        resized_temp = cv2.resize(frame_temp, (width, height), interpolation=cv2.INTER_AREA)
        os.makedirs(os.path.dirname(REF_IMAGE), exist_ok=True)
        cv2.imwrite(REF_IMAGE, resized_temp)
        print(f"[SPOT-IT] Successfully captured and saved new baseline: {REF_IMAGE}")
        ref_bgr = resized_temp
    else:
        print(f"[ERROR] Failed to connect to stream to capture baseline. Check IP/Network.")
        exit()
    cap_temp.release()
ref_gray = cv2.cvtColor(ref_bgr, cv2.COLOR_BGR2GRAY)
ref_blur = cv2.GaussianBlur(ref_gray, (21, 21), 0)
TARGET_SIZE = (ref_bgr.shape[1], ref_bgr.shape[0])
print(f"[SPOT-IT] Reference frame loaded — size: {TARGET_SIZE}")

print(f"[DEBUG] Reference size: {ref_bgr.shape}")

# ── MOBILENETV2 DNN GATEKEEPER — DOWNLOAD + INIT ──────────────────────────
def _download_with_fallback(urls, dest_path, min_size_bytes=1024, description="file"):
    """
    Downloads `dest_path` from the first URL in `urls` that works.
    Skips the download entirely if a valid-looking file already exists.
    Guards against saving HTML error pages or Git-LFS pointer stubs by
    rejecting anything smaller than `min_size_bytes`.
    """
    if os.path.exists(dest_path) and os.path.getsize(dest_path) >= min_size_bytes:
        print(f"[SPOT-IT][DNN] {description} already present at {dest_path} — skipping download.")
        return True

    os.makedirs(os.path.dirname(dest_path), exist_ok=True)

    for url in urls:
        try:
            print(f"[SPOT-IT][DNN] Downloading {description} from {url} ...")
            resp = requests.get(url, stream=True, timeout=30)
            resp.raise_for_status()

            tmp_path = dest_path + '.tmp'
            with open(tmp_path, 'wb') as f:
                for chunk in resp.iter_content(chunk_size=8192):
                    if chunk:
                        f.write(chunk)

            if os.path.getsize(tmp_path) < min_size_bytes:
                print(f"[SPOT-IT][DNN][WARN] Downloaded file too small "
                      f"({os.path.getsize(tmp_path)} bytes) — probably not the "
                      f"real file (e.g. an LFS pointer or HTML error page). "
                      f"Trying next source...")
                os.remove(tmp_path)
                continue

            os.replace(tmp_path, dest_path)
            print(f"[SPOT-IT][DNN] Saved {description} -> {dest_path}")
            return True

        except Exception as e:
            print(f"[SPOT-IT][DNN][WARN] Failed to download from {url}: {e}")
            continue

    print(f"[SPOT-IT][DNN][ERROR] All sources failed for {description}. "
          f"DNN gatekeeper will be disabled and validate_presence_dnn() will fail OPEN.")
    return False


def _build_clean_labels_file(raw_path, clean_path):
    """
    Converts the raw ImageNet synset.txt (format: 'n01440764 tench, Tinca tinca')
    into a plain ordered list of class-name strings, one per line, index-aligned
    with MobileNetV2's 1000-way softmax output.
    """
    with open(raw_path, 'r') as f_in:
        lines = f_in.readlines()

    cleaned = []
    for line in lines:
        line = line.strip()
        if not line:
            continue
        parts = line.split(' ', 1)  # drop the leading wnid (e.g. 'n01440764')
        cleaned.append(parts[1] if len(parts) > 1 else parts[0])

    with open(clean_path, 'w') as f_out:
        f_out.write('\n'.join(cleaned))

    print(f"[SPOT-IT][DNN] Parsed {len(cleaned)} class labels -> {clean_path}")


def ensure_mobilenet_assets():
    """Downloads the MobileNetV2 ONNX model + ImageNet labels if not already present."""
    model_ok = _download_with_fallback(
        MOBILENET_MODEL_URLS, DNN_MODEL_PATH,
        min_size_bytes=1_000_000, description="MobileNetV2 ONNX model"
    )
    labels_ok = _download_with_fallback(
        MOBILENET_LABELS_URLS, DNN_RAW_LABELS_PATH,
        min_size_bytes=5_000, description="ImageNet synset labels"
    )
    if labels_ok and not os.path.exists(DNN_LABELS_PATH):
        _build_clean_labels_file(DNN_RAW_LABELS_PATH, DNN_LABELS_PATH)

    return model_ok and os.path.exists(DNN_LABELS_PATH)


dnn_net = None
DNN_CLASS_LABELS = []

if ensure_mobilenet_assets():
    try:
        dnn_net = cv2.dnn.readNetFromONNX(DNN_MODEL_PATH)
        dnn_net.setPreferableBackend(cv2.dnn.DNN_BACKEND_OPENCV)
        dnn_net.setPreferableTarget(cv2.dnn.DNN_TARGET_CPU)
        with open(DNN_LABELS_PATH, 'r') as f:
            DNN_CLASS_LABELS = [line.strip() for line in f if line.strip()]
        print(f"[SPOT-IT][DNN] MobileNetV2 gatekeeper loaded — "
              f"{len(DNN_CLASS_LABELS)} ImageNet classes ready.")
    except Exception as e:
        print(f"[SPOT-IT][DNN][ERROR] Failed to initialize DNN network: {e}")
        print("[SPOT-IT][DNN] Continuing with classical-only detection "
              "(validate_presence_dnn will fail OPEN).")
        dnn_net = None
        DNN_CLASS_LABELS = []
else:
    print("[SPOT-IT][DNN] MobileNetV2 assets unavailable — "
          "continuing with classical-only detection.")


def validate_presence_dnn(roi_crop, target_label, top_k=DNN_TOP_K,
                           conf_threshold=DNN_CONFIDENCE_THRESHOLD, verbose=False):
    """
    Secondary ML validation gate (gatekeeper pattern, CLAUDE.md §3a).

    Classifies a cropped ROI image with MobileNetV2 (ImageNet-1k, 1000 classes)
    and checks whether `target_label` appears among the model's top-K predicted
    classes with sufficient confidence. This does NOT replace the classical
    absdiff/threshold pipeline — it is only called on crops the classical
    pipeline has already flagged as changed, to answer "is the expected object
    still visually present here" (e.g. after being nudged, not removed).

    Args:
        roi_crop     : BGR image crop (numpy array) of the flagged ROI.
        target_label : Expected object label, e.g. "keyboard", "mouse",
                        "monitor", "backpack". Matched case-insensitively
                        against MobileNetV2's ImageNet class names.
        top_k         : How many top predictions to search for a match.
        conf_threshold: Minimum softmax confidence required to count as present.
        verbose       : Whether to print classification diagnostic output.

    Returns:
        (is_present: bool, confidence: float)
        - If the target label is found in the top-K predictions with
          confidence >= conf_threshold  -> (True, confidence)
        - If found but below threshold, or not found at all -> (False, confidence
          of the best matching class, or of the top-1 class if no match)
        - If the DNN isn't available or inference fails, this fails OPEN
          (True, 0.0) so a missing/broken model never blocks the classical
          pipeline's own decision-making.
    """
    if dnn_net is None or not DNN_CLASS_LABELS or roi_crop is None or roi_crop.size == 0:
        return True, 0.0

    try:
        resized = cv2.resize(roi_crop, DNN_INPUT_SIZE, interpolation=cv2.INTER_AREA)
        rgb = cv2.cvtColor(resized, cv2.COLOR_BGR2RGB).astype(np.float32) / 255.0
        normalized = (rgb - IMAGENET_MEAN) / IMAGENET_STD
        blob = normalized.transpose(2, 0, 1)[np.newaxis, :, :, :].astype(np.float32)

        dnn_net.setInput(blob)
        raw_output = dnn_net.forward().flatten()

        exp_scores = np.exp(raw_output - np.max(raw_output))
        probs = exp_scores / exp_scores.sum()

        top_indices = np.argsort(probs)[::-1][:top_k]
        target = target_label.strip().lower()

        # Map custom labels to standard ImageNet synonyms
        label_mappings = {
            'mini_fan': ['fan', 'blower', 'ventilator'],
            'headphones': ['headphones', 'earphone', 'headset'],
            'tumbler': ['tumbler', 'cup', 'mug', 'bottle', 'water bottle'],
            'box': ['box', 'carton', 'crate', 'package', 'container', 'chest', 'cube', 'block'],
            'mouse': ['mouse', 'computer mouse', 'pointing device', 'trackball'],
            'keyboard': ['keyboard', 'computer keyboard', 'typewriter keyboard', 'keypad'],
            'monitor': ['monitor', 'screen', 'television', 'computer monitor', 'display'],
            'pc': ['desktop computer', 'computer', 'screen', 'monitor'],
            'computer': ['desktop computer', 'computer', 'screen', 'monitor'],
            'watch': ['watch', 'stopwatch', 'digital watch', 'wrist watch', 'wristwatch', 'clock', 'timepiece'],
        }

        search_terms = [target]
        if target in label_mappings:
            search_terms.extend(label_mappings[target])

        for idx in top_indices:
            class_names = [n.strip().lower() for n in DNN_CLASS_LABELS[idx].split(',')]
            for term in search_terms:
                if any(term == name or term in name or name in term for name in class_names):
                    confidence = float(probs[idx])
                    is_present = (confidence >= conf_threshold)
                    if verbose:
                        print(f"[SPOT-IT][DNN] Target '{target_label}' matched ImageNet class '{DNN_CLASS_LABELS[idx]}' "
                              f"with {confidence:.1%} confidence (thresh: {conf_threshold:.1%}) -> Result: {'Present' if is_present else 'Absent'}")
                    return is_present, confidence

        # target_label never appeared in the top-K at all
        top1_idx = top_indices[0]
        top1_name = DNN_CLASS_LABELS[top1_idx]
        top1_confidence = float(probs[top1_idx])
        if verbose:
            print(f"[SPOT-IT][DNN] Target '{target_label}' NOT in top-{top_k} predictions. Top 1 predicted: '{top1_name}' ({top1_confidence:.1%}) -> Result: Absent")
        return False, top1_confidence

    except Exception as e:
        print(f"[SPOT-IT][DNN][WARN] Inference failed: {e} — failing OPEN.")
        return True, 0.0
# ─────────────────────────────────────────────────────────────────────────

# ── THREADED VIDEO STREAM TO ELIMINATE RTSP LATENCY ───────────────────────
import threading

class VideoStream:
    STALE_TIMEOUT_SEC = 8  # reconnect if stream goes dead for 8 seconds

    def __init__(self, src):
        self.src = src
        self.cap = None
        self.ret = False
        self.frame = None
        self.last_good_frame_at = time.time()
        self.stopped = False
        self.lock = threading.Lock()

    def start(self):
        threading.Thread(target=self.update, args=(), daemon=True).start()
        threading.Thread(target=self._watchdog, args=(), daemon=True).start()
        return self

    def update(self):
        # Asynchronously connect in the background to avoid startup hang
        new_cap = cv2.VideoCapture(self.src)
        new_cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)
        with self.lock:
            self.cap = new_cap
            self.last_good_frame_at = time.time()

        while not self.stopped:
            if self.cap is None:
                time.sleep(0.5)
                continue
            ret, frame = self.cap.read()
            if ret:
                with self.lock:
                    self.ret = ret
                    self.frame = frame
                    self.last_good_frame_at = time.time()
            else:
                time.sleep(0.1)

    def _reconnect(self):
        print(f"[SPOT-IT][WATCHDOG] Dead stream detected. Reconnecting RTSP...")
        with self.lock:
            old_cap = self.cap
            self.cap = None
        
        if old_cap is not None:
            try:
                old_cap.release()
            except Exception:
                pass
                
        new_cap = cv2.VideoCapture(self.src)
        new_cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)
        with self.lock:
            self.cap = new_cap
            self.last_good_frame_at = time.time()
        print("[SPOT-IT][WATCHDOG] Reconnected successfully.")

    def _watchdog(self):
        while not self.stopped:
            time.sleep(2)
            if self.cap is not None:
                if time.time() - self.last_good_frame_at > self.STALE_TIMEOUT_SEC:
                    self._reconnect()

    def read(self):
        with self.lock:
            return self.ret, self.frame

    def isOpened(self):
        with self.lock:
            return self.cap is not None and self.cap.isOpened()

    def release(self):
        self.stopped = True
        with self.lock:
            if self.cap is not None:
                try:
                    self.cap.release()
                except Exception:
                    pass

# ── CONNECT TO RTSP STREAM ────────────────────────────────────────────────
print(f"[SPOT-IT] Connecting to stream: {RTSP_URL}")
cap = VideoStream(RTSP_URL).start()

# Wait up to 10 seconds for the background connection to resolve
start_wait = time.time()
while not cap.isOpened() and time.time() - start_wait < 10.0:
    time.sleep(0.5)

if not cap.isOpened():
    print("[ERROR] Cannot open RTSP stream. Check:")
    print("  1. Camera and laptop are on the same WiFi network")
    print(f"  2. URL is correct: {RTSP_URL}")
    exit()

print("[SPOT-IT] Stream connected. Starting detection loop...")
print("[SPOT-IT] Press Q to quit.\n")

# ── PER-ROI STATE ─────────────────────────────────────────────────────────
consistency_count = {roi['label']: 0 for roi in roi_list}
active_events     = {roi['label']: False for roi in roi_list}

# Phase 2 Upgrades State
active_detection_ids   = {roi['label']: None for roi in roi_list}
snapshot_b_triggered   = {roi['label']: False for roi in roi_list}
clean_snapshot_saved   = {roi['label']: False for roi in roi_list}
seq_completed          = {roi['label']: False for roi in roi_list}
last_seq_snapshot_time = {roi['label']: 0 for roi in roi_list}
ok_consistency_count   = {roi['label']: 0 for roi in roi_list}
prev_roi_gray          = {roi['label']: None for roi in roi_list}
shifted_locations      = {roi['label']: None for roi in roi_list}
missing_timestamps     = {}
registered_seq_count   = {roi['label']: 0 for roi in roi_list}  # unlimited progression frame counter per Tier 1 item
unreg_first_seen       = {}
stage2_triggered        = {}
item_occupied_crops     = {}
unreg_absence_count     = {}
monitoring_paused      = False

last_rois_mtime    = os.path.getmtime(ROI_FILE) if os.path.exists(ROI_FILE) else 0
last_ref_mtime     = os.path.getmtime(REF_IMAGE) if os.path.exists(REF_IMAGE) else 0
last_mode_mtime    = 0
IS_PRODUCTION_MODE   = False   # updated dynamically from detection_mode.json
SANDBOX_TRACKING_MODE = 'registered'  # 'registered' | 'unregistered' — updated dynamically

# ── HELPERS ───────────────────────────────────────────────────────────────
def validate_presence_dnn(crop, label="object"):
    """
    Secondary ML / Computer Vision Gatekeeper Filter.
    Analyzes texture variance and edge density to reject shadows and lighting noise.
    """
    if crop is None or crop.shape[0] < 30 or crop.shape[1] < 30:
        return False, 0.0
    try:
        gray_crop = cv2.cvtColor(crop, cv2.COLOR_BGR2GRAY)
        laplacian_var = cv2.Laplacian(gray_crop, cv2.CV_64F).var()
        edges = cv2.Canny(gray_crop, 40, 120)
        total_px = float(crop.shape[0] * crop.shape[1])
        edge_density = cv2.countNonZero(edges) / total_px
        
        # Physical objects (boxes, phones, bags) have crisp edges (> 0.03) and texture variance (> 85.0)
        is_real_object = (laplacian_var >= 85.0) and (edge_density >= 0.03)
        confidence = min(1.0, (laplacian_var / 250.0) * (edge_density / 0.08))
        return is_real_object, confidence
    except Exception:
        return True, 0.5

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

def save_frames_atomic(output_frame, clean_frame):
    try:
        live_path = os.path.join(SNAPSHOT_DIR, f"live_{ROOM_ID}.jpg")
        live_tmp  = os.path.join(SNAPSHOT_DIR, f"live_{ROOM_ID}_tmp.jpg")
        cv2.imwrite(live_tmp, output_frame)
        os.replace(live_tmp, live_path)

        clean_path = os.path.join(SNAPSHOT_DIR, f"clean_{ROOM_ID}.jpg")
        clean_tmp  = os.path.join(SNAPSHOT_DIR, f"clean_{ROOM_ID}_tmp.jpg")
        cv2.imwrite(clean_tmp, clean_frame)
        os.replace(clean_tmp, clean_path)
    except Exception:
        pass

def save_snapshot(frame, label, suffix="snapshot_A"):
    filename  = f"{label}_{suffix}.jpg"
    filepath  = os.path.join(SNAPSHOT_DIR, filename)
    cv2.imwrite(filepath, frame)
    return filepath

def count_missing_items():
    """Count how many ROIs are currently confirmed missing (consistency met)."""
    return sum(1 for label in consistency_count if consistency_count[label] >= CONSISTENCY_FRAMES)

def get_roi_tier(roi):
    """Determine ROI tier based on label keywords (fallback if not in json).
    Handles both numeric (1) and string ('tier1') formats saved by the web editor.
    Falls back to keyword matching if 'tier' key is absent.
    """
    if 'tier' in roi:
        t = roi['tier']
        if isinstance(t, int):
            return t
        # Handle 'tier1', 'tier2', 'tier3', 'tier4' string formats
        if isinstance(t, str):
            t_lower = t.lower().replace('tier', '').strip()
            try:
                return int(t_lower)
            except ValueError:
                pass
    # Keyword fallback — Tier 1 = fixed lab equipment per thesis Table 3.2/3.3
    label = roi['label'].lower()
    fixed_keywords = ['monitor', 'keyboard', 'mouse', 'pc', 'ups', 'computer', 'fan', 'headphones', 'mini_fan']
    if any(kw in label for kw in fixed_keywords):
        return 1
    return 2  # default to Tier 2 (personal/lost items)

def find_item_template_match(frame, ref_crop, roi, match_threshold=MATCH_SCORE_THRESHOLD):
    """
    Progressively searches for the reference crop in wider zones of the frame:
    1.5x -> 2.0x -> full frame.
    Returns (found: bool, x: int, y: int, score: float)
    """
    h_f, w_f = frame.shape[:2]
    x, y, w, h = roi['x'], roi['y'], roi['w'], roi['h']
    cx, cy = x + w // 2, y + h // 2
    
    scales = [1.5, 2.0, 0.0]  # 0.0 indicates full frame search
    
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
    """
    POST detection event (Snapshot A) to ingest_detection.php
    """
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
                print(f"  → API SUCCESS: detection_id={detection_id}, "
                      f"deviation={result.get('deviation')}, stage={result.get('stage')}")
                return detection_id
            else:
                print(f"  → API ERROR: {result.get('message')}")
        else:
            print(f"  → API HTTP ERROR: {response.status_code} — {response.text[:200]}")

    except requests.exceptions.RequestException as e:
        print(f"  → API CONNECTION FAILED: {e}")
    return None

def post_snapshot_b(label, detection_id, snapshot_path_b):
    """
    POST Snapshot B (evidence of who touched/took the missing item)
    """
    if not detection_id:
        print("[WARN] Cannot post Snapshot B: No active detection_id found.")
        return

    payload = {
        'api_key':      API_KEY,
        'action':       'snapshot_b',
        'detection_id': detection_id,
    }

    try:
        with open(snapshot_path_b, 'rb') as img_file:
            files = {'snapshot': (os.path.basename(snapshot_path_b), img_file, 'image/jpeg')}
            response = requests.post(API_URL, data=payload, files=files, timeout=5)

        if response.status_code == 200:
            result = response.json()
            if result.get('success'):
                print(f"  → Snapshot B API SUCCESS for Detection #{detection_id}")
            else:
                print(f"  → Snapshot B API ERROR: {result.get('message')}")
        else:
            print(f"  → Snapshot B API HTTP ERROR: {response.status_code}")

    except requests.exceptions.RequestException as e:
        print(f"  → Snapshot B API CONNECTION FAILED: {e}")

def post_mass_deviation():
    """
    POST mass deviation event to pause room monitoring in the backend database.
    """
    payload = {
        'api_key': API_KEY,
        'action':  'mass_deviation',
        'room_id': ROOM_ID,
    }

    try:
        response = requests.post(API_URL, data=payload, timeout=5)
        if response.status_code == 200:
            result = response.json()
            if result.get('success'):
                print(f"  → Mass Deviation API SUCCESS: Room monitoring paused.")
            else:
                print(f"  → Mass Deviation API ERROR: {result.get('message')}")
        else:
            print(f"  → Mass Deviation API HTTP ERROR: {response.status_code}")
    except requests.exceptions.RequestException as e:
        print(f"  → Mass Deviation API CONNECTION FAILED: {e}")

def post_item_recovered(label):
    """
    POST item_recovered event to auto-resolve detection when item physically returns to ROI.
    """
    payload = {
        'api_key':     API_KEY,
        'action':      'item_recovered',
        'room_id':     ROOM_ID,
        'object_zone': label,
    }
    try:
        response = requests.post(API_URL, data=payload, timeout=5)
        if response.status_code == 200 and response.json().get('success'):
            print(f"  → Item Recovered API SUCCESS for '{label}'")
    except Exception as e:
        pass

# ── MAIN DETECTION LOOP ───────────────────────────────────────────────────
while True:
    # ── CHECK FOR CONFIG RECALIBRATION CHANGES ────────────────────────────
    current_rois_mtime = os.path.getmtime(ROI_FILE) if os.path.exists(ROI_FILE) else 0
    current_ref_mtime  = os.path.getmtime(REF_IMAGE) if os.path.exists(REF_IMAGE) else 0
    
    # Check dynamic testing vs production mode toggle
    # In TESTING mode: flood gate only triggers at 100% (all items gone).
    # In PRODUCTION mode: flood gate triggers at >50% (realistic lab scenario).
    mode_file = 'detection_mode.json'
    current_mode_mtime = os.path.getmtime(mode_file) if os.path.exists(mode_file) else 0
    if current_mode_mtime > last_mode_mtime:
        last_mode_mtime = current_mode_mtime
        try:
            with open(mode_file, 'r') as mf:
                mode_data = json.load(mf)
                mode = mode_data.get('mode', 'testing')
                IS_PRODUCTION_MODE = (mode == 'production')
                SANDBOX_TRACKING_MODE = mode_data.get('tracking_mode', 'registered')

                # Unpause monitoring if reset signal is set
                if mode_data.get('reset_paused', False):
                    print("[SPOT-IT] Recalibration / status reset signal received! Unpausing live monitoring...")
                    monitoring_paused = False
                    missing_timestamps.clear()
                    unreg_first_seen.clear()
                    stage2_triggered.clear()
                    item_occupied_crops.clear()
                    unreg_absence_count.clear()
                    for r in roi_list:
                        lbl = r['label']
                        consistency_count[lbl] = 0
                        active_events[lbl] = False
                        shifted_locations[lbl] = None
                        snapshot_b_triggered[lbl] = False
                        active_detection_ids[lbl] = None
                    active_events.clear()

                if IS_PRODUCTION_MODE:
                    print("[SPOT-IT] Production Mode active — flood gate at >50% items missing | Both tiers active")
                else:
                    tracking_label = 'Registered Items' if SANDBOX_TRACKING_MODE == 'registered' else 'Unregistered/Left Items'
                    print(f"[SPOT-IT] Testing Mode active — flood gate at 100% | Tracking: {tracking_label}")
        except Exception as me:
            print(f"[SPOT-IT][ERROR] Failed to load detection mode: {me}")
    
    if current_rois_mtime > last_rois_mtime or current_ref_mtime > last_ref_mtime:
        print("[SPOT-IT] Config or reference frame change detected! Reloading parameters dynamically...")
        time.sleep(1.0) # Wait a second for any write operations to fully finish
        monitoring_paused = False
        missing_timestamps.clear()
        
        try:
            # 1. Reload ROIs
            with open(ROI_FILE, 'r') as f:
                new_roi_list = json.load(f)
            
            # 2. Reload Reference Image
            new_ref_bgr = cv2.imread(REF_IMAGE)
            if new_ref_bgr is not None:
                roi_list = new_roi_list
                ref_bgr = new_ref_bgr
                ref_gray = cv2.cvtColor(ref_bgr, cv2.COLOR_BGR2GRAY)
                ref_blur = cv2.GaussianBlur(ref_gray, (21, 21), 0)
                TARGET_SIZE = (ref_bgr.shape[1], ref_bgr.shape[0])
            else:
                print("[SPOT-IT] Reference image cleared or deleted — resetting active ROIs to empty.")
                roi_list = []
                ref_bgr = None
                
            # Re-initialize state dictionaries for the new list
            for roi in roi_list:
                lbl = roi['label']
                if lbl not in consistency_count: consistency_count[lbl] = 0
                if lbl not in active_events: active_events[lbl] = False
                if lbl not in active_detection_ids: active_detection_ids[lbl] = None
                if lbl not in snapshot_b_triggered: snapshot_b_triggered[lbl] = False
                if lbl not in clean_snapshot_saved: clean_snapshot_saved[lbl] = False
                if lbl not in seq_completed: seq_completed[lbl] = False
                if lbl not in last_seq_snapshot_time: last_seq_snapshot_time[lbl] = 0
                if lbl not in ok_consistency_count: ok_consistency_count[lbl] = 0
                if lbl not in prev_roi_gray: prev_roi_gray[lbl] = None
                if lbl not in shifted_locations: shifted_locations[lbl] = None
                if lbl not in registered_seq_count: registered_seq_count[lbl] = 0
            
            BASELINE_COUNT = len(roi_list)
            last_rois_mtime = current_rois_mtime
            last_ref_mtime  = current_ref_mtime
            # Reinitialize all per-label state for new/changed ROI set
            for roi in roi_list:
                lbl = roi['label']
                if lbl not in registered_seq_count: registered_seq_count[lbl] = 0
            print(f"[SPOT-IT] Dynamic reload state updated! Active ROIs: {len(roi_list)}")
        except Exception as reload_err:
            print(f"[SPOT-IT][ERROR] Failed to reload config: {reload_err}")

    ret, frame = cap.read()

    if not ret:
        print("[WARN] Frame read failed — retrying connection...")
        cap.release()
        time.sleep(2)
        cap = VideoStream(RTSP_URL).start()
        continue

    if ROTATE_CAMERA:
        frame = cv2.rotate(frame, cv2.ROTATE_90_COUNTERCLOCKWISE)
    frame = rescaleFrame(frame)

    # ── AUTO-INITIALIZE REFERENCE FRAME IF MISSING / CLEARED ──────────────
    if ref_bgr is None:
        print("[SPOT-IT] Capturing new baseline reference image from live stream...")
        ref_bgr = frame.copy()
        ref_gray = cv2.cvtColor(ref_bgr, cv2.COLOR_BGR2GRAY)
        ref_blur = cv2.GaussianBlur(ref_gray, (21, 21), 0)
        TARGET_SIZE = (ref_bgr.shape[1], ref_bgr.shape[0])
        os.makedirs(os.path.dirname(REF_IMAGE), exist_ok=True)
        cv2.imwrite(REF_IMAGE, ref_bgr)
        last_ref_mtime = os.path.getmtime(REF_IMAGE) if os.path.exists(REF_IMAGE) else time.time()

    # ── RESIZE REFERENCE FRAME IF SIZES MISMATCH ──────────────────────────
    if frame.shape[:2] != ref_bgr.shape[:2]:
        print(f"[SPOT-IT][WARN] Resolution mismatch! Live frame is {frame.shape[1]}x{frame.shape[0]}, "
              f"but reference frame is {ref_bgr.shape[1]}x{ref_bgr.shape[0]}. Resizing reference baseline to fit...")
        ref_bgr = cv2.resize(ref_bgr, (frame.shape[1], frame.shape[0]))
        ref_gray = cv2.cvtColor(ref_bgr, cv2.COLOR_BGR2GRAY)
        ref_blur = cv2.GaussianBlur(ref_gray, (21, 21), 0)
        TARGET_SIZE = (ref_bgr.shape[1], ref_bgr.shape[0])

    gray  = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
    blur  = cv2.GaussianBlur(gray, (21, 21), 0)

    full_diff = cv2.absdiff(ref_blur, blur)
    _, thresh = cv2.threshold(full_diff, THRESHOLD, 255, cv2.THRESH_BINARY)

    output = frame.copy()

    # ── CHECK MONITORING PAUSED ───────────────────────────────────────────
    if monitoring_paused:
        cv2.putText(output, "MASS DEVIATION DETECTED — paused. Recalibration required.",
                    (10, 30), cv2.FONT_HERSHEY_SIMPLEX, 0.55, (0, 0, 255), 2)
        
        for roi in roi_list:
            x, y, w, h = roi['x'], roi['y'], roi['w'], roi['h']
            cv2.rectangle(output, (x, y), (x+w, y+h), (0, 0, 255), 2)
            cv2.putText(output, f"LOCKED: {roi['label']}", (x, y - 5),
                        cv2.FONT_HERSHEY_SIMPLEX, 0.45, (0, 0, 255), 1)

        save_frames_atomic(output, frame)

        if SHOW_GUI:
            cv2.imshow("S.P.O.T.-IT Live Detection", output)
            if cv2.waitKey(1) & 0xFF == ord('q'):
                break
        else:
            time.sleep(0.03) # Prevent CPU hogging
        continue

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

        save_frames_atomic(output, frame)

        if SHOW_GUI:
            cv2.imshow("S.P.O.T.-IT Live Detection", output)
            if cv2.waitKey(1) & 0xFF == ord('q'):
                break
        else:
            time.sleep(0.03)
        continue

    output = frame.copy()

    # ── UNREGISTERED / LEFT ITEMS MODE (Foreground Contour Tracking + ML Gate) ────────
    if SANDBOX_TRACKING_MODE == 'unregistered':
        diff_unreg = cv2.absdiff(ref_blur, blur)
        _, thresh_unreg = cv2.threshold(diff_unreg, 35, 255, cv2.THRESH_BINARY)
        kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (9, 9))
        thresh_unreg = cv2.morphologyEx(thresh_unreg, cv2.MORPH_OPEN, kernel)
        thresh_unreg = cv2.morphologyEx(thresh_unreg, cv2.MORPH_DILATE, kernel)

        contours, _ = cv2.findContours(thresh_unreg, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
        
        unreg_count = 0
        live_left_state = {}
        now_ts = time.time()
        current_seen_labels = set()

        for cnt in contours:
            area = cv2.contourArea(cnt)
            if area >= 4500: # Filter out small shadow noise (~65x65 px)
                x, y, w, h = cv2.boundingRect(cnt)

                # Exclude camera edge border reflections
                if y + h > TARGET_SIZE[1] - 15 or y < 10 or x < 10 or x + w > TARGET_SIZE[0] - 10:
                    continue

                # Secondary ML Gatekeeper: Objectness & Texture Filter
                crop = frame[y:y+h, x:x+w]
                is_real_obj, conf = validate_presence_dnn(crop)
                if not is_real_obj:
                    # Flat shadow / lighting noise -> REJECTED by ML gatekeeper!
                    continue

                unreg_count += 1
                label = f"object{unreg_count}"
                current_seen_labels.add(label)

                if label not in unreg_first_seen:
                    unreg_first_seen[label] = now_ts
                    active_events[label] = False
                    stage2_triggered[label] = False
                    snapshot_b_triggered[label] = False

                elapsed = now_ts - unreg_first_seen[label]

                # Determine Stage Color & Label based on Real Clock Time (time.time())
                if elapsed >= 6.0:
                    box_color = (0, 0, 255) # RED
                    label_text = f"CONFIRMED LOST: {label} ({elapsed:.1f}s)"
                elif elapsed >= 3.0:
                    box_color = (0, 215, 255) # YELLOW
                    label_text = f"UNATTENDED: {label} ({elapsed:.1f}s)"
                else:
                    box_color = (255, 255, 0) # CYAN
                    label_text = f"CHECKING: {label} ({elapsed:.1f}s)"

                # DRAW SQUARE BOUNDING BOX & LABEL
                cv2.rectangle(output, (x, y), (x+w, y+h), box_color, 2)
                cv2.putText(output, label_text, (x, y - 8),
                            cv2.FONT_HERSHEY_SIMPLEX, 0.48, box_color, 2)

                live_left_state[label] = {'is_missing': False, 'tier': 'tier2'}

                # STAGE 1 (Yellow at exactly 3.0 seconds): Log as potential unattended item
                if elapsed >= 3.0 and not active_events[label]:
                    active_events[label] = True
                    snapshot_path = save_snapshot(frame, label, "snapshot_A")
                    print(f"\n[STAGE 1 (YELLOW 3.0s): UNATTENDED ITEM DETECTED: {label}]")
                    det_id = post_to_api(label, snapshot_path, float(area) / (TARGET_SIZE[0] * TARGET_SIZE[1]))
                    active_detection_ids[label] = det_id
                    try:
                        item_occupied_crops[label] = cv2.cvtColor(crop.copy(), cv2.COLOR_BGR2GRAY)
                    except Exception:
                        pass

                # STAGE 2 (Red at exactly 6.0 seconds): Escalate status to confirmed_missing
                if elapsed >= 6.0 and active_events[label] and not stage2_triggered.get(label, False):
                    stage2_triggered[label] = True
                    print(f"[STAGE 2 (RED 6.0s): CONFIRMED LOST ESCALATION: {label}]")
                    if active_detection_ids.get(label):
                        try:
                            requests.post(API_URL, data={
                                'api_key': API_KEY,
                                'action': 'escalate_status',
                                'detection_id': active_detection_ids[label],
                                'status': 'confirmed_missing'
                            }, timeout=3)
                        except Exception:
                            pass

        # ── SNAPSHOT B TRIGGER & STATE CLEANUP (Item Removal Evidence & Reset) ────
        # Checks all active logged left items OUTSIDE the contour loop.
        # 1) Fires Snapshot B when item is taken
        # 2) Resets timer & tracking state when item is removed from desk (>10 frames absence)
        known_unreg_labels = list(set(list(unreg_first_seen.keys()) + list(active_events.keys())))
        for lbl in known_unreg_labels:
            if lbl in current_seen_labels:
                unreg_absence_count[lbl] = 0
            else:
                unreg_absence_count[lbl] = unreg_absence_count.get(lbl, 0) + 1
                
                # Check Snapshot B trigger if item was active and just taken
                if active_events.get(lbl, False) and not snapshot_b_triggered.get(lbl, False):
                    snapshot_b_triggered[lbl] = True
                    snapshot_b_path = save_snapshot(frame, lbl, "snapshot_B")
                    det_id = active_detection_ids.get(lbl)
                    print(f"\n[SNAPSHOT B TRIGGERED: Item '{lbl}' TAKEN / REMOVED from desk!]")
                    print(f"  Evidence Snapshot B: {snapshot_b_path}")
                    if det_id:
                        post_snapshot_b(lbl, det_id, snapshot_b_path)
                
                # Reset item state after ~10 frames of complete absence so new/replaced items start timer fresh from 0.0s
                if unreg_absence_count[lbl] >= 10:
                    unreg_first_seen.pop(lbl, None)
                    active_events.pop(lbl, None)
                    stage2_triggered.pop(lbl, None)
                    snapshot_b_triggered.pop(lbl, None)
                    active_detection_ids.pop(lbl, None)
                    item_occupied_crops.pop(lbl, None)
                    unreg_absence_count.pop(lbl, None)

        # Write real-time physical state for PHP polling
        try:
            with open('photos/live_roi_state.json', 'w') as sf:
                json.dump(live_left_state, sf)
        except Exception:
            pass

        save_frames_atomic(output, frame)

        if SHOW_GUI:
            cv2.imshow("S.P.O.T.-IT Live Detection", output)
            if cv2.waitKey(1) & 0xFF == ord('q'):
                break
        else:
            time.sleep(0.03)
        continue

    # ── GATE 2 + 3: REGISTERED ROI CHANGE + FRAME CONSISTENCY ───────────────
    missing_count = 0

    for roi in roi_list:
        x, y, w, h = roi['x'], roi['y'], roi['w'], roi['h']
        label      = roi['label']
        base_tier  = get_roi_tier(roi)

        # ── SANDBOX TRACKING MODE OVERRIDE ──────────────────────────────────
        # In production, use actual ROI tier from JSON/keywords (full pipeline).
        # In sandbox testing:
        #   'registered'   → treat ALL ROIs as Tier 1 (DNN check, sequential snapshots)
        #   'unregistered' → treat ALL ROIs as Tier 2 (template match, Snapshot A/B)
        if IS_PRODUCTION_MODE:
            tier = base_tier
        elif SANDBOX_TRACKING_MODE == 'registered':
            tier = 1
        else:  # 'unregistered'
            tier = 2

        changed, change_pct = check_roi(roi, thresh)
        is_actually_missing = changed
        if changed:
            if tier == 1:
                # Registered Asset -> Direct Presence Validation Gate (Template Match + MobileNetV2)
                ref_crop = ref_bgr[y:y+h, x:x+w]
                live_crop = frame[y:y+h, x:x+w]
                is_present_template = False
                max_score = 0.0
                if live_crop.shape[0] >= h and live_crop.shape[1] >= w:
                    res = cv2.matchTemplate(live_crop, ref_crop, cv2.TM_CCOEFF_NORMED)
                    _, max_score, _, _ = cv2.minMaxLoc(res)
                    if max_score >= MATCH_SCORE_THRESHOLD:
                        is_present_template = True

                if is_present_template:
                    # Item is physically inside the ROI box -> GREEN OK
                    is_actually_missing = False
                else:
                    # Secondary presence validation gate: MobileNetV2 DNN check
                    is_present_dnn, dnn_conf = validate_presence_dnn(live_crop, label)
                    if is_present_dnn:
                        # DNN confirms object is still present in zone -> GREEN OK
                        is_actually_missing = False
            else:
                # Direct match check for Tier 2/Unregistered items
                ref_crop = ref_bgr[y:y+h, x:x+w]
                live_crop = frame[y:y+h, x:x+w]
                if live_crop.shape[0] >= h and live_crop.shape[1] >= w:
                    res = cv2.matchTemplate(live_crop, ref_crop, cv2.TM_CCOEFF_NORMED)
                    _, f_score, _, _ = cv2.minMaxLoc(res)
                    if f_score >= MATCH_SCORE_THRESHOLD:
                        is_actually_missing = False

        if is_actually_missing:
            ok_consistency_count[label] = 0
            consistency_count[label] += 1
            if consistency_count[label] == 1:
                print(f"[SPOT-IT] '{label}' change detected! (change: {change_pct:.1%}). Status: CHECKING ({consistency_count[label]}/{CONSISTENCY_FRAMES})")
            elif consistency_count[label] < CONSISTENCY_FRAMES:
                print(f"[SPOT-IT] '{label}' checking progress ({consistency_count[label]}/{CONSISTENCY_FRAMES})")

            # ── PROGRESSION SNAPSHOT SEQUENCE (Tier 1 / Registered Items) ──
            # Captures sequential snapshots (registered_1.jpg to registered_5.jpg max)
            # as the item is moved/lifted. Throttled to ~200ms intervals.
            now_ts = time.time()
            if tier == 1 and not seq_completed[label] and registered_seq_count[label] < 5:
                if now_ts - last_seq_snapshot_time[label] >= 0.20:
                    last_seq_snapshot_time[label] = now_ts
                    registered_seq_count[label] += 1
                    seq_filename = f"{label}_registered_{registered_seq_count[label]}.jpg"
                    save_snapshot(frame, label, f"registered_{registered_seq_count[label]}")
                    print(f"[SPOT-IT] Progression snapshot #{registered_seq_count[label]}/5 saved for '{label}' -> {seq_filename}")
                    if registered_seq_count[label] >= 5:
                        seq_completed[label] = True
        else:
            consistency_count[label] = 0
            ok_consistency_count[label] += 1
            # Hysteresis Latching: Require 8 consecutive frames of OK presence before resetting missing state
            if ok_consistency_count[label] >= 8:
                if active_events[label]:
                    print(f"[SPOT-IT] '{label}' confirmed returned to OK state (over 8 consecutive frames). Auto-resolving alert...")
                    post_item_recovered(label)
                active_events[label]        = False
                active_detection_ids[label] = None
                snapshot_b_triggered[label] = False
                clean_snapshot_saved[label] = False
                seq_completed[label]        = False
                last_seq_snapshot_time[label] = 0
                shifted_locations[label]    = None
                registered_seq_count[label] = 0
                if label in missing_timestamps:
                    del missing_timestamps[label]

        confirmed_missing = (
            consistency_count[label] >= CONSISTENCY_FRAMES
            and not active_events[label]
        )

        if confirmed_missing:
            active_events[label] = True

            if tier == 1:
                curr_idx = max(1, min(5, registered_seq_count[label]))
                snapshot_path = os.path.join(SNAPSHOT_DIR, f"{label}_registered_{curr_idx}.jpg")
            else:
                snapshot_path = save_snapshot(frame, label, "snapshot_A")

            print(f"\n[CANDIDATE EVENT DETECTED ({'Registered Item sequence' if tier == 1 else 'Snapshot A'})]")
            print(f"  ROI Label    : {label} (Tier {tier})")
            print(f"  Room ID      : {ROOM_ID}")
            print(f"  Detected At  : {datetime.now().isoformat()}")
            print(f"  Snapshot     : {snapshot_path}")
            print(f"  Change       : {change_pct:.1%} over {CONSISTENCY_FRAMES} consecutive frames")

            det_id = post_to_api(label, snapshot_path, change_pct)
            active_detection_ids[label] = det_id
            missing_timestamps[label] = time.time()
            print()

            # ── AUTO-FLOOD GATE PROTECTION ────────────────────────────────
            recent_missing = [lbl for lbl, ts in missing_timestamps.items() if time.time() - ts <= 60]
            n_rois = len(roi_list)
            n_missing = len(recent_missing)
            flood_triggered = False
            if IS_PRODUCTION_MODE:
                if n_rois > 0 and n_missing > 0.50 * n_rois:
                    flood_triggered = True
            else:
                if n_rois > 0 and n_missing >= n_rois:
                    flood_triggered = True
            if flood_triggered:
                print(f"[ALERT] Auto-Flood Gate triggered! {n_missing}/{n_rois} items went missing ({'production' if IS_PRODUCTION_MODE else 'testing'} mode).")
                monitoring_paused = True
                post_mass_deviation()
                break  # Exit ROI loop immediately

        # ── FINAL POST-REMOVAL FRAME COMPLETION ─────────────────────────────
        # Once item is confirmed missing, capture ONE final clean frame showing empty ROI after hand leaves
        if active_events[label] and not clean_snapshot_saved[label]:
            roi_gray = gray[y:y+h, x:x+w]
            if prev_roi_gray[label] is not None and prev_roi_gray[label].shape == roi_gray.shape:
                ftf_diff = cv2.absdiff(prev_roi_gray[label], roi_gray)
                _, ftf_thresh = cv2.threshold(ftf_diff, 15, 255, cv2.THRESH_BINARY)
                motion_val = cv2.countNonZero(ftf_thresh) / (w * h)
                
                # When motion settles below 4%, hand has finished removing item and left ROI
                if motion_val < 0.04:
                    clean_snapshot_saved[label] = True
                    seq_completed[label] = True
                    final_path = os.path.join(SNAPSHOT_DIR, f"{label}_registered_final.jpg")
                    cv2.imwrite(final_path, frame)
                    print(f"[SPOT-IT] Movement finished for '{label}'. Final clean post-removal frame saved (hand left ROI).")

        # ── SNAPSHOT B LOGIC (Unregistered / Tier 2 Only) ──────────────────
        # If item is already confirmed missing, watch it for subsequent movement (theft/hand capture)
        if tier == 2 and active_events[label] and not snapshot_b_triggered[label]:
            roi_gray = gray[y:y+h, x:x+w]
            if prev_roi_gray[label] is not None:
                if prev_roi_gray[label].shape == roi_gray.shape:
                    # Frame-to-frame difference within the empty ROI
                    ftf_diff = cv2.absdiff(prev_roi_gray[label], roi_gray)
                    _, ftf_thresh = cv2.threshold(ftf_diff, 15, 255, cv2.THRESH_BINARY)
                    motion_val = cv2.countNonZero(ftf_thresh) / (w * h)
                    
                    if motion_val >= 0.05:  # 5% of pixels changed frame-to-frame
                        snapshot_b_triggered[label] = True
                        snapshot_path_b = save_snapshot(frame, label, "snapshot_B")
                        print(f"\n[INTERACTION DETECTED (Snapshot B)]")
                        print(f"  ROI Label    : {label}")
                        print(f"  Detection ID : {active_detection_ids[label]}")
                        print(f"  Snapshot B   : {snapshot_path_b}")
                        post_snapshot_b(label, active_detection_ids[label], snapshot_path_b)
                        print()
            prev_roi_gray[label] = roi_gray

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
        elif shifted_locations[label] is not None:
            # Draw original box in yellow-green and shifted template match location in orange
            cv2.rectangle(output, (x, y), (x+w, y+h), (0, 255, 0), 1, lineType=cv2.LINE_AA)
            sx, sy, sw, sh = shifted_locations[label]
            cv2.rectangle(output, (sx, sy), (sx+sw, sy+sh), (0, 165, 255), 2)
            cv2.putText(output, f"SHIFTED: {label}", (sx, sy - 10),
                        cv2.FONT_HERSHEY_SIMPLEX, 0.5, (0, 165, 255), 2)
        else:
            cv2.rectangle(output, (x, y), (x+w, y+h), (0, 255, 0), 2)
            cv2.putText(output, f"OK: {label}", (x, y - 10),
                        cv2.FONT_HERSHEY_SIMPLEX, 0.5, (0, 255, 0), 1)

    # ── STATUS BAR ────────────────────────────────────────────────────────
    if not monitoring_paused:
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

    # ── WRITE REAL-TIME PHYSICAL ROI STATE FOR PHP ENDPOINTS ──────────────
    try:
        live_state_map = {}
        for r in roi_list:
            lbl = r['label']
            live_state_map[lbl] = {
                'is_missing': active_events.get(lbl, False) or (consistency_count.get(lbl, 0) >= CONSISTENCY_FRAMES),
                'consistency': consistency_count.get(lbl, 0),
                'tier': r.get('tier', 'tier1')
            }
        with open('photos/live_roi_state.json', 'w') as sf:
            json.dump(live_state_map, sf)
    except Exception:
        pass

    # Write live stream frame and clean baseline frame for web dashboard
    save_frames_atomic(output, frame)

    if SHOW_GUI:
        cv2.imshow("S.P.O.T.-IT Live Detection", output)
        if cv2.waitKey(1) & 0xFF == ord('q'):
            break
    else:
        time.sleep(0.03)

cap.release()
cv2.destroyAllWindows()
print("[SPOT-IT] Stream closed.")