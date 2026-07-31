import os
import time

# ── FFMPEG RTSP SOCKET TIMEOUT ──────────────────────────────────────────────
# Must be set before cv2 is imported so OpenCV's backend respects this variable!
os.environ["OPENCV_FFMPEG_CAPTURE_OPTIONS"] = "rtsp_transport;tcp|stimeout;5000000"

import sys
import cv2
import json
import numpy as np
import requests
from datetime import datetime

# ── ROOM / API CONFIG ───────────────────────────────────────────────────────
ROOM_ID = "DESK"  # default
CLI_ROOM_ID = None  # set if room was provided via command line
if len(sys.argv) > 1:
    ROOM_ID = sys.argv[1].upper()
    CLI_ROOM_ID = ROOM_ID  # lock — detection_mode.json polling won't override this

API_URL = os.environ.get("API_URL", "http://localhost/spotit/auth/ingest_detection.php")
API_KEY = os.environ.get(
    "API_KEY", "CHANGE_ME_DETECTION_KEY"
)  # must match env.php / DETECTION_API_KEY
SHOW_GUI = False  # Set to False to run headless (no floating window)
BASELINE_COUNT = None  # set below from number of ROIs

# ── CONFIG ───────────────────────────────────────────────────────────────
STREAM_SCALE = 0.50
ROI_FILE = "rois.json" if ROOM_ID == "DESK" else f"rois_{ROOM_ID}.json"
REF_IMAGE = (
    "photos/ref_image.jpg" if ROOM_ID == "DESK" else f"photos/ref_image_{ROOM_ID}.jpg"
)

# Dynamic path resolution for snapshots directory
SNAPSHOT_DIR = os.environ.get(
    "SNAPSHOT_DIR", "C:/xampp/htdocs/spotit/uploads/snapshots"
)
if not os.path.exists("C:/xampp") or os.name == "posix":
    SNAPSHOT_DIR = "uploads/snapshots"

THRESHOLD = 25  # delta needed to count as changed (was 10)
MIN_CHANGE_PERCENT = 0.08
SCENE_MOTION_LIMIT = (
    0.20  # % of frame-to-frame motion allowed before pausing monitoring
)
CONSISTENCY_FRAMES = 25  # 25 consecutive frames (~0.8s @ 30 FPS) for balanced stability
RTSP_URL = "rtsp://SpotItCamera:spotittapo232@192.168.18.11:554/stream1"
ROTATE_CAMERA = False  # Set to True for portrait iPhone, False for Tapo CCTV
MATCH_SCORE_THRESHOLD = (
    0.48  # legacy template-match score — retained only by main_classical_only.py A/B baseline
)

# ── DUAL-TEMPLATE PRESENCE TUNING (registered ROIs) ──────────────────────
# Measured on the real ROOM_1 reference frame across present / removed /
# occluded / +-25% lighting scenarios for all three registered items:
#   sim_present : item there 0.985-1.000 | removed 0.473-0.743 | occluded 0.302-0.509
#   sim_empty   : removed    0.765-0.819 | occluded  0.362-0.522
# Both thresholds sit in the middle of a wide empirical gap, not on an edge.
SIM_PRESENT = 0.80  # >= this vs the "item here" template  -> item is still there (lowered for position tolerance)
SIM_EMPTY = 0.55    # >= this vs the "item gone"  template  -> bare surface revealed (lowered for weak inpaints)
FOOTPRINT_MIN_FRAC = 0.03  # item smaller than 3% of its box -> ROI drawn far too loose
# Confirmation is wall-clock, not frame counts: the loop outruns the RTSP frame
# rate, so counting iterations does not measure elapsed time (see BUGS.md,
# "Instant Stage Escalation in Loop" — same fix already applied to left items).
MISSING_CONFIRM_SEC = 1.0   # continuous ABSENT time before an item is flagged RED
RECOVER_CONFIRM_SEC = 0.5   # continuous PRESENT time before a RED alert auto-resolves
METRICS_CSV = "photos/detection_metrics.csv"
# ─────────────────────────────────────────────────────────────────────────

# ── MOBILENETV2 DNN GATEKEEPER CONFIG (Phase 2, Step 2.1) ──────────────────
# Per CLAUDE.md §3a: MobileNetV2 is a SECONDARY validation gate only. The
# classical absdiff/threshold pipeline above still does the first-pass
# "did anything change" job — this DNN only answers "is the target object
# still visually present in the flagged ROI crop, regardless of exact pose."
DNN_MODEL_DIR = "models"
DNN_MODEL_PATH = os.path.join(DNN_MODEL_DIR, "mobilenetv2-7.onnx")
DNN_RAW_LABELS_PATH = os.path.join(DNN_MODEL_DIR, "synset_raw.txt")
DNN_LABELS_PATH = os.path.join(DNN_MODEL_DIR, "imagenet_classes.txt")

DNN_INPUT_SIZE = (224, 224)  # MobileNetV2 expects 224x224 RGB
DNN_TOP_K = 5  # target_label must appear in top-K predictions
DNN_CONFIDENCE_THRESHOLD = 0.15  # min softmax confidence to count as "present"

# ImageNet normalization constants (torchvision/ONNX MobileNetV2 preprocessing)
IMAGENET_MEAN = np.array([0.485, 0.456, 0.406], dtype=np.float32)
IMAGENET_STD = np.array([0.229, 0.224, 0.225], dtype=np.float32)

# Multiple sources tried in order — onnx/models moved to Git LFS-only hosting
# and Hugging Face mirrors it without requiring LFS on the client side, so
# it's listed first for reliability.
MOBILENET_MODEL_URLS = [
    "https://huggingface.co/onnxmodelzoo/mobilenetv2-7/resolve/main/mobilenetv2-7.onnx",
    "https://github.com/onnx/models/raw/main/validated/vision/classification/mobilenet/model/mobilenetv2-7.onnx",
]
MOBILENET_LABELS_URLS = [
    "https://raw.githubusercontent.com/onnx/models/main/validated/vision/classification/synset.txt",
]
# ─────────────────────────────────────────────────────────────────────────


def rescaleFrame(frame, scale=STREAM_SCALE):
    width = int(frame.shape[1] * scale)
    height = int(frame.shape[0] * scale)
    return cv2.resize(frame, (width, height), interpolation=cv2.INTER_AREA)


os.makedirs(SNAPSHOT_DIR, exist_ok=True)

# ── LOAD ROIs ─────────────────────────────────────────────────────────────
if not os.path.exists(ROI_FILE):
    with open(ROI_FILE, "w") as f:
        json.dump([], f)

with open(ROI_FILE, "r") as f:
    roi_list = json.load(f)
print(f"[SPOT-IT] Loaded {len(roi_list)} ROIs: {[r['label'] for r in roi_list]}")

BASELINE_COUNT = len(roi_list)  # all items present = baseline

# ── REFERENCE FRAME VARIABLES ──────────────────────────────────────────────
ref_bgr = None
ref_gray = None
ref_blur = None
TARGET_SIZE = (960, 540)


# ── MOBILENETV2 DNN GATEKEEPER — DOWNLOAD + INIT ──────────────────────────
def _download_with_fallback(urls, dest_path, min_size_bytes=1024, description="file"):
    """
    Downloads `dest_path` from the first URL in `urls` that works.
    Skips the download entirely if a valid-looking file already exists.
    Guards against saving HTML error pages or Git-LFS pointer stubs by
    rejecting anything smaller than `min_size_bytes`.
    """
    if os.path.exists(dest_path) and os.path.getsize(dest_path) >= min_size_bytes:
        print(
            f"[SPOT-IT][DNN] {description} already present at {dest_path} — skipping download."
        )
        return True

    os.makedirs(os.path.dirname(dest_path), exist_ok=True)

    for url in urls:
        try:
            print(f"[SPOT-IT][DNN] Downloading {description} from {url} ...")
            resp = requests.get(url, stream=True, timeout=30)
            resp.raise_for_status()

            tmp_path = dest_path + ".tmp"
            with open(tmp_path, "wb") as f:
                for chunk in resp.iter_content(chunk_size=8192):
                    if chunk:
                        f.write(chunk)

            if os.path.getsize(tmp_path) < min_size_bytes:
                print(
                    f"[SPOT-IT][DNN][WARN] Downloaded file too small "
                    f"({os.path.getsize(tmp_path)} bytes) — probably not the "
                    f"real file (e.g. an LFS pointer or HTML error page). "
                    f"Trying next source..."
                )
                os.remove(tmp_path)
                continue

            os.replace(tmp_path, dest_path)
            print(f"[SPOT-IT][DNN] Saved {description} -> {dest_path}")
            return True

        except Exception as e:
            print(f"[SPOT-IT][DNN][WARN] Failed to download from {url}: {e}")
            continue

    print(
        f"[SPOT-IT][DNN][ERROR] All sources failed for {description}. "
        f"DNN gatekeeper will be disabled and validate_presence_dnn() will fail OPEN."
    )
    return False


def _build_clean_labels_file(raw_path, clean_path):
    """
    Converts the raw ImageNet synset.txt (format: 'n01440764 tench, Tinca tinca')
    into a plain ordered list of class-name strings, one per line, index-aligned
    with MobileNetV2's 1000-way softmax output.
    """
    with open(raw_path, "r") as f_in:
        lines = f_in.readlines()

    cleaned = []
    for line in lines:
        line = line.strip()
        if not line:
            continue
        parts = line.split(" ", 1)  # drop the leading wnid (e.g. 'n01440764')
        cleaned.append(parts[1] if len(parts) > 1 else parts[0])

    with open(clean_path, "w") as f_out:
        f_out.write("\n".join(cleaned))

    print(f"[SPOT-IT][DNN] Parsed {len(cleaned)} class labels -> {clean_path}")


