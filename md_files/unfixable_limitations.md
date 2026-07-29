# S.P.O.T.-IT — Diagnostic Report: Unfixable Camera & Detection Pipeline Limitations

This document registers persistent, structurally unfixable limitations inherent to the hardware, environment, and physical setup of the DLSU-D CEAT Laboratory Rooms. These notes are preserved as a direct handoff for future sessions and AI coding agents.

---

## 1. The Wood Grain Background False-Match Problem
*   **Symptom:** Small items (like the computer mouse) or darker items (like the laptop) stay **GREEN (`OK`)** even when picked up and completely removed from the table.
*   **Root Cause:**
    *   **Texture Similarity:** The baseline item crop contains the object in the center, but is surrounded by the brown, textured wooden desk background.
    *   **Correlation False Positives:** When the item is removed, the template matcher searches the area. Because the bare desk has the exact same wood grain texture and color as the desk area stored in the baseline crop, the correlation score matches at $\approx 0.65$.
    *   **Logic Failure:** Since $0.65$ exceeds the presence threshold ($0.52$), the classical template matcher decides the object is still present, keeping the box green.
*   **Why it is Unfixable in Software:** 
    *   Classical template matching (`cv2.matchTemplate`) is mathematical cross-correlation. It cannot differentiate between the texture of the table under the item and the texture of the table when empty.
    *   Increasing the threshold to $>0.70$ causes the box to turn red under normal shadows or minor ambient light changes when the item *is* present.

---

## 2. CCTV Resolution & Object Size Constraints (The DNN Failure)
*   **Symptom:** MobileNetV2 DNN classification fails to verify presence (turning boxes RED even when items are present).
*   **Root Cause:**
    *   **Pixel Density:** The CCTV camera is mounted far away on the wall/ceiling. The cropped ROI of a mouse is very small (approx. $80 \times 60$ pixels).
    *   **Aggressive Downsampling:** MobileNetV2 requires a $224 \times 224$ input. Resizing a tiny $80 \times 60$ pixel crop up to $224 \times 224$ produces a blurry, pixelated cluster of colors.
    *   **Inference Failure:** The neural network cannot identify features on such low-resolution crops, frequently misclassifying the mouse as background noise or a "container ship" with low confidence.
*   **Why it is Unfixable in Software:**
    *   You cannot generate high-frequency shape features from a blurry, low-resolution crop. No change to model thresholds can resolve the lack of physical pixels in the camera feed.

---

## 3. Human Occlusion vs. Theft Ambiguity (The Hand/Arm Block)
*   **Symptom:** When a user types on the laptop or holds the mouse, the boxes turn RED or flicker.
*   **Root Cause:**
    *   **Visual Blockage:** A user's hands, arms, or body physically block the camera's line-of-sight to the item.
    *   **Logic Mismatch:** The template matcher sees skin/sleeves instead of the laptop/mouse. It correctly identifies that the laptop/mouse is not visible and marks it RED.
*   **Why it is Unfixable in Software:**
    *   A single monocular CCTV camera cannot see through solid objects. If a hand is covering the mouse, the camera cannot mathematically verify if the mouse is still underneath the hand or if it has been taken.
    *   Attempting to suppress the warning using local frame differencing (`local_motion > 0.05`) works during active typing, but fails if the user rests their hand still on the item.

---

## 4. Recommendations for Chapter 5 (Future Work & Limitations)
When writing your thesis Chapter 5, document these limitations as valuable scientific findings:
1.  **Hardware Limitation:** Recommend upgrading the CCTV system to a $4\text{K}$ high-density camera to increase the visual pixels of distant items.
2.  **Environment Limitation:** Recommend using high-contrast solid desk surfaces (e.g., solid white or blue desks) rather than textured wood grain to prevent template match cross-correlation errors on the empty table surface.
3.  **Sensor Redundancy:** Recommend integrating physical IoT weight sensors or RFID tags underneath high-value laboratory equipment to supplement visual CCTV tracking when line-of-sight is blocked by human users.
