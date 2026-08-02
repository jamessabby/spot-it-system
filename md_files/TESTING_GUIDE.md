# S.P.O.T.-IT — Detection Pipeline Testing Guide

**For the 2026-07-30 rewrite.** Follow this in order against the live Tapo TC65 stream. Every
test states what to do, what you should see, and **what it proves** — so if something fails you
know which part is broken rather than just "it didn't work".

Nothing in this guide has been run against the live camera yet. The numbers quoted as
"expected" come from running the real functions in `main.py` against
`photos/ref_image_ROOM_1.jpg`, so they are grounded, but the live stream is the real test.

**Time needed:** ~45 min for Parts 0–4 (the essentials). Parts 5–8 are for Chapter 4 evidence.

---

## What actually changed (one page)

### The detection fix

| | Before | After |
|---|---|---|
| Question asked | "Does this look like the mouse?" | "Does this look like the mouse, **or** like the bare desk?" |
| Removed item on wood | Bare desk scored 0.65 against a crop that was mostly desk → **stayed GREEN** | Bare desk matches the *empty* template → **turns RED** |
| Hand on mouse | Not-the-item → **turned RED** | Matches neither template → **OCCLUDED, timers freeze** |
| Still hand | Motion hack failed → **RED** | Doesn't depend on motion → **stays safe** |
| Confirmation | Counted loop spins (~half were duplicate frames) | Wall-clock seconds |  
| Lighting change | Could flip states | Compared in feature space; ±25% moves score <0.02 |

### The five bugs fixed

| # | Bug | Symptom you'd have seen |
|---|---|---|
| a | `validate_presence_dnn()` called with a missing argument, uncaught | **`main.py` died** the first time a left item appeared |
| b | `prev_roi_gray` overwritten before use | **Snapshot B never fired**; "clean frame" fired instantly |
| c | Scene motion *reset* the missing counter | Items removed while anyone moved nearby were **never detected** |
| d | Consistency counted loop iterations, not frames | Confirmation timing didn't match config |
| e | Production mode `continue`d before the ROI loop | **Registered equipment not monitored at all** in production |

### New on-screen vocabulary

| Box colour | Label | Meaning |
|---|---|---|
| 🟢 Green | `OK: mouse` | Item is there |
| 🟡 Yellow | `CHECKING: mouse (60%)` | Looks gone, counting toward 1.0 s |
| 🔴 Red | `MISSING: mouse` | Confirmed gone, event fired |
| 🔵 Blue | `OCCLUDED: mouse` | Can't see it — **holding, no alarm** |

Under every box: `here=0.99 gone=0.60` — the two scores that made the decision.
`here` high → present. `gone` high → removed. Both low → occluded.

---

## Part 0 — Pre-flight

```powershell
cd C:\xampp\htdocs\spotit
```

1. **XAMPP running** — Apache + MySQL green in the control panel. Open
   <http://localhost/spotit/> and confirm it loads.
2. **Camera reachable** — this must succeed before anything else:
   ```powershell
   ping 192.168.18.11
   ```
   If it fails, the camera is off or on a different network. `main.py` will exit with
   `[ERROR] Cannot open RTSP stream`.
3. **Baseline photo contains the items.** Open `photos/ref_image_ROOM_1.jpg`. Every registered
   item must be visible and in place. **This is the single most common cause of failure** — see
   Part 4 Test 4.2.
4. **Clear old artefacts** so you're reading fresh results:
   ```powershell
   del photos\detection_metrics.csv
   ```
   Then click **🧹 Clear Snapshots** in `pages/room-monitor.php`.
5. **Confirm mode.** `detection_mode.json` should read:
   ```json
   { "mode": "testing", "tracking_mode": "registered", "room_id": "ROOM_1" }
   ```

---

## Part 1 — Startup & calibration

### Test 1.1 — Clean boot

```powershell
python main.py ROOM_1
```

**Expect:**
```
[SPOT-IT] Loaded 3 ROIs: ['tumbler', 'laptop', 'mouse']
[SPOT-IT][DNN] MobileNetV2 gatekeeper loaded — 1000 ImageNet classes ready.
[SPOT-IT] Connecting to stream: rtsp://...
[SPOT-IT] Reference frame loaded — size: (1152, 648) | Baseline items count: 3
[SPOT-IT][CALIB] 'tumbler': item footprint 106x139px (50% of box) | DNN templates ready
[SPOT-IT][CALIB] 'laptop':  item footprint 183x102px (52% of box) | DNN templates ready
[SPOT-IT][CALIB] 'mouse':   item footprint 62x59px (53% of box) | DNN templates ready
```

