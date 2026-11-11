"""YOLO (YOLOX-S, COCO-80) object detector for S.P.O.T.-IT, via OpenCV DNN.

Model: OpenCV Model Zoo `object_detection_yolox_2022nov.onnx` (Apache-2.0),
stored as models/yolox_s.onnx. Same runtime as the MobileNetV2 gate (cv2.dnn),
so no PyTorch is needed on the lab PC. Pre/post-processing follows the Model Zoo
reference demo: letterbox to 640x640 (pad 114, top-left), raw BGR 0-255, grid +
stride decoding, class-aware NMS.

Used for:
  * the experimental YOLO monitoring mode (is the expected equipment class still
    detected inside the ROI? is a PERSON covering it?), and
  * automatic ROI suggestions (detect_tiled on the full-resolution frame).

It names object classes. That goes beyond the MobileNetV2 presence-only gate
(CLAUDE.md §8) — treat both uses as experimental pending adviser approval.
"""
import os

import cv2
import numpy as np

COCO = [
    "person", "bicycle", "car", "motorcycle", "airplane", "bus", "train", "truck", "boat",
    "traffic light", "fire hydrant", "stop sign", "parking meter", "bench", "bird", "cat",
    "dog", "horse", "sheep", "cow", "elephant", "bear", "zebra", "giraffe", "backpack",
    "umbrella", "handbag", "tie", "suitcase", "frisbee", "skis", "snowboard", "sports ball",
    "kite", "baseball bat", "baseball glove", "skateboard", "surfboard", "tennis racket",
    "bottle", "wine glass", "cup", "fork", "knife", "spoon", "bowl", "banana", "apple",
    "sandwich", "orange", "broccoli", "carrot", "hot dog", "pizza", "donut", "cake", "chair",
    "couch", "potted plant", "bed", "dining table", "toilet", "tv", "laptop", "mouse",
    "remote", "keyboard", "cell phone", "microwave", "oven", "toaster", "sink",
    "refrigerator", "book", "clock", "vase", "scissors", "teddy bear", "hair drier",
    "toothbrush",
]

# ROI label keyword -> COCO class(es) that count as "the item is here".
# Anything not listed (oscilloscope, power supply, CPU tower...) has no COCO
# class: YOLO cannot judge it and the caller must fall back to another pipeline.
LABEL_TO_CLASSES = {
    "keyboard": {"keyboard"},
    "mouse": {"mouse"},
    "monitor": {"tv", "laptop"},
    "screen": {"tv", "laptop"},
    "tv": {"tv"},
    "laptop": {"laptop"},
    "phone": {"cell phone"},
    "cellphone": {"cell phone"},
    "tumbler": {"bottle", "cup", "vase"},
    "bottle": {"bottle"},
    "cup": {"cup"},
    "mug": {"cup"},
    "bag": {"backpack", "handbag", "suitcase"},
    "backpack": {"backpack"},
    "book": {"book"},
    "chair": {"chair"},
    "umbrella": {"umbrella"},
}

# Classes worth proposing as ROIs for a computer lab, and belongings classes.
EQUIPMENT_CLASSES = {"keyboard", "mouse", "tv", "laptop", "cell phone"}
BELONGING_CLASSES = {"backpack", "handbag", "suitcase", "bottle", "cup", "cell phone",
                     "book", "umbrella", "laptop"}


def classes_for_label(label):
    """COCO classes an ROI label maps to, or an empty set if YOLO cannot judge it."""
    low = label.lower()
    for key, classes in LABEL_TO_CLASSES.items():
        if key in low:
            return classes
    return set()


