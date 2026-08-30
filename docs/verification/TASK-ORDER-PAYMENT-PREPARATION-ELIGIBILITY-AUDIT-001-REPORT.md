# TASK-ORDER-PAYMENT-PREPARATION-ELIGIBILITY-AUDIT-001 — Root Cause Audit

**AUDIT ONLY. NOTHING WAS IMPLEMENTED, COMMITTED OR DEPLOYED. NO BUSINESS DATA WAS MUTATED.**

Method: 6 parallel code-tracing lanes, each adversarially reviewed by an independent
agent instructed to *refute* it, then synthesised. **4 of 6 root causes were materially
corrected by the adversarial pass** — those corrections are carried below, not the
original claims. Every load-bearing fact was then re-verified directly.

Paths: `<B>` = `C:/ecos-develop/backend`, `<R>` = `C:/ecos-develop`.

---

## 1. Executive summary

The four reported symptoms are **not one problem**. They are two independent families
plus a trigger:

| # | Symptom | Root cause | Related to? |
|---|---|---|---|
§1 | Edit Order "Server Error" | `discount_amount: null` written to a `NOT NULL` column | **Independent.** Not a geography regression; not payment-related |
§2 | Payment method change auto-confirms | Approved trigger, **but the destination status contradicts ADR-042 §7** | Trigger for §3/§4 |
§3 | `awaiting_payment` order inside a Preparation wave | `preparation_wave_orders` is a write-once snapshot with **no post-attach eligibility authority** | Structural |
§4 | Preparation 12 vs Distribution 11 | Both sides are write-time snapshots; **collector cadences differ** (60s vs never scheduled) | Structural |

**The single most consequential finding: on §2 the reporter is right, and ADR-042 already
says so.** §7 states that `awaiting_payment` orders "enter **`in_progress`** and become
eligible by that route", and §5 rule 3 defines Confirm as "an **explicit operator
action**". The implementation does neither: it runs `ConfirmOrderWorkflow` directly from
`awaiting_payment`, skipping `in_progress`, with no operator intent. But §6 of the same ADR
sanctions landing on `confirmed`. **The ADR contradicts itself, and that contradiction must
be resolved by an owner decision before any code changes.**

§2 is the *trigger* for §3 and §4, not their cause. ORD-00017 had a **~13-second
eligibility window** and two independent structural gaps caught it. Fixing §2 would make
ORD-00017 disappear and leave both structural defects armed.

**Two incidental defects found that are operationally more severe than any of §1–§4** —
see §12.

---

## 2. §1 — Edit Order Server Error

### Root cause

The Edit page's **reads are healthy**. The banner is the **save** path.

```
Frontend  order-form-schema.ts:184
          discount_amount: values.discount_amount ? Number(values.discount_amount) : null
             ↓  (field seeded `undefined` when discount is 0 — manual-order-form.tsx:843)
PUT /api/orders/{id}                       orders-service.ts:46
             ↓
UpdateOrderRequest.php:69   'discount_amount' => ['nullable', 'numeric', 'min:0']   ← accepts null
             ↓
UpdateOrderAction.php:154-158   array_key_exists() copies the null VERBATIM into $attributes
             ↓                  (:161 reads it null-safely for the MATH — never writes back the coerced value)
EloquentOrderRepository.php:384  $order->update($attributes)
             ↓
MySQL: orders.discount_amount = decimal(10,2) NOT NULL DEFAULT '0.00'
             ↓
SQLSTATE[23000] 1048 "Column 'discount_amount' cannot be null"
             ↓
APP_DEBUG=false → Laravel returns {"message":"Server Error"}
             ↓
manual-order-form.tsx:1596-1600 renders it verbatim: AlertTitle "Error" + body "Server Error"
```

The exact logged exception (container `ecos-dev-app`, `storage/logs/laravel-2026-08-23.log:469`):

```
[2026-08-23 02:33:21] production.ERROR: SQLSTATE[23000]: Integrity constraint violation:
1048 Column 'discount_amount' cannot be null
SQL: update `orders` set `payment_method_manual` = cod, `discount_amount` = ?,
     `orders`.`updated_at` = 2026-08-23 02:33:21
     where `id` = 01a02b73-5c9d-71aa-be27-2b3903057bfd     ← ORD-00017
#9  UpdateOrderAction.php(197)
#14 OrderController.php(135) ... 'update' with UpdateOrderRequest
```

### Answers to the §1 questions

