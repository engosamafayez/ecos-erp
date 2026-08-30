# TASK-ORDER-PAYMENT-PREPARATION-CONTRACT-DECISION-001 — Contract Decision Package

**DECISION PACKAGE ONLY. NO CODE, NO MIGRATION, NO DB MUTATION, NO FRONTEND OR BACKEND
CHANGE, NO COMMIT, NO DEPLOY.**

> ### OWNER RULINGS RECORDED — 2026-08-23
>
> | Ruling | Decision |
> |---|---|
> **Q2 — advance mechanism** | ✅ **S2 APPROVED** (see §10.1). `ReturnToPendingWorkflow` must **not** be used for this transition — verified below that it releases inventory. |
> **Payment change contract** | ✅ Approved. Payment Method Change ≠ Order Confirmation; it triggers eligibility re-evaluation only. |
> **Count invariant scope** | ✅ Corrected to **`company_id` + `assigned_warehouse_id` + wave** (see §8). |
> **NULL-warehouse population** | ✅ Ruled a **separate operational exception** (see §8.1). |
>
> Q1, Q3, Q4, Q5, Q6 remain open (§17). **Implementation remains blocked.**

Every rule below is traced to existing code or approved text. Where the owner's target
behaviour cannot be expressed by an existing contract, that is stated as an open question
rather than resolved by invention.

Paths: `<B>` = `C:/ecos-develop/backend`, `<R>` = `C:/ecos-develop`.

---

## 1. Business decision (as instructed, recorded for approval)

**Payment Method Change ≠ Order Confirmation.**

```
Payment Method Change
   → Fulfillment Eligibility Re-evaluation        (approved, D1-A — unchanged)
       → may produce:  Awaiting Payment → In Progress
       → may produce:  In Progress / Confirmed → Awaiting Payment   (return, unchanged)
       → MUST NEVER produce:  → Confirmed

In Progress → Confirmed
   → explicit operator confirmation workflow ONLY
```

Rationale accepted: a method describes *how* the customer will pay. Confirmation is a
distinct commercial act. When COD makes an order fulfilment-eligible without prepayment, the
correct outcome is that it **enters the fulfilment queue** (`in_progress`), not that someone
is deemed to have confirmed it.

### ✅ Good news: this needs no new lifecycle, no new workflow and no new status

`awaiting_payment → in_progress` is **already a legal, existing transition with an existing
workflow**:

`<B>/Modules/Operations/Fulfillment/Application/Workflows/ReturnToPendingWorkflow.php:34-50`
```php
$allowed = [
    OrderStatus::Confirmed,
    OrderStatus::AwaitingPayment,   // ← already permitted
    OrderStatus::AwaitingStock,
    OrderStatus::OnHold,
    OrderStatus::Cancelled,
    OrderStatus::Scheduled,
];
```
…and it writes `InProgress`. So the decision is expressible entirely within the approved FSM.

---

## 2. The ADR-042 contradiction, stated exactly

Three passages, all in `<R>/docs/adr/ADR-042-order-fsm-v3-canonical.md` ("Status: Approved"):

| Passage | Verbatim | Implies |
|---|---|---|
**§7** (:314-316) | "`scheduled` and `awaiting_payment` are **not** eligible — they sit outside fulfilment execution until their own business triggers make them eligible, **at which point they enter `in_progress`** and become eligible by that route." | payment trigger → **`in_progress`** |
**§5 rule 3** (:260-262) | "**Confirm** (`in_progress → confirmed`) is an **explicit operator action** exposed at `POST /fulfillment/orders/{order}/confirm`. It must store `confirmed` — not `in_progress`." | Confirm is **operator-initiated**, and its source is **`in_progress`** |
**§6** (:279-281) | "When an order reaches Confirm without a reservation (for example, it was created `awaiting_payment` and paid), Confirm reserves." | `awaiting_payment → confirmed` is **anticipated** |

### What §6 actually explains — and what it does not

§6 is about **what Confirm must do when it is reached** from an unreserved state (namely:
reserve). It is a *behavioural* clause about reservation, **not an authorisation clause for
an automatic trigger**. It says nothing about *who or what* invokes Confirm.

The implementation reads §6 as licence for an **automatic** advance:
`ReevaluateOrderFulfillmentAction:104-113` invokes `ConfirmOrderWorkflow` directly from
`awaiting_payment`, which:

- **violates §7** — skips `in_progress` entirely (ORD-00019's `order_events` show a single
  hop `awaiting_payment → confirmed`, with no `in_progress` record); and
- **violates the "explicit operator action" half of §5 rule 3** — there is no operator intent
  in the call.

**Conclusion: §6 is not in conflict with §7. Only the implementation is.** The apparent
three-way contradiction collapses to a two-way one once §6 is read as scoped to reservation
behaviour. That materially narrows the amendment.

---

## 3. Proposed ADR-042 amendment (FOR APPROVAL — not adopted)

No new lifecycle. No new status. No new workflow. Three clarifying edits:

### Amendment A — §7, clarify the trigger's destination (confirmatory, not a change)

> …until their own business triggers make them eligible, at which point they enter
> `in_progress` and become eligible by that route. **A payment-fact trigger (payment
> recorded, payment proof verified, payment method changed) that satisfies
> `PaymentFulfillmentGate` therefore advances `awaiting_payment → in_progress`. It never
> advances to `confirmed`: Confirm is an explicit operator action (§5 rule 3).**

### Amendment B — §6, scope the clause to reservation behaviour

> When an order reaches Confirm without a reservation (for example, it was created
> `awaiting_payment` and paid), Confirm reserves. **This clause governs what Confirm must
> do when it is reached from an unreserved state; it does not authorise an automatic or
> non-operator invocation of Confirm. `awaiting_payment` remains an allowed Confirm source
> for the explicit operator action and for the documented recovery paths only.**

### Amendment C — §3.1, separate the two directions of the payment trigger

> Payment-fact triggers run `ReevaluateOrderFulfillmentAction`, which is the single entry
> point. **Advance direction: gate satisfied + `awaiting_payment` → `in_progress`. Return
> direction: gate unsatisfied + fulfilment-eligible status → `awaiting_payment`
> (unchanged). Neither direction confirms an order.**

### Confirmed against the six required properties

| # | Required | Satisfied by |
|---|---|---|
1 | `awaiting_payment → in_progress` only when financial conditions are met | Gate is the sole authority; Amendment A |
2 | `in_progress → confirmed` explicit operator only | Amendment B; §5 rule 3 restored |
3 | Method change does not confirm | Amendment C |
4 | Method change may trigger re-evaluation | D1-A preserved verbatim |
5 | Payment verification keeps its own path | Unchanged — `VerifyPaymentProofAction` remains a separate trigger |
6 | Payment state independent of order status | Unchanged — `PaymentState` stays derived and unstored |

---

## 4. State transition matrix

Legend: **Gate** = `PaymentFulfillmentGate::permits()`. "COD" = a method whose brand-policy
requirement resolves to `none`. "Instapay" = a method whose requirement resolves to
`required`.

| # | Current status | Old method | New method | Payment state | Expected status | Prep eligible | Authority |
|---|---|---|---|---|---|---|---|
**A** | Awaiting Payment | Instapay | Instapay (no change) | Unpaid, no proof | **Awaiting Payment** | **NO** | No change ⇒ no trigger. `announceGeographyChange`-style equality check pattern |
**B** | Awaiting Payment | Instapay | **COD** | Unpaid | **In Progress** ✳ | **YES** | **Amendment A** — today produces `Confirmed` ❌ |
**C** | In Progress | Instapay | COD | Unpaid | **In Progress** (unchanged) | YES | Correct today — source is not `awaiting_payment`, so no advance fires |
**D** | In Progress | COD | **Instapay** | Unpaid, no proof | **Awaiting Payment** | **NO** | Correct today. `ReturnToPaymentWorkflow` blocks only `AwaitingPayment, ReadyForDispatch, OutForDelivery, Delivered, Returned, Cancelled` (`:27-43`) — `InProgress` is permitted, so it **returns**; the edit is **not** blocked |
**E** | In Progress | — | — (explicit Confirm) | any | **Confirmed** | YES | `ConfirmOrderWorkflow` — canonical source. Unchanged |
**F** | Awaiting Payment | — | — (explicit Confirm) | gate satisfied | **Confirmed** — **ALLOWED** | YES | Existing contract, unchanged: `ConfirmOrderWorkflow:51-62` lists `AwaitingPayment` as a permitted source, described as a **recovery source** — "already permitted before ADR-042 and preserved so re-activating a held, returned or cancelled order still reaches Confirmed". Guarded by `paymentPermitsConfirmation()` at `:84`. **Not invented; not proposed for removal.** |
**F′** | Awaiting Payment | — | — (explicit Confirm) | gate **unsatisfied** | **refused (422)** | NO | `ConfirmOrderWorkflow:84-90` throws `WorkflowPreconditionException`. Unchanged |
**G** | any | any | any | any | **NEVER auto-Confirmed** | — | **Amendment C** |

