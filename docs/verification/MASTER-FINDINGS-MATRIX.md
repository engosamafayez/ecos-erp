# MASTER FINDINGS MATRIX — ECOS Pre-Shipping Domain Closure

**Task:** TASK-ECOS-PRE-SHIPPING-DOMAIN-CLOSURE-001 · **PHASE 0 ONLY**
**Date:** 2026-08-14 · **Revision 2:** 2026-08-15 · **Branch:** `develop`

> **Revision 2 — what changed**
> - **T-6 delivered and proven** — `scripts/test-gate.sh`, six proofs, three defects found in
>   the gate itself while proving it. See `T-6-TEST-RUNNER-ISOLATION.md`. **F-XC-01 closes.**
> - **T-9 partial** — `TASK-UAT-006-sales-orders` (NO-GO) closed in substance; four findings
>   added as **F-ORD-15…18**. ~45 reports remain. See `T-9-UNREAD-REPORT-CLOSURE.md`.
> - **T-1 traced, not implemented** — the trace **changed the design**: `Untracked` has a
>   proven non-business consumer and must NOT be deleted. See
>   `T-1-AVAILABILITY-CONTRACT-AUDIT.md`.
> - **T-1 IMPLEMENTED + CERTIFIED (rev 3, 2026-08-15)** — three-state business projection
>   live; `Untracked` retained for the data platform; **13/13 proofs, 84 assertions**.
>   **F-PRD-01…05 and F-INV-10 all close.** See `TASK-T-1-AVAILABILITY-CONTRACT-ENGINEERING-REPORT.md`.
> - **One new defect found:** **F-ORD-14**, the transition-refusal fix reached one of four
>   surfaces. Assigned to existing T-4; not fixed opportunistically.
**Mode (rev 1):** READ-ONLY AUDIT.
**Mode (rev 2):** audit remains read-only; **T-6 and T-1 were separately authorised and DID
change code** — `scripts/test-gate.sh` (new), the `ProductAvailability` business projection
and its consuming surfaces. No migration, no schema change, no `ecos_dev` data change. The
only destructive operation was `db:wipe` on `ecos_dev_test`, executed under the T-6 lock.

**Domains:** Orders · Preparation · Customers/CRM · Products · Inventory/Warehouse ·
Procurement (Purchasing) · Pricing/Cost

---

## 0. How to read this matrix — evidence levels

Every row carries an explicit evidence level. This exists so that nothing in this document
is taken as more certain than it is, which is the whole point of Phase 0.

| Level | Meaning |
|---|---|
| **CODE-VERIFIED (2026-08-14)** | I opened the current file this session and confirmed the finding is still true, or now false, in the tree as it stands |
| **RUNTIME-VERIFIED (2026-08-14)** | Confirmed against a live database or a live HTTP call this session |
| **REPORT-SOURCED** | Taken from a prior engineering report; **not** re-verified in code this session. Treat the state as "as of that report's date", not as of today |
| **SELF-CERTIFIED (this session)** | Delivered and certified by me earlier in this same session, with runtime proof recorded in its own report |

**A warning that matters more than any single row:** report verdicts are **not** reliable
indicators of current state. Verified counter-examples found while building this matrix —
`TASK-PREPARATION-WAVE-CONTRACT-RESOLUTION-001` scores "blocked" on a naive scan but its
real verdict is "CONTRACT PARTIALLY RESOLVED"; `TASK-ORDER-LIFECYCLE-V3-CANONICAL-REPAIR-001`
is stamped NOT CERTIFIED but its subject matter was subsequently superseded by ADR-042 and
the canonical migration, which are both in the tree. **Nothing here was classified from a
verdict line alone.**

---

## 1. Coverage statement — what this phase did and did not read

| | Count |
|---|---|
| Reports in `docs/verification/` | 155 |
| In scope for the seven domains | ~70 |
| Read in full or in substantive part | **25** (rev 2: +`TASK-UAT-006-sales-orders`) |
| Indexed by title/verdict only | **~45** |
| Findings carried into the matrix | **38** (rev 2: +7) |
| Findings re-verified against current code/runtime | **19** (rev 2: +7) |

**This is not yet complete Phase 0 coverage.** The ~46 indexed-only reports are listed in
§7 as remaining inventory work. Presenting the matrix as complete would be exactly the
"assumed" status the task forbids, so it is presented as what it is: the verified core, plus
a named remainder.

---

## 2. Executive summary

### 2.1 What is already genuinely closed

- **Preparation Wave operational cycle** — cross-day cycles, start/intake-cutoff/end,
  stale-wave closure, carry-over, historical membership, one-active-membership, no duplicate
  reservation, no duplicate material demand. Certified, deployed, 65/65 suite green, proven
  against the live scheduler and database earlier today.
- **Order status vocabulary (ADR-042)** — the eleven canonical cases, `fulfilmentEligible()`
  as single source, re-derived by `PreparationSessionPolicy` and `config/distribution.php`.
- **Reservation/demand consistency** — Required / Available / Missing, and the
  `ownWaveMaterialReservations` netting. Unmodified and structurally protected by the
  wave-scoped unique key.
- **Untracked availability no longer collapses to Out of Stock** (RC-1) — the repository now
  preserves the LEFT JOIN NULL.

### 2.2 What is certified

Two components are certified with runtime proof in the current tree:
**Preparation Wave** (CROSS-DAY-TRANSITION-002) and the **Product availability contract**
(T-1 — 13/13 proofs, 84 assertions, including tenant isolation).

**No full domain is certified**, because every domain's UI gate is unverified (§2.7).

### 2.3 What is implemented but not certified

- **Order inventory-execution lifecycle** — implemented; its own report records the backend
  regression as unexecuted.
- **Negative-stock fulfilment contract (REPAIR-003)** — 18-case matrix **written, not run**;
  verdict "NOT CERTIFIED — RUNTIME E2E NOT EXECUTED".
- **Customer domain closure** — engineering PASS with one unverified item; regression
  **BLOCKED** on a shared-runner conflict; browser E2E pending.
