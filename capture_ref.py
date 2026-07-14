import cv2

# ── CONFIG ───────────────────────────────────────────────────────────────
RTSP_URL      = 'rtsp://SpotItCamera:spotittapo232@192.168.18.11:554/stream1'
ROTATE_CAMERA = False  # True for portrait-oriented iPhone setup, False for Tapo/landscape CCTV
STREAM_SCALE  = 0.50
# ─────────────────────────────────────────────────────────────────────────

print(f"[SPOT-IT] Connecting to stream: {RTSP_URL}")
cap = cv2.VideoCapture(RTSP_URL)
ret, frame = cap.read()

def rescaleFrame(frame, scale=STREAM_SCALE):
    width = int(frame.shape[1] * scale)
    height = int(frame.shape[0] * scale)
    dimensions = (width, height)
    return cv2.resize(frame, dimensions, interpolation=cv2.INTER_AREA)

if ret:
    if ROTATE_CAMERA:
        frame = cv2.rotate(frame, cv2.ROTATE_90_COUNTERCLOCKWISE)
    resized_Frame = rescaleFrame(frame)
    cv2.imwrite('photos/ref_image.jpg', resized_Frame)
    print("Reference image saved to photos/ref_image.jpg")
    cv2.imshow("Reference — press any key to confirm", resized_Frame)
    cv2.waitKey(0)
else:
    print("Failed. Check stream. Make sure the CCTV is powered, connected to the network, and the IP is correct.")

cap.release()
cv2.destroyAllWindows()

