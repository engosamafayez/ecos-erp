# TASK-ORDERS-PAYMENT-CONFIRMATION-COD-FULFILLMENT-AUDIT-001 — Audit Report

**Date:** 2026-08-21 · **Environment:** DEV (`ecos_dev`) · **AUDIT ONLY — no code, data, schema or commit changed.**

> **Audit coverage note.** Six parallel traces were launched; **five failed on a session limit**
> and were completed by direct inspection instead. Everything asserted below is backed by code
> or live data I read myself. Areas with thinner coverage are marked **PARTIAL**.

---

## 1. Executive Summary

**The payment gate is correct and was already repaired. The defect is that nothing ever
re-evaluates it.**

Payment facts (money recorded, proof verified) are written by leaf actions that perform **no
lifecycle transition**. The gate lives inside `ConfirmOrderWorkflow::guard()`, which runs only
on an explicit confirm action. There is **no bridge** from "payment became sufficient" to
"order proceeds". So an order can be fully paid, with a verified proof, and sit in
`awaiting_payment` forever.

`VerifyPaymentAction` — the natural bridge, and now correct — is **unreachable from the UI**.

The consequence chain is closed and provable:

```
payment recorded / proof verified  →  (nothing)  →  order stays awaiting_payment
awaiting_payment ∉ ["in_progress","confirmed"]  →  never collected into a Preparation wave
```

**COD is NOT blocked.** Brand policy sets `cod: "none"`, the gate passes, and 10 of 11 live COD
orders progressed normally. COD already behaves per the approved contract.

### The single clearest piece of evidence

**ORD-00003** — `instapay`, **10 000 / 10 000 paid in full**, payment proof **VERIFIED** — is
still `awaiting_payment`. It satisfies the gate on its very first condition. Nothing blocks it;
nothing advances it.

---

## 2. Payment Model

| Column on `orders` | Written by | Status |
|---|---|---|
| `payment_method` | WooCommerce import | live (NULL on all 14 DEV orders) |
| `payment_method_title` | WooCommerce import | live (NULL on all 14) |
| `payment_method_manual` | manual create / PATCH | **the real method field** — populated on all 14 |
| `deposit_amount` / `total` | `RecordOrderPaymentAction` | **the money truth** |
| `payment_status` | *nothing* | **DEAD COLUMN — NULL on all 14 orders** |
| `payment_proof_path` | manual create, verify-payment | legacy, free text |

**Payment state is DERIVED, never stored:** `paid ⇔ deposit_amount >= total`
(`ConfirmOrderWorkflow::paymentPermitsConfirmation`, mirroring `EloquentOrderRepository`).
There is no `payments` / `payment_transactions` table — a payment is `deposit_amount` on the
order plus, optionally, a `payment_proofs` row.

**Finding P-1 (dead column).** `payment_status` has no writer and is NULL on every order, yet it
is still emitted by the API and rendered in the UI. Any logic reading it is reading nothing.

---

## 3. Payment Methods (live brand policy)

```json
"payment_proof_policy": {
  "cod": "none",  "cash": "none",
  "instapay": "required", "credit_card": "required",
  "bank_transfer": "required", "mobile_wallet": "required"
}
```

| Method | Proof required? | Blocks confirm while unpaid? | Fulfillment without confirmation? |
|---|---|---|---|
| `cod` | none | **No** | **Yes** — matches the approved contract |
| `cash` | none | No | Yes |
| `instapay` | required | Yes, until paid **or** VERIFIED proof | No |
| `credit_card` | **required** | Yes | No |
| `bank_transfer` | required | Yes | No |
| `mobile_wallet` | required | Yes | No |

**Finding P-2 (doc vs reality).** `ConfirmOrderWorkflow`'s docblock says
`credit_card → 'optional'`, but the live policy says **`required`**. The code reads the policy,
so behaviour is correct; the comment is wrong and misleads readers.

**Finding P-3 (silent bypass).** `paymentProofRequirement()` returns `'none'` when
`channel_id IS NULL` — so an order with no channel requires **no proof for any method**,
including instapay. Live: **ORD-00008** has `channel_id = NULL`.

---

## 4. Confirmation Path

Real user path today:

| Surface | Endpoint | Effect |
|---|---|---|
| `RecordPaymentDialog` | `POST /orders/{id}/record-payment` | money only — **no status change** |
| `PaymentProofSection` | `POST /orders/{id}/payment-proofs`, `POST /payment-proofs/{id}/verify` | evidence only — **no status change** |
| Quick actions / bulk | `POST /fulfillment/orders/{id}/confirm` | the only real transition |
| — | `POST /orders/{id}/verify-payment` | **exists, but no UI calls it** |

