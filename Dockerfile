# S.P.O.T.-IT — Production Containerization Dockerfile
# Packages Python, OpenCV, ONNX Runtime, and MobileNetV2 for headless 24/7 background execution.

FROM python:3.9-slim

# Prevent Python from writing .pyc files and enable unbuffered logging
ENV PYTHONDONTWRITEBYTECODE=1
ENV PYTHONUNBUFFERED=1

# Install system dependencies for OpenCV
RUN apt-get update && apt-get install -y --no-install-recommends \
    build-essential \
    libgl1-mesa-glx \
    libglib2.0-0 \
    libgomp1 \
    && rm -rf /var/lib/apt/lists/*

# Set workspace directory
WORKDIR /app

# Copy dependency requirements
COPY requirements.txt .

# Install python packages
RUN pip install --no-cache-dir -r requirements.txt

# Copy source code and pre-trained models
COPY main.py .
COPY models/ ./models/
COPY photos/ ./photos/

# Trigger head-less execution
ENV SHOW_GUI=False

# Run the dynamic room monitoring engine
CMD ["python", "main.py"]
