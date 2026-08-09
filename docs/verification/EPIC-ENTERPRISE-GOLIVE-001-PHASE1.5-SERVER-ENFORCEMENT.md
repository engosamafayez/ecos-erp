# EPIC-ENTERPRISE-GOLIVE-001 — Phase 1.5
## Server Enforcement Verification (RC-10)

**Date:** 2026-08-08
**Type:** Verification only. No code modified, no patch, no redesign, no fix.
**Question:** Is RC-10 a Presentation problem or a Domain problem?

---

# FINAL VERDICT

# **A) — RC-10 is primarily a Presentation issue. The backend rejects the transition.**

**But it rejects for the wrong reason, and that reason is more serious than RC-10 itself.**

The backend does **not** reject `In Progress → Ready for Dispatch` because the order is
unreserved. It rejects it because **`resolveTransitionWorkflow()` does not recognise the status
vocabulary the rest of the platform uses.** The system is protected by accident.

The same accident blocks **every legal transition** through this endpoint.

---

# The execution path, traced to persistence

```
Frontend  order-detail-drawer.tsx → transition.mutate({ id, targetStatus, reason })
    ↓
Service   orders service: POST /fulfillment/orders/${id}/transition          (line 190)
    ↓
Route     routes/api.php:995
          Route::post('orders/{order}/transition', [FulfillmentController::class,'transition'])
          middleware: ['auth:sanctum', 'permission:operations.fulfillment.manage']
    ↓
Controller Modules/Operations/Fulfillment/Presentation/Http/Controllers/
           FulfillmentController::transition()                                (line 356)
    ↓
Request    inline $request->validate([
             'target_status' => ['required','string','max:50'],
             'reason'        => ['nullable','string','max:500'],
           ])
    ↓
Routing    $workflow = $this->resolveTransitionWorkflow($current, $target);   (line 396)
           if ($workflow === null) → 422 "Transition from [X] to [Y] is not allowed."
    ↓
Engine     $this->engine->run($workflow, $order, [...], $actorId)   ← NEVER REACHED for V3 orders
    ↓
Workflow   ProcessOrderWorkflow / PrepWorkflow / DispatchWorkflow / …
    ↓
Domain     OrderStatus, ReservationStatus
    ↓
Persistence orders table
```

**For a V3 order the chain terminates at the routing table with a 422. The engine, workflows,
domain and persistence layers are never reached.**

---

# The evidence

## The vocabulary mismatch

`OrderStatus` — the enum backing the persisted column
(`Modules/Commerce/Orders/Domain/Enums/OrderStatus.php`):

```
new · in_progress · ready_for_dispatch · out_for_delivery · delivered
awaiting_payment · awaiting_stock · scheduled · on_hold · cancelled · returned
```

`resolveTransitionWorkflow()` — the sole (current, target) → workflow map
(`FulfillmentController.php:396–498`):

| Token | Occurrences in routing table |
| --- | --- |
| `in_progress` | **0** |
| `ready_for_dispatch` | **0** |
| `'new'` | **0** |
| `'processing'` | 5 |
| `'confirmed'` | 4 |
| `'preparing'` | 3 |
| `'pending'` | present in `$earlyStates` |

**`processing`, `confirmed`, `preparing` and `pending` are not cases in the `OrderStatus` enum.**
They cannot be the value of `$order->status->value`. The routing table is written entirely in a
status vocabulary the domain no longer uses.

## Trace of the actual observed case

`resolveTransitionWorkflow('in_progress', 'ready_for_dispatch')`:

| § | Branch | Result |
| --- | --- | --- |
| — | `$current === $target` | no |
| 1 | Execution chain (`processing→preparing`, `preparing→out_for_delivery`, `out_for_delivery→delivered`, `delivered→completed`, `→returned`) | no match |
| 2 | Locked states `['preparing','out_for_delivery','delivered','returned','completed']` | `in_progress` not in set |
| 3 | `$target === 'cancelled'` | no |
| 4 | TO `confirmed` | target is not `confirmed` |
| 5 | TO `processing` | target is not `processing` |
| 6 | TO `pending` | no |
| 7 | TO `awaiting_payment` | no |
| 8 | TO `awaiting_stock` | no |
| 9 | TO `review` | no |
| 10 | TO `rescheduled` | no |
| — | **`return null;`** | **→ controller returns 422** |

There is **no normalisation, no alias map, no legacy-status translation and no fallback** — the
method ends at `return null`. Searched and confirmed absent.

---

# Answers to the required questions

### For the generic `/transition` endpoint

| Question | Answer |
| --- | --- |
| **Who authorizes it?** | Route middleware — `auth:sanctum` + `permission:operations.fulfillment.manage`. **Authorization is enforced.** |
| **Who validates it?** | Two places: inline `$request->validate()` (shape only — string, max 50) and `resolveTransitionWorkflow()` (the status-pair graph). |
| **Who rejects it?** | `FulfillmentController::transition()` at line 370, returning **422**, when the routing table yields `null`. |
| **Who owns the business rule?** | **`resolveTransitionWorkflow()` — a private method on a Presentation-layer controller.** It is the only (current, target) authority in the codebase. |
| **Is reservation checked?** | **No.** The routing table receives only two strings. It has no order, no reservation, no inventory. |
| **Is inventory checked?** | **No** — not at routing. Individual workflows (`ProcessOrderWorkflow`, `ConfirmWorkflow`) do reserve inventory, and `ProcessOrderWorkflow` is documented as carrying a delivery-date guard — but they are never reached for V3 statuses. |
| **Is warehouse assignment checked?** | **No.** |
| **Is preparation checked?** | Structurally, via the `preparing → out_for_delivery` chain — but in dead vocabulary. |
| **Is dispatch checked?** | Same. |