**Finding C-1 (root cause).** Neither recording payment nor verifying a proof triggers any
lifecycle re-evaluation. The gate is evaluated only inside `ConfirmOrderWorkflow`.

**Finding C-2 (orphaned bridge).** `useOrderWorkflowVerifyPayment()` is defined at
`use-orders.ts:259` and **consumed by zero components** (repo-wide grep). The grid's
"Verify Payment" action merely opens the order drawer.

---

## 5. Payment Proof

Two disjoint systems existed; one has been reconciled:

- **Legacy:** `orders.payment_proof_path`, a free-text string.
- **Current:** `payment_proofs` table — Uploaded / Verified / Rejected, supersede history,
  tenant-scoped download, three permissions.

`hasAcceptedPaymentProof()` **was already repaired** (TASK-ORDERS-PREPARATION-PAYMENT-FINAL-FIX-001
D5): the ACTIVE proof (`superseded_at IS NULL`) must be **VERIFIED**. An unreviewed upload is
"evidence submitted, not evidence accepted", and correctly does not clear a required gate.

**Finding PR-1 (security — HIGH).** `payment_proof_path` is unvalidated free text, and the gate
accepts **any non-empty value**. Sending `{"payment_proof_path":"x"}` clears a REQUIRED-proof
gate with **zero money and zero evidence**, needing only `sales.orders.update` — bypassing
`sales.orders.proof_verify` entirely.

**Finding PR-2 (BLOCKER).** `sales.orders.proof_upload`, `proof_verify` and `proof_reject` exist
in the catalogue but are granted to **NO role** (verified in `role_permissions`: 0 grants each).
The entire proof lifecycle 403s for every non-system-role user — so in practice **only a
super-admin can ever clear a required-proof gate**.

---

## 6. Payment Amount Validation — PARTIAL

`RecordOrderPaymentAction` owns overpayment handling. Confirmed: the gate compares
`deposit_amount >= total` only. **No** validation ties a payment to a reference, a proof, or a
proof's amount. Partial payments are representable (`deposit_amount < total`) and correctly do
not satisfy the gate.

**Finding A-1 (idempotency).** `record-payment` has no idempotency key, no reference field, no
row lock and no transaction. Full payments happen to be idempotent; **partial payments
double-apply on retry** — a double-click can overpay.

---

## 7. COD Path — **working as contracted**

`cod → "none"` ⇒ gate passes regardless of money. Live evidence: ORD-00002/06/07/09/10/11/12 are
`ready_for_dispatch` and ORD-00013/14 `in_progress`, **all with `deposit_amount = 0.00`**.

COD is **not** marked paid — `deposit_amount` stays 0 and payment state stays unpaid. Correct
per the approved contract.

**ORD-00008 is the lone COD outlier** (`awaiting_payment`). It is not blocked by the gate — it
has `channel_id = NULL`, so its requirement is `'none'` and the gate passes. It is stuck for the
same reason as ORD-00003: **nothing ever confirmed it**.

---

## 8. Non-COD Path

| Order | Method | Paid | Proof | Gate verdict | Actual |
|---|---|---|---|---|---|
| ORD-00003 | instapay | **10000/10000** | **VERIFIED** | **PASS** | `awaiting_payment` ❌ **stuck wrongly** |
| ORD-00004 | instapay | 0 / 10000 | uploaded only | **BLOCK** | `awaiting_payment` ✅ correct |
| ORD-00005 | instapay | 3000 / 10000 | uploaded only | **BLOCK** | `awaiting_payment` ✅ correct |

The gate is discriminating correctly. ORD-00003 proves the missing transition, not a bad rule.

---

## 9. Order Lifecycle

Guarded: `Order::updating` rejects any `status` write outside `FulfillmentEngine::run()`
(P9 / `OrderStatusGuard`). `ConfirmOrderWorkflow` writes `Confirmed` (ADR-042 §5.3) and accepts
these sources: `in_progress`, `awaiting_payment`, `awaiting_stock`, `on_hold`, `returned`,
`cancelled`.

```
awaiting_payment --[ConfirmOrderWorkflow + payment gate]--> confirmed --> preparation
```

Payment state and order status remain **separate concepts** — the audit found no place where one
substitutes for the other.

---

## 10. Preparation Integration — **no change needed**

`eligible_order_statuses = ["in_progress","confirmed"]` (live). Preparation reads **order status
only** — never payment status, never payment method.

Both required behaviours already hold, provided the order reaches the right status:

- COD, unpaid → reaches `in_progress`/`confirmed` → **eligible** ✅
- Non-COD, unconfirmed → stays `awaiting_payment` → **not eligible** ✅

