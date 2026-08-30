# TASK-ORDERS-PAYMENT-CONFIRMATION-FULFILLMENT-IMPLEMENTATION-001 — FINAL REPORT

**Status:** IMPLEMENTED / VERIFIED (focused gates)
**Date:** 2026-08-21
**Branch:** `develop` — **NOT COMMITTED**
**Basis:** approved decisions from `TASK-ORDERS-PAYMENT-CONFIRMATION-FULFILLMENT-DECISION-AUDIT-003`

---

## 1. Executive Summary

**The defect was orchestration, not logic.** Recording a payment and verifying a payment
proof each updated a financial fact and then stopped. Nothing re-evaluated the payment gate
afterwards. An order could satisfy every condition the gate asks about and still sit in
`awaiting_payment` indefinitely — never reaching `confirmed`/`in_progress`, the only two
statuses Preparation collects.

This is still observable, read-only, in `ecos_dev` right now:

```
order_number:           ORD-00003
status:                 awaiting_payment      <-- stuck
total:                  10000.00
deposit_amount:         10000.00              <-- paid in full
payment_method_manual:  instapay              <-- proof REQUIRED
active_verified_proofs: 1                     <-- proof VERIFIED
```

Every precondition holds. The order does not move, because nothing asked again.

**What was implemented:**

1. **A single canonical re-evaluation entry point** — `ReevaluateOrderFulfillmentAction` —
   used by **both** triggers. It never writes `Order.status`; it runs `ConfirmOrderWorkflow`
   through `FulfillmentEngine`, which re-applies the payment gate and every other guard.
   The chain is exactly the approved one: *payment facts change → gate re-evaluated →
   ConfirmOrderWorkflow → FulfillmentEngine → existing events → Preparation naturally sees
   an eligible order.*
2. **The payment-proof contract was tightened** (Decisions 1 + 3). For proof-required
   methods the gate now requires payment **AND** a verified proof; the two used to be OR-ed.
   The legacy `orders.payment_proof_path` column no longer constitutes acceptance.
3. **RBAC separation of duties** (Decision 2) — upload sits with Sales, verify/reject with
   Finance and the tenant administrator. No role holds both.
4. **WooCommerce was left alone** (Decision 4), after verifying the existing code already
   fails loudly rather than silently succeeding on unmapped statuses.

**Focused gates: 79 tests / 222 assertions green across six payment/proof suites (Section 14).
PHPStan clean; Pint clean on every file this task authored.**

**Two findings are reported but deliberately NOT fixed**, because fixing them would exceed the
approved scope: a **creation-time bypass** of the same `payment_proof_path` flaw (Section 17.2)
and the **`channel_id IS NULL` proof-policy default**, which this task converted from latent to
live (Section 18 item 4). Both need their own decision.

**No commit. No business data was created or modified.** ORD-00003 was read, never touched.

---

## 2. Approved Decisions Implemented

| # | Decision | Where | Status |
|---|---|---|---|
| 1 + 3 | Proof-required methods need sufficient payment **AND** a valid, **VERIFIED** proof. `payment_proof_path` must not independently satisfy the requirement. `payment_proofs` is the canonical source. No new proof source, no new payment state, no migration. | `ConfirmOrderWorkflow.php:110-167` | Implemented |
| 1 (preserve) | Methods that do **not** require proof keep their existing sufficient-payment behaviour. | `ConfirmOrderWorkflow.php:121-125` | Preserved, with an explicit regression test |
| 2 | UPLOAD → Sales roles. VERIFY / REJECT → `finance-manager`, `company-admin`. No verify/reject for ordinary Sales. Reuse existing permissions; create no users; assign nothing to individuals; bypass no authorization. | `config/permissions.php` (5 role-grant lines) | Implemented — no new permission row, no new role |
| 4 | ECOS stays source of truth for `confirmed`/`in_progress`. Invent no lossy mapping. Unmapped states must not produce silent false success. Preserve existing non-throwing failure recording. | `OrderStatusSyncJob.php` — **verified, unchanged** | No change required (Section 12) |
| Core | ONE canonical re-evaluation entry point, used by both triggers, not bypassing `ConfirmOrderWorkflow`, never setting `confirmed`/`in_progress` directly. | `ReevaluateOrderFulfillmentAction.php` | Implemented |

---

## 3. Files Changed

Ten files, one of them comment-only. Five were already uncommitted from earlier sessions
(marked `??` in `git status`), so a `git diff` shows changes only for the three tracked ones —
stated here so the change surface is not under-reported.

