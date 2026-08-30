# TASK-ORDER-PAYMENT-PREPARATION — IMPLEMENTATION FINAL REPORT

**Date:** 2026-08-23
**Branch:** `develop`

> ## FINAL VERDICT
>
> # IMPLEMENTED · VERIFIED · NOT CERTIFIED
>
> **Reason: Browser Verification Outstanding.**
>
> **No commit. No deploy. No migration. No business-data mutation.**

Owner direction of 2026-08-23 accepted this implementation and closed both open decisions.
This report is the final record. Paths: `<B>` = `C:/ecos-develop/backend`, `<R>` = `C:/ecos-develop`.

---

## 0. Where each required item is recorded

| # | Required item | Section |
| --- | --- | --- |
| 1 | M1 implementation | §2, §3 |
| 2 | BL-2-A resolution | §4 |
| 3 | RC-3 corrected eviction semantics | §5 |
| 4 | `hasLeftPreparation()` rationale | §5.1 |
| 5 | NULL-warehouse narrowing and carry-over protection | §5.2 |
| 6 | Regression classification | §7 |
| 7 | ORD-00008 left untouched | §9 |
| 8 | Browser verification limitation | §8 |
| 9 | Side-effect audit | §10 |
| 10 | Exact final verdict | header, §12 |

---

## 1. Scope delivered

| Ruling | Scope | State |
| --- | --- | --- |
| **Q1** | ADR-042 amendments A / B / C | Shipped |
| **Q2 / M1** | Gate the `awaiting_payment -> in_progress` advance **inside `ProcessOrderWorkflow`**; leave `OrderStatus::advancesToInProgressOnReservation()` unchanged | Shipped — **ACCEPTED** |
| **Q3 / BL-2-A** | O1 + O3 scoped to the **advance decision only** | Shipped — **ACCEPTED** |
| **RC-3** | Preparation Wave stale-membership eviction | Shipped, corrected — **ACCEPTED** |
| **Q4** | NULL warehouse = separate operational exception, no operator surface | Shipped, **narrowed** — **ACCEPTED** |
| **Q5** | No direct data remediation | Honoured — zero business-data writes (§10) |
| **Q6** | Collector cadence deferred | Not touched |

Nothing outside this list was implemented. **No second gate. No new predicate. No new payment
source of truth. No new status. No new lifecycle. No new API surface.** There is exactly **one**
`PaymentFulfillmentGate` class, and its three entry points all remain in use by their intended
callers.

---

## 2. M1 — the gated advance *(required item 1)*

**What it does.** `ProcessOrderWorkflow` now performs the `awaiting_payment -> in_progress`
advance, and performs it **only** when `PaymentFulfillmentGate::permitsAdvance()` allows it.
`ReevaluateOrderFulfillmentAction`'s advance branch was rewired from `ConfirmOrderWorkflow` to
`ProcessOrderWorkflow`; `ConfirmOrderWorkflow::paymentPermitsConfirmation()` now consults
`permitsAdvance()` too.

**The enum is unchanged, as ruled.** `OrderStatus::advancesToInProgressOnReservation()` still
returns `[in_progress, awaiting_stock, scheduled, on_hold, cancelled]`. `awaiting_payment` and
`confirmed` remain excluded. It stays a financial safety backstop for the ungated
`PatchOrderAction:328` route (BL-1); only a comment was added explaining that role.

**Why the gate had to live inside the workflow (BL-1).** `PatchOrderAction:328` routes a
`status=in_progress` request to `ProcessOrderWorkflow`. Placing the check only at the callers
would have left `awaiting_payment -> PATCH status=in_progress -> reservation -> in_progress`
open. With the gate inside the workflow, that path is closed at the single point every caller
passes through.

**The invariant the owner made a condition of acceptance, stated and held:**

> **A refused payment gate can never cause an advance to `in_progress`.**

Three properties keep it true:

1. The advance is guarded by `permitsAdvance()` **inside** the workflow, so no caller can skip it.
2. The creation-context suppression (§2.1) can only ever **withhold** an advance. It contains no
   branch that advances, so it cannot turn a refusal into a transition — suppression fails in the
   safe direction by construction.
