# TASK-CONTRACT-DECISION-AUDIT-001 — ENGINEERING REPORT

**Date:** 2026-08-19 · **Type:** READ-ONLY contract audit (zero production-code changes) · Certification **DEFERRED**.
**Rule applied:** every conclusion cites an exact evidence source (FILE / MODEL / MIGRATION / TEST / SERVICE / CONTROLLER / ADR) or is marked **UNDEFINED**. Inferences are labelled `[INFERENCE]`.

## 1. Executive Summary

| Area | Status |
|---|---|
| **Warehouse → Brand Coverage** | **PARTIALLY DEFINED** — data + routing **semantics are fully certified**; the **write/configuration surface, authorization, and UI are UNDEFINED**. |
| **Payment Status + Payment Proof** | **PARTIALLY DEFINED** — the **authoritative payment state, confirmation gate, and proof-requirement rules are certified**; **Mark-Paid mechanism, proof lifecycle (reject/replace/retain), dedicated authorization, and VerifyPaymentAction's fate are UNDEFINED**. |
| **Overall** | **AUDIT COMPLETE — IMPLEMENTATION BLOCKED ON OWNER DECISIONS** |

The prior task's blanket "CONTRACT DECISION REQUIRED" for both is refined here: **the behavioral contracts already exist and are test-certified.** What is missing is narrower and specific (below).

## 2. Current Verified State
- Warehouse brand coverage: 1 reader (`BranchAssignmentEngine`), 1 model, 1 migration, **3 tests** (incl. a full runtime matrix). **No** controller/route/request/service/policy/permission/UI/seeder.
- Payment: no `PaymentStatus`/`PaymentMethod` enum in Commerce/Orders; the confirm gate + creation gate are certified; `VerifyPaymentAction` is wired to a route but non-functional at runtime and unused by the UI.

---

## 3. Warehouse → Brand Coverage Audit

| # | Question | Answer | Evidence |
|---|---|---|---|
| A | What does one row mean? | "This warehouse, **within this company**, **actively** serves this brand." Fulfilment authority is the warehouse (it holds the stock). | MIGRATION `2026_08_12_100000_create_warehouse_brand_coverage_table.php` L12-32; TEST `WarehouseCoverageBrandAssignmentTest::serveBrand` |
| B | Relation shape? | **Warehouse → Company + Brand** — all three must agree; company_id denormalised for tenant integrity. | MIGRATION L30-32, L44-46; TEST test_9 (cross-tenant deny) |
| C | Global or tenant-scoped? | **Company/tenant scoped.** | MIGRATION L30-32; TEST test_9 |
| D | One warehouse → many brands? | **YES** (one row per brand; `unique(warehouse_id,brand_id)`). | MIGRATION L50-52; TEST test_4 |
| E | One brand → many warehouses? | **YES** (reverse index; two warehouses may serve the same brand). | MIGRATION L57; TEST test_7 |
| F | If NO coverage exists? | The warehouse **serves no brands**; the order is simply **not assigned** — and its **status is not changed** (not forced to awaiting_stock). | MIGRATION L21-28; TEST test_8, test_warehouse_with_no_brand_rows |
| G | Absence = invalid / serves-none / unrestricted? | **"Serves no brands" — an intentional fail-closed state.** Explicitly **never** read as "serves all". Not an error, not unrestricted. | MIGRATION L21-28 ("NO ROWS … SERVES NO BRANDS"; "absence of permission is never permission", ADR-027 §16.4) |
| H | Multiple warehouses cover same brand → routing? | Brand eligibility is a **hard pre-filter applied BEFORE ranking**; among brand-eligible survivors the **existing priority rule** decides (`priority ASC`, or nearest-by-GPS when the order has coordinates). No order splitting. | SERVICE `BranchAssignmentEngine` L108-126, `filterByBrandCoverage`, `selectNearest`; TEST test_6, test_7, test_5 |
| I | Does branch (geography) precede warehouse (brand)? | **No precedence — they are independent AND-conditions**; both must pass. Geography resolves candidate branches (`branch_coverage_areas`), brand filters them (`warehouse_brand_coverage`). | SERVICE `BranchAssignmentEngine` L93-94; TEST test_1/2/3 |
| J | Which role/permission manages coverage? | **UNDEFINED** — no policy, no permission (no reference anywhere in `Modules/IAM`). | grep IAM → 0 matches |
| K | Manual / auto / derived? | Migration **states manual, explicit, row-by-row, and deliberately un-seeded** — but **no write mechanism exists** to perform that manual configuration. So *intent* = manual (defined); *mechanism* = **UNDEFINED**. Whether to add auto-seed is an open decision. | MIGRATION L21-23 ("no seed"; "configured explicitly, row by row"); `WarehouseSeeder` has no coverage rows |
| L | Canonical UI location? | **UNDEFINED** — no frontend reference. (The admin "delivery coverage" workspace is a **different** table: brand→governorate `/brands/{id}/geographies`.) | grep frontend → only `admin/configuration` delivery-geography; `configuration-service.ts` L104-139 |

