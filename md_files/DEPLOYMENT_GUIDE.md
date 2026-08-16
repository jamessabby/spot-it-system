# S.P.O.T.-IT — Deployment Guide

Follow this on the day you deploy. It assumes nothing is bought or set up yet.

**Time needed:** ~2 hours for the website, ~30 minutes for the lab PC.

---

## 0. Why there are two machines

This is the single thing to understand before anything else makes sense.

Your camera lives at `192.168.18.14`. Addresses starting `192.168.` are **private** —
they only mean something inside one local network, and are not unique in the world.
A server in a data centre asking for `192.168.18.14` gets no meaningful answer, and
the school's router would refuse the connection anyway: NAT lets devices inside reach
out, but blocks anything outside from reaching in.

So detection cannot run on Hostinger. Ever. It has to run on a machine in the lab.

```
   INSIDE THE CEAT LAB                        ON THE INTERNET

   Tapo TC65  ──RTSP──▶  mini-PC                  Hostinger
   192.168.18.14         running main.py          your-domain.com
                              │                    (dashboards, claiming)
                              └───── HTTPS ──────────▶   allowed
                              ◀───── blocked ───────────
```

**The rule everything follows: the mini-PC always starts the conversation.** It reaches
out to say "here's a detection", "here's the current camera view", "any instructions for
me?" The server never calls in, because it can't.

That is why Capture Baseline works backwards now — the dashboard leaves a note, and the
detector picks it up on its next check-in, a few seconds later.

This is also how Ring, Nest and Ubiquiti work. It is the standard architecture, not a
workaround.

---

## 1. Buy hosting

**Hostinger Cloud Startup** (~₱409/mo, 100 GB NVMe). At checkout make sure you get:

| Requirement | Why |
|---|---|
| PHP 8.0+ | The codebase uses `match()` and typed properties |
| MySQL/MariaDB, **4+ databases** | One per microservice — a hard thesis requirement (CLAUDE.md §8) |
| **SSH access** | You need the `mysql` CLI to import schemas; phpMyAdmin times out on large files |
| Free SSL | Required — the detector sends its API key on every request |

Point your domain at it and **enable SSL before going further**. Without HTTPS, the API
key and every session cookie cross the internet in clear text.

---

## 2. Create the databases

In hPanel → Databases → create **five**. Hostinger prefixes names with your account id.

```
u000000000_spotit_auth        u000000000_spotit_monitor
u000000000_spotit_lf          u000000000_spotit_user
u000000000_spotit_community
```

Write down the username and password — they go in `env.local.php` next.

Then over SSH, import each schema into its own database:

```bash
cd ~/domains/YOUR-DOMAIN/public_html
mysql -u USER -p u000000000_spotit_auth      < services/auth/schema.sql
mysql -u USER -p u000000000_spotit_monitor   < services/monitoring/schema.sql
mysql -u USER -p u000000000_spotit_lf        < services/lostfound/schema.sql
mysql -u USER -p u000000000_spotit_user      < services/user/schema.sql
```

Then the migrations, **in exactly this order** (CLAUDE.md §7a — they depend on each other):

```bash
mysql -u USER -p u000000000_spotit_monitor < migration_workflow_audit.sql
mysql -u USER -p u000000000_spotit_monitor < migration_confidence.sql
mysql -u USER -p u000000000_spotit_monitor < migration_notifications.sql
mysql -u USER -p u000000000_spotit_monitor < migration_snapshot_b.sql
mysql -u USER -p u000000000_spotit_monitor < services/migration_accuracy_trials.sql
```

---

## 3. Upload the site

Upload the repo to `public_html`. Then create the one file that is not in git:

```bash
cp config/env.local.example.php config/env.local.php
nano config/env.local.php
```

Fill in your real values. The three that matter most:

```php
'SPOTIT_DEPLOY' => 'remote',                        // the camera is not reachable from here
'SITE_URL'      => 'https://your-domain.com',       // no trailing slash
'SPOTIT_DETECTION_KEY' => '<64 random hex chars>',  // see below
```

Generate the key and **keep it** — the mini-PC needs the identical string:

```bash
php -r "echo bin2hex(random_bytes(32)).PHP_EOL;"
```

> **`SPOTIT_DEPLOY => 'remote'` is not optional.** Left as `local`, the site will keep
> trying to reach the camera itself: the live feed will never load and Capture Baseline
> will hang waiting for ffmpeg to talk to an address that does not exist.

Create the writable directories:

```bash
mkdir -p uploads/snapshots photos runtime logs
chmod 755 uploads/snapshots photos runtime logs
```

### Verify before continuing

```bash
curl -s -o /dev/null -w "%{http_code}\n" https://your-domain.com/config/env.php   # want 403
curl -s -o /dev/null -w "%{http_code}\n" https://your-domain.com/main.py          # want 403
curl -s -o /dev/null -w "%{http_code}\n" https://your-domain.com/rois_ROOM_1.json # want 403
curl -s -o /dev/null -w "%{http_code}\n" https://your-domain.com/pages/login.php  # want 200
```

If `env.php` returns 200 you are publishing your database password — stop and fix
`.htaccess` before doing anything else.

---

## 4. Create the first admin

Admins are never self-signup (CLAUDE.md §2), so the first one is seeded by hand.

```bash
php -r "echo password_hash('YourStrongPassword', PASSWORD_BCRYPT).PHP_EOL;"
```

```sql
INSERT INTO users (full_name, email, password_hash, role, is_active)
VALUES ('Your Name', 'you@dlsud.edu.ph', '<paste the hash>', 'admin', 1);
```

Log in at `https://your-domain.com/pages/login.php` and confirm the dashboard loads.

---

## 5. Microsoft OAuth

In the Azure portal, add the production redirect URI to your app registration:

```
https://your-domain.com/auth/microsoft_callback.php
```

Then put the client id, secret and tenant id in `env.local.php`.

> This catches people out constantly: `MS_REDIRECT_URI` is derived from `SITE_URL`
> automatically, so the code is already correct — but Azure rejects any redirect URI it
> has not been told about in advance. Login will fail with a confusing error until you
> register it.

---

## 6. Set up the lab mini-PC

This machine watches the camera. It stays in or near the lab, powered on.

