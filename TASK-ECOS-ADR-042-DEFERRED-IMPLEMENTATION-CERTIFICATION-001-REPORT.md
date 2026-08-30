# TASK-ECOS-ADR-042-DEFERRED-IMPLEMENTATION-CERTIFICATION-001 — Engineering Report

**ADR-042 Order FSM V3 — Deferred Implementation Source Certification**
Mode: **READ-ONLY source certification** · Date: 2026-08-30 · Primary: `C:\ecos-develop` @ `72ecaddc` (dirty) · Baseline: `2b851c14`
**No source edit, no git mutation, no migration/test execution.**

---

## 1. Executive Summary

The deferred ADR-042 implementation in the dirty `C:\ecos-develop` working set is a **faithful and careful** implementation of the approved contract at its core — the `OrderStatus` enum, the §3.1 payment-proof control, Preparation/Wave eligibility, and the schema are **CERTIFIED**. A read-only 11-area audit found **two real production deviations** plus test-fixture and dead-frontend cleanup and some missing test coverage. None invalidate the contract; all are **targeted, small corrections**.

- **CERTIFIED (5 areas):** status enum, payment/fulfilment control, Preparation eligibility, Wave eligibility, migrations (schema).
- **NEEDS FIX (2 real, production):**
  1. **§7.1 — `VerifyPaymentAction` auto-confirms.** `POST /orders/{order}/verify-payment` routes proof-verify through `ConfirmOrderWorkflow`, advancing `awaiting_payment → confirmed`. §7.1 (2026-08-23 amendment) requires *payment proof verified* to advance to **`in_progress`** only; the canonical `POST /payment-proofs/{proof}/verify` already does the right thing — so this legacy route is both a §7.1 violation and a duplicate transition authority.
  2. **§2/§7/§12 — `DistributionPlanningController` stale KPI list.** `const READY_STATUSES = ['confirmed','preparing']` uses pre-V3 `preparing` and is hardcoded, not enum-derived. (The **core** Distribution admission is correct — `config/distribution.php` derives `[in_progress,confirmed]` from the enum.)
- **NEEDS FIX (test/FE/coverage):** stale `'new'` in a few wave/preparation **test fixtures**; a `DistributionCoreTest` confirmed-status assertion; legacy pre-V3 vocab in mostly-**dead** FE order components; 4 **missing tests** (→ TEST VERIFICATION DEFERRED).
- **§11 note:** the normalisation migration is idempotent and same-deploy, but performs its writes via `DB::table()->update()` + `Schema::…->change()` rather than the "raw SQL only" letter of §11.

**FINAL STATUS: PARTIAL — ADR-042 REMEDIATION REQUIRED** (targeted fixes; core contract certified).

## 2. Execution Environment

Read-only inspection of the uncommitted working-tree implementation at `C:\ecos-develop` (HEAD `72ecaddc`, 139 modified / 0 staged — unchanged). Method: an 11-agent read-only certification workflow (one agent per ADR area) plus independent reads of `OrderStatus.php`, `VerifyPaymentAction.php`, `DistributionPlanningController.php`, `config/distribution.php`, and `routes/api.php`. No test suite, browser check, or DEV mutation.

## 3. ADR-042 Authority

`docs/adr/ADR-042-order-fsm-v3-canonical.md` — **Approved**, v1.0 (2026-08-13), amended 2026-08-21 (D1-A) and 2026-08-23 (A/B/C). Contract confirmed as summarised in §6 of the prior reconciliation-design report.

## 4. Canonical Status Contract — **CERTIFIED**

`backend/Modules/Commerce/Orders/Domain/Enums/OrderStatus.php` (dirty) implements exactly the ADR contract:
- **11 cases** (`OrderStatus.php:26-40`): in_progress, confirmed, ready_for_dispatch, out_for_delivery, delivered, awaiting_payment, awaiting_stock, scheduled, on_hold, cancelled, returned — no extras, none missing (exhaustive `match` at 44-56 and `displayOrder()` at 257-270 would fail to compile if a case changed).
- **`new` removed** — appears only as docblock history (16,19); normalised by migration. Pre-V3 vocab appears in no case/helper.
- **`confirmed` restored** (27) first-class; deliberately absent from `entryStatuses()` (§3).
- **§2.2 `isLocked()`** (77-80) unlocked set = `{in_progress, scheduled, awaiting_payment}` — exact.
- **§7 `fulfilmentEligible()`** (179-185) = closed `[in_progress, confirmed]`. All reservation helpers (`advancesToInProgressOnReservation`, `yieldsToStockBlock`, `decidesAvailabilityAtCreation`, `hasLeftPreparation`) are explicit closed `in_array` lists — **no `!isTerminal()` widening**.
- **§12 SSOT:** FormRequests derive from `OrderStatus::cases()` (`PatchOrderRequest.php:23` et al.); `GET /orders/statuses` served from the enum (`OrderController::orderStatuses()`).
- **orders.status default** corrected to `in_progress` by the V3 migration up() (residual `'pending'` lives only in the superseded create migration + the down() rollback).