3. `advancesToInProgressOnReservation()` still excludes `awaiting_payment`, so the reservation
   path cannot advance a payment-parked order either. Two independent mechanisms, not one.

**The advance lands on `in_progress`, never automatically `confirmed`** (ADR-042 §7.1, Amendment
A). `confirmed` is reachable only through the explicit Confirm action. Asserted both ways: the
advance records `initiate_order`, and **no `confirm_order` event may be written by a payment
fact**.

### 2.1 Creation-context suppression *(accepted)*

`CreateManualOrderAction` runs `ProcessOrderWorkflow` for every status in
`decidesAvailabilityAtCreation()`, which includes `awaiting_payment`. That call site documents the
invariant it depends on (CLOSURE-001 PART 1/23-B): deciding availability at creation must not
touch the payment block — the order *"stays Awaiting Payment and merely learns whether the goods
exist"*.

Without scoping, M1 broke it: a COD order created as `awaiting_payment` advanced to `in_progress`
**within the same request**, so `entryStatuses()` offered a state the creation request then took
away. `CreateManualOrderAction` now declares its invocation
(`['creation_availability_decision' => true]`) and the workflow's advance skips it. One flag, one
call site, and — per property 2 above — BL-1 stays closed.

---

## 3. The payment gate as it now stands — one class, three entry points

| Entry point | Direction | Blank `payment_method_manual` | Callers |
| --- | --- | --- | --- |
| `permitsAdvance()` | ADVANCE (`awaiting_payment -> in_progress`) | **refuses** (fails closed) | `ProcessOrderWorkflow`, `ReevaluateOrderFulfillmentAction` (advance branch), `ConfirmOrderWorkflow` |
| `permits()` | RETURN / ongoing evaluation | permits | `ReevaluateOrderFulfillmentAction` (return branch) |
| `permitsAtCreation()` | CREATION | permits (unchanged) | `CreateOrderAction`, `CreateManualOrderAction`, `WooCommerceOrderImporter` |

Verified after the final edit: exactly one class declares `PaymentFulfillmentGate`, and all three
entry points are still reached by live callers — none went dead.

The four-term conjunction is unchanged: method non-empty -> requirement `required` ->
`deposit_amount >= total` -> active verified proof.

---

## 4. BL-2-A — the resolution *(required item 2)*

**The defect I introduced.** O3 applied to `permits()` made a blank `payment_method_manual` fail
closed in **both** directions. `StoreManualOrderRequest` makes the method nullable and creation
permits it, so method-less orders legitimately exist; the RETURN direction reads `! permits()`.
The result was that recording a payment **demoted** a method-less order out of `in_progress` — two
failures in `OrderPaymentMethodAndSettlementContractTest`. I reported it as introduced by this
work rather than adjusting the tests around it.

**The resolution, as ruled (BL-2-A).** Fail closed on the **ADVANCE only**, via the new
`permitsAdvance()`. `permits()` was reverted to permissive on a blank method and
`permitsAtCreation(null, ...)` was left untouched.

**Constraints honoured:** manual creation does not fail on a NULL method; WooCommerce import
unchanged; no new payment-method source of truth; nothing demotes on the record-payment or return
path.

**Result:** `OrderPaymentMethodAndSettlementContractTest` is green. The security property is
unchanged, because the advance is the only direction that can move an order forward.

**Contract coverage (requirements A–E), no test weakened:**

| Req | Assertion | Covered by |
| --- | --- | --- |
| A | a blank method cannot bypass the advance gate | `test_a_blank_method_cannot_bypass_the_advance_gate` |
| B | a blank method does not demote on the return path | `test_a_blank_method_does_not_demote_an_order_on_the_return_path`, `test_record_payment_does_not_demote_a_method_less_order` (real HTTP) |
| C | creation still permits a NULL method | `test_creation_still_permits_a_null_method` (real HTTP `POST /api/orders/manual`) |
| D | COD advances | `test_awaiting_payment_with_cod_advances_to_in_progress` |
| E | a proof-required method still needs payment **and** a verified proof | `test_awaiting_payment_with_proof_required_method_does_not_advance` |

---

## 5. RC-3 — corrected eviction semantics *(required item 3)*