## 4. Evidence Table — Warehouse (write surface)

| Concern | Exists? | Evidence |
|---|---|---|
| Migration / table | ✅ | `2026_08_12_100000_create_warehouse_brand_coverage_table.php` |
| Model + relationships | ✅ | `WarehouseBrandCoverage` (belongsTo Warehouse, Brand) |
| Reader | ✅ | `BranchAssignmentEngine::filterByBrandCoverage` |
| Certified behavior test | ✅ | `WarehouseCoverageBrandAssignmentTest` (13 cases) |
| Writer (controller/action/service) | ❌ | none in backend (grep: only tests write rows) |
| Route | ❌ | `route:list` → none |
| Request / validation | ❌ | none |
| Policy | ❌ | none |
| Permission | ❌ | none in `Modules/IAM` |
| Frontend / UI | ❌ | none (delivery-coverage is a different table) |
| Navigation entry | ❌ | none |
| Seeder | ❌ | `WarehouseSeeder` has none (by design) |

## 5. Undefined Warehouse Contract Items
- **CONTRACT UNDEFINED — OWNER DECISION REQUIRED:** J (authorization), K-mechanism (how manual config is performed), L (UI location). *(A/B/C/D/E/F/G/H/I are DEFINED with evidence above.)*

**Warehouse status: PARTIALLY DEFINED.**

---

## 6. Payment Lifecycle Audit

Lifecycle traced: **create → payment method → deposit/capture → state → proof → verification → confirmation → reservation → fulfillment.**

| # | Question | Answer | Evidence |
|---|---|---|---|
| A | Authoritative payment state today? | **Derived** from `deposit_amount` vs `total`, combined with the payment method's brand `payment_proof_policy`. | REPO `EloquentOrderRepository` L161-174; SERVICE `ConfirmOrderWorkflow::paymentPermitsConfirmation` L99; TEST `OrderPaymentConfirmationGateTest` |
| B | Stored or derived? | **Both physically exist** — a stored `payment_status` column and a deposit-derived status. | MIGRATION `..._add_payment_status_to_orders_table` (column); REPO L161 (derived) |
| C | Which is authoritative? | **The DERIVED one.** `orders.payment_status` is **orphaned**: it is **not fillable/cast on the Order model, never written by any code, and never read by the gate**. | MODEL `Order.php` (no `payment_status` in fillable/casts); grep Commerce → no writer; gate uses `deposit_amount` |
| D | What is "Paid"? | `deposit_amount >= total`. | REPO L165; `ConfirmOrderWorkflow` L99; TEST test_3 |
| E | "Partially Paid"? | `0 < deposit_amount < total`. | REPO L166-168 |
| F | "Unpaid"? | `deposit_amount` is `0`/null. | REPO L169-173 |
| G | COD vs prepaid? | Proof requirement is per-method (brand policy): **cod/cash → `none`**, **credit_card → `optional`**, **instapay/bank_transfer → `required`**. COD may leave `awaiting_payment` unpaid (paid on delivery); a `required` method must be paid-in-full or have proof attached. | ACTION `CreateManualOrderAction::resolveManualOrderStatus` L310-313; `ConfirmOrderWorkflow` L111-114; TEST test_1/test_2 |
| H | Proof lifecycle? | Proof = a single nullable **string** `orders.payment_proof_path` (max 500). Set at order create/edit, uploaded via the **generic** `POST /api/media/upload`; displayed via `getMediaUrl`. **No dedicated proof model, no versioning.** | MODEL `Order.php` L216; REQUEST `StoreManualOrderRequest` L46; ROUTE `api/media/upload`; RESOURCE `OrderResource` L221 |
| I | Who uploads proof? | **UNDEFINED as a dedicated flow.** The path is supplied through the order form using the generic media upload; there is **no dedicated "upload payment proof" endpoint/action**. | no proof-upload route in Orders; generic `media/upload` |
| J | Who verifies proof? | *Intended:* `VerifyPaymentAction`. *Actual operative path:* `ConfirmOrderWorkflow` gate treats "proof attached" as sufficient to leave `awaiting_payment`. | `ConfirmOrderWorkflow` L80,L113-114; `VerifyPaymentAction` (broken, §9) |
| K | Can proof be rejected? | **UNDEFINED** — no reject concept exists. | none |
| L | Can proof be replaced? | Technically the single string is overwrite-able; **no defined replace contract**. | MODEL single column |
| M | Is previous proof retained? | **UNDEFINED** — single column, no history/versioning. | MODEL single column |
| N | Approved storage mechanism? | Generic `MediaController` (`POST /api/media/upload`) + Laravel storage disk (`storage/{path}`). | `route:list` |
| O | Authorization required? | **No dedicated payment-verify/mark-paid permission found**; general order-write authorization applies. Dedicated payment role = **UNDEFINED**. | route grep → no payment permission |
| P | Does verification change order status? | **Canonical:** `ConfirmOrderWorkflow` (confirm-customer) moves `awaiting_payment → confirmed` once the payment gate passes. `VerifyPaymentAction` (intended `awaiting_payment → entry status`) is broken. | `ConfirmOrderWorkflow` L80-86,L225; TEST gate |
| Q | Does verification trigger reservation? | **Indirectly:** Confirm reserves inventory **iff not already reserved** (the created-`awaiting_payment`-then-paid path). Deposit capture alone does not reserve. | `ConfirmOrderWorkflow` L166-223 |
| R | Does verification trigger a domain event? | **No dedicated payment domain event.** Confirm fires `OrderConfirmedEvent`; `VerifyPaymentAction` only writes an `OrderEvent` audit row (`payment_verified`). | `ConfirmOrderWorkflow` L255; `Orders/Domain/Events` (no payment event) |
| — | Payment abstraction elsewhere in ECOS? | **Yes but unused by Orders:** `POS\Payment\Domain\Models\Payment` + `PaymentMethodType`; `Purchasing\GoodsReceipts\Domain\Enums\PaymentStatus`/`PaymentMethod`. Commerce Orders reuses none of them. | those files |