- **Materials status / schedule position fix** — backend regression not executed.

### 2.4 What remains broken — verified in code today

| | Finding |
|---|---|
| ~~F-PRD-01~~ | **CLOSED by T-1** — the two contradictory availability contracts are now one business projection plus a retained data-platform enum |
| **F-CUS-01** | `OrderResource` customer-stats query has no `company_id` filter |
| **F-CUS-02** | `useCustomerOrderStats` aggregates in React over a 200-order page; Orders screens will disagree with the Customers workspace |
| **F-ORD-05** | `partial_reserved` can never reach `reserved` — the ADR-027 transition is unreachable |

### 2.5 What requires a business decision

**Eight open, three answered.** D-A/D-B (availability states and terminology) were settled by
the approved contract and are now implemented; P-6 and P-7 are answered and delivered.
Still open: D-C, Q2/Q3, D1/D2, P-1…P-5, and new **F-INV-11/T-1c** (two tenant-scoping
strategies now coexist in `EloquentProductRepository`). Full detail in §5.

### 2.6 What requires a repair task

Eleven repair tasks remain, specified and ordered in §6. **T-1, T-1b and T-6 are DONE.**

### 2.7 What can safely be deferred

Cosmetic and low-risk items only: the unused `OrderStatus::displayOrder()`, the orphaned
`table.untracked` i18n key, `orders.preparation_completed_at` cleanup, and
`wave_engine_configurations.timezone` removal. None blocks Shipping.

### 2.8 The one structural blocker

**No authenticated browser session is available in this environment.** Phase 7 requires
real-UI verification of all five original domains and Phase 13 makes a pending browser gate
fatal to certification. `PRE-SHIPPING FOUNDATION = CERTIFIED` therefore cannot be reached by
engineering alone — it requires a human browser smoke, regardless of how many repair tasks
land.

---

## 3. Master finding matrix

Legend for **Sev**: **S1** blocks Shipping · **S2** blocks its own domain's certification ·
**S3** quality/consistency · **S4** cosmetic.

### 3.1 ORDERS