| # | File | Tracked? | Change |
|---|---|---|---|
| 1 | `backend/Modules/Commerce/Orders/Application/Actions/ReevaluateOrderFulfillmentAction.php` | **new** | The canonical entry point (98 lines) |
| 2 | `backend/Modules/Operations/Fulfillment/Application/Workflows/ConfirmOrderWorkflow.php` | tracked | Payment gate rewritten: `paymentPermitsConfirmation()` + new `isPaidInFull()` / `hasVerifiedPaymentProof()`; `hasAcceptedPaymentProof()` removed |
| 3 | `backend/Modules/Commerce/Orders/Application/Actions/RecordOrderPaymentAction.php` | untracked (pre-existing) | Constructor injection + one re-evaluation call after the payment persists (`:29`, `:80`) |
| 4 | `backend/Modules/Commerce/Orders/Application/Actions/VerifyPaymentProofAction.php` | untracked (pre-existing) | Constructor injection + one re-evaluation call after the proof state persists (`:29`, `:64`) |
| 5 | `backend/config/permissions.php` | tracked | 5 role-grant lines (Section 5) |
| 6 | `backend/tests/Feature/Commerce/OrderPaymentConfirmationGateTest.php` | untracked (pre-existing) | 4 tests → 10; two rewritten to the new contract |
| 7 | `backend/tests/Feature/Commerce/OrderPaymentFulfillmentReevaluationTest.php` | **new** | 12 tests for the orchestration repair |
| 8 | `backend/Modules/Commerce/Orders/Application/Actions/VerifyPaymentAction.php` | tracked | **Comment only — zero executable change.** Its docblock asserted that an attached proof clears the gate, which Decision 1 makes false. Corrected rather than left to mislead. See Section 17. |
| 9 | `backend/tests/Feature/Commerce/PaymentProofLifecycleTest.php` | untracked (pre-existing) | One test updated to the new contract (Section 14.3). The other 22 unchanged |
| 10 | `backend/tests/Feature/Orders/OrderEditReservationAndPaymentGuardsTest.php` | untracked (pre-existing) | Reflection target renamed + explanatory comment. **No assertion changed** |

**No migration. No schema change. No new permission row. No new role. No new event. No
change to Preparation. No change to WooCommerce.**

`config/permissions.php` already carried unrelated uncommitted edits from other sessions
(`expected_incoming`, Loading OS, `finance.ap.opening`, and the `proof_*` catalogue entry
itself). Those are **not** mine; my contribution is the 5 role-grant lines in Section 5.

---

## 4. Payment Proof Contract

### 4.1 The contract as implemented

```
method requires no proof ('none' / 'optional')   → gate does not block   [UNCHANGED]
method REQUIRES proof                            → paid in full AND active VERIFIED proof
```

### 4.2 What changed, and why it mattered

Before, the three conditions were OR-ed and "paid in full" was evaluated **first**:

```php
if ((float) $order->deposit_amount >= (float) $order->total) {
    return true;                       // short-circuits the policy entirely
}
...
return $this->paymentProofRequirement($order, $method) !== 'required'
    || $this->hasAcceptedPaymentProof($order);
```

A fully-paid instapay order therefore confirmed with **no proof at all**. The brand's
`payment_proof_policy` said `required`; money alone satisfied it. The two facts are not
interchangeable: payment says the amount arrived, the verified proof says it arrived *from
this customer, for this order*. Neither substitutes for the other, so for proof-required
methods they are now AND-ed.

### 4.3 `payment_proof_path` is no longer a proof source

The old helper accepted the legacy column:

```php
if (! empty($order->payment_proof_path)) {
    return true;              // any non-empty string
}
```

`payment_proof_path` is validated as `nullable|string|max:500` — no storage check, no tenant
check, no existence check, no MIME check. Any non-empty value cleared a **required**-proof
gate with zero money and zero evidence, needing only `sales.orders.update` and never
touching `sales.orders.proof_verify`. It is now read by nothing in the gate. The column
still exists and is still displayed; it simply no longer constitutes acceptance. **No
migration, and no data was rewritten.**

### 4.4 Canonical source

`payment_proofs`, and only `payment_proofs`:

```php
PaymentProof::query()
    ->where('order_id', $order->id)
    ->whereNull('superseded_at')                       // active
    ->where('state', PaymentProofState::Verified->value)
    ->exists();
```

- `superseded_at IS NULL` — uploading a replacement supersedes the previous proof even when
  that proof was already verified, so a replaced proof correctly stops clearing the gate
  until the new one is verified in its own right.
- `state = verified` — never merely `uploaded`. Evidence submitted is not evidence accepted.
- `rejected` never clears the gate.

Preserved unchanged: the `uploaded`/`verified`/`rejected` lifecycle, tenant isolation
(company-scoped lookups, cross-tenant → 404), and the storage contract. No new proof source,
no new payment state, no redesign of the state machine.

---

