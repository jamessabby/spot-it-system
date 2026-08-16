# A/B Accuracy Protocol — MobileNetV2 Gatekeeper vs Classical-Only

**Purpose:** produce the Chapter 4 evidence that (a) the classical pipeline alone is not
accurate enough, (b) the MobileNetV2 gatekeeper fixes exactly the failures it is supposed to
fix, and (c) the system is still *classically driven* — not an ML-first design like a YOLOv8
detector. Written for the adviser conversation, not just for us.

Companion to [TESTING_GUIDE.md](TESTING_GUIDE.md) Part 6, which this supersedes.

---

## 0. The argument you are actually defending

The adviser question is really two questions, and they need **different evidence**. Don't
answer them with one table.

| Claim | Evidence that proves it | Where it lives |
|---|---|---|
| **1. ML is necessary** | Classical-only arm produces FP/FN on scenarios the gatekeeper arm gets right — on the *same frames* | §4 confusion matrices + §5 McNemar test |
| **2. ML is not the detector** | The network runs on a small crop, only after the classical stage flags a change — measurable duty cycle | §6 duty-cycle + latency table |
| **3. ML choice is proportionate** | MobileNetV2 emits no class labels and costs ~30× less compute per inference than YOLOv8n | §6 + §7 scope argument |

Claim 2 is the one that actually answers *"but isn't this just AI?"* and it is the one we
currently have **no number for**. Get it — it is the cheapest, strongest thing in this
document.

---

## 0.5. RUN IT — exact steps, copy-paste

Everything below is implemented and smoke-tested. Open PowerShell in `c:\xampp\htdocs\spotit`.

**Step 1 — make sure the room you're testing actually has ROIs.**

```powershell
type rois_ROOM_1.json
```

If this prints `[]`, the room has no registered items and **nothing will be scored**. Register
them first (room monitor page → Recalibrate), or test against `DESK`, which currently has
`box`, `watch`, `phone` registered. Whichever room you pick, use the same name for both arms.

**Step 2 — capture a fresh baseline photo, then record the clips.** Same session, same
lighting. One event per clip, ~30 s each, starting with ~5 s of undisturbed scene.

```powershell
mkdir photos\ab_clips -Force
ffmpeg -rtsp_transport tcp -i "rtsp://SpotItCamera:spotitcamera232@192.168.18.14:554/stream1" -t 30 -c copy "photos/ab_clips/s01_mouse_removed.mp4"
```

Write down what really happened in each clip as you record it — that's your ground truth.

**Step 3 — clear old results.**

```powershell
del photos\ab_events_*.csv
```

**Step 4 — replay one clip through both arms.**

```powershell
$env:SPOTIT_SOURCE = "photos/ab_clips/s01_mouse_removed.mp4"

python main.py DESK                     # gatekeeper arm
python main_classical_only.py DESK      # classical baseline arm
```

Each prints its state changes live, e.g. `[A/B] t=  5.07s  box  -> ABSENT`, and finishes with
the ML duty-cycle block. Repeat step 4 for every clip — the CSVs accumulate, so all clips end
up in one pair of files.

**Step 5 — go back to normal.** The env var persists for the whole PowerShell window:

```powershell
Remove-Item Env:SPOTIT_SOURCE
```

Forgetting this is the one easy mistake — `main.py` would keep replaying the clip instead of
watching the camera. It prints `REPLAY MODE` at startup, so check that line.

**Step 6 — read the two CSVs side by side** (`photos/ab_events_gatekeeper.csv` and
`photos/ab_events_classical.csv`), score each clip against your ground-truth notes, and fill in
the matrix in §4.

**Sanity check before you trust anything:** on a clip where the item is removed at a known
time, both arms should report the transition within ~0.1 s of that time. If one arm reports
nothing at all, its ROI file or reference photo is wrong — check the startup lines, both
scripts print exactly which files they loaded.

---

## 1. Parity — already fixed (2026-08-16), here's what changed and why