| Question | Answer |
|---|---|
1. Which request fails | The **save**, not the page load |
2. Method + endpoint | `PUT /api/orders/{id}` |
3. HTTP status | **500** |
4. Exception | `QueryException` SQLSTATE 23000 / MySQL 1048 |
5. Responsible | `OrderController::update` → `UpdateOrderAction:154-158,197` → `EloquentOrderRepository:384` |
6. Related to City/Governorate? | **No.** The failing SQL contains only `payment_method_manual`, `discount_amount`, `updated_at` |
7. Regression from §3 geography work? | **No** — see below |
8. GET or save? | **Save only.** All reads verified 200 |

**Not a geography regression, on four independent grounds:**

1. All four order-scoped GETs return **200** (verified live over HTTP: `/api/orders/{id}`,
   `/activities`, `/snapshot`, `/payment-proofs`).
2. `serverError` is `useState(null)` and is written **only** from submit handlers —
   `setServerError` call sites are 1376/1382 (edit-save `onError`), 1391, 1422–1487
   (create `onError`), 1591 (Zod `onInvalid`). There is **no mount-time mutation and no
   `useEffect`**, so the banner cannot appear from a page load.
3. The offending frontend line is **byte-identical at HEAD**, and the `nullable` rule is
   untouched in the working diff.
4. The same 1048 is logged on **2026-08-19, 08-20 and 08-22** for other orders — it
   predates the geography work. And `OrderGeographyChanged` is dispatched at
   `UpdateOrderAction.php:321-322`, *after* the transaction closes at `:240`, so it is
   unreachable when `:197` throws.

`awaiting_payment` is **not** locked (`OrderStatus.php:77-80`), which is why ORD-00017
takes the structural branch that writes `discount_amount` at all.

---

## 3. §2 — Payment method change must not auto-confirm

### The observable chain (proven from the audit trail)

`order_events` for ORD-00019, **one second apart**:

```
05:35:21  field_updated     Field 'payment_method_manual' updated.
05:35:22  order_confirmed   Order #ORD-00019 confirmed. Inventory reserved.
05:35:22  confirm_order     Order #ORD-00019 confirmed.
```

ORD-00017 shows it firing in **both** directions:

```
00:49:47  field_updated (payment_method_manual)  →  order_confirmed   (awaiting_payment → confirmed)
00:50:04  field_updated (payment_method_manual)  →  return_to_payment (confirmed → awaiting_payment,
                                                                       reservation released)
```

Live state confirms: ORD-00019 = `confirmed`, `previous_status = awaiting_payment`,
`deposit_amount = 0.00`. **Confirmed with zero payment recorded.**

### Mechanism

`ReevaluateOrderFulfillmentAction` is the single, deliberate entry point. Its own docblock:

> ONE entry point, deliberately. Every trigger calls this same action:
> - payment recorded (RecordOrderPaymentAction)
> - payment proof verified (VerifyPaymentProofAction)
> - **payment method changed (PatchOrderAction, UpdateOrderAction) <- IMPLEMENTATION-002**
>
> `awaiting_payment + gate satisfied   -> ConfirmOrderWorkflow    (advance)`
> `in_progress/confirmed + gate unsatisfied -> ReturnToPaymentWorkflow (return)`

So the **trigger is approved** (owner decision D1-A). COD requires no proof, so the gate
passes and the order advances.

### The actual defect — an unresolved contradiction inside ADR-042

The adversarial review **refuted** the framing "this is a regression". It is not: `git grep
ReevaluateOrderFulfillmentAction HEAD` returns nothing and `PaymentFulfillmentGate.php` is
untracked — trigger, gate and `Confirmed` write all arrived in **one uncommitted change
set**, documented and test-pinned. There is no committed behaviour to have regressed from.

The real defect is that the change set was built against an ADR that contradicts itself.
Verbatim from `<R>/docs/adr/ADR-042-order-fsm-v3-canonical.md`:

**§7 (supports the reporter):**
> `scheduled` and `awaiting_payment` are **not** eligible — they sit outside fulfilment
> execution until their own business triggers make them eligible, **at which point they
> enter `in_progress`** and become eligible by that route.

**§5 rule 3 (also supports the reporter, on a second ground):**
> **Confirm** (`in_progress → confirmed`) is an **explicit operator action** exposed at
> `POST /fulfillment/orders/{order}/confirm`. It must store `confirmed` — not
> `in_progress`.