## 5. RBAC Changes

Five role-grant lines in `config/permissions.php`. **Every permission name already existed**
in the catalogue — nothing was created, and nothing was assigned to an individual user.

| Role | Added | Rationale |
|---|---|---|
| `sales` | `proof_upload` | Decision 2 — upload belongs to Sales |
| `sales-manager` | `proof_upload` | idem |
| `sales-representative` | `proof_upload` | idem |
| `company-admin` | `proof_verify`, `proof_reject` | Decision 2. Deliberately **not** `proof_upload` |
| `finance-manager` | `proof_verify`, `proof_reject` | Decision 2. Its first and only `sales.*` grant |

Resulting matrix (verified by evaluating the config, not by reading it):

| Role | upload | verify | reject |
|---|:--:|:--:|:--:|
| `sales`, `sales-manager`, `sales-representative` | ✅ | ❌ | ❌ |
| `company-admin` | ❌ | ✅ | ✅ |
| `finance-manager` | ❌ | ✅ | ✅ |
| all other roles holding `sales.orders` (`viewer`, `customer-service`, `dispatcher`, `cashier`, `fulfillment-supervisor`, `system-auditor`, `branch-manager`) | ❌ | ❌ | ❌ |

**No role holds both upload and verify.** Separation of duties is structural, not procedural.

`finance-manager` deliberately receives *only* the two review verbs — no `sales.orders.view`,
no `update`, no order write verb of any kind. That is sufficient: the routes carry exactly
these permissions and nothing more, and `PaymentProofController` scopes by company only.
Test `test_a_role_holding_only_proof_verify_can_verify_and_thereby_advance_the_order` proves
the minimal grant works end to end.

```php
Route::post('payment-proofs/{proof}/verify')->middleware('permission:sales.orders.proof_verify');
Route::post('payment-proofs/{proof}/reject')->middleware('permission:sales.orders.proof_reject');
```

No authorization was bypassed in backend code. No user was created. `customer-service` holds
`sales.orders => ['view','update']` but is not a Sales role and was left untouched.

**Deployment note:** the grants are config. They reach the database only when `RbacSeeder`
runs. **It was not run** — see Section 16.

---

## 6. Payment Gate Changes

`ConfirmOrderWorkflow.php`. The guard call site is unchanged (`:82`):

```php
if ($order->status === OrderStatus::AwaitingPayment && ! $this->paymentPermitsConfirmation($order)) {
```

The decision body (`:110`):

```php
private function paymentPermitsConfirmation(Order $order): bool
{
    $method = (string) ($order->payment_method_manual ?? $order->payment_method ?? '');

    if ($method === '') {
        return true;                                                   // unchanged default
    }

    if ($this->paymentProofRequirement($order, $method) !== 'required') {
        return true;                                                   // unchanged behaviour
    }

    return $this->isPaidInFull($order) && $this->hasVerifiedPaymentProof($order);
}
```

- `isPaidInFull()` (`:133`) uses the existing derivation — `deposit_amount >= total`. No
  payment state is stored anywhere; no new one was introduced.
- `hasVerifiedPaymentProof()` (`:154`) replaces `hasAcceptedPaymentProof()`, which is gone.
- `paymentProofRequirement()` (`:169`) is **untouched**, including its brand-policy lookup
  and its `channel_id IS NULL → 'none'` default (see Section 18).

**Nothing else about payment behaviour was changed.** Method precedence, the empty-method
default, brand policy resolution, and every non-`required` method behave exactly as before.

---

## 7. Canonical Re-evaluation Entry Point

`ReevaluateOrderFulfillmentAction` — one action, used by both triggers.

```php
$advanced = DB::transaction(function () use ($order, $actorId): bool {
    $locked = Order::whereKey($order->id)->lockForUpdate()->first();

    if ($locked === null)                                    { return false; }
    if ($locked->status !== OrderStatus::AwaitingPayment)    { return false; }

    try {
        $this->engine->run($this->confirmWorkflow, $locked, [], $actorId);
        return true;
    } catch (WorkflowPreconditionException) {
        return false;                        // "not yet" — never an error
    }
});
```

**It does not bypass `ConfirmOrderWorkflow`, and it does not decide eligibility.** It writes
no status of its own; `FulfillmentEngine::run()` invokes the workflow, whose `guard()`
re-applies the payment gate, the allowed source statuses, and the ADR-027 reservation rules.
The action's only job is to ask the question again at a moment when the answer may have
changed. It never sets `confirmed` or `in_progress` directly, and it can only ever advance an
order — it has no revert path.

One entry point was a deliberate choice: two independently written transition paths would
double the concurrency surface and make "at most one transition" untestable.
`test_the_transition_is_attributed_to_the_confirm_workflow` asserts the transition is
recorded under the workflow's own name (`confirm_order`), which fails immediately if either
call site ever writes status itself.