`main_classical_only.py` had drifted from `main.py`. Run as they were, the two arms differed
in **five ways that had nothing to do with MobileNetV2** — any panelist who read both files
could have thrown the result out. These are now aligned:

| # | Was | Now | Why it would have invalidated the run |
|---|---|---|---|
| 1 | hardcoded `rois.json` + `photos/ref_image.jpg` | room-scoped `rois_{ROOM}.json` + `ref_image_{ROOM}.jpg`, same as `main.py` | The arms were looking at **different boxes against a different baseline**. Not a comparison. |
| 2 | `SCENE_MOTION_LIMIT = 0.40` | `0.20` | Classical arm accepted frames the ML arm rejected. |
| 3 | `CONSISTENCY_FRAMES = 5` | wall-clock `MISSING_CONFIRM_SEC = 1.0` | 5 frames ≠ 1.0 s. Different debounce = different false-alarm rate, independent of ML. |
| 4 | timers **reset** on an unstable frame | timers **hold** | Resetting is a known false-negative path (BUGS.md 2026-07-30 (c)) — removing an item is itself motion. Leaving it in would have penalised the baseline for a bug unrelated to the ML gate, flattering our system dishonestly. |
| 5 | posted events to `detections` as room `DESK` | posting **off** unless `SPOTIT_CLASSICAL_POST=1` | Baseline-arm events landed in the live table, indistinguishable from real ones. |

**What was deliberately NOT changed:** `find_item_template_match()`, `check_roi()`, the
fixed-asset keyword shortcut, `MATCH_SCORE_THRESHOLD = 0.55`, and the decision logic that uses
them. `THRESHOLD = 25` and `MIN_CHANGE_PERCENT = 0.08` were already identical in both files —
that shared classical front-end is the whole point. The only difference between the two arms
is now the presence test itself.

> CLAUDE.md §9 marks this file "deliberately left unchanged." That means *don't improve its
> detection logic* — aligning its inputs and debounce is the opposite of tampering, it's what
> makes a control a control. **Say this in one sentence in the manuscript** so it can never
> look like the baseline was tuned to lose. The full rationale is in the file's own header
> comment, which is the right thing to show an adviser who asks.

One loose end worth closing: `services/migration_accuracy_trials.sql` is referenced in
CLAUDE.md §7a but **does not exist in the repo** — the `accuracy_trials` table was created by
hand in phpMyAdmin. Export a `CREATE TABLE` into that path, or the tally is unreproducible on
a fresh install.

---

## 2. Protocol A — recorded-clip replay *(primary; this is the defensible one)*

Both pipelines see **byte-identical frames**. This is what makes the comparison *paired*,
which is what lets you run a real significance test in §5 instead of eyeballing two
percentages.

