# TASK-ORDERS-BUSINESS-MODEL-AUDIT-001 — Order-Driven Preparation vs. Current Implementation

**Status:** AUDIT ONLY — no inventory/reservation code written. Requested before any inventory implementation.
**Production untouched. DEV DB not reset. ORD-00001/00002 intact. No destructive git ops.**
Date: 2026-08-20. Environment: DEV.

---

## 0. Why this audit exists (two owner directives conflict)

- **Directive A (previous "OWNER DECISION"):** *"Honey Jar … can_manufacture = false. Therefore it MUST remain Awaiting Stock … can_manufacture remains a gate for the manufacturing path."*
- **Directive B (this "BUSINESS MODEL CORRECTION"):** *"Honey Jar … Recipe executable → Product fulfillable → Order In Progress → Preparation … FG stock = 0 is NOT sufficient to produce Awaiting Stock,"* and *"Do NOT automatically make can_manufacture the gate."*

These cannot both be implemented. The audit below resolves what `can_manufacture` actually is, so the owner can pick the reconciliation. **Nothing is implemented pending that decision.**

## 1. Exact existing architecture (the real operational chain)

```
Order ─► ReserveOrderInventoryAction ─► (reserved) ─► MoveToPreparationWorkflow
        (availability decision)                        └─► PrepareOrderManufacturingAction
                                                             └─► ManufacturingLifecycleHandler
                                                                  └─► ManufacturingApplicationService
                                                                       └─► ManufacturingExecutor
                                                                            • consumeComponent (RM ↓)
                                                                            • produceFinishedGoods (FG on_hand ↑, FIFO layer)
        Preparation OS (waves/sessions/pick-lists/PreparedProductsPool) = batching/picking/pooling ONLY
```

- **The codebase ALREADY implements order-driven fulfillment.** A zero-FG-stock order can reach `reserved` via recipe-executability, and RM→FG production runs when the order enters Preparing. There is **no** manufacture-to-stock path independent of a customer order (production qty = per-order shortfall, tagged `trigger_type: 'order_lifecycle'`).
- **"Preparation OS" (`Modules/Operations/Preparation`) does NOT consume raw materials or create finished goods.** Per ADR-027 §5 it MUST NOT consume/release reservations. It records `quantity_prepared`, builds a `PreparedProductsPool` keyed by `(wave, product, warehouse)` — **not order-tied** — and holds soft reservations only. The actual RM→FG production is done by **`Modules/Manufacturing`** (`ManufacturingExecutor::produceFinishedGoods`, increments `inventory_items.on_hand`).
- **Manufacturing and Preparation are separate modules.** ADR-015 (`docs/architecture/ADR-015…`) *intended* Preparation to "replace Manufacturing OS," but the code kept both: Manufacturing produces, Preparation batches. (ADR-010 / ADR-015 do not exist under `docs/adr/`; the governing ADR is **ADR-027**.)

## 2. Exact role of `can_manufacture` — meaning (B), a hard gate in TWO places

Determined by full-codebase audit: **`can_manufacture` = permission to assemble/prepare a sellable product from its recipe to fulfil a customer order** (build/assemble-to-order permission). Not production-into-stock. Evidence: no order-independent production path exists; the flag's only live effects are on the order lifecycle.

- Default **`false`** (migration `2026_06_29_000001…:25`); **not rendered by the standard product form** (set only via a separate recipe-eligibility toggle / PATCH — `ProductController.php:146-149`).
- **Gate #1 — Reservation:** `ReserveOrderInventoryAction.php:191` — `if ($product?->can_manufacture && $this->manufacturingIsExecutable($product))`. False ⇒ Case 2 skipped ⇒ Awaiting Stock.
- **Gate #2 — Production:** `ManufacturingPolicy.php:98` Rule 3 — `if (! $product->can_manufacture) return ineligible(ProductCannotManufacture)`. False ⇒ the line is `Skipped`, so even a reserved order would produce nothing.
- **Live data: `can_manufacture = false` on 100% of products (all 5, incl. all 3 finished goods).** So today **no recipe-backed finished good can be fulfilled OR produced** — every such order goes Awaiting Stock.