---

## 8. Concurrency Protection

The decision and the transition share **one** transaction boundary, and the order row is
locked for the whole of it:

```php
DB::transaction(function () {
    $locked = Order::whereKey($order->id)->lockForUpdate()->first();
    // ... status re-read here, INSIDE the lock ...
    // ... transition here, still INSIDE the same transaction ...
});
```

- **`lockForUpdate` is reused, not invented** — the same pattern as
  `ReserveOrderInventoryAction:138` and `WaveLifecycleService:50`. No new locking framework,
  no advisory locks, no new abstraction.
- **The status is re-read inside the lock.** The `Order` passed in is treated as an
  identifier, never as trusted state. A pre-check outside the transaction would not be
  sufficient: record-payment and proof-verification can fire concurrently, and both would
  read `awaiting_payment`, both pass the guard, and both transition.
- **The lock is held across the guard.** A concurrent trigger blocks at
  `lockForUpdate()` and, on acquiring it, observes the committed state rather than racing it
  — so it sees `confirmed` and returns without acting.

Note for the record: `FulfillmentEngine::run()` evaluates `guard()` *outside* its own
transaction. Wrapping the engine call inside this action's transaction and lock is what
closes that window for this path. The engine itself was not modified.

---

## 9. Idempotency

| Concern | Mechanism |
|---|---|
| Duplicate transitions | Status re-read inside the lock; anything other than `awaiting_payment` returns early |
| Duplicate events | Only one call can reach `engine->run()`; the second sees the advanced status |
| Duplicate audit records | `OrderEvent` is written by `FulfillmentEngine` per successful run — at most one run occurs |
| Duplicate downstream effects | Reservation, snapshots and domain events all fire inside the single workflow execution |

A failed gate is a **no-op, not an error**: `WorkflowPreconditionException` is caught and
translated to "no transition applied", so the payment or proof-verification that triggered
the re-evaluation stays committed. This is verified from both directions:

- `test_full_payment_is_retained_when_the_gate_still_blocks` — money committed, order parked.
- `test_verifying_the_proof_on_an_unpaid_order_verifies_without_advancing` — proof committed,
  order parked, `deposit_amount` untouched.
- `test_re_evaluation_is_idempotent` — the action invoked twice produces exactly one
  transition and exactly one `confirm_order` event.
- `test_an_order_not_awaiting_payment_is_untouched` — a cancelled order is left alone.

---

## 10. Payment Recording Integration

`RecordOrderPaymentAction` — one call, added **after** the financial fact has persisted:

```php
$order->update(['deposit_amount' => $newPaid]);   // amount received — NOT status
OrderEvent::log(... 'payment_recorded' ...);

$this->reevaluate->execute($order->fresh());      // ← added

return OperationResult::success($order->fresh(), ...);
```

Everything pre-existing is intact: the amount validation, the overpayment rejection, the
`$outstanding <= 0.0` idempotency short-circuit, and the `payment_recorded` event. The action
still never writes `Order.status` — the P9 `OrderStatusGuard` would throw
`UnauthorizedOrderStatusWriteException` if it tried, and the transition it now causes happens
inside `FulfillmentEngine::run()`, which is the guard's only authorised writer.

`RecordOrderPaymentAction` is the **only** runtime path that mutates `deposit_amount` after
creation — `deposit_amount` was removed from `UpdateOrderAction`'s editable fields by
TASK-ORDERS-PREPARATION-PAYMENT-FINAL-FIX-001 (D6), and `CreateManualOrderAction` sets it at
creation, where the creation gate already chooses the initial status. So this call site plus
Section 11 is the complete set of post-creation payment-fact mutations.

---

## 11. Proof Verification Integration

`VerifyPaymentProofAction` — one call, after the proof state commits:

```php
$proof->update(['state' => PaymentProofState::Verified, 'verified_by' => ..., 'verified_at' => now()]);
OrderEvent::log($proof->order_id, 'payment_proof_verified', ...);

$order = Order::find($proof->order_id);           // ← added
if ($order !== null) {
    $this->reevaluate->execute($order);
}
```

The `UPLOADED → VERIFIED` precondition (422 otherwise), the verifier attribution and the
audit event are unchanged. Verification remains **evidence-only**: it writes no payment
amount, creates no second payment, and writes no order status. It can now *cause* a
transition, but only by asking `ConfirmOrderWorkflow`, which independently re-checks that the
order is actually paid — `test_verifying_the_proof_on_an_unpaid_order_verifies_without_advancing`
pins that down.

