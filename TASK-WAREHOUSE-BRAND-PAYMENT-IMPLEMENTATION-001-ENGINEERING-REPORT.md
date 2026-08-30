# TASK-WAREHOUSE-BRAND-PAYMENT-IMPLEMENTATION-001 — ENGINEERING REPORT

**Date:** 2026-08-19 · **Env:** DEV (`ecos_dev`); live Vite (`5173`) + `ecos-dev-app`; tests gated on `ecos_dev_test`. Certification **DEFERRED**.

## FINAL STATUS
- **A — BRAND → WAREHOUSE COVERAGE: IMPLEMENTATION COMPLETE + RUNTIME VERIFIED**
- **B — PAYMENT: PARTIAL** — payment state + full-payment + VerifyPaymentAction repair **COMPLETE + RUNTIME VERIFIED**; **payment-proof lifecycle (B4/B5/B6) BLOCKED — CONTRACT GAP** (needs a new `payment_proofs` model).

Change set: **9 new files + 9 targeted edits**. No commits. Existing certified semantics/tests preserved (verified by regression). `routes/api.php` edits were **append-only** (no existing line touched); no concurrent modification observed.

---

## A — BRAND → WAREHOUSE COVERAGE  (COMPLETE + RUNTIME VERIFIED)

**Backend.** New `BrandWarehouseCoverageController` (`index`, `update`) + `UpdateBrandWarehouseCoverageRequest`. Reuses the certified `warehouse_brand_coverage` model/table — **no new table, no new engine, no BranchAssignmentEngine duplication.** `update` is an idempotent, transactional sync of the complete set (create/re-activate requested, delete de-selected). Absence of a row = warehouse does not serve the brand (fail-closed) — unchanged.

**API.** `GET /api/brands/{brand}/warehouse-coverage` (list company warehouses + `serves_brand`), `PUT` (save set). Runtime-verified: GET→`serves_brand:false`; PUT→200; DB row created.

**Permission.** Reuses existing **`organization.brands.update`** (the brand-config permission used by sibling brand PUT routes) — no new permission invented. GET is read-only (auth only).

**Tenant isolation.** Brand resolved via `CurrentCompanyService` (foreign-company brand → 404); every requested warehouse validated against the brand's `company_id` (foreign-company id → 422). Cross-company selection is impossible.

**UI.** Brand → detail drawer → **Warehouses** tab (`brand-warehouses-tab.tsx` + hook + service). Checkbox list of the company's warehouses, current enabled state, multi-select, Save, empty-state, and explanatory text ("a warehouse that is not selected cannot be assigned this brand's orders"). No standalone page, no auto-seed.

**Tests (`BrandWarehouseCoverageTest`, 10/10).** enable / enable-multiple / warehouse-serves-multiple-brands / remove / duplicate-idempotent / cross-company-rejected(422) / unauthorized(403) / empty-serves-none / **engine respects endpoint-configured coverage** / **geography+brand AND semantics preserved**. Regression: existing `WarehouseCoverageBrandAssignmentTest` **13/13** (unchanged).

**Browser smoke.** Opened ECOS Holding drawer → Warehouses tab: Main Warehouse rendered checked ("Serves brand"), Save disabled (not dirty). Unchecked → Save enabled → saved → **DB coverage removed (0 rows)**; re-checked → saved → **DB row restored**; full page reload → tab still shows Main Warehouse checked (**persistence proven**).

**Integration Check (C).** ECOS Holding brand → **Main Warehouse enabled and persisted** through the real UI — the fulfillment engine's brand-coverage pre-filter can now recognise Main Warehouse. (Geographic eligibility via `branch_coverage_areas` remains its own existing mechanism; allocation/reservation algorithms untouched.)

---

## B — PAYMENT  (core COMPLETE + RUNTIME VERIFIED; proof lifecycle BLOCKED)

### Payment state (B1) — DONE + VERIFIED
New `PaymentState` enum (`unpaid` / `partially_paid` / `paid`), **derived**, never a second stored truth. Rule: `paid<=0`→UNPAID, `0<paid<total`→PARTIALLY PAID, `paid>=total`→PAID — **a deposit is never PAID**. Exposed by `OrderResource` as `payment_state` / `paid_amount` / `outstanding_amount`, derived from `deposit_amount` vs `total` (the same authority the confirm gate uses). The orphaned `orders.payment_status` column is deliberately **not** written (no conflicting truth).

### Full-payment action (B2) — DONE + VERIFIED
New `RecordOrderPaymentAction` (`POST /orders/{order}/record-payment`, permission `sales.orders.update`). Records the amount into `deposit_amount` (existing architecture), **rejects overpayment beyond outstanding (422)**, is idempotent on a fully-paid order, distinguishes partial from full, yields PAID only at the full total, preserves deposit info, and **never writes Order.status**.

### Partial deposit (B3) — DONE
Payment method and payment state are independent (e.g. COD + PARTIALLY PAID). Methods preserved (COD / card / InstaPay / Bank Transfer) — none invented.

### VerifyPaymentAction repair (B7) — DONE + VERIFIED
Repaired, not removed. **Fixed the enum defect** (`status !== ...->value` compared enum to string → always `abort(422)`) to enum-to-enum. **Removed the forbidden direct status write**: the transition now routes through `FulfillmentEngine::run(ConfirmOrderWorkflow)`, which enforces the payment gate + reservation rules + lifecycle and activates `OrderStatusGuard` (so P9 is satisfied). Proof is attached first (a non-status field) so the gate can see it; proof alone still cannot bypass the gate.

### ConfirmOrderWorkflow gate (B8) — PRESERVED
Unchanged and authoritative. Policy preserved: COD→no proof, card→optional, InstaPay/Bank Transfer→proof required. Verified by the full gate suite.

### Payment UI (B9) — IMPLEMENTED (display)
`order-detail-page.tsx` PaymentCard now shows a derived **Payment State** field (colour-coded), backed by the new resource fields + Order type + i18n keys (en/ar). tsc + eslint clean; vite build green. (Deposit Paid / Remaining Balance already present.)

### Tests
- `OrderPaymentStateTest` **15/15** — zero→UNPAID, partial→PARTIALLY PAID, exact→PAID, record partial/remaining/full, COD variants, **proof alone does not pay**, **partial cannot be PAID**, overpayment 422, idempotent full, **recording payment does not change Order status**, resource exposes derived state.
- `OrderPaymentConfirmationGateTest` **5/5** — the 4 gate cases **plus** `test_verify_payment_advances_an_awaiting_payment_order` (the B7 acceptance test) now pass.

### Runtime (real API, `ecos-dev-app`)
ORD-00001 (total 188): initial **UNPAID** (outstanding 188) → record 100 → **PARTIALLY PAID** (outstanding 88) → record 88 → **PAID** (outstanding 0); order status stayed `awaiting_stock` throughout (payment never touches status). VerifyPaymentAction repair runtime-verified via the gated HTTP suite (real endpoint).

### BLOCKED — CONTRACT GAP: Payment-proof lifecycle (B4 / B5 / B6), and B10/B12(D-G)
Not implemented. B4/B5 require proof to be a **first-class entity** — "do not store filesystem paths as the authoritative proof state", lifecycle **Uploaded / Verified / Rejected**, **replacement with previous proof retained in history**, and separate **upload / verify / reject** permissions (B6). The existing Orders payment architecture holds only a single nullable `payment_proof_path` string and has **no proof model, no lifecycle, no history**. Representing this needs a **new `payment_proofs` model/table + storage-abstraction wiring + 3–4 actions + permissions + UI (verify/replace/reject)** — a new contract/business-rule surface. Per stop-condition D ("a new business rule is required"), and to avoid shipping a large half-verified payment-proof subsystem, this is reported as a scoped follow-up rather than built. Its acceptance tests (B11.13–B11.20) and browser scenarios (B12 D–G), and the Orders-table payment column (B10), depend on it.

---

## VERIFICATION SUMMARY (section E)
| Gate | Result |
|---|---|
| Backend focused tests | ✅ Feature A 10/10 (+engine 13/13); Payment 15/15; Confirm gate 5/5 |
| PHP lint | ✅ |
| Pint (new files) | ✅ (auto-fixed, re-verified PASS) |
| PHPStan (changed files) | ✅ No errors |
| Frontend tsc (changed files) | ✅ 0 errors in changed files |
| ESLint (changed files) | ✅ |
| Vite build | ✅ built in 7.83s |
| Runtime DEV API | ✅ coverage + payment state |
| Runtime DEV UI | ✅ Brand Warehouses tab (persistence proven); payment-state field implemented |

## STOP-CONDITION COMPLIANCE
`routes/api.php` edited append-only (no concurrent modification seen) · reused `warehouse_brand_coverage` (no new table/engine) · no ADR change · warehouse semantics unchanged (regression 13/13) · VerifyPaymentAction repaired only for the confirmed defects, routed through the canonical workflow, no lifecycle/gate/tenant bypass · payment state is derived (no conflicting truth; orphaned column left unwritten) · no SQL business seeding · production untouched · no `git checkout/reset/restore` · no commits.

## FINAL
- **WAREHOUSE: IMPLEMENTATION COMPLETE + RUNTIME VERIFIED**
- **PAYMENT: PARTIAL** — state + full-payment + VerifyPaymentAction repair COMPLETE + RUNTIME VERIFIED; **proof lifecycle BLOCKED — CONTRACT GAP** (new `payment_proofs` model required).
- Certification remains **DEFERRED**.