| ID | Finding | Origin task | Current state | Files | Code? | DB? | Runtime? | E2E? | Regr? | Open? | Depends on | Next task | Status | Sev | Evidence |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **F-ORD-01** | `status = "new"` rejected by `StoreManualOrderRequest`; all manual order creation blocked | ORDER-CREATE-STATUS-INVALID-FIX-001 | Fixed; 5/5 tests on real HTTP surface | `StoreManualOrderRequest` | Yes | No | Yes | No | Partial | No | — | — | **FIXED + RUNTIME VERIFIED** | — | REPORT-SOURCED |
| **F-ORD-02** | Order FSM V3 vocabulary inconsistent; `new` removal breaks live data | ORDER-LIFECYCLE-V3-CANONICAL-REPAIR-001 / SUPERSESSION-001 | Superseded by ADR-042 + `2026_08_13_100000_supersede_order_lifecycle_v3_canonical`; enum now 11 canonical cases | `OrderStatus.php`, migration | Yes | Yes | Yes | No | Yes | **No** | — | — | **FIXED + VERIFIED** | — | CODE-VERIFIED |
| **F-ORD-03** | Order entry status: brand policy stores pre-V3 vocabulary; write path migrates, read path discards | ORDER-INVENTORY-FULFILLMENT-CONTRACT-RECOVERY-001 (D1/D2, finding 5/6) | Diagnosed, **not repaired**. Blocked on business ruling | `BrandPolicy:154`, order controller/action | No | No | No | No | No | **Yes** | Business ruling D1/D2 | **T-3** | **CONTRACT DECISION REQUIRED** | **S1** | REPORT-SOURCED |
| **F-ORD-04** | V3 rename migrated **data** but never **configuration** — three separate instances (wave config, request whitelist, brand policy) | ORDER-INVENTORY-FULFILLMENT-CONTRACT-RECOVERY-001 | Two instances fixed (wave, whitelist); brand policy open | multiple | Partial | No | Partial | No | No | **Yes** | F-ORD-03 | **T-3** | **TASK REQUIRED** | **S2** | REPORT-SOURCED |
| **F-ORD-05** | `partial_reserved` never completes to `reserved`; ADR-027 §8 transition unreachable — needs delta-reservation | ORDERS-INVENTORY-EXECUTION-LIFECYCLE-REPAIR-001 §23.1 | Deliberately not invented; excluded from re-evaluation candidates | `ProcessOrderWorkflow` | No | No | No | No | No | **Yes** | ADR-027 clause | **T-5** | **CONTRACT GAP** | **S2** | REPORT-SOURCED |
| **F-ORD-06** | Order with no reservable line has no ADR-027 state; treated as vacuously `reserved` | same §23.2 | Behaviour chosen and documented; ADR clause missing | `ProcessOrderWorkflow` | No | No | No | No | No | Yes | ADR-027 amendment | **T-8** | **CONTRACT GAP** | S3 | REPORT-SOURCED |
| **F-ORD-07** | Zero-line order must not fall into `awaiting_stock` | Master task Phase 1 | Believed satisfied by F-ORD-06's vacuous-reserved rule; **not re-verified** | `ProcessOrderWorkflow` | — | — | No | No | No | **Yes** | — | **T-5** | **NOT VERIFIED — TASK REQUIRED** | **S2** | REPORT-SOURCED |
| **F-ORD-08** | `ecos_erp` stale rows: ORD-00001 carries `awaiting_stock` + "Warehouse Not Assigned" from a superseded path | same §23.3 | Data repair out of scope; `ReprocessLegacyReservationsCommand` exists | data only | No | No | No | No | No | Yes | — | **T-7** | **TASK REQUIRED** (data) | S3 | REPORT-SOURCED |
| **F-ORD-09** | No-coverage order: `ProcessOrderWorkflow` routes to `AwaitingStock`; `BRANCH-ASSIGNMENT-ENGINE.md` says leave status alone. **Both certified — one must be retired** | ORDER-AWAITING-STOCK-DIAGNOSTIC-001 §19.1 | Conflict live; workflow silently overrides the engine | `ProcessOrderWorkflow:29`, branch engine | No | No | Yes | No | No | **Yes** | Business ruling Q2 | **T-2** | **CONTRACT DECISION REQUIRED** | **S1** | REPORT-SOURCED |
| **F-ORD-10** | Nothing ever re-triggers warehouse assignment; every order failing assignment is permanently stuck | same §19.3 | No trigger exists | assignment engine | No | No | Yes | No | No | **Yes** | Business ruling Q3 | **T-2** | **CONTRACT DECISION REQUIRED** | **S1** | REPORT-SOURCED |
| **F-ORD-11** | `OrderStatus::displayOrder()` has no consumer | ORDERS-MATERIALS-STATUS §11.2 | Kept in sync, read by nothing | `OrderStatus.php` | No | No | — | — | — | Yes | — | defer | **OUT OF SCOPE / DEFER** | S4 | CODE-VERIFIED |
| **F-ORD-12** | Backend regression for the materials-status fix never executed | ORDERS-MATERIALS-STATUS §11.1 | One additive filter branch, unproven at suite level | orders backend | Yes | No | No | No | **No** | **Yes** | shared runner | **T-6** | **FIXED + NOT FULLY CERTIFIED** | **S2** | REPORT-SOURCED |
| **F-ORD-13** | D-1 scheduled activation | Master task Phase 1 | `orders:activate-scheduled` scheduled daily 00:05; behaviour **not re-verified** this session | `ActivateScheduledOrdersCommand`, `routes/console.php:16` | — | — | No | No | No | **Yes** | — | **T-5** | **NOT VERIFIED — TASK REQUIRED** | **S2** | CODE-VERIFIED (wiring only) |
| **F-ORD-14** | **NEW.** The transition-refusal fix reached **one of four** surfaces. `order-detail-drawer.tsx:1423` has `onError`; `order-workflow-actions-panel.tsx:97`, `smart-status-selector.tsx:92` and `order-detail-page.tsx:1346` do not. Backend refusals are **silently swallowed** on three surfaces — the operator clicks and nothing happens | discovered closing UAT-006 | Still true | 4 frontend files | No | No | No | No | No | **Yes** | — | **T-4** (existing) | **REGRESSION / UI-API MISMATCH** | **S1** | **CODE-VERIFIED** |
| **F-ORD-15** | **UAT6-001 P0** — order advanced toward shipping with no reservation and no warehouse | TASK-UAT-006 | **Backend FIXED** (`MoveToPreparationWorkflow` guards warehouse, awaiting-stock, terminal reservation, unapproved partial). **UI half open** → F-ORD-14 | `MoveToPreparationWorkflow.php:43,56,66,88,110` | Yes | No | No | No | No | **Partly** | F-ORD-14 | **T-4** | **FIXED (backend) + STILL OPEN (UI)** | **S1** | **CODE-VERIFIED** |
| **F-ORD-16** | **UAT6-002 P1** — orders carry **no tax**; no tax configuration screen exists anywhere. Egyptian VAT is mandatory, so no compliant invoice can be produced | TASK-UAT-006 | Not re-verified; no report since claims tax work | Orders + Finance | No | No | No | No | No | **Yes** | Product decision | **T-12** | **PRE-EXISTING — PRODUCT DECISION REQUIRED** | **S1** | REPORT-SOURCED |
| **F-ORD-17** | **UAT6-003 P1** — no Quotations, Order Approval, Returns workflow or Reports. `RETURNED` is a status the system can display but never reach | TASK-UAT-006 | Not re-verified; effort XL | Orders | No | No | No | No | No | **Yes** | Roadmap | **T-12** | **OUT OF SCOPE — MISSING CAPABILITY** | S3 | REPORT-SOURCED |
| **F-ORD-18** | **UAT6-004 P3** — order search requires Enter, no debounce feedback | TASK-UAT-006 | Not re-verified | orders UI | No | No | No | No | No | Yes | — | defer | **PRE-EXISTING / DEFER** | S4 | REPORT-SOURCED |
| **F-ORD-19** | UAT-006 declares **allocation, fulfilment, partial fulfilment, cancellation, returns, order notifications and order tenant isolation UNVERIFIED** — no order was created and no transition executed | TASK-UAT-006 §"honest limit" | Still unverified | Orders | — | — | **No** | **No** | No | **Yes** | — | **T-5** | **NOT VERIFIED — TASK REQUIRED** | **S1** | REPORT-SOURCED |

### 3.2 PRODUCTS