**§6:279-281 and §3.1:162-163 (support the implementation):**
> When an order reaches Confirm without a reservation (for example, it was created
> `awaiting_payment` and paid), Confirm reserves.

The implementation violates §7 (skips `in_progress` entirely) **and** the "explicit
operator action" half of §5 rule 3 (`ReevaluateOrderFulfillmentAction:104-108` invokes
Confirm with no operator intent), while conforming to §6.

**This is an owner decision, not an engineering fix.** Either §7/§5 or §6 must be amended.
The reporter's expected behaviour is the one §7 already specifies.

### Two unaddressed consequences (both live, both provable)

1. **The structural lock traps unpaid orders.** `OrderStatus::isLocked()` (`:77-79`)
   returns true for `Confirmed`. So a payment-method edit silently makes an **unpaid**
   order structurally uneditable.
2. **`confirmed_at` is orphaned.** `ReturnToPaymentWorkflow:59-63` does not clear it.
   Verified live: **ORD-00017 is `status=awaiting_payment` with
   `confirmed_at='2026-08-22 21:49:47'`.**

---

## 4. §3 — Preparation must never include awaiting_payment

### Root cause: a write-once snapshot with no post-attach eligibility authority

Proven by timestamp — the order was eligible **when admitted** and went stale 5 seconds later:

```
ORD-00017 added to wave PREP-202608-000006 : 2026-08-22 21:50:00   (was `confirmed`)
ORD-00017 status_entered_at (awaiting_payment): 2026-08-22 21:50:05
released_at = NULL    postponed_at = NULL
```

The predicate itself is **correct**. `WaveMembershipService.php:42-58` filters
`whereIn('status', $config->eligible_order_statuses)` = `['in_progress','confirmed']`, plus
a Collecting-only guard at `:33-38`. ORD-00017 passed it legitimately.

**Nothing re-checks after admission:**

- `preparation_wave_orders.released_at` is written by exactly two places, both **wave
  lifecycle** events: `CancelWaveAction.php:54` and `WaveLifecycleService.php:137`. Neither
  is triggered by an order's status changing.
- There is **no listener at all** — `Modules/Commerce/Orders/Application/Listeners/` contains
  only `HandlePreparationWave*`, i.e. Orders listens **to** Preparation, never the reverse.
- Stronger still: `ReturnToPaymentWorkflow::events()` returns `[]`, so the demotion emits
  **no domain event whatsoever**. There is nothing a listener could subscribe to even in
  principle.
- The read paths do not filter either — `PreparationWaveController.php:416` filters neither
  `postponed_at` nor `released_at`, and `ProductDemandCalculator.php:28-34` does not even
  `SELECT o.status`, so the UI **cannot** flag a stale member.

### The asymmetry that proves this is a gap, not a design

Preparation has **two** membership stores with **opposite** behaviour:

| Store | Status-reactive? | Evidence |
|---|---|---|
`preparation_session_orders` | **YES** | `PreparationServiceProvider.php:127` registers `OrderPreparationObserver`, which on `wasChanged('status')` (`:64-78`) consults `PreparationReleaseEngine.php:36-48` and detaches. Six live rows carry `status_ineligible:awaiting_stock` / `status_ineligible:ready_for_dispatch`. |
`preparation_wave_orders` | **NO** | No observer, no listener, no reconcile, no read-time filter. |

The session store already solves this problem. The wave store does not.

### Answers to the §3 questions

1. Pool builder: `WaveMembershipService::attachEligibleOrders()`
2. Predicate: `whereIn('status', eligible_order_statuses)` + not-already-in-an-active-wave
3. Allowed: `['in_progress','confirmed']` — **correct, not too wide**
4. Why ORD-00017 entered: it was `confirmed` at `21:50:00`
5. Stale? **Yes — this is the answer.** Added, then demoted 5s later
6. Snapshot? **Yes**, a row in `preparation_wave_orders`
7. Reconciliation? **None** for waves (sessions have one)
8. Checked once only? **Yes**, at insert
9. Three eligibilities differ — see §5

---

## 5. §4 — Preparation 12 vs Distribution 11

### The reconciliation table (live, read-only)

Active wave `PREP-202608-000006` (`collecting`), 12 active members:

| Order | Status | Prep? | Dist? | Zone | Slot | lcid | Warehouse | Note |
|---|---|---|---|---|---|---|---|---|
ORD-00001 | in_progress | YES | YES | NULL | no | NULL | set | **the 1 Unassigned** (no city) |
ORD-00002 | in_progress | YES | YES | 7 | yes | 2 | set | |
ORD-00006 | in_progress | YES | YES | 7 | yes | 2 | set | |
ORD-00007 | in_progress | YES | YES | 9 | no | 23 | set | re-zoned by the geography task |
ORD-00009 | in_progress | YES | YES | 2 | yes | 1 | set | |
ORD-00010 | in_progress | YES | YES | 8 | no | 7 | set | |
ORD-00011 | in_progress | YES | YES | 1 | yes | 9 | set | |
ORD-00012 | in_progress | YES | YES | 2 | yes | 1 | set | |
ORD-00016 | in_progress | YES | YES | 2 | yes | 1 | set | |
ORD-00018 | in_progress | YES | YES | 2 | yes | 1 | set | |
ORD-00019 | **confirmed** | YES | YES | 2 | yes | 1 | set | confirmed by §2 |
**ORD-00017** | **awaiting_payment** | **YES** | **—** | — | — | **NULL** | set | **the entire discrepancy** |
ORD-00013 | in_progress | — | YES | 3 | no | 40 | **NULL** | excluded by warehouse scope |
ORD-00014 | in_progress | — | YES | 3 | no | 43 | **NULL** | excluded by warehouse scope |

**The arithmetic closes exactly:**

- `distribution_window_orders` = 13 rows − ORD-00013 and ORD-00014 (`assigned_warehouse_id
  IS NULL`, so excluded by the warehouse-scoped read) = **11 Eligible** ✅
- Of those 11, ORD-00001 has `distribution_zone_id IS NULL` = **10 Assigned + 1
  Unassigned** ✅
- Preparation 12 = those same 11 **+ ORD-00017** ✅
- **12 − 11 = ORD-00017, and nothing else.**

ORD-00017 has **no `distribution_window_orders` row at all**: `awaiting_payment` is not in
`eligible_order_statuses`, so `DistributionCollectionService` never collected it — and
`OrderCityBinder` never bound its city either, which is why `logistics_city_id` is NULL
despite `city = 'Nasr City'`.

### Do Preparation and Distribution share one canonical contract?

**They keep parallel copies — and ADR-042 §7 explicitly approves that.** Verbatim:

> Each consumer keeps its own explicit, closed list. An unknown or future status is not
> eligible by default. **Distribution deliberately does not import Preparation's list: the
> two are separate contracts that presently agree.**

**Canonical source of truth: ADR-042 §7's list, expressed in code as
`OrderStatus::fulfilmentEligible()` (`<B>/…/OrderStatus.php:147`) = `['in_progress','confirmed']`.**
No third definition is proposed.

Of five declarations in play, four are legitimate and **one is not**:

| Declaration | Verdict |
|---|---|
`OrderStatus::fulfilmentEligible()` (`OrderStatus.php:147`) | **Canonical** |
`config('distribution.eligible_order_statuses')` (`config/distribution.php:57`) | Legitimate — derived from the enum at boot |
`config('distribution.loading_eligible_order_statuses')` (`:93`) | Legitimate — approved LP-1.0 second predicate |
`PreparationSessionPolicy::defaultEligibleStatuses()` (`:79`) | Legitimate — derived from the enum |
**`wave_engine_configurations.eligible_order_statuses`** (mutable DB JSON, cast `'array'`, **no enum fallback** — `WaveEngineConfiguration.php:46,60`, read raw at `WaveMembershipService.php:43`) | **Illegitimate copy** — hand-editable, not derived from ADR-042 |

Live values agree today (`["in_progress","confirmed"]`), so this is **latent drift**, not
the cause of ORD-00017.

### The real divergence is cadence, not the list

- **Preparation wave collector: every 60 seconds** — `routes/console.php:58-61`,
  `Schedule::command('wave:run-scheduler')->everyMinute()`.
- **Distribution collector: not scheduled at all** — `grep -rn "Schedule::"
  Modules/Logistics/` returns nothing. It runs only when an operator presses Refresh.

ORD-00017's eligibility window was **13 seconds** (`21:49:47` → `21:50:05`). The
every-minute Preparation sweep landed inside it at `21:50:00`; the operator-triggered
Distribution sweep never did. **Any** order with a window shorter than the gap between the
two collectors produces this mismatch — a cancel-then-reinstate, a warehouse reassignment,
a stock block.

There are in fact **four** numbers, not two: the wave store (12), the denormalised counter
`preparation_waves.orders_count` (12), the **active session store (1 — only ORD-00016)**,
and Distribution (11).

