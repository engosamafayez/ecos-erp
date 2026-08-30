# TASK-ORDERS-PAYMENT-CONFIRMATION-COD-FULFILLMENT-AUDIT-002 — Audit Report

**Date:** 2026-08-21 · **Environment:** DEV (`ecos_dev`)
**AUDIT / DECISION ONLY — no code, schema, permission, role, user, payment, order status,
WooCommerce sync or business data was changed. No commit.**

---

## 1. Executive Summary

**Automatic Payment-Gate re-evaluation must NOT be authorised yet. Three STOP conditions from
the task brief are triggered.**

| Area | Verdict |
|---|---|
| WooCommerce sync | **Cannot safely consume the transition today** — the outbound status map does not contain `confirmed` or `in_progress` |
| PR-1 proof bypass | **CONFIRMED — HIGH severity.** Proof can be forged with an arbitrary string |
| PR-2 proof permissions | **CONFIRMED — operational + E2E blocker.** Granted to zero roles; UI hides the controls |
| FulfillmentEngine idempotency | **Safe sequentially, NOT safe concurrently** — guard runs outside the transaction, no row lock |
| Payment gate conditions | **Correct** — documented exactly in §13 |
| COD | **Unchanged and correct** |
| Preparation | **No change needed** |

The most serious finding is not any single item but their **combination**:

> The **sanctioned** proof path is invisible to every normal operator (PR-2), while the
> **unsanctioned** bypass is available to them (PR-1). The system actively channels operators
> toward the insecure route.

---

## 2. Previous Audit Findings Re-checked

| Finding (AUDIT-001) | Re-check |
|---|---|
| Payment gate correct | **CONFIRMED** |
| No re-evaluation after payment facts change | **CONFIRMED** |
| `VerifyPaymentAction` repaired but orphaned | **CONFIRMED** |
| PR-1 `payment_proof_path` bypass | **CONFIRMED — with exact mechanism, §9** |
| PR-2 zero grants | **CONFIRMED — plus the UI hides controls, §11** |
| §18 WooCommerce unaudited | **NOW AUDITED — §3–§6** |

---

## 3. WooCommerce Sync Trace

The sync is an **Eloquent observer**, not a domain-event listener:

```
Order::updated
  → OrderObserver::updated                  (SynchronizationServiceProvider:38, Order::observe)
      guard 1: wasChanged('status')
      guard 2: external_order_id not null/empty
      guard 3: channel_id not null
      guard 4: channel exists AND is_active
  → OrderStatusSyncJob::dispatch($channel, $order)   (ShouldQueue, tries=3, backoff=60)
  → HTTP PUT to WooCommerce
```

**Critical consequence:** because it is a model observer, it fires on **any** status write —
including every `FulfillmentEngine` transition. It does **not** need a new event, and it cannot
be opted out of.

`QUEUE_CONNECTION=sync` — the job runs **inline in the same request**. `tries`/`backoff` are
therefore inert.

**Live exposure today: ZERO.** All 14 DEV orders have `external_order_id = NULL`, so guard 2
returns early and no sync fires at all. (13 of 14 do have a `channel_id`, and both channels are
`woocommerce` + active — so only the missing external id is holding it back.)

---

## 4. Order Status Event Trace

`ConfirmOrderWorkflow` emits `OrderConfirmedEvent`, registered at
`FulfillmentServiceProvider:66` → `HandleOrderConfirmed`.

**WooCommerce does not listen to it.** The sync is driven purely by the model observer above.
So the domain-event pipeline and the WooCommerce pipeline are **independent**; confirming the
former says nothing about the latter.

---

## 5. WooCommerce Status Mapping — **BROKEN VOCABULARY**

`OrderStatusSyncJob::STATUS_MAP`:

```php
'pending'    => 'pending',
'processing' => 'processing',
'completed'  => 'completed',
'cancelled'  => 'cancelled',
```

`OrderStatus` actually defines **11** values:
`in_progress, confirmed, ready_for_dispatch, out_for_delivery, delivered, awaiting_payment,
awaiting_stock, scheduled, on_hold, cancelled, returned`.

**Only `cancelled` intersects.** `pending`, `processing` and `completed` are not ECOS statuses at
all — the map was written against a different (WooCommerce/legacy) vocabulary.

Unmapped statuses hit:

```php
$logService->markFailed($log, "No WooCommerce mapping for status [{$statusValue}].", ...);
```