| ID | Finding | Origin task | Current state | Files | Code? | DB? | Runtime? | E2E? | Regr? | Open? | Depends on | Next task | Status | Sev | Evidence |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **F-PRD-01** | **RESOLVED by T-1.** Two contradictory availability contracts lived in the same file. `EloquentProductRepository:30-36` *preserves* the LEFT JOIN NULL so `Untracked` is reachable; `:186-188` *coalesces* NULL to 0 with the comment "rather than a fourth 'untracked' state. Presence of an inventory record is deliberately NOT an input." | INVENTORY-NEGATIVE-STOCK-CURRENT-STATE-DIAGNOSTIC-002 (RC-1/RC-2) + Master Phase 2 | **Both live.** The filter implements the Master Phase-2 three-state rule; the enum/resource implement a different four-state model | `EloquentProductRepository.php:30-36, 178-205`, `AvailabilityState.php`, `ProductResource.php:188` | Yes | No | No | No | No | **Yes** | Business ruling **D-A/D-B** | **T-1** | **CONTRACT DECISION REQUIRED** | **S1** | **CODE-VERIFIED** |
| **F-PRD-02** | `AvailabilityState` enum has **no `NegativeAllowed` case**; `fromAvailable()` cannot see `allow_negative_stock`. Master Phase 2 requires it as one of exactly three states | RC-2 / D-A | Still true | `AvailabilityState.php:32-58` | No | No | No | No | No | **No** | D-A | **T-1** | **FIXED + RUNTIME VERIFIED** | **S1** | **CODE-VERIFIED** |
| **F-PRD-03** | Products drawer composes "Backorder Allowed" **client-side** from `availability_state` + `can_commit`. Master Phase 2 forbids client-side duplicate classification and names the state "Negative Allowed" | D-B | Still true | `product-detail-drawer.tsx:657-666` | No | No | No | No | No | **No** | D-A/D-B | **T-1** | **FIXED + RUNTIME VERIFIED** | **S1** | **CODE-VERIFIED** |
| **F-PRD-04** | **Two surfaces classify the same state differently.** Raw Materials types only `in_stock\|out_of_stock\|untracked` and has no `can_commit` composition, so `available = −1` with Allow Negative ON renders **Out of Stock**; the Products drawer renders **Backorder Allowed** for the identical condition | ORDER-STOCK-STATUS §8.3 + type inspection | Still true | `raw-materials/types/index.ts:57`, `raw-material-detail-drawer.tsx:71-85` | No | No | No | No | No | **No** | D-A/D-B | **T-1** | **FIXED + RUNTIME VERIFIED** | **S1** | **CODE-VERIFIED** |
| **F-PRD-05** | `Untracked` rendered as a visible fourth business state in the Products drawer | Master Phase 2 ("No fourth business state") | Still true | `product-detail-drawer.tsx:660` | No | No | No | No | No | **No** | D-A | **T-1** | **FIXED + RUNTIME VERIFIED** | **S2** | **CODE-VERIFIED** |
| **F-PRD-06** | RC-1 untracked→out-of-stock collapse | DIAGNOSTIC-002 RC-1 | **Repaired** in tree (uncommitted): NULL now survives to the resource | `EloquentProductRepository.php:30-36` | Yes | No | No | No | No | **No** | — | — | **FIXED + VERIFIED** | — | **CODE-VERIFIED** |
| **F-PRD-07** | `can_manufacture = 0` alongside an active BOM | ORDER-AWAITING-STOCK-DIAGNOSTIC §19.5 | Ruled a **valid** configuration by a later task | product data | No | No | Yes | No | No | No | — | — | **OUT OF SCOPE + CONTRACT** | — | REPORT-SOURCED |
| **F-PRD-08** | `FG-000001.allow_negative_stock = 0` while both recipe components are `1` | same §19.4 | Product-configuration decision, not a code fix | product data | No | No | Yes | No | No | **Yes** | Business ruling | **T-1** | **CONTRACT DECISION REQUIRED** | S3 | REPORT-SOURCED |

### 3.3 INVENTORY / WAREHOUSE

| ID | Finding | Origin task | Current state | Files | Code? | DB? | Runtime? | E2E? | Regr? | Open? | Depends on | Next task | Status | Sev | Evidence |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **F-INV-01** | **D-A** — which state applies to `untracked` + Allow Negative ON? Named "the blocking one" by its own report | INV-NEGATIVE-STOCK-SEMANTICS-001 §15 | Unresolved | see F-PRD-01 | No | No | No | No | No | **Yes** | Owner | **T-1** | **CONTRACT DECISION REQUIRED** | **S1** | REPORT-SOURCED |
| **F-INV-02** | **D-B** — confirm canonical terminology. ADR-027:167 says "Case 3 — Negative Stock"; UI says "Backorder Allowed"; Master Phase 2 says "Negative Allowed". **Three names, one concept** | same | Unresolved | ADR-027, UI, filter | No | No | No | No | No | **Yes** | Owner | **T-1** | **CONTRACT DECISION REQUIRED** | **S1** | REPORT-SOURCED + CODE-VERIFIED |
| **F-INV-03** | **D-C** — CASE 5 (`on_hand < 0`, Allow Negative OFF) behaviour | same | "Do not invent" — left as-is | reservation path | No | No | No | No | No | **Yes** | Owner | **T-1** | **CONTRACT DECISION REQUIRED** | **S2** | REPORT-SOURCED |
| **F-INV-04** | **RC-3** — Available clamped in ten places | DIAGNOSTIC-002 §5.2 | Repository path de-clamped (F-PRD-06); the other sites **not audited this session** | multiple | Partial | No | No | No | No | **Yes** | D-A | **T-1** | **TASK REQUIRED** | **S2** | REPORT-SOURCED |
| **F-INV-05** | **RC-4** — Orders 500 from `ORD-00002.status = 'new'` post-ADR-042 | DIAGNOSTIC-002 | **Resolved** — `ORD-00002` is `in_progress` in `ecos_dev` today | data | No | Yes | Yes | No | No | **No** | — | — | **FIXED + RUNTIME VERIFIED** | — | **RUNTIME-VERIFIED** |
| **F-INV-06** | **RC-5** — Order Creation reads the channel, not the ERP | DIAGNOSTIC-002 | `product-browser.tsx:114`; **not re-verified** | `product-browser.tsx` | No | No | No | No | No | **Yes** | — | **T-4** | **TASK REQUIRED** | **S2** | REPORT-SOURCED |
| **F-INV-07** | Negative-stock fulfilment contract: 18-case matrix **written, not run** | INVENTORY-NEGATIVE-STOCK-FULFILLMENT-CONTRACT-REPAIR-003 | Environment gate blocked | test matrix | Yes | No | **No** | No | No | **Yes** | runner | **T-6** | **FIXED + NOT FULLY CERTIFIED** | **S1** | REPORT-SOURCED |
| **F-INV-08** | Inventory Count → Waste Investigation → Resolution → AdjustmentOut; no double deduction; blind count; warehouse liability | Master Phase 3 | **Not audited this session** | Inventory modules | — | — | — | — | — | **Unknown** | — | **T-9** | **NOT INVESTIGATED — TASK REQUIRED** | **S1** | — |
| **F-INV-09** | FIFO architecture, stock ledger, warehouse-scoped reservations | Master Phase 3 | **Not audited this session**; `inventory_receipt_layers` present | Inventory modules | — | — | — | — | — | **Unknown** | — | **T-9** | **NOT INVESTIGATED — TASK REQUIRED** | **S1** | — |
| **F-INV-10** | **NEW.** The `inv_agg` subquery feeding product availability sums `inventory_items` with **no `company_id` filter** — another company's rows contribute to your product's Available. Same defence-in-depth pattern as F-CUS-01 | discovered by T-1 proof P12 | **Still true, and true at HEAD** — `git show HEAD` confirms the subquery was never company-scoped. Product LIST is scoped (GD-1 tap), so exposure is bounded, but the availability NUMBER is not | `EloquentProductRepository.php:82-88` | **Yes** | No | **Yes** | No | **Yes** | **No** | — | — | **FIXED + RUNTIME VERIFIED** (P-7 authorised; P12 + P12b green) | — | **RUNTIME-VERIFIED** |
| **F-INV-11** | **NEW (observation).** Two different tenant-scoping strategies now coexist in `EloquentProductRepository`: `inv_agg` scopes by the **actor's** company, while the `ii_c` manufacturing-availability aggregate scopes by the **brand owner's** company. Both are correct; they are not the same rule | discovered repairing F-INV-10 | Recorded, deliberately not unified — doing so would broaden scope beyond the P-7 authorization | `EloquentProductRepository.php:105-112, 150-165` | No | No | No | No | No | **Yes** | Owner | **T-1c** | **CONTRACT DECISION REQUIRED** | S3 | **CODE-VERIFIED** |
| **F-INV-12** | **NEW.** Uncommitted tree work changed availability from clamp-per-warehouse to **signed** in `InventorySummaryService`, breaking the pre-existing `AvailabilityStateDerivationTest` (expects 6.0, gets −2.0). A certified contract's own test is now red and was not caught because that suite had not been run | discovered by T-1 regression | Still true | `InventorySummaryService.php` | No | No | **Yes** | No | **Yes** | **Yes** | — | **T-9/owner** | **PRE-EXISTING — REGRESSION IN UNCOMMITTED WORK** | **S2** | **RUNTIME-VERIFIED** |
| **F-INV-13** | **NEW.** `OrderDrivenMaterialReservationTest` — 14 errors, `bills_of_materials.yield_quantity cannot be null`. BOM fixture/schema mismatch; no availability code involved | discovered by T-1 regression | Still true | BOM fixtures | No | No | **Yes** | No | **Yes** | **Yes** | — | **T-9/owner** | **PRE-EXISTING** | **S2** | **RUNTIME-VERIFIED** |