def ensure_mobilenet_assets():
    """Downloads the MobileNetV2 ONNX model + ImageNet labels if not already present."""
    model_ok = _download_with_fallback(
        MOBILENET_MODEL_URLS,
        DNN_MODEL_PATH,
        min_size_bytes=1_000_000,
        description="MobileNetV2 ONNX model",
    )
    labels_ok = _download_with_fallback(
        MOBILENET_LABELS_URLS,
        DNN_RAW_LABELS_PATH,
        min_size_bytes=5_000,
        description="ImageNet synset labels",
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
        with open(DNN_LABELS_PATH, "r") as f:
            DNN_CLASS_LABELS = [line.strip() for line in f if line.strip()]
        print(
            f"[SPOT-IT][DNN] MobileNetV2 gatekeeper loaded — "
            f"{len(DNN_CLASS_LABELS)} ImageNet classes ready."
        )
    except Exception as e:
        print(f"[SPOT-IT][DNN][ERROR] Failed to initialize DNN network: {e}")
        print(
            "[SPOT-IT][DNN] Continuing with classical-only detection "
            "(validate_presence_dnn will fail OPEN)."
        )
        dnn_net = None
        DNN_CLASS_LABELS = []
else:
    print(
        "[SPOT-IT][DNN] MobileNetV2 assets unavailable — "
        "continuing with classical-only detection."
    )


# ═══════════════════════════════════════════════════════════════════════════
# MOBILENETV2 AS A PERCEPTUAL SIMILARITY METRIC  (secondary gate)
# ═══════════════════════════════════════════════════════════════════════════
# md_files/unfixable_limitations.md §2 correctly found that CLASSIFICATION fails
# here: asking the network to name an 80x60 blurry mouse crop against ImageNet's
# 1000 classes returns noise, because the features it needs were destroyed by
# the camera before inference ever started.
#
# But comparing TWO CROPS FROM THE SAME CAMERA through the same encoder is a
# different problem. Both sides carry identical blur and identical downsampling
# damage, so that degradation is common-mode and cancels in the comparison. We
# use the network as a learned, shift- and lighting-tolerant feature extractor
# and compare feature vectors by cosine similarity — never asking it "what is
# this object?", only "do these two views contain the same thing?".
#
# This keeps CLAUDE.md §8 intact: no class name is ever emitted, so this is not
# object-identity recognition. And it stays a genuine gatekeeper (§3a) on two
# counts — it runs ONLY inside the ambiguous similarity band, and it can only
# ever resolve PRESENT vs OCCLUDED. It can never declare an item ABSENT. ML in
# this pipeline can suppress an alarm; it can never raise one.

# Verified against models/mobilenetv2-7.onnx: this layer outputs (1, 1280, 1, 1),
# the global-average-pool immediately before the 1x1-conv classifier head. The
# "onnx_node!" prefix is required — there is no unprefixed alias for this node.
DNN_EMBED_LAYER = "onnx_node!mobilenetv20_features_pool0_fwd"
_dnn_embed_ok = dnn_net is not None
_dnn_embed_warned = False


def dnn_embed(bgr_crop):
    """Return the L2-normalised 1280-d MobileNetV2 feature vector for a crop.

    Returns None if the model or layer is unavailable — callers must treat None
    as "no opinion" and fall back to the classical decision, never as absence.
    Stopping at the pooling layer skips the classifier head, so this is actually
    cheaper than the full forward pass the old code ran.
    """
    global _dnn_embed_ok, _dnn_embed_warned
    if not _dnn_embed_ok or bgr_crop is None or bgr_crop.size == 0:
        return None
    if bgr_crop.shape[0] < 8 or bgr_crop.shape[1] < 8:
        return None
    try:
        resized = cv2.resize(bgr_crop, DNN_INPUT_SIZE, interpolation=cv2.INTER_AREA)
        rgb = cv2.cvtColor(resized, cv2.COLOR_BGR2RGB).astype(np.float32) / 255.0
        normalized = (rgb - IMAGENET_MEAN) / IMAGENET_STD
        blob = normalized.transpose(2, 0, 1)[np.newaxis, :, :, :].astype(np.float32)
        dnn_net.setInput(blob)
        vec = dnn_net.forward(DNN_EMBED_LAYER).flatten().astype(np.float32)
        norm = float(np.linalg.norm(vec))
        if norm < 1e-6:
            return None
        return vec / norm
    except Exception as e:
        if not _dnn_embed_warned:
            _dnn_embed_warned = True
            _dnn_embed_ok = False
            print(
                f"[SPOT-IT][DNN][WARN] Embedding layer '{DNN_EMBED_LAYER}' unavailable "
                f"({e}). Falling back to classical-only presence decisions; ambiguous "
                f"cases will be treated as PRESENT (fail open, never a false alarm)."
            )
        return None



def validate_presence_dnn(
    roi_crop,
    target_label,
    top_k=DNN_TOP_K,
    conf_threshold=DNN_CONFIDENCE_THRESHOLD,
    verbose=False,
):
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
    if (
        dnn_net is None
        or not DNN_CLASS_LABELS
        or roi_crop is None
        or roi_crop.size == 0
    ):
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
            "mini_fan": ["fan", "blower", "ventilator"],
            "headphones": ["headphones", "earphone", "headset"],
            "tumbler": ["tumbler", "cup", "mug", "bottle", "water bottle"],
            "box": [
                "box",
                "carton",
                "crate",
                "package",
                "container",
                "chest",
                "cube",
                "block",
            ],
            "mouse": ["mouse", "computer mouse", "pointing device", "trackball"],
            "keyboard": [
                "keyboard",
                "computer keyboard",
                "typewriter keyboard",
                "keypad",
            ],
            "monitor": [
                "monitor",
                "screen",
                "television",
                "computer monitor",
                "display",
            ],
            "pc": ["desktop computer", "computer", "screen", "monitor"],
            "computer": ["desktop computer", "computer", "screen", "monitor"],
            "laptop": ["laptop", "notebook", "notebook computer", "computer"],
            "watch": [
                "watch",
                "stopwatch",
                "digital watch",
                "wrist watch",
                "wristwatch",
                "clock",
                "timepiece",
            ],
            "umbrella": ["umbrella", "parasol", "brolly"],
        }

        search_terms = [target]
        if target in label_mappings:
            search_terms.extend(label_mappings[target])

        for idx in top_indices:
            class_names = [n.strip().lower() for n in DNN_CLASS_LABELS[idx].split(",")]
            for term in search_terms:
                if any(
                    term == name or term in name or name in term for name in class_names
                ):
                    confidence = float(probs[idx])
                    is_present = confidence >= conf_threshold
                    if verbose:
                        print(
                            f"[SPOT-IT][DNN] Target '{target_label}' matched ImageNet class '{DNN_CLASS_LABELS[idx]}' "
                            f"with {confidence:.1%} confidence (thresh: {conf_threshold:.1%}) -> Result: {'Present' if is_present else 'Absent'}"
                        )
                    return is_present, confidence

        # target_label never appeared in the top-K at all
        top1_idx = top_indices[0]
        top1_name = DNN_CLASS_LABELS[top1_idx]
        top1_confidence = float(probs[top1_idx])
        if verbose:
            print(
                f"[SPOT-IT][DNN] Target '{target_label}' NOT in top-{top_k} predictions. Top 1 predicted: '{top1_name}' ({top1_confidence:.1%}) -> Result: Absent"
            )
        return False, top1_confidence

    except Exception as e:
        print(f"[SPOT-IT][DNN][WARN] Inference failed: {e} — failing OPEN.")
        return True, 0.0


# ─────────────────────────────────────────────────────────────────────────

# ── THREADED VIDEO STREAM TO ELIMINATE RTSP LATENCY ───────────────────────
import threading


class VideoStream:
    STALE_TIMEOUT_SEC = 15  # reconnect if stream goes dead for 15 seconds

    def __init__(self, src):
        self.src = src
        self.fallback_src = (
            src.replace("/stream1", "/stream2") if "/stream1" in src else src
        )
        self.cap = None
        self.ret = False
        self.frame = None
        # Monotonic counter incremented on every genuinely NEW decoded frame.
        # The detection loop spins faster (~33 Hz) than the RTSP stream delivers
        # (15-20 fps), so without this it re-processes the same cached frame and
        # any frame-counting logic silently counts iterations, not observations.
        self.seq = 0
        self.last_good_frame_at = time.time()
        self.stopped = False
        self.lock = threading.Lock()

    def start(self):
        threading.Thread(target=self.update, args=(), daemon=True).start()
        threading.Thread(target=self._watchdog, args=(), daemon=True).start()
        return self

    def _try_open(self, url):
        try:
            temp_cap = cv2.VideoCapture(url)
            temp_cap.set(cv2.CAP_PROP_BUFFERSIZE, 1)
            if temp_cap.isOpened():
                r, f = temp_cap.read()
                if r and f is not None:
                    return temp_cap, r, f
            temp_cap.release()
        except Exception:
            pass
        return None, False, None

    def update(self):
        # Try main stream1 first; fallback to stream2 if stream1 is locked
        cap_obj, r, f = self._try_open(self.src)
        if cap_obj is None and self.fallback_src != self.src:
            print(
                f"[SPOT-IT][VideoStream] Main stream locked — attempting fallback to {self.fallback_src}..."
            )
            cap_obj, r, f = self._try_open(self.fallback_src)

        with self.lock:
            if cap_obj is not None:
                self.cap = cap_obj
                self.ret = r
                self.frame = f
                self.seq += 1
                self.last_good_frame_at = time.time()

        while not self.stopped:
            with self.lock:
                current_cap = self.cap

            if current_cap is None or not current_cap.isOpened():
                time.sleep(0.5)
                continue

            try:
                ret, frame = current_cap.read()
                if ret and frame is not None:
                    with self.lock:
                        self.ret = ret
                        self.frame = frame
                        self.seq += 1
                        self.last_good_frame_at = time.time()
                else:
                    time.sleep(0.05)
            except Exception:
                time.sleep(0.1)

    def _reconnect(self):
        if self.stopped:
            return
        print(f"[SPOT-IT][WATCHDOG] Dead stream detected. Reconnecting RTSP...")
        with self.lock:
            old_cap = self.cap
            self.cap = None

        if old_cap is not None:
            try:
                old_cap.release()
            except Exception:
                pass

        cap_obj, r, f = self._try_open(self.src)
        if cap_obj is None and self.fallback_src != self.src:
            cap_obj, r, f = self._try_open(self.fallback_src)

        with self.lock:
            if cap_obj is not None:
                self.cap = cap_obj
                self.ret = r
                self.frame = f
                self.seq += 1
                self.last_good_frame_at = time.time()
                print("[SPOT-IT][WATCHDOG] Reconnected successfully.")
            else:
                print("[SPOT-IT][WATCHDOG] Reconnect attempt failed.")

    def _watchdog(self):
        while not self.stopped:
            time.sleep(4)
            with self.lock:
                is_alive = self.cap is not None and self.ret
                last_time = self.last_good_frame_at

            if is_alive and (time.time() - last_time > self.STALE_TIMEOUT_SEC):
                self._reconnect()

    def read(self):
        with self.lock:
            return self.ret, self.frame

    def read_seq(self):
        """Like read(), but also returns the frame's sequence number so callers
        can tell a freshly decoded frame from a re-read of the cached one."""
        with self.lock:
            return self.ret, self.frame, self.seq

    def isOpened(self):
        with self.lock:
            return self.cap is not None and self.cap.isOpened() and self.ret

    def release(self):
        self.stopped = True
        with self.lock:
            old_cap = self.cap
            self.cap = None
            self.ret = False

        if old_cap is not None:
            try:
                old_cap.release()
            except Exception:
                pass


# ── CONNECT TO RTSP STREAM ────────────────────────────────────────────────
print(f"[SPOT-IT] Connecting to stream: {RTSP_URL}")
cap = VideoStream(RTSP_URL).start()

# Wait up to 15 seconds for the background connection to resolve
start_wait = time.time()
while not cap.isOpened() and time.time() - start_wait < 15.0:
    time.sleep(0.5)

if not cap.isOpened():
    print("[ERROR] Cannot open RTSP stream. Check:")
    print("  1. Camera and laptop are on the same WiFi network")
    print(f"  2. URL is correct: {RTSP_URL}")
    exit()

