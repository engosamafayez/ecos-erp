# TASK-ORDER-PREPARATION-FULFILLABILITY-CONTRACT-001 — Engineering Report

**Date:** 2026-08-20 · **Branch:** develop · **Environment:** DEV only.
**Production untouched. No DB reset. ORD-00001/00002 preserved (ORD-00002 driven to its corrected state — see §Runtime). No destructive git ops (surgical edits + `docker cp` sync only).**
**Certification: DEFERRED — no certification claimed. Interactive browser smoke pending sign-in (see Remaining Gaps).**

---

## 1. Old contract

Order fulfilment required **both** `can_manufacture = true` **and** an executable recipe (ADR-027 §16 "Option B", owner-approved 2026-08-09). `can_manufacture` also gated the preparation/production path at `ManufacturingPolicy` Rule 3. Because `can_manufacture` is `false` by default and on 100% of live products, every zero-stock recipe-backed finished good (e.g. Honey Jar 250g) went **Awaiting Stock**.

## 2. Owner decision

Revise the contract. ECOS is **order-driven preparation / made-to-order**. `can_manufacture` must **no longer** gate order fulfilment; recipe executability alone governs, consistently at reservation **and** preparation. Do not enable/redefine/delete `can_manufacture`. Add `allow_negative_stock` recovery; no `can_manufacture` recovery.

## 3. New contract (ADR-027 §16 v1.5 / §19)

Product Fulfillability = **physical FG stock (Case 1) OR executable preparation recipe (Case 2)**. Recipe executability = `ManufacturingAvailabilityService` (unchanged single authority): every required material `available > 0 OR allow_negative_stock`. A missing recipe is not a preparation path. `can_manufacture` is retained but no longer consulted for fulfilment or preparation eligibility.

## 4. Exact files changed