### 3.4 PREPARATION

| ID | Finding | Origin task | Current state | Files | Code? | DB? | Runtime? | E2E? | Regr? | Open? | Depends on | Next task | Status | Sev | Evidence |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **F-PRP-01** | Operational Wave: start / intake cutoff / end, cross-midnight, `ends_at` authority, `companies.timezone` | WAVE-CROSS-DAY-TRANSITION-002 | **Delivered + certified + deployed**; 65/65; live scheduler proof | wave engine | Yes | Yes | **Yes** | No | Yes | No | — | — | **FIXED + RUNTIME VERIFIED** | — | **SELF-CERTIFIED** |
| **F-PRP-02** | Carry-over, historical membership, one active membership, no duplicate reservation, no duplicate material demand | same | Delivered + certified | membership + unique key | Yes | Yes | **Yes** | No | Yes | No | — | — | **FIXED + RUNTIME VERIFIED** | — | **SELF-CERTIFIED** |
| **F-PRP-03** | Stale waves never closed | WAVE-CONTRACT-RESOLUTION-001 G-3 | Fixed; 3 real stranded waves closed on the live stack | scheduler | Yes | Yes | **Yes** | No | Yes | No | — | — | **FIXED + RUNTIME VERIFIED** | — | **SELF-CERTIFIED** |
| **F-PRP-04** | `orders.preparation_completed_at` orphaned — one writer that cannot fire, zero readers | WAVE-CONTRACT-RESOLUTION-001 §5 | Left in place by G-1 instruction | `Order.php`, `HandlePreparationWaveCompleted` | No | No | Yes | No | No | Yes | — | defer | **OUT OF SCOPE + CONTRACT** | S4 | **CODE-VERIFIED** |
| **F-PRP-05** | `HandlePreparationWaveCompleted` filters `status = 'preparing'`, which is not an `OrderStatus` — matches zero rows | same | Manual-completion path only; not required for wave end | listener | No | No | Yes | No | No | Yes | — | **T-8** | **PRE-EXISTING** | S3 | **CODE-VERIFIED** |
| **F-PRP-06** | Four wave-scoped consumers omit the `postponed_at` filter | same | Pre-existing; wave-scoped so unaffected by carry-over | 4 sites | No | No | No | No | No | Yes | — | **T-8** | **PRE-EXISTING** | S3 | REPORT-SOURCED |
| **F-PRP-07** | Wave settings UI not wired to the new `/configuration/wave-engine` API | CROSS-DAY-TRANSITION-002 §24 | API delivered; UI is a "Coming soon" placeholder | `wave-settings-page.tsx` | No | No | No | **No** | No | **Yes** | — | **T-4** | **TASK REQUIRED** | **S2** | **CODE-VERIFIED** |
| **F-PRP-08** | Approved operational times (18:00 / 08:00 / 15:00) not applied on `ecos_dev` | Master Phase 4 | Config still `00:00 / 23:59 / 23:59:59`; cross-day proven storable and applied-then-restored | `wave_engine_configurations` | No | No | **Yes** | No | No | **Yes** | Owner | **T-1** | **CONTRACT DECISION REQUIRED** | **S2** | **RUNTIME-VERIFIED** |
| **F-PRP-09** | `wave_engine_configurations.timezone` now read by nothing | same | Superseded by `companies.timezone` | model/table | No | No | Yes | No | No | Yes | — | defer | **OUT OF SCOPE / DEFER** | S4 | **CODE-VERIFIED** |

### 3.5 CUSTOMERS / CRM

| ID | Finding | Origin task | Current state | Files | Code? | DB? | Runtime? | E2E? | Regr? | Open? | Depends on | Next task | Status | Sev | Evidence |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **F-CUS-01** | `OrderResource` customer-stats query filters `customer_id` + `deleted_at` with **no `company_id`**, exposing `total_orders`, `lifetime_value`, `first_order_date`, `last_order_date` | CUSTOMER-DOMAIN-FINAL-CLOSURE-001 §24.1 | **Still true today** — confirmed at `OrderResource.php:82-85` | `OrderResource.php:82` | No | No | No | No | No | **Yes** | — | **T-4** | **TASK REQUIRED** (Orders domain) | **S2** | **CODE-VERIFIED** |
| **F-CUS-02** | `useCustomerOrderStats` aggregates in React over a 200-order page; a customer with 200+ orders sees Orders screens disagree with the Customers workspace | same §24.2 | Only `preferredGovernorate` moved server-side | `useCustomerOrderStats` | No | No | No | No | No | **Yes** | — | **T-4** | **TASK REQUIRED** | **S2** | REPORT-SOURCED |
| **F-CUS-03** | Customer full regression **BLOCKED** — shared runner occupied by another agent | same §19, §23 | Never executed | `Modules/Sales/Customers/Tests` | No | No | No | No | **No** | **Yes** | runner | **T-6** | **FIXED + NOT FULLY CERTIFIED** | **S1** | REPORT-SOURCED |
| **F-CUS-04** | Permissions PASS **with a documented CONTRACT GAP** (Sales vs CRM authorization semantics) | same §25 | Gap recorded, unresolved | permissions | No | No | No | No | No | **Yes** | Owner | **T-2** | **CONTRACT GAP** | **S2** | REPORT-SOURCED |
| **F-CUS-05** | Tenant isolation: timeline fixed, **its test unrun** | same | Fix in place, unverified | timeline | Yes | No | No | No | **No** | **Yes** | runner | **T-6** | **FIXED + NOT FULLY CERTIFIED** | **S1** | REPORT-SOURCED |
| **F-CUS-06** | Browser E2E pending | same §23 | PENDING USER BROWSER SMOKE | UI | — | — | — | **No** | — | **Yes** | Human | **T-10** | **TASK REQUIRED** (human) | **S1** | REPORT-SOURCED |
| **F-CUS-07** | Last Order = `MAX(order_date)`; preferred governorate `COUNT DESC` then governorate `ASC`; timeline company-scoped, soft-delete safe, `occurredAt` then `refId` | Master Phase 5 contracts | Reported PASS; **not re-verified this session** | customer services | — | — | — | — | — | **Yes** | — | **T-9** | **NOT VERIFIED** | **S2** | REPORT-SOURCED |

### 3.6 PROCUREMENT (Purchasing)

| ID | Finding | Origin task | Current state | Files | Code? | DB? | Runtime? | E2E? | Regr? | Open? | Depends on | Next task | Status | Sev | Evidence |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **F-PRC-01** | **No `PurchaseRequest` concept exists.** The approved contract names Purchase Request = warehouse need / purchasing guide, but the module tree has `PurchaseOrders`, not `PurchaseRequests` | Master task Procurement contract | Divergence between approved vocabulary and the shipped module | `backend/Modules/Purchasing/*` | No | No | No | No | No | **Yes** | Owner | **T-11** | **CONTRACT GAP** | **S2** | **CODE-VERIFIED** |
| **F-PRC-02** | Module surface present: `GoodsReceipts`, `PurchaseOrders`, `SupplierInvoices`, `Suppliers`, `PurchaseMaterials`, `SupplierReturns` | — | Exists; behaviour **not audited** | as listed | — | — | — | — | — | **Unknown** | — | **T-11** | **NOT INVESTIGATED** | **S2** | **CODE-VERIFIED** (existence only) |
| **F-PRC-03** | Contract chain — Goods Receipt mutates Inventory/FIFO; Supplier Payable/Payment financially separate; Raw Materials only for V1; supplier↔RM pricing history; consumption intelligence | Master task contract | **Not audited this session** | Purchasing + Inventory | — | — | — | — | — | **Unknown** | — | **T-11** | **NOT INVESTIGATED — TASK REQUIRED** | **S1** | — |

### 3.7 PRICING / COST

| ID | Finding | Origin task | Current state | Files | Code? | DB? | Runtime? | E2E? | Regr? | Open? | Depends on | Next task | Status | Sev | Evidence |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| **F-PRI-01** | Price review action / snooze / assign HTTP contract repairs | PRICE-REVIEW-ACTION-REPAIR-001, PRICING-REVIEW-SNOOZE-ASSIGN-HTTP-CONTRACT-REPAIR-001 | Report verdicts inconclusive on a naive scan; **not read in full** | CostManagement | — | — | — | — | — | **Unknown** | — | **T-9** | **NOT INVESTIGATED** | **S2** | — |
| **F-PRI-02** | Pricing review cost-management final certification | PRICING-REVIEW-COST-MANAGEMENT-FINAL-CERTIFICATION-001 | Indexed only | CostManagement | — | — | — | — | — | **Unknown** | — | **T-9** | **NOT INVESTIGATED** | **S2** | — |

### 3.8 CROSS-CUTTING

| ID | Finding | Origin | Current state | Open? | Next task | Status | Sev | Evidence |
|---|---|---|---|---|---|---|---|---|
| **F-XC-01** | **No per-agent test isolation.** `ecos_dev_test` is one shared schema, `tests/TestCase.php` pins it in code, and `RefreshDatabase` runs `migrate:fresh` — two agents running suites concurrently destroy each other's schema mid-run | ORDERS-INVENTORY-EXECUTION §23.4; CUSTOMER-CLOSURE §19 | **MITIGATED.** `scripts/test-gate.sh` delivered and proven over six scenarios — advisory lock + `/proc` scan + DDL detector. Gated runs cannot collide; ungated runs are detected and refused | **Partly** | **T-6 done**; harness lock = **T-6b** | **FIXED + RUNTIME VERIFIED** (detection); **CONTRACT DECISION REQUIRED** (harness lock changes all agents' behaviour) | **S2** (was S1) | **RUNTIME-VERIFIED** |
| **F-XC-06** | **NEW.** An **ungated** run still cannot be *prevented*, only detected — a wrapper cannot stop another agent typing `php vendor/bin/phpunit`. Closing it requires a `GET_LOCK` inside `tests/TestCase.php`, which makes a second concurrent suite **wait** rather than corrupt | T-6 §5.1 | Specified, **not applied** — changes behaviour for every agent | **Yes** | **T-6b** | **CONTRACT DECISION REQUIRED** | **S2** | **RUNTIME-VERIFIED** |
| **F-XC-02** | Full `php artisan migrate` on `ecos_dev_test` fails on a non-idempotent `timeline_events` migration | this session | Blocks clean environment rebuild | **Yes** | **T-6** | **TASK REQUIRED** | **S2** | **RUNTIME-VERIFIED** |
| **F-XC-03** | Certified-baseline parity broken between host and `ecos-dev-app` (volume not hot-mounted) | ORDER-FULFILLMENT-STATE-CONTRACT-001 Part 16 | Recurs whenever `docker cp` is skipped | **Yes** | **T-6** | **PRE-EXISTING** | **S2** | REPORT-SOURCED |
| **F-XC-04** | **No authenticated browser session available.** Phase 7 + Phase 13 both require it | Master task | Structural | **Yes** | **T-10** | **TASK REQUIRED** (human) | **S1** | **RUNTIME-VERIFIED** |
| **F-XC-05** | V15 acceptance — one order traversing `new → in_progress → ready_for_dispatch` — recorded as **never achieved in this environment** | ORDER-FULFILLMENT-STATE-CONTRACT-001 | Superseded in part: ORD-00001/3/4 reached `ready_for_dispatch` today | Partial | **T-5** | **NOT VERIFIED** | **S1** | **RUNTIME-VERIFIED** (partial) |

---

## 4. Domain certification readiness

| Domain | Verdict | Blocking |
|---|---|---|
| **ORDERS** | **NOT CERTIFIED** | F-ORD-03, F-ORD-09, F-ORD-10, F-ORD-16 (decisions); F-ORD-05, F-ORD-07, F-ORD-12, F-ORD-13, **F-ORD-14**, F-ORD-19; UI gate |
| **PRODUCTS** | **NOT CERTIFIED** | Availability contract **RESOLVED + CERTIFIED** (T-1). Remaining: F-PRD-08 (FG data decision) and the UI gate |
| **INVENTORY** | **NOT CERTIFIED** | F-INV-01…04, F-INV-07; F-INV-08/09 not investigated |
| **PREPARATION** | **CERTIFIED (engineering)** — **NOT CERTIFIED (domain)** | Wave certified with runtime proof; blocked only by F-PRP-07 (UI) and F-PRP-08 (config decision) |
| **CUSTOMERS / CRM** | **NOT CERTIFIED** | F-CUS-01…06 |
| **PROCUREMENT** | **NOT CERTIFIED** | F-PRC-01 contract gap; F-PRC-02/03 not investigated |
| **PRICING / COST** | **NOT CERTIFIED** | Not investigated |
| **CROSS-DOMAIN** | **NOT CERTIFIED** | F-XC-01, F-XC-04, F-XC-05 |
| **PRE-SHIPPING FOUNDATION** | **NOT CERTIFIED** | — |

---

## 5. Contract decisions required — for the owner

None of these may be taken by engineering. Ordered by blocking severity.

| # | Decision | Why it blocks | Origin |
|---|---|---|---|
| **D-A** | Which state applies to `untracked` + Allow Negative ON — and **is `untracked` a business state at all?** Master Phase 2 says exactly three states; the code holds both answers simultaneously | Products + Inventory certification; F-PRD-01…05 | INV-NEGATIVE-STOCK-SEMANTICS-001 |
| **D-B** | Canonical terminology: ADR-027 "Case 3 — Negative Stock" vs UI "Backorder Allowed" vs Master Phase 2 "Negative Allowed" | Same; three names for one concept across API, UI and ADR | same |
| **D-C** | CASE 5 — `on_hand < 0` with Allow Negative OFF | Inventory certification | same |
| **Q2** | No-coverage order status: `ProcessOrderWorkflow` → `AwaitingStock` vs Branch Assignment Engine → leave alone. **Both certified; one must be retired** | Orders certification | ORDER-AWAITING-STOCK-DIAGNOSTIC-001 |
| **Q3** | What re-triggers warehouse assignment? Today nothing does, so every failed assignment is permanent | Orders certification | same |
| **D1/D2** | What does an operator's Entry Status selection mean, and may a payment method override it? | Orders certification | ORDER-INVENTORY-FULFILLMENT-CONTRACT-RECOVERY-001 |
| **P-1** | Operational wave times on `ecos_dev`: adopt 18:00 / 08:00 / 15:00, or keep the current values? | Preparation domain closure | this session |
| **P-2** | Is `FG-000001.allow_negative_stock = 0` intended while both components are `1`? | Products data correctness | ORDER-AWAITING-STOCK-DIAGNOSTIC-001 |
| **P-3** | Procurement vocabulary: is "Purchase Request" a missing concept, or is `PurchaseOrders` the approved name for it? | Procurement scoping | this session |
| **P-4** | **VAT/tax** — orders cannot carry tax and no tax configuration screen exists. Egyptian VAT is mandatory, so no compliant invoice can be issued. Is this in scope before Shipping? | Orders certification; legal | TASK-UAT-006 |
| **P-5** | **Harness test lock** — may `tests/TestCase.php` acquire a blocking `GET_LOCK`? It makes a second concurrent suite wait rather than corrupt, which is correct but will look like a hang and changes behaviour for every agent | Test isolation completeness | T-6 §5.1 |
| ~~**P-7**~~ **ANSWERED — authorised, fix delivered and proven** | **T-1 closure** — authorise the one-line company scoping of `inv_agg` (F-INV-10) so mandatory proof P12 passes, or rule P12 out of T-1 scope and certify on P1–P11. **Recommend authorising.** | T-1 certification | T-1 proof run |
| ~~**P-6**~~ **ANSWERED — approved, now live** | **T-1 behaviour change** — under the approved rule, every product with no inventory row and Allow Negative ON stops reading "Not tracked" and starts reading "Negative Allowed". Correct, and a visible change to live catalogue rows. Confirm before it lands | Products/Inventory | T-1 §6 |

