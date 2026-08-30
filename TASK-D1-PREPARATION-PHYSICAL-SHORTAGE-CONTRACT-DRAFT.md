# D1 — DRAFT CONTRACT: Fulfillment Truth vs. Physical Preparation Truth

**Status: DRAFT FOR OWNER CONFIRMATION. Nothing implemented. No ADR, schema, or code changed.**
Restated from the owner's instruction of 2026-08-20. Confirm or correct before I touch anything.

---

## 1. The principle

`allow_negative_stock` is an **execution permission**, not a statement about physical stock. It decides whether an order may be *committed*; it must never make a physical shortage *invisible*.

Two truths are tracked separately, and neither overwrites the other:

| | **Fulfillment Truth** | **Physical Preparation Truth** |
|---|---|---|
| Question | *May this customer order be committed?* | *What must physically be supplied to prepare it?* |
| Authority | `ManufacturingAvailabilityService` (ADR-027 §16.3/§19) | `MaterialDemandCalculator` → `wave_material_demand` (ADR-027 §18) |
| `allow_negative_stock` | **Satisfies** the recipe requirement | **Does NOT** offset the physical deficit |
| Consumer | Reservation → order lifecycle | Preparation OS / procurement |

## 2. The worked example (your case)

```
Required = 1 · On Hand = 0 · allow_negative_stock = true
```

| Output | Value | Owner |
|---|---|---|
| Recipe Executable | **true** | ManufacturingAvailabilityService |
| Finished Product Fulfillable | **true** | Reservation |
| Order | **reservable → In Progress** | Order lifecycle |
| Preparation: Required | **1** | MaterialDemandCalculator |
| Preparation: Physical Available | **0** | MaterialDemandCalculator |
| Preparation: **Missing Qty** | **1** | MaterialDemandCalculator |
| Coverage | **0%** (true value, not forced to 100%) | MaterialDemandCalculator |

The material appears in **Missing Materials** so preparation/procurement know exactly what to supply — while the order still proceeds.

## 3. What changes

**One line of logic is deleted**, plus its ADR clause:

- `MaterialDemandCalculator.php:257-260` — the override that currently forces `$missing = 0.0; $coveragePct = 100.0;` whenever the material has `allow_negative_stock = true`. **Removed.** The arithmetic immediately above it (`:244-248`) already computes the correct `available = 0, missing = 1, coverage = 0`; it is only overwritten afterwards.
- `wave_missing_materials` then populates automatically — `MissingMaterialCalculator` already selects `missing_qty > 0`; no change needed there.
- **UI:** the Raw Materials tab badge must key on **physical stock**, not on `missing_qty` (today a zero-stock material renders a green "Sufficient" badge). The Missing Materials tab needs no logic change — the row will simply appear.

**Explicitly NOT changed:**
- `ManufacturingAvailabilityService` — `available > 0 OR allow_negative_stock` still makes the recipe executable. **Fulfillment is untouched.**
- Reservation semantics, ADR-027 §19 made-to-order contract, `can_manufacture`.
- The §18.2 clamps: `available = max(0, on_hand − effective_reserved)` and `missing = max(0, required − available)`. **Missing still never exceeds Required.**
- The `own_active_member` / `postponed_member` netting (§18.3).

## 4. ADR impact — this contradicts an approved clause

ADR-027 **§18.4** currently states that a material on open credit *"never blocks preparation and never enters the procurement/missing queue."* This contract **reverses the second half**: it must enter the missing queue (visibility), while still **not blocking** fulfillment (the first half stands).

So §18.4 needs an amendment, not a deletion — I will draft it as a v1.6 revision preserving the history, once you confirm.

## 5. ⚠️ Consequence you must weigh before confirming

`StartPreparationAction.php:48` blocks starting a wave when `shortage_detected` is true (unless explicitly overridden):

```php
if ($wave->shortage_detected && ! $dto->overrideShortage) { … }
```

- **Today this is inert** — `shortage_detected` is a dead flag whose only writer (`AnalyzeMaterialsAction`) is broken and cannot run. So **D1 on its own is display-only and blocks nothing.**
- **But if D2 is also approved** (pointing `shortage_detected` at the demand engine), then every wave containing an allow-negative material would start **blocking on wave start**. An order that is deliberately fulfillable on credit would stop its own preparation wave.

**Recommendation:** approve **D1 alone first** (visibility, zero operational risk). Treat "should a physical shortage covered by credit block wave start?" as a separate decision when D2 is taken — my suggestion would be that credit-covered shortages are **visible but non-blocking**, which preserves both halves of your principle.

## 6. Where it applies — exact scope

| Layer | File | Change |
|---|---|---|
| Calculator | `MaterialDemandCalculator.php` | delete the `allow_negative` override (`:257-260`) |
| Projection | `MissingMaterialCalculator.php`, `DemandReadRepository.php` | none (follows automatically) |
| API | `WaveDemandController.php` | none |
| UI | `wave-raw-materials-page.tsx` | badge keyed on physical stock, not `missing_qty` |
| UI | `wave-missing-materials-page.tsx` | none (row appears); empty-state copy only if you want it reworded |
| ADR | `ADR-027` §18.4 | amend to "visible in the missing queue, still non-blocking" |
| Tests | new regression | Required=1 / OnHand=0 / allow_negative=true ⇒ recipe executable **and** missing_qty=1 |

## 7. Open question for you

`wave_material_demand` for the live wave is **stale** (`reserved_qty=1` recorded at 23:33:02; live inventory is `2`, set at 23:35:22 by ORD-00008 — and no listener recalculates on a reservation by a non-member order). Recalculating will move numbers on screen.

**Do you want the recalculation triggered as part of this change, or handled separately?** (A missing invalidation listener is a defect in its own right — reported under Track 1 item 3.)

---

**Confirm this restatement (or correct it) and I will implement exactly this scope — D1 only, unless you also approve D2.**