✳ **Row B carries the one open implementation question — see §10.1.** The *decision* is
unambiguous; the mechanism needs one verification before it is scoped.

**Note on F:** the owner asked whether explicit Confirm from `awaiting_payment` should be
allowed or must route through `in_progress` first. The existing contract **allows it
directly**, as a gate-checked recovery source. This package does **not** propose changing
that — only the *automatic* path changes. Removing `AwaitingPayment` from Confirm's allowed
sources would break the documented recovery paths and is explicitly a non-goal (§18).

---

## 5. Payment Method vs Payment State — formal contract

The prior framing ("payment state alone is authoritative") was **wrong**.
`PaymentFulfillmentGate::permits()` (`:52-69`) is a **four-term conjunction** evaluated in
order:

| Term | Reads | Decides |
|---|---|---|
1 | `methodOf($order)` = `payment_method_manual ?? payment_method` (`:97`) — non-empty? (`:57-59`) | **whether the control applies at all** |
2 | `requirementFor(method, channel_id, company_id) === 'required'` (`:61`) | **which policy applies** |
3 | `deposit_amount >= total` (`:179-182`) | settlement |
4 | an ACTIVE VERIFIED `payment_proofs` row (`:208-216`) | evidence |

### Formal definitions

| Concept | Field(s) | Means | Authoritative for |
|---|---|---|---|
**Payment Method** | `payment_method_manual` (primary), `payment_method` (fallback) | *how* the customer will pay | **which financial control/policy applies** (gate terms 1–2) |
**Payment State** | derived: `deposit_amount` vs `total` (`PaymentState`) | *actual settlement condition* | gate term 3; display; rollups |
**Payment Proof** | `payment_proofs` rows | *evidence* submitted | gate term 4 input |
**Payment Verification** | `payment_proofs.state = 'verified'`, `superseded_at IS NULL` | *verification state* of that evidence | gate term 4 |
**Order Status** | `orders.status` | **fulfilment lifecycle position** | Preparation / Distribution eligibility |

**Invariant to record:** Order Status is *downstream* of the gate. The gate is a function of
(method, policy, settlement, verification). **Payment State never writes Order Status
directly** — only `ReevaluateOrderFulfillmentAction`, through an approved workflow, does.

### The blank-method bypass — options, no implementation

`methodOf()` returning `''` makes `permits()` return **`true` unconditionally** at `:57-59`.
Reachable today: `payment_method_manual` is accepted `nullable` at `UpdateOrderRequest.php:66`
and persisted via `SOFT_FIELDS` (`UpdateOrderAction.php:37`).

| Option | Description | Assessment |
|---|---|---|
**O1** | **Reject blanking** — make `payment_method_manual` non-nullable on the update path | Smallest change; matches the create path, which already requires a method. **Recommended for scoping.** Risk: legacy rows with a null method would fail validation on unrelated edits — needs a data check first |
**O2** | Resolve an effective method from a canonical fallback (e.g. channel default) | Introduces a new resolution rule and a new source of truth for "method". **Not recommended** |
**O3** | Invert the gate's empty-method default — no method ⇒ **not** permitted (fail closed) | Most correct semantically; a control that cannot identify the policy should not pass. Risk: could block legacy null-method orders from confirming at all. **Recommended to evaluate alongside O1** |
**O4** | Leave as-is, document | Not acceptable — it is a reachable financial-control bypass |

**Proposed boundary:** evaluate O1 + O3 together (validation *and* fail-closed default), each
behind its own test, after a read-only count of null-method orders. **No architecture change
required.**

---

## 6. Preparation eligibility contract (unchanged, restated)

**Canonical:** ADR-042 §7 ⇒ `OrderStatus::fulfilmentEligible()` = `['in_progress','confirmed']`
(`<B>/Modules/Commerce/Orders/Domain/Enums/OrderStatus.php:147`).

`awaiting_payment` and `scheduled` are **not** eligible. The predicate at
`WaveMembershipService.php:42-58` is **correct and is not being changed.**