## 3. Exact role of Recipe / Recipe Executability

- **`ManufacturingAvailabilityService::evaluate()`** is the **single authority** for "recipe executable" (ADR-027 §16.3). Rule (identical to the owner's contract): a material is fulfillable when `available > 0 OR allow_negative_stock = true`; recipe executable ⇔ every component fulfillable. Company-scoped, fail-closed. **It does NOT read `can_manufacture`.**
- `ActiveRecipeResolver` picks the one active BOM; `InventoryAvailabilityEngine` computes per-order shortfall; `DecisionKernel` is a generic rule engine (no flag).

## 4. Exact role of Preparation
Operational batching/picking/pooling of already-reserved orders into waves/sessions; records prepared quantities into an enterprise `PreparedProductsPool`. Does not touch the inventory ledger, does not consume RM, does not create FG (ADR-027 §5). ADR-027 §18 only floors the *wave-view RM-availability projection* at 0.

## 5. Where Product Fulfillability / Order availability is decided
- **Order availability decision:** `ReserveOrderInventoryAction::execute()` (Cases 1 physical-FG / 2 manufacture-to-order / 3 negative-stock / 4 awaiting). This is the authoritative fulfillability decision for an order.
- **Recipe executability:** `ManufacturingAvailabilityService::evaluate()`.
- **Products-page badge (display only):** a *separate* SQL calc (`EloquentProductRepository` `manufacturing_availability`) reflecting recipe buildability — this is what mislabels a zero-FG-stock FG "In Stock" (the original Part-1 finding). It is NOT the order availability decision.

## 6. THE GAP (current implementation vs. the order-driven-preparation model)

The single gap is the `can_manufacture` gate. The reservation engine already answers *"physical FG stock OR recipe executable?"* — but only credits recipe executability when `can_manufacture = true`. Because the flag is universally `false`, the order-driven-preparation model is **inert** in practice (Honey Jar → Awaiting Stock).

**Critical structural fact for any fix:** `can_manufacture` gates production in **two** places (reservation Case 2 **and** `ManufacturingPolicy` Rule 3). So bypassing it only at reservation would let an order reserve but then produce **nothing** at Preparing (Rule 3 rejects). Any "recipe-alone" approach must address **both** gates + ADR-027 §3/§16, not one line.

## 7. Reconciliation — two coherent options (owner decision required)

Both make Honey Jar fulfillable, satisfying Directive B; they differ on *how*, and on whether `can_manufacture` remains a required permission (Directive A).

**Option 1 — `can_manufacture` is the prepare-to-order permission; make it true (master-data, ZERO code/ADR change).**
- Treat the flag as intended (meaning B). Set `can_manufacture = true` for Honey Jar (and every prepared-to-order FG). Then reservation Case 2 **and** ManufacturingPolicy Rule 3 both pass ⇒ Honey Jar fulfillable ⇒ In Progress ⇒ production consumes RM ⇒ FG.
- Reconciles BOTH directives: Directive A's *"stays Awaiting Stock while flag false, recovers when an operator sets it true"* is literally the behavior; Directive B's *"Honey Jar fulfillable"* is achieved by setting the flag. Directive A even pre-specified the flag-flip auto-recovery.
- Work: the two recovery triggers already approved (RM-stock exists; add `allow_negative_stock` + `can_manufacture` flip events → existing `RetryReservationOnStockAvailableListener`), the Product-UI concept separation, and setting the master-data flag. No reservation-engine change, no ADR amendment.

**Option 2 — remove the permission gate; recipe-executability alone drives fulfilment (architecture change).**
- Make recipe executability sufficient at BOTH gates. Requires: amend ADR-027 §3 Case 2/§16; change `ReserveOrderInventoryAction:191`; change `ManufacturingPolicy` Rule 3; decide the fate of `can_manufacture` (retire or repurpose). Honey Jar fulfillable immediately, no data change.
- Matches Directive B's *"do not make can_manufacture the gate"* literally, but contradicts Directive A and is a multi-gate, ADR-amending change.

**Recommendation:** Option 1 — it satisfies both directives, requires no ADR amendment or reservation-engine change, and matches the audited meaning of `can_manufacture` (permission-to-prepare). But this is the owner's call and I will not proceed until it is made.

## 8. STOP-condition status
- Second recipe engine? No (single authority exists). ✓
- Direct order-status writes / new reservation engine / lifecycle bypass / mass polling / cross-tenant? Not required by either option (recovery reuses `ProcessOrderWorkflow` via the existing scoped listener). ✓
- Contested shared file? Inventory targets are the standing dirty tree (mtimes Aug 15–19, not churning). ✓

## 9. GPS (unrelated, already complete)
Code-complete; 8/8 tests, Pint/PHPStan/tsc/ESLint green. Browser smoke pending a signed-in session.

---

# ADDENDUM — Completed Deep Audit (the 12 questions)

**Concept glossary (these are NOT the same thing):**
- **Finished-Product physical stock** = `inventory_items.on_hand` for the FG (Honey Jar = 0).
- **Raw-Material availability** = RM `on_hand − reserved` (Honey 99.5, Jar 498).
- **Recipe executability** = `ManufacturingAvailabilityService::evaluate()`: every component `available>0 OR allow_negative_stock` (Honey Jar recipe = **EXECUTABLE**).
- **Product fulfillability** = Case 1 physical FG **OR** Case 2 (`can_manufacture` AND recipe executable) **OR** Case 3 (FG allow_negative).
- **Order reservability** = whether `ReserveOrderInventoryAction` commits (reserved/partial) vs awaiting_stock.
- **Preparation eligibility** = `PreparationReleaseEngine`/entry gates, downstream of reservation.

**1. Original meaning of `can_manufacture`.** Introduced by **TASK-MFG-IMP-001 PKG-01 "Product Foundation — manufacturing fields"** (commit `48513309`, 2026-06-29). Migration comment: *"Whether the product can be produced via a manufacturing recipe."* Model docblock: *"Has a recipe and may be produced."* A per-product capability/permission flag; base default `false`; the factory sets it via `->manufacturable()`.

**2. Intended for (a/b/c/d).** **(b) order-time preparation/assembly.** No order-independent production path exists anywhere; the only production is per-order-shortfall assemble-to-order, tagged `trigger_type: 'order_lifecycle'`. It is not (a) manufacture-into-stock.

**3. Why Reservation gates on it.** Deliberately added in commit `ec43b470` (2026-08-15), *"ship the ADR-027 §16/§17 reservation chain,"* implementing **ADR-027 §16 "Option B" (owner-approved 2026-08-09)**. The in-code comment calls it *"Option B gate: manufacturing may only be committed when the recipe is executable."*

**4. Consistent with ADR-027, or old/incorrect?** **Consistent and current** — it is exactly ADR-027 §3 (decision-tree line 183: `can_manufacture = true → Case 2`) plus §16 Option B. Shipped 11 days ago. **Not** an outdated implementation.

**5. What `ManufacturingAvailabilityService` represents.** **Recipe executability** (ADR-027 §16.3: *"the only engine that decides recipe availability"*) — i.e. generic material feasibility of the recipe (`available>0 OR allow_negative`). It is **not** manufacturing-into-stock feasibility and **not** the `can_manufacture` permission; it does **not** read `can_manufacture`.

**6. Is Preparation OS the correct execution engine here?** No — not for producing the customer-ready product. Preparation OS batches/picks/pools and **must not** consume inventory (ADR-027 §5). The actual RM→FG assemble-to-order runs in **`Modules/Manufacturing`** (`ManufacturingExecutor`) during the Preparing transition. ADR-015 intended Preparation to replace Manufacturing OS; the code kept both.

**7. Does Reservation already have the correct recipe/material decision tree apart from the gate?** **Yes.** Case 1 physical FG → Case 2 recipe-executable → Case 3 allow_negative → Case 4 awaiting. The full RM-availability-OR-allow_negative executability tree is present and correct; the **only** extra condition is the `can_manufacture &&` precondition on Case 2.

**8. Today with FG=0, recipe exists, all RMs available, `can_manufacture=false`.** Case 1 fails (FG 0) → Case 2 skipped (flag false) → Case 3 skipped (FG allow_negative false) → **Case 4: awaiting_stock**, `reservation_failure_reason = 'Insufficient Inventory'`. (Exactly ORD-00002.)

**9. If the `can_manufacture` gate were simply removed.** Two-part, and the second part is the trap:
   - Reservation Case 2 would fire on the executable recipe → order reserves → In Progress.
   - **But `can_manufacture` also gates production** at `ManufacturingPolicy` Rule 3. At the Preparing step the line is `PolicyRejected → manufacturing_state = Skipped`: **no RM consumed, no FG produced.** The order would sit reserved/In Progress with RM reserved but never assembled; at shipment, issuing 0-on-hand FG fails unless allow_negative. **Removing only the reservation gate yields a broken half-state.** Enabling made-to-order for `can_manufacture=false` products requires changing **both** gates (Case 2 **and** Rule 3) and amending ADR-027 §16.

**10. Affected ADRs / contracts / tests.** ADR-027 §3 + §16 (Option B); `ManufacturingPolicy` Rule 3 (+ `ManufacturingPolicyTest`); **`RecipeToOrderAvailabilityE2ETest`** (all recipe-fulfillment tests build the FG with `->manufacturable()` = `can_manufacture=true`; `test_e` is explicitly *"does can_manufacture bypass the recipe"* → asserts awaiting_stock for an unexecutable recipe); plus ~18 further test files referencing the flag / awaiting_stock (OrderAvailabilityLifecycleContract, OrderManufacturingIntegration, Rc10LifecycleCertification, NegativeStockReservation, PreparationEntryGate/BypassGuard, …).

**11. Is there an approved contract that explicitly makes `can_manufacture` gate recipe-based order fulfillment?** **Yes** — three, mutually reinforcing: ADR-027 §3 line 183 + §16 "Option B" (owner-approved 2026-08-09); `ManufacturingPolicy` Rule 3; and `RecipeToOrderAvailabilityE2ETest`, whose entire premise is `can_manufacture=true` (factory `manufacturable()`), with `test_e` naming the flag as the decisive gate.

**12. Current intended model or outdated assumption?** **Current, recent, deliberately owner-approved** (approved 2026-08-09; shipped 2026-08-15). It is NOT a legacy manufacturing artifact. However, it embeds one assumption that collides with the just-clarified made-to-order model: it treats `can_manufacture=true` as a **required per-product opt-in** for recipe-based fulfilment. In a business where *every* finished product is prepared-to-order, that opt-in is redundant unless some FGs are deliberately non-preparable. The live data (`can_manufacture=false` on all FGs) is itself **inconsistent with the approved contract's own test premise** (recipe-backed FGs = `manufacturable()` = true).

## Semantic conclusion (no recommendation, per instruction)

`can_manufacture` = **a per-product opt-in permission that this finished product may be prepared/assembled to order from its recipe.** It is meaning **(b)**, it gates **both** reservation and production, it does **not** bypass recipe executability, and the gate is a **current owner-approved contract (ADR-027 §16 Option B)**, not an outdated manufacturing assumption. The reservation's recipe/material decision tree is otherwise exactly the made-to-order tree the owner described.

Therefore the made-to-order behaviour the owner wants is reachable in exactly two ways, and both are genuine contract-level choices for the owner (not defects to silently fix):
- **Honor the approved opt-in:** the flag stays the gate; a recipe-backed made-to-order FG is expected to carry `can_manufacture=true` (per the contract's own test premise). No code/ADR change; the gap is data.
- **Revise the approved contract:** make recipe executability alone sufficient — requires changing **both** gates (reservation Case 2 + `ManufacturingPolicy` Rule 3) and amending ADR-027 §16, and updating the E2E premise/tests.

**No code, ADR, reservation, or inventory change was made. Awaiting the owner's contract-level decision.**