class YoloDetector:
    INPUT = 640

    def __init__(self, model_path, conf=0.30, nms=0.45):
        self.ok = os.path.exists(model_path)
        self.net = cv2.dnn.readNetFromONNX(model_path) if self.ok else None
        self.conf, self.nms = conf, nms
        grids, strides = [], []
        for stride in (8, 16, 32):
            n = self.INPUT // stride
            xv, yv = np.meshgrid(np.arange(n), np.arange(n))
            grids.append(np.stack((xv, yv), 2).reshape(-1, 2))
            strides.append(np.full((n * n, 1), stride))
        self.grids = np.concatenate(grids, 0).astype(np.float32)
        self.strides = np.concatenate(strides, 0).astype(np.float32)

    def detect(self, bgr, conf=None):
        """Detections on one image: list of dicts {cls, score, box:(x1,y1,x2,y2)}."""
        if not self.ok or bgr is None or bgr.size == 0:
            return []
        h, w = bgr.shape[:2]
        r = min(self.INPUT / h, self.INPUT / w)
        padded = np.full((self.INPUT, self.INPUT, 3), 114, np.uint8)
        padded[: int(h * r), : int(w * r)] = cv2.resize(bgr, (int(w * r), int(h * r)),
                                                        interpolation=cv2.INTER_LINEAR)
        blob = padded.transpose(2, 0, 1)[np.newaxis].astype(np.float32)
        self.net.setInput(blob)
        dets = self.net.forward()[0]
        dets[:, :2] = (dets[:, :2] + self.grids) * self.strides
        dets[:, 2:4] = np.exp(dets[:, 2:4]) * self.strides
        scores = dets[:, 4:5] * dets[:, 5:]
        cls = scores.argmax(1)
        best = scores.max(1)
        thr = self.conf if conf is None else conf
        keep = best >= thr
        if not keep.any():
            return []
        d, cls, best = dets[keep], cls[keep], best[keep]
        xyxy = np.stack([d[:, 0] - d[:, 2] / 2, d[:, 1] - d[:, 3] / 2,
                         d[:, 0] + d[:, 2] / 2, d[:, 1] + d[:, 3] / 2], 1) / r
        idx = cv2.dnn.NMSBoxesBatched(
            [[float(a), float(b), float(c - a), float(e - b)] for a, b, c, e in xyxy],
            best.tolist(), cls.tolist(), thr, self.nms)
        out = []
        for i in np.array(idx).flatten():
            x1, y1, x2, y2 = xyxy[i]
            out.append({"cls": COCO[int(cls[i])], "score": float(best[i]),
                        "box": (max(0.0, x1), max(0.0, y1), min(w, x2), min(h, y2))})
        return out

    def detect_tiled(self, bgr, conf=None, grid=(2, 2), overlap=0.2):
        """Whole image + overlapping tiles, merged with class-aware NMS.

        A 2304x1296 frame squeezed into 640x640 shrinks a distant mouse to a few
        pixels; tiling keeps far more of the native resolution. Used for ROI
        suggestions, where a few hundred ms of extra time does not matter."""
        dets = list(self.detect(bgr, conf))
        h, w = bgr.shape[:2]
        gx, gy = grid
        tw, th = int(w / gx * (1 + overlap)), int(h / gy * (1 + overlap))
        for iy in range(gy):
            for ix in range(gx):
                x0 = min(int(ix * w / gx), w - tw) if gx > 1 else 0
                y0 = min(int(iy * h / gy), h - th) if gy > 1 else 0
                for d in self.detect(bgr[y0:y0 + th, x0:x0 + tw], conf):
                    x1, y1, x2, y2 = d["box"]
                    dets.append({**d, "box": (x1 + x0, y1 + y0, x2 + x0, y2 + y0)})
        if not dets:
            return []
        idx = cv2.dnn.NMSBoxesBatched(
            [[d["box"][0], d["box"][1], d["box"][2] - d["box"][0], d["box"][3] - d["box"][1]] for d in dets],
            [d["score"] for d in dets], [COCO.index(d["cls"]) for d in dets],
            self.conf if conf is None else conf, self.nms)
        return [dets[i] for i in np.array(idx).flatten()]