print("[SPOT-IT] Stream connected. Starting detection loop...")
print("[SPOT-IT] Press Q to quit.\n")

# ── LOAD / AUTO-CAPTURE REFERENCE FRAME ──────────────────────────────────
ref_bgr = cv2.imread(REF_IMAGE)
if ref_bgr is None or ref_bgr.shape[0] < 50:
    print(
        f"[SPOT-IT] Reference image missing or invalid! Capturing baseline directly from VideoStream..."
    )
    ref_wait_start = time.time()
    ret_ref, frame_ref = cap.read()
    while (not ret_ref or frame_ref is None) and (time.time() - ref_wait_start < 10.0):
        time.sleep(0.2)
        ret_ref, frame_ref = cap.read()

    if ret_ref and frame_ref is not None:
        width = int(frame_ref.shape[1] * STREAM_SCALE)
        height = int(frame_ref.shape[0] * STREAM_SCALE)
        ref_bgr = cv2.resize(frame_ref, (width, height), interpolation=cv2.INTER_AREA)
        os.makedirs(os.path.dirname(REF_IMAGE), exist_ok=True)
        cv2.imwrite(REF_IMAGE, ref_bgr)
        print(
            f"[SPOT-IT] Successfully captured and saved new baseline image: {REF_IMAGE}"
        )
    else:
        print(
            f"[ERROR] Could not read valid frame from VideoStream to auto-capture baseline."
        )
        exit()

ref_gray = cv2.cvtColor(ref_bgr, cv2.COLOR_BGR2GRAY)
ref_blur = cv2.GaussianBlur(ref_gray, (21, 21), 0)
TARGET_SIZE = (ref_bgr.shape[1], ref_bgr.shape[0])
print(
    f"[SPOT-IT] Reference frame loaded — size: {TARGET_SIZE} | Baseline items count: {BASELINE_COUNT}"
)

# ── PER-ROI STATE ─────────────────────────────────────────────────────────
consistency_count = {roi["label"]: 0 for roi in roi_list}
active_events = {roi["label"]: False for roi in roi_list}

# Phase 2 Upgrades State
active_detection_ids = {roi["label"]: None for roi in roi_list}
snapshot_b_triggered = {roi["label"]: False for roi in roi_list}
clean_snapshot_saved = {roi["label"]: False for roi in roi_list}
seq_completed = {roi["label"]: False for roi in roi_list}
last_seq_snapshot_time = {roi["label"]: 0 for roi in roi_list}
ok_consistency_count = {roi["label"]: 0 for roi in roi_list}
prev_roi_gray = {roi["label"]: None for roi in roi_list}
shifted_locations = {roi["label"]: None for roi in roi_list}
missing_timestamps = {}
registered_seq_count = {
    roi["label"]: 0 for roi in roi_list
}  # unlimited progression frame counter per Tier 1 item
unreg_first_seen = {}
stage2_triggered = {}
item_occupied_crops = {}
unreg_absence_count = {}
monitoring_paused = False

# ── DUAL-TEMPLATE PRESENCE STATE (see CLAUDE.md §3a) ──────────────────────
# label -> {footprint, frac, embed_present, embed_empty, hist_present, hist_empty}
# Built once against ref_bgr by calibrate_roi_baselines() and rebuilt on every
# config/baseline reload.
roi_templates = {}

# Wall-clock presence/absence accumulators. Frame counting is unreliable here
# because the loop outruns the RTSP frame rate (see VideoStream.seq).
absent_time = {roi["label"]: 0.0 for roi in roi_list}
present_time = {roi["label"]: 0.0 for roi in roi_list}
roi_state = {roi["label"]: "PRESENT" for roi in roi_list}
prev_roi_gray_prev = {roi["label"]: None for roi in roi_list}
last_tick_time = time.time()
last_processed_seq = -1

last_rois_mtime = os.path.getmtime(ROI_FILE) if os.path.exists(ROI_FILE) else 0
last_ref_mtime = os.path.getmtime(REF_IMAGE) if os.path.exists(REF_IMAGE) else 0
last_mode_mtime = 0
IS_PRODUCTION_MODE = False  # updated dynamically from detection_mode.json
SANDBOX_TRACKING_MODE = (
    "registered"  # 'registered' | 'unregistered' — updated dynamically
)


# ── HELPERS ───────────────────────────────────────────────────────────────
def validate_presence_classical(crop, label="object"):
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


prev_frame_blur = None


def is_scene_stable(frame_blur):
    global prev_frame_blur
    if prev_frame_blur is None:
        prev_frame_blur = frame_blur.copy()
        return True, 0.0
    full_diff = cv2.absdiff(prev_frame_blur, frame_blur)
    _, full_thr = cv2.threshold(full_diff, THRESHOLD, 255, cv2.THRESH_BINARY)
    total_px = TARGET_SIZE[0] * TARGET_SIZE[1]
    changed_px = cv2.countNonZero(full_thr)
    motion_pct = changed_px / total_px
    prev_frame_blur = frame_blur.copy()
    return motion_pct < SCENE_MOTION_LIMIT, motion_pct


def check_roi(roi, thresh_mask):
    x, y, w, h = roi["x"], roi["y"], roi["w"], roi["h"]
    roi_mask = thresh_mask[y : y + h, x : x + w]
    total_pixels = w * h
    changed_pixels = cv2.countNonZero(roi_mask)
    change_pct = changed_pixels / total_pixels
    return change_pct >= MIN_CHANGE_PERCENT, change_pct


# ═══════════════════════════════════════════════════════════════════════════
# DUAL-TEMPLATE PRESENCE DETECTION
# ═══════════════════════════════════════════════════════════════════════════
# THE PROBLEM (md_files/unfixable_limitations.md §1)
# The old pipeline asked "can I find the item's reference crop near this ROI?"
# and answered it with cv2.matchTemplate. On a textured wooden desk that
# question is unanswerable: the reference crop is mostly desk, so BARE DESK
# correlates against it at ~0.65 and a removed mouse still reads as present.
# Raising the threshold to reject that then rejects the item itself under a
# passing shadow. It is a formulation problem, not a tuning problem.
#
# THE FIX — give the removal something of its own to match against.
# The old comparison was one-sided: only "does this look like the item?", with
# no competing hypothesis. So we build a SECOND template at calibration time by
# inpainting the item out of the reference frame, reconstructing that patch of
# desk from the texture immediately around it. That is a physically meaningful
# prediction of what the camera will see once the item is lifted away.
#
# Every live frame is then scored against BOTH:
#     sim_present  = similarity(live, "item is here"  template)
#     sim_empty    = similarity(live, "item is gone"  template)
#
#     sim_present high                  -> PRESENT   (item still there)
#     sim_present low, sim_empty high   -> ABSENT    (bare surface revealed)
#     both low                          -> OCCLUDED  (a hand/bag is in the way)
#
# Wood grain can no longer cause a false PRESENT, because bare wood now matches
# the empty template far better than it matches the item template. The wood is
# still there in both — but it is no longer the only thing being compared.
#
# WHY THIS SURVIVES LIGHTING CHANGES
# Comparison is by MobileNetV2 feature-space cosine, not raw pixels. Measured on
# the real ROOM_1 frame at +-25% brightness, sim_present moved by less than 0.02.
#
# WHY OCCLUSION IS SAFE
# A hand matches neither template, so it lands in OCCLUDED, which HOLDS the
# timers rather than advancing them. A motionless hand resting on the mouse
# therefore cannot raise an alarm — the case the old local_motion > 0.05 hack
# structurally could not handle (unfixable_limitations.md §3).
#
# ML BOUNDARY (CLAUDE.md §3a, §8)
# The classical absdiff/threshold gate (check_roi) still does the first pass and
# decides whether anything changed at all; the network is consulted only on
# already-flagged crops. It compares two crops from the same camera and never
# emits a class name, so this is perceptual similarity, not object identity
# recognition. See dnn_embed() for why comparison succeeds on small crops where
# classification failed. classical_similarity() below is the fallback whenever
# the model is unavailable, so detection degrades rather than stopping.


def _chroma_hist(bgr_crop):
    """Chroma-only (a*, b*) Lab histogram — the classical similarity fallback.

    Excludes L* deliberately: lightness tracks illumination, so binning it makes
    a 25% dimming look like a different object. Hue/saturation is what actually
    identifies the item.
    """
    if bgr_crop is None or bgr_crop.size == 0:
        return None
    lab = cv2.cvtColor(bgr_crop, cv2.COLOR_BGR2LAB)
    hist = cv2.calcHist([lab], [1, 2], None, [16, 16], [0, 256, 0, 256])
    cv2.normalize(hist, hist, 0, 1, cv2.NORM_MINMAX)
    return hist.flatten()


def classical_similarity(ref_hist, live_crop):
    """Fallback similarity when MobileNetV2 is unavailable. Weaker than the
    embedding (chroma alone cannot tell a red tumbler from a red mug) but it
    keeps the same three-way decision working with no model present."""
    if ref_hist is None:
        return None
    live_hist = _chroma_hist(live_crop)
    if live_hist is None:
        return None
    try:
        return float(max(0.0, cv2.compareHist(ref_hist, live_hist, cv2.HISTCMP_CORREL)))
    except Exception:
        return None


def _inpaint_item_away(image_bgr, box):
    """Reconstruct the surface hidden behind an item, from its own surroundings.

    cv2.inpaint propagates the neighbouring texture inward, so for an item on a
    wooden desk it returns that patch of desk complete with plausible grain —
    exactly the appearance the camera will produce once the item is lifted.
    """
    x, y, w, h = box
    H, W = image_bgr.shape[:2]
    pad = max(w, h)
    px1, py1 = max(0, x - pad), max(0, y - pad)
    px2, py2 = min(W, x + w + pad), min(H, y + h + pad)
    region = image_bgr[py1:py2, px1:px2].copy()

    mask = np.zeros(region.shape[:2], np.uint8)
    mx, my = x - px1, y - py1
    mask[my + 2 : my + h - 2, mx + 2 : mx + w - 2] = 255
    if cv2.countNonZero(mask) == 0:
        return None

    filled = cv2.inpaint(region, mask, 5, cv2.INPAINT_TELEA)
    out = image_bgr.copy()
    out[py1:py2, px1:px2] = filled
    return out