It also sidesteps a trap: the Tapo TC65 limits concurrent RTSP clients, and `main.py` silently
falls back from `/stream1` (2304×1296) to `/stream2` (640×360) when the main stream is locked
([main.py:527-529](../main.py#L527-L529)). Run both scripts live at once and there is a real
chance **one arm is quietly running at a fifth of the other's resolution**. You would never see
it in the console, and it would wreck the result.

### A1. Record the scenario clips

```powershell
# From c:\xampp\htdocs\spotit — one clip per scenario, camera and lighting untouched between takes
ffmpeg -rtsp_transport tcp -i "rtsp://SpotItCamera:spotitcamera232@192.168.18.14:554/stream1" `
       -t 30 -c copy "photos/ab_clips/s01_mouse_removed.mp4"
```

Rules that keep the clips admissible:
- Record the **baseline reference frame from the same session** — do not reuse a week-old
  `ref_image_ROOM_1.jpg`. Lighting drift between baseline and clip is a confound that hits
  both arms unequally.
- Every clip starts with ~5 s of the undisturbed scene, so both arms settle into GREEN before
  the event.
- One event per clip. Two events in one clip make the confusion-matrix bookkeeping ambiguous.
- Log ground truth in a text file **as you record** (`s01: mouse removed at t≈12s`), not from
  memory afterwards.

### A2. Replay each clip through both arms

`SPOTIT_SOURCE=<path>` is implemented in both scripts. Setting it replays the clip instead of
opening the camera, and **automatically disables all API posting** — a scoring run can never
write into the live detections table.

```powershell
$env:SPOTIT_SOURCE = "photos/ab_clips/s01_mouse_removed.mp4"

python main.py ROOM_1                    # arm B — gatekeeper
python main_classical_only.py ROOM_1     # arm A — baseline
```

Each run prints its state changes with clip timestamps and appends them to
`photos/ab_events_gatekeeper.csv` / `photos/ab_events_classical.csv` — same schema
(`clip,arm,label,state,clip_time_sec`), so the two files read straight across against each
other. Each run also prints the ML duty cycle (§6a) on exit.

Replay reads the file **one frame per detection cycle** (synchronously — a threaded reader
would race to EOF while the loop was still on frame 3, and most of the clip would never be
scored), and advances the confirmation timers by *the clip's own frame interval* rather than
wall-clock. So `MISSING_CONFIRM_SEC = 1.0` means one second of recorded footage no matter how
fast the file decodes, and replay timings are directly comparable to live ones. Verified: on a
clip where the item disappears at t=5.00 s, both arms report the transition at t=5.07 s.

**Clear the CSVs before a fresh scoring session** (`del photos\ab_events_*.csv`) or runs will
accumulate in the same files.

---

## 3. Protocol B — live side-by-side *(for the demo, not the statistics)*

For the defense itself, run the two arms live in two terminals. To dodge the stream-lock
problem above, point the classical arm at `/stream2` explicitly and **say out loud that the
resolutions differ**, or run the arms sequentially over the same staged scenario. The live run
is theatre — persuasive theatre, but your numbers come from Protocol A.

The money shots, both of which follow directly from the dual-template design (CLAUDE.md §3):

1. **Remove the mouse from the wooden desk.** Gatekeeper → RED. Classical-only → stays GREEN,
   because bare wood grain template-matches the mouse crop (which was itself mostly desk) at
   ~0.65 and wins by default. *This is the false negative that a one-sided similarity test
   cannot fix with any threshold.*
2. **Rest a still hand over the mouse.** Gatekeeper → OCCLUDED, holds, no alert. Classical-only
   → RED false alarm.

Those two are the entire argument in 40 seconds of demo. Rehearse them.

---

## 4. Scenario matrix and scoring

**Positive = "item absent"** (a deviation event), matching
[auth/log_sandbox_trial.php:34-45](../auth/log_sandbox_trial.php#L34-L45). Keep that mapping —
your existing tally UI already encodes it.

You need **negatives, and plenty of them.** A matrix of only removals measures recall and
nothing else, and recall alone can't distinguish a good detector from one that screams
constantly. Aim for roughly half the trials to be "nothing was taken" cases.

| # | Scenario | Truth | Tests |
|---|---|---|---|
| 1 | Mouse removed | absent | TP — the wood-grain FN case |
| 2 | Laptop removed | absent | TP |
| 3 | Tumbler removed | absent | TP |
| 4 | Item removed, returned within 5 s | absent→present | recovery / auto-resolve |
| 5 | Keyboard nudged ~3 in, still in ROI | **present** | FP — repositioning tolerance |
| 6 | Still hand resting on item | **present** | FP — occlusion hold |
| 7 | Person walks between camera and desk | **present** | FP — transient motion |
| 8 | Room lights dimmed ~25 % (projector) | **present** | FP — illumination |
| 9 | Chair/bag placed beside item, item untouched | **present** | FP — scene clutter |
| 10 | Item swapped for a different item, same zone | **present** | scope honesty — *both* arms should say present (§7) |

**Repeat every scenario ≥3× per arm.** 10 scenarios × 3 reps × 2 arms = 60 trials, ~90 minutes.
Single-rep results are anecdotes; a panelist asking "did you run it more than once?" should get
a yes.

Log each trial via the existing sandbox page (`pages/desk-sandbox.php`) → it computes accuracy,
precision, recall for you. **Caveat:** `accuracy_trials` has **no column identifying which
pipeline produced the trial**, so the two arms will be summed together into one meaningless
number. Two options:

- *Quick:* run arm A fully → screenshot the metric cards → use the page's `clear` action
  (truncates the table) → run arm B. Crude but zero code.
- *Better:* add a `variant ENUM('classical','gatekeeper')` column and group by it. ~15 lines
  across the handler and the page, and it makes the comparison reproducible instead of a
  screenshot.

Build one 2×2 per arm:

|  | Detected absent | Detected present |
|---|---|---|
| **Truly absent** | TP | FN |
| **Truly present** | FP | TN |

Report accuracy `(TP+TN)/N`, precision `TP/(TP+FP)`, recall `TP/(TP+FN)`, F1. **Report all
four.** Accuracy alone hides the trade — and the honest expected story is that the classical
arm has *lower precision* (false alarms on nudges/hands) and *lower recall* (the wood-grain
miss), so you don't need to spin anything.

---

## 5. The significance test — use McNemar, not two accuracy percentages

Because both arms saw identical frames, the trials are **paired**, and comparing two accuracy
figures side by side throws that away. McNemar's test is the correct one, takes two numbers,
and is computable by hand:

Tabulate the 60 paired trials by whether each arm was right:

|  | Gatekeeper correct | Gatekeeper wrong |
|---|---|---|
| **Classical correct** | a | b |
| **Classical wrong** | **c** | d |

Only the disagreements matter. With `b + c ≥ 25`, χ² = (|b − c| − 1)² / (b + c), df = 1,
significant at p < 0.05 when χ² > 3.84. Below 25 disagreements — likely, at 60 trials — use the
exact binomial: p = 2 × P(X ≤ min(b,c)) for X ~ Binomial(b + c, 0.5). Example: b = 1, c = 9 →
p = 0.021, significant.

One sentence in Chapter 4 — *"the gatekeeper corrected 9 cases the classical pipeline got
wrong while breaking 1 (McNemar exact, p = 0.021)"* — is worth more than a page of percentages,
and it is the kind of thing advisers notice.

---

## 6. Claim 2 evidence — prove the ML is *secondary*

This is the part that answers *"not too AI-reliant."* Two measurements, both cheap.

### 6a. Duty cycle — how often the network actually runs

**Implemented and always on** — both scripts print this block on exit (Ctrl-C included):

```
====================================================================
  ML DUTY CYCLE  (evidence that the ML stage is secondary)
====================================================================
  ROI-frames handled by classical stage : 675 (100.0%)
  ...flagged as changed (ML eligible)    : 150 (22.22%)
  MobileNetV2 forward passes            : 150 (22.22%)
  Mean latency per forward pass         : 19.6 ms
  Total time inside the network         : 2.94 s
====================================================================
```

Startup calibration passes are excluded from the count — those are a fixed startup cost, and
including them would overstate the duty cycle.

**Get this number from a real 30-minute room session, not from a clip.** A test clip is mostly
event, so its duty cycle is meaningless (the 22 % above is from a synthetic plumbing test where
the item is missing for two-thirds of the footage). A real session is mostly a quiet room, and
the figure should land in the low single digits. The sentence it buys you:

> *"Across a 30-minute session, the classical stage handled 100 % of ROI-frames; the
> MobileNetV2 feature extractor was invoked on 2.8 % of them, exclusively on crops the
> classical stage had already flagged as changed."*

### 6b. Cost per inference — measured, on the actual mini-PC

Mean forward-pass latency is printed by the same summary block. Run it on the deployment
mini-PC, not your dev laptop, and fill this in with **your** numbers:

| | MobileNetV2 (ours) | YOLOv8n |
|---|---|---|
| Model file on disk | **13.6 MB** (`models/mobilenetv2-7.onnx`, verified) | ~6.2 MB |
| Parameters | ~3.4 M | ~3.2 M |
| Compute per inference | ~0.3 GFLOPs | ~8.7 GFLOPs (**~29×**) |
| Input | one ~100×100 ROI crop | full 640×640 frame |
| Runs on | flagged frames only (§6a) | every frame |
| Output | 1280-d feature vector | class labels + boxes |
| Measured latency (dev laptop, CPU) | **19.6 ms** — re-measure on the mini-PC | — |

**Be precise, not rhetorical.** YOLOv8n is *not* a huge model — it has roughly the same
parameter count. The honest argument is not "YOLO is bloated," it is **compute per inference
(~29×) × frames processed (every frame vs ~3 %)**, which compounds to two-plus orders of
magnitude of work avoided. Overstating this is the one thing that could actually lose you the
point, because a panelist who knows YOLOv8n's size will catch it and then doubt the rest.

---

## 7. The scope argument — why MobileNetV2 is the *right* amount of ML

Three points, in this order:

1. **It is not used as a classifier.** We take the penultimate 1280-d pooled feature vector
   ([main.py:337](../main.py#L337)) and compare two crops from the same camera by cosine
   similarity. **It never emits a class name.** ImageNet cannot usefully label an 80×60 CCTV
   crop, and we don't ask it to. This is what keeps the CLAUDE.md §8 no-identity-recognition
   guardrail strictly intact — a YOLO detector would *have* to emit COCO class labels, which
   is precisely the scope the thesis rules out.
2. **It answers a question the classical stage provably cannot.** Absdiff cannot separate *"the
   item was removed"* from *"a hand is covering it"* — both read as "not the item." The
   embedding separates them: measured **0.98** for the item under lighting change vs **0.44**
   for a hand. That single disambiguation is the gatekeeper's entire job, and scenario 6 in
   §4 is its proof.
3. **It degrades instead of failing.** With the model absent, `classical_similarity()`
   (chroma-only Lab histogram) makes the same three-way decision. The system does not stop
   without the network — which is the structural definition of *secondary*. Demonstrate this
   live by renaming `models/mobilenetv2-7.onnx`; detection keeps running, less accurately.
   A YOLO-based design cannot do that, because there is nothing underneath it.

Anticipated question — **"why not just train YOLOv8 on your lab items?"** Answer: it needs an
annotated dataset of DLSU-D CEAT lab equipment that does not exist, it would perform identity
recognition the thesis explicitly excludes, and it solves a problem we don't have — we already
know where every registered item *should* be, from `rois.json`. We need presence in a known
box, not detection of unknown objects in an unknown scene. Using an object detector here would
be answering a harder question than the one asked, at 29× the cost.

---

## 8. What goes in Chapter 4

- **Table:** per-arm confusion matrix + accuracy / precision / recall / F1 (§4)
- **Table:** per-scenario outcome, both arms side by side (§4 matrix, filled)
- **Test:** McNemar result with the disagreement counts spelled out (§5)
- **Table:** duty cycle + cost comparison (§6)
- **Figure:** the two demo scenarios as before/after screenshots, both arms (§3)
- **Figure:** `sim_present` vs `sim_empty` scatter from `detection_metrics.csv`, showing the
  0.65–0.85 decision gap is a real separation and not a tuned-to-fit threshold
- **Appendix:** archived `detection_metrics.csv` + the clip set

---

## 9. Ways a panelist could invalidate this — close them in advance

| Risk | Close it by |
|---|---|
| "You tuned the baseline to lose" | Baseline presence logic untouched; only inputs and debounce aligned (§1). Show the diff. |
| "Different frames, different results" | Protocol A replays identical files (§2). |
| "One run proves nothing" | ≥3 reps per scenario (§4). |
| "Only tested removals" | Half the matrix is negatives (§4). |
| "Percentages aren't significance" | McNemar (§5). |
| "The ML is really doing the work" | Duty cycle (§6a) + model-absent fallback demo (§7.3). |
| "Baseline used a stale reference image" | Baseline captured in the same session as the clips (§2). |
