# S.P.O.T.-IT — Detection Pipeline Limitations: Diagnosis & Resolution

This document tracks structural limitations of the hardware, environment, and physical
setup of the DLSU-D CEAT Laboratory Rooms, and — where they have since been solved —
how they were solved.

> **2026-07-30 update.** Sections 1, 2 and 3 were previously logged as *unfixable in
> software*. They have since been resolved by reformulating the presence test rather
> than by re-tuning thresholds. The original diagnoses are kept below because they are
> correct about *why the old approach failed*, and that reasoning is what motivates the
> new design. Section 5 records what genuinely remains a hardware limitation.

---

## 1. The Wood Grain Background False-Match Problem — **SOLVED**

*   **Symptom:** Small items (computer mouse) or dark items (laptop) stayed **GREEN (`OK`)**
    even when picked up and completely removed from the table.
*   **Original root cause (still accurate):**
    *   The baseline item crop contains the object in the centre, surrounded by brown,
        textured wooden desk.
    *   When the item is removed, `cv2.matchTemplate` compares the bare desk against that
        crop. Because the desk texture is present in *both*, correlation reaches $\approx 0.65$.
    *   Since $0.65$ exceeded the presence threshold ($0.52$), the matcher concluded the
        object was still present.
*   **Why threshold tuning could never fix it:** raising the bar above $0.70$ made the box
    turn red under ordinary shadows while the item *was* present. Each adjustment simply
    traded a false negative for a false positive. The defect was in the question being
    asked, not in the number.

### The fix — give the removal a competing hypothesis

The old test was **one-sided**: it only ever asked *"does this look like the item?"*, with
nothing else to compare against. Bare desk scored 0.65 on that question and won by default,
because it was the only question on the table.

At calibration time the system now builds a **second template** by inpainting the item out
of the reference frame (`cv2.inpaint`, Telea), reconstructing the hidden patch of desk from
the texture immediately surrounding it. This is a physically meaningful prediction of what
the camera will see once the item is lifted away. Every live frame is then scored against
**both**:

```
sim_present = similarity(live, "item is here" template)
sim_empty   = similarity(live, "item is gone" template)

sim_present >= 0.85                      -> PRESENT   (item still there)
sim_present <  0.85 and sim_empty >= 0.65 -> ABSENT    (bare surface revealed)
both low                                  -> OCCLUDED  (something in the way)
```

Bare wood can no longer produce a false PRESENT, because it now matches the *empty*
template far better than it matches the item template. The wood is still present in both
crops — it is simply no longer the only thing being compared.

A second refinement matters for small items: the item's true outline is recovered by
differencing the reference crop against its own inpainted version, and all comparisons are
cropped to that **footprint**. The mouse box is ~47% bare desk, and that padding was
diluting the comparison; cropping to the footprint widened the mouse's removal margin from
0.03 to a clean separation.

**Measured on the real ROOM_1 reference frame** (`photos/ref_image_ROOM_1.jpg`), all three
registered items, across present / removed / ±25% lighting / nudged / hand-occluded:

| signal | item present | item removed | hand occluding |
|---|---|---|---|
| `sim_present` | 0.985 – 1.000 | 0.477 – 0.743 | 0.289 – 0.513 |
| `sim_empty`   | 0.509 – 0.685 | 0.774 – 0.829 | 0.357 – 0.521 |

Both thresholds sit in the middle of a wide empirical gap rather than on an edge:
**27/27 scenarios classified correctly.**

---

## 2. CCTV Resolution & Object Size Constraints (The DNN Failure) — **SOLVED**

*   **Symptom:** MobileNetV2 failed to verify presence, turning boxes RED even when items
    were present.
*   **Original root cause (still accurate for *classification*):**
    *   The cropped ROI of a mouse is only ~$80 \times 60$ px.
    *   MobileNetV2 requires $224 \times 224$ input; upscaling that crop produces a blurry,
        pixelated cluster of colour.
    *   The network could not identify features on such a crop, frequently misclassifying
        the mouse as background noise or a "container ship" with low confidence.

### The fix — stop asking the network to name things

The original diagnosis is right that **classification** is hopeless here: the network is
being asked to map a degraded crop onto an ImageNet class it never saw at that resolution.

But *comparing two crops from the same camera* is a different problem. Both crops carry
**identical** blur and identical downsampling damage, so that degradation is common-mode
and cancels in the comparison. The system now takes the penultimate global-average-pool
feature vector — layer `onnx_node!mobilenetv20_features_pool0_fwd`, output `(1, 1280, 1, 1)`
— L2-normalises it, and compares by cosine similarity. The network is used as a learned,
shift- and lighting-tolerant **feature extractor**, never as a recogniser.

Measured on 66×63 crops — precisely the size this section called hopeless:

| comparison | cosine |
|---|---|
| same item, new sensor noise | 1.000 |
| same item, lights dimmed 25% | 0.978 |
| same item, shifted 4 px | 0.996 |
| bare wood | 0.609 |
| hand / sleeve | 0.440 |