RC-3 makes `preparation_wave_orders` status-reactive. Before it, only
`preparation_session_orders` reacted; the wave store was write-once, which is why an
`awaiting_payment` order could sit in an active wave indefinitely.

**Where it lives.** `OrderPreparationObserver::reconcileWaveMembership()`, called at the top of
`updated()` **before** the session early-returns — deliberately, because the two stores are
independent and every previous path out of that hook was session-shaped. That is precisely why the
gap survived: the hook existed, but nothing in it could reach the wave store.

**How it evicts.** Through `WaveMembershipService::releaseIneligibleOrder()`, which **stamps
`released_at`** — it never deletes. Audit history is preserved, and there is still exactly one
writer of wave membership. It never writes `orders.status`: release is a membership decision.

**No event-driven alternative existed:** the demotion that produces this case runs
`ReturnToPaymentWorkflow`, whose `events()` returns `[]`. There is no domain event to subscribe to.

**The mandatory distinction, as the owner has now made binding:**

| Question | Predicate | Used for |
| --- | --- | --- |
| **Admission eligibility** | `OrderStatus::fulfilmentEligible()` = `[in_progress, confirmed]` | may this order *enter* preparation |
| **Preparation retention** | `fulfilmentEligible()` **and** `! hasLeftPreparation()` | is preparation *still* this order's concern |

### 5.1 `hasLeftPreparation()` — rationale *(required item 4)*

**Do not revert this method.** It is what prevents ordinary forward fulfilment
(`ready_for_dispatch -> out_for_delivery -> delivered`) from being read as Preparation eviction.

**The root fact.** `preparation_wave_orders.released_at` is written by **only** `closeWave()` and
`CancelWaveAction` — never by order completion. A successfully prepared order therefore holds an
**active** membership all the way through `ready_for_dispatch -> out_for_delivery -> delivered`.
None of those three is in `fulfilmentEligible()`.

**The defect that produced.** RC-3's first cut wrote retention as the bare negation of admission.
Ordinary forward progress therefore read as "became ineligible", and would have:

- released the membership row,
- decremented `orders_count` — the number `CompleteWaveAction:158` reports as what the wave
  actually prepared,
- fired `OrderRemovedFromWave` with a misleading reason plus a demand recompute, **on every
  dispatch**.

Each wave would have ended the day understating its own output. **This was live-imminent: 11 of
the 12 current wave members were sitting at `ready_for_dispatch` when it was found.**

**Why not an existing predicate.**

- `isTerminal()` = `[delivered, cancelled, returned]` mixes the order that completed **through**
  preparation (must be retained) with the two that **abandoned** it (must be evicted). It answers
  the wrong question.
- `isPreActivation()` is only `[scheduled]`.
- Inlining the status list in the observer would have contradicted that class's own standing rule
  that it holds no hardcoded status strings.

`hasLeftPreparation()` is therefore a lifecycle-**position** fact on the canonical enum
(`[ready_for_dispatch, out_for_delivery, delivered]`), placed beside `isTerminal()` and
`isPreActivation()`. It is **not** a rival eligibility predicate: `fulfilmentEligible()` remains
the one admission rule, and no existing caller's behaviour changed.

**Blast-radius audit.** Every `fulfilmentEligible()` consumer was enumerated. Exactly one other
site negates it — `MoveToPreparationWorkflow:42` — and that is an **admission** guard, correct as
written. No other site conflated the two questions.

**Differential proof, not just a green run:**

| Observer | `PaymentPreparationEligibilityContractTest` |
| --- | --- |
| retention guard **removed** | **2 failures** — a prepared order is evicted (`0` members, expected `1`) |
| retention guard **present** | **27 / 27 green** |

`test_a_cancelled_order_is_still_evicted` passes in **both** runs. That is what proves the guard
did not weaken abandonment eviction — the eviction RC-3 exists for still fires.

### 5.2 NULL-warehouse narrowing and carry-over protection *(required item 5)*

**Accepted rule: only a non-null warehouse *reassignment* may evict.**