## 7. Payment Evidence Table

| Concern | State | Evidence |
|---|---|---|
| `payment_status` column | Exists but **orphaned** (unmapped, unwritten, unread) | MODEL `Order.php`; MIGRATION add_payment_status |
| Derived paid/partial/unpaid | **Authoritative** | REPO L161-174 |
| Payment gate (leave awaiting_payment) | **Certified** | `ConfirmOrderWorkflow::guard/paymentPermitsConfirmation`; TEST `OrderPaymentConfirmationGateTest` (4 cases) |
| Creation-time gate (twin) | **Certified** | `CreateManualOrderAction::resolveManualOrderStatus` L299-320 |
| Proof storage | string path + generic media upload | `Order.php` L216; `api/media/upload` |
| Dedicated proof model/upload/verify endpoint | ❌ | none |
| Mark-Paid endpoint | ❌ | `route:list` → only `verify-payment` |
| Payment domain event | ❌ | none |
| Payment permission/policy | ❌ | none |
| Status-write authority | `FulfillmentEngine` + `OrderStatusGuard`; direct writes throw (P9) | MODEL `Order.php` L143-153 |

## 8. Undefined Payment Contract Items
**PAYMENT CONTRACT UNDEFINED — OWNER DECISION REQUIRED:** I (dedicated upload flow), K (reject), L/M (replace/retain), O (dedicated authorization), and the fate of the orphaned `payment_status` column and of `VerifyPaymentAction`. *(A-G, P, Q, R are DEFINED with evidence.)*

## 9. VerifyPaymentAction Classification

**Classification: WIRED + INTENDED, but NON-FUNCTIONAL AT RUNTIME and UNUSED BY THE UI → effectively legacy/broken. The operative payment-clearance path is `ConfirmOrderWorkflow`'s gate, not this action.**

| Aspect | Finding | Evidence |
|---|---|---|
| Wired? | Yes — `POST /api/orders/{order}/verify-payment` → `OrderController::verifyPayment` → `VerifyPaymentAction`. | CONTROLLER `OrderController` L263-276 |
| Referenced by canonical workflow? | Yes — named as the "attach-and-verify-proof" clearance path. | `ConfirmOrderWorkflow` L78-79 |
| Invoked by frontend? | **No** — the UI's `verify_payment` action calls `bulkConfirm` (the confirm path) or opens the detail drawer; the `verify-payment` endpoint is not called. | `orders-page.tsx` L478,L722; `order-quick-actions.tsx` L135 |
| **BUG (implementation)** | `if ($order->status !== OrderStatus::AwaitingPayment->value)` compares the **enum-cast** `status` to a **string** → always true → **always `abort(422)`**. The action can never pass its own guard. | MODEL `Order.php` L288 (`'status' => OrderStatus::class`); ACTION `VerifyPaymentAction` L38 |
| **CONTRACT VIOLATION** | `$order->update(['status' => $targetStatus])` is a **direct status write outside FulfillmentEngine** → the Order `updating` guard (P9) throws `UnauthorizedOrderStatusWriteException`. Even if the 422 bug were fixed, the write would throw. | MODEL `Order.php` L143-153; ACTION `VerifyPaymentAction` L44-49 |
| Third "list-vs-scalar" concern | `resolveTargetStatus` reads `$policy['source_entry_policies']['manual']` then `OrderStatus::from(...)`. Whether this is a real defect depends on the brand-policy shape. **`[INFERENCE]` — not confirmed; flagged for the owner, not asserted.** | `VerifyPaymentAction` L61-83 |

