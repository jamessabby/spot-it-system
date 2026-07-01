import cv2

cap = cv2.VideoCapture('rtsp://192.168.0.89/stream')
ret, frame = cap.read()

def rescaleFrame(frame, scale=0.50):
    width = int(frame.shape[1] * scale)
    height = int(frame.shape[0] * scale)
    dimensions = (width, height)
    
    return cv2.resize(frame, dimensions, interpolation = cv2.INTER_AREA)

if ret:
    frame = cv2.rotate(frame, cv2.ROTATE_90_COUNTERCLOCKWISE)
    resized_Frame = rescaleFrame(frame)
    cv2.imwrite('photos/ref_image.jpg', resized_Frame)
    print("Reference image saved.")
    cv2.imshow("Reference — press any key to confirm", resized_Frame)
    cv2.waitKey(0)
else:
    print("Failed. Check stream.")

cap.release()
cv2.destroyAllWindows()