```php
if ($order->assigned_warehouse_id !== null
    && (string) $order->assigned_warehouse_id !== (string) ($wave->warehouse_id ?? '')
) { /* release, reason: warehouse_reassigned */ }
```

`warehouse_unassigned` no longer exists as an eviction reason.

**Why treating NULL as a mismatch was wrong.** Wave membership is warehouse-keyed only in the
**collector** that fills it, never in the store. The certified carry-over contract
(`HandlePreparationWaveClosed`, `WaveCarryOverDependencyTest`) models membership purely by status
and G-1 completion, and its fixtures assign **no warehouse at all** — deliberately.

**Traced mechanism** (instrumented in a container copy, then removed):

```
ENTER order=01a02d7c... status=in_progress wh=NULL left=false
  EVICT-warehouse order=01a02d7c... wave_wh='67aba1dd-...'
```

The early release flipped `HandlePreparationWaveClosed` from **CASE C** (carry the unfinished
order back to In Progress) to **CASE B** (leave it Ready for Dispatch), silently cancelling
carry-over across **five certified scenarios** — a cliff, not a ratchet.

**Isolation evidence.** With RC-3's hook disabled, all five `WaveCarryOverDependencyTest` failures
disappeared while the unrelated DemandAnalysis failure persisted. That is what identified RC-3 as
the cause and the DemandAnalysis failure as independent.

**Q4's admission half is untouched and still asserted.** The collector is warehouse-keyed, so an
order with no warehouse is never admitted to a wave in the first place —
`test_a_null_warehouse_order_is_never_admitted`. Nothing invents or auto-assigns a warehouse, and
`BranchAssignmentEngine` was not modified.

**NULL-warehouse operational visibility remains a future Distribution UI concern** (§11).

---

## 6. Test results

**Command:** `GATE_WAIT=2400 sh scripts/test-gate.sh --filter <17 suites>` in
`ecos-dev-testrunner` (MySQL 8.4, `ecos_dev_test`).

```
Tests: 358, Assertions: 1177, Errors: 2, Failures: 1
```

### 6.1 Suites that were red and are now green

| Suite | Was | Now |
| --- | --- | --- |
| `OrderPaymentMethodAndSettlementContractTest` | 2 failures (introduced by O3 — **BL-2**) | green |
| `WaveCarryOverDependencyTest` | 5 failures (introduced by RC-3 — §5.2) | green |
| `OrderPaymentFulfillmentReevaluationTest` | 7 failures | green (contract updated — §6.2) |
| `PaymentPreparationEligibilityContractTest` | — | **27 tests green** |

### 6.2 Tests changed, and why that is not weakening them

`OrderPaymentFulfillmentReevaluationTest` encoded the **superseded** contract in which satisfying
the payment gate also performed the operator's Confirm. ADR-042 §7.1 (Amendment A, Q1) replaced
that. Seven assertions moved from `'confirmed'` to `'in_progress'`.

What was **not** relaxed:

- Every one of those rows still asserts an **exact** terminal status, not a set.
- All seven `awaiting_payment` **non-advance** premises (partial payment, unverified proof) are
  untouched — those are the rows that prove the gate still refuses.
- The attribution test **gained** an assertion: no `confirm_order` event may be written by a
  payment fact. That is the half that actually regressed.
- Two absolute event counts became **deltas** (`$before + 1`; "the second run adds nothing").
  Creation legitimately logs its own `initiate_order`, so a literal `1` was simply wrong; a delta
  is a stricter claim and survives future changes to the creation path.
- One premise assertion was **kept and documented as load-bearing**: that a COD order is still
  `awaiting_payment` immediately after creation. It is what caught §2.1.

### 6.3 Static gates

- **Pint:** PASS on every file this task touched. `ProcessOrderWorkflow.php` reports 29
  pre-existing alignment diffs; **0** are on lines this task added — verified by Pint-formatting a
  copy and grepping the diff for the added identifiers.
- **PHPStan:** `[OK] No errors` on all changed source files.

---

## 7. Regression classification *(required item 6)*

**Three problems remain, all classified PRE-EXISTING on isolation evidence, and accepted as such.**