**Therefore the outbound status sync is already non-functional for 10 of 11 statuses** — this is
pre-existing, not caused by the proposed transition. But the transition would target
`confirmed`, which is **unmapped**, so every Woo-originated order it advances would produce a
**failed sync log**, three times per transition under normal queueing.

`markFailed()` records and returns; it does not throw. So a failure would **not** roll back the
order transition — it would silently diverge ECOS from WooCommerce.

**No, the mapping does not distinguish payment state from fulfillment state** — it is a single
flat status map.

---

## 6. Sync Recursion / Idempotency

- **Recursion:** none. Inbound import writes orders; the observer only reacts to `status`
  changes and pushes outbound. No inbound-triggered-by-outbound loop was found.
- **Duplicate updates:** possible — the observer fires on every status change, so a transition
  chain (`awaiting_payment → confirmed → ready_for_dispatch`) produces one PUT per hop.
- **Silent failure:** **yes, by design** — unmapped statuses and missing credentials both
  `markFailed` without throwing.
- **Stale WooCommerce status:** **the current expected outcome** for any status outside the
  4-entry map.

---

## 7. Payment Proof Lifecycle

Two parallel representations, only one of which is a real lifecycle:

| Representation | Nature |
|---|---|
| `payment_proofs` table | Real lifecycle — Uploaded / Verified / Rejected, supersede history, actor, tenant-scoped download, 3 permissions |
| `orders.payment_proof_path` | A single free-text string column |

`ConfirmOrderWorkflow::hasAcceptedPaymentProof()` accepts **either**:

```php
if (! empty($order->payment_proof_path)) {
    return true;                       // ← any non-empty string
}
return PaymentProof::query()
    ->where('order_id', $order->id)
    ->whereNull('superseded_at')
    ->where('state', PaymentProofState::Verified->value)
    ->exists();                        // ← the real, verified proof
```

The second branch is correct and was deliberately repaired (D5) to require **VERIFIED**, not
merely uploaded. The first branch undermines it entirely.

---

## 8. `payment_proof_path` Contract

Against the four options in the brief, the answer is **C — a user-provided arbitrary path**.

Evidence: the only validation anywhere is

```php
'payment_proof_path' => 'nullable|string|max:500'
```

in **both** `OrderController::verifyPayment:267` and `StoreManualOrderRequest:46`.

There is **no** storage-existence check, **no** tenant/ownership check, **no** MIME/type check,
**no** path-traversal check, and **no** link to a `payment_proofs` row. It is not a storage
reference in any enforced sense — it is an unvalidated string that the gate treats as proof.

---

## 9. PR-1 Security Finding — **CONFIRMED**

**Exact bypass:**

```
POST /orders/{id}/verify-payment      { "payment_proof_path": "x" }
```

1. Validation passes (`string`, ≤500).
2. `VerifyPaymentAction` writes `payment_proof_path = "x"`.
3. It then runs `FulfillmentEngine::run(ConfirmOrderWorkflow)`.
4. The gate calls `hasAcceptedPaymentProof()` → `! empty("x")` → **true**.
5. Order → `confirmed` → becomes Preparation-eligible.

**Zero money. Zero evidence. No proof row. No verification.**

| Attribute | Value |
|---|---|
| **Severity** | **HIGH** |
| **Affected permission** | `sales.orders.update` — **not** `sales.orders.proof_verify` |
| **Affected workflow** | `ConfirmOrderWorkflow` payment gate |
| **Defeats** | The required-proof policy for `instapay`, `credit_card`, `bank_transfer`, `mobile_wallet` (all `"required"`) |
| **Cross-tenant file?** | The path is never resolved or read, so no file is accessed — the risk is **forged proof**, not cross-tenant disclosure |
| **Minimum safe fix** | Stop treating `payment_proof_path` as acceptance. Let `payment_proofs` (active + VERIFIED) be the only clearance, keeping the legacy column readable for historical/WooCommerce orders only — a decision, not implemented here |

---

## 10. Proof Verification Contract

Verification **is** represented independently from path existence — in the `payment_proofs`
branch. `uploaded` correctly does **not** clear a required gate; only `verified` does.

The defect is not the contract; it is that a second, unverified branch exists alongside it.

---

## 11. PR-2 Permission Finding — **CONFIRMED**