**`RejectPaymentProofAction` was deliberately not wired.** Re-evaluation only ever advances an
order, and rejection can only remove grounds for advancement. Wiring it would be a no-op at
best; a revert path is out of scope and was not authorised.

---

## 12. WooCommerce Handling

**No change was made.** The existing implementation already satisfies Decision 4, which was
verified rather than assumed.

`OrderStatusSyncJob::STATUS_MAP` contains `pending`, `processing`, `completed`, `cancelled`.
`confirmed` and `in_progress` have no WooCommerce equivalent and are **not** in the map. On an
unmapped status:

```php
$wooStatus = self::STATUS_MAP[$statusValue] ?? null;

if ($wooStatus === null) {
    $logService->markFailed($log, "No WooCommerce mapping for status [{$statusValue}].", null, $this->channel);
    return;                                    // records, does not throw
}
```

- **No lossy mapping was invented.** `confirmed`/`in_progress` were not mapped to `processing`.
  ECOS remains the source of truth for both.
- **No silent false success.** The sync log row is written with `status = failed` and an
  explicit reason naming the unmapped state. It is never recorded as sent.
- **The existing non-throwing failure recording was preserved**, exactly as Decision 4
  requires.

This mattered more after this task than before it, and the reason is worth stating: this
change makes `awaiting_payment → confirmed` **actually happen** on channel orders for the
first time, so the unmapped branch is now genuinely reachable in production. Because the
observer fires inside the `FulfillmentEngine` transaction and `QUEUE_CONNECTION=sync` runs the
job inline, a throw here would have rolled the transition back. It does not throw.
`test_confirming_a_channel_order_records_a_failed_sync_instead_of_a_false_success` asserts all
three properties — the order reaches `confirmed`, the log row is `failed`, and the reason
names the missing mapping.

No mapping was ambiguous, so no STOP was triggered here.

---

## 13. Preparation Boundary

**Preparation was not modified. Nothing in `Modules/Operations/Preparation` was touched.**

- No new Preparation event.
- No Preparation query change.
- No change to `eligible_order_statuses` (`["in_progress","confirmed"]`).

Preparation picks the order up because the order legitimately reaches a status Preparation
already collects — through `ConfirmOrderWorkflow` → `FulfillmentEngine` → the events that
already existed. That is the approved chain, and it is the whole integration: the order
becomes eligible, and Preparation *naturally sees it*. Nothing pushes anything to Preparation.

---

## 14. Tests

### 14.1 Results

All six suites green, run through `scripts/test-gate.sh` against `ecos_dev_test`.

| Suite | Tests | Assertions | Result |
|---|---:|---:|---|
| `OrderPaymentFulfillmentReevaluationTest` **(new)** | 12 | 46 | **OK** |
| `OrderPaymentConfirmationGateTest` (4 → 10 tests) | 10 | 35 | **OK** |
| `PaymentProofLifecycleTest` (1 test updated) | 23 | 56 | **OK** |
| `OrderPaymentStateTest` (untouched) | 15 | 28 | **OK** |
| `OrderPaymentMethodAndSettlementContractTest` (untouched) | 8 | 30 | **OK** |
| `OrderEditReservationAndPaymentGuardsTest` (rename only) | 11 | 27 | **OK** |
| **Total** | **79** | **222** | **all green** |

### 14.2 Coverage of the required cases

**Payment gate — proof contract (`OrderPaymentConfirmationGateTest`)**

| # | Case | Expected | ✔ |
|---|---|---|---|
| 1 | Unpaid + proof-required, no proof | blocked | ✅ |
| 2 | COD (`'none'`) | confirms | ✅ |
| 3 | **Paid in full, proof-required, no verified proof** | **blocked** | ✅ |
| 4 | **Legacy `payment_proof_path` only** | **blocked** | ✅ |
| 5 | Paid in full + VERIFIED proof | confirms | ✅ |
| 6 | Uploaded (unverified) proof | blocked | ✅ |
| 7 | Rejected proof | blocked | ✅ |
| 8 | Verified but SUPERSEDED proof | blocked | ✅ |
| 9 | Verified proof but unpaid | blocked | ✅ |
| 10 | `credit_card` (`'optional'`) | confirms — unchanged | ✅ |

**Orchestration (`OrderPaymentFulfillmentReevaluationTest`)**

