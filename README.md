# S.P.O.T.-IT — IoT Integrated Lab Monitoring System
## BS Computer Engineering Thesis · DLSU-D CEAT

---

## ⚠️ ARCHITECTURE RULE — READ BEFORE TOUCHING ANYTHING

**THIS IS A STRICT MICROSERVICES ARCHITECTURE. NO MONOLITHIC CODE. EVER.**

Every service has its own database. Pages only talk to their own service.
Cross-service data is fetched via the shared `service_bootstrap.php` helpers.
Never put two database connections in one service file.
Never put SQL queries directly inside a `.php` page — always go through `auth/` handlers.

---

## Folder Structure

```
spotit/
├── README.md                    ← you are here
│
├── config/
│   └── env.php                  ← DB credentials (never commit real creds)
│
├── services/                    ← ONE folder per microservice, ONE DB each
│   ├── auth/
│   │   └── db.php               ← spotit_auth_db   (users, sessions, login_attempts)
│   ├── monitoring/
│   │   └── db.php               ← spotit_monitor_db (rooms, detections, monitoring_logs, registered_lab_items)
│   ├── lostfound/
│   │   └── db.php               ← spotit_lf_db      (recovered_items, surrender_logs, claims)
│   └── user/
│       └── db.php               ← spotit_user_db    (user_profiles, user_settings)
│
├── auth/                        ← Backend action handlers (POST endpoints)
│   ├── service_bootstrap.php    ← Loads all service DBs, shared helpers
│   ├── login_handler.php
│   ├── logout.php
│   ├── microsoft_login.php      ← OAuth redirect to Microsoft
│   ├── microsoft_callback.php   ← OAuth callback handler
│   ├── signup_handler.php
│   ├── get_rooms.php
│   ├── get_detections.php
│   ├── update_event_status.php
│   ├── register_roi.php
│   ├── recalibrate_room.php
│   ├── get_claims.php
│   └── submit_claim.php
│
├── pages/                       ← Public-facing PHP pages (UI only, no raw SQL)
│   ├── login.php
│   ├── signup.php
│   ├── dashboard-admin.php
│   ├── dashboard-staff.php
│   ├── dashboard-student.php
│   ├── room-monitor.php
│   ├── claiming-station.php
│   └── lost-thread.php
│
├── assets/
│   ├── css/
│   │   ├── variables.css        ← Design tokens (shared across all pages)
│   │   ├── login.css
│   │   ├── signup.css
│   │   ├── dashboard-admin.css
│   │   ├── dashboard-staff.css
│   │   ├── dashboard-student.css
│   │   ├── room-monitor.css
│   │   ├── claiming-station.css
│   │   └── lost-thread.css
│   ├── js/
│   │   ├── theme.js             ← dark/light toggle (shared)
│   │   ├── toast.js             ← toast notification helper (shared)
│   │   ├── dashboard-admin.js
│   │   └── claiming-station.js
│   └── icons/
│       └── microsoft.svg
│
├── uploads/
│   └── snapshots/               ← CCTV snapshot images saved by Python detection module
│
└── vendor/
    └── PHPMailer/               ← For OTP / alert emails
```

---

## Databases (Microservices — Strict Separation)

| Service       | Database             | Tables                                                                 |
|---------------|----------------------|------------------------------------------------------------------------|
| auth          | spotit_auth_db       | users, login_attempts, sessions, microsoft_tokens                      |
| monitoring    | spotit_monitor_db    | rooms, registered_lab_items, detections, monitoring_logs               |
| lostfound     | spotit_lf_db         | recovered_items, surrender_logs, claims                                |
| user          | spotit_user_db       | user_profiles, user_settings                                           |

---

## Auth Rules

- Microsoft OAuth (`@dlsud.edu.ph` only) is the PRIMARY login method
- Manual email/password is the FALLBACK (also enforces `@dlsud.edu.ph`)
- CAPTCHA required before every manual login attempt
- Rate limiting: 3 fails → 30s cooldown, 5 fails → 5 min lockout (stored in `login_attempts`)
- Roles: `student`, `staff`, `admin` (admin is provisioned only by existing admin)

---

## Detection Logic (Camera — future integration)

- Python + OpenCV runs separately, posts JSON to `auth/ingest_detection.php`
- `ingest_detection.php` writes to `spotit_monitor_db.detections`
- Dashboard polls `auth/get_detections.php` every 10 seconds via JS fetch
- Timer thresholds: 30 min → "Potentially Lost", 60 min → "Confirmed Missing"
- ROI tolerance: item slightly moved but still within search zone → "Item Found/Moved" (no alert)

---

## UX Systems — Skeleton Loading & Onboarding Tour

### Skeleton Loading Screen
- `assets/css/skeleton.css` + `assets/js/skeleton.js` — shared across all pages
- Set `<body data-skeleton="dashboard|form|thread|legal|none">` to pick the matching skeleton shape
- Injects an overlay that mimics the real layout (sidebar, topbar, stat cards, table rows) immediately on page load
- Auto-fades out once `window.onload` fires, with a 450ms minimum display time (no flash on fast loads) and a 4s safety-net timeout
- Manual control for AJAX/fetch swaps: `window.SpotitSkeleton.show()`, `.hide()`, `.wrapAsync(selector, renderFn, fetchFn)`

### First-Time Onboarding Tour
- `assets/css/onboarding.css` + `assets/js/onboarding.js` — shared across all dashboard pages
- Each dashboard defines `window.SPOTIT_TOUR_STEPS = [...]` (target selector, title, description, icon, placement) before loading `onboarding.js`
- Spotlight overlay + positioned tooltip with Next / Previous / Skip Tutorial controls and progress dots
- Completion is checked via `auth/get_tour_status.php` (server-side, `spotit_auth_db.tour_status` table) with `localStorage` as an instant-paint fallback cache
- Completion is saved via `auth/save_tour_status.php` on Finish or Skip
- Users can replay the tour anytime from **Settings → Display → Onboarding & Help → Replay Tour**, which redirects to their dashboard with `?replay_tour=1`
- Implemented on: `dashboard-admin.php`, `dashboard-staff.php`, `dashboard-student.php`

## Build Order (UI First, Camera Later)

1. ✅ config/env.php
2. ✅ services/*/db.php  (all 4 microservice DB connectors)
3. ✅ auth/service_bootstrap.php
4. ✅ assets/css/variables.css
5. ✅ pages/login.php + assets/css/login.css
6. ✅ pages/signup.php + assets/css/signup.css
7. ⬜ pages/dashboard-admin.php + assets/css/dashboard-admin.css
8. ⬜ pages/dashboard-staff.php
9. ⬜ pages/dashboard-student.php + lost-thread
10. ⬜ pages/claiming-station.php
11. ⬜ pages/room-monitor.php
12. ⬜ auth/*.php backend handlers
13. ⬜ SQL schema files for all 4 databases
14. ⬜ Python detection module integration (CAMERA PHASE)
