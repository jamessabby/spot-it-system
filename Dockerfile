# S.P.O.T.-IT - Detector container
#
# WHEN TO USE THIS, AND WHEN NOT TO
# This packages main.py only - the detector, not the web app. It does nothing
# for web traffic or scaling; the PHP site is served by Hostinger.
#
# On a WINDOWS mini-PC (the current deployment), prefer deploy/install-service.ps1.
# Docker Desktop on Windows needs a logged-in user session and inserts a virtual
# network between the container and the camera, both of which are liabilities on
# an unattended machine.
#
# Use this image when the detector host is Linux (a NUC, a Raspberry Pi, or a
# second lab box), where restart policies and host networking are native.
#
# Build:  docker compose build
# Run:    docker compose up -d
# Logs:   docker compose logs -f

FROM python:3.11-slim

# Unbuffered output matters for an unattended container: without it stdout sits
# in a buffer and `docker logs` stays empty for minutes while you are trying to
# work out why nothing is happening.
ENV PYTHONDONTWRITEBYTECODE=1 \
    PYTHONUNBUFFERED=1

# OpenCV runtime dependencies. libGL and libglib are needed even by the headless
# wheel; ffmpeg supplies the RTSP/H.264 decoding for the Tapo stream.
RUN apt-get update && apt-get install -y --no-install-recommends \
        libgl1 \
        libglib2.0-0 \
        libgomp1 \
        ffmpeg \
    && rm -rf /var/lib/apt/lists/*

WORKDIR /app

# Dependencies first so a code change does not invalidate the pip layer.
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt

# Source and the ONNX model. Deliberately NOT photos/ or rois*.json: baking
# those in would ship a baseline captured on some other day, in some other
# lighting, and the detector would compare every live frame against it. They
# arrive at runtime instead - from the server in remote mode, or from a mounted
# volume in local mode.
COPY main.py .
COPY models/ ./models/

# Writable mount points, created so the container still runs with no volumes.
RUN mkdir -p /app/photos /app/uploads/snapshots /app/logs

# Headless by default - a container has no display, so cv2.imshow would throw.
# SPOTIT_GUI is intentionally left unset.
ENV SPOTIT_DEPLOY=remote \
    SNAPSHOT_DIR=/app/uploads/snapshots \
    ROOM_ID=DESK

# ROOM_ID is passed as the CLI argument main.py expects. Without it every
# container would silently fall back to the DESK sandbox.
CMD ["sh", "-c", "python main.py \"$ROOM_ID\""]
