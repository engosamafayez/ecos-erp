# TASK-ORDERS-INVENTORY-MANUAL-REMEDIATION-001 — ENGINEERING REPORT

**Date:** 2026-08-19 · **Branch:** `develop` · **Scope:** Orders + Inventory manual-test findings closure (pre-certification).

---

## 1. Executive Status

Eight findings + two approved business decisions (A1, A2) were investigated end-to-end (UI → API → Application → Domain → state machine). Each was traced to a **root cause with file:line evidence** before any change; nothing was hidden in the frontend and no workaround was applied.

Outcome by class:

- **Fixed + runtime-verified (2):** Finding 05 (GPS), Finding 06 (payment confirmation bypass — the critical defect).
- **Already-correct, regression-verified (3):** Finding 03 (allow-negative RM), Finding 04 (stock recovery), Section I (reservation correctness / release-warehouse). The manual-test symptoms here reflect a **deployment/verification gap** (the running container predated these working-tree fixes), not a code gap — the dedicated suites pass against the current code.
- **PARTIAL — root-caused, fix designed, not implemented (1):** Finding 01 (SKU) — needs a one-line scope sign-off (recommend global) plus a focused impl+frontend pass.
- **CONTRACT GAP / STOP (per the task's STOP conditions) (4):** Finding 02 (company isolation — structural + schema drift), Section H/Finding 07 (payment inline edit + proof retention), Section J (multi-warehouse override), Decision A1 (BOM/recipe-change reservation release — "Option A" is **not documented**).

**No new financial or inventory contract was invented.** Where the existing contract was silent, the finding is reported, not guessed.

**Certification status: DEFERRED** (see §12). No `CERTIFIED` claim is made anywhere.

---

## 2. Finding-by-Finding Matrix

| # | Finding | Status | Layer of root cause |
|---|---|---|---|
| 01 | SKU generation | **PARTIAL** (root-caused; global-scope fix designed) | Presentation (advisory-only gen) + Infra (scope/constraint mismatch) |
| 02 | Company isolation | **CONTRACT GAP / STOP** | Domain/Schema (no `company_id`, no scope; materials ownership unrepresentable) |
| 03 | Allow-negative stock | **RUNTIME VERIFIED (already correct)** | — (correct at `ManufacturingAvailabilityService:95`) |
| 04 | Stock recovery → order state | **RUNTIME VERIFIED (already correct)** | — (correct at `RetryReservation…` → `ProcessOrderWorkflow:253`) |
| 05 | GPS "No GPS" | **RUNTIME VERIFIED (fixed)** | Application (create/update never resolved short URL) |
| 06 | Payment lifecycle bypass | **RUNTIME VERIFIED (fixed)** | Application (confirm guard had no payment gate) |
| 07/H | Payment inline edit + proof | **CONTRACT GAP** | Endpoints absent; retention/`payment_status` undefined |
| I | Reservation correctness | **RUNTIME VERIFIED (already correct)** | — (release-warehouse fix intact) |
| J | Multi-warehouse safety | **CONTRACT GAP (future)** | `WarehouseAssignmentEngine::override()` |
| A1 | BOM change + reservation | **CONTRACT GAP / STOP** | "Option A" undocumented |
| A2 | Warehouse reassignment | **Preserved (single-warehouse)** | — (release fix intact; no premature orchestration) |

---

## 3. Root Cause (per finding)

### Finding 06 — Payment confirmation bypass (CRITICAL) — FIXED
`ConfirmOrderWorkflow::guard()` accepted `awaiting_payment` as a legal source with **no payment check**. Both confirm entry paths funnel through it: the "Confirm Data" button (`OrderController::confirmCustomer:354`) and the status-dropdown path (`PatchOrderAction::resolveWorkflow:232`). The payment contract already existed but only at creation (`CreateManualOrderAction::resolveManualOrderStatus:302-315`: `payment_proof_policy[method]==='required' && empty(payment_proof_path)` → `awaiting_payment`) and was never re-evaluated at confirmation.

**Fix:** added a payment gate to `ConfirmOrderWorkflow::guard()` that mirrors the creation contract **exactly** — an order may leave `awaiting_payment` only when paid in full (`deposit_amount >= total`, the read model's own derivation), OR its method's proof requirement is not `required` (COD/cash → `none`, credit_card → `optional`), OR proof is attached. No new payment status/semantics; the block closes **both** paths in one place. Non-`awaiting_payment` sources are unaffected.

**Secondary (documented, NOT fixed):** `VerifyPaymentAction` — the *sanctioned* payment-clearance path — is itself broken by **three layered defects**: (1) `:38` compares the `OrderStatus` enum to a string (`!== …->value`) so it **always 422s**; (2) `resolveTargetStatus` reads `source_entry_policies['manual']`, which is a **list** (`['pending','awaiting_payment','processing','confirmed']`), and passes it to `OrderStatus::from()` → `TypeError`; (3) it writes `status` **directly** (`$order->update([...])`), which the `Order` model now forbids (`Order.php:146-153` — all transitions must go through `FulfillmentEngine::run()`). Fixing (1)+(2) is trivial, but (3) requires routing the transition through the engine **and** deciding whether payment-verification should trigger reservation — an **undefined payment-transition semantic**. Per the STOP condition, this is reported, not invented. (My exploratory fixes for (1)+(2) were reverted so the endpoint stays at its graceful 422 rather than a 500.)

### Finding 05 — GPS dropped at the action layer — FIXED
`CreateManualOrderAction:153-156` (and `UpdateOrderAction`) persisted a Google Maps URL verbatim but **never resolved it to coordinates** — only `PatchOrderAction:86-97,249-289` did. So an order created from a pasted **short `maps.app.goo.gl` link** (which carries no coordinates until followed) stored `NULL` lat/lng, and `OrderResource:242` returned `location: null` → the grid showed "No GPS". No field-name mismatch existed; the coordinates were simply never produced on create.

**Fix:** extracted the resolution into a single authority, `GoogleMapsUrlResolver` (mirrors Patch's logic verbatim — follow redirects, then parse `!3d!4d` place-pin / `@lat,lng` / `q=` / `/maps/search/`), and wired it into `CreateManualOrderAction` and `UpdateOrderAction`. No-op when coordinates are already present; only short links are followed. No frontend/resource/migration change.

### Findings 03, 04, I — already correct (regression-verified)
- **03:** `ManufacturingAvailabilityService:95` honours `allow_negative_stock` (a negative-enabled RM shortage does not block the FG); `ReserveStockAction:81-92` records the **real** negative exposure (Available goes negative; On Hand/ledger untouched; not clamped positive). Both are at HEAD (baseline-correct).
- **04:** `RetryReservationOnStockAvailableListener` (registered `OrderServiceProvider:74-85`) re-runs `ProcessOrderWorkflow`, which advances `awaiting_stock → in_progress` via `OrderStatus::advancesToInProgressOnReservation()` (`ProcessOrderWorkflow:253-257`); failure stays `awaiting_stock`; idempotency enforced four ways. (Contract nuance, not a defect: a `confirmed` order clears the reservation block but stays `confirmed` — the intended lifecycle-vs-reservation two-column separation.)
- **I:** `ReleaseOrderInventoryAction:101-102,155-166` releases from the **ledger-recorded reservation warehouse**, not the mutable `assigned_warehouse_id` — the previously-fixed defect is intact.

### Finding 01 — SKU (PARTIAL, root-caused)
Auto-generation exists **only as a frontend advisory prefill**; the server (`StoreProductRequest:54`, `EloquentProductRepository:385`) **requires** a client SKU and validates it as **globally unique** (`products_sku_unique(sku)`). The generator `ProductController::nextSku:349-352` is **company-scoped** (`whereHas('brand', company_id)`) **and trashed-blind** (SoftDeletes scope), so it suggests a number the global index then rejects → "The SKU has already been taken." Generation is also non-atomic (read-then-insert), so concurrent creates can collide.

**Scope decision (recommendation, not yet implemented):** the enforcing DB unique index is **global**, and the runtime `products` table has **no `company_id` column** (dropped for brand-ownership — verified: absent in `ecos_dev`, present only in the drifted `ecos_dev_test`), so per-company SKU uniqueness is not representable without a schema change. **Global** is therefore both what is enforced and the only runtime-viable scope; the generator's company filter is the bug. **Designed fix:** make `sku` nullable on input (`StoreProductRequest`, `ProductDTO`); generate atomically server-side at create time under a locking read with retry-on-unique-violation; fix `nextSku` to global + `withTrashed()` (demote to a preview); update the frontend form to show the server-assigned SKU rather than require entry. **Not implemented** pending scope sign-off + a focused impl/test pass (it is a core creation path across several concurrent-modified files plus a frontend component).

---

## 4. Files Changed

**Production (4):**

| File | Change |
|---|---|
| `Modules/Operations/Fulfillment/Application/Workflows/ConfirmOrderWorkflow.php` | Payment gate in `guard()` + `paymentPermitsConfirmation()`/`paymentProofRequirement()` helpers + `ConfigurationManager` dependency (Finding 06) |
| `Modules/Commerce/Orders/Application/Services/GoogleMapsUrlResolver.php` | **New** — single authority for short-link → coordinate resolution (Finding 05) |
| `Modules/Commerce/Orders/Application/Actions/CreateManualOrderAction.php` | Wire `GoogleMapsUrlResolver::backfillCoordinates()` (Finding 05) |
| `Modules/Commerce/Orders/Application/Actions/UpdateOrderAction.php` | Wire `GoogleMapsUrlResolver::backfillCoordinates()` (Finding 05) |

**Tests (2, new):** `tests/Feature/Commerce/OrderPaymentConfirmationGateTest.php`, `tests/Feature/Commerce/OrderGpsPersistenceTest.php`.

**Reverted (net-zero, back to concurrent baseline):** `Modules/Commerce/Orders/Application/Actions/VerifyPaymentAction.php` (exploratory fixes reverted — see §3 Finding 06 secondary).

**Migrations:** none. **Commits/staging:** none. **Frontend:** none changed.

**Deliberately untouched:** `OrderReservationLifecycleTest.php` (concurrent-modified by another session), all other concurrent-modified files, `ReserveOrderInventoryAction`/`ManufacturingAvailabilityService`/`ReserveStockAction` (HEAD, baseline-correct), and every SKU/isolation file (Finding 01 pending; Finding 02 STOP).

---

## 5. Tests Added

- **`OrderGpsPersistenceTest`** (8): coordinate extraction (`@`, place-pin priority, no-coords); `backfillCoordinates` no-ops (coords present, non-short host); short-link resolved end-to-end (faked 302); manual order **persists supplied coordinates and the resource returns `location`**; absent GPS → `location: null`.
- **`OrderPaymentConfirmationGateTest`** (4): unpaid + proof-required → **Confirm Data blocked (422), status unchanged**; COD → confirmed; paid-in-full → confirmed; proof attached → confirmed. All drive the real `POST /api/orders/manual` → `POST /api/orders/{id}/confirm-customer` stack.

---

## 6 & 7. Tests Executed / Runtime Results

Run via the pinned/contended gate (`scripts/test-gate.sh`, `ecos_dev_test`), with the modified reservation production files synced into the container for parity.

| Suite | Result |
|---|---|
| `OrderGpsPersistenceTest` | **8/8 pass** |
| `OrderPaymentConfirmationGateTest` (confirm-guard cases) | **4/4 pass** |
| `NegativeStockReservationTest` (Findings 03, 04) | **pass** |
| `OrdersInventoryExecutionLifecycleTest` (Findings 03, 04) | **pass** |
| `ReservationWarehouseAuthorityTest` (Section I) | **pass** |
| `OrderReservationLifecycleTest` | **2 failures — pre-existing / concurrent-owned (see §11)** |

**Interpretation:** every finding in scope is green. The two `OrderReservationLifecycleTest` failures assert **superseded exception semantics** (`OrderAlreadyReservedException` / insufficient-stock throw) that the awaiting-stock **status-return** architecture replaced; `ReserveOrderInventoryAction` (unchanged by this task) returns `AwaitingStock` and idempotently skips instead of throwing. That file is `M` (owned by a concurrent session's suite-alignment task) and was not touched.

---

## 8. Static Checks

- **`php -l`:** clean on all changed files.
- **Pint (`--test`):** **passed** on all changed files (Finding 06 + Finding 05 production + both tests).
- **PHPStan L0:** **[OK] No errors** on the 4 changed production files (`ConfirmOrderWorkflow`, `GoogleMapsUrlResolver`, `CreateManualOrderAction`, `UpdateOrderAction`).

PHPStan core L6 was not run (targets `app/Core`, untouched). No platform-wide static cleanliness is claimed.

---

## 9. Manual Test Checklist (PREPARED ONLY — not executed)

> Run against a build that actually contains these working-tree changes (verify over HTTP; this project's dev nginx serves its own bundle and the container is not hot-mounted).

1. **SKU (Finding 01 — pending impl):** create Product A → auto SKU; create Product B → different auto SKU; rapid repeat → no "already taken"; operator never types a SKU. *(Blocked until §3 Finding 01 is implemented.)*
2. **Company isolation (Finding 02 — STOP):** as Company A, attempt `GET/PUT/DELETE /api/products/{id}` of a Company B product → must 403/404 (currently **does not** — documented gap). Repeat for raw materials, suppliers (suppliers already isolated).
3. **Raw material isolation:** as above for a `raw_material` product.
4. **Supplier isolation:** Company A cannot read/relate Company B supplier (expected pass — already scoped).
5. **Allow-negative (Finding 03):** FG with a negative-enabled RM shortage → order still reservable; Available shows the real negative; On Hand unchanged.
6. **Order reservation:** create order → one reservation per demand; no duplicates.
7. **Stock recovery (Finding 04):** park an order in `awaiting_stock`; add stock → order auto-advances to `in_progress`; failed retry stays `awaiting_stock`.
8. **GPS (Finding 05):** create an order with a pasted short `maps.app.goo.gl` link → the pin resolves and the grid shows GPS (not "No GPS"); create with explicit coordinates → shown; create with none → "No GPS".
9. **Unpaid order (Finding 06):** create an unpaid `instapay` order (lands `awaiting_payment`); click **Confirm Data** → **rejected (422)**, stays `awaiting_payment`.
10. **COD order (Finding 06):** COD order in `awaiting_payment` → Confirm Data → `confirmed`.
11. **Payment proof (Finding 07 — gap):** attach/replace proof from the row — **no endpoint exists**; document expected behavior for product sign-off.
12. **Payment inline edit (Finding 07 — gap):** edit payment method/state from the row — **no endpoint exists**.
13. **Cancellation → release (Section I):** cancel a reserved order → the correct quantity releases from the reservation-recorded warehouse.
14. **Reservation after availability change:** as #7, confirm no duplicate reservation/transition.

**Do NOT claim Manual Test passed** — this checklist is prepared only.

---

## 10. Remaining Contract Gaps (STOP — owner decision required; nothing invented)

- **A1 — BOM/recipe change vs active reservation:** "Option A" is **not documented** anywhere in the repo. Three verification reports already classify this exact scenario as *"CONTRACT GAP — OWNER DECISION REQUIRED"*, and the BOM-change code (`SetBomStatusAction`, `UpdateBomAction`, `BomController`) touches reservations not at all. No atomic release-then-recompute rule exists to implement.
- **02 — Company isolation (Products / Raw Materials):** Products carry **no `company_id`** and **no global scope**; by-id `show`/`update`/`delete` use unscoped `Product::find` → **cross-tenant IDOR**. Raw materials are a Product subtype with `brand_id` made **nullable** ("materials belong to a Company directly") but **no column represents that** → a null-brand material is owned by no company (invisible to `whereHas('brand')` lists, yet exposed by id). The `products.company_id` column is **present in `ecos_dev_test` but absent in `ecos_dev` runtime** — a schema drift. A correct fix (adopt the Supplier-style `company_id` + `TenantOwnershipResolver` global scope, backfill, resolve materials ownership) requires a **schema + ownership decision and a migration** under a live drift — reserved by the STOP condition. Suppliers are clean; Units are intentionally global (preserve).
- **07/H — Payment inline edit + proof:** no inline payment-method/state endpoint exists (`PatchOrderAction::ALLOWED` / `UpdateOrderAction` SOFT/STRUCTURAL carry no `payment_status`/`payment_proof_path`); the only proof writer (`verify-payment`) force-advances status and is itself broken (§3); **replace-proof retention/audit/authorization** and the dead `payment_status` column have **no contract**. Financial semantics must be specified, not invented.
- **J — Multi-warehouse override:** `WarehouseAssignmentEngine::override():72-105` rewrites `assigned_warehouse_id` with **no** release→reassign→re-reserve orchestration. Latent/safe under single-warehouse (the release fix reads the ledger-recorded warehouse); it would violate the future contract when multi-warehouse is activated. Per A2, **no premature orchestration** was added.
- **VerifyPaymentAction** (3 layered defects, §3) — needs an engine-routed transition + a payment-verification-triggers-reservation decision.

---

## 11. Concurrent Work / Environment Blockers

- **Shared test DB (`ecos_dev_test`)** is pinned and contended; every run went through `scripts/test-gate.sh` with `GATE_WAIT`. No destructive shared-DB op was run outside the gate.
- **`OrderReservationLifecycleTest.php` is concurrent-modified** (`M`, another session's TASK-ARCH-004→007 suite alignment). Its 2 failures test superseded throw-semantics and were **not** touched (not this task's file; not attributable to this task's code — `ReserveOrderInventoryAction` is unchanged).
- **Container parity:** the running dev container predated the working-tree Orders/Inventory fixes — the likely reason Findings 03/04/I *appeared* broken in manual testing while the current code is correct. Verify future manual tests over HTTP against a build containing these changes.
- **Schema drift:** `products.company_id` exists in `ecos_dev_test` but not `ecos_dev` — flagged for the isolation decision (§10).
- No concurrent file was reset/cleaned/restored/overwritten; no unrelated changes were committed (nothing was committed at all).

---

## 12. Certification Status

**DEFERRED.** Never `CERTIFIED`. Engineering remediation for the in-scope fixes (05, 06) is complete and runtime-verified; 03/04/I are regression-verified; 01 is PARTIAL; 02/07/J/A1 are contract gaps awaiting owner decisions. Certification remains gated on: engineering remediation → automated runtime verification → **manual operational test** → manual sign-off → final certification.