def _item_footprint(ref_crop, empty_crop):
    """Locate the item's actual outline inside its ROI box.

    The item sits exactly where the reference frame disagrees with its own
    inpainted version, so differencing the two recovers its silhouette without
    any manual annotation.

    This matters a lot for small items: the mouse box is ~47% bare desk, and
    that padding dilutes every comparison equally, shrinking the gap between
    "present" and "removed" to almost nothing. Cropping to the footprint took
    the mouse's removal margin from 0.03 to a clean separation.

    Returns ((fx, fy, fw, fh), area_fraction) in ROI-local coordinates.
    """
    try:
        a = cv2.cvtColor(ref_crop, cv2.COLOR_BGR2LAB).astype(np.float32)
        b = cv2.cvtColor(empty_crop, cv2.COLOR_BGR2LAB).astype(np.float32)
        diff = np.linalg.norm(a - b, axis=2)
        thr = max(12.0, float(np.percentile(diff, 60)))
        m = (diff > thr).astype(np.uint8) * 255
        m = cv2.morphologyEx(m, cv2.MORPH_CLOSE, np.ones((5, 5), np.uint8))
        m = cv2.morphologyEx(m, cv2.MORPH_OPEN, np.ones((3, 3), np.uint8))
        n, _, stats, _ = cv2.connectedComponentsWithStats(m, 8)
        if n <= 1:
            return None, 0.0
        big = 1 + int(np.argmax(stats[1:, cv2.CC_STAT_AREA]))
        fx, fy, fw, fh, area = stats[big]
        pad = 2
        fx, fy = max(0, int(fx) - pad), max(0, int(fy) - pad)
        fw = min(ref_crop.shape[1] - fx, int(fw) + 2 * pad)
        fh = min(ref_crop.shape[0] - fy, int(fh) + 2 * pad)
        if fw < 6 or fh < 6:
            return None, 0.0
        frac = float(area) / float(ref_crop.shape[0] * ref_crop.shape[1])
        return (fx, fy, fw, fh), frac
    except Exception as e:
        print(f"[SPOT-IT][CALIB] footprint extraction failed: {e}")
        return None, 0.0


def evaluate_roi_presence(frame_bgr, roi, prev_state=None):
    """Classify one ROI as PRESENT / ABSENT / OCCLUDED for the current frame.

    Returns (state, sim_present, sim_empty, used_dnn).
    Fails safe: if this ROI was never calibrated, report PRESENT rather than
    inventing an alarm from a measurement we know is meaningless.

    Hysteresis: if coming out of ABSENT state, require higher confidence (0.85)
    to flip back to PRESENT, preventing oscillation on frame-to-frame noise.
    """
    label = roi["label"]
    tpl = roi_templates.get(label)
    if not tpl:
        return "PRESENT", 1.0, 0.0, False

    x, y, w, h = roi["x"], roi["y"], roi["w"], roi["h"]
    fx, fy, fw, fh = tpl["footprint"]
    live = frame_bgr[y + fy : y + fy + fh, x + fx : x + fx + fw]
    if live.size == 0 or live.shape[0] < 4 or live.shape[1] < 4:
        return "PRESENT", 1.0, 0.0, False

    used_dnn = False
    sim_p = sim_e = None
    if tpl.get("embed_present") is not None:
        live_vec = dnn_embed(live)
        if live_vec is not None:
            sim_p = float(np.dot(tpl["embed_present"], live_vec))
            sim_e = (
                float(np.dot(tpl["embed_empty"], live_vec))
                if tpl.get("embed_empty") is not None
                else 0.0
            )
            used_dnn = True

    if sim_p is None:  # model unavailable — classical chroma fallback
        sim_p = classical_similarity(tpl.get("hist_present"), live)
        sim_e = classical_similarity(tpl.get("hist_empty"), live)
        if sim_p is None:
            return "PRESENT", 1.0, 0.0, False
        if sim_e is None:
            sim_e = 0.0

    # Hysteresis: use slightly higher threshold to flip out of ABSENT to prevent noise
    # oscillation, but not so high that nudged items can't recover. 0.82 allows recovery
    # when the item is repositioned while still blocking frame-to-frame jitter at 0.75-0.80.
    present_threshold = 0.82 if prev_state == "ABSENT" else SIM_PRESENT

    # This is a two-hypothesis test, so absence must be *won*, not merely reached.
    # Requiring sim_empty > sim_present is what separates "the surface is showing"
    # from "something I cannot identify is in the way":
    #
    #   item present   here 1.00  gone 0.00   -> here wins        -> PRESENT
    #   item removed   here 0.50  gone 0.80   -> gone wins        -> ABSENT
    #   hand holding   here 0.77  gone 0.60   -> neither wins     -> OCCLUDED
    #
    # Without the comparison the last row fell through to ABSENT purely because
    # gone cleared an absolute floor, and a hand resting on the mouse raised an
    # alarm — the exact failure unfixable_limitations.md §3 exists to prevent.
    # SIM_EMPTY now acts only as a floor beneath that comparison.
    if sim_p >= present_threshold:
        state = "PRESENT"
    elif sim_e >= SIM_EMPTY and sim_e > sim_p:
        state = "ABSENT"
    else:
        state = "OCCLUDED"
    return state, sim_p, sim_e, used_dnn


def calibrate_roi_baselines(ref_image_bgr, rois):
    """Build both templates for every ROI. Runs at startup and on every reload.

    Cost is a handful of inpaints and DNN passes, paid once per calibration —
    nothing here runs per frame.
    """
    roi_templates.clear()
    if ref_image_bgr is None or not rois:
        return

    for r in rois:
        label = r["label"]
        x, y, w, h = r["x"], r["y"], r["w"], r["h"]
        ref_crop = ref_image_bgr[y : y + h, x : x + w]
        if ref_crop.size == 0 or w < 10 or h < 10:
            print(f"[SPOT-IT][CALIB][WARN] '{label}' ROI is too small to monitor.")
            continue

        empty_full = _inpaint_item_away(ref_image_bgr, (x, y, w, h))
        if empty_full is None:
            print(f"[SPOT-IT][CALIB][WARN] '{label}' inpainting failed; skipping.")
            continue
        empty_crop = empty_full[y : y + h, x : x + w]

        footprint, frac = _item_footprint(ref_crop, empty_crop)
        if footprint is None:
            # Nothing distinguishes the ROI from its own surroundings, which
            # means the baseline was captured with the item already absent, or
            # the item is invisible at this camera distance.
            print(
                f"[SPOT-IT][CALIB][WARN] '{label}' — no item found inside this ROI. "
                f"Re-capture the baseline with the item in place, or redraw the box."
            )
            continue
        if frac < FOOTPRINT_MIN_FRAC:
            print(
                f"[SPOT-IT][CALIB][WARN] '{label}' fills only {frac:.1%} of its box. "
                f"Redraw the ROI tighter around the item — detection will be weak."
            )

        fx, fy, fw, fh = footprint
        sub_ref = ref_crop[fy : fy + fh, fx : fx + fw]
        sub_empty = empty_crop[fy : fy + fh, fx : fx + fw]

        roi_templates[label] = {
            "footprint": footprint,
            "frac": frac,
            "embed_present": dnn_embed(sub_ref),
            "embed_empty": dnn_embed(sub_empty),
            "hist_present": _chroma_hist(sub_ref),
            "hist_empty": _chroma_hist(sub_empty),
        }

        mode = "DNN" if roi_templates[label]["embed_present"] is not None else "classical"
        print(
            f"[SPOT-IT][CALIB] '{label}': item footprint {fw}x{fh}px "
            f"({frac:.0%} of box) | {mode} templates ready"
        )


_metrics_header_written = False
_metrics_last = {}  # label -> last_state (for state-transition detection)


def log_roi_metrics(
    label, change_pct, sim_present, sim_empty, used_dnn, state,
    t_absent, t_present, scene_motion,
):
    """Append one row per ROI per frame to photos/detection_metrics.csv.

    Three jobs at once: it turns threshold tuning into reading a distribution
    instead of guessing, it produces the TP/FP/FN/TN evidence Chapter 4 needs,
    and it is the measurement substrate for the A/B run against
    main_classical_only.py (CLAUDE.md §3c).
    """
    global _metrics_header_written
    try:
        # Only log on state transitions. Steady-state rows are noise for Chapter 4 analysis
        # and create massive CSV files during long test runs. State transitions are what
        # matter: they mark the evidence for TP/FP/FN/TN classification.
        prev_state = _metrics_last.get(label)
        if state == prev_state:
            return
        _metrics_last[label] = state

        if not _metrics_header_written:
            _metrics_header_written = True
            if not os.path.exists(METRICS_CSV) or os.path.getsize(METRICS_CSV) == 0:
                with open(METRICS_CSV, "w", encoding="utf-8") as mf:
                    mf.write(
                        "ts,room,label,change_pct,sim_present,sim_empty,used_dnn,"
                        "state,absent_time,present_time,scene_motion\n"
                    )
        with open(METRICS_CSV, "a", encoding="utf-8") as mf:
            mf.write(
                f"{datetime.now().isoformat(timespec='milliseconds')},{ROOM_ID},{label},"
                f"{change_pct:.4f},{sim_present:.4f},{sim_empty:.4f},{int(used_dnn)},"
                f"{state},{t_absent:.2f},{t_present:.2f},{scene_motion:.4f}\n"
            )
    except Exception:
        pass  # instrumentation must never break detection


def save_frames_atomic(output_frame, clean_frame):
    try:
        live_path = os.path.join(SNAPSHOT_DIR, f"live_{ROOM_ID}.jpg")
        live_tmp = os.path.join(SNAPSHOT_DIR, f"live_{ROOM_ID}_tmp.jpg")
        clean_path = os.path.join(SNAPSHOT_DIR, f"clean_{ROOM_ID}.jpg")
        clean_tmp = os.path.join(SNAPSHOT_DIR, f"clean_{ROOM_ID}_tmp.jpg")

        res_live, buf_live = cv2.imencode(
            ".jpg", output_frame, [int(cv2.IMWRITE_JPEG_QUALITY), 85]
        )
        if res_live:
            with open(live_tmp, "wb") as f:
                f.write(buf_live.tobytes())
            os.replace(live_tmp, live_path)

        res_clean, buf_clean = cv2.imencode(
            ".jpg", clean_frame, [int(cv2.IMWRITE_JPEG_QUALITY), 85]
        )
        if res_clean:
            with open(clean_tmp, "wb") as f:
                f.write(buf_clean.tobytes())
            os.replace(clean_tmp, clean_path)
    except Exception:
        pass


def save_snapshot(frame, label, suffix="snapshot_A"):
    filename = f"{label}_{suffix}.jpg"
    filepath = os.path.join(SNAPSHOT_DIR, filename)
    cv2.imwrite(filepath, frame)
    return filepath


def count_missing_items():
    """Count how many ROIs are currently confirmed missing (consistency met)."""
    return sum(
        1
        for label in consistency_count
        if consistency_count[label] >= CONSISTENCY_FRAMES
    )


def get_roi_tier(roi):
    """Determine ROI tier based on label keywords (fallback if not in json).
    Handles both numeric (1) and string ('tier1') formats saved by the web editor.
    Falls back to keyword matching if 'tier' key is absent.
    """
    if "tier" in roi:
        t = roi["tier"]
        if isinstance(t, int):
            return t
        # Handle 'tier1', 'tier2', 'tier3', 'tier4' string formats
        if isinstance(t, str):
            t_lower = t.lower().replace("tier", "").strip()
            try:
                return int(t_lower)
            except ValueError:
                pass
    # Keyword fallback — Tier 1 = fixed lab equipment per thesis Table 3.2/3.3
    label = roi["label"].lower()
    fixed_keywords = [
        "monitor",
        "keyboard",
        "mouse",
        "pc",
        "laptop",
        "ups",
        "computer",
        "fan",
        "headphones",
        "mini_fan",
    ]
    if any(kw in label for kw in fixed_keywords):
        return 1
    return 2  # default to Tier 2 (personal/lost items)