**Preparation must not be modified.**

---

## 11. VerifyPaymentAction — re-audited

All three previously recorded defects are **NO LONGER TRUE** in the working tree (uncommitted):

| Prior defect | Status now |
|---|---|
| enum compared to `->value` string ⇒ always 422 | **FIXED** — enum-to-enum |
| `resolveTargetStatus` treats a LIST as scalar ⇒ TypeError | **FIXED** — method no longer exists anywhere |
| writes `Order.status` directly ⇒ hits P9 guard | **FIXED** — routes through `FulfillmentEngine::run(ConfirmOrderWorkflow)` |

It logs an `OrderEvent('payment_verified')` with actor and target status.

**It is correct but orphaned** — not canonical, and unreachable from the UI (Finding C-2).

---

## 12. Domain Events / Auditability — PARTIAL

`OrderConfirmedEvent` fires from `ConfirmOrderWorkflow`. `VerifyPaymentAction` writes
`OrderEvent('payment_verified')`.

**Finding E-1.** No event is emitted when **payment is recorded** or when a **proof is
verified**, and no listener maps either to a lifecycle transition. This is the mechanical form
of the root cause.

**Finding E-2.** No event is emitted when the **payment method changes** — see §13.

---

## 13. Payment Method Change

Changing `payment_method_manual` writes the field and nothing else. **No** status re-evaluation,
**no** event, **no** eligibility recomputation.

So the desired rule — *"changing to COD removes the need for confirmation and the order enters
the appropriate fulfillment state, without being marked paid"* — is **NOT implemented**. The
order keeps sitting in `awaiting_payment` until someone confirms it manually.

Reverse (COD → non-COD): **no rule exists.** An already-confirmed order is not re-blocked. That
is a **business decision**, not a defect — recorded, not invented.

---

## 14. Permissions

- `sales.orders.*` includes `proof_upload`, `proof_verify`, `proof_reject`.
- **All three are granted to zero roles** (Finding PR-2 — blocker).
- Clearing a required gate via `payment_proof_path` needs only `sales.orders.update`, bypassing
  the proof permissions (Finding PR-1).

**Finding PERM-1.** The proof permission model is currently unenforceable *and* trivially
bypassable at the same time.

---

## 15. Frontend

Present: `RecordPaymentDialog`, `PaymentProofSection` (upload / verify / reject),
payment-method editing, `confirmCustomer`, quick-action confirm.

Absent: **any control that completes payment-confirmation → fulfillment.** The hook exists;
nothing renders it. An operator who records payment or verifies a proof sees the payment facts
change and the order stay exactly where it was — precisely the reported symptom.

---

## 16. API Contract

| Method | Route | Permission | Side effect |
|---|---|---|---|
| POST | `/orders/{id}/record-payment` | `sales.orders.update` | `deposit_amount` only |
| POST | `/orders/{id}/verify-payment` | `sales.orders.update` | proof path + confirm workflow — **no UI caller** |
| POST | `/orders/{id}/payment-proofs` | `sales.orders.proof_upload` | proof row |
| POST | `/payment-proofs/{id}/verify` | `sales.orders.proof_verify` | proof state |
| POST | `/fulfillment/orders/{id}/confirm` | fulfillment | the real transition |

---

## 17. Idempotency / Duplicates

- Double-confirm: safe — the guard rejects a non-allowed source status.
- **Record payment retry: UNSAFE for partial payments** (Finding A-1).
- Same reference twice: **undetectable** — no reference field exists.
- Proof verify twice: safe — `uploaded → verified` only.

---

## 18. WooCommerce Sync — **NOT AUDITED**

The agent covering outbound sync failed on the session limit and I did not re-run it. **No claim
is made.** This must be established before implementing anything that changes order status
automatically.

---

## 19. Failure Matrix

| # | Scenario | Current Result | Expected | Root Cause | Class |
|---|---|---|---|---|---|
| 1 | COD + pending | Proceeds; stays unpaid | Same | — | **PASS** |
| 2 | COD + fulfillment | Eligible | Same | — | **PASS** |
| 3 | Non-COD + pending | Blocked | Blocked | Gate correct | **PASS** |
| 4 | **Non-COD + valid confirmation** | **Stays `awaiting_payment`** | Confirmed → Preparation | **C-1** no bridge; **C-2** orphaned hook | **FAIL (primary)** |
| 5 | Non-COD + invalid proof | Blocked (uploaded ≠ verified) | Blocked | — | **PASS** |
| 6 | Confirmation retry | Safe | Safe | — | **PASS** |
| 7 | **Method → COD** | Field changes only; still stuck | Becomes fulfillment-eligible, still unpaid | **E-2** no re-evaluation | **FAIL** |
| 8 | COD → non-COD | No re-block | Undefined | No rule | **BUSINESS DECISION** |
| 9 | Already confirmed | Rejected by guard | Rejected | — | **PASS** |
| 10 | **Amount mismatch / retry** | Partial payment double-applies | Idempotent | **A-1** | **FAIL** |
| 11 | **`payment_proof_path:"x"`** | Clears a REQUIRED gate | Rejected | **PR-1** | **FAIL (security)** |
| 12 | **Proof lifecycle, non-super-admin** | 403 everywhere | Works for granted roles | **PR-2** | **BLOCKER** |