---

## 6. §5 / §7 — Payment method vs payment state

**The audit's own framing was corrected here.** The clean dichotomy "method = descriptive,
state = authoritative" is **wrong**. `PaymentFulfillmentGate::permits()` (`:52-69`) is a
**four-term conjunction**, and the first two terms are the *method* fields:

| # | Term | Field | Site |
|---|---|---|---|
1 | `methodOf($order) !== ''` | `payment_method_manual ?? payment_method` | `:57-59` |
2 | `requirementFor(method, channel, company) === 'required'` | method + brand policy | `:61` |
3 | `deposit_amount >= total` | money | `:179-182` |
4 | an ACTIVE VERIFIED `payment_proofs` row | proof | `:208-216` |

So **payment method is authoritative for *whether the financial control runs at all*.**
It is not merely descriptive. Terms 3–4 decide *satisfaction*; terms 1–2 decide
*applicability*.

| Field | Meaning | Written by | Authoritative for |
|---|---|---|---|
`payment_method` | channel-supplied method (Woo) | import | display; 2nd choice in `methodOf()` |
`payment_method_manual` | operator-entered method | Orders create/update | **gate term 1–2** |
`payment_status` | *nothing* | **NO WRITER** | **nothing — see §12** |
`deposit_amount` | money received | `RecordOrderPaymentAction` only (D6) | **gate term 3** |
`payment_proofs` | uploaded/verified proof | proof actions | **gate term 4** |
PaymentState (computed) | `deposit >= total` ⇒ paid | derived, never stored | display/rollups |

Two **reachable bypasses** via `PUT /api/orders/{order}`:
**(a)** blanking `payment_method_manual` (accepted `nullable` at `UpdateOrderRequest.php:66`,
persisted via `SOFT_FIELDS` at `:37`) makes `permits()` return `true` **unconditionally**
at `:57-59`; **(b)** a method whose brand-policy requirement is not `required` short-circuits
at `:61`.

### §5 scenario expectations vs current behaviour

| Scenario | Expected | Actual | Verdict |
|---|---|---|---|
`in_progress` + instapay → COD | stays `in_progress` | stays `in_progress` (gate satisfied, but source status is not `awaiting_payment`, so no advance) | ✅ correct today |
`awaiting_payment` + instapay → COD | stays `awaiting_payment`, NOT prep-eligible | becomes **`confirmed`**, prep-eligible | ❌ **the §2 defect** |

---

## 7. §6 — Canonical Order lifecycle

**Authoritative contract: `<R>/docs/adr/ADR-042-order-fsm-v3-canonical.md`** — "Status:
Approved" (:3), v1.0 2026-08-13 (:4-5), "Discharges: ADR-005 §5" (:8), amended 2026-08-21
(:115) and 2026-08-23 (:194). ADR-005 explicitly deferred the FSM
(`ADR-005-Order-Ownership-and-Lifecycle.md:167`).

**Caveat:** `docs/architecture/ADR-015-enterprise-fulfillment-architecture.md` is also
marked "APPROVED — CRITICAL" and at `:641` still names `OrderStatus::NeedsShippingReview`,
a status that no longer exists. It is **stale** and must not be treated as authoritative
for the FSM.

Key transitions (23 workflow classes; `PatchOrderAction::resolveWorkflow()` maps (from,to)):

| From → To | Workflow | Requires |
|---|---|---|
`awaiting_payment` → `in_progress` | *per §7, the payment trigger* | **not implemented this way — see §2** |
`awaiting_payment` → `confirmed` | `ConfirmOrderWorkflow` via `ReevaluateOrderFulfillmentAction` | payment gate satisfied — **contradicts §7** |
`in_progress` → `confirmed` | `ConfirmOrderWorkflow` | §5 rule 3: "an **explicit operator action**" |
`confirmed` → `in_progress` | Unlock (`ReturnToPendingWorkflow`) | releases lock + inventory |
`in_progress`/`confirmed` → `awaiting_payment` | `ReturnToPaymentWorkflow` | gate unsatisfied |
`ready_for_dispatch` → `in_progress` | `ReturnToProcessingWorkflow` | **fires automatically** from `HandlePreparationWaveCancelled:29` / `HandlePreparationWaveClosed:57` |

**Answering the §6 questions directly:** Confirmation *should* be a user action (§5 rule 3
says so). Payment **verification** and payment **recording** are approved re-evaluation
triggers (§3.1). Payment **method update** is also an approved trigger (D1-A) — but no
approved text names `confirmed` as the destination for the advance, and §7 names
`in_progress`.

---

## 8. Root cause per problem

| # | Root cause | Confidence |
|---|---|---|
**RC-1 (§1)** | `UpdateOrderAction:154-158` copies a null `discount_amount` into a `NOT NULL` column; validation says `nullable`, schema says `NOT NULL`. Contract/schema disagreement. | **Certain** — logged exception + full chain, survived refutation |
**RC-2 (§2)** | Approved trigger with a destination that contradicts ADR-042 §7 and the "explicit operator action" half of §5 rule 3, while conforming to §6. **ADR self-contradiction.** | **Certain** — verbatim ADR quotes |
**RC-3 (§3)** | `preparation_wave_orders` is a write-once snapshot; `released_at` is written only by wave cancel/close; the demotion emits no event; no read-time filter. | **Certain** — survived refutation |
**RC-4 (§4)** | Both sides snapshot at write time with different collector cadences (60s vs unscheduled). Not a predicate mismatch. | **Certain** — arithmetic closes exactly |
**RC-5** | `payment_status` zombie + `remaining_balance` falsified — see §12 | **Certain** — verified live |

---

## 9. Existing contracts involved

- **ADR-042 §7** — fulfilment eligibility `['in_progress','confirmed']`; per-consumer closed
  lists explicitly approved.
- **ADR-042 §5 rule 3** — Confirm is an explicit operator action storing `confirmed`.
- **ADR-042 §3.1 / D1-A** — payment-method change is an approved re-evaluation trigger.
- **D2-B** — `cod` ⇒ proof requirement `none`.
- **D6** — `deposit_amount` is not accepted on the update path.
- **LP-1.0** — the approved two-predicate split in Distribution.
- `PaymentFulfillmentGate` — the ONE gate implementation.
- `ReevaluateOrderFulfillmentAction` — the ONE re-evaluation entry point.
- `PaymentState` — derived, never stored ("no second source of truth").

---

## 10. Proposed fix boundaries

### RC-1 (§1) — safe to fix now, independent

**Change:** coerce `discount_amount` to `0` server-side before it reaches `$attributes` —
`UpdateOrderRequest.php:69` and/or `UpdateOrderAction.php:154-158`. The math at `:161`
already coalesces; only the *write* is unguarded. Optionally also
`order-form-schema.ts:184`, but **not only** there.

**Must NOT change:** the `NOT NULL DEFAULT '0.00'` constraint; the method-value narrowing at
`UpdateOrderRequest.php:66`; the D6 exclusion of `deposit_amount`; the geography chain
(proven unimplicated).

### RC-2 (§2) — 🛑 BLOCKED ON AN OWNER DECISION

**No code may change until the ADR contradiction is resolved.** Either §7/§5 or §6 must be
amended. Two candidate readings:
- **(i)** Honour §7 — the payment trigger advances `awaiting_payment → in_progress`; Confirm
  stays an operator action. *This is what the reporter expects.*
- **(ii)** Honour §6 — amend §7 to name `confirmed` as the automatic destination.

Two fixes are safe **either way**: clear `confirmed_at` in `ReturnToPaymentWorkflow:59-63`,
and close the two `PaymentFulfillmentGate` outer-term bypasses (`:57-59`, `:61`).

**Must NOT change:** `PaymentFulfillmentGate` as the one implementation;
`ReevaluateOrderFulfillmentAction` as the one entry point (including its
`lockForUpdate` semantics); the RETURN direction; `cod = none` (D2-B).

### RC-3 (§3) — the fix is reconciliation, not the predicate

**Change:** give `preparation_wave_orders` the eligibility authority the *session* store
already has. The precedent to copy is `OrderPreparationObserver` +
`PreparationReleaseEngine`. **Do not widen or narrow the predicate** — it is correct.
Blocked-adjacent: `ReturnToPaymentWorkflow::events()` returning `[]` means an event-based
approach needs an event to exist first.

**Must NOT change:** `['in_progress','confirmed']`; the Collecting-only admission guard;
the `released_at IS NULL` active-membership semantics.

### RC-4 (§4) — cadence and the one illegitimate copy

**Change:** make `WaveEngineConfiguration.eligible_order_statuses` validate against or
default to `OrderStatus::fulfilmentEligible()`. Address the cadence asymmetry (Distribution
has no scheduled collector).

