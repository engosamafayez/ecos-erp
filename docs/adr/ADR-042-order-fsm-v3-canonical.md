# ADR-042: Order FSM — V3 Canonical

**Status:** Approved
**Version:** v1.0
**Date:** 2026-08-13
**Author:** Engineering Architecture Review
**Inputs:** TASK-ORDER-INVENTORY-FULFILLMENT-CONTRACT-RECOVERY-001, TASK-ORDER-LIFECYCLE-V3-CANONICAL-REPAIR-001 (superseded), TASK-ORDER-LIFECYCLE-V3-SUPERSESSION-001, CTO business decisions B1–B4
**Discharges:** ADR-005 §5 — *"the internal order status FSM … is defined in a dedicated future ADR"* (never written until now)
**Supersedes:** the status vocabulary installed by migration `2026_07_22_100000_simplify_order_lifecycle_v3`
**Related:** ADR-005 (Order Ownership and Lifecycle), ADR-023 (Order Snapshot Policy), ADR-027 (Reservation Ownership Policy)
**Amended 2026-08-23** (owner decision, TASK-ORDER-PAYMENT-PREPARATION-CONTRACT-DECISION-001) — three amendments resolving an internal contradiction over where a payment-fact trigger lands: **§3.1** (Amendment C — the trigger's two directions stated separately), **§6** (Amendment B — the "Confirm reserves" clause scoped to reservation behaviour, not authorisation), **§7.1** (Amendment A — the trigger advances to `in_progress`, never automatically to `confirmed`). No new status, no new lifecycle, no replacement FSM.

---

## 1. Context

ADR-005 §5 defined the order status model conceptually and explicitly deferred the finite
state machine to a dedicated ADR:

> The internal order status FSM — including the specific states, transitions, and guard
> conditions — is defined in a dedicated future ADR. The examples above are conceptual only
> and do not constitute the final state machine.

That ADR was never written. In its absence the lifecycle was changed by migration and by
code, without an authoritative contract to check against. The result was drift that a
contract-recovery pass measured directly:

- migration `2026_07_22_100000_simplify_order_lifecycle_v3` renamed `pending → new` and
  merged `confirmed → in_progress`, deleting the confirmation step as a *state*;
- configuration was **not** migrated with the data — three separate configuration sources
  were left holding pre-V3 vocabulary (`wave_engine_configurations`, `config_brand_policies`,
  and a hardcoded FormRequest whitelist), each of which caused a production defect;
- `orders.status` retained a column default of `'pending'`, a value no enum case accepts;
- runtime code compensated with `LEGACY_STATUS_MAP`, silently repairing invalid configuration
  on read and thereby hiding the drift instead of surfacing it.

This ADR is the missing authority. It defines the canonical states, the entry contract, the
confirmation step, and the boundary between lifecycle status and reservation.

## 2. Decision — Canonical States

The canonical `OrderStatus` vocabulary is **eleven** cases:

| Value | Meaning | Locked | Fulfilment-eligible |
|---|---|---|---|
| `in_progress` | Order exists and is being worked. **Entry state for normal orders.** | No | **Yes** |
| `confirmed` | Operator has explicitly confirmed the order. | Yes | **Yes** |
| `scheduled` | Future-dated; waits for its scheduling trigger. | No | No |
| `awaiting_payment` | Waits for the payment-success condition. | No | No |
| `awaiting_stock` | Reservation could not be satisfied. | Yes | No |
| `ready_for_dispatch` | Preparation complete. | Yes | No (already downstream) |
| `out_for_delivery` | Dispatched. | Yes | No |
| `delivered` | Terminal — fulfilled. | Yes | No |
| `on_hold` | Manual intervention. | No | No |
| `cancelled` | Terminal — explicitly ended. | No | No |
| `returned` | Terminal — return processed. | Yes | No |

**`new` is not canonical.** It is removed from the runtime enum. It exists only as a
historical value in migration files and in audit rows written before this ADR.

**`pending`, `processing`, `preparing`, `completed`, `review`, `rescheduled` are not
canonical.** They are pre-V3 vocabulary. They are accepted **nowhere** at runtime.

### 2.1 Why `confirmed` returns

The V3 migration merged `confirmed` into `in_progress` on the reasoning that confirmation is
carried by the `orders.confirmed_at` timestamp rather than by a state. Operationally that
proved wrong: the business needs to distinguish *an order that has been entered* from *an
order that has been committed*, and a timestamp cannot gate a transition, appear in a status
filter, or drive an eligibility list. `confirmed` is restored as a first-class state.

### 2.2 Why `in_progress` is the entry state and is **not** locked

This is the load-bearing consequence of removing `new`, and it is stated explicitly because
it is the one place where this ADR changes the meaning of an existing state rather than
merely renaming one.

Under V3, `new` was the entry state and was **unlocked** — product, price and shipping data
were editable — while `in_progress` meant "engines are running" and was **locked**.

With `new` removed, normal orders are created directly at `in_progress`. If `in_progress`
kept its locked semantics, **every manually created order would be structurally uneditable
from the instant of creation**, which contradicts the certified order-edit contract. The
entry role and its unlocked semantics therefore transfer from `new` to `in_progress`, and the
structural lock begins at `confirmed`:

```
isLocked() == false  for  in_progress, scheduled, awaiting_payment
isLocked() == true   for  everything else, i.e. from confirmed onward
```

The unlocked set is exactly the V3 set with `new` replaced by `in_progress`. `on_hold` and
`cancelled` remain locked, as they were — this ADR does not revisit them.

Read plainly: **Confirm is what commits an order.** Before Confirm it can be edited; after
Confirm it cannot. That is what makes Confirm a real business action rather than a label.

## 3. Decision — Entry Status Contract (Pick-and-Stay)

An order may be created in exactly three states:

| Intent | Entry status |
|---|---|
| Normal | `in_progress` |
| Future-dated delivery | `scheduled` |
| Payment required first | `awaiting_payment` |

`confirmed` is **never** offered as an entry status — it is reachable only by the Confirm
action. `new` is not offered because it does not exist.

**Pick-and-stay is binding.** When an operator explicitly submits an entry status, that
status is what gets stored. The creation path may not substitute a different one.

### 3.1 The one sanctioned exception, and why it is not an override

> **AMENDED 2026-08-21 — Owner decision D1-A (MANDATORY FINANCIAL CONTROL).**
> Recorded by `TASK-ORDERS-PAYMENT-CONFIRMATION-FULFILLMENT-OWNER-DECISION-003`; implemented by
> `TASK-ORDERS-PAYMENT-CONFIRMATION-FULFILLMENT-IMPLEMENTATION-002`.
> The original text below defined the exception in terms of *"no proof was supplied"* without
> defining what *supplied* meant. When this ADR was approved (2026-08-13) the only proof that
> existed was the `orders.payment_proof_path` string column. `payment_proofs` was created on
> 2026-08-19 and the string column was declared **superseded** on 2026-08-20, so the original
> acceptance criterion no longer names a proof source this system recognises. §3.1 is restated
> below against the canonical source. **No other section of this ADR is changed.**

**The rule — `payment_proof_policy: required` is a mandatory financial control.**

An order whose chosen payment method resolves to `required` **may not hold a
fulfilment-eligible status** (§7 — `in_progress`, `confirmed`) unless **both** of the following
are true:

1. **payment is sufficient** — `deposit_amount >= total`; **and**
2. **an active, verified proof exists** — a `payment_proofs` row for the order with
   `superseded_at IS NULL` and `state = 'verified'`.

Until both hold, the order's status is `awaiting_payment`.

**Creation-time behaviour.** A canonical proof is a `payment_proofs` row, and that row is
created only by `POST /orders/{order}/payment-proofs`, which requires an order that already
exists. **Condition 2 is therefore unsatisfiable at creation time.** It follows, with no
further judgement required, that:

> **A proof-required payment method is always created `awaiting_payment`, regardless of the
> submitted entry status, regardless of the amount paid, and regardless of any proof reference
> supplied in the request.**

This is the same blocking business condition the original §3.1 described; only the acceptance
criterion has been made explicit.

**Behaviour when payment is insufficient.** Condition 1 fails ⇒ the order stays
`awaiting_payment`. Recording a payment re-evaluates the gate; a payment that does not complete
the balance leaves the order parked, and the payment itself remains committed.

**Behaviour when proof is absent, unverified, or rejected.** Condition 2 fails ⇒ the order
stays `awaiting_payment`. `uploaded` is evidence *submitted*, not evidence *accepted*;
`rejected` never satisfies the gate; a `superseded_at IS NOT NULL` proof is history and never
satisfies the gate. Verifying a proof re-evaluates the gate.

**Relationship to the canonical `payment_proofs` lifecycle.** Creation may still accept a proof
*reference* (`orders.payment_proof_path`) as a display and audit field, and an
`proof_uploaded` order event is still written for it. **It carries no lifecycle authority.** A
non-empty value does not make a proof verified, does not substitute for a `payment_proofs`
row, and may not move an order out of `awaiting_payment`. The order's route into fulfilment is
the canonical lifecycle only: upload → verify (Finance) → re-evaluation.

> **Amendment (2026-08-23) — the two directions of the payment trigger are separate.**
> *Owner decision. Amendment C of TASK-ORDER-PAYMENT-PREPARATION-CONTRACT-DECISION-001.*
>
> Payment-fact triggers run through ONE entry point,
> `ReevaluateOrderFulfillmentAction`. That entry point has exactly two directions, and they
> are now stated separately because they were being conflated:
>
> | Direction | Precondition | Result |
> |---|---|---|
> **Advance** | gate **satisfied** and status is `awaiting_payment` | → `in_progress` (§7.1) |
> **Return** | gate **unsatisfied** and status is fulfilment-eligible | → `awaiting_payment` (unchanged) |
>
> **Neither direction confirms an order.** Confirmation is reachable only through the
> explicit operator action of §5 rule 3.
>
> The approved triggers are unchanged: payment recorded, payment proof verified, payment
> method changed. A method change is a trigger for **re-evaluation only** — never for
> confirmation.
>
> No new status, no new lifecycle and no replacement FSM is introduced by this amendment.

**The exception remains an exception, and remains audited.** As before, this is permitted
because:

- it is a **blocking business condition**, not a preference;
- `awaiting_payment` is the existing payment-state mechanism, which is precisely the
  representation the contract calls for; and
- it is **never silent** — an `entry_status_overridden_by_payment_proof_policy` order event
  is written recording the submitted status, the stored status, and the reason.

**Precedence.** This is the *one* sanctioned entry-status exception. Where any other creation
mechanism would otherwise store a different status — including the shipping-review
`status_override` — the payment block takes precedence, and the audited stored status is the
one actually written.

**Neither input to the control is fixed at creation.** The resolved requirement is a function of
the order's *current* payment method and of the *brand policy* that method is resolved against —
which is selected by the order's channel. Either can move for the life of the order, and either
movement re-opens this question. The evaluation is therefore re-run whenever a fact the control
depends on changes, through the single canonical re-evaluation entry point:

| Trigger | Which fact moved |
|---|---|
| payment recorded | condition 1 — `deposit_amount` |
| proof verified | condition 2 — a proof became `verified` |
| proof rejected | condition 2 — a proof can no longer become `verified` |
| **proof superseded** | condition 2 — the active verified proof ceased to be active |
| payment method changed | which requirement applies |
| **channel changed** | which brand policy resolves that requirement |

> **AMENDED 2026-08-23 — `TASK-ORDERS-PAYMENT-CONFIRMATION-FULFILLMENT-FINAL-COMPLETION-001`.**
> The two bolded rows were added. They are not new conditions and they do not change what D1-A
> requires — the two conditions above are untouched. They close the gap between the rule as
> stated and the moments at which it was actually being asked.
>
> *Superseded* was already load-bearing in condition 2 (`superseded_at IS NULL`) but was absent
> from this list, so replacing an order's proof falsified the condition while nothing re-read it:
> an order already in `in_progress`/`confirmed` stayed fulfilment-eligible with no active verified
> evidence. That was reachable by an actor holding `proof_upload` alone, which made a control
> Finance had to clear undoable without Finance.
>
> *Channel* was absent for the same reason: §3.1 was written when the requirement was read from
> the payment method alone, before D2-B made the resolution chain (channel → company → default)
> explicit. Once the brand policy behind the channel decides the answer, moving an order between
> channels is a move of the same control.

An order that ceases to satisfy the control returns to `awaiting_payment` via the existing
`return_to_payment` workflow; no new state and no direct status write is introduced.

Any override that is not audited is prohibited. Any override that is merely a *preference* is
prohibited outright — see §4.

### 3.2 Fallbacks when no status is submitted

When the caller submits no status, the creation path resolves one in this order:

1. the §3.1 payment control is unsatisfied → `awaiting_payment` (as amended: a proof-required
   method is always created `awaiting_payment`)
2. requested delivery date is in the future → `scheduled`
3. first enabled status in the brand's `source_entry_policies.manual` list
4. `in_progress`

These are fallbacks. **None of them may displace an explicitly submitted status** (except
rule 1, per §3.1).

## 4. Decision — Payment Method May Not Determine Lifecycle Status

The `PAYMENT_CLEAR_STATUS_PREFERENCE` mechanism — which, when a payment method was present,
preferred `in_progress`/`new` over the operator's selection — is **removed and prohibited**.

Payment method is an attribute of how an order is paid. It is not an input to the lifecycle
state machine. Payment progress is represented by payment fields and by the
`awaiting_payment` state, never by silently rewriting a chosen lifecycle status.

## 5. Decision — Transitions

```
                    ┌──────────────────┐
   create normal ─► │   in_progress    │ ──Confirm──► confirmed ──► ready_for_dispatch
                    └──────────────────┘   ◄─unlock──                      │
                          ▲     ▲                                          ▼
   create scheduled ──────┘     │                                  out_for_delivery
        (scheduling trigger)    │                                          │
                                │                                          ▼
   create awaiting_payment ─────┘                                      delivered
        (payment-success trigger)                                          │
                                                                           ▼
                                                                       returned
```

Governing rules:

1. **`scheduled` stays `scheduled`** until its existing scheduling trigger fires. Creation
   never converts it to `in_progress`.
2. **`awaiting_payment` stays `awaiting_payment`** until its existing payment-success
   condition is met. Creation never converts it to `in_progress`.
3. **Confirm** (`in_progress → confirmed`) is an explicit operator action exposed at
   `POST /fulfillment/orders/{order}/confirm`. It must store `confirmed` — not
   `in_progress`, and not any pre-V3 value.
4. **Unlock** (`confirmed → in_progress`) releases the structural lock and any held
   inventory. This is the successor to the V3 `return_to_new` action.
5. `awaiting_stock` is written **only** by the reservation contract (§6), never by an entry
   path.

## 6. Decision — Reservation Boundary (unchanged by this ADR)

**Confirm is not the reservation trigger, and this ADR does not move it.**

Reservation is governed by ADR-027 and is triggered where it already was: when an order
enters the operational queue at creation, via `ProcessOrderWorkflow` (`initiate_order`). The
V3 trigger condition was "status is `new`"; because normal orders are now created at
`in_progress`, the equivalent condition is "status is `in_progress`". The *timing* of
reservation relative to the order's life is therefore unchanged — only the name of the state
that triggers it.

The Confirm action performs no reservation when inventory is already held. When an order
reaches Confirm without a reservation (for example, it was created `awaiting_payment` and
paid), Confirm reserves — the same idempotent behaviour the workflow already implemented.

> **Amendment (2026-08-23) — scope of the clause above.** *Owner decision. Amendment B of
> TASK-ORDER-PAYMENT-PREPARATION-CONTRACT-DECISION-001.*
>
> The preceding paragraph governs **what Confirm must do once it has been invoked** from an
> unreserved state. It is a statement about **reservation behaviour**, and it is **not** an
> authorisation for automatic or non-operator invocation of Confirm. It must not be read as
> permitting a payment-fact trigger to advance an order to `confirmed` — §7.1 prohibits that
> explicitly.
>
> The example it cites ("created `awaiting_payment` and paid") describes an order that
> reaches Confirm **through the explicit operator action of §5 rule 3**, after a payment fact
> made it eligible.
>
> **`awaiting_payment` remains a legal Confirm source, and is not removed or further
> restricted.** `ConfirmOrderWorkflow`'s allowed-source list keeps it as a documented
> recovery source, gated by `PaymentFulfillmentGate`. What changes is only that no
> *automatic* path may use it.

Restoring `confirmed` deliberately does **not** redefine reservation architecture. Any future
proposal to move the reservation trigger to Confirm requires its own ADR and must be argued
against ADR-027, not inherited from this one.

### 6.1 A missing warehouse does not block Confirm

ADR-027 §10 establishes that a coverage gap is a Command Center signal, not a status
change — a rule about what the **engine** may do. It does not restrict what an **operator**
may do.

Therefore:

| Situation | `initiate_order` (engine) | `confirm_order` (operator) |
|---|---|---|
| No warehouse assigned | postpone reservation, change no status | postpone reservation, **still write `confirmed`** |
| Finished-good shortage | write `awaiting_stock` (§3 Case 4) | write `awaiting_stock` |

The asymmetry is intentional. `initiate_order` exists to execute a reservation and its
status write is incidental, so skipping it costs nothing. `confirm_order` exists *to record
the operator's decision*; returning early there would answer `200 OK` while silently
discarding that decision. A finished-good shortage is different in kind — it is a genuine
lifecycle outcome under ADR-027 §3 Case 4 and legitimately overrides the confirmation.

## 7. Decision — Fulfilment Eligibility

Preparation, Distribution and the Wave Engine each recognise exactly:

```
['in_progress', 'confirmed']
```

`scheduled` and `awaiting_payment` are **not** eligible — they sit outside fulfilment
execution until their own business triggers make them eligible, at which point they enter
`in_progress` and become eligible by that route.

Each consumer keeps its own explicit, closed list. An unknown or future status is not
eligible by default. Distribution deliberately does not import Preparation's list: the two
are separate contracts that presently agree.

### 7.1 Amendment (2026-08-23) — the payment trigger's destination is `in_progress`

*Owner decision. Amendment A of TASK-ORDER-PAYMENT-PREPARATION-CONTRACT-DECISION-001.*

A **payment-fact trigger** — payment recorded, payment proof verified, or payment method
changed — that satisfies `PaymentFulfillmentGate` advances:

```
awaiting_payment  →  in_progress
```

It **must never** automatically advance:

```
awaiting_payment  →  confirmed        ← PROHIBITED as an automatic outcome
```

This makes the sentence above operative rather than descriptive: "they enter `in_progress`
and become eligible by that route" is the **only** automatic route out of
`awaiting_payment`. Confirmation is a separate commercial act (§5 rule 3), and a change to
*how* a customer will pay is not a decision *that* the order is confirmed.

**Payment Method Change ≠ Order Confirmation.** A method change may only trigger a
payment/fulfilment eligibility re-evaluation.

Consequence for eligibility: an order advanced this way is `in_progress`, and therefore
becomes Preparation- and Distribution-eligible **by the ordinary rule above** — no special
case is introduced.

## 8. Decision — Legacy Vocabulary Is Not Silently Repaired

`LEGACY_STATUS_MAP`-style read-time repair of configuration is **prohibited** as a
correctness mechanism. Invalid configuration must be normalised by migration, once, and then
rejected at the edge.

Legacy values are mapped exactly once, by the normalisation migration:

| Legacy | Canonical | Note |
|---|---|---|
| `pending` | `in_progress` | transitively: V3 made it `new`; this ADR makes `new` → `in_progress` |
| `new` | `in_progress` | B1 |
| `processing` | `in_progress` | |
| `preparing` | `in_progress` | |
| `confirmed` **in an entry-policy list** | *removed* | `confirmed` is not an entry status (§3) |
| `review` | `on_hold` | |
| `rescheduled` | `on_hold` | |
| `completed` | `delivered` | POS entry policy only |

**The `confirmed` row deserves attention.** Because `confirmed` becomes canonical again, a
pre-V3 configuration row containing `"confirmed"` would otherwise become *accidentally valid*
with the wrong meaning — the old `confirmed` meant "V2 confirmation", the new one means the
post-Confirm committed state. Dropping it from entry-policy lists removes the ambiguity
rather than resolving it by guesswork.

## 9. Historical Record — What the V3 Migration Did

Migration `2026_07_22_100000_simplify_order_lifecycle_v3` is **not modified**. It remains the
historical record of the following transformation:

```
pending     → new
processing  → in_progress
confirmed   → in_progress   (merged: was a separate confirmation step)
preparing   → in_progress   (invisible engine state)
```

This ADR supersedes that **vocabulary**, not that **history**. Two consequences must be
recorded honestly:

1. Orders migrated `confirmed → in_progress` in July 2026 are **not recoverable** — they are
   indistinguishable from orders that were genuinely `in_progress`. Restoring `confirmed` does
   not retroactively re-separate them, and no attempt is made to guess.
2. The comments inside that migration describe a vocabulary that is no longer current. They
   are left untouched precisely so the sequence of decisions stays auditable.

## 10. Consequences

### Positive

- The FSM promised by ADR-005 §5 finally exists and is checkable.
- Confirm becomes a real, gating business action with a real state behind it.
- Entry status is honoured as chosen; no hidden writer can rewrite it.
- Configuration and data share one vocabulary, so a stale config value fails loudly instead
  of being silently repaired into a plausible-but-wrong status.
- The `orders.status` column default is corrected from the non-canonical `'pending'`.

### Negative / Trade-offs

- `in_progress` changes meaning: it is now the *entry* state, not the *engines-running*
  state. Historical `in_progress` rows predating this ADR carry the older meaning and cannot
  be re-separated (§9).
- Fulfilment consumers accept two statuses rather than one, so eligibility lists must be kept
  in step across three modules.
- One deployment ordering constraint is introduced (§11).

## 11. Deployment Ordering

There must never be a state in which `orders.status` holds a value the enum cannot hydrate.
The normalisation migration therefore:

- uses **raw SQL only** — no Eloquent model, no `OrderStatus` cast, no enum reference — so it
  runs correctly whether the old or the new code is loaded;
- is **idempotent**, so a re-run is harmless;
- runs in the **same deploy** as the enum change, before traffic is served.

Required order: deploy code → run `php artisan migrate` → serve traffic. The migration must
not be run ahead of the code as a shortcut, and the code must not serve traffic ahead of the
migration.

## 12. Enforcement

- `OrderStatus` is the single source of truth. Every FormRequest derives its validation from
  `OrderStatus::cases()`; no hardcoded status list may exist in a request, config, or seeder.
- Entry-status options are served from `GET /orders/statuses`, derived from the enum.
- Status writes go through `FulfillmentEngine`; the `Order` model guard rejects direct writes.
- Preparation, Distribution and Wave eligibility lists are asserted by tests against
  `['in_progress', 'confirmed']`.
