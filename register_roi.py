import cv2
import json
import os

TARGET_SIZE = (640, 480)
ROI_FILE = 'rois.json'

roi_list = []               # will hold all the boxes successfully drawn
drawing = False         # true when mouse was held down
start_x, start_y = -1, -1   # starting point where mouse was first clicked
temp_frame = None   # hold temporary copies of images while drawing

# function that listens to mouse inside the image window
def draw_roi(event, x, y, flags, param):
    global drawing, start_x, start_y, temp_frame

    if event == cv2.EVENT_LBUTTONDOWN:  # left mouse
        drawing = True
        start_x, start_y = x, y         # cursor's current position

    elif event == cv2.EVENT_MOUSEMOVE:  # mouse actively moving
        if drawing:
            temp_frame = ref_display.copy()
            # Draw all saved ROIs
            for roi in roi_list:    # loops through finished boxes
                cv2.rectangle(temp_frame,
                    (roi['x'], roi['y']),
                    (roi['x'] + roi['w'], roi['y'] + roi['h']),
                    (255, 0, 0), 2)                         
                cv2.putText(temp_frame, roi['label'],                   #  blue text label of object 
                    (roi['x'], roi['y'] - 5),
                    cv2.FONT_HERSHEY_SIMPLEX, 0.5, (255, 0, 0), 1)
            # Draw current rectangle being drawn
            cv2.rectangle(temp_frame, (start_x, start_y), (x, y), (0, 255, 255), 2)
            cv2.imshow("ROI Registration", temp_frame)

    elif event == cv2.EVENT_LBUTTONUP: #trigger the moment finger was lifted
        drawing = False
        x2, y2 = x, y           # final release coordinate
        w = abs(x2 - start_x)   # end points - start points
        h = abs(y2 - start_y)
        x_roi = min(start_x, x2)    # find the topmost, leftmost pixel corner 
        y_roi = min(start_y, y2)

        if w > 10 and h > 10:   # ensures box is greater than 10x10 pixels
            # Ask for label in terminal
            label = input(f"Enter label for this ROI (e.g. 'mouse', 'phone'): ")
            roi_list.append({
                'label': label,
                'x': x_roi,
                'y': y_roi,
                'w': w,
                'h': h
            })
            print(f"ROI saved: {label} at ({x_roi},{y_roi}) size {w}x{h}")

# Load reference image
ref = cv2.imread('photos/ref_image.jpg')
if ref is None:
    print("ERROR: Cannot find photos/ref_image.jpg")
    exit()

ref = cv2.resize(ref, TARGET_SIZE)
# duplicate back-up versions of the image canvas so drawing on one layer doesn't break the raw image underneath
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
cv2.setMouseCallback("ROI Registration", draw_roi)  # connects mouse function directly to that active window

while True:               # true until save or quit was pressed
    key = cv2.waitKey(1) & 0xFF     # listen for 1 mS for any keypress 
    
    if key == ord('s'):     # save
        with open(ROI_FILE, 'w') as f:  # opens rois.json in write mode
            json.dump(roi_list, f, indent=2)    # converts drawn coordinates into aligned text entries inside that file
        print(f"\nSaved {len(roi_list)} ROIs to {ROI_FILE}")
        for r in roi_list:
            print(f"  - {r['label']}: ({r['x']},{r['y']}) {r['w']}x{r['h']}")
        break

    elif key == ord('z'):   # undo drawn boxes
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

    elif key == ord('q'):               # skips saving & terminates loop
        print("Quit without saving")
        break

cv2.destroyAllWindows()