# TASK-ORDERS-PAYMENT-TIME-SHIPPING-REMAINING-001 — Engineering Report

**Date:** 2026-08-20
**Scope:** Remaining/delta work only — Payment UX (A), Order Time (B), Shipping remaining (C).
**Frozen:** Financial Settlement (Part D) — no financial consequence of delivery shortage implemented.
**Discipline:** Audit-first. Every item was classified *before* implementing; completed features were **not** rebuilt.

---

## 1. Outcome

| Part | Result |
|------|--------|
| A — Payment remaining UX | **DONE** (A3 inline method edit, A4 record-payment, A5/A6 grid action, A7 permission gating) |
| B — Add time to orders | **DONE** (B1 grid order date+time, B3 requested-delivery column surfaced, B4 drawer order date+time) |
| C — Shipping remaining | **AUDITED** — canonical backend already exists; the only missing pieces are **CONTRACT GAPS** (§6), not buildable within this delta without new backend contracts |
| D — Financial Settlement | **FROZEN — untouched** |
| E — Tests | **DONE** — 8/8 new + 11/11 regression, all green |
| F — Browser smoke | **BLOCKED** — no runtime auth available in this environment |
| G — Concurrency safety | **HONORED** — no `checkout`/`reset`/`clean`/`restore`; edits merged into co-modified files; file stability verified before each edit |
| H — Static verification | **GREEN** — php -l, Pint, PHPStan L0, tsc (baseline 23), ESLint, vite build |

---

## 2. Audit classification (before any code)

### Part A — Payment
| Item | Classification | Action |
|------|----------------|--------|
| `payment_state` derivation (deposit vs total; deposit = PARTIALLY_PAID) | ALREADY COMPLETE (OrderResource) | reused, not rebuilt |
| `RecordOrderPaymentAction` (cumulative deposit, overpayment guard, never writes status) | ALREADY COMPLETE | reused as the sole money path |
| `PaymentProof` lifecycle + verify/reject routes + permissions | ALREADY COMPLETE | reused |
| Grid payment column shows method only | REMAINING | A5/A6 added a "Payment & Proof" row action |
| No inline payment-method edit | REMAINING | A3 added (Popover, quick-update) |
| No "record full/partial payment" affordance in drawer | REMAINING | A4 added (RecordPaymentDialog → canonical action) |
| Proof upload/verify/reject shown to all | REGRESSION (missing permission gate) | A7 gated on `proof_upload`/`proof_verify`/`proof_reject` |

### Part B — Time
| Item | Classification | Action |
|------|----------------|--------|
| `created_at` present but date-only in grid & drawer | REMAINING | B1/B4 render **date + time** (no duplicate column) |
| Requested delivery date/time | ALREADY COMPLETE in model (`requested_delivery_date`, `preferred_delivery_time`, `delivery_window`) but hidden | B3 surfaced the `delivery_window` column (`defaultVisible: true`) |

### Part C — Shipping
| Item | Classification | Action |
|------|----------------|--------|
| Loading engine (`LoadProductAction`, ShortLoaded), `AllocationRecord`, `RecordProductDeliveryAction` (T-09), reconciliation | ALREADY COMPLETE on `/api/loading/*` | left intact; **not** reconnected to the dead `/api/distribution` stack |
| `order_lines.prepared_qty/loaded_qty/delivered_qty` | DEAD columns (no writer) | not used as truth; live truth = `wave_product_demand`, `loading_tasks`, `allocation_records`, `delivery_return_lines` |
| Driver/Loading operator HEAD UI | CONTRACT GAP (needs backend surface) — see §6 | documented, not stubbed |

---

## 3. Changes made

### Backend (2 files + 1 test)
- **`Modules/Commerce/Orders/Presentation/Http/Requests/PatchOrderRequest.php`**
  Added `payment_method_manual` rule, constrained to the same five-value catalogue the create/update gates enforce (`in:cod,instapay,mobile_wallet,credit_card,bank_transfer`) so an unknown method can never slip past `ConfirmOrderWorkflow`'s proof requirement.
- **`Modules/Commerce/Orders/Application/Actions/PatchOrderAction.php`**
  Added `payment_method_manual` to the `ALLOWED` whitelist. It flows through the **non-status** patch branch (`$order->update(...)`), so it is a label-only write that never touches `Order.status`. (Also collapsed pre-existing column alignment in `resolveWorkflow`'s `match` to satisfy Pint — whitespace only, behaviorally inert; the confirm/payment-guard regression suite confirms this.)
- **`tests/Feature/Orders/OrderPaymentMethodAndSettlementContractTest.php`** *(new)* — 8 tests (§5).

### Frontend (5 components + 2 locale files)
- **`order-payment-cell.tsx`** — A3 inline payment-method editor (Popover + 5-value list), written via the canonical `usePatchOrder` → `PATCH /quick-update {payment_method_manual}`. Gated on `sales.orders.update`; falls back to a read-only badge without the permission. Never mutates status.
- **`record-payment-dialog.tsx`** *(new)* — A4. Computes outstanding = max(0, total−paid), validates amount ≤ outstanding, submits through `useRecordOrderPayment` → `RecordOrderPaymentAction`. Never writes `deposit_amount` or status directly.
- **`order-detail-drawer.tsx`** — A4 wiring ("Record Payment" button shown when `remaining_balance > 0.005` + dialog) and B4 (`created_at` rendered as date **+ time** via `formatDateTime`).
- **`order-column-defs.tsx`** — A5/A6 ("Payment & Proof" row action), B1 (`created_at` date + time on two lines), B3 (`delivery_window` column `defaultVisible: true`).
- **`payment-proof-section.tsx`** — A7 permission gating of Upload/Replace (`proof_upload`), Verify (`proof_verify`), Reject (`proof_reject`).
- **`i18n/locales/{en,ar}/orders.json`** — keys for the record-payment dialog, the `Payment & Proof` action, and `orderDetail.cancel`. Both locales kept at parity.

---

## 4. Static verification (Part H)

| Gate | Result |
|------|--------|
| `php -l` (both backend files + test) | No syntax errors |
| Pint `--test` (both backend files) | **PASS** |
| PHPStan `--level=0` (both backend files) | **[OK] No errors** |
| `tsc -p tsconfig.app.json` | **23 errors** = unchanged baseline, **0 new** |
| ESLint (all 5 changed components) | exit 0 |
| `vite build` | ✓ built |

All backend files synced to `ecos-dev-app` **and** `ecos-dev-testrunner` (container is not bind-mounted; base64-over-stdin transfer).

---

## 5. Tests (Part E)

**New — `OrderPaymentMethodAndSettlementContractTest` — 8 tests, 30 assertions, OK:**
1. valid method persists + surfaces + **status untouched**
2. method outside catalogue → **422**, nothing persisted
3. method edit is **tenant-scoped** → 404 for a foreign company
4. method edit requires `sales.orders.update` → **403** for the unprivileged
5. partial payment → `partially_paid`, deposit set, **status untouched**
6. full payment → `paid`, outstanding 0, **status untouched**
7. overpayment beyond outstanding → **422**, nothing persisted
8. record-payment is **tenant-scoped** → 404 for a foreign company

**Regression — `OrderEditReservationAndPaymentGuardsTest` — 11 tests, 27 assertions, OK** (confirms the whitespace-only edit to `resolveWorkflow` changed no behavior; the confirm gate and payment guards still hold).

Both suites run through `GATE_WAIT=2400 scripts/test-gate.sh` (shared `ecos_dev_test` schema is contended — queued for the advisory lock rather than racing).

---

## 6. Contract gaps (documented, NOT implemented)

These are the honest boundaries of the "remaining" work — each needs a **new backend contract** and is out of scope for a delta task:

1. **C5 — Driver / Loading operator HEAD UI.** The canonical loading/allocation/delivery engines exist on `/api/loading/*`, but there is no operator-facing UI surface bound to them at HEAD. Building one requires backend read endpoints (task/allocation lists per operator) that do not exist yet.
2. **C6 — Delivery GPS / proof-of-delivery capture** has no order-level lat/lng contract on the delivery side (orders carry customer GPS, not a delivery-completion fix).
3. **C13 — Carrier → vehicle mapping** has no contract linking a chosen carrier to a concrete vehicle/driver.
4. **Dead stacks** `/api/distribution/*` and `/api/driver/*` remain **broken/404** and were deliberately **not** reconnected (the task forbids reviving them; the live truth is `/api/loading/*`).
5. **B (timezone)** — order and delivery times render in the client locale; there is no company-timezone normalization contract for these specific fields.

---

## 7. Financial Settlement (Part D) — FROZEN

No financial consequence of delivery shortage was implemented, wired, or scaffolded. `RecordOrderPaymentAction` (settlement of *received* payment) is the pre-existing money path and was only *reused* by A4; it is unrelated to delivery-shortage settlement, which stays frozen.

---

## 8. Concurrency safety (Part G)

- No `git checkout`/`reset`/`clean`/`restore` was run at any point.
- `PatchOrderAction.php`, `order-detail-drawer.tsx`, and the locale files are **co-modified** by this session's cumulative uncommitted Orders work; every edit was a **merge into** existing content, verified line-by-line (the drawer's deletions are this session's own D-series proof/warehouse refactor, not another session's work).
- Before editing `PatchOrderAction.php` I confirmed the file was stable (mtime ~11 min old, diff unchanged across a sampling window) — not actively churning.

---

## 9. Browser smoke (Part F) — BLOCKED

Runtime browser verification requires authenticated access to the app; no auth is available in this environment. **Not claimed as verified.** The frontend is proven only to the extent of type-check, lint, and a clean production build.