---

# Where business rules should live vs. where they do

| Business rule | Should live | Currently lives | Enforcement level |
| --- | --- | --- | --- |
| Is the actor permitted to transition orders? | Application / policy | Route middleware (`permission:operations.fulfillment.manage`) | ✅ **Application enforced** |
| Is the payload well-formed? | Application | Inline `validate()` in controller | ✅ **Application enforced** |
| Is (current → target) a legal edge? | **Domain** (state machine) | `FulfillmentController::resolveTransitionWorkflow()` | ⚠️ **Presentation enforced** — real, but misplaced *and* written in dead vocabulary |
| Which actions to display | Presentation | `OrderResource::resolveAllowedTransitions()` | ✅ Correct layer |
| **Is stock reserved before Ready for Dispatch?** | **Domain** | **nowhere** | ❌ **NOT ENFORCED** |
| **Is a warehouse assigned before dispatch?** | **Domain** | **nowhere** | ❌ **NOT ENFORCED** |
| Is inventory reserved on confirm/process? | Domain | `ConfirmWorkflow` / `ProcessOrderWorkflow` | ✅ **Domain enforced** — but unreachable for V3 |
| Is inventory released on cancel / return-to-pending? | Domain | `CancelOrderWorkflow` / `ReturnToPendingWorkflow` | ✅ **Domain enforced** — unreachable for V3 |
| Delivery-date constraint on activation | Domain | `ProcessOrderWorkflow` guard | ✅ **Domain enforced** — unreachable for V3 |

**Two `OrderResource` methods and one controller method each hold a piece of the same rule, in
three layers, in two vocabularies.**

---

# What this changes about RC-10

## RC-10 as originally stated is downgraded

> *"Orders can be advanced toward shipping with no reservation and no warehouse."*

**Not demonstrated.** A user clicking `Mark Ready` would receive **422 — Transition from
[in_progress] to [ready_for_dispatch] is not allowed.** No state change, no persistence write, no
customer promise. **The observed defect is that the UI offers an action the API will refuse.**

That is Presentation: `OrderResource` computes its offer list from a V3 status graph, while the
controller resolves workflows from a V2 one. Two graphs, never reconciled.

## But a more severe finding is exposed

**The generic transition endpoint appears non-functional for every V3 order.**

`OrderResource` emits only V3 targets. `resolveTransitionWorkflow` recognises only V2 sources and
targets. **Every button in the Workflow tab should therefore 422** — not just `Mark Ready`.

This is consistent with the observed system state across eleven campaigns: two orders, created
2026-08-07, still sitting at `In Progress` and `Awaiting Stock`; zero fulfilments; zero shipments;
zero deliveries; an empty stock ledger. **Nothing has ever progressed through the order lifecycle.**

**Scope limit — important.** Fifteen *dedicated* routes exist alongside the generic one
(`/confirm`, `/dispatch`, `/move-to-preparation`, `/complete-delivery`, `/awaiting-stock`, …), each
calling its workflow directly and bypassing `resolveTransitionWorkflow()`. **Those were not
traced** and may work correctly. The failure certified here is specific to the **generic
`/transition` endpoint**, which is the one the order drawer uses.

## And the real RC-10 remains true

Even if the vocabularies are reconciled, **no layer anywhere checks reservation or warehouse
assignment before allowing progression toward dispatch.** The routing table takes two strings; it
could not perform that check even if it matched. Reconciling the vocabulary would **convert a
latent defect into a live one** — the transition would then succeed, unreserved.

**RC-10 is a Domain gap that is currently masked by a Presentation/vocabulary defect.**

---

# Confidence

| Claim | Confidence | Basis |
| --- | --- | --- |
| Route, controller, validation and 422 path exist as described | **High** | Read directly, lines cited |
| Routing table contains zero V3 status tokens | **High** | Counted: `in_progress` 0, `ready_for_dispatch` 0, `'new'` 0 |
| `OrderStatus` enum contains no V2 tokens | **High** | Enum read directly |
| `resolveTransitionWorkflow('in_progress','ready_for_dispatch')` returns null | **High** | All 10 branches traced by hand |
| The drawer calls `/fulfillment/orders/{id}/transition` | **High** | Frontend service line 190 |
| **The endpoint 422s in practice** | **Medium-High** | **Static analysis only — not executed.** A POST would mutate order state if the analysis is wrong, and this is a verification task. Empirical confirmation is deliberately deferred. |
| The 15 dedicated routes are unaffected | **Unverified** | Not traced |

---

# Certification

**RC-10 is certified as: (A) primarily a Presentation issue — the backend rejects the transition.**

With three qualifications that must carry into Phase 2:

1. **The protection is accidental.** The rejection comes from a status-vocabulary mismatch, not a
   business guard. It is not a control anyone designed and cannot be relied upon.
2. **The same defect blocks legal transitions.** The generic endpoint appears unusable for V3
   orders, which is consistent with an eleven-campaign system in which no order has ever advanced.
3. **The Domain gap is real and unmitigated.** Reservation and warehouse assignment are checked
   **nowhere**. Fixing the vocabulary without adding the guard would make illegal transitions
   genuinely possible for the first time.

**Phase 2 must not treat this as "already protected."**

---

**No code was modified. No patch was generated. No fix or redesign is proposed. No API call was
executed — a transition POST would mutate the only order data in the platform, and static evidence
was sufficient to certify the path.**