## 5. FSM / Transition Review — **NEEDS FIX (1 real)**

- **§5 rule 3 Confirm — CORRECT:** `ConfirmOrderWorkflow::execute` writes `OrderStatus::Confirmed` + `confirmed_at`; `POST /fulfillment/orders/{order}/confirm` → `FulfillmentController::confirm` → engine runs `confirmWorkflow`.
- **§6 reservation boundary — CORRECT:** Confirm is not the reservation trigger; reservation stays in `ProcessOrderWorkflow`/`initiate_order` at `in_progress`.
- **§7.1 — NEEDS FIX:** `VerifyPaymentAction` (`backend/Modules/Commerce/Orders/Application/Actions/VerifyPaymentAction.php:40,79`) injects and runs `ConfirmOrderWorkflow`, so `POST /orders/{order}/verify-payment` (`routes/api.php:578`) advances `awaiting_payment → confirmed`. §7.1 requires *payment proof verified* to advance to `in_progress` only. Canonical alternative exists: `POST /payment-proofs/{proof}/verify` (`routes/api.php:591` → `PaymentProofController::verify`). **This route is a §7.1 violation and a duplicate transition authority.**

## 6. Payment / Fulfillment Control — **CERTIFIED**

`PaymentFulfillmentGate`, `PaymentProof`, `PaymentProofState`, `ReevaluateOrderFulfillmentAction`, `UploadPaymentProofAction` implement §3.1/§4/§7.1: proof-required method forced to `awaiting_payment` at creation; eligibility requires `deposit>=total` AND an active `verified` `payment_proofs` row; the two directions (advance `awaiting_payment→in_progress`; return→`awaiting_payment`) run through `ReevaluateOrderFulfillmentAction`; neither auto-confirms; the gate cannot be bypassed by direct status write (Order guard + `advancesToInProgressOnReservation` excludes `awaiting_payment`, the documented BL-1 backstop). (The §7.1 breach is the *separate legacy verify route* in §5, not this canonical chain.)

## 7. Preparation Eligibility — **CERTIFIED** (test fixtures need fix)

Production Preparation uses the explicit closed `[in_progress, confirmed]` list (via `OrderStatus::fulfilmentEligible()`); no `!isTerminal()` widening. Retention vs admission is correctly separated (`hasLeftPreparation()`, not the eligibility list). **Fix (tests only):** stale `'new'` vocab in `PreparationEntryGateTest` docblock and in two wave fixtures (§18).

## 8. Distribution Eligibility — **NEEDS FIX (secondary surface)**

- **Core admission — CORRECT:** `config/distribution.php` `eligible_order_statuses` is enum-derived to `[in_progress, confirmed]` (docblock cites §7); `loading_eligible_order_statuses` is a separate documented list. Distribution keeps its own list and does not import Preparation's.
- **NEEDS FIX:** `DistributionPlanningController::READY_STATUSES = ['confirmed','preparing']` (`:17`) — a KPI-header list that is hardcoded and carries pre-V3 `preparing` (§2/§7/§12). Secondary surface, not the core admission.

## 9. Wave Engine Eligibility — **CERTIFIED**

The Wave Engine applies its own explicit closed `[in_progress, confirmed]` list (its own reference to the canonical enum method), separate from Preparation and Distribution — not collapsed into a shared broad predicate. Sibling wave tests already assert against `OrderStatus::fulfilmentEligible()`.

## 10. Frontend Status Contract — **NEEDS FIX (cleanup, mostly dead)**

The FE `OrderStatus` union and the live rendering resolve the canonical 11 via i18n. **Fix:** remove stale pre-V3 vocab from `order-inline-status-cell.tsx` (dead, no importers), `order-workflow-actions-panel.tsx` (dead), `manual-order-form.tsx` (`STATUS_LABELS` still has `new`, add `confirmed` — note this file is in the separate 24-tsc-error WIP set), `order-status-tabs.tsx`, `smart-status-selector.tsx`, and remove dead `workflowReturnToProcessing`/`workflowReturnToPending` from `orders-service.ts`. Live impact is limited (dead code / cosmetic fallback styling); labels still resolve.

## 11. Coupled Logistics Review — **NEEDS FIX (1 test) / mostly NOT-ADR-042**

The audit correctly separated ADR-042-coupled Logistics from unrelated Distribution redesign/future work (4 NOT-ADR-042). **Fix (test):** `DistributionCoreTest` "confirmed order" case asserts `in_progress + confirmed_at` rather than a genuine `Confirmed` STATUS (§2.1/§12).

## 12. Route / RBAC Review — **NEEDS FIX (= §5 finding)**

Canonical routes present with RBAC (`/fulfillment/orders/{order}/confirm`, `/orders/statuses`, `/orders/{order}/payment-proofs`, `/payment-proofs/{proof}/verify`). **The one issue is the legacy `POST /orders/{order}/verify-payment` (`api.php:578`) = the §7.1 duplicate/auto-confirm authority (§5).** Known `routes/api.php` source/runtime drift remains a separate concern; not copied or mutated here.

## 13. Migration Review — **CERTIFIED (with §11 mechanism note)**

`2026_08_13_100000_supersede_order_lifecycle_v3_canonical.php`: normalises legacy status values, changes `orders.status` default to `in_progress`, is idempotent, and runs in the same deploy; `2026_07_22_100000_simplify_order_lifecycle_v3.php` is **not modified** (§9); `2026_08_19_140000_create_payment_proofs_table.php` creates the proof table (guarded `hasTable`). No duplicate table/authority; fresh-install coherent. **§11 note:** the up() uses `DB::table()->update()` + `Schema::…->change()` rather than "raw SQL only" as §11's letter specifies (the idempotent + same-deploy requirements are met). Flag for §11-letter review.

## 14. Existing Test Evidence — **TEST VERIFICATION DEFERRED + gaps**

ADR-042 tests exist for supersession, payment-confirmation-gate, payment-proof lifecycle, V3 transition resolution, preparation entry gate, wave operational cycle, orders final certification, payment-preparation eligibility — all mapped to clauses but **execution is out of scope (freeze)** → **TEST VERIFICATION DEFERRED**. Coverage **gaps (MISSING)**: §2.2 `isLocked()` invariant, §6.1 missing-warehouse-still-confirms (operator side), §8 no-legacy-repair regression guard, §12 `GET /orders/statuses` served-from-enum.

## 15. Test Verification Deferred

Per the project freeze, the full suite and browser checks were **not run**. Every clause whose proof needs execution is marked **TEST VERIFICATION DEFERRED**; the four MISSING tests above should be added and run in the future integration task, not guessed here.

## 16. ADR File/Group Classification (§13)

| Group | Class | Note |
|---|---|---|
| `OrderStatus.php` + FormRequests + `OrderController::orderStatuses` | **REQUIRED — CORRECT** | §2/§3/§12 exact |
| PaymentFulfillmentGate / PaymentProof / PaymentProofState / Reevaluate / Upload | **REQUIRED — CORRECT** | §3.1/§7.1 |
| Fulfillment workflows (Confirm/Process/MoveToPreparation/ReturnToPending/…) | **REQUIRED — CORRECT** | §5/§6 |
| `VerifyPaymentAction` + `/orders/{order}/verify-payment` | **REQUIRED — NEEDS FIX** | §7.1 auto-confirm / duplicate authority |
| Preparation eligibility (production) | **REQUIRED — CORRECT** | §7 |
| Distribution core admission (`config/distribution.php`) | **REQUIRED — CORRECT** | §7 enum-derived |
| `DistributionPlanningController::READY_STATUSES` | **REQUIRED — NEEDS FIX** | §2/§7/§12 stale hardcoded |
| Wave eligibility | **REQUIRED — CORRECT** | §7 own list |
| supersede + payment_proofs migrations | **REQUIRED — CORRECT** (§11-letter note) | §9/§11 |
| FE order components (labels/tabs/selector/dead cells) | **REQUIRED — NEEDS FIX** | §2/§8 legacy vocab; mostly dead |
| Wave/Preparation test fixtures with `'new'`; DistributionCoreTest | **REQUIRED — NEEDS FIX** | §2/§12 stale fixtures |
| Unrelated Distribution redesign / future work | **NOT ADR-042** | keep deferred; do not certify here |
| `manual-order-form.tsx` (24-tsc WIP) | **NOT ADR-042 (WIP)** | its `new` label fix belongs to WIP cleanup, not ADR-042 cert |
| — | DUPLICATE / SUPERSEDED / UNKNOWN | none found in the ADR-042 set |

## 17. Deviations (summary)

| # | Deviation | Clause | Severity |
|---|-----------|--------|----------|
| D1 | `VerifyPaymentAction` auto-confirms on proof-verify (legacy `/orders/{order}/verify-payment`) | §7.1 / §5 / §10 | **HIGH (production)** |
| D2 | `DistributionPlanningController::READY_STATUSES = ['confirmed','preparing']` | §2/§7/§12 | MEDIUM (secondary surface) |
| D3 | stale `'new'`/pre-V3 vocab in wave/preparation test fixtures + docblock | §2/§7/§12 | LOW (tests) |
| D4 | `DistributionCoreTest` confirmed case asserts `in_progress+confirmed_at`, not `Confirmed` STATUS | §2.1/§12 | LOW (test) |
| D5 | FE order components carry legacy vocab (mostly dead) | §2/§8 | LOW (cleanup) |
| D6 | 4 missing tests (§2.2/§6.1/§8/§12) | §12 | TEST DEFERRED |
| D7 | normalisation migration not "raw SQL only" (idempotent+same-deploy met) | §11 | LOW (letter) |

## 18. Required Remediation (smallest corrections — NOT implemented here)

1. **D1 (§7.1):** stop `VerifyPaymentAction` from running `ConfirmOrderWorkflow`. Route proof-verify through the canonical re-evaluation so an `awaiting_payment` order advances to `in_progress` (via `ReevaluateOrderFulfillmentAction` / the `/payment-proofs/{proof}/verify` path), or retire the legacy `/orders/{order}/verify-payment` in favour of `/payment-proofs/{proof}/verify`. Confirmation must remain the explicit §5 operator action only.
2. **D2 (§2/§7/§12):** replace `DistributionPlanningController::READY_STATUSES` with the enum/config-derived closed list (`config('distribution.eligible_order_statuses')` = `[in_progress, confirmed]`); drop `preparing`.
3. **D3:** `PreparationEntryGateTest` docblock → `[in_progress, confirmed]`; `WavePostponeOrderTest:405` and `WaveDeferredOrderCutoffReturnTest:133` `['new','in_progress']` → `OrderStatus::fulfilmentEligible()`.
4. **D4:** `DistributionCoreTest` confirmed case → create the order with `OrderStatus::Confirmed` via the engine.
5. **D5:** remove `new`/pre-V3 keys and add `confirmed` in `manual-order-form.tsx`, `order-status-tabs.tsx`, `smart-status-selector.tsx`; delete dead `order-inline-status-cell.tsx` / `order-workflow-actions-panel.tsx` and dead `orders-service` methods (or fold into canonical helpers).
6. **D6:** add + run tests for §2.2 `isLocked()`, §6.1 operator-Confirm-with-null-warehouse, §8 no-legacy-repair, §12 `GET /orders/statuses` (future task).
7. **D7:** confirm with the ADR owner whether §11's "raw SQL only" is satisfied by the current query-builder/Schema mechanism or requires a rewrite (idempotency + same-deploy already hold).

## 19. Distribution Unblock Decision

**DISTRIBUTION ADR-042: SOURCE CERTIFIABLE AFTER FIXES.**
- Core admission contract is present and correct (`config/distribution.php`, enum-derived `[in_progress, confirmed]`, own closed list).
- Blockers before unblock: **D2** (`DistributionPlanningController` stale hardcoded list) and **D1** (the order-eligibility feed must not auto-confirm on proof-verify). Both are small, targeted fixes; nothing is architecturally missing and `agent-ad776` is not needed.

## 20. Baseline Integration Decision

**ADR-042 CLASS-A SET: APPROVED AFTER TARGETED REMEDIATION.**
The class-A ADR-042 implementation is faithful and may be integrated into the clean develop baseline **after** D1–D5 are applied (D6 tests added/run, D7 confirmed). This is approval for a **future** reconciliation task only — nothing is integrated here.

## 21. Reconciliation Inputs

For the future preservation-first reconciliation (design report §22): class-A integration is gated on this remediation (D1–D5) + ADR-042 certification sign-off. The two production fixes (D1, D2) and the test/FE cleanup are the exact scope. The §5/§8 collision files from the design report still apply (`phpstan-baseline`, `V3TransitionResolutionTest`, `routes/api.php`, nav). The 24-tsc-error WIP set (incl. `manual-order-form.tsx`) stays **outside** ADR-042 and is preserved separately; the navigation restructure is **not** integrated to serve ADR-042.

## 22. CTO Decisions Required

1. **Approve the targeted remediation scope (D1–D7)** and authorise a separate implementation task (D1 §7.1 fix is the priority — a real runtime auto-confirm).
2. **Confirm §11 letter** (raw-SQL-only) disposition for the normalisation migration (D7).
3. **Authorise the 4 missing tests** (D6) in the integration task (freeze currently defers execution).
4. Confirm the class-A set is **APPROVED AFTER TARGETED REMEDIATION** for the future reconciliation, and that the WIP set + nav restructure stay separate.

---

FINAL STATUS: **PARTIAL — ADR-042 REMEDIATION REQUIRED**
DISTRIBUTION ADR-042: **SOURCE CERTIFIABLE AFTER FIXES** (D1, D2)
ADR-042 CLASS-A SET: **APPROVED AFTER TARGETED REMEDIATION**
`C:\ecos-develop`: **UNTOUCHED** (read-only; HEAD `72ecaddc`, 139 modified invariant intact)
NEXT: **targeted remediation (D1–D7) → ADR-042 certification sign-off → preservation-first reconciliation**
