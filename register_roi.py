import cv2
import json
import os

ROI_FILE = 'rois.json'

roi_list = []
drawing = False
start_x, start_y = -1, -1
temp_frame = None

def draw_roi(event, x, y, flags, param):
    global drawing, start_x, start_y, temp_frame

    if event == cv2.EVENT_LBUTTONDOWN:
        drawing = True
        start_x, start_y = x, y

    elif event == cv2.EVENT_MOUSEMOVE:
        if drawing:
            temp_frame = ref_display.copy()
            for roi in roi_list:
                cv2.rectangle(temp_frame,
                    (roi['x'], roi['y']),
                    (roi['x'] + roi['w'], roi['y'] + roi['h']),
                    (255, 0, 0), 2)
                cv2.putText(temp_frame, roi['label'],
                    (roi['x'], roi['y'] - 5),
                    cv2.FONT_HERSHEY_SIMPLEX, 0.5, (255, 0, 0), 1)
            cv2.rectangle(temp_frame, (start_x, start_y), (x, y), (0, 255, 255), 2)
            cv2.imshow("ROI Registration", temp_frame)

    elif event == cv2.EVENT_LBUTTONUP:
        drawing = False
        x2, y2 = x, y
        w = abs(x2 - start_x)
        h = abs(y2 - start_y)
        x_roi = min(start_x, x2)
        y_roi = min(start_y, y2)

        if w > 10 and h > 10:
            label = input(f"Enter label for this ROI (e.g. 'mouse', 'phone'): ")
            roi_list.append({
                'label': label,
                'x': x_roi,
                'y': y_roi,
                'w': w,
                'h': h
            })
            print(f"ROI saved: {label} at ({x_roi},{y_roi}) size {w}x{h}")

# ── Load reference image AT ITS NATIVE SIZE ──────────────────────────────
# IMPORTANT: do NOT resize here. main.py uses ref_image.jpg's native
# dimensions as TARGET_SIZE, so ROI coordinates must be drawn at that
# same native size or boxes will misalign.
ref = cv2.imread('photos/ref_image.jpg')
if ref is None:
    print("ERROR: Cannot find photos/ref_image.jpg")
    exit()

print(f"[INFO] Reference image native size: {ref.shape[1]}x{ref.shape[0]}")

ref_display = ref.copy()
temp_frame = ref_display.copy()

print("=== ROI REGISTRATION TOOL ===")
print("Draw boxes around each item you want to monitor")
print("Controls:")
print("  Left click + drag = draw ROI box")
print("  S = save all ROIs and exit")
print("  Z = undo last ROI")
print("  Q = quit without saving")

cv2.imshow("ROI Registration", ref_display)
cv2.setMouseCallback("ROI Registration", draw_roi)

while True:
    key = cv2.waitKey(1) & 0xFF

    if key == ord('s'):
        with open(ROI_FILE, 'w') as f:
            json.dump(roi_list, f, indent=2)
        print(f"\nSaved {len(roi_list)} ROIs to {ROI_FILE}")
        for r in roi_list:
            print(f"  - {r['label']}: ({r['x']},{r['y']}) {r['w']}x{r['h']}")
        break

    elif key == ord('z'):
        if roi_list:
            removed = roi_list.pop()
            print(f"Removed ROI: {removed['label']}")
            temp_frame = ref_display.copy()
            for roi in roi_list:
                cv2.rectangle(temp_frame,
                    (roi['x'], roi['y']),
                    (roi['x'] + roi['w'], roi['y'] + roi['h']),
                    (255, 0, 0), 2)
                cv2.putText(temp_frame, roi['label'],
                    (roi['x'], roi['y'] - 5),
                    cv2.FONT_HERSHEY_SIMPLEX, 0.5, (255, 0, 0), 1)
            cv2.imshow("ROI Registration", temp_frame)

    elif key == ord('q'):
        print("Quit without saving")
        break

cv2.destroyAllWindows()