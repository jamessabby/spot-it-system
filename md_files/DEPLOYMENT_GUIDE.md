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

Then the migrations. The first four **must run in exactly this order** — they depend on
each other (CLAUDE.md §7a). The rest are independent and can run in any order after them,
but watch the database each one targets: they are not all the monitoring DB.

```bash
# Ordered — each builds on the last.
mysql -u USER -p u000000000_spotit_monitor < services/migration_workflow_audit.sql
mysql -u USER -p u000000000_spotit_monitor < services/migration_confidence.sql
mysql -u USER -p u000000000_spotit_monitor < services/migration_notifications.sql
mysql -u USER -p u000000000_spotit_monitor < services/migration_snapshot_b.sql

# Independent. migration_room_enabled needs camera_ip, so keep these two in order.
mysql -u USER -p u000000000_spotit_monitor < services/migration_room_camera_columns.sql
mysql -u USER -p u000000000_spotit_monitor < services/migration_room_enabled.sql
mysql -u USER -p u000000000_spotit_monitor < services/migration_accuracy_trials.sql

# AUTH database — email verification + password reset.
mysql -u USER -p u000000000_spotit_auth    < services/migration_email_verification.sql

# LOST & FOUND database, not monitoring.
mysql -u USER -p u000000000_spotit_lf      < services/migration_claims_archive.sql
```

`migration_workflow_audit.sql` also writes into the **auth** database — it creates
`notifications` and `user_activity` there via its own `USE` statements — so run it with an
account that can reach both, or split it by hand.

All of these are safe to re-run: every one uses `IF NOT EXISTS` or an idempotent
`MODIFY COLUMN`.

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
INSERT INTO users (full_name, email, password_hash, role, is_active, email_verified, email_verified_at)
VALUES ('Your Name', 'you@dlsud.edu.ph', '<paste the hash>', 'admin', 1, 1, NOW());
```

> `email_verified = 1` is **required**. Since 2026-08-22 login refuses an account
> whose address has not been confirmed, and this admin has no confirmation email
> to click — omit it and you lock yourself out of the site you just deployed.

Log in at `https://your-domain.com/pages/login.php` and confirm the dashboard loads.

---

## 4a. Email (confirmation + password reset)

Signup now emails a confirmation link, and password reset emails a 6-digit code.
Both were non-functional before 2026-08-22 — reset in particular was a mock that
told users their password had changed when nothing had. If mail is not
configured, **new accounts cannot sign in** and nobody can recover a password.

Everything is driven by `MAIL_DRIVER` in `config/env.local.php`. Three options:

| Driver | Sends? | Use when |
|---|---|---|
| `log` (default) | No — writes to `logs/mail/` | Local XAMPP, and any time you want to test the flow without a domain |
| `mail` | Yes, via PHP `mail()` | Quickest on Hostinger. No account needed. Deliverability to Outlook is mediocre without SPF/DKIM |
| `resend` | Yes, via api.resend.com | Best deliverability. Needs a Resend account and a verified domain |

### Local / no domain yet — `log`

Nothing to configure; this is the default. Mail is written to
`logs/mail/` instead of being sent:

```bash
cat logs/mail/index.log          # newest message last
```

Open the matching `.html` file in a browser — the confirmation link is clickable
and the reset code is readable. `logs/` is gitignored and returns **403** over
HTTP, so these never leak.

### Hosted, simplest — `mail`

```php
'MAIL_DRIVER'    => 'mail',
'MAIL_FROM'      => 'noreply@your-domain.com',
'MAIL_FROM_NAME' => 'S.P.O.T.-IT System',
```

Send yourself a test signup. If it lands in spam, add an SPF record for your
domain in Hostinger's DNS panel, or switch to `resend`.

### Hosted, best deliverability — `resend`

1. Create a free account at resend.com (3,000 emails/month, 100/day — far more
   than this project needs).
2. **Domains → Add Domain**, enter your Hostinger domain, and copy the DNS
   records it gives you into Hostinger's DNS panel. Wait for it to show
   *Verified*.
3. **API Keys → Create**, then:

```php
'MAIL_DRIVER'    => 'resend',
'MAIL_API_KEY'   => 're_xxxxxxxxxxxxxxxx',
'MAIL_FROM'      => 'noreply@your-domain.com',
'MAIL_FROM_NAME' => 'S.P.O.T.-IT System',
```

> **`MAIL_FROM` must be on a domain you own and have verified.** It is *not*
> `@dlsud.edu.ph` — that is where mail is sent *to*. Claiming to send as the
> university would be rejected outright, and until the domain is verified Resend
> only delivers to the address that owns the Resend account.

### Verifying it works

```bash
php -r "require 'config/env.php'; print_r(spotit_mail('you@dlsud.edu.ph','S.P.O.T.-IT test', spotit_mail_template('Test','<p>It works.</p>')));"
```

`[ok] => 1` means accepted. On `resend`, a failure body usually says exactly what
is wrong — "domain not verified" is the common first one.

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