| # | Test | Verdict |
| --- | --- | --- |
| E1 | `DemandProjectionBuilderTest::test_multiple_waves_do_not_cross_contaminate` | **PRE-EXISTING — not this task** |
| E2 | `DemandProjectionBuilderTest::test_initialize_wave_creates_kpi_row_with_zeros` | **PRE-EXISTING — not this task** |
| F1 | `FinishedGoodOwnReservationDemandTest::test_component_reserved_by_an_order_inside_the_same_wave` | **PRE-EXISTING — not this task** |

**Evidence, E1/E2.** `ArgumentCountError: Too few arguments to
MaterialDemandCalculator::__construct(), 0 passed ... exactly 1 expected`. That constructor gained
an `ActiveRecipeResolver` dependency in a different, in-flight workstream: the whole
`Modules/Operations/DemandAnalysis` tree is dirty (12 modified + 10 untracked files), none of them
touched by this task. A DI signature mismatch in a module this task never enters.

**Evidence, F1.** Re-run with RC-3 **disabled**: this test still failed, identically, while the
five carry-over failures vanished. Independent of this work.

**`Modules/Operations/DemandAnalysis` was not modified. Unrelated DI/test debt was not fixed in
this task**, per direction.

---

## 8. Browser verification *(required item 8)*

> ### BROWSER NOT VERIFIED — DATA SAFETY / AUTHENTICATION CONSTRAINT

**No Browser Verified claim is made anywhere in this report.**

Two independent blockers, neither worked around:

1. **AUTHENTICATION.** The UI (Vite, `:5173`) requires an interactive login. Credentials were not
   entered.
2. **DATA SAFETY.** Demonstrating the behaviour in the UI would require an `awaiting_payment`
   order to advance and a wave member to be evicted. Every candidate is protected: `ORD-00017` and
   `ORD-00019` are named as not-to-mutate, `ORD-00008` is explicitly not to be repaired (§9), and
   the wave's `intake_closes_at` passed at 05:00 — so a newly created order **cannot legitimately
   join a wave at all.** RC-3 eviction cannot be demonstrated without editing a wave schedule or
   mutating protected live business data. No business data was created or mutated to obtain
   screenshots.

**Accepted evidence in its place.**

**API layer (real HTTP: routes, FormRequests, controllers, middleware, observers) — VERIFIED,
read-only.** `GET /api/orders` via `127.0.0.1:8081` returned all 19 orders with statuses, payment
methods and warehouse assignment consistent with the database and with the new contract; `401`
without a token; database confirmed `ecos_dev`, not the test database.

All four HTTP surfaces this task changes are additionally covered by focused tests that exercise
the real routes: `PATCH /api/orders/{id}/quick-update`, `POST /api/orders/{id}/record-payment`,
`PUT /api/orders/{id}` (O1 validation), `POST /api/orders/manual`.

**This outstanding item is the sole reason the verdict is NOT CERTIFIED.**

---

## 9. ORD-00008 — left untouched *(required item 7)*

**State, observed read-only and unchanged:** `awaiting_payment` + `cod` + warehouse assigned.

This is the live evidence that the M1 business path is relevant: COD requires no proof, so the
gate permits, and this order will advance to `in_progress` on its next legitimate payment or edit
event.

**It was not manually repaired for verification, and must not be.** Specifically, none of the
following was done and none may be done:

- no direct status patch
- no direct payment-method change
- no direct `confirmed_at` change
- no fabricated payment data
- no payment-gate bypass
- no fabricated wave or order

Its correction must arrive through a legitimate business event, not through verification activity.

---

## 10. Side-effect audit *(required item 9)*

| Check | Result |
| --- | --- |
| Orders total | **19**, unchanged (`ORD-00001` ... `ORD-00019`) |
| Orders created by this work | **0** |
| `ORD-00008` | **untouched** (§9) |
| `ORD-00017`, `ORD-00019`, `ORD-00013`, `ORD-00014`, `ORD-00001` | untouched |
| `preparation_wave_orders.released_at` stamped in the last 8 h | **0** — the deployed RC-3 code has evicted nothing |
| Direct SQL / status / timestamp corrections | none (Q5 honoured) |
| Fabricated fleet, payment, wave or order data | none |
| Migrations | none |
| Commits / deployments | none |