---

## 20. Minimum Fix Plan (proposed — NOT implemented)

**A. Payment Confirmation (the primary fix).** Re-evaluate the existing gate when payment facts
change. Smallest form: after `record-payment` succeeds and after a proof reaches `verified`,
attempt `FulfillmentEngine::run(ConfirmOrderWorkflow)` when the order is `awaiting_payment`, and
swallow `WorkflowPreconditionException` (still short ⇒ stay put). Reuses the engine, the gate,
the workflow and existing permissions. **No new status, no new event, no migration.**

**B. Payment Proof.** Stop accepting arbitrary `payment_proof_path` as proof of payment
(PR-1) — prefer the `payment_proofs` authority, keeping the legacy column readable for
historical/WooCommerce orders only.

**C. COD fulfillment.** No code change — already correct. Fixed as a side effect of A.

**D. Payment Method change.** Re-run the same re-evaluation from A after
`payment_method_manual` changes. Switching to COD then advances the order without marking it
paid.

**E. Preparation.** **No change.** Already correct.

**F. Frontend.** Wire the orphaned hook (or rely on A and simply invalidate the order query so
the new status appears). Fix the dead `payment_status` display (P-1).

**G. Tests.** §21.

**Sequencing note:** A alone fixes matrix rows 4 and 7. PR-2 is a **blocker for real operator
use** and should be decided first — it is an RBAC grant decision, **not** a code change, and this
audit does not make it.

---

## 21. Focused Test Strategy (narrow — no full regression)

1. Non-COD, paid in full ⇒ confirmable and confirmed.
2. Non-COD, proof uploaded-but-not-verified ⇒ still blocked.
3. Non-COD, proof VERIFIED ⇒ confirmed.
4. COD unpaid ⇒ confirmable, and `deposit_amount` stays 0 (never marked paid).
5. COD unpaid ⇒ becomes Preparation-eligible.
6. Method → COD ⇒ advances without being marked paid.
7. Non-COD ⇒ remains blocked until confirmation.
8. Duplicate confirm and duplicate partial `record-payment` ⇒ no double-apply.

Reuse `PaymentProofLifecycleTest` (23 green) and the existing confirm-workflow tests.

---

## 22. Browser Acceptance Plan (after implementation)

- **Scenario A** — ORD-00003 (`instapay`, fully paid, proof verified) should become confirmable
  and reach Preparation eligibility. **Already exists in live data; no fabrication needed.**
- **Scenario B** — an order switched to COD should stay payment-pending yet become
  fulfillment-eligible. Requires a method change, i.e. a business write, and must be authorised
  before execution.

---

## 23. Migration Findings

**No migration is required** for the primary fix. Everything needed already exists:
`deposit_amount`, `payment_method_manual`, `payment_proofs`, the brand policy, the engine and
the workflow.

Two *optional* future capabilities would need schema and are **not** proposed here: a payment
reference / idempotency key (A-1), and a `payment_status` writer or the column's removal (P-1).

---

## 24. Blockers

1. **PR-2 — proof permissions granted to no role.** The proof lifecycle is unusable for any
   non-super-admin. RBAC decision required.
2. **PR-1 — `payment_proof_path` bypass.** A required-proof gate can be cleared with an arbitrary
   string and without the proof permissions. Security decision required.
3. **§18 WooCommerce sync unaudited.** Must be established before auto-transitioning orders.
4. **Matrix row 8** (COD → non-COD) is an undecided business rule.

---

## 25. Final Recommendation

Implement **A** first — it is small, reuses the engine, gate, workflow and permissions, and
resolves the primary failure plus the method-change failure together. Take **D** with it, since
it is the same call site.

Decide **PR-2** and **PR-1** before declaring the payment flow usable: today the proof lifecycle
cannot be used by normal roles, while the gate it protects can be cleared by a free-text string.

Do **not** touch Preparation, and do not add a payment status column — payment truth is derived
from money and that is working.

**AUDIT ONLY. No code, schema, data, permission or business state was changed. No commit.**