| # | Case | Expected | ✔ |
|---|---|---|---|
| 1 | Final payment recorded, verified proof present | order advances | ✅ |
| 2 | Partial payment | money kept, order parked | ✅ |
| 3 | Full payment, gate still blocks | **money kept**, order parked | ✅ |
| 4 | Proof verified on a paid order | order advances | ✅ |
| 5 | Proof verified on an unpaid order | proof kept, order parked, deposit untouched | ✅ |
| 6 | Transition attribution | exactly one `confirm_order` event | ✅ |
| 7 | Re-evaluation run twice | one transition, one event | ✅ |
| 8 | Order not in `awaiting_payment` | untouched | ✅ |
| 9 | COD payment recorded | advances — unchanged | ✅ |
| 10 | Role holding **only** `proof_verify` | can verify; order advances | ✅ |
| 11 | Role holding `sales.orders.update` | **403** on verify | ✅ |
| 12 | Channel order reaching `confirmed` | failed sync log, no throw, order still advances | ✅ |

### 14.3 Tests whose assertions were intentionally changed

Three pre-existing tests asserted behaviour the approved decisions replace. None was deleted —
each was rewritten to assert the new contract, because the scenarios they cover are exactly
the ones that used to be wrong.

1. **`OrderPaymentConfirmationGateTest::test_paid_order_can_be_confirmed_even_when_method_requires_proof`**
   → now `test_paid_order_is_blocked_when_method_requires_proof_and_none_is_verified`.
   It asserted that money alone clears a required-proof gate. Decision 1 makes that false.

2. **`OrderPaymentConfirmationGateTest::test_order_with_attached_proof_can_be_confirmed`**
   → now `test_legacy_payment_proof_path_no_longer_satisfies_a_required_proof_method`.
   It seeded `payment_proof_path` directly and expected confirmation. Decision 1 forbids that
   column from independently satisfying the requirement.

3. **`PaymentProofLifecycleTest::test_10_verification_does_not_change_order_status`**
   → now `test_10_verification_never_writes_order_status_itself`.
   This one is worth reading carefully, because it is the clearest evidence that the defect
   was real: the old assertion passed **only because nothing re-evaluated the gate**. Now that
   verification re-asks `ConfirmOrderWorkflow`, an order whose gate is satisfied advances. The
   guarantee that genuinely still holds is now asserted instead — the proof action writes no
   status itself, and any transition is attributed to `confirm_order`.

   Its fixture also surfaced the `channel_id IS NULL` gap described in Section 18 item 4: the
   orders it builds have no channel, so the proof policy resolves to `'none'` and they advance
   despite being unpaid. That is pre-existing gate behaviour, not something this task changed.

Additionally, `OrderEditReservationAndPaymentGuardsTest` had **two reflection-based tests**
calling the private `hasAcceptedPaymentProof()`. The method was renamed to
`hasVerifiedPaymentProof()`, so the reflection target was updated — **no assertion changed**.
Their four cases (no proof / verified+active / uploaded / verified+superseded) are precisely
the semantics the new helper preserves, and they still pass.

### 14.4 Not run

The **full ERP regression suite was NOT run**, per the task constraint. The six suites above
were chosen as the payment/proof/confirm blast radius. Section 18 item 6 states the residual
risk that carries.

---

## 15. Static Gates

| Gate | Scope | Result |
|---|---|---|
| `php -l` | all 10 changed files | **No syntax errors** (10/10) |
| Pint (`--test`) | all 10 changed files | **9/10 pass** — one pre-existing failure, see below |
| PHPStan | the 5 changed source files | **`[OK] No errors`** |
| PHPUnit (focused) | 6 suites — see Section 14 | **79 tests / 222 assertions, all green** |

**Pint — the one failure, stated rather than glossed over.**
`tests/Feature/Orders/OrderEditReservationAndPaymentGuardsTest.php` fails `global_namespace_import`
and `ordered_imports`. **This predates my change and was proven, not assumed:** the file is
untracked (never committed, so there is no HEAD to diff against), so a copy was taken, my two
edits — the reflection-target rename and an explanatory comment — were reverted in that copy,
and Pint was re-run. It produced the identical failure with the identical two fixers. The
violations come from the file's pre-existing inline fully-qualified class references
(`\Modules\Operations\...\ConfirmOrderWorkflow::class`, `\ReflectionMethod`), none of which I
added. It was deliberately **not** auto-fixed: the file belongs to another session's
uncommitted work, and reformatting its imports would collide with concurrent edits.

Every file authored or substantively changed by this task passes Pint.

**The full ERP regression suite was NOT run**, per the task constraint.

---

## 16. Business Data Side Effects

**None. No business data was created, modified, or deleted.**

| Constraint | Compliance |
|---|---|
| Do not modify ORD-00003 manually | Read-only `SELECT` only. It is still `awaiting_payment` with `deposit_amount = 10000.00` |
| Do not change its payment | Not touched |
| Do not upload a fake proof | None uploaded |
| Do not verify a fake proof | None verified |
| Do not change Order status manually | No manual status write anywhere |
| Do not create fake orders / payments / suppliers / invoices | None created |
| Do not trigger an irreversible financial operation | None triggered |