**Non-business writes, both reversed:** one Sanctum token minted for read-only verification, then
**revoked**; trace instrumentation added to a container copy of the observer to diagnose §5.2, then
removed — verified 0 occurrences remain in the deployed file.

**Not attributable to this work:** `ORD-00001`/`ORD-00019` reaching `ready_for_dispatch` at
05:00:01–02, and `ready_for_dispatch` events continuing at 08:00:02, are the wave scheduler's
`->everyMinute()` job.

### 10.1 Pre-existing stale membership persists, by design

`ORD-00017` is `awaiting_payment` **and** still an active wave member — the original RC-3 target
case. Q5 forbids remediation, so it correctly remains. **RC-3 is forward-acting only:** it evicts
on the next status change, never retroactively. Cleanup of existing stale rows is a separate,
unauthorised decision and was not performed.

---

## 11. Follow-ups recorded, not implemented

1. **Warehouse-Unassigned operational visibility.** `ORD-00013` and `ORD-00014` are `in_progress`
   with no warehouse and in no wave; per Q4 there is deliberately no bucket or endpoint, so they
   are invisible to operators. Recorded as a **future Distribution UI concern**. Not built. No
   Distribution UI work, no new API surface, no `BranchAssignmentEngine` change, no auto-assignment.
2. **`payment_proofs` has no `method` column** — pre-existing STOP, unchanged.
3. **`BrandPolicy` manual-entry default is pre-V3** and parks unconfigured brands — pre-existing.
4. **Q6 collector cadence** — deferred by ruling. No collector scheduling was added.
5. **DemandAnalysis suite** — 3 failures owned by that in-flight workstream (§7). Not modified.

---

## 12. Files changed and final verdict *(required item 10)*

**Source (9):**

| File | Change |
| --- | --- |
| `Commerce/Orders/Domain/Services/PaymentFulfillmentGate.php` | `permitsAdvance()` added; `permits()` reverted to permissive on blank |
| `Commerce/Orders/Domain/Enums/OrderStatus.php` | `hasLeftPreparation()` added. `advancesToInProgressOnReservation()` behaviour **unchanged** |
| `Commerce/Orders/Application/Actions/ReevaluateOrderFulfillmentAction.php` | advance rewired to `ProcessOrderWorkflow` + `permitsAdvance`; return path still `permits` |
| `Commerce/Orders/Application/Actions/CreateManualOrderAction.php` | declares `creation_availability_decision` |
| `Commerce/Orders/Presentation/Http/Requests/UpdateOrderRequest.php` | O1 — `filled` on `payment_method_manual` |
| `Operations/Fulfillment/Application/Workflows/ProcessOrderWorkflow.php` | M1 gated advance, scoped away from creation |
| `Operations/Fulfillment/Application/Workflows/ConfirmOrderWorkflow.php` | uses `permitsAdvance` |
| `Operations/Preparation/Application/Observers/OrderPreparationObserver.php` | RC-3 wave reconciliation + retention guard + narrowed warehouse branch |
| `Operations/Preparation/Application/Services/WaveEngine/WaveMembershipService.php` | `releaseIneligibleOrder()` — release, never delete |

**Tests (2):** `tests/Feature/Orders/PaymentPreparationEligibilityContractTest.php` (new, 27
tests) · `tests/Feature/Commerce/OrderPaymentFulfillmentReevaluationTest.php` (contract updated,
§6.2).

**Docs (2):** `docs/adr/ADR-042-order-fsm-v3-canonical.md` (amendments A/B/C) · this report.

**Migrations: none.**

---

> # FINAL VERDICT
>
> ## IMPLEMENTED · VERIFIED · NOT CERTIFIED
>
> **Reason: Browser Verification Outstanding** (§8 — BROWSER NOT VERIFIED — DATA SAFETY /
> AUTHENTICATION CONSTRAINT).
>
> Accepted: M1 (§2), BL-2-A (§4), RC-3 corrected eviction semantics including
> `hasLeftPreparation()` (§5, §5.1), the NULL-warehouse narrowing (§5.2), and the three
> DemandAnalysis failures as pre-existing (§7).
>
> **No commit. No deploy.**