**Install:**
1. [Python 3.11+](https://www.python.org/downloads/) — tick **"Add Python to PATH"**
2. [NSSM](https://nssm.cc/download) — unzip, put `nssm.exe` on PATH (or `choco install nssm`)
3. The repo, e.g. `C:\spotit`

```powershell
cd C:\spotit
pip install -r requirements.txt
```

**Confirm it can see the camera before going further:**

```powershell
ping 192.168.18.14
```

**Install the service** (elevated PowerShell — Run as Administrator):

```powershell
cd C:\spotit\deploy
.\install-service.ps1 -ApiUrl "https://your-domain.com/auth/ingest_detection.php" `
                      -ApiKey "<the same key as env.local.php>" `
                      -RoomId ROOM_1
```

That's it. The detector now starts at boot, restarts if it crashes, and needs no
terminal and no logged-in user.

```powershell
Get-Content C:\spotit\logs\detector.log -Wait -Tail 40   # watch it
nssm restart SpotItDetector                              # after config changes
```

**One detector per room.** For a second room, install a second service with a different
name and room id:

```powershell
.\install-service.ps1 -ServiceName SpotItDetector_ROOM2 -RoomId ROOM_2 -ApiUrl ... -ApiKey ...
```

---

## 7. Smoke test

Do this in order. Each step depends on the one before it.

| # | Action | Expected |
|---|---|---|
| 1 | Open **Room Cameras** for ROOM_1 | Pill top-left reads **LIVE · ~15 FPS** within 30s |
| 2 | — | Live view shows the room |
| 3 | Click **Capture Baseline** | "Capture requested…" then a fresh baseline within ~10s |
| 4 | **Setup ROI Zones**, draw a box, save | Console logs a `[CALIB]` line for it |
| 5 | Remove that item | Box turns RED within ~1s |
| 6 | Check the dashboard | Detection appears with a snapshot image |
| 7 | Put the item back | Auto-resolves to green |
| 8 | Stop the service | Pill turns **DETECTOR OFFLINE** within ~30s |
| 9 | Start it again | Returns to LIVE |

If step 1 shows DETECTOR OFFLINE, the service is running but cannot reach the server.
Check the API key matches on both sides, and that `logs/detector.err.log` is not full of
connection errors.

---

## 8. What runs where

| Thing | Where | Notes |
|---|---|---|
| Dashboards, claiming, login | Hostinger | Public URL |
| MySQL (5 databases) | Hostinger | One per service |
| Snapshots, live frames | Hostinger `uploads/snapshots/` | Arrive over HTTP |
| **main.py** | **Lab mini-PC** | The only thing that can see the camera |
| Baselines, ROI files | Both | Server is authoritative; the PC keeps a synced copy as an offline cache |

**Data flow when an item goes missing:**

```
camera → main.py detects → POST snapshot to ingest_detection.php
       → saved in uploads/snapshots/, filename stored in the DB
       → dashboard renders ../uploads/snapshots/<filename>
```

Only the bare filename is stored, never a path — which is why moving hosts needs no
database rewriting.

---

## 9. Bandwidth and storage

The detector pushes live frames **only while somebody has a dashboard open** (~1/sec),
and drops to one frame every 15s otherwise.

| | Per room per day |
|---|---|
| Live frames, idle | ~0.3 GB |
| Live frames, actively watched 8h | ~1.7 GB |
| Detection snapshots (~100 events) | ~20 MB |

Without the idle throttle this would be ~5 GB/day/room of frames sent to an empty room.
If the campus connection is metered, raise `LIVE_FRAME_IDLE_SEC` in `main.py`.

Storage is bounded by the donation purge (§10) rather than growing forever.

---

## 10. End-of-year cleanup

**Donation & Retention** in the sidebar (admin only) processes unclaimed items: it keeps
the item description and dates as a record, and deletes the CCTV snapshots, detection
rows and claimant selfies. An audit entry naming you is written first.

This is both the SWAFO-mandated donation process and the system's garbage collection.

---

## 11. Troubleshooting

| Symptom | Cause | Fix |
|---|---|---|
| DETECTOR OFFLINE, service running | Cannot reach the server | Check API key matches; check `logs/detector.err.log` |
| Live view never loads | `SPOTIT_DEPLOY` still `local` | Set `'remote'` in `env.local.php` |
| Capture Baseline hangs | Same | Same |
| Dashboard buttons spin forever | Missing JS asset | Check the browser console first — it names the file |
| `env.php` returns 200 | `.htaccess` not applied | Confirm `AllowOverride All` |
| No detections in the DB | API key mismatch | `logs/detector.err.log` will show 401s |
| Detector restarts repeatedly | Bad config or camera | `logs/detector.err.log`; NSSM throttles after 10s |
| Uploaded image 403s | Working as intended | `uploads/.htaccess` only serves jpg/png/gif/webp |

**Offline fallback for the defense.** If campus internet is unreliable on the day, run
the whole system on one laptop with XAMPP: leave `SPOTIT_DEPLOY` as `local` (or unset)
and everything reverts to the on-disk behaviour, MJPEG proxy included. That path is kept
working deliberately so the demo is never hostage to the network.

---

## Appendix: the two config files

**`config/env.local.php`** — on the server, gitignored, holds DB credentials, the
detection key, Azure secrets, `SITE_URL`, and `SPOTIT_DEPLOY = 'remote'`.

**Service environment on the mini-PC** — set by `install-service.ps1`:
`API_URL`, `API_KEY`, `SPOTIT_DEPLOY=remote`, `ROOM_ID`.

The one value that must be **identical on both**:

```
config/env.local.php:  'SPOTIT_DETECTION_KEY' => 'abc123...'
mini-PC service:        API_KEY=abc123...
```

Every detector request is rejected with 401 if they differ.