All test data lives in `ecos_dev_test` under `RefreshDatabase`, which is torn down per run and
is a different schema from `ecos_dev`.

**`RbacSeeder` was NOT run.** The role grants in Section 5 are config-only at this point and
are **not yet present in any database**. This was deliberate: a prior session established that
running the seeder also adopts unrelated permissions registered by other sessions' pending
migrations, which would have produced grants outside this task's authorisation. Applying them
is a deployment step, and it is listed in Section 18 as an outstanding action rather than
performed here.

**No `docker cp` to `ecos-dev-app`, no image rebuild, no container recreate.** The changed
files were copied only into `ecos-dev-testrunner` to run the gates. The running dev
application is unchanged, so this work is not live anywhere.

---

## 17. Security Review

| Concern | Finding |
|---|---|
| **Privilege escalation via the legacy column** | **Closed.** `payment_proof_path` was an unvalidated free-text field that cleared a *required*-proof gate with only `sales.orders.update`. It is no longer read by the gate. |
| **Separation of duties** | **Enforced structurally.** No role holds both `proof_upload` and `proof_verify`. The role that raises an order cannot accept the evidence for it. `test_order_update_permission_does_not_grant_proof_verification` proves `sales.orders.update` does not reach verify. |
| **Authorization bypass in backend code** | **None.** Route middleware is unchanged; no `Gate` or policy check was weakened, skipped, or worked around. |
| **Privilege granted to individuals** | **None.** All grants are role-level and reuse existing permission rows. |
| **Tenant isolation** | **Unchanged.** `PaymentProofController` still scopes every order/proof lookup to the current company (cross-tenant → 404). The new proof query is scoped by `order_id`, itself resolved company-scoped. |
| **Elevated privilege in the new action** | **None.** `ReevaluateOrderFulfillmentAction` performs no authorization check of its own and grants nothing; it is reachable only from two already-authorised endpoints and stamps the acting user via `Auth::id()`. |
| **Audit trail** | **Strengthened.** Transitions are attributed to `confirm_order` with actor and previous/new status, on top of the pre-existing `payment_recorded` and `payment_proof_verified` events. |
| **Money safety** | A blocked gate never rolls back a committed payment (Section 9). The overpayment guard is intact. |

Widened surface, stated plainly: `finance-manager` gains its first `sales.*` capability. It is
two review verbs, it cannot move an order by itself (`ConfirmOrderWorkflow` re-checks
everything), and it comes with no order write verb.

### 17.1 The escalation vector this closed, concretely

`POST /orders/{order}/verify-payment` (`VerifyPaymentAction`) accepts a caller-supplied
`payment_proof_path` string, validated only as `nullable|string|max:500`, behind
`permission:sales.orders.update`. It writes that string to the order and then runs
`ConfirmOrderWorkflow`. Under the old OR-based gate the string alone satisfied a **required**
proof policy, so any order editor could confirm an unpaid, proof-required order with an
arbitrary value — never touching `sales.orders.proof_verify`, and leaving no evidence.

That path is now closed: the gate reads `payment_proofs` only. The endpoint still exists and
still writes the display field, but for a proof-required method it advances an order only when
the order is genuinely paid in full **and** carries a verified proof.

### 17.2 Residual bypass, NOT fixed here — creation time

The same `payment_proof_path` acceptance still exists on the **creation** path, and it is
outside this task's authorised scope. `CreateManualOrderAction::resolveManualOrderStatus()` is
a separate implementation of the proof rule (ADR-042 §3.1):

```php
if ($requirement === 'required' && empty($data['payment_proof_path'])) {
    return ['status' => OrderStatus::AwaitingPayment->value, ...];
}
```

`StoreManualOrderRequest` accepts both `payment_proof_path` (`nullable|string|max:500`) and
`status`. So `POST /api/orders/manual` with `payment_method_manual: instapay`,
`payment_proof_path: "x"` and `status: in_progress` creates the order **directly in
`in_progress`** — it never enters `awaiting_payment`, so the confirmation gate this task
hardened never runs, and Preparation sees it immediately. It also ignores payment entirely.

**Deliberately not changed.** Altering creation-time status resolution would change documented
ADR-042 §3.1 semantics and the behaviour of orders that never reach the confirmation gate —
"unrelated payment behaviour" that this task forbids changing silently. It is reported here
for a separate decision, exactly as the audit reported the confirmation-path flaw.

---

## 18. Remaining Limitations

1. **NOT BROWSER VERIFIED.** No browser acceptance was performed. Doing so would have required
   recording a payment or verifying a proof on real data, which is forbidden by this task.
2. **NOT DEPLOYED.** Changes exist on disk and in the testrunner only. The dev application
   still runs the old code, and the RBAC grants are not in any database until `RbacSeeder`
   runs.