**Backend (behaviour):**
- [`ReserveOrderInventoryAction.php`](backend/Modules/Commerce/Orders/Application/Actions/ReserveOrderInventoryAction.php) — Case 2 gate `can_manufacture && …` → recipe-executability only; `manufacturingIsExecutable()` tightened to `status === 'instock'` (a recipe-less FG can't slip through the now-ungated branch); null-safety + docblocks.
- [`ManufacturingPolicy.php`](backend/Modules/Manufacturing/ManufacturingPolicy/Domain/Services/ManufacturingPolicy.php) — Rule 3 (`can_manufacture`) removed; Rule 4 (recipe exists) retained; `PolicyCode::ProductCannotManufacture` kept for BC, no longer emitted.
- [`ProductNegativeStockEnabled.php`](backend/Modules/Inventory/DomainEvents/Events/ProductNegativeStockEnabled.php) — **new** domain event (product_id, company_id).
- [`Product.php`](backend/Modules/Inventory/Products/Domain/Models/Product.php) — model observer publishes the event, afterCommit, only on `allow_negative_stock` false→true; fail-closed on missing tenant.
- [`RetryReservationOnStockAvailableListener.php`](backend/Modules/Commerce/Orders/Application/Listeners/RetryReservationOnStockAvailableListener.php) — `handleNegativeStockEnabled()` + company-scoped (warehouse-agnostic) `candidateOrders()`/`runCandidates()` refactor; reuses the existing recovery, tenant-safe, idempotent, no polling.
- [`OrderServiceProvider.php`](backend/Modules/Commerce/Orders/Infrastructure/Providers/OrderServiceProvider.php) — registered the new event → existing listener.

**Tests:**
- [`OrderPreparationFulfillabilityContractTest.php`](backend/tests/Feature/Orders/OrderPreparationFulfillabilityContractTest.php) — **new**, 11 tests (matrix + recovery + wiring below).
- [`RecipeToOrderAvailabilityE2ETest.php`](backend/tests/Feature/Manufacturing/RecipeToOrderAvailabilityE2ETest.php) — added `test_f_can_manufacture_false_with_executable_recipe_reserves`.
- [`ManufacturingPolicyTest.php`](backend/tests/Unit/Manufacturing/ManufacturingPolicyTest.php) — Rule 3 assertions replaced (can_manufacture=false now eligible; missing recipe → RecipeNotFound).

**Docs:** [`ADR-027`](docs/adr/ADR-027-reservation-ownership-policy.md) — new **Section 19 (v1.5)**, §3/§16.1 supersession pointers, revision log.

**Frontend (UI §16):**
- [`product-column-defs.tsx`](frontend/src/features/products/components/product-column-defs.tsx) — finished-good badge: physical **In Stock** (Case 1) vs **Fulfillable (Recipe)** (Case 2, FG=0) vs **Out of Stock**. Stops the misleading "In Stock" for a zero-stock buildable FG.
- `en/products.json` + `ar/products.json` — added `colDefs.mfgFulfillable`.

## 5. Exact ADR changes
ADR-027 → **v1.5**. New **Section 19 — Order-Driven Preparation Fulfillability** documents: previous Option B contract, reason for amendment, new fulfillability rule (FG stock OR recipe executable), single authority preserved, reservation/preparation consistency (Rule 3 removed), `allow_negative_stock` recovery, **no** `can_manufacture` recovery, retained-but-narrowed flag, and enforcing tests. Section 3 Case 2 and Section 16.1 carry supersession notes; revision log updated.

## 6. Reservation behaviour — before/after
| Case (FG stock=0) | Before | After |
|---|---|---|
| recipe executable, `can_manufacture=false` | **Awaiting Stock** | **Reserved → In Progress** |
| recipe executable, `can_manufacture=true` | Reserved | Reserved (unchanged) |
| recipe blocked (material short, no allow_negative) | Awaiting Stock | Awaiting Stock (unchanged) |
| FG physical stock ≥ requested | Reserved (Case 1) | Reserved (Case 1, unchanged, evaluated first) |

## 7. Preparation behaviour — before/after
Before: `can_manufacture=false` → `ManufacturingPolicy` Rule 3 rejected → line `Skipped` (broken half-state possible). After: Rule 3 removed → a reserved, recipe-backed order line is eligible for preparation regardless of the flag; Rule 4 still requires a recipe. Reservation and preparation now use the **same** executability contract.

## 8. Recovery behaviour
- **(A) RM stock available** — existing `InventoryStockReceived/Released/Adjusted` recovery, unchanged.
- **(B) `allow_negative_stock` false→true** — new `ProductNegativeStockEnabled` event → existing listener → company-scoped re-evaluation → retry → Awaiting Stock → In Progress. Idempotent, tenant-safe, affected-orders-only, no polling, no direct status write.
- **(C) `can_manufacture` change** — no recovery (not a gate).

## 9. Test matrix & exact results
Ran via the serialized gate inside `ecos-dev-testrunner` (`php vendor/bin/phpunit`):
```
OrderPreparationFulfillabilityContractTest  + ManufacturingPolicyTest
+ RecipeToOrderAvailabilityE2ETest + OrderAvailabilityLifecycleContractTest
OK (72 tests, 188 assertions)
```
Decisive evidence line (E2E): `F: recipe=instock | http=200 | order=ready_for_dispatch | reservation=reserved | reserved_qty=1.00` — `can_manufacture=false` + executable recipe now reserves.

New contract-suite coverage (all green): §8 zero-FG + executable recipe reserves without `can_manufacture`; §9 allow-negative material reserves; §10 blocked material → Awaiting Stock; §11 RM-arrival auto-recovery; §12 allow-negative-flip auto-recovery; observer publishes only on false→true; policy listener subscribed; `can_manufacture` change publishes no recovery event.

**Static/quality gates:** `php -l` OK · Pint PASS (6 files) · PHPStan L0 "No errors" · frontend `tsc` — 0 new errors in changed files (23 pre-existing EPIC-L10N baseline unrelated) · ESLint clean on changed file · `vite build` exit 0. GPS suite (separate task) still 8/8.

## 10. Runtime scenarios (real DEV data + real application paths)
- **Core case, real data — ORD-00002 (Honey Jar 250g, FG on_hand=0, `can_manufacture=false`, recipe executable):** drove the **real** `FulfillmentEngine`/`ProcessOrderWorkflow`. **Before:** `awaiting_stock` / reservation `awaiting_stock` / reserved_qty 0. **After:** `in_progress` / reservation `reserved` / reserved_qty **1**. Order-driven RM reservations landed (Raw Honey + Glass Jar `reserved` increased per recipe). No manual status write.
- **Matrix (§9/§10/§11/§12) via real HTTP paths:** proven by the 11-test contract suite hitting `POST /api/fulfillment/orders/{id}/transition` and the recovery listener — reservation, blocked → awaiting, RM-arrival recovery, and allow-negative-flip recovery all pass.

## 11. Existing contracts preserved
Tenant isolation, reservation ownership/lifecycle, stock-ledger semantics, FIFO, Allow-Negative-Stock semantics, Preparation-wave ownership (§18), and Case-1 physical-stock precedence are unchanged. `OrderAvailabilityLifecycleContractTest` (recovery/tenant/replay regression) is green. No duplicate engine, no second reservation/recipe engine, no lifecycle bypass.

## 12. Remaining gaps
1. **Interactive browser smoke is not done** — the DEV UI requires a signed-in session, and entering passwords to authenticate is outside my permitted actions (no dev credentials available). The operational check was instead performed through **real application paths**: real DEV data (ORD-00002) via the real fulfillment engine, plus the real-HTTP feature-test matrix. The visual/browser confirmation of the Products badge and the order flow still needs a human-authenticated session.
2. **Repo-wide `npm run build` (`tsc -b`) is red on a pre-existing 23-error EPIC-L10N baseline** unrelated to this task; the `vite build` bundler step passes. Not fixed (out of scope; "ratchet, never cliff").
3. **Preparation execution** (actual RM consumption / customer-ready assembly) is downstream of reservation and unchanged here; this task establishes fulfillability + reservation + preparation *eligibility* per the contract.

## 13. Certification status
**DEFERRED.** No "Fixed + Verified" claim. Backend contract, recovery, ADR, tests, and the real-data + real-HTTP runtime are complete and green; interactive browser confirmation remains outstanding and is the only blocker to a full operational sign-off.