Two further points, both load-bearing for the defence:

*   **Scope guardrail intact (CLAUDE.md §8).** No class name is ever emitted. This is
    perceptual similarity, not object-identity recognition.
*   **Genuinely secondary (CLAUDE.md §3a).** The classical absdiff/threshold gate
    (`check_roi`) still decides *whether anything changed*; the network is consulted only on
    crops already flagged. On a quiet frame it never runs at all. If the model is missing,
    `classical_similarity()` — a chroma-only Lab histogram — performs the same three-way
    decision, so detection degrades rather than stopping.
*   **Cheaper than before.** Stopping at the pooling layer skips the classifier head
    entirely, so this costs *less* than the old full forward pass.

---

## 3. Human Occlusion vs. Theft Ambiguity — **RESOLVED BY DESIGN**

*   **Symptom:** When a user typed on the laptop or held the mouse, boxes turned RED or flickered.
*   **Original root cause (still accurate):** a monocular CCTV camera cannot see through a
    hand. The template matcher saw skin/sleeve instead of the item and marked it RED.
*   **Why the old mitigation failed:** suppressing the alarm via local frame differencing
    (`local_motion > 0.05`) worked during *active* typing but failed the moment the user
    rested a hand still on the item — the exact case this section documented.

### The fix — occlusion is not a decision, it is a reason to withhold one

The physical limitation is real and unchanged: **you still cannot see through a hand.** What
changed is that the system no longer has to guess. A hand matches neither the "item here"
template nor the "item gone" template, so it lands in a distinct **OCCLUDED** state which
*holds* the confirmation timers rather than advancing them.

That is the correct epistemic response: while the view is blocked, no evidence is
accumulating in either direction, so no verdict is reached. It does not depend on the hand
moving, which is why the still-hand case is now handled.

The removal-through-hand sequence resolves correctly and in the right order:

```
hand reaches in    -> OCCLUDED -> timers hold
hand leaves w/item -> ABSENT   -> timer accumulates -> RED after 1.0s
```

Displayed as a distinct blue box, so an operator can tell at a glance that nothing is wrong —
someone's hand is simply in the way.

---

## 4. Solid-Colour Background Bleed (Tumbler on Chair) — **SOLVED**

*   **Symptom:** A red tumbler on a reddish-brown wooden chair seat stayed GREEN after removal.
*   **Root cause:** low-edge-density items fell through to a colour-difference fallback, and
    the warm chair seat passed the absolute `color_diff < 12.0` test.
*   **Resolution:** the fallback is gone. The tumbler is handled by the same dual-template
    test as everything else — measured `sim_present` 1.000 present vs 0.614 removed,
    `sim_empty` 0.604 present vs 0.811 removed. Correct in all six lighting/removal variants.

---

## 5. Remaining Genuine Limitations (for Chapter 5)

These are **not** solved and should be documented honestly as findings:

1.  **Total occlusion is undecidable.** If an item is permanently hidden — a bag left on top
    of it for an hour — the system holds in OCCLUDED and reports neither present nor missing.
    This is correct behaviour rather than a bug, but it means the system cannot detect a theft
    that occurs entirely under cover and is never re-exposed. *Recommendation: physical IoT
    weight sensors or RFID under high-value equipment as a redundant modality.*
2.  **Baseline must contain the item.** Calibration derives the item footprint by comparing
    the reference frame against its inpainted version. If the baseline is captured while the
    item is already absent, no footprint is found and the ROI is skipped with a warning. This
    is a workflow constraint on the recalibration UI, not a detection failure.
3.  **ROI boxes must reasonably bound the item.** Detection weakens if a box is mostly empty
    surface. Startup warns when an item fills less than 3% of its box.
4.  **Very large flat items** that fill their entire box leave little surrounding texture for
    inpainting to reconstruct from. The three current test items are unaffected; worth
    re-checking when full-size lab equipment (monitors, UPS) is registered.
5.  **Camera resolution still bounds everything.** The above works at 2K on ~60 px items.
    A 4K camera would extend the same method to smaller items at greater distance.

---

## 6. Reproducing These Measurements

The numbers in §1–§4 come from exercising the real functions in `main.py` (extracted by AST,
so the tested code cannot drift from the running code) against
`photos/ref_image_ROOM_1.jpg`. Removals are simulated with a *different* inpainting algorithm
(Navier-Stokes, radius 9) than the one used to build the template (Telea, radius 5), plus
sensor noise, so the comparison is not circular.

Live-run metrics accumulate in `photos/detection_metrics.csv`:

```
ts, room, label, change_pct, sim_present, sim_empty, used_dnn, state,
absent_time, present_time, scene_motion
```

Rows are written on every state transition and at most twice per second otherwise. This is
the raw material for the Chapter 4 TP/FP/FN/TN table and for the A/B comparison against
`main_classical_only.py`, which still runs the original template-match pipeline unmodified.