---

## 6. Recommended execution order

Dependency-ordered. **T-1 and T-6 come first and are largely independent of each other** —
one is a decision, the other is infrastructure, and everything else waits on them.

| Order | Task | Scope | Unblocks | Type |
|---|---|---|---|---|
| ~~**T-1**~~ **DONE — CERTIFIED** | **Availability Contract Resolution** — rule D-A/D-B/D-C, then unify the enum, the resource, the repository filter, both UI surfaces and ADR-027 onto one model | F-PRD-01…05, F-PRD-08, F-INV-01…04, F-PRP-08 | Products, Inventory | Decision → implementation |
| **T-2** | **Order Assignment & Coverage Contract** — rule Q2/Q3, retire the losing contract, define the re-assignment trigger | F-ORD-09, F-ORD-10, F-CUS-04 | Orders | Decision → implementation |
| **T-3** | **Entry Status Contract + configuration migration sweep** — rule D1/D2; sweep every store of pre-V3 status vocabulary | F-ORD-03, F-ORD-04 | Orders | Decision → implementation |
| **T-4** | **UI/API parity repairs** — `OrderResource` company scope, `useCustomerOrderStats` server-side, wave settings wiring, order-creation availability source | F-CUS-01, F-CUS-02, F-PRP-07, F-INV-06 | Customers, Preparation | Implementation |
| **T-5** | **Orders lifecycle verification** — `partial_reserved`, zero-line, D-1 activation, full V15 traverse | F-ORD-05, F-ORD-07, F-ORD-13, F-XC-05 | Orders, Cross-domain | Verification + repair |
| **T-6** | **Test-runner isolation** (per-worker DB or advisory lock) + `timeline_events` idempotency + `docker cp` parity gate. **Do this early** — it gates every certification run below | F-XC-01…03, F-ORD-12, F-INV-07, F-CUS-03, F-CUS-05 | Everything | Infrastructure |
| **T-7** | Legacy data repair — `ecos_erp` stale reservation rows | F-ORD-08 | — | Data |
| **T-8** | Small pre-existing cleanups — `HandlePreparationWaveCompleted`, four `postponed_at` filters, ADR-027 zero-line clause | F-PRP-05, F-PRP-06, F-ORD-06 | — | Implementation |
| **T-9** | **Phase 0 completion** — audit the ~46 indexed-only reports; Inventory Count/Waste/Liability/FIFO; Customers contract re-verification; Pricing/Cost | F-INV-08/09, F-CUS-07, F-PRI-01/02 | Inventory, Customers, Pricing | Audit |
| **T-10** | **Human browser smoke** across all domain UIs | F-CUS-06, F-XC-04, all UI gates | Everything | **Human — cannot be automated here** |
| **T-11** | Procurement audit against the approved contract | F-PRC-01…03 | Procurement | Audit |
| ~~**T-1b**~~ **DONE** | company-scope the `inv_agg` inventory aggregation so T-1's P12 can pass | F-INV-10 | Products, Inventory | Decision (P-7) → 1-line fix |
| **T-1c** | **NEW** — decide whether to unify the two tenant-scoping strategies in `EloquentProductRepository` (F-INV-11) | F-INV-11 | Inventory | Decision |
| **T-12** | **NEW** — Orders capability gaps: VAT/tax (mandatory, no configuration exists anywhere), Quotations, Order Approval, Returns workflow, Reports | F-ORD-16, F-ORD-17 | Orders | **Product decision** → implementation |
| **T-6b** | **NEW** — harness-level test lock in `tests/TestCase.php`, making isolation enforceable for *all* agents rather than only gated runs | F-XC-06, remainder of F-XC-01 | Everything | Decision → infrastructure |

---

## 7. Remaining Phase 0 inventory — the ~46 indexed-only reports

Not yet read in substance. Assigned to **T-9**. Grouped so it can be split:

- **Preparation (9)** — GOLIVE-PREPARATION ×6, PREPARATION-DAILY-WAVE-LIFECYCLE, DEMAND-PREPARED-EXPORT-PRINT, WAVE-WORKSPACE-FRONTEND-COMPLETION
- **Recipe/BOM (7)** — GOLIVE-RECIPE ×5, BUG-BOM-DATA-LOSS-001, GOLIVE-BOM-OWNERSHIP-CONTRACT
- **Inventory (5)** — INV-RAW-MATERIAL-POLICY-TOGGLE, INV-RAW-MATERIALS-REGRESSION, UAT-005, PERMISSION-INVENTORY, WAREHOUSE-COVERAGE-BRAND-ASSIGNMENT
- **Pricing/Cost (8)** — all PRICE-REVIEW and PRICING-REVIEW reports, DISASSEMBLY-RECIPE-COST
- **Customers (5)** — CUSTOMER-360 ×3, SALES-CUSTOMERS ×2, EPIC-CRM-UI-001, UAT-008
- **Orders/other (12)** — remaining ORDER reports, UAT-003 procurement, UAT-006 sales-orders (**NO-GO**), PRODUCTION-CUTOVER-001-BLOCKED

`TASK-UAT-006-sales-orders` carries a **NO-GO** and sits directly in the Orders domain — it
should be first in T-9.

---

## 8. Compliance statement

Read-only. No production code, migration, schema, database row, configuration value, test or
frontend file was modified. No implementation was started. No repair task discovered here was
executed. No previously approved business decision was reinterpreted or resolved — the nine
open decisions in §5 are recorded as decisions, not answered.

**Master Closure verdict: NOT CERTIFIED.** Phase 0 is partially complete (24 of ~70 in-scope
reports read in substance) and is itself listed as **T-9**.