- Names: `sales.orders.proof_upload`, `sales.orders.proof_verify`, `sales.orders.proof_reject`.
- They appear in `config/permissions.php` **only in the catalogue** (line 63,
  `modules.sales.orders`) — grep finds them in **no role definition**.
- `role_permissions`: **0 grants each** (verified in the live database).
- Super Admin bypasses via `userHasSystemRole()` (`is_system = true`).
- **The frontend HIDES the controls** — `payment-proof-section.tsx` calls `usePermission()` and
  gates `canUpload` / `canVerify` / `canReject`. So a normal operator does not even see them;
  it is not merely a 403.

**Consequence:** no non-system user can upload, verify or reject a proof. The sanctioned route
to clear a required-proof gate is unreachable in normal operation.

---

## 12. Operational Roles

There is **no** role intended for proof verification anywhere in `config/permissions.php` — the
permissions were added to the catalogue and never assigned. This is not "a role exists but has
no users"; **the grant itself was never authored.**

---

## 13. Payment Gate — Exact Conditions

`ConfirmOrderWorkflow::guard()` blocks **only** when status is `awaiting_payment` **and**
`paymentPermitsConfirmation()` is false. That method returns **true** (i.e. permits) when **any**
of the following holds:

1. `deposit_amount >= total` — **paid in full**; or
2. the resolved method string is empty; or
3. `paymentProofRequirement(order, method) !== 'required'`; or
4. `hasAcceptedPaymentProof(order)` — legacy path non-empty **OR** an active VERIFIED proof.

`paymentProofRequirement()` returns `'none'` when `channel_id IS NULL` or the channel/brand
policy cannot be resolved.

**Answering the brief's explicit concern:** the gate **does** pass on `deposit_amount >= total`
**alone**, before proof is considered. So a future automatic transition triggered by
record-payment **would** advance a fully-paid order even where policy marks proof `required`.
Whether that is desired is a **business decision (Category F)** — it is the existing certified
behaviour, not a defect I may change.

---

## 14. COD Contract — unchanged

Live brand policy: `"cod": "none"`, `"cash": "none"`.

Requirement `none` ⇒ condition 3 above is satisfied ⇒ the gate never requires payment for COD.
COD is **not** marked paid: `deposit_amount` stays `0.00` and payment state stays unpaid.
Verified live: 10 of 11 COD orders progressed with `deposit_amount = 0.00`.

**No COD change is proposed or required.**

---

## 15. Automatic Transition Safety

The transition would run the **real** `ConfirmOrderWorkflow` through the **real**
`FulfillmentEngine`, so it cannot bypass:

- the guard's allowed-source-status list,
- the payment gate,
- `OrderStatusGuard` (P9),
- reservation rules (ADR-027; the `$alreadyReserved` short-circuit is preserved),
- the status-transition audit stamps and `OrderEvent` log.

**What it CAN bypass / disturb:**

1. **Operator intent.** Confirm is currently an explicit human action. Automating it means an
   order advances with no operator in the loop — a deliberate change of meaning.
2. **WooCommerce divergence** (§5) — silent failed syncs on `confirmed`.
3. **Proof policy**, where paid-in-full alone satisfies the gate (§13).

No fraud/channel-specific checks were found beyond the gate itself.

---

## 16. FulfillmentEngine Idempotency

**Sequentially: SAFE.** After the first run the status is `confirmed`, which is not in the
guard's allowed-source list, so a second run throws `WorkflowPreconditionException`. The guard
is the idempotency mechanism.

**Concurrently: NOT SAFE.**

```php
$workflow->guard($ctx);          // ← OUTSIDE the transaction
...
DB::transaction(function () { $workflow->execute($ctx); ... });
```

There is **no `lockForUpdate()`** and no row lock anywhere in `run()`. Two concurrent requests
can both read `awaiting_payment`, both pass the guard, and both execute. With
`QUEUE_CONNECTION=sync` everything is inline, so two overlapping HTTP requests are genuinely
concurrent.

**This matters directly for the proposal:** if *both* record-payment *and* proof-verification
trigger re-evaluation, two triggers can overlap — as can a double-clicked button.

---

## 17. Event Idempotency

Events are emitted **after commit**, once per successful `run()`. A duplicated concurrent run
would therefore emit `OrderConfirmedEvent` **twice** and write **two** `OrderEvent` audit rows.

`OrderObserver` is not idempotent either — it reacts to each `wasChanged('status')`, so a
duplicate transition means a duplicate outbound PUT attempt.

