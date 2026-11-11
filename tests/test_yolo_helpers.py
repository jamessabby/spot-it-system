"""Tests for yolo_detector.py (read-only: reads the DESK baseline, writes nothing).

    python -I tests/test_yolo_helpers.py
"""
import os
import sys

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.dirname(HERE)
sys.path.insert(0, REPO)
from yolo_detector import YoloDetector, classes_for_label, overlap_frac, iou  # noqa: E402

passed = failed = 0


def check(name, actual, expected):
    global passed, failed
    if actual == expected:
        passed += 1
        print(f"  PASS  {name}")
    else:
        failed += 1
        print(f"  FAIL  {name}\n        expected {expected!r}, got {actual!r}")


print("== Label -> COCO class mapping")
check("keyboard2 -> keyboard", classes_for_label("keyboard2"), {"keyboard"})
check("mouse_1 -> mouse", classes_for_label("mouse_1"), {"mouse"})
check("monitor5 -> tv/laptop", classes_for_label("monitor5"), {"tv", "laptop"})
check("oscilloscope_mid -> nothing (manual/gatekeeper fallback)", classes_for_label("oscilloscope_mid"), set())
check("powersupply_near -> nothing", classes_for_label("powersupply_near"), set())

print("== Overlap maths")
check("box fully covering ROI -> 1.0", overlap_frac((0, 0, 100, 100), (10, 10, 20, 20)), 1.0)
check("box covering half the ROI -> 0.5", overlap_frac((0, 0, 20, 100), (10, 10, 20, 20)), 0.5)
check("disjoint -> 0", overlap_frac((0, 0, 5, 5), (10, 10, 20, 20)), 0.0)
check("identical box/ROI IoU -> 1", round(iou((10, 10, 30, 30), (10, 10, 20, 20)), 6), 1.0)

print("== Real detection on the DESK baseline (model required)")
model = os.path.join(REPO, "models", "yolox_s.onnx")
ref = os.path.join(REPO, "photos", "ref_image.jpg")
if os.path.exists(model) and os.path.exists(ref):
    import cv2
    det = YoloDetector(model, conf=0.30)
    found = {d["cls"] for d in det.detect_tiled(cv2.imread(ref))}
    check("phone found on DESK baseline", "cell phone" in found, True)
    check("tumbler found as bottle", "bottle" in found, True)
else:
    print("  SKIP  model or baseline missing")

print(f"\n{passed} passed, {failed} failed")
sys.exit(1 if failed else 0)