**Proves:** both templates were built for every ROI and the embedding layer resolved. The
`DNN templates ready` suffix is the important bit — if it says `classical templates ready`
instead, the model didn't load and you're running on the weaker chroma-histogram fallback.

> ⚠️ **`[CALIB][WARN] '<label>' — no item found inside this ROI`** means the baseline was
> captured with that item **already missing**. Put the item back, re-capture the baseline via
> **📷 Capture Frame** in `pages/camera-setup.php`, and restart. Calibration cannot work from a
> photo of an empty desk.

> ⚠️ **`fills only 2% of its box`** means the ROI is drawn far too loose. Redraw it tighter
> around the item in `pages/desk-sandbox.php`.

### Test 1.2 — Idle stability (2 minutes)

Leave everything untouched. Watch <http://localhost/spotit/pages/room-monitor.php>.

**Expect:** all three boxes solid green the whole time. No yellow flicker. `here=` stays ≈0.97–1.00.

**Proves:** no false positives at rest — RTSP compression noise isn't tripping the gate.

---

## Part 2 — The tests you actually care about

### Test 2.1 — Remove the mouse ⭐ the headline fix

The mouse is the hardest case: small, dark, on glossy wood — the exact scenario that used to
stay green forever.

1. Lift the mouse off the desk and set it out of frame.
2. Watch the box.

**Expect:**

| Time | Box | Console |
|---|---|---|
| ~0.0 s | turns 🟡 `CHECKING: mouse (20%)` | `'mouse' surface revealed where the item was (match vs item 0.74, vs empty surface 0.78). Confirming...` |
| ~1.0 s | turns 🔴 `MISSING: mouse` | `[CONFIRMED MISSING EVENT (RED) DETECTED]` + `-> API SUCCESS: detection_id=...` |

Then verify it reached the database:
```sql
SELECT detection_id, object_type, detected_at, roi_change_pct, status, snapshot_path
FROM spotit_monitor_db.detections
WHERE room_id = 'ROOM_1' ORDER BY detected_at DESC LIMIT 5;
```
One new row, `status = 'pending'`. And on disk: `uploads\snapshots\mouse_registered_1.jpg`.

**Proves:** the wood-grain false-negative from `unfixable_limitations.md` §1 is fixed.
**Repeat 3×** — one success could be luck.

### Test 2.2 — Put it back