def find_item_template_match(
    frame, ref_crop, roi, match_threshold=MATCH_SCORE_THRESHOLD
):
    """
    Progressively searches for the reference crop in wider zones of the frame:
    1.5x -> 2.0x -> full frame.
    Returns (found: bool, x: int, y: int, score: float)
    """
    h_f, w_f = frame.shape[:2]
    x, y, w, h = roi["x"], roi["y"], roi["w"], roi["h"]
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

        search_area = frame[sy : sy + sh, sx : sx + sw]
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
                    f"  -> API SUCCESS: detection_id={detection_id}, "
                    f"deviation={result.get('deviation')}, stage={result.get('stage')}"
                )
                return detection_id
            else:
                print(f"  -> API ERROR: {result.get('message')}")
        else:
            print(
                f"  -> API HTTP ERROR: {response.status_code} — {response.text[:200]}"
            )

    except requests.exceptions.RequestException as e:
        print(f"  -> API CONNECTION FAILED: {e}")
    return None


def post_snapshot_b(label, detection_id, snapshot_path_b):
    """
    POST Snapshot B (evidence of who touched/took the missing item)
    """
    if not detection_id:
        print("[WARN] Cannot post Snapshot B: No active detection_id found.")
        return

    payload = {
        "api_key": API_KEY,
        "action": "snapshot_b",
        "detection_id": detection_id,
    }

    try:
        with open(snapshot_path_b, "rb") as img_file:
            files = {
                "snapshot": (os.path.basename(snapshot_path_b), img_file, "image/jpeg")
            }
            response = requests.post(API_URL, data=payload, files=files, timeout=5)

        if response.status_code == 200:
            result = response.json()
            if result.get("success"):
                print(f"  -> Snapshot B API SUCCESS for Detection #{detection_id}")
            else:
                print(f"  -> Snapshot B API ERROR: {result.get('message')}")
        else:
            print(f"  -> Snapshot B API HTTP ERROR: {response.status_code}")

    except requests.exceptions.RequestException as e:
        print(f"  -> Snapshot B API CONNECTION FAILED: {e}")


def post_mass_deviation():
    """
    POST mass deviation event to pause room monitoring in the backend database.
    """
    payload = {
        "api_key": API_KEY,
        "action": "mass_deviation",
        "room_id": ROOM_ID,
    }

    try:
        response = requests.post(API_URL, data=payload, timeout=5)
        if response.status_code == 200:
            result = response.json()
            if result.get("success"):
                print(f"  -> Mass Deviation API SUCCESS: Room monitoring paused.")
            else:
                print(f"  -> Mass Deviation API ERROR: {result.get('message')}")
        else:
            print(f"  -> Mass Deviation API HTTP ERROR: {response.status_code}")
    except requests.exceptions.RequestException as e:
        print(f"  -> Mass Deviation API CONNECTION FAILED: {e}")


def post_item_recovered(label):
    """
    POST item_recovered event to auto-resolve detection when item physically returns to ROI.
    """
    payload = {
        "api_key": API_KEY,
        "action": "item_recovered",
        "room_id": ROOM_ID,
        "object_zone": label,
    }
    try:
        response = requests.post(API_URL, data=payload, timeout=5)
        if response.status_code == 200 and response.json().get("success"):
            print(f"  -> Item Recovered API SUCCESS for '{label}'")
    except Exception as e:
        pass


def get_camera_rtsp_url(room_id):
    """
    Query get_room_camera.php dynamically to fetch camera credentials and RTSP URL for the given room.
    """
    try:
        # Build URL dynamically from API_URL to support localhost or production server domain
        base_url = API_URL.split("/auth/")[0]
        url = f"{base_url}/auth/get_room_camera.php?room_id={room_id}&api_key={API_KEY}"

        response = requests.get(url, timeout=5)
        if response.status_code == 200:
            data = response.json()
            if data.get("success") and data.get("configured"):
                rtsp_url = data.get("rtsp_url")
                print(
                    f"[SPOT-IT] Retrieved camera RTSP url for room '{room_id}' dynamically."
                )
                return rtsp_url
            else:
                print(
                    f"[SPOT-IT][INFO] Room '{room_id}' has NO camera configured in database."
                )
                if room_id == "DESK":
                    return "rtsp://SpotItCamera:spotittapo232@192.168.18.11:554/stream1"
                return None
        else:
            print(
                f"[SPOT-IT][WARN] Failed to contact get_room_camera.php, status: {response.status_code}"
            )
    except Exception as e:
        print(f"[SPOT-IT][ERROR] Error fetching dynamic camera credentials: {e}")

    if room_id == "DESK":
        return "rtsp://SpotItCamera:spotittapo232@192.168.18.11:554/stream1"
    return None


# ── INITIALIZE RUNNING STATE FOR DYNAMIC ROOM MONITORING ──────────────────
current_running_room = ROOM_ID
CURRENT_RTSP_URL = get_camera_rtsp_url(ROOM_ID)

# Establish per-ROI occupancy baselines from the reference frame before the
# detection loop starts — every presence ratio is measured against these.
calibrate_roi_baselines(ref_bgr, roi_list)