**Export the records first.** The admin dashboard has an **Export Records** card in its
right-hand column: one CSV of confirmed claimants, one of unclaimed/donated items,
optionally filtered to a year. The purge is irreversible, so take these before running it.
Confirmed outcomes only — unverified `pending` detections are deliberately excluded.

**Donation & Retention** in the sidebar (admin only) then processes unclaimed items: it
keeps the item description and dates as a record, and deletes the CCTV snapshots and
detection rows. Claimant selfies are deleted too, but the **claim row itself is kept**,
marked `archived` — who collected what is an audit record the lab needs; the photograph is
personal data with a retention limit. An audit entry naming you is written first.

This is both the SWAFO-mandated donation process and the system's garbage collection.

---

## 10a. Scheduled job — auto-escalation

`auth/escalate_detections.php` is what moves a detection from `pending` to `potential` at
30 minutes and to `confirmed_missing` at 60 (`TIMER_POTENTIAL_MIN` / `TIMER_CONFIRMED_MIN`
in `config/env.php`). It also writes the `auto_escalation` audit log and notifies every
admin and staff account.

**Nothing calls it on its own.** Until it is scheduled, every detection sits at `pending`
forever and the two-stage escalation exists only on paper. Set up **exactly one** of the
two options below — running both would escalate each detection twice and duplicate every
notification.

### Hosted (Hostinger) — control-panel cron

hPanel -> **Advanced** -> **Cron Jobs** -> Create. Every 5 minutes:

```
*/5 * * * *   curl -fsS "https://your-domain.com/auth/escalate_detections.php?cron_key=YOUR_CRON_KEY" > /dev/null
```

If the plan offers a PHP-CLI cron instead, prefer it — CLI skips the key check entirely,
so the secret never appears in a cron table or a server log:

```
*/5 * * * *   /usr/bin/php /home/uXXXXXXX/domains/your-domain.com/public_html/auth/escalate_detections.php
```

`YOUR_CRON_KEY` is `SPOTIT_CRON_KEY` from **`config/env.local.php`** — the same gitignored
file that holds every other secret. It is never hardcoded:

```php
'SPOTIT_CRON_KEY' => '<32+ random hex chars, different from SPOTIT_DETECTION_KEY>',
```

Generate one with `php -r "echo bin2hex(random_bytes(24));"`.

While `SPOTIT_CRON_KEY` is still the shipped `CHANGE_ME_CRON_KEY` placeholder, the HTTP
trigger returns **503** and refuses to run. That is deliberate: the endpoint changes
detection status and fans out notifications, so on a public host a key printed in the repo
is no key at all.

### Local / Windows — NSSM service

Same approach as the detector service, for the same reasons (no logged-in user, no open
terminal, restarts after a crash or reboot). From an **elevated** PowerShell:

```powershell
cd C:\xampp\htdocs\spotit\deploy
.\install-escalation-service.ps1
```

It runs the job once first, so a bad DB config shows up as readable output rather than as
a silent service that never escalates anything. Then it registers `SpotItEscalation`,
which loops every 5 minutes. `deploy/escalation-runner.ps1` is the loop itself — NSSM
supervises processes, not schedules, so the interval has to live in a script.

Remove it with `.\uninstall-escalation-service.ps1` (do this before switching to the
hosted cron).

### Confirming it is actually running

```powershell
nssm status SpotItEscalation
Get-Content C:\xampp\htdocs\spotit\logs\escalation.log -Wait -Tail 20
```

`SERVICE_RUNNING`, plus a timestamped `Escalation run complete:` line every 5 minutes, is
the proof. On Hostinger the same evidence is in the database instead:

```sql
SELECT logged_at, event_message FROM monitoring_logs
 WHERE event_type = 'auto_escalation' ORDER BY log_id DESC LIMIT 10;
```

An end-to-end test that does not require waiting 30 real minutes is in
[TESTING_GUIDE.md](file:///c:/xampp/htdocs/spotit/md_files/TESTING_GUIDE.md) under
"Auto-escalation".

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
| Detections never leave `pending` | Escalation job not scheduled | §10a — install the service or add the cron |
| Escalation URL returns 503 | `SPOTIT_CRON_KEY` still the placeholder | Set a real key in `env.local.php` |
| Escalation URL returns 401 | Key in the cron does not match `env.local.php` | Re-copy it; no quotes, no trailing space |
| Every alert notified twice | Both the NSSM service *and* a cron are running | Run only one — `.\uninstall-escalation-service.ps1` |
| Camera IP rejected on save | Another enabled room already holds it | Disable that room on Room Setup first — the error names it |
| New users cannot sign in | Address not confirmed | Check `logs/mail/`, or configure a real `MAIL_DRIVER` (§4a) |
| Confirmation email never arrives | `MAIL_DRIVER` still `log` | It was written to `logs/mail/`, not sent. Set `mail` or `resend` |
| Resend returns "domain not verified" | DNS records not added or not propagated | Resend → Domains; until verified it only delivers to your own address |
| Locked out after deploying | Seed admin has `email_verified = 0` | `UPDATE users SET email_verified = 1 WHERE email = '...';` |

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