Place the mouse back roughly where it was (don't be precise).

**Expect:** green within ~0.5 s. Console: `'mouse' confirmed returned to OK state. Auto-resolving alert...`
The dashboard alert clears itself.

**Proves:** recovery works without pixel-perfect repositioning.

### Test 2.3 — Nudge, don't remove

Slide the mouse ~2 cm and leave it there.

**Expect:** **stays green.** May blink yellow for a moment during the movement itself.

**Proves:** the system tolerates repositioning — a nudged item is not a missing item
(CLAUDE.md §3a).

### Test 2.4 — Repeat for laptop and tumbler

Same three steps. The tumbler is the solid-colour-on-reddish-wood case that used to defeat the
colour-difference fallback; the laptop is the large-dark-item case.

**Expect:** identical behaviour. The laptop should have the widest margin (`here≈1.00` present
vs `≈0.48` removed).

---

## Part 3 — The false alarms that used to fire

### Test 3.1 — Rest your hand on the mouse and hold still ⭐

This is the case `unfixable_limitations.md` §3 called unfixable. Hold **completely still** for
15 seconds — no typing, no twitching. The old motion hack only worked while you were moving.

**Expect:** box turns 🔵 `OCCLUDED: mouse` and **stays there**. Never yellow, never red. Both
scores low (`here≈0.51 gone≈0.52`).

**Proves:** occlusion holds the timers instead of counting toward an alarm.

### Test 3.2 — Type on the laptop for 30 s

**Expect:** green or occasionally blue. Never red.

### Test 3.3 — Walk between the camera and the desk

**Expect:** `SCENE UNSTABLE — holding (xx% motion)` banner, boxes orange, then everything
returns to green. **No alert is created.**

**Proves:** walk-by suppression still works — and note the banner now says *holding*, not
*paused*, which is bug (c) fixed.

### Test 3.4 — Remove an item *while walking past* ⭐ bug (c)

Walk toward the desk, pick up the mouse, and keep walking out of frame in one motion.

**Expect:** RED within ~1–2 s of the scene settling.

**Proves:** the old code zeroed the counter on every motion frame, so this removal was
**never** detected. This is a genuine before/after difference worth demonstrating to the panel.

### Test 3.5 — Lighting change

With everything in place: switch the room lights off/on, or open/close the blinds.

**Expect:** no state changes. Boxes stay green through the transition.

**Proves:** illumination invariance. Measured ±25% brightness moves `here` by less than 0.02.

---

## Part 4 — Verifying the five bug fixes

### Test 4.1 — Bug (a): the crash ⭐ most important regression test

Before this fix, `main.py` **died** the moment a left item appeared.

1. In `pages/desk-sandbox.php`, switch tracking mode to **Unregistered**.
2. Place a large object (a book, a bag) on the desk in open view.

**Expect:** the object gets a cyan box labelled `object1` with a running timer, and
**`main.py` keeps running**. Before the fix, the terminal would show
`TypeError: validate_presence_dnn() missing 1 required positional argument` and the process
would exit.

**Proves:** the script survives its own left-item detector.

### Test 4.2 — Bug (b): Snapshot B ⭐ never once worked before

Snapshot B is your "who took it" evidence, and it has **never fired** for a registered item.

1. Set tracking mode back to **Registered**.
2. Let the mouse go RED (Test 2.1) and leave it red.
3. Now reach your hand into the empty mouse ROI and wave it around.

**Expect:**
```
[INTERACTION DETECTED (Snapshot B)]
  ROI Label    : mouse
  Snapshot B   : ...\uploads\snapshots\mouse_snapshot_B.jpg
```
File exists on disk, and the DB row is updated:
```sql
SELECT detection_id, object_type, snapshot_path, snapshot_path_b
FROM spotit_monitor_db.detections
WHERE room_id='ROOM_1' AND snapshot_path_b IS NOT NULL
ORDER BY detected_at DESC LIMIT 3;
```
`snapshot_path_b` must be non-NULL.

**Proves:** the dual-snapshot evidence chain in CLAUDE.md §3c is now real rather than nominal.

### Test 4.3 — Bug (d): confirmation timing

Remove an item and time it with a stopwatch.

**Expect:** RED at **1.0 s ± 0.3 s**, consistently across 5 repeats. Previously this drifted
because roughly half of every "frame" counted was a re-read of one already counted.

### Test 4.4 — Bug (e): production mode monitors both ⭐

1. Set `detection_mode.json` → `"mode": "production"`.
2. Remove the **mouse** (a registered item) **and** place a book on the desk (an unregistered
   item) in the same session.

**Expect:** the mouse goes RED **and** the book is tracked as `object1` — **both at once**.
Before the fix, production mode returned before the registered loop ever ran, so the mouse
would have been ignored entirely.

Check both appear in one poll:
```powershell
type photos\live_roi_state.json
```
Should contain both `mouse` and `object1`.

**Set `"mode"` back to `"testing"` when done.**

---

## Part 5 — Dashboard integration

With `main.py` running, click through:

| Page | Check |
|---|---|
| `pages/room-monitor.php` | Live feed shows the coloured boxes and `here=/gone=` numbers |
| `pages/dashboard-admin.php` | New detection appears in the event list within ~5 s |
| Alert row | **Verify** / **Dismiss** buttons update `detections.status` |
| Recovered | Marking recovered lets the item re-trigger when removed again |
| `pages/camera-setup.php` | 📷 Capture Frame writes a new baseline and triggers recalibration |

After a **📷 Capture Frame**, the console should re-print the three `[SPOT-IT][CALIB]` lines —
templates are rebuilt automatically, no restart needed.

---

## Part 6 — A/B comparison for Chapter 4

This is the evidence that the ML gatekeeper is doing real work.

Two terminals, same live stream:
```powershell
# Terminal 1 — new pipeline
python main.py ROOM_1

# Terminal 2 — original template-match baseline (deliberately unchanged)
python main_classical_only.py
```

Run each scenario and record both. The expected headline: **remove the mouse from the wooden
desk — new pipeline goes RED, baseline stays GREEN** (the wood-grain false match). And
**rest a still hand on the mouse — new stays safe, baseline goes RED**.

| # | Scenario | Truth | `main.py` | `main_classical_only.py` |
|---|---|---|---|---|
| 1 | mouse removed | missing | | |
| 2 | laptop removed | missing | | |
| 3 | tumbler removed | missing | | |
| 4 | mouse returned | present | | |
| 5 | mouse nudged 2 cm | present | | |
| 6 | still hand on mouse | present | | |
| 7 | typing on laptop | present | | |
| 8 | person walks past | present | | |
| 9 | lights toggled | present | | |
| 10 | removed during walk-past | missing | | |

Then compute TP / FP / FN / TN for each column.

---

## Part 7 — Reading `photos/detection_metrics.csv`

Every decision's inputs are logged, so you never have to guess why a box changed colour.

```
ts, room, label, change_pct, sim_present, sim_empty, used_dnn, state,
absent_time, present_time, scene_motion
```

Rows are written on every **state transition** and at most twice per second otherwise.

| Column | Read it as |
|---|---|
| `change_pct` | how much the ROI changed vs baseline (classical first pass) |
| `sim_present` | match against "item is here" — **≥ 0.85 → PRESENT** |
| `sim_empty` | match against "item is gone" — **≥ 0.65 → ABSENT** |
| `used_dnn` | `1` = MobileNetV2, `0` = chroma fallback |
| `state` | `PRESENT` / `ABSENT` / `OCCLUDED` |

Open in Excel, filter by `label`, and chart `sim_present` and `sim_empty` over time — the
crossover is the moment of removal. That chart is directly usable as a Chapter 4 figure.

**Expected ranges** (measured on your reference frame):

| | present | removed | occluded |
|---|---|---|---|
| `sim_present` | 0.985 – 1.000 | 0.477 – 0.743 | 0.289 – 0.513 |
| `sim_empty` | 0.509 – 0.685 | 0.774 – 0.829 | 0.357 – 0.521 |

---

## Part 8 — Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `[CALIB][WARN] no item found` | Baseline captured with item already gone | Put item back → 📷 Capture Frame → restart |
| `classical templates ready` | ONNX model didn't load | Check `models\mobilenetv2-7.onnx` (~13.6 MB) |
| Item stays green when removed | `sim_empty` not reaching 0.65 | Check CSV; if `sim_empty` ≈ 0.6 lower `SIM_EMPTY` to 0.60 in `main.py` |
| Item flickers red while present | `sim_present` dipping below 0.85 | Check CSV; lower `SIM_PRESENT` to 0.80 |
| Everything blue/OCCLUDED | Both scores low — camera moved since baseline | Re-capture the baseline |
| Nothing ever turns yellow | Classical gate not firing | `change_pct` in CSV should exceed 0.08 on removal; if not, lower `MIN_CHANGE_PERCENT` |
| `Cannot open RTSP stream` | Camera off / different network | `ping 192.168.18.11` |
| All items red at once | Auto-flood gate | Expected if the desk was rearranged — recalibrate |

**Tune from the CSV, not by guessing.** Both thresholds currently sit in the middle of a wide
measured gap, so if one needs moving, the CSV will show you exactly how far.

---

## Sign-off checklist

**Essential — the system is not working without these:**

- [ ] 1.1 Three `[CALIB]` lines, all saying `DNN templates ready`
- [ ] 1.2 Two minutes idle, no false alarms
- [ ] 2.1 Mouse removed → RED in ~1 s (**3× in a row**)
- [ ] 2.2 Mouse returned → GREEN in ~0.5 s
- [ ] 2.4 Laptop and tumbler behave identically
- [ ] 3.1 Still hand → OCCLUDED, never red
- [ ] 3.5 Lights toggled → no state change

**Regression — these were broken before:**

- [ ] 3.4 Removal during walk-past is detected *(bug c)*
- [ ] 4.1 Left item appears, script survives *(bug a)*
- [ ] 4.2 `snapshot_path_b` non-NULL in the DB *(bug b)*
- [ ] 4.4 Production mode tracks registered **and** unregistered *(bug e)*

**Chapter 4 evidence:**

- [ ] Part 6 A/B table filled in for all 10 scenarios
- [ ] `detection_metrics.csv` archived
- [ ] Snapshot A/B pairs saved as evidence-chain examples