**Consequence:** "verify payment proof" as an operation is currently satisfied **only** by attaching a proof path (order form) and confirming (`ConfirmOrderWorkflow` gate). A standalone verify/mark-paid action does not function today.

---

## 10. Owner Decision Matrix

### Warehouse Brand Coverage
| ID | Decision | Status | Note |
|---|---|---|---|
| W1 | What does coverage mean? | **DEFINED** | warehouse actively serves brand, tenant-scoped (no decision needed) |
| W2 | Who owns/configures it? | **DECISION REQUIRED** | no policy/permission exists — choose the managing role/permission |
| W3 | Behavior when absent? | **DEFINED** | serves no brands, no assignment, no status change (no decision needed) |
| W4 | Multiple warehouses per brand? | **DEFINED** | yes; priority ASC / nearest tie-break (no decision needed) |
| W5 | Where should the UI live? | **DECISION REQUIRED** | no UI exists — choose location (e.g. Warehouse detail tab) |
| W6 | Manual vs auto/derived/seeded? | **DECISION REQUIRED** | migration mandates manual+unseeded; decide whether to add an explicit auto-seed for single-brand companies (the fresh-env blocker) |

### Payment
| ID | Decision | Status | Note |
|---|---|---|---|
| P1 | Authoritative payment state? | **DEFINED (ratify)** | derived `deposit_amount` vs `total`; decide whether to **retire/backfill** the orphaned `payment_status` column |
| P2 | Supported states? | **DEFINED (ratify)** | paid / partial / unpaid (derived) |
| P3 | How is "Paid" established? | **DEFINED + DECISION** | via `deposit_amount` on order create/edit; decide whether a **dedicated Mark-Paid action** is wanted |
| P4 | How does COD behave? | **DEFINED** | proof `none`; may confirm from awaiting_payment; COD collection-on-delivery is a separate, currently-undefined concern |
| P5 | Proof lifecycle? | **DECISION REQUIRED** | path-string + generic media upload today; reject/replace/retain undefined |
| P6 | Who uploads / verifies / rejects proof? | **DECISION REQUIRED** | upload=order-writer via generic media; verify=confirm gate; reject undefined; dedicated authz undefined |
| P7 | Replaced proof? | **DECISION REQUIRED** | single column, no retention/history |
| P8 | Verification → order transition? | **DEFINED** | confirm gate `awaiting_payment → confirmed` |
| P9 | Verification → reservation? | **DEFINED** | confirm reserves iff not already reserved |
| P10 | VerifyPaymentAction canonical / repairable / legacy? | **DECISION REQUIRED** | classified legacy/broken; decide **repair** (route through FulfillmentEngine + fix enum compare + set payment fields) **or remove** (rely on the confirm gate) — **do not repair speculatively** |

## 11. Recommended Implementation Order (once decisions are made — not part of this task)
1. **Warehouse** (smaller, semantics already certified): decide W2/W5/W6 → then implement a warehouse-scoped write surface + UI mirroring the certified rules. Lowest risk; unblocks order→preparation for fresh environments.
2. **Payment** (larger): decide P1/P3/P5-P7/P10 first (they interact) → then implement. VerifyPaymentAction's fate (P10) gates any payment-action work.

## 12. Explicit STOP / BLOCKED Items
- **Warehouse write surface:** BLOCKED on **W2, W5, W6**. (Semantics need no decision.)
- **Payment operations (Mark-Paid / proof upload-verify-reject):** BLOCKED on **P1, P3, P5, P6, P7, P10**.
- **VerifyPaymentAction:** must **not** be repaired speculatively (per task constraint); its repair-vs-remove is decision **P10**.
- **This task made ZERO code/DB/route/migration/ADR/seed changes.**

## FINAL STATUS
- **WAREHOUSE: PARTIALLY DEFINED** (semantics certified; write surface / authz / UI = CONTRACT DECISION REQUIRED)
- **PAYMENT: PARTIALLY DEFINED** (state + gates certified; Mark-Paid / proof lifecycle / VerifyPaymentAction = PAYMENT CONTRACT DECISION REQUIRED)
- **Overall: AUDIT COMPLETE — IMPLEMENTATION BLOCKED ON OWNER DECISIONS.** Certification remains **DEFERRED**.