# ROI suggestion naming: COCO class -> the label stem the ROI editor shows.
SUGGEST_DEFAULT_CLASSES = ("keyboard", "mouse", "tv", "laptop")
SUGGEST_NAMES = {"tv": "monitor", "cell phone": "phone"}


def suggest_rois(detector, frame_bgr, classes=SUGGEST_DEFAULT_CLASSES, out_size=None, min_score=0.30):
    """THE ROI-suggestion step used by main.py (live) and tools/suggest_from_image.py.

    Runs tiled YOLO on `frame_bgr` (ideally the full-resolution camera frame) and
    returns (suggestions, raw_detections). Suggestion boxes are expressed in
    `out_size` = (w, h) coordinates — main.py passes the detection frame's size
    (the baseline/ROI editor's coordinate space); the image tool passes the
    image's own size. Nothing is saved anywhere.
    """
    rh, rw = frame_bgr.shape[:2]
    ow, oh = out_size if out_size else (rw, rh)
    sx, sy = ow / float(rw), oh / float(rh)
    dets = detector.detect_tiled(frame_bgr, conf=min_score)
    wanted = set(classes)
    # Monitors seen from a high CCTV angle are mostly classed "laptop" by COCO
    # models (measured on the MLH305 screenshot). When monitors are requested,
    # laptop detections count as monitor candidates; `class` keeps what the
    # model actually said, so the editor can show it.
    screens_as_monitor = "tv" in wanted and "laptop" not in wanted
    kept = []
    for d in sorted(dets, key=lambda d: -d["score"]):
        cls = d["cls"]
        if cls not in wanted and not (screens_as_monitor and cls == "laptop"):
            continue
        # Cross-class duplicates: the same object often comes back as both "tv"
        # and "laptop". Class-aware NMS keeps both, so drop any box that heavily
        # overlaps a higher-scoring suggestion of ANY class.
        if any(_box_iou_xyxy(d["box"], k["box"]) >= SUGGEST_DUP_IOU for k in kept):
            continue
        kept.append(d)
    counts, out = {}, []
    for d in kept:
        x1, y1, x2, y2 = d["box"]
        x, y = int(round(x1 * sx)), int(round(y1 * sy))
        w, h = int(round((x2 - x1) * sx)), int(round((y2 - y1) * sy))
        if w < 6 or h < 6:
            continue
        stem = "monitor" if (screens_as_monitor and d["cls"] == "laptop") else SUGGEST_NAMES.get(d["cls"], d["cls"])
        counts[stem] = counts.get(stem, 0) + 1
        out.append({"label": f"{stem}{counts[stem]}", "class": d["cls"], "score": round(d["score"], 3),
                    "tier": "tier1", "x": max(0, x), "y": max(0, y), "w": w, "h": h})
    return out, dets


SUGGEST_DUP_IOU = 0.55


def _box_iou_xyxy(a, b):
    ix = max(0.0, min(a[2], b[2]) - max(a[0], b[0]))
    iy = max(0.0, min(a[3], b[3]) - max(a[1], b[1]))
    inter = ix * iy
    union = (a[2] - a[0]) * (a[3] - a[1]) + (b[2] - b[0]) * (b[3] - b[1]) - inter
    return inter / union if union > 0 else 0.0


def overlap_frac(box, roi):
    """Fraction of the ROI (x,y,w,h) covered by box (x1,y1,x2,y2)."""
    x, y, w, h = roi
    ix = max(0.0, min(box[2], x + w) - max(box[0], x))
    iy = max(0.0, min(box[3], y + h) - max(box[1], y))
    return (ix * iy) / float(w * h) if w * h else 0.0


def iou(box, roi):
    x, y, w, h = roi
    ix = max(0.0, min(box[2], x + w) - max(box[0], x))
    iy = max(0.0, min(box[3], y + h) - max(box[1], y))
    inter = ix * iy
    union = (box[2] - box[0]) * (box[3] - box[1]) + w * h - inter
    return inter / union if union > 0 else 0.0
