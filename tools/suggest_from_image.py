"""Standalone ROI-suggestion test on still images (no camera, no database, no ROI writes).

Runs the SAME suggestion step the live detector uses — yolo_detector.suggest_rois()
with the real models/yolox_s.onnx weights — on image files, and writes, for each
image, into --out only:
    <name>_suggestions.jpg   annotated: suggestions (orange, label class score) and,
                             thin grey, every other YOLO detection for transparency
    <name>_suggestions.json  suggestion boxes in THIS IMAGE's pixel coordinates

Nothing is registered: to use a suggestion you still review it in the ROI editor.

Usage:
    python tools/suggest_from_image.py IMAGE [IMAGE ...] --out DIR
        [--classes keyboard,mouse,tv,laptop] [--min-score 0.30]
        [--rois rois_MLH305.json --rois-frame 1152x648]   (optional, read-only comparison)

Coordinates: boxes are in the pixel space of the image you pass in. A 640x360
screenshot is NOT the detector's frame — results on it understate what the live
2304x1296 stream can show. For a real test use a full-resolution snapshot.
"""
import argparse
import json
import os
import sys
import time

import cv2

REPO = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, REPO)
from yolo_detector import YoloDetector, iou, suggest_rois, SUGGEST_DEFAULT_CLASSES  # noqa: E402


def main():
    ap = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    ap.add_argument("images", nargs="+")
    ap.add_argument("--out", required=True, help="output folder (created if missing)")
    ap.add_argument("--classes", default=",".join(SUGGEST_DEFAULT_CLASSES))
    ap.add_argument("--min-score", type=float, default=0.30)
    ap.add_argument("--rois", action="append", default=[],
                    help="registered ROI json to compare against (one per image, same order; read-only)")
    ap.add_argument("--rois-frame", default="1152x648", help="pixel size the ROI file was drawn on")
    a = ap.parse_args()

    model = os.path.join(REPO, "models", "yolox_s.onnx")
    det = YoloDetector(model, conf=a.min_score)
    if not det.ok:
        sys.exit(f"model not found: {model}")
    classes = [c.strip() for c in a.classes.split(",") if c.strip()]
    os.makedirs(a.out, exist_ok=True)
    fw, fh = (int(v) for v in a.rois_frame.lower().split("x"))

    for i, path in enumerate(a.images):
        img = cv2.imread(path)
        if img is None:
            print(f"!! cannot read {path}")
            continue
        h, w = img.shape[:2]
        t0 = time.time()
        sugg, raw = suggest_rois(det, img, classes=classes, out_size=(w, h), min_score=a.min_score)
        ms = int((time.time() - t0) * 1000)
        name = os.path.splitext(os.path.basename(path))[0]

        vis = img.copy()
        thick = max(1, round(w / 640))
        fs = 0.35 * max(1.0, w / 640)
        sugg_boxes = {(s["x"], s["y"], s["w"], s["h"]) for s in sugg}
        others = []
        for d in raw:
            x1, y1, x2, y2 = (int(round(v)) for v in d["box"])
            if (x1, y1, x2 - x1, y2 - y1) in sugg_boxes and d["cls"] in classes:
                continue
            others.append({"class": d["cls"], "score": round(d["score"], 3), "x": x1, "y": y1, "w": x2 - x1, "h": y2 - y1})
            cv2.rectangle(vis, (x1, y1), (x2, y2), (150, 150, 150), 1)
            cv2.putText(vis, f"{d['cls']} {d['score']:.2f}", (x1 + 2, y2 - 3), cv2.FONT_HERSHEY_SIMPLEX, fs * 0.85, (170, 170, 170), 1)
        for s in sugg:
            x, y, bw, bh = s["x"], s["y"], s["w"], s["h"]
            cv2.rectangle(vis, (x, y), (x + bw, y + bh), (40, 160, 255), thick + 1)
            txt = f"{s['label']}: {s['class']} {s['score']:.2f}"
            (tw, th), _ = cv2.getTextSize(txt, cv2.FONT_HERSHEY_SIMPLEX, fs, 1)
            cv2.rectangle(vis, (x, max(0, y - th - 4)), (x + tw + 4, y), (40, 160, 255), -1)
            cv2.putText(vis, txt, (x + 2, y - 3), cv2.FONT_HERSHEY_SIMPLEX, fs, (0, 0, 0), 1)
        banner = f"{w}x{h} input | YOLOX-S tiled | {len(sugg)} suggestion(s) | NOT registered"
        cv2.rectangle(vis, (0, h - int(22 * fs / 0.35)), (w, h), (0, 0, 0), -1)
        cv2.putText(vis, banner, (6, h - 6), cv2.FONT_HERSHEY_SIMPLEX, fs * 1.1, (255, 255, 255), 1)

        compare = None
        if i < len(a.rois):
            sx, sy = w / float(fw), h / float(fh)
            compare = []
            for r in json.load(open(a.rois[i], encoding="utf-8")):
                roi = (r["x"] * sx, r["y"] * sy, r["w"] * sx, r["h"] * sy)
                best = max(((iou((s["x"], s["y"], s["x"] + s["w"], s["y"] + s["h"]), roi), s) for s in sugg),
                           key=lambda t: t[0], default=(0.0, None))
                compare.append({"registered": r["label"], "roi_in_this_image": [round(v) for v in roi],
                                "best_iou": round(best[0], 2), "best_suggestion": best[1]["label"] if best[1] else None})
                rx, ry, rw_, rh_ = (int(round(v)) for v in roi)
                cv2.rectangle(vis, (rx, ry), (rx + rw_, ry + rh_), (0, 0, 255), 1)

        cv2.imwrite(os.path.join(a.out, f"{name}_suggestions.jpg"), vis)
        json.dump({
            "source_image": os.path.abspath(path), "image_w": w, "image_h": h,
            "coordinate_system": f"pixels of this {w}x{h} input image",
            "note": "Suggestions only — nothing was registered. Screenshot inputs are not the detector's frame.",
            "model": "models/yolox_s.onnx (YOLOX-S, COCO-80)", "classes": classes, "min_score": a.min_score,
            "elapsed_ms": ms, "suggestions": sugg, "other_detections": others,
            "registered_roi_comparison": compare,
        }, open(os.path.join(a.out, f"{name}_suggestions.json"), "w", encoding="utf-8"), indent=1)
        print(f"== {path} ({w}x{h}): {len(sugg)} suggestion(s) in {ms} ms -> {a.out}")
        for s in sugg:
            print(f"   {s['label']:12s} {s['class']:9s} {s['score']:.2f}  x={s['x']} y={s['y']} w={s['w']} h={s['h']}")
        if others:
            c = {}
            for o in others:
                c[o["class"]] = c.get(o["class"], 0) + 1
            print(f"   other detections (not suggested): {c}")
        for c in compare or []:
            print(f"   registered {c['registered']:18s} best IoU {c['best_iou']:.2f} ({c['best_suggestion']})")


if __name__ == "__main__":
    main()
