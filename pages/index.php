<?php
/**
 * S.P.O.T.-IT — Landing / Index Page v19
 * Official DLSU-D Light Theme:
 * - Official DLSU-D Emerald Header (#0b4e28)
 * - Crisp Light Background (#f7faf7) matching DLSU-D Official Portal (sms.dlsud.edu.ph)
 * - 1-to-1 WebGL 3D TP-Link Tapo TC65 Camera:
 *     - Hero (Section 1): Center Stage below title (Y=-1.10, scale=0.38)
 *     - Features (Section 2) & How It Works (Section 3): RIGHT Margin (X=+4.00, scale=0.38, facing left)
 *     - About (Section 4) & Footer (Section 5): LEFT Margin (X=-4.00, scale=0.38, facing right)
 */
$already_logged_in = !empty($_SESSION['user_id'] ?? null);
$cb = '?v=' . time();
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>S.P.O.T.-IT — IoT Lab Monitoring System — DLSU-D CEAT</title>
  <meta name="description" content="S.P.O.T.-IT uses CCTV-based computer vision to continuously monitor laboratory equipment in CEAT rooms."/>
  <link rel="preconnect" href="https://fonts.googleapis.com"/>
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin/>
  <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet"/>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css"/>
  <link rel="stylesheet" href="../assets/css/index.css<?= $cb ?>"/>
  <!-- Three.js for 3D WebGL Camera Rendering -->
  <script src="https://cdnjs.cloudflare.com/ajax/libs/three.js/r128/three.min.js"></script>
  <style>
    html,body{margin:0;padding:0;background:#f7faf7!important;color:#0f2918;font-family:'Plus Jakarta Sans',sans-serif;overflow-x:hidden}
  </style>
</head>
<body>
<div class="lp">

<!-- FIXED FULLSCREEN CANVAS FOR 60FPS CAMERA GLIDING -->
<div class="lp-camera-overlay">
  <canvas id="cctvCanvas"></canvas>
</div>

<!-- FULL-WIDTH FIXED TOP NAV (OFFICIAL DLSU-D EMERALD) -->
<nav class="lp-nav">
  <a href="#" class="lp-nav-brand">
    <div class="lp-nav-logo">S</div>
    <div><div class="lp-nav-name">S.P.O.T.-IT</div><div class="lp-nav-sub">DLSU-D CEAT</div></div>
  </a>
  <div class="lp-nav-links">
    <a href="#features">Features</a>
    <a href="#how">How It Works</a>
    <a href="#about">About</a>
  </div>
  <div class="lp-nav-actions">
    <?php if ($already_logged_in): ?>
      <a href="dashboard-admin.php" class="lp-btn-outline">Dashboard</a>
    <?php else: ?>
      <a href="login.php" class="lp-btn-outline">Sign In</a>
      <a href="signup.php" class="lp-btn-solid">Register</a>
    <?php endif; ?>
  </div>
  <button class="lp-hamburger" onclick="document.getElementById('mobNav').classList.toggle('open')"><i class="fa-solid fa-bars"></i></button>
</nav>
<div class="lp-mobile-nav" id="mobNav">
  <a href="#features">Features</a><a href="#how">How It Works</a><a href="#about">About</a>
  <a href="login.php" style="color:#86efac;font-weight:600">Sign In</a>
  <a href="signup.php" style="background:#ffffff;color:#0b4e28;text-align:center;border-radius:10px;font-weight:800">Register</a>
</div>

<!-- ══════════════════ HERO SECTION ══════════════════ -->
<section class="lp-hero">
  <div class="lp-glow lp-glow-1"></div>
  <div class="lp-glow lp-glow-2"></div>
  <div class="lp-glow lp-glow-3"></div>

  <div class="lp-hero-eyebrow">
    <span class="lp-rec"></span> IoT-Integrated Surveillance · DLSU-D CEAT
  </div>

  <!-- HIGH-CONTRAST DARK EMERALD HEADLINE TEXT -->
  <h1 class="lp-hero-title">SPOT-IT</h1>
  <h2 class="lp-hero-title2">SURVEILLANCE</h2>

  <!-- Hero Stage Spacer (Leaves clean gap below headline so camera is lowered down without touching title) -->
  <div class="lp-hero-stage-space"></div>

  <!-- SUBTITLE & ACTION BUTTONS -->
  <div class="lp-hero-bottom">
    <p class="lp-hero-desc">
      CCTV-based computer vision that continuously monitors laboratory equipment —
      detecting missing items and alerting personnel in real time.
    </p>
    <div class="lp-hero-ctas">
      <a href="login.php" class="lp-cta-primary"><i class="fa-solid fa-arrow-right-to-bracket"></i> Access Dashboard</a>
      <a href="#how" class="lp-cta-secondary"><i class="fa-solid fa-play"></i> See How It Works</a>
    </div>
  </div>

  <!-- SPEC CARDS -->
  <div class="lp-specs">
    <div class="lp-spec"><div class="lp-spec-icon"><i class="fa-solid fa-video"></i></div><h4>2K Ultra-HD</h4><p>2304×1296 RTSP · F1.6 lens</p></div>
    <div class="lp-spec"><div class="lp-spec-icon"><i class="fa-solid fa-bolt"></i></div><h4>&lt;2s Alerts</h4><p>Instant detection to dashboard</p></div>
    <div class="lp-spec"><div class="lp-spec-icon"><i class="fa-solid fa-brain"></i></div><h4>AI Verified</h4><p>MobileNetV2 false-positive gate</p></div>
    <div class="lp-spec"><div class="lp-spec-icon"><i class="fa-solid fa-id-card"></i></div><h4>Smart Claims</h4><p>Student ID + webcam evidence</p></div>
  </div>
</section>

<!-- ══════════════════ FEATURES SECTION ══════════════════ -->
<section class="lp-section lp-section-features" id="features">
  <div class="lp-container">
    <div class="lp-badge reveal">Core Capabilities</div>
    <h2 class="lp-heading reveal">Intelligent Campus<br/>Surveillance at a Glance</h2>
    <p class="lp-subtext reveal">Four integrated modules keep every laboratory room accountable.</p>
    <div class="lp-features-grid">
      <div class="lp-fcard reveal">
        <div class="lp-fnum">01</div>
        <div class="lp-ficon"><i class="fa-solid fa-camera"></i></div>
        <h3>Equipment Deviation Detection</h3>
        <p>Python + OpenCV compares each frame against a baseline count. Disappearances from ROI zones are flagged instantly.</p>
        <div class="lp-ftag">Background Subtraction · Contour Count</div>
      </div>
      <div class="lp-fcard reveal">
        <div class="lp-fnum">02</div>
        <div class="lp-ficon" style="background:#fee2e2;color:#dc2626"><i class="fa-solid fa-bell"></i></div>
        <h3>Two-Stage Alert System</h3>
        <p><strong>30 min</strong> → Potentially Lost. <strong>1 hour</strong> → Confirmed Missing with auto-escalation.</p>
        <div class="lp-ftag" style="color:#991b1b;background:#fee2e2">30 min Potential · 60 min Confirmed</div>
      </div>
      <div class="lp-fcard reveal">
        <div class="lp-fnum">03</div>
        <div class="lp-ficon" style="background:#dbeafe;color:#2563eb"><i class="fa-solid fa-gauge-high"></i></div>
        <h3>Centralized Dashboard</h3>
        <p>Real-time room status, incident logs, alert histories — all from any browser with role-based access.</p>
        <div class="lp-ftag" style="color:#1e40af;background:#dbeafe">Admin · Student Roles</div>
      </div>
      <div class="lp-fcard reveal">
        <div class="lp-fnum">04</div>
        <div class="lp-ficon" style="background:#fef9c3;color:#ca8a04"><i class="fa-solid fa-hand-holding"></i></div>
        <h3>Smart Claiming Station</h3>
        <p>University ID verification, webcam photo, and auto-stored chain of custody records.</p>
        <div class="lp-ftag" style="color:#854d0e;background:#fef9c3">ID Verification · Webcam Capture</div>
      </div>
    </div>
  </div>
</section>

<!-- ══════════════════ HOW IT WORKS SECTION ══════════════════ -->
<section class="lp-section lp-section-how" id="how">
  <div class="lp-container">
    <div class="lp-badge reveal">System Pipeline</div>
    <h2 class="lp-heading reveal">From Camera Feed<br/>to Resolution</h2>
    <div class="lp-steps">
      <div class="lp-step reveal"><div class="lp-stepnum">1</div><div class="lp-stepicon"><i class="fa-solid fa-video"></i></div><h4>Video Capture</h4><p>Tapo TC65 streams 2K footage via RTSP to Python engine.</p></div>
      <div class="lp-step-arrow"><i class="fa-solid fa-arrow-right"></i></div>
      <div class="lp-step reveal"><div class="lp-stepnum">2</div><div class="lp-stepicon" style="background:#fee2e2;color:#dc2626"><i class="fa-solid fa-magnifying-glass"></i></div><h4>Detection</h4><p>OpenCV compares frames against reference within ROI zones.</p></div>
      <div class="lp-step-arrow"><i class="fa-solid fa-arrow-right"></i></div>
      <div class="lp-step reveal"><div class="lp-stepnum">3</div><div class="lp-stepicon" style="background:#fef9c3;color:#ca8a04"><i class="fa-solid fa-bolt"></i></div><h4>Alert Dispatch</h4><p>Events POST to PHP/MySQL. Staff notified with snapshot.</p></div>
      <div class="lp-step-arrow"><i class="fa-solid fa-arrow-right"></i></div>
      <div class="lp-step reveal"><div class="lp-stepnum">4</div><div class="lp-stepicon" style="background:#e8f5e9;color:#0b4e28"><i class="fa-solid fa-circle-check"></i></div><h4>Resolution</h4><p>Owner claims via station. Staff verify. Custody documented.</p></div>
    </div>
  </div>
</section>

<!-- ══════════════════ ABOUT SECTION (LIGHT DLSU-D CARDS) ══════════════════ -->
<section class="lp-section lp-section-about" id="about">
  <div class="lp-container">
    <div class="lp-badge reveal">About the Project</div>
    <h2 class="lp-heading reveal" style="font-size:2rem">Built for DLSU-D CEAT.<br/>Designed for scale.</h2>
    <p class="lp-subtext reveal">Replacing manual inventory checks with continuous, intelligent computer vision surveillance.</p>
    
    <div class="lp-about-grid reveal">
      <!-- LEFT CARD: Thesis & Engineering Team -->
      <div class="lp-about-card">
        <div style="font-size:.65rem;font-weight:700;letter-spacing:.18em;text-transform:uppercase;color:#0b4e28;margin-bottom:.6rem">Thesis Project & Team</div>
        <h3 style="font-family:'Outfit',sans-serif;font-size:1.15rem;font-weight:800;color:#0b4e28;margin-bottom:.6rem">Computer Engineering Thesis</h3>
        <p style="font-size:.82rem;color:#4a6b54;line-height:1.65;font-weight:500;margin-bottom:1.2rem">Developed for De La Salle University – Dasmariñas. Combines classical image processing with MobileNetV2 AI for real-time laboratory room accountability.</p>
        
        <div style="font-size:.62rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:#64748b;margin-bottom:.5rem">Engineers</div>
        <div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:1rem">
          <span class="lp-author">Ryan Robert C. Diosana</span>
          <span class="lp-author">Yiannis L. Pedrozo</span>
          <span class="lp-author">James Jacob E. Sabio</span>
        </div>

        <div style="font-size:.62rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:#64748b;margin-bottom:.5rem">Tech Stack</div>
        <div style="display:flex;gap:5px;flex-wrap:wrap">
          <span class="lp-tag">Python · OpenCV</span>
          <span class="lp-tag">PHP · MySQL</span>
          <span class="lp-tag">Tapo TC65</span>
          <span class="lp-tag">IoT · CCTV</span>
        </div>
      </div>

      <!-- RIGHT CARD: Dashboard Action -->
      <div class="lp-about-card" style="display:flex;flex-direction:column;justify-content:center">
        <div style="font-size:.65rem;font-weight:700;letter-spacing:.18em;text-transform:uppercase;color:#0b4e28;margin-bottom:.5rem">Portal Access</div>
        <h3 style="font-family:'Outfit',sans-serif;font-size:1.15rem;font-weight:800;color:#0b4e28;margin-bottom:.6rem">Access the Dashboard</h3>
        <p style="font-size:.82rem;color:#4a6b54;line-height:1.65;font-weight:500">Sign in with your DLSU-D Microsoft account to view live room status and detection events.</p>
        <a href="login.php" class="lp-cta-primary" style="display:inline-flex;margin-top:1.2rem;align-self:flex-start"><i class="fa-brands fa-microsoft"></i> Sign in with Microsoft</a>
        <div style="font-size:.68rem;color:#64748b;margin-top:.7rem">@dlsud.edu.ph accounts only</div>
      </div>
    </div>
  </div>
</section>

<!-- FOOTER -->
<footer class="lp-footer">
  <div style="max-width:1100px;margin:0 auto;display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
    <div style="display:flex;align-items:center;gap:10px">
      <div style="width:32px;height:32px;border-radius:8px;background:#ffffff;color:#0b4e28;font-family:'Outfit',sans-serif;font-weight:900;font-size:.9rem;display:flex;align-items:center;justify-content:center">S</div>
      <div><div style="font-family:'Outfit',sans-serif;font-weight:800;font-size:.9rem;color:#fff">S.P.O.T.-IT</div><div style="font-size:.62rem;color:rgba(255,255,255,0.75)">DLSU-D · BS Computer Engineering</div></div>
    </div>
    <div style="font-size:.72rem;color:rgba(255,255,255,0.75);display:flex;gap:18px;flex-wrap:wrap">
      <span>&copy; 2026 Diosana · Pedrozo · Sabio</span>
      <a href="terms.php" style="color:rgba(255,255,255,0.9);text-decoration:underline">Terms</a>
      <a href="privacy-policy.php" style="color:rgba(255,255,255,0.9);text-decoration:underline">Privacy</a>
    </div>
  </div>
</footer>
</div>

<!-- ═══════════════════════════════════════════
     THREE.JS 3D TAPO TC65 + SCROLL CHOREOGRAPHY ENGINE
═══════════════════════════════════════════ -->
<script>
(function() {
  const canvas = document.getElementById('cctvCanvas');
  if (!canvas || typeof THREE === 'undefined') return;

  // 1. Scene, Camera, Renderer
  const scene = new THREE.Scene();
  const camera = new THREE.PerspectiveCamera(40, window.innerWidth / window.innerHeight, 0.1, 100);
  camera.position.set(0, 0, 7.8);

  const renderer = new THREE.WebGLRenderer({ canvas: canvas, alpha: true, antialias: true });
  renderer.setSize(window.innerWidth, window.innerHeight);
  renderer.setPixelRatio(Math.min(window.devicePixelRatio, 2));

  // 2. Studio Lighting (Tuned for light background)
  const ambientLight = new THREE.AmbientLight(0xffffff, 1.35);
  scene.add(ambientLight);

  const keyLight = new THREE.DirectionalLight(0xffffff, 1.6);
  keyLight.position.set(6, 8, 7);
  scene.add(keyLight);

  const fillLight = new THREE.DirectionalLight(0x0b4e28, 0.45);
  fillLight.position.set(-7, -4, 5);
  scene.add(fillLight);

  const backLight = new THREE.DirectionalLight(0xffffff, 0.8);
  backLight.position.set(0, 5, -6);
  scene.add(backLight);

  // Status LED Green PointLight
  const ledLight = new THREE.PointLight(0x22c55e, 3.2, 3.5);
  ledLight.position.set(0, -0.76, 1.5);

  // 3. Materials
  const whiteMat = new THREE.MeshStandardMaterial({
    color: 0xfcfcfc, roughness: 0.15, metalness: 0.05
  });
  const darkFaceMat = new THREE.MeshStandardMaterial({
    color: 0x121214, roughness: 0.1, metalness: 0.25
  });
  const redRingMat = new THREE.MeshStandardMaterial({
    color: 0xe53935, roughness: 0.25, emissive: 0x660000
  });
  const glassLensMat = new THREE.MeshPhysicalMaterial({
    color: 0x07152b, roughness: 0.04, metalness: 0.92, clearcoat: 1.0, clearcoatRoughness: 0.08, transmission: 0.25
  });

  // PROMINENT BRIGHT WHITE OVAL SPOTLIGHT CAPSULES
  const spotRimMat = new THREE.MeshStandardMaterial({
    color: 0xffffff, roughness: 0.05, metalness: 0.8, emissive: 0x888888
  });
  const spotGlassMat = new THREE.MeshStandardMaterial({
    color: 0xffffff, roughness: 0.02, metalness: 0.1, emissive: 0xeeeeee
  });

  const ledMat = new THREE.MeshBasicMaterial({ color: 0x22c55e });
  const mountMat = new THREE.MeshStandardMaterial({ color: 0xe4e4e7, roughness: 0.35 });

  // 4. Helper: Create Squarish Rounded Box Geometry
  function createRoundedBoxGeometry(width, height, depth, radius, smoothness) {
    const shape = new THREE.Shape();
    const w = width, h = height, r = radius;
    shape.moveTo(-w/2 + r, -h/2);
    shape.lineTo(w/2 - r, -h/2);
    shape.quadraticCurveTo(w/2, -h/2, w/2, -h/2 + r);
    shape.lineTo(w/2, h/2 - r);
    shape.quadraticCurveTo(w/2, h/2, w/2 - r, h/2);
    shape.lineTo(-w/2 + r, h/2);
    shape.quadraticCurveTo(-w/2, h/2, -w/2, h/2 - r);
    shape.lineTo(-w/2, -h/2 + r);
    shape.quadraticCurveTo(-w/2, -h/2, -w/2 + r, -h/2);

    const extrudeSettings = {
      depth: depth,
      bevelEnabled: true,
      bevelSegments: smoothness,
      steps: 1,
      bevelSize: r * 0.4,
      bevelThickness: r * 0.4
    };
    const geo = new THREE.ExtrudeGeometry(shape, extrudeSettings);
    geo.center();
    return geo;
  }

  // 5. Build TAPO TC65 Model Group
  const mainGroup = new THREE.Group();
  scene.add(mainGroup);

  // A. STATIC WALL MOUNT
  const mountGroup = new THREE.Group();
  mountGroup.position.set(0, 1.8, -1.4);

  const basePlateGeo = new THREE.CylinderGeometry(0.9, 0.9, 0.12, 32);
  const basePlateMesh = new THREE.Mesh(basePlateGeo, mountMat);
  basePlateMesh.rotation.x = Math.PI / 2;
  mountGroup.add(basePlateMesh);

  const nutGeo = new THREE.CylinderGeometry(0.5, 0.55, 0.38, 16);
  const nutMesh = new THREE.Mesh(nutGeo, mountMat);
  nutMesh.position.z = 0.26;
  nutMesh.rotation.x = Math.PI / 2;
  mountGroup.add(nutMesh);

  const armGeo = new THREE.CylinderGeometry(0.22, 0.22, 0.8, 16);
  const armMesh = new THREE.Mesh(armGeo, mountMat);
  armMesh.position.set(0, -0.4, 0.45);
  armMesh.rotation.x = Math.PI / 3.8;
  mountGroup.add(armMesh);

  mainGroup.add(mountGroup);

  // B. ROTATING CAMERA HEAD
  const cameraHead = new THREE.Group();
  cameraHead.position.set(0, 0.6, -0.2);

  // Soft Realistic Radial Drop Shadow Plane behind Pure White Camera Body
  const shadowGeo = new THREE.PlaneGeometry(5.2, 5.2);
  const shadowCanvas = document.createElement('canvas');
  shadowCanvas.width = 256; shadowCanvas.height = 256;
  const sctx = shadowCanvas.getContext('2d');
  const grad = sctx.createRadialGradient(128, 128, 10, 128, 128, 120);
  grad.addColorStop(0, 'rgba(0, 0, 0, 0.52)');    // Dark core contact shadow
  grad.addColorStop(0.4, 'rgba(0, 0, 0, 0.22)');  // Soft ambient blur
  grad.addColorStop(1, 'rgba(0, 0, 0, 0)');       // Transparent edge
  sctx.fillStyle = grad; sctx.fillRect(0, 0, 256, 256);

  const shadowTex = new THREE.CanvasTexture(shadowCanvas);
  const shadowMat = new THREE.MeshBasicMaterial({
    map: shadowTex, transparent: true, opacity: 0.88, depthWrite: false
  });
  const shadowMesh = new THREE.Mesh(shadowGeo, shadowMat);
  shadowMesh.position.set(0, -0.4, -0.6);
  cameraHead.add(shadowMesh);

  // Pure Authentic White Tapo TC65 Body
  const bodyGeo = createRoundedBoxGeometry(2.1, 1.9, 2.3, 0.35, 4);
  const bodyMesh = new THREE.Mesh(bodyGeo, whiteMat);
  cameraHead.add(bodyMesh);

  const redRingGeo = createRoundedBoxGeometry(2.14, 1.94, 0.06, 0.36, 3);
  const redRingMesh = new THREE.Mesh(redRingGeo, redRingMat);
  redRingMesh.position.z = 0.3;
  cameraHead.add(redRingMesh);

  const faceGeo = createRoundedBoxGeometry(1.95, 1.75, 0.12, 0.32, 4);
  const faceMesh = new THREE.Mesh(faceGeo, darkFaceMat);
  faceMesh.position.z = 1.18;
  cameraHead.add(faceMesh);

  const bezelGeo = new THREE.CylinderGeometry(0.58, 0.68, 0.18, 32);
  const bezelMesh = new THREE.Mesh(bezelGeo, darkFaceMat);
  bezelMesh.position.z = 1.26;
  bezelMesh.rotation.x = Math.PI / 2;
  cameraHead.add(bezelMesh);

  const lensGeo = new THREE.SphereGeometry(0.5, 32, 16, 0, Math.PI * 2, 0, Math.PI * 0.45);
  const lensMesh = new THREE.Mesh(lensGeo, glassLensMat);
  lensMesh.position.z = 1.25;
  lensMesh.rotation.x = Math.PI / 2;
  cameraHead.add(lensMesh);

  const pupilGeo = new THREE.CircleGeometry(0.24, 32);
  const pupilMat = new THREE.MeshBasicMaterial({ color: 0x02050e });
  const pupilMesh = new THREE.Mesh(pupilGeo, pupilMat);
  pupilMesh.position.z = 1.34;
  cameraHead.add(pupilMesh);

  function createTapoSpotlight(xPos) {
    const spotGroup = new THREE.Group();
    spotGroup.position.set(xPos, 0, 1.32);

    const rimGeo = new THREE.CylinderGeometry(0.14, 0.14, 0.10, 24);
    const rimMesh = new THREE.Mesh(rimGeo, spotRimMat);
    rimMesh.scale.set(1.0, 1.0, 1.5);
    rimMesh.rotation.x = Math.PI / 2;
    spotGroup.add(rimMesh);

    const glassGeo = new THREE.CylinderGeometry(0.10, 0.10, 0.12, 24);
    const glassMesh = new THREE.Mesh(glassGeo, spotGlassMat);
    glassMesh.scale.set(1.0, 1.0, 1.5);
    glassMesh.rotation.x = Math.PI / 2;
    spotGroup.add(glassMesh);

    return spotGroup;
  }

  cameraHead.add(createTapoSpotlight(-0.74));
  cameraHead.add(createTapoSpotlight(0.74));

  const ledGeo = new THREE.SphereGeometry(0.048, 16, 16);
  const ledMesh = new THREE.Mesh(ledGeo, ledMat);
  ledMesh.position.set(0, -0.76, 1.32);
  cameraHead.add(ledMesh);
  cameraHead.add(ledLight);

  function createTapoAntenna(xOffset, angleZ) {
    const antGroup = new THREE.Group();
    antGroup.position.set(xOffset, 0.2, -0.4);

    const bladeGeo = new THREE.BoxGeometry(0.14, 1.9, 0.35);
    const bladeMesh = new THREE.Mesh(bladeGeo, whiteMat);
    bladeMesh.position.y = 0.95;
    antGroup.add(bladeMesh);

    const ribMat = new THREE.MeshStandardMaterial({ color: 0xdddddd });
    for (let i = 0; i < 4; i++) {
      const ribGeo = new THREE.BoxGeometry(0.15, 0.04, 0.36);
      const ribMesh = new THREE.Mesh(ribGeo, ribMat);
      ribMesh.position.set(0, 1.2 + i * 0.15, 0);
      antGroup.add(ribMesh);
    }

    antGroup.rotation.z = angleZ;
    antGroup.rotation.x = -Math.PI / 14;
    return antGroup;
  }

  cameraHead.add(createTapoAntenna(1.22, -Math.PI / 5.5));
  cameraHead.add(createTapoAntenna(-1.22, Math.PI / 5.5));

  mainGroup.add(cameraHead);

  // 6. Real-time Cursor Rotation Logic
  let targetYaw = 0;
  let targetPitch = 0;
  let currentYaw = 0;
  let currentPitch = 0;

  window.addEventListener('mousemove', (e) => {
    const nx = (e.clientX / window.innerWidth - 0.5) * 2;
    const ny = (e.clientY / window.innerHeight - 0.5) * 2;

    targetYaw = nx * 0.85;   // Extended pan angle left & right (~50 deg)
    targetPitch = ny * 0.45; // Pitch tilt up/down (~25 deg)
  });

  window.addEventListener('resize', () => {
    if (!canvas) return;
    camera.aspect = window.innerWidth / window.innerHeight;
    camera.updateProjectionMatrix();
    renderer.setSize(window.innerWidth, window.innerHeight);
  });

  // 7. MULTI-STAGE SCROLL POSITION ENGINE
  let targetScrollRatio = 0;
  let currentScrollRatio = 0;

  window.addEventListener('scroll', () => {
    const totalHeight = Math.max(1, document.documentElement.scrollHeight - window.innerHeight);
    targetScrollRatio = Math.min(1.0, Math.max(0.0, window.scrollY / totalHeight));
  });

  // 8. Animation Render Loop
  let clock = new THREE.Clock();

  // CONSTANT UNIFORM SCALE EVERYWHERE ON THE PAGE
  const UNIFORM_SCALE = 0.38;

  function animate() {
    requestAnimationFrame(animate);

    const rightMarginX = 4.00; // Far right margin (Sections 2 & 3)
    const leftMarginX = -4.00; // Far left margin (Sections 4 & 5)

    currentYaw += (targetYaw - currentYaw) * 0.07;
    currentPitch += (targetPitch - currentPitch) * 0.07;

    currentScrollRatio += (targetScrollRatio - currentScrollRatio) * 0.04;

    // ═══ CHOREOGRAPHY PARAMETERS ═══
    const r = currentScrollRatio;
    let posX = 0.0;
    let posY = -1.10;
    let scaleVal = UNIFORM_SCALE;
    let baseYaw = 0.0;

    if (r <= 0.18) {
      // Hero (Stage 1) -> Features/How (Stage 2 & 3 Right)
      const p = r / 0.18;
      posX = THREE.MathUtils.lerp(0.0, rightMarginX, p);
      posY = THREE.MathUtils.lerp(-1.10, 0.0, p);
      scaleVal = UNIFORM_SCALE;
      baseYaw = THREE.MathUtils.lerp(0.0, -0.55, p);
    } else if (r <= 0.60) {
      // Features (Section 2) & How It Works (Section 3): Hold on FAR RIGHT side (X = +4.00)
      posX = rightMarginX;
      posY = 0.0;
      scaleVal = UNIFORM_SCALE;
      baseYaw = -0.55;
    } else if (r <= 0.74) {
      // Smooth glide from RIGHT margin -> LEFT margin in the gap between Section 3 and Section 4
      const p = (r - 0.60) / (0.74 - 0.60);
      posX = THREE.MathUtils.lerp(rightMarginX, leftMarginX, p);
      posY = 0.0;
      scaleVal = UNIFORM_SCALE;
      baseYaw = THREE.MathUtils.lerp(-0.55, 0.55, p);
    } else {
      // About (Section 4) & Footer (Section 5): Hold on FAR LEFT side (X = -4.00)
      posX = leftMarginX;
      posY = 0.0;
      scaleVal = UNIFORM_SCALE;
      baseYaw = 0.55;
    }

    mainGroup.position.x = posX;
    mainGroup.position.y = posY;
    mainGroup.scale.set(scaleVal, scaleVal, scaleVal);

    // Apply 3D Rotations (Base facing angle + cursor tracking)
    cameraHead.rotation.y = baseYaw + currentYaw;
    cameraHead.rotation.x = currentPitch;
    cameraHead.rotation.z = -currentYaw * 0.15;

    // Subtle floating animation
    const t = clock.getElapsedTime();
    mainGroup.position.y += Math.sin(t * 1.5) * 0.05;

    // Blinking Green Status LED
    const isBlinkingOn = (t % 1.2) < 0.8;
    ledLight.intensity = isBlinkingOn ? 3.2 : 0.2;
    ledMat.color.setHex(isBlinkingOn ? 0x22c55e : 0x052e16);

    renderer.render(scene, camera);
  }

  animate();
})();

/* ── SCROLL REVEAL ANIMAION ── */
(function() {
  var els = document.querySelectorAll('.reveal');
  if (!els.length) return;
  var obs = new IntersectionObserver(function(entries) {
    entries.forEach(function(e) {
      if (e.isIntersecting) { e.target.classList.add('visible'); obs.unobserve(e.target); }
    });
  }, { threshold: 0.1, rootMargin: '0px 0px -30px 0px' });
  for (var i = 0; i < els.length; i++) obs.observe(els[i]);
})();
</script>
</body>
</html>