Per-consumer closed lists remain approved (§7: "Distribution deliberately does not import
Preparation's list"). **One illegitimate copy** exists and is proposed for correction:
`wave_engine_configurations.eligible_order_statuses` — mutable DB JSON, cast `'array'` with
no enum fallback (`WaveEngineConfiguration.php:46,60`), read raw at
`WaveMembershipService.php:43`. Proposed: validate against / default to
`OrderStatus::fulfilmentEligible()`. Live value agrees today, so this is latent drift.

---

## 7. Preparation Wave reconciliation contract (RC-3) — proposed boundary

### Existing Wave contract, preserved verbatim

- Wave lifecycle is independent of the calendar day.
- Cutoff (`intake_closes_at`) **freezes intake only** — it is not a membership lock.
- Active membership is `released_at IS NULL`.
- Orders not prepared/shipped as required **return to In Progress for the next wave** — the
  existing mechanism is `ReturnToProcessingWorkflow` (guard `:24` requires
  `ReadyForDispatch`, writes `InProgress` at `:35`), fired automatically from
  `HandlePreparationWaveCancelled.php:29` and `HandlePreparationWaveClosed.php:57`.

**None of this changes.**

### The gap

`preparation_wave_orders` is a write-once snapshot. `released_at` is written by exactly two
places, both wave-lifecycle: `CancelWaveAction.php:54`, `WaveLifecycleService.php:137`.
Nothing reacts to an order's status changing. `ReturnToPaymentWorkflow::events()` returns
`[]`, so the demotion emits **no domain event at all**.

### The precedent to copy (already in the codebase)

`preparation_session_orders` **is** status-reactive:
`PreparationServiceProvider.php:127` registers `OrderPreparationObserver`, which on
`wasChanged('status')` (`:64-78`) consults `PreparationReleaseEngine.php:36-48` and detaches
with a reason. Six live rows carry `status_ineligible:awaiting_stock` /
`status_ineligible:ready_for_dispatch`.

### Answers to the §4 questions

| Question | Proposed answer |
|---|---|
**What watches status changes?** | The **existing** `OrderPreparationObserver` hook (Eloquent `updated` + `wasChanged('status')`). Extend its *reach* to the wave store; do not build a second watcher. An event-driven design is **not viable as-is** because the demotion emits no event — an observer is the only available hook |
**When is an order removed?** | The moment its status leaves `fulfilmentEligible()` **while holding active membership** in a wave that has not yet closed |
**Event-driven?** | **No** — observer-driven, matching the session precedent. Adding a domain event to `ReturnToPaymentWorkflow` is a viable alternative but is a wider change and would need its own decision |
**What happens on becoming ineligible?** | Release the membership with an explicit reason (`status_ineligible:<status>`), exactly as the session store does. **Do not** touch `orders.status` — Preparation must not write the order lifecycle |
**Return to In Progress / next wave?** | **No new mechanism.** The order's status is already whatever made it ineligible (e.g. `awaiting_payment`). When it becomes eligible again it is re-collected by the existing every-minute sweep (`routes/console.php:58-61`). The existing `ReturnToProcessingWorkflow` path for `ready_for_dispatch` at wave close is untouched |
**Duplicate membership?** | Already guaranteed — `uq_prep_wave_orders_company_order_active` permits at most one *active* membership per order. A released row is history and blocks nothing |
**Audit trail?** | Reuse the session store's shape: stamp `released_at` + a machine-readable reason. Never hard-delete a membership row |

### Explicit non-goal

Do **not** filter ineligible members at read time in the UI as the fix. Read-time filtering
is acceptable as *defence in depth*, but the membership row itself must be released, or the
counter `preparation_waves.orders_count` and every downstream projection stay wrong.

---

## 8. Distribution eligibility relationship + count invariant (§5)

### ✅ APPROVED INVARIANT (owner-ruled scope)

The invariant is **not global**. It is evaluated strictly within one
`company_id` + `assigned_warehouse_id` + wave:

```
For one (company_id, assigned_warehouse_id, active Preparation Wave):

    Preparation Eligible  ==  Distribution Eligible  ==  Zone Eligible

    Assigned    <=  Eligible
    Unassigned  ==  Eligible - Assigned
```

Any count presented without all three scope keys is meaningless and must not be compared.

### Why the qualifier was required

Two live orders break the unscoped form, and **not** because of RC-3:

| Order | Status | In wave? | In Distribution? | Why |
|---|---|---|---|---|
ORD-00013 | in_progress | **NO** | YES | `assigned_warehouse_id IS NULL` |
ORD-00014 | in_progress | **NO** | YES | `assigned_warehouse_id IS NULL` |

A Preparation Wave is **per warehouse**, so an order with no warehouse can never be a
member. But it *does* occupy a `distribution_window_orders` row, and it is excluded from
Distribution's *warehouse-scoped* reads while still counting in unscoped ones.

A Preparation Wave is **per warehouse**, so an order with no warehouse can never be a member
— yet it occupies a `distribution_window_orders` row and is excluded from Distribution's
*warehouse-scoped* reads while still counting in unscoped ones.

## 8.1 ✅ NULL-warehouse population — owner ruling

Orders with `assigned_warehouse_id IS NULL` are a **separate operational exception**, not a
counting edge case.

| Rule | Ruling |
|---|---|
Eligibility | **Excluded** from the eligibility of *any* warehouse wave |
Invent a warehouse? | **NO** |
Auto-move to Main Warehouse? | **NO** |
Delete the order? | **NO** |
Surface as | **"Warehouse Unassigned / Needs Warehouse Assignment"** — its own operational exception state |
Resolution | Only through the **existing approved warehouse assignment workflow** |
Wave membership | Permitted **only after** a valid warehouse is set, per the existing contract |

This population is therefore **outside the §8 invariant by construction** — it is neither
Preparation-eligible nor warehouse-scoped-Distribution-eligible. It must be *visible*, never
silently dropped: an order nobody can prepare is an operational fact that needs an operator,
not a filter.

Live members of this population today: **ORD-00013, ORD-00014** (both `in_progress`, both
holding a `distribution_window_orders` row, both zoned — 3 — but unassignable to any wave).

**Note:** the existing `BranchAssignmentEngine` (`CreateManualOrderAction:216` calls
`$this->branchAssignment->assign(...)`) is the approved assignment path. Why it produced NULL
for these two orders is **not** established by this package and is not in scope here.

Today's arithmetic, for the record:

```
distribution_window_orders            = 13
  − ORD-00013, ORD-00014 (no warehouse) = 11  Eligible   ✅
  − ORD-00001 (no city ⇒ no zone)        = 10  Assigned + 1 Unassigned  ✅
Preparation active members            = 12  = the same 11 + ORD-00017 (awaiting_payment)
12 − 11 = ORD-00017, exactly.
```

After RC-3, ORD-00017 leaves the wave and Preparation reads **11**, matching Distribution.

### Cadence asymmetry (recorded, no fix proposed here)

Preparation's collector runs `->everyMinute()`. **Distribution has no scheduled collector at
all** (`grep -rn "Schedule::" Modules/Logistics/` returns nothing) — it runs only on operator
Refresh. Any order whose eligibility window is shorter than the gap between the two
collectors reproduces a mismatch. ORD-00017's window was **13 seconds**. RC-3 fixes the
*stale member*; it does not equalise the cadences. Flagged for a separate decision.

---

## 9. Zone / City inline editing contract (RC-4) — proposed boundary only

| Edit | Canonical path | Must NOT do |
|---|---|---|
**City / Governorate** from the Zones table | Write `orders.city` / `orders.governorate` via the existing Orders write path, then let the **already-shipped** chain run: `OrderGeographyChanged` → `SyncOrderGeographyListener` → `OrderCityBinder::rebindOrder()` (canonical `logistics_city_id`) → `OrderZoneResolver` → `ManualAssignmentService::changeOrderZone()` | Write `logistics_city_id` directly; write a zone from city text; bypass `OrderCityResolver` |
**Zone** from the Zones table | `ManualAssignmentService::changeOrderZone()` **only** | Write `distribution_window_orders.distribution_zone_id` directly; write `delivery_zone` / `delivery_zone_id` text as if it were the zone |
**Unassigned orders** | Must be listed and resolvable **from the same table** — an unzoned order is a first-class row, not an omission | Hide unassigned orders |

Note: `delivery_zone` / `delivery_zone_id` are **free-text display labels** with no catalog
and no link to `distribution_zones`. They are not the zone. (Recorded in the geography audit.)

The backend already accepts `city` on `PATCH /orders/{id}/quick-update`; the UI control is
the missing piece. **No new endpoint is proposed.**

---

## 10. RC-1 (Edit Order 500) — proposed boundary only

**Exact writer:** `<B>/Modules/Commerce/Orders/Application/Actions/UpdateOrderAction.php:154-158`

```php
foreach (self::STRUCTURAL_FIELDS as $field) {
    if (array_key_exists($field, $extraData)) {
        $attributes[$field] = $extraData[$field];   // ← copies NULL verbatim
    }
}
```

**Why null throws:** `array_key_exists()` is true for a key present *with a null value*.
`orders.discount_amount` is `decimal(10,2) NOT NULL DEFAULT '0.00'` ⇒ MySQL 1048. Note
`:161` already reads the value null-safely **for the arithmetic** (`?? 0`) but never writes
the coerced value back — so totals are computed correctly while the persisted value stays
null.

| Aspect | Proposed |
|---|---|
**Correct normalisation** | Coerce to `0` at the **write**, server-side, for the numeric structural fields. Fix in `UpdateOrderAction:154-158` (and/or tighten `UpdateOrderRequest.php:69`). Frontend `order-form-schema.ts:184` may also be fixed but **must not be the only fix** — the server contract is what guarantees it |
**Validation** | Keep `nullable` **or** move to `['present','numeric','min:0']`; either is acceptable provided the write coerces. Do **not** relax `min:0` |
**Preserving totals** | The arithmetic at `:161-172` already coalesces, so coercing at the write **changes no computed total**. Verify with a test asserting `subtotal / grand_total / discount_total` are byte-identical before and after |
**Preserving geography dispatch ordering** | `OrderGeographyChanged` is dispatched at `:321-322`, **after** the transaction closes at `:240`. That ordering is deliberate (the audit event and the persisted row must precede the announcement) and **must not move** |
**Must NOT change** | The `NOT NULL DEFAULT '0.00'` constraint; the method-value narrowing at `UpdateOrderRequest.php:66`; the D6 exclusion of `deposit_amount` (`:71`, `UpdateOrderAction:39-48`) |

**Confirmed independent** of §2/§3/§4 and of the geography work: same 1048 logged 2026-08-19,
08-20, 08-22, 08-23; offending frontend line byte-identical at HEAD; all four order GETs
return 200.

### 10.1 THE ONE OPEN IMPLEMENTATION QUESTION (matrix row B)

The *decision* is settled; the *mechanism* is not, and I will not guess it.

The advance must achieve **two** things: set `in_progress` **and** ensure inventory is
reserved (`ReturnToPaymentWorkflow` released it on the way in). No single existing workflow
does both from `awaiting_payment`:

| Candidate | Sets `in_progress` from `awaiting_payment`? | Reserves? |
|---|---|---|
`ReturnToPendingWorkflow` | **YES** — `AwaitingPayment` is in its allowed sources (`:36-43`) | It **releases** held inventory (it is the "Unlock" workflow) |
`ProcessOrderWorkflow` | **NO** — `AwaitingPayment` is an allowed *source* (`:52`), but the terminal write is gated by `OrderStatus::advancesToInProgressOnReservation()`, which **excludes `AwaitingPayment` by explicit design**: *"Awaiting Payment and Confirmed are excluded in BOTH directions: having stock is no more a reason to declare an unpaid order In Progress than lacking it is a reason to declare it Awaiting Stock."* | **YES** |

**Three candidate shapes were presented. ✅ OWNER RULING: S2 APPROVED.**

- **S1** — *(not selected)* `ProcessOrderWorkflow` **then** `ReturnToPendingWorkflow`.
  Rejected in favour of S2; also inherits S3's inventory-release problem.
- **S2** — ✅ **APPROVED.** Amend `OrderStatus::advancesToInProgressOnReservation()` to
  include `AwaitingPayment`, so `ProcessOrderWorkflow` alone both reserves and writes
  `in_progress`. The owner ruled that the helper's documented exclusion was scoped to
  **stock-driven** advancement ("having stock is no more a reason to declare an unpaid order
  In Progress") and does **not** govern **gate-driven** advancement, where the payment
  condition has actually been satisfied.
- **S3** — ❌ **REJECTED, and now proven unsafe.** `ReturnToPendingWorkflow` releases
  inventory. Verified at
  `<B>/Modules/Operations/Fulfillment/Application/Workflows/ReturnToPendingWorkflow.php:57-60`:
  ```php
  if ($order->assigned_warehouse_id !== null && $order->inventory_released_at === null
      && $order->inventory_reserved_at !== null) {
      $this->releaseInventory->execute($order);
  }
  ```
  Its docblock is explicit: *"UNLOCK — returns an order to In Progress, **releasing its
  inventory reservation**."* Using it here would advance the order to `in_progress` **with
  its reservation dropped** — the opposite of the required outcome.

### Constraints attached to the S2 ruling

1. **`ReturnToPendingWorkflow` must not be used for this transition** (owner instruction;
   grounds verified above).
2. **Payment method change must never reach `Confirmed`.** Confirm stays an explicit
   operator action.
3. **`ConfirmOrderWorkflow` keeps `AwaitingPayment` as a recovery source — unchanged.** It
   must not be removed or further restricted.
4. **No new `OrderStatus`, no new workflow class, no new lifecycle.** The existing FSM
   expresses the whole transition.

### Useful precedent discovered while verifying S3

`ReturnToPendingWorkflow:67` sets `'confirmed_at' => null` — *"Unlocking undoes the
confirmation, so the stamp must not survive it."* This is the in-repo pattern for the
orphaned-`confirmed_at` defect recorded in §3 of the audit: `ReturnToPaymentWorkflow` should
clear the stamp the same way. Scoped, not implemented.

---

## 11. Required tests (to be written before any implementation)

| # | Test | Expected |
|---|---|---|
T1 | Matrix A — Awaiting Payment, method unchanged | stays Awaiting Payment; **no** trigger fires |
T2 | Matrix B — Awaiting Payment + Instapay → COD | **In Progress**; NOT Confirmed; prep-eligible |
T3 | Matrix C — In Progress + Instapay → COD | stays In Progress |
T4 | Matrix D — In Progress + COD → Instapay | returns to Awaiting Payment; edit not blocked |
T5 | Matrix E — explicit Confirm from In Progress | Confirmed |
T6 | Matrix F — explicit Confirm from Awaiting Payment, gate satisfied | Confirmed (**unchanged** behaviour) |
T7 | Matrix F′ — explicit Confirm from Awaiting Payment, gate unsatisfied | 422, status unchanged |
T8 | Matrix G — no payment-method change ever yields Confirmed | asserted across all methods |
T9 | `confirmed_at` is cleared by `ReturnToPaymentWorkflow` | null after demotion |
T10 | Blank `payment_method_manual` does not open the gate | refused (per O1/O3 ruling) |
T11 | **RC-3** — order becomes ineligible after wave admission | membership released with reason; `orders_count` decremented; `orders.status` untouched |
T12 | RC-3 — eligible member is never released | no false positives |
T13 | RC-3 — released order is re-collected when eligible again | one active membership, no duplicate |
T14 | RC-3 — `ready_for_dispatch` at wave close still uses `ReturnToProcessingWorkflow` | existing path untouched |
T15 | **Count invariant** — same (company, warehouse, wave): Prep Eligible == Distribution Eligible | equal |
T16 | Count invariant — Unassigned == Eligible − Assigned | holds |
T17 | Orders with `assigned_warehouse_id IS NULL` | behave per the §8 decision (pending) |
T18 | **RC-1** — `PUT /orders/{id}` with `discount_amount: null` | 200, persists `0.00` |
T19 | RC-1 — computed totals unchanged by the coercion | byte-identical |
T20 | RC-1 — `OrderGeographyChanged` still dispatches after commit | ordering preserved |
T21 | `wave_engine_configurations.eligible_order_statuses` cannot drift from the enum | validated/defaulted |

---

## 12. Critical finance follow-up audit (scope only)

### TASK-ORDER-PAYMENT-STATE-REMAINING-BALANCE-SOURCE-OF-TRUTH-AUDIT-001

**Independent audit. No implementation. Created because two findings are operationally more
severe than anything in §1–§4 — one reaches the driver's cash-collection figure.**

Evidence already established (read-only):

1. **`orders.payment_status` is a zombie** — NULL on **19/19** live orders; **no writer
   anywhere**; absent from `Order::$fillable`. Yet read as payment truth by 5+ services:
   `PreparationWaveController.php:149`, `WaveMembershipService.php:101-110`,
   `DistributionAggregationService.php:566-567/:681/:845`, `WaveDemandController.php` (7
   sites), `DistributionWindowController.php:215/:241`. Live blast radius:
   `preparation_wave_orders` is **39/39 `is_paid=0, snapshot=NULL`**.
2. **`orders.remaining_balance` is falsified on every structural edit.** `deposit_amount`
   cannot reach `$attributes` (refused `UpdateOrderRequest.php:71`; absent from `SOFT_FIELDS`
   `:39-48` per D6 and from `STRUCTURAL_FIELDS` `:56-59`), so `UpdateOrderAction.php:167`
   **always** evaluates `0.0` and `:172` writes `remaining_balance = grandTotal`, discarding
   recorded payments. The guarded recompute at `:130-146` is dead code for the same reason.
   **That value is served to the driver app at `DriverRuntimeController.php:400`.**
3. Three divergent grand totals: `UpdateOrderAction:168` omits tax; `:144` includes it;
   `OrderResource.php:40` includes it.

**Required scope (audit only):**

| # | Scope item |
|---|---|
1 | `payment_status` source of truth — is the column retired, or given a writer? |
2 | `payment_state` source of truth — confirm `PaymentState` stays derived and unstored |
3 | Deposits — every writer of `deposit_amount`; D6's boundary |
4 | Payment proofs — lifecycle, supersession, active-proof semantics |
5 | Verified amount — is there a verified *amount* distinct from `deposit_amount`? |
6 | Remaining balance — every writer and reader; the correct formula incl. tax |
7 | Driver-facing financial values — full list of what reaches the driver app and from where |
8 | All readers/writers of each field, enumerated |
9 | Reconciliation rules — how a stale/false value is detected and corrected |

**Constraint to carry:** `PaymentState.php:7-14` forbids a second source of truth. Do **not**
propose repairing `payment_status` by starting to write it.

---

## 13. Migration impact

**NONE PROPOSED.** No schema change, no new table, no new column, no index change. RC-1 is
fixed at the writer, **not** by relaxing `orders.discount_amount NOT NULL`.

## 14. API impact

**NONE PROPOSED.** No new endpoint, no removed endpoint, no changed response shape. Possible
*validation-tightening* only, pending the §5 O1/O3 ruling (`payment_method_manual`
nullability) — a 422 where a 200 previously produced a silent bypass.

## 15. Data safety

Nothing was changed while preparing this package: no code, no migration, no DB mutation, no
frontend or backend change, no commit, no deploy. All verification was file reads and
`SELECT`-only queries, re-confirming facts already established in the audit.

Live state unchanged: ORD-00017 `awaiting_payment`/`mobile_wallet`, ORD-00018
`in_progress`/`cod`, ORD-00019 `confirmed`/`cod`; prep members 12; `distribution_window_orders`
13.

**Note for implementation planning:** ORD-00019 is currently `confirmed` having reached that
state through the path this package proposes to change, and ORD-00017 carries an orphaned
`confirmed_at` while `awaiting_payment`. Whether these two rows are corrected, and how, is a
**data-remediation decision** to be made separately from the code change. No remediation is
proposed here.

## 16. Explicit non-goals

1. Do **not** create a third eligibility definition — ADR-042 §7 is canonical.
2. Do **not** remove `AwaitingPayment` from `ConfirmOrderWorkflow`'s allowed sources — it is
   a documented recovery source (matrix F).
3. Do **not** change the RETURN direction (`ReturnToPaymentWorkflow`) — it is what makes the
   gate mandatory rather than advisory.
4. Do **not** change `cod ⇒ none` (D2-B).
5. Do **not** relax `orders.discount_amount NOT NULL`.
6. Do **not** start writing `orders.payment_status`.
7. Do **not** widen or narrow the Preparation predicate — it is correct.
8. Do **not** fix RC-3 with a frontend filter, or the counts with a hardcoded value.
9. Do **not** introduce a new status, a new workflow class, or a second re-evaluation entry
   point.
10. Do **not** let Preparation write `orders.status`, or Orders write Distribution rows.
11. Do **not** change `PaymentFulfillmentGate` from being the ONE gate implementation.
12. Do **not** touch the geography chain — proven unimplicated in RC-1.
13. Do **not** alter the Preparation Wave lifecycle contract (calendar independence, cutoff
    freezes intake only, `ready_for_dispatch` returns to In Progress at close).

---

## 17. Open questions requiring your ruling

| # | Question | Status | Blocks |
|---|---|---|---|
**Q1** | Approve Amendments A/B/C to ADR-042? | **OPEN** | all of RC-2 |
**Q2** | Which mechanism for matrix row B? | ✅ **DECIDED — S2 APPROVED** | — |
**Q3** | Blank-method bypass — O1, O3, or both? | **OPEN** | gate hardening |
**Q4** | Orders with `assigned_warehouse_id IS NULL` — treatment? | ✅ **ANSWERED** by the NULL-warehouse ruling (§8.1). Confirmation sought only on the residual: does the exception surface need its own task? | §8 invariant |
**Q5** | Remediate the two existing rows (ORD-00019 `confirmed`, ORD-00017 orphaned `confirmed_at`)? | **OPEN** | data remediation |
**Q6** | Equalise the collector cadences (Distribution has no scheduler)? | **OPEN** | separate task |

---

# STATUS

**Q2 DECIDED — S2 APPROVED**
**IMPLEMENTATION STILL BLOCKED PENDING Q1 / Q3 / Q4 / Q5 / Q6**

No code, no migration, no DB mutation, no frontend or backend change, no commit, no deploy.