# ── MAIN DETECTION LOOP ───────────────────────────────────────────────────
while True:
    # ── CHECK FOR DYNAMIC ROOM SWITCH ─────────────────────────────────────
    if CLI_ROOM_ID is None and ROOM_ID != current_running_room:
        print(
            f"\n[SPOT-IT] Dynamic room switch detected! Swapped from '{current_running_room}' -> '{ROOM_ID}'"
        )
        current_running_room = ROOM_ID

        # 1. Update dynamic file paths
        ROI_FILE = "rois.json" if ROOM_ID == "DESK" else f"rois_{ROOM_ID}.json"
        REF_IMAGE = (
            "photos/ref_image.jpg"
            if ROOM_ID == "DESK"
            else f"photos/ref_image_{ROOM_ID}.jpg"
        )

        # 2. Get the new RTSP URL
        new_rtsp_url = get_camera_rtsp_url(ROOM_ID)
        if new_rtsp_url != CURRENT_RTSP_URL:
            print(
                f"[SPOT-IT] Camera stream source updated for '{ROOM_ID}': {new_rtsp_url or 'UNASSIGNED'}"
            )
            CURRENT_RTSP_URL = new_rtsp_url
            if cap is not None:
                cap.release()
                cap = None
            time.sleep(1)
            if CURRENT_RTSP_URL:
                cap = VideoStream(CURRENT_RTSP_URL).start()
                start_wait = time.time()
                while not cap.isOpened() and time.time() - start_wait < 5.0:
                    time.sleep(0.5)

        # 3. Force reload of ROIs and reference images on next frame
        last_rois_mtime = -1
        last_ref_mtime = -1
    # ── CHECK FOR CONFIG RECALIBRATION CHANGES ────────────────────────────
    current_rois_mtime = os.path.getmtime(ROI_FILE) if os.path.exists(ROI_FILE) else 0
    current_ref_mtime = os.path.getmtime(REF_IMAGE) if os.path.exists(REF_IMAGE) else 0

    # Check dynamic testing vs production mode toggle
    # In TESTING mode: flood gate only triggers at 100% (all items gone).
    # In PRODUCTION mode: flood gate triggers at >50% (realistic lab scenario).
    mode_file = "detection_mode.json"
    current_mode_mtime = os.path.getmtime(mode_file) if os.path.exists(mode_file) else 0
    if current_mode_mtime > last_mode_mtime:
        last_mode_mtime = current_mode_mtime
        try:
            with open(mode_file, "r") as mf:
                mode_data = json.load(mf)
                mode = mode_data.get("mode", "testing")
                IS_PRODUCTION_MODE = mode == "production"
                SANDBOX_TRACKING_MODE = mode_data.get("tracking_mode", "registered")
                TIMER_MODE = mode_data.get("timer_mode", "testing_speed")
                # Only accept room_id from JSON if NOT launched with a CLI room argument
                if CLI_ROOM_ID is None:
                    new_room = mode_data.get("room_id", "DESK").upper()
                    if new_room != ROOM_ID:
                        ROOM_ID = new_room
                        ROI_FILE = (
                            "rois.json" if ROOM_ID == "DESK" else f"rois_{ROOM_ID}.json"
                        )
                        REF_IMAGE = (
                            "photos/ref_image.jpg"
                            if ROOM_ID == "DESK"
                            else f"photos/ref_image_{ROOM_ID}.jpg"
                        )
                        last_rois_mtime = -1
                        last_ref_mtime = -1

                # Dynamic timer threshold selection
                STAGE1_THRESH = 1800.0 if TIMER_MODE == "production_speed" else 3.0
                STAGE2_THRESH = 3600.0 if TIMER_MODE == "production_speed" else 6.0

                # Unpause monitoring if reset signal is set
                if mode_data.get("reset_paused", False) or mode_data.get(
                    "reset_rois", False
                ):
                    print(
                        "[SPOT-IT] Recalibration / status reset signal received! Unpausing live monitoring..."
                    )
                    monitoring_paused = False
                    missing_timestamps.clear()
                    unreg_first_seen.clear()
                    stage2_triggered.clear()
                    item_occupied_crops.clear()
                    unreg_absence_count.clear()
                    for r in roi_list:
                        lbl = r["label"]
                        consistency_count[lbl] = 0
                        active_events[lbl] = False
                        shifted_locations[lbl] = None
                        snapshot_b_triggered[lbl] = False
                        active_detection_ids[lbl] = None

                    # Clear reset flags in detection_mode.json
                    try:
                        mode_data["reset_paused"] = False
                        mode_data["reset_rois"] = False
                        with open(mode_file, "w") as mf_out:
                            json.dump(mode_data, mf_out, indent=2)
                    except Exception:
                        pass

                # Dismiss active alerts (clear internal state)
                if mode_data.get("clear_alerts", False):
                    print("[SPOT-IT] Dismiss alerts signal received! Clearing active alerts...")
                    for r in roi_list:
                        lbl = r["label"]
                        absent_time[lbl] = 0
                        present_time[lbl] = 0
                        consistency_count[lbl] = 0
                        active_events[lbl] = False
                        active_detection_ids[lbl] = None
                    missing_timestamps.clear()
                    unreg_absence_count.clear()
                    try:
                        mode_data["clear_alerts"] = False
                        with open(mode_file, "w") as mf_out:
                            json.dump(mode_data, mf_out, indent=2)
                    except Exception:
                        pass

                if IS_PRODUCTION_MODE:
                    print(
                        f"[SPOT-IT] Production Mode active for Room '{ROOM_ID}' — Timer Speed: {TIMER_MODE} (S1:{STAGE1_THRESH}s / S2:{STAGE2_THRESH}s) | Dual Tracking Active"
                    )
                else:
                    tracking_label = (
                        "Registered Items"
                        if SANDBOX_TRACKING_MODE == "registered"
                        else "Unregistered/Left Items"
                    )
                    print(
                        f"[SPOT-IT] Testing Mode active for Room '{ROOM_ID}' — Timer Speed: {TIMER_MODE} (S1:{STAGE1_THRESH}s / S2:{STAGE2_THRESH}s) | Tracking: {tracking_label}"
                    )
        except Exception as me:
            print(f"[SPOT-IT][ERROR] Failed to load detection mode: {me}")

    if current_rois_mtime > last_rois_mtime or current_ref_mtime > last_ref_mtime:
        print(
            "[SPOT-IT] Config or reference frame change detected! Reloading parameters dynamically..."
        )
        time.sleep(1.0)  # Wait a second for any write operations to fully finish
        prev_frame_blur = None
        monitoring_paused = False
        missing_timestamps.clear()

        try:
            # 1. Reload ROIs
            with open(ROI_FILE, "r") as f:
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
                print(
                    "[SPOT-IT] Reference image cleared or deleted — resetting active ROIs to empty."
                )
                roi_list = []
                ref_bgr = None

            # Re-initialize state dictionaries for the new list
            for roi in roi_list:
                lbl = roi["label"]
                if lbl not in consistency_count:
                    consistency_count[lbl] = 0
                if lbl not in active_events:
                    active_events[lbl] = False
                if lbl not in active_detection_ids:
                    active_detection_ids[lbl] = None
                if lbl not in snapshot_b_triggered:
                    snapshot_b_triggered[lbl] = False
                if lbl not in clean_snapshot_saved:
                    clean_snapshot_saved[lbl] = False
                if lbl not in seq_completed:
                    seq_completed[lbl] = False
                if lbl not in last_seq_snapshot_time:
                    last_seq_snapshot_time[lbl] = 0
                if lbl not in ok_consistency_count:
                    ok_consistency_count[lbl] = 0
                if lbl not in prev_roi_gray:
                    prev_roi_gray[lbl] = None
                if lbl not in shifted_locations:
                    shifted_locations[lbl] = None
                if lbl not in registered_seq_count:
                    registered_seq_count[lbl] = 0
                if lbl not in absent_time:
                    absent_time[lbl] = 0.0
                if lbl not in present_time:
                    present_time[lbl] = 0.0
                if lbl not in roi_state:
                    roi_state[lbl] = "PRESENT"
                if lbl not in prev_roi_gray_prev:
                    prev_roi_gray_prev[lbl] = None

            # Recompute per-ROI occupancy baselines against the new reference
            calibrate_roi_baselines(ref_bgr, roi_list)

            BASELINE_COUNT = len(roi_list)
            last_rois_mtime = current_rois_mtime
            last_ref_mtime = current_ref_mtime
            # Reinitialize all per-label state for new/changed ROI set
            for roi in roi_list:
                lbl = roi["label"]
                if lbl not in registered_seq_count:
                    registered_seq_count[lbl] = 0
            print(
                f"[SPOT-IT] Dynamic reload state updated! Active ROIs: {len(roi_list)}"
            )
        except Exception as reload_err:
            print(f"[SPOT-IT][ERROR] Failed to reload config: {reload_err}")

    if CURRENT_RTSP_URL is None or cap is None:
        # Standby mode for unassigned room — write a clean status frame
        placeholder = np.zeros((540, 960, 3), dtype=np.uint8)
        cv2.putText(
            placeholder,
            f"STANDBY: NO CAMERA ASSIGNED TO '{ROOM_ID}'",
            (120, 260),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.75,
            (0, 165, 255),
            2,
        )
        cv2.putText(
            placeholder,
            "Go to Room Setup to assign a CCTV camera IP.",
            (170, 300),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.55,
            (200, 200, 200),
            1,
        )
        save_frames_atomic(placeholder, placeholder)
        time.sleep(1.0)
        continue

    ret, frame, frame_seq = cap.read_seq()

    if not ret or frame is None:
        time.sleep(0.1)
        continue

    # Is this a genuinely new camera frame, or a re-read of the cached one?
    # Detection decisions are only advanced on new frames (see the wall-clock
    # timers in the ROI loop); rendering still happens every iteration so the
    # dashboard feed stays smooth.
    frame_is_new = frame_seq != last_processed_seq
    last_processed_seq = frame_seq

    if ROTATE_CAMERA:
        frame = cv2.rotate(frame, cv2.ROTATE_90_COUNTERCLOCKWISE)
    frame = rescaleFrame(frame)

    # ── CHECK REFERENCE FRAME IF MISSING / CLEARED ──────────────
    if ref_bgr is None:
        # Draw a warning overlay on the feed indicating no baseline is loaded
        output = frame.copy()
        cv2.putText(
            output,
            "NO BASELINE CAPTURED — calibration required.",
            (10, 30),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.55,
            (0, 165, 255),
            2,
        )
        for roi in roi_list:
            x, y, w, h = roi["x"], roi["y"], roi["w"], roi["h"]
            cv2.rectangle(output, (x, y), (x + w, y + h), (0, 165, 255), 1)
            cv2.putText(
                output,
                roi["label"],
                (x, y - 5),
                cv2.FONT_HERSHEY_SIMPLEX,
                0.45,
                (0, 165, 255),
                1,
            )
        save_frames_atomic(output, frame)
        if SHOW_GUI:
            cv2.imshow("S.P.O.T.-IT Live Detection", output)
            if cv2.waitKey(1) & 0xFF == ord("q"):
                break
        else:
            time.sleep(0.05)
        continue

    # ── RESIZE REFERENCE FRAME IF SIZES MISMATCH ──────────────────────────
    if frame.shape[:2] != ref_bgr.shape[:2]:
        print(
            f"[SPOT-IT][WARN] Resolution mismatch! Live frame is {frame.shape[1]}x{frame.shape[0]}, "
            f"but reference frame is {ref_bgr.shape[1]}x{ref_bgr.shape[0]}. Resizing reference baseline to fit..."
        )
        ref_bgr = cv2.resize(ref_bgr, (frame.shape[1], frame.shape[0]))
        ref_gray = cv2.cvtColor(ref_bgr, cv2.COLOR_BGR2GRAY)
        ref_blur = cv2.GaussianBlur(ref_gray, (21, 21), 0)
        TARGET_SIZE = (ref_bgr.shape[1], ref_bgr.shape[0])

    gray = cv2.cvtColor(frame, cv2.COLOR_BGR2GRAY)
    blur = cv2.GaussianBlur(gray, (21, 21), 0)

    full_diff = cv2.absdiff(ref_blur, blur)
    _, thresh = cv2.threshold(full_diff, THRESHOLD, 255, cv2.THRESH_BINARY)

    output = frame.copy()

    # ── CHECK MONITORING PAUSED ───────────────────────────────────────────
    if monitoring_paused:
        cv2.putText(
            output,
            "MASS DEVIATION DETECTED — paused. Recalibration required.",
            (10, 30),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.55,
            (0, 0, 255),
            2,
        )

        for roi in roi_list:
            x, y, w, h = roi["x"], roi["y"], roi["w"], roi["h"]
            cv2.rectangle(output, (x, y), (x + w, y + h), (0, 0, 255), 2)
            cv2.putText(
                output,
                f"LOCKED: {roi['label']}",
                (x, y - 5),
                cv2.FONT_HERSHEY_SIMPLEX,
                0.45,
                (0, 0, 255),
                1,
            )

        save_frames_atomic(output, frame)

        if SHOW_GUI:
            cv2.imshow("S.P.O.T.-IT Live Detection", output)
            if cv2.waitKey(1) & 0xFF == ord("q"):
                break
        else:
            time.sleep(0.03)  # Prevent CPU hogging
        continue

    # ── GATE 1: SCENE STABILITY ───────────────────────────────────────────
    stable, motion_pct = is_scene_stable(blur)

    if not stable:
        # HOLD, do not reset. Previously this zeroed every consistency counter,
        # which created a false-negative path: physically removing an item IS
        # motion, so the counter was wiped on exactly the frames that mattered
        # and an item taken during any activity could never be confirmed missing.
        # Freezing the accumulators is equally safe against false alarms (no
        # counter advances while we cannot see clearly) without that blind spot.
        last_tick_time = time.time()

        cv2.putText(
            output,
            f"SCENE UNSTABLE — holding ({motion_pct:.1%} motion)",
            (10, 30),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.55,
            (0, 165, 255),
            2,
        )

        for roi in roi_list:
            x, y, w, h = roi["x"], roi["y"], roi["w"], roi["h"]
            cv2.rectangle(output, (x, y), (x + w, y + h), (0, 165, 255), 2)
            cv2.putText(
                output,
                roi["label"],
                (x, y - 5),
                cv2.FONT_HERSHEY_SIMPLEX,
                0.45,
                (0, 165, 255),
                1,
            )

        save_frames_atomic(output, frame)

        if SHOW_GUI:
            cv2.imshow("S.P.O.T.-IT Live Detection", output)
            if cv2.waitKey(1) & 0xFF == ord("q"):
                break
        else:
            time.sleep(0.03)
        continue

    output = frame.copy()
    production_left_state = {}

    # ── UNREGISTERED / LEFT ITEMS MODE (Foreground Contour Tracking + ML Gate) ────────
    if SANDBOX_TRACKING_MODE == "unregistered" or IS_PRODUCTION_MODE:
        diff_unreg = cv2.absdiff(ref_blur, blur)
        _, thresh_unreg = cv2.threshold(diff_unreg, 35, 255, cv2.THRESH_BINARY)
        kernel = cv2.getStructuringElement(cv2.MORPH_RECT, (9, 9))
        thresh_unreg = cv2.morphologyEx(thresh_unreg, cv2.MORPH_OPEN, kernel)
        thresh_unreg = cv2.morphologyEx(thresh_unreg, cv2.MORPH_DILATE, kernel)

        contours, _ = cv2.findContours(
            thresh_unreg, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE
        )

        unreg_count = 0
        live_left_state = {}
        now_ts = time.time()
        current_seen_labels = set()

        for cnt in contours:
            area = cv2.contourArea(cnt)
            if area >= 4500:  # Filter out small shadow noise (~65x65 px)
                x, y, w, h = cv2.boundingRect(cnt)

                # Exclude camera edge border reflections
                if (
                    y + h > TARGET_SIZE[1] - 15
                    or y < 10
                    or x < 10
                    or x + w > TARGET_SIZE[0] - 10
                ):
                    continue

                # Secondary Gatekeeper: Objectness & Texture Filter.
                # NOTE: this previously called validate_presence_dnn(crop) with a
                # single argument while that function requires target_label
                # positionally — an uncaught TypeError that killed the process on
                # the first left-item contour. It also asked the wrong question:
                # ImageNet classification cannot name a small CCTV crop (see
                # md_files/unfixable_limitations.md §2). What this gate actually
                # needs is objectness — "is this a real object or a flat shadow?"
                # — which validate_presence_classical() already answers via
                # Laplacian variance + edge density.
                crop = frame[y : y + h, x : x + w]
                is_real_obj, conf = validate_presence_classical(crop, label=f"object{unreg_count + 1}")
                if not is_real_obj:
                    # Flat shadow / lighting noise -> REJECTED by objectness gate
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

                # Determine Stage Color & Label based on Real Clock Time (time.time()) & Speed Mode
                if elapsed >= STAGE2_THRESH:
                    box_color = (0, 0, 255)  # RED
                    label_text = f"CONFIRMED LOST: {label} ({elapsed:.1f}s)"
                elif elapsed >= STAGE1_THRESH:
                    box_color = (0, 215, 255)  # YELLOW
                    label_text = f"UNATTENDED: {label} ({elapsed:.1f}s)"
                else:
                    box_color = (255, 255, 0)  # CYAN
                    label_text = f"CHECKING: {label} ({elapsed:.1f}s)"

                # DRAW SQUARE BOUNDING BOX & LABEL
                cv2.rectangle(output, (x, y), (x + w, y + h), box_color, 2)
                cv2.putText(
                    output,
                    label_text,
                    (x, y - 8),
                    cv2.FONT_HERSHEY_SIMPLEX,
                    0.48,
                    box_color,
                    2,
                )

                live_left_state[label] = {"is_missing": False, "tier": "tier2"}

                # STAGE 1 (Yellow at STAGE1_THRESH): Log as potential unattended item
                if elapsed >= STAGE1_THRESH and not active_events[label]:
                    active_events[label] = True
                    snapshot_path = save_snapshot(frame, label, "snapshot_A")
                    print(
                        f"\n[STAGE 1 (YELLOW {STAGE1_THRESH}s): UNATTENDED ITEM DETECTED: {label}]"
                    )
                    det_id = post_to_api(
                        label,
                        snapshot_path,
                        float(area) / (TARGET_SIZE[0] * TARGET_SIZE[1]),
                    )
                    active_detection_ids[label] = det_id
                    try:
                        item_occupied_crops[label] = cv2.cvtColor(
                            crop.copy(), cv2.COLOR_BGR2GRAY
                        )
                    except Exception:
                        pass

                # STAGE 2 (Red at STAGE2_THRESH): Escalate status to confirmed_missing
                if (
                    elapsed >= STAGE2_THRESH
                    and active_events[label]
                    and not stage2_triggered.get(label, False)
                ):
                    stage2_triggered[label] = True
                    print(
                        f"[STAGE 2 (RED {STAGE2_THRESH}s): CONFIRMED LOST ESCALATION: {label}]"
                    )
                    if active_detection_ids.get(label):
                        try:
                            requests.post(
                                API_URL,
                                data={
                                    "api_key": API_KEY,
                                    "action": "escalate_status",
                                    "detection_id": active_detection_ids[label],
                                    "status": "confirmed_missing",
                                },
                                timeout=3,
                            )
                        except Exception:
                            pass

        # ── SNAPSHOT B TRIGGER & STATE CLEANUP (Item Removal Evidence & Reset) ────
        # Checks all active logged left items OUTSIDE the contour loop.
        # 1) Fires Snapshot B when item is taken
        # 2) Resets timer & tracking state when item is removed from desk (>10 frames absence)
        known_unreg_labels = list(
            set(list(unreg_first_seen.keys()) + list(active_events.keys()))
        )
        for lbl in known_unreg_labels:
            if lbl in current_seen_labels:
                unreg_absence_count[lbl] = 0
            else:
                unreg_absence_count[lbl] = unreg_absence_count.get(lbl, 0) + 1

                # Check Snapshot B trigger if item was active and just taken
                if active_events.get(lbl, False) and not snapshot_b_triggered.get(
                    lbl, False
                ):
                    snapshot_b_triggered[lbl] = True
                    snapshot_b_path = save_snapshot(frame, lbl, "snapshot_B")
                    det_id = active_detection_ids.get(lbl)
                    print(
                        f"\n[SNAPSHOT B TRIGGERED: Item '{lbl}' TAKEN / REMOVED from desk!]"
                    )
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

        # In PRODUCTION both jobs must run on the same frame: watch the
        # registered lab equipment AND spot unregistered left items. Previously
        # this branch always `continue`d, so whenever production mode was on the
        # registered-ROI loop below never executed at all and no lab equipment
        # was monitored. Only the sandbox 'unregistered' test mode short-circuits
        # here; production falls through, carrying its left-item state along to
        # be merged into the single live_roi_state.json the dashboard polls.
        if not IS_PRODUCTION_MODE:
            try:
                with open("photos/live_roi_state.json", "w") as sf:
                    json.dump(live_left_state, sf)
            except Exception:
                pass

            save_frames_atomic(output, frame)

            if SHOW_GUI:
                cv2.imshow("S.P.O.T.-IT Live Detection", output)
                if cv2.waitKey(1) & 0xFF == ord("q"):
                    break
            else:
                time.sleep(0.03)
            continue

        production_left_state = live_left_state

    # ── GATE 2 + 3: REGISTERED ROI PRESENCE + WALL-CLOCK CONFIRMATION ───────
    missing_count = 0

    # Elapsed wall-clock since the last evaluated frame. Presence/absence is
    # accumulated in seconds, not frames, because the loop spins faster than the
    # camera delivers and frame counts therefore do not measure time.
    _now = time.time()
    tick_dt = min(0.5, max(0.0, _now - last_tick_time))  # clamp to survive stalls
    last_tick_time = _now

    for roi in roi_list:
        x, y, w, h = roi["x"], roi["y"], roi["w"], roi["h"]
        label = roi["label"]
        base_tier = get_roi_tier(roi)

        # Safety guard: Ensure all state dictionaries contain 'label'
        if label not in active_events:
            active_events[label] = False
        if label not in consistency_count:
            consistency_count[label] = 0
        if label not in ok_consistency_count:
            ok_consistency_count[label] = 0
        if label not in active_detection_ids:
            active_detection_ids[label] = None
        if label not in snapshot_b_triggered:
            snapshot_b_triggered[label] = False
        if label not in clean_snapshot_saved:
            clean_snapshot_saved[label] = False
        if label not in seq_completed:
            seq_completed[label] = False
        if label not in last_seq_snapshot_time:
            last_seq_snapshot_time[label] = 0
        if label not in shifted_locations:
            shifted_locations[label] = None
        if label not in registered_seq_count:
            registered_seq_count[label] = 0

        # ── SANDBOX TRACKING MODE OVERRIDE ──────────────────────────────────
        # In production, use actual ROI tier from JSON/keywords (full pipeline).
        # In sandbox testing:
        #   'registered'   → treat ALL ROIs as Tier 1 (DNN check, sequential snapshots)
        #   'unregistered' → treat ALL ROIs as Tier 2 (template match, Snapshot A/B)
        if IS_PRODUCTION_MODE:
            tier = base_tier
        elif SANDBOX_TRACKING_MODE == "registered":
            tier = 1
        else:  # 'unregistered'
            tier = 2

        # Compute frame-to-frame pixel change inside this ROI (hand movement/typing).
        # Kept for the Snapshot B / clean-frame watchers below, which need the
        # PREVIOUS frame's crop — hence prev_roi_gray_prev, preserved before
        # prev_roi_gray is overwritten. Without that separation both watchers
        # diffed a crop against a copy of itself and always measured zero motion,
        # silently disabling Snapshot B entirely.
        roi_gray = gray[y : y + h, x : x + w]
        prev_roi_gray_prev[label] = prev_roi_gray.get(label)
        local_motion = 0.0
        if (
            prev_roi_gray_prev[label] is not None
            and prev_roi_gray_prev[label].shape == roi_gray.shape
        ):
            roi_diff = cv2.absdiff(prev_roi_gray_prev[label], roi_gray)
            _, roi_thr = cv2.threshold(roi_diff, 15, 255, cv2.THRESH_BINARY)
            local_motion = cv2.countNonZero(roi_thr) / (w * h)
        prev_roi_gray[label] = roi_gray.copy()

        # ── GATE 2: CLASSICAL CHANGE DETECTION (first pass) ───────────────
        # Purely classical absdiff vs the baseline. This decides WHETHER
        # anything in the ROI is worth a closer look; it never decides presence.
        # Keeping this first is what preserves "classical primary, ML secondary"
        # (CLAUDE.md §3a) — on a quiet frame the network is never invoked at all,
        # which is also why the added cost is negligible.
        changed, change_pct = check_roi(roi, thresh)

        # ══════════════════════════════════════════════════════════════════
        # GATE 3: PRESENCE DECISION — dual-template (see evaluate_roi_presence)
        # ══════════════════════════════════════════════════════════════════
        # Scores the ROI against BOTH "item is here" and "item is gone"
        # templates. Bare wood matches the empty template, so it can no longer
        # masquerade as the item the way it did under plain template matching.
        if not changed:
            # Nothing changed against the baseline — the item is where it was.
            state, sim_present, sim_empty, used_dnn = "PRESENT", 1.0, 0.0, False
        else:
            state, sim_present, sim_empty, used_dnn = evaluate_roi_presence(
                frame, roi, prev_state=roi_state.get(label)
            )

        # ── WALL-CLOCK CONFIRMATION ──────────────────────────────────────
        # Only advance timers on genuinely new camera frames; re-reads of the
        # cached frame carry no new evidence.
        if frame_is_new:
            if state == "ABSENT":
                absent_time[label] = absent_time.get(label, 0.0) + tick_dt
                present_time[label] = 0.0
            elif state == "PRESENT":
                present_time[label] = present_time.get(label, 0.0) + tick_dt
                absent_time[label] = 0.0
            # OCCLUDED holds both accumulators exactly where they are

        roi_state[label] = state
        is_actually_missing = absent_time.get(label, 0.0) >= MISSING_CONFIRM_SEC
        item_confirmed_present = present_time.get(label, 0.0) >= RECOVER_CONFIRM_SEC

        # Keep the frame-count fields the dashboard/JSON contract expects in sync
        # with the wall-clock truth, so no PHP or JS has to change.
        consistency_count[label] = (
            CONSISTENCY_FRAMES
            if is_actually_missing
            else int(
                min(
                    CONSISTENCY_FRAMES - 1,
                    (absent_time.get(label, 0.0) / MISSING_CONFIRM_SEC)
                    * CONSISTENCY_FRAMES,
                )
            )
        )
        shifted_locations[label] = None

        log_roi_metrics(
            label,
            change_pct,
            sim_present,
            sim_empty,
            used_dnn,
            state,
            absent_time.get(label, 0.0),
            present_time.get(label, 0.0),
            motion_pct,
        )

        # NOTE: the old SHIFT-SEARCH MATCH GATE (cv2.matchTemplate + Canny edge
        # density ratio + colour-diff fallback) previously sat here. It has been
        # replaced by the dual-template decision above. The template-match
        # approach is preserved unmodified in main_classical_only.py as the A/B
        # baseline for Chapter 4 (CLAUDE.md §3c).

        if state == "ABSENT" and not active_events[label]:
            ok_consistency_count[label] = 0
            if absent_time[label] <= tick_dt * 1.5:  # first frame of this absence
                print(
                    f"[SPOT-IT] '{label}' surface revealed where the item was "
                    f"(match vs item {sim_present:.2f}, vs empty surface "
                    f"{sim_empty:.2f}). Confirming..."
                )

            # ── PROGRESSION SNAPSHOT SEQUENCE (Tier 1 / Registered Items) ──
            # Captures sequential snapshots (registered_1.jpg to registered_5.jpg max)
            # as the item is moved/lifted. Throttled to ~200ms intervals.
            now_ts = time.time()
            if (
                tier == 1
                and not seq_completed[label]
                and registered_seq_count[label] < 5
            ):
                if now_ts - last_seq_snapshot_time[label] >= 0.20:
                    last_seq_snapshot_time[label] = now_ts
                    registered_seq_count[label] += 1
                    seq_filename = (
                        f"{label}_registered_{registered_seq_count[label]}.jpg"
                    )
                    save_snapshot(
                        frame, label, f"registered_{registered_seq_count[label]}"
                    )
                    print(
                        f"[SPOT-IT] Progression snapshot #{registered_seq_count[label]}/5 saved for '{label}' -> {seq_filename}"
                    )
                    if registered_seq_count[label] >= 5:
                        seq_completed[label] = True

        elif item_confirmed_present:
            # Item has been continuously and unambiguously present for
            # RECOVER_CONFIRM_SEC. Note this requires state == PRESENT that whole
            # time — an OCCLUDED zone freezes the timer, so a hand hovering over
            # an empty spot cannot clear a live alert.
            ok_consistency_count[label] += 1
            if active_events[label]:
                print(
                    f"[SPOT-IT] '{label}' confirmed returned to OK state. Auto-resolving alert..."
                )
                post_item_recovered(label)
            active_events[label] = False
            active_detection_ids[label] = None
            snapshot_b_triggered[label] = False
            clean_snapshot_saved[label] = False
            seq_completed[label] = False
            last_seq_snapshot_time[label] = 0
            shifted_locations[label] = None
            registered_seq_count[label] = 0
            if label in missing_timestamps:
                del missing_timestamps[label]

        confirmed_missing = is_actually_missing and not active_events[label]

        if confirmed_missing:
            active_events[label] = True

            if tier == 1:
                curr_idx = max(1, min(5, registered_seq_count[label]))
                snapshot_path = os.path.join(
                    SNAPSHOT_DIR, f"{label}_registered_{curr_idx}.jpg"
                )
                if not os.path.exists(snapshot_path):
                    snapshot_path = save_snapshot(
                        frame, label, f"registered_{curr_idx}"
                    )
            else:
                snapshot_path = save_snapshot(frame, label, "snapshot_A")

            print(f"\n[CONFIRMED MISSING EVENT (RED) DETECTED]")
            print(f"  ROI Label    : {label} (Tier {tier})")
            print(f"  Room ID      : {ROOM_ID}")
            print(f"  Detected At  : {datetime.now().isoformat()}")
            print(f"  Snapshot     : {snapshot_path}")
            print(
                f"  Change       : {change_pct:.1%} over {CONSISTENCY_FRAMES} consecutive frames"
            )

            det_id = post_to_api(label, snapshot_path, change_pct)
            active_detection_ids[label] = det_id
            missing_timestamps[label] = time.time()
            print()

            # ── AUTO-FLOOD GATE PROTECTION ────────────────────────────────
            recent_missing = [
                lbl for lbl, ts in missing_timestamps.items() if time.time() - ts <= 60
            ]
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
                print(
                    f"[ALERT] Auto-Flood Gate triggered! {n_missing}/{n_rois} items went missing ({'production' if IS_PRODUCTION_MODE else 'testing'} mode)."
                )
                monitoring_paused = True
                post_mass_deviation()
                break  # Exit ROI loop immediately

        # ── FINAL POST-REMOVAL FRAME COMPLETION ─────────────────────────────
        # Once item is confirmed missing, capture ONE final clean frame showing empty ROI after hand leaves
        if active_events[label] and not clean_snapshot_saved[label]:
            # Uses prev_roi_gray_prev (the PREVIOUS frame's crop). prev_roi_gray
            # was already overwritten with this frame's crop at the top of the
            # loop, so diffing against it always yielded 0.0 motion and this
            # "wait for the hand to leave" gate fired instantly on every event.
            if (
                prev_roi_gray_prev[label] is not None
                and prev_roi_gray_prev[label].shape == roi_gray.shape
            ):
                ftf_diff = cv2.absdiff(prev_roi_gray_prev[label], roi_gray)
                _, ftf_thresh = cv2.threshold(ftf_diff, 15, 255, cv2.THRESH_BINARY)
                motion_val = cv2.countNonZero(ftf_thresh) / (w * h)

                # When motion settles below 4%, hand has finished removing item and left ROI
                if motion_val < 0.04:
                    clean_snapshot_saved[label] = True
                    seq_completed[label] = True
                    final_path = os.path.join(
                        SNAPSHOT_DIR, f"{label}_registered_final.jpg"
                    )
                    cv2.imwrite(final_path, frame)
                    print(
                        f"[SPOT-IT] Movement finished for '{label}'. Final clean post-removal frame saved (hand left ROI)."
                    )

        # ── SNAPSHOT B LOGIC (Unregistered / Tier 2 Only) ──────────────────
        # If item is already confirmed missing, watch it for subsequent movement (theft/hand capture)
        if tier == 2 and active_events[label] and not snapshot_b_triggered[label]:
            # Also fixed to read prev_roi_gray_prev — against the clobbered
            # prev_roi_gray this diff was always exactly 0.0, so the >= 0.05 gate
            # could never be met and Snapshot B never once fired for a registered
            # ROI, despite being a documented requirement (CLAUDE.md §3c).
            if prev_roi_gray_prev[label] is not None:
                if prev_roi_gray_prev[label].shape == roi_gray.shape:
                    # Frame-to-frame difference within the empty ROI
                    ftf_diff = cv2.absdiff(prev_roi_gray_prev[label], roi_gray)
                    _, ftf_thresh = cv2.threshold(ftf_diff, 15, 255, cv2.THRESH_BINARY)
                    motion_val = cv2.countNonZero(ftf_thresh) / (w * h)

                    if motion_val >= 0.05:  # 5% of pixels changed frame-to-frame
                        snapshot_b_triggered[label] = True
                        snapshot_path_b = save_snapshot(frame, label, "snapshot_B")
                        print(f"\n[INTERACTION DETECTED (Snapshot B)]")
                        print(f"  ROI Label    : {label}")
                        print(f"  Detection ID : {active_detection_ids[label]}")
                        print(f"  Snapshot B   : {snapshot_path_b}")
                        post_snapshot_b(
                            label, active_detection_ids[label], snapshot_path_b
                        )
                        print()

        # ── DRAW ROI BOXES ────────────────────────────────────────────────
        if is_actually_missing:
            missing_count += 1
            cv2.rectangle(output, (x, y), (x + w, y + h), (0, 0, 255), 2)
            cv2.putText(
                output,
                f"MISSING: {label}",
                (x, y - 10),
                cv2.FONT_HERSHEY_SIMPLEX,
                0.5,
                (0, 0, 255),
                2,
            )
        elif state == "ABSENT":
            pct = min(1.0, absent_time.get(label, 0.0) / MISSING_CONFIRM_SEC)
            cv2.rectangle(output, (x, y), (x + w, y + h), (0, 255, 255), 2)
            cv2.putText(
                output,
                f"CHECKING: {label} ({pct:.0%})",
                (x, y - 10),
                cv2.FONT_HERSHEY_SIMPLEX,
                0.45,
                (0, 255, 255),
                1,
            )
        elif state == "OCCLUDED":
            # Blue = "cannot see the item, holding judgement". Distinct from
            # yellow (counting down to an alarm) so the operator can tell at a
            # glance that nothing is wrong — someone's hand is simply in the way.
            cv2.rectangle(output, (x, y), (x + w, y + h), (255, 180, 0), 2)
            cv2.putText(
                output,
                f"OCCLUDED: {label}",
                (x, y - 10),
                cv2.FONT_HERSHEY_SIMPLEX,
                0.45,
                (255, 180, 0),
                1,
            )
        else:
            cv2.rectangle(output, (x, y), (x + w, y + h), (0, 255, 0), 2)
            cv2.putText(
                output,
                f"OK: {label}",
                (x, y - 10),
                cv2.FONT_HERSHEY_SIMPLEX,
                0.5,
                (0, 255, 0),
                1,
            )

        # Live diagnostics under each box: occupancy ratio and appearance
        # similarity — the two numbers that decided the state above. Without
        # these visible, threshold tuning is guesswork.
        cv2.putText(
            output,
            f"here={sim_present:.2f} gone={sim_empty:.2f}",
            (x, y + h + 14),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.38,
            (220, 220, 220),
            1,
        )

    # ── STATUS BAR ────────────────────────────────────────────────────────
    if not monitoring_paused:
        if missing_count > 0:
            status_text = f"ALERT: {missing_count} item(s) missing"
            cv2.putText(
                output,
                status_text,
                (10, 30),
                cv2.FONT_HERSHEY_SIMPLEX,
                0.55,
                (0, 0, 255),
                2,
            )

        cv2.putText(
            output,
            f"Scene motion: {motion_pct:.1%}",
            (10, TARGET_SIZE[1] - 10),
            cv2.FONT_HERSHEY_SIMPLEX,
            0.4,
            (200, 200, 200),
            1,
        )

    # ── WRITE REAL-TIME PHYSICAL ROI STATE FOR PHP ENDPOINTS ──────────────
    try:
        # Start from any left-item state produced earlier this frame so that in
        # production BOTH registered equipment and unregistered left items reach
        # the dashboard through the single file it polls.
        live_state_map = dict(production_left_state)
        for r in roi_list:
            lbl = r["label"]
            live_state_map[lbl] = {
                "is_missing": active_events.get(lbl, False)
                or absent_time.get(lbl, 0.0) >= MISSING_CONFIRM_SEC,
                "consistency": consistency_count.get(lbl, 0),
                "state": roi_state.get(lbl, "PRESENT"),
                "tier": r.get("tier", "tier1"),
            }
        with open("photos/live_roi_state.json", "w") as sf:
            json.dump(live_state_map, sf)
    except Exception:
        pass

    # Write live stream frame and clean baseline frame for web dashboard
    save_frames_atomic(output, frame)

    if SHOW_GUI:
        cv2.imshow("S.P.O.T.-IT Live Detection", output)
        if cv2.waitKey(1) & 0xFF == ord("q"):
            break
    else:
        time.sleep(0.03)

cap.release()
cv2.destroyAllWindows()
print("[SPOT-IT] Stream closed.")