3. **ORD-00003 is still stuck.** The repaired code would advance it on the *next* payment or
   proof-verification event, but it has already received both — so it needs a one-time
   operational re-trigger that this task does not authorise. Flagged, deliberately not acted on.
4. **`channel_id IS NULL` bypasses the proof requirement — and this task made that latent gap
   live.** `paymentProofRequirement()` returns `'none'` when an order has no channel, so a
   channel-less order is treated as not-proof-required regardless of its payment method.
   Pre-existing and untouched. But it used to be *inert*: the gate would have permitted
   confirmation, and nothing ever asked. Now that re-evaluation works, a channel-less,
   **unpaid** instapay order advances to `confirmed` the moment its proof is verified or any
   payment is recorded.

   This is not theoretical — it is what made `PaymentProofLifecycleTest::test_10` fail on the
   first adjacent run (Section 14.3). Its fixture builds orders with no `channel_id`, deposit
   `0`, method `instapay`; verification now advances them. The gate logic is behaving exactly
   as specified; the `'none'` default is the questionable part, and changing it would alter
   payment behaviour outside this task's authorisation. **Recommended for a separate decision,
   together with item 9.**
5. **A superseded proof cannot pull a confirmed order back.** Re-evaluation only advances.
   If a verified proof is superseded after confirmation, the order stays confirmed. A revert
   path is out of scope.
6. **Contract change with a blast radius beyond these tests.** Fully-paid, proof-required
   orders with no verified proof no longer auto-confirm. Any *other* fixture or flow that
   relied on the old OR behaviour will now block. The four adjacent suites in Section 14 were
   re-run to bound this, but the full regression suite was not run.
7. **`payment_status` column remains dead** (NULL on all 14 orders). Untouched — payment state
   is derived, and introducing a stored one was explicitly out of scope.
8. **WooCommerce `confirmed`/`in_progress` sync attempts will now appear as failed sync logs**
   for channel orders. That is the correct, approved behaviour (Section 12), but it is a new
   and visible operational signal that support staff should expect.
9. **Creation-time bypass still open** (Section 17.2). A manual order created with
   `payment_proof_path` set can enter `in_progress` directly, skipping the hardened gate
   entirely. Out of scope, reported for a separate decision. **This is the most important
   open item in this report** — the confirmation path is hardened, the creation path is not.
10. **`POST /orders/{order}/verify-payment` behaviour changes visibly.** Any UI control wired
    to it that previously advanced a proof-required order by submitting a path string will now
    return the gate's 422 instead. This is the intended security outcome, not a regression, but
    it is user-facing and the frontend was not audited or updated as part of this task.
11. **A third transition path remains unlocked.** `VerifyPaymentAction` calls
    `FulfillmentEngine::run(ConfirmOrderWorkflow)` directly, without the row lock the new entry
    point uses. It is correctly gated, but it is not covered by the concurrency guarantee in
    Section 8. Pre-existing; not rewired, because the task scoped the canonical entry point to
    the two payment triggers and this endpoint has its own 422 contract.

---

## 19. STOP Conditions

No STOP condition was triggered.

| Condition | Outcome |
|---|---|
| "If an existing role cannot safely be granted: STOP before inventing a role." | Not triggered. `finance-manager` and `company-admin` both existed and could safely hold the two review verbs. **No role was invented.** |
| "If a WooCommerce mapping is genuinely ambiguous: STOP and report." | Not triggered. Nothing was mapped — the existing unmapped-status branch already records a failure without faking success, so no ambiguity had to be resolved. |
| Redesigning the state machine / adding a payment state / adding a migration | Not required, not done. |
| Modifying Preparation | Not required, not done. |

---

## 20. Final Verdict

# PAYMENT FULFILLMENT RE-EVALUATION — IMPLEMENTED / VERIFIED

The orchestration defect is repaired. Payment facts changing now re-evaluate the payment gate
through one canonical entry point, which routes through `ConfirmOrderWorkflow` and
`FulfillmentEngine` so that an order which satisfies the gate reaches a status Preparation
already collects. The proof contract is tightened per Decisions 1 + 3, RBAC separation of
duties is enforced per Decision 2, and WooCommerce is untouched per Decision 4.

**This verdict is scoped to the focused gates in Sections 14 and 15.**

Explicitly **not** claimed:

- ❌ FULL ERP CERTIFIED — the full regression suite was not run.
- ❌ WooCommerce fully certified — only the unmapped-status behaviour was verified.
- ❌ Payment E2E fully certified — no browser acceptance was performed.
- ❌ Deployed — the dev application still runs the previous code.

**NOT BROWSER VERIFIED. NOT COMMITTED.**