**Must NOT change:** `config('distribution.eligible_order_statuses')` (it gates two write
paths); the approved LP-1.0 two-predicate split; ADR-042 §7's per-consumer lists. **Do not
create a third eligibility definition.**

### RC-5 — see §12

---

## 11. Required tests (to write before implementing)

| § | Test | Expected |
|---|---|---|
9A | `in_progress` + instapay → COD | stays `in_progress` |
9B | `awaiting_payment` + instapay → COD | **per the owner decision** — blocked |
9C | `awaiting_payment` cannot enter a wave | admission refused |
9D | `in_progress` enters a wave | admitted |
9E | `confirmed` enters a wave | admitted (approved) |
9F | Prep and Distribution resolve the same status list | both `['in_progress','confirmed']` |
9G | Payment-method change never silently confirms | per owner decision |
9H | The explicit confirm workflow still works | unchanged |
9I | **Status change after wave creation reconciles membership** | ineligible member removed/flagged — the RC-3 test |
— | `discount_amount: null` on `PUT /orders/{id}` | **200**, persists `0.00` (RC-1) |
— | `ReturnToPaymentWorkflow` clears `confirmed_at` | null after demotion |
— | Blank `payment_method_manual` does not open the gate | gate refuses |
— | `remaining_balance` survives a structural edit | recorded deposit preserved |

---

## 12. Incidental findings — more severe than §1–§4

### (a) `orders.payment_status` is a zombie column with 5+ readers

**Verified live: NULL on 19 of 19 orders.** No writer anywhere (only the migration, a
query-string param at `OrderController.php:65`, and a repository filter at
`EloquentOrderRepository.php:165` that actually reads `deposit_amount`). **Not in
`Order::$fillable`** (grep returns nothing).

Yet it is read as payment truth by: `PreparationWaveController.php:149`,
`WaveMembershipService.php:101-110`, `DistributionAggregationService.php:566-567/:681/:845`,
`WaveDemandController.php` (7 sites), `DistributionWindowController.php:215/:241`.

**Live blast radius: `preparation_wave_orders` is 39/39 `is_paid=0, snapshot=NULL`.**

**Do NOT fix by starting to write it** — `PaymentState.php:7-14` forbids a second source of
truth. Migrate the readers onto the derived rule.

### (b) `orders.remaining_balance` is falsified — and it is the driver's cash figure

`deposit_amount` can never reach `$attributes` on the update path: refused at
`UpdateOrderRequest.php:71`, absent from `SOFT_FIELDS` (`:39-48`, D6) and from
`STRUCTURAL_FIELDS` (`:56-59`). Therefore `UpdateOrderAction.php:167`
(`$depositAmount = (float) ($attributes['deposit_amount'] ?? 0)`) **always evaluates to
0.0**, and `:172` writes `remaining_balance = grandTotal` on **every structural edit** —
discarding recorded payments. The guarded recompute at `:130-146` is dead code for the same
reason.

**That value is served to the driver app at `DriverRuntimeController.php:400`** — i.e. a
driver could be told to collect money a customer already paid.

Secondary: three different grand totals — `:168` omits tax, `:144` includes it,
`OrderResource.php:40` includes it.

---

## 13. Data safety verification

**No business data was mutated.** All investigation was `SELECT`-only plus file reads.
Verified after the audit:

```
ORD-00017 status=awaiting_payment method=mobile_wallet updated_at=2026-08-22 21:50:05
ORD-00018 status=in_progress       method=cod          updated_at=2026-08-23 02:34:00
ORD-00019 status=confirmed         method=cod          updated_at=2026-08-23 02:35:21
prep members=12   distribution_window_orders=13
```

All three `updated_at` values predate this audit. No status, payment method, payment state,
preparation membership or distribution assignment was changed. Sub-agents were instructed
`SELECT only — never UPDATE/INSERT/DELETE`.

Two Sanctum tokens were minted server-side for read-only HTTP probing and **both revoked**
(`claude-%` remaining: 0). Temporary scratch scripts removed. No password handled.

---

## 14. Files and services involved

**§1:** `order-form-schema.ts:184` · `manual-order-form.tsx:843,1596-1600` ·
`orders-service.ts:46` · `UpdateOrderRequest.php:69` · `OrderController.php:129-135` ·
`UpdateOrderAction.php:56-59,154-158,161,197` · `EloquentOrderRepository.php:382-384` ·
`OrderStatus.php:77-80`