---

## 18. Preparation Boundary

`eligible_order_statuses = ["in_progress","confirmed"]`. Preparation reads **order status only**.

So yes — the future transition would make the order visible to Preparation **naturally**, with
**no Preparation change**. Confirmed and unchanged.

---

## 19. Confirmed Blockers

| # | Blocker | STOP condition triggered |
|---|---|---|
| 1 | **PR-1** — proof forgeable with an arbitrary string | *"payment proof can be forged/bypassed"* ✅ |
| 2 | **PR-2** — proof permissions granted to no role; UI hides controls | *"proof permissions make normal workflow impossible"* ✅ |
| 3 | **WooCommerce status map lacks `confirmed`/`in_progress`** | *"WooCommerce sync cannot safely consume automatic Order transitions"* ✅ |
| 4 | **Engine not concurrently idempotent** (no row lock) | *"FulfillmentEngine is not idempotent"* — partially ✅ (sequential safe, concurrent unsafe) |

**No schema change is required** for any of the above.

---

## 20. Minimum Safe Future Implementation (proposal only)

Ordered by dependency; none implemented here.

1. **Decide PR-1** — make `payment_proofs` (active + VERIFIED) the only proof clearance; keep the
   legacy column readable for historical/WooCommerce orders. *Must precede automation*: today
   automation would advance forged-proof orders faster and more silently than a human would.
2. **Decide PR-2** — grant the three proof permissions to the appropriate role(s). RBAC decision,
   not code.
3. **Decide the WooCommerce map** — either extend `STATUS_MAP` to cover the ECOS vocabulary, or
   explicitly accept that non-mapped statuses do not sync. Note the map is *already* broken for
   10 of 11 statuses independently of this work.
4. **Then** add re-evaluation at the two call sites (record-payment success, proof→verified),
   calling `FulfillmentEngine::run(ConfirmOrderWorkflow)` only when status is
   `awaiting_payment`, swallowing `WorkflowPreconditionException`.
5. **Guard concurrency** at that call site (a row lock or a single trigger point), given §16.

---

## 21. Decisions Required (Category F)

1. **PR-1:** should `payment_proof_path` remain able to clear a required-proof gate?
2. **PR-2:** which role(s) receive the three proof permissions?
3. **WooCommerce:** extend the status map, or accept non-sync for unmapped statuses?
4. **§13:** should paid-in-full alone clear a `required`-proof gate, or should proof be required
   even when fully paid? *(Existing certified behaviour: paid alone suffices.)*
5. **Automation scope:** trigger on record-payment only, on proof-verified only, or both?
6. Should confirmation remain an explicit human action rather than becoming automatic?

---

## 22. Final Recommendation

**Do not authorise automatic Payment-Gate re-evaluation yet.**

The orchestration fix itself is small and correct in principle, and it reuses the engine, gate,
workflow and permissions. But automating a gate whose evidence can be forged by an arbitrary
string (PR-1), whose legitimate path no normal operator can reach (PR-2), and whose target
status does not exist in the outbound sync map (§5), would make three existing defects
**faster, automatic and silent**.

Recommended order: **decide PR-1 and PR-2 first** (one security decision, one RBAC decision —
neither requires code), settle the WooCommerce mapping question, and only then implement the
re-evaluation with a concurrency guard.

**COD requires no change. Preparation requires no change. No migration is required.**

### Decision classification

| Category | Items |
|---|---|
| **A — Confirmed safe / existing contract** | Payment gate conditions (§13); COD (§14); Preparation boundary (§18); sequential engine idempotency (§16); no recursion in Woo sync (§6) |
| **B — Confirmed defect requiring fix** | PR-1 proof forgery (§9); WooCommerce `STATUS_MAP` vocabulary mismatch (§5); concurrent non-idempotency (§16) |
| **C — Operational blocker** | PR-2 — proof permissions granted to no role (§11) |
| **D — E2E blocker** | PR-2 — UI hides the controls, so no end-to-end proof flow can be exercised by a normal user (§11) |
| **E — Go-Live follow-up** | Silent failed syncs (§6); duplicate outbound PUTs on multi-hop transitions (§6); `payment_status` dead column (AUDIT-001 P-1) |
| **F — Requires explicit user decision** | All six items in §21 |

---

**AUDIT ONLY. Nothing was implemented, changed, granted, created or committed.
STOPPING — awaiting explicit authorization before any implementation.**