**§2:** `PatchOrderAction.php` (payment hook) · `UpdateOrderAction.php` ·
`ReevaluateOrderFulfillmentAction.php:104-113` · `PaymentFulfillmentGate.php:52-69,97,179-182,208-216` ·
`ConfirmOrderWorkflow.php` · `ReturnToPaymentWorkflow.php:59-63,76-80` · `ADR-042 §3.1/§5/§6/§7`

**§3:** `WaveMembershipService.php:33-58,101-110,112` · `WaveLifecycleService.php:137` ·
`CancelWaveAction.php:54` · `OrderPreparationObserver.php:31-79` ·
`PreparationReleaseEngine.php:36-48` · `PreparationServiceProvider.php:127` ·
`PreparationWaveController.php:416` · `ProductDemandCalculator.php:28-34`

**§4:** `OrderStatus.php:147` · `config/distribution.php:57,93` ·
`PreparationSessionPolicy.php:79` · `WaveEngineConfiguration.php:46,60` ·
`DistributionCollectionService.php:354` · `OrderCityBinder.php:52` ·
`DistributionAggregationService.php:90,295,422,523` · `routes/console.php:58-61`

**§12:** `EloquentOrderRepository.php:164-178` · `DriverRuntimeController.php:400` ·
`PaymentState.php:7-14` · `WaveDemandController.php` · `DistributionWindowController.php:215,241`

---

## 15. Explicit DO-NOT-CHANGE list

1. `orders.discount_amount` `NOT NULL DEFAULT '0.00'` — fix the writer, not the schema.
2. `PaymentFulfillmentGate` as the ONE gate implementation.
3. `ReevaluateOrderFulfillmentAction` as the ONE re-evaluation entry point, including its
   lock-and-re-read semantics.
4. The RETURN direction (`ReturnToPaymentWorkflow`) — it is the control that makes the gate
   mandatory rather than advisory.
5. `cod ⇒ none` (D2-B).
6. D6: `deposit_amount` is not accepted on the update path.
7. ADR-042 §7's per-consumer closed lists — the parallelism is **approved**. Do not
   create a third eligibility definition.
8. `['in_progress','confirmed']` — the §3 predicate is correct; the gap is reconciliation.
9. `config('distribution.eligible_order_statuses')` — it gates two write paths.
10. The approved LP-1.0 two-predicate split in Distribution.
11. `orders.payment_status` — do **not** start writing it; migrate its readers instead.
12. The geography chain (`OrderGeographyChanged`, `announceGeographyChange`,
    `OrderCityBinder::rebindOrder`, `SyncOrderGeographyListener`) — proven unimplicated in §1.
13. `OrderStatus` enum values; no new status.
14. Do not fix any symptom with a frontend filter, a new `if`, or a hardcoded count.

---

## 16. Remaining uncertainty

1. **Owner intent on the §2 destination** — blocks RC-2 entirely.
2. Which *surface* produced the literal "12" and "11" was not pinned; four numbers exist
   (12 wave, 12 counter, **1 session**, 11 distribution).
3. `DistributionAggregationService::orders()` (`:493`) takes no `warehouseId` parameter — it
   relies on a frontend-supplied filter. Whether "11" is a server guarantee or a UI
   convention is unverified.
4. `payment_method_effective` precedence is inverted between
   `DistributionAggregationService.php:709` (prefers `payment_method`) and
   `PaymentFulfillmentGate::methodOf()` (`:97`, prefers `payment_method_manual`).
5. `UploadPaymentProofAction` calls the re-evaluation, but "proof uploaded" is **not** in
   the ADR-042 §3.1 trigger table — possibly an unapproved sixth trigger.
6. ORD-00017's creation trail shows `entry_status_overridden_by_payment_proof_policy`,
   `initiate_order` **and** `reservation_reserved` in the same second — inventory reserved
   for an order parked at `awaiting_payment`. Untraced.
7. A **+3h clock offset** between `order_events.created_at` and `orders` /
   `preparation_wave_orders` timestamps. It does not affect any conclusion here (the
   `added_at` vs `status_entered_at` comparison is same-clock) but will corrupt any future
   forensic timeline mixing the two tables.

---

# STATUS

**AUDIT COMPLETE — IMPLEMENTATION NOT STARTED**

No commit. No deploy. No migration. No schema change. No business data mutation.

**RC-2 (§2) is blocked on an owner decision** — the ADR-042 §5/§7 vs §6 contradiction must
be resolved before any code change. RC-1, RC-3, RC-4 and RC-5 have defined fix boundaries
and are ready to scope as implementation tasks on your instruction.
