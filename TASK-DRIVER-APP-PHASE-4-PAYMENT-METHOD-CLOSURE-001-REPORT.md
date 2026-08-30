# TASK-DRIVER-APP-PHASE-4-PAYMENT-METHOD-CLOSURE-001

## Executive Summary

Closes the single remaining Phase-4 gap: an authorized driver can now change the order's payment
method during an active delivery through a canonical driver endpoint, **without** a new
fulfillment engine and **without** duplicating the re-evaluation orchestration.

- **Endpoint:** `PATCH /api/driver/stops/{stopId}/payment-method` → `DriverRuntimeController::changePaymentMethod`.
- **Authority reused, not rebuilt:** the write + fulfillment re-evaluation live in a thin
  `ChangeOrderPaymentMethodAction`, which **reuses the single authority** `ReevaluateOrderFulfillmentAction`
  (+ `PaymentFulfillmentGate`). No `Order.status` is written from the driver stack.
- **A safety hole the task's STOP condition predicted was found and closed.**
  `ReevaluateOrderFulfillmentAction` converts a blocked transition into a **no-op SUCCESS**, so a
  wrapping transaction alone cannot stop a proof-required method from committing onto a still-
  fulfilling order. The action adds an explicit **consistency invariant** (re-consult the gate;
  the order must end permitted or parked at `awaiting_payment`, else roll back) — proven by test.

Backend: **4 files** (2 new, 2 edited) · Frontend: **6 files** (0 new components; editable control
added) · Backend tests **12/12** · Frontend tsc 23 baseline / vitest 70 / eslint 0 · No live data
mutated · No commit/deploy.

**IMPLEMENTATION STATUS: COMPLETE.**
**FINAL CERTIFICATION: DEFERRED** (project-wide certification remains deferred per §15).

Date: 2026-08-28 · Branch: `develop`

---

## §2 Audit Trace (before implementation)

| Concern | Canonical source (verified in the working tree) | Decision |
|---|---|---|
| Driver stop → Order | no Eloquent relation; `Order::query()->where('id', $stop->order_id)->first()` (the existing driver pattern, DRC:663) | reuse |
| Effective method | `payment_method_manual ?? payment_method` (`PaymentFulfillmentGate::methodOf()`) — operator writes `payment_method_manual` | write `payment_method_manual` |
| Existing "change method + re-evaluate" | **inline** in `PatchOrderAction:233-238` and `UpdateOrderAction:303-309`: `$order->update([...])` then `$this->reevaluateFulfillment->execute($order)` | reuse the re-eval half |
| Dedicated change-method action | **none exists** | add a thin orchestrator, reuse the authority |
| Re-evaluation authority | `ReevaluateOrderFulfillmentAction::execute(Order): OperationResult` — own `DB::transaction`+`lockForUpdate`; advance (`awaiting_payment`+`permitsAdvance`→`ProcessOrderWorkflow`) or demote (`fulfilmentEligible`+`!permits`→`ReturnToPaymentWorkflow`); **never writes `Order.status`**; catches `WorkflowPreconditionException` as no-op | reuse verbatim |
| Vocabulary | 5-value `in:` list `cod,instapay,mobile_wallet,credit_card,bank_transfer` (request classes + policy-map keys); **no enum** | replicate the `in:` list |
| Proof policy | `BrandPolicy.payment_proof_policy`: instapay/bank_transfer/mobile_wallet=`required`, credit_card=`optional`, cod=`none` | surfaced in UI |
| Editability guard | **none** — `payment_method_manual` is a SOFT field editable on any order; only guard is the demotion precondition | driver bridge adds a delivery-state gate |

**Conclusion:** the orchestration authority already exists and is a directly-callable action; I
reused it and added only the thin transaction wrapper + the driver bridge. No orchestration was
duplicated inside `DriverRuntimeController` (§2).

## §1 Architecture — no new engine

`ReevaluateOrderFulfillmentAction` + `PaymentFulfillmentGate` remain the sole authority for
fulfillment re-evaluation. `ChangeOrderPaymentMethodAction` is an application bridge: it writes
the method and calls that authority. It defines no workflow, no gate, no status write.

## §3 Driver Endpoint

`PATCH /api/driver/stops/{stopId}/payment-method` in the existing `prefix('driver')` group
(`auth:sanctum` + `permission:loading.driver.operate`). PATCH is used because it is a partial
update of one order attribute (matching the operator `quickUpdate` verb); the `/payment` path
was already the frozen settlement stub, so `/payment-method` is a distinct path. Handler follows
the controller's conventions: `ownedStop()` → eligibility → `Order` via `order_id` → validate →
delegate → return `['stop' => stopDetail(refresh)]`.

## §4 / §11 Authorization & Eligibility

- **Ownership:** `ownedStop()` resolves the stop and re-asserts the parent trip belongs to the
  authenticated driver's company AND driver — a cross-driver stop id returns **404**; a non-driver
  user **403**; unauthenticated **401** (all test-pinned).
- **Delivery-state gate (driver-imposed):** because no canonical order-level editability guard
  exists, the bridge enforces `stop.status === in_progress` (out for delivery). It rejects
  **before Start Delivery** (`pending`) and on any **settled/terminal** outcome (delivered /
  partial / failed / returned / skipped) with **422**. This mirrors the Phase-4 Start-Delivery
  gating and keeps editing inside the active delivery experience.

## §5 Allowed Methods

Server-side validation `['required','string','in:cod,instapay,mobile_wallet,credit_card,bank_transfer']`
— the exact five-value canonical catalogue. An unsupported value (`paypal`) is **422** (test-pinned).
The driver UI offers only these five; no alias vocabulary is introduced (the request field name
`payment_method` matches the driver read contract, while the value set is canonical).

## §6 Fulfillment Re-evaluation (mandatory)

Every real change routes through `ReevaluateOrderFulfillmentAction` — never a hand-rolled
reservation path. Proven by **effect** (a transition only that action can make):
- **InstaPay → COD** on an order blocked at `awaiting_payment` → advances it out of the payment
  block (test `test_instapay_to_cod_advances_a_blocked_awaiting_payment_order`).
- **COD → InstaPay** on a fulfilment-eligible order → demoted to `awaiting_payment`
  (test `test_cod_to_instapay_demotes_a_fulfilment_eligible_order`).

Driver-context note: an order a driver handles is `out_for_delivery` — neither `awaiting_payment`
nor `fulfilmentEligible() = {in_progress, confirmed}` — so re-evaluation is a canonical **no-op**
there (`ReturnToPaymentWorkflow` deliberately does not unwind physical execution). The action
still routes through it (does not bypass), and the §7 invariant governs the outcome.

## §7 Transaction Boundary — the STOP-condition proof

**The task's STOP condition was correct, and a wrapping transaction alone is insufficient.**
Traced (`ReevaluateOrderFulfillmentAction.php:97-187`, `OperationResult.php`): a
`WorkflowPreconditionException` in either branch is **caught and returned as
`OperationResult::success` (a no-op)**. It never throws and never signals rejection. So for an
`out_for_delivery` order changed COD→InstaPay, the write commits with `method=instapay,
status=out_for_delivery, no proof` — a proof-required method on a fulfilling order the gate
forbids — and re-evaluation reports success. The operator paths carry this same latent property.

**Fix (an explicit consistency invariant, not trust in the no-op):** inside one `DB::transaction`,
after write + re-evaluate, re-consult the **same authority** `PaymentFulfillmentGate::permits()`:

> commit only if `gate->permits($order)` OR `$order->status === AwaitingPayment`; otherwise
> **throw `PaymentMethodChangeRejectedException` → roll back the write**.

This admits **InstaPay→COD** (`permits(cod)=true`) and **rejects COD→InstaPay on a fulfilling
order without verified proof** (the hazard). Pinned by
`test_switching_a_fulfilling_order_to_a_proof_required_method_is_rejected_and_rolled_back` (action)
and `test_switching_to_a_proof_required_method_on_a_fulfilling_order_is_rejected` (HTTP): after
the 422, the DB still holds `cod`. No new method is ever committed beside a stale fulfilment state.

## §8 Failure Behavior

A rejected change returns **422** with a domain message (`PaymentMethodChangeRejectedException`,
Symfony `UnprocessableEntityHttpException`). The frontend hook toasts the message and, on either
outcome (`onSettled`), invalidates the stop detail so the UI **reloads canonical server truth** —
it never optimistically shows a method the backend rejected.

## §9 Payment Proof Rule

Phase-4 proof requirements are preserved and reused (`useUploadPaymentProof` → the certified
secure `payment_proofs` endpoint; upload-only, verification stays with the operator). When the
current or selected method requires proof (instapay / bank_transfer / mobile_wallet), the UI
surfaces an explicit "payment proof required" notice beside the existing upload control. The
backend gate independently makes verified proof mandatory for those methods to reach fulfilment.
No change to the POD / delivery-finalization architecture was made (§13); the hard finalization
proof-gate remains the Finance/operator verify authority.

## §10 Driver UI

The read-only payment-method row (Phase 3) gains a **Change** affordance, shown **only** while
`stop.status === in_progress`. It opens a sheet listing the five canonical methods with full
localized labels, submits to the new endpoint, shows a pending state, handles rejection (toast +
canonical reload), and refreshes stop/order truth on success. It never writes fulfillment state
in React. Two new canonical labels (`mobile_wallet`, `credit_card`) were added so every selectable
method renders a full label.

## §12 Idempotency

An unchanged method short-circuits before any write or re-evaluation
(`test_an_unchanged_method_is_a_no_op`), so COD→COD (or a repeated accepted request) duplicates no
fulfillment side effect. Beneath it, `ReevaluateOrderFulfillmentAction` is itself idempotent
(`lockForUpdate`, status re-read in the lock).

## §13 / §14 Scope

No change to delivery-quantity, vehicle-custody, POD, failed-delivery, returns, reconciliation,
wallet, driver-reports, day-settlement, or Finance code. Backend additions are limited to the
bridge (endpoint + validation + the thin action + one exception) and reuse the canonical payment
mutation + re-evaluation. No duplicate domain authority was created.

## Files Changed

**Backend (4):**
| File | Change |
|---|---|
| `Modules/Commerce/Orders/Application/Actions/ChangeOrderPaymentMethodAction.php` | **NEW** — write `payment_method_manual` + reuse `ReevaluateOrderFulfillmentAction`, one tx, consistency invariant |
| `Modules/Commerce/Orders/Domain/Exceptions/PaymentMethodChangeRejectedException.php` | **NEW** — 422 domain rejection (rolls back inside the tx) |
| `Modules/Logistics/Distribution/Presentation/Http/Controllers/DriverRuntimeController.php` | `changePaymentMethod` handler + 2 imports |
| `routes/api.php` | `PATCH /driver/stops/{stopId}/payment-method` |

**Frontend (6):**
| File | Change |
|---|---|
| `pages/driver-stop-detail-page.tsx` | editable method control (gated in_progress), change sheet (5 canonical methods), §9 proof notice |
| `hooks/use-driver-mobile.ts` | `useChangePaymentMethod` (reload truth on settle) |
| `services/driver-mobile-service.ts` | `changePaymentMethod` (PATCH) |
| `pages/driver-stop-detail-gating.test.tsx` | assert the control is hidden pending / shown in_progress (§11) |
| `i18n/locales/{en,ar}/driver-mobile.json` | `stop.changeMethod.*` + `stop.payment.methods.{mobile_wallet,credit_card}` (EN↔AR parity) |

## §15 Verification (focused only — no full/browser/module certification)

**Backend (isolated `ecos_dev_test`, via the test gate) — 12/12, 23 assertions:**
- `ChangeOrderPaymentMethodActionTest` (4): InstaPay→COD advances a blocked order; COD→InstaPay
  demotes a fulfilment-eligible order; proof-required change on a fulfilling order rejected +
  **rolled back**; unchanged = no-op.
- `DriverPaymentMethodChangeTest` (8): own active stop change persists; proof-required on a
  fulfilling order → 422 (rolled back); unsupported method → 422; refused before start (pending);
  refused on a settled stop; another driver's stop → 404; non-driver → 403; unauthenticated → 401.

**Frontend:** tsc **23 = baseline (0 in touched files)**; vitest **70 pass** (incl. the gating
test's new §11 assertions); eslint **0**; i18n EN↔AR parity clean.

No full backend/frontend suite, no browser certification, no module certification (§15).

## §16 Live-Data Protection

All mutation verification ran in the isolated `ecos_dev_test` via the gate. No DEV/demo business
data was mutated; the DEV stock condition was not touched; no deploy to `ecos-dev-app`; no commit.

## Remaining Notes / Follow-ups (not in scope)

1. **Operator paths carry the same latent no-op hazard** — `PatchOrderAction`/`UpdateOrderAction`
   call `ReevaluateOrderFulfillmentAction` and do not inspect the outcome, so an operator can leave
   a fulfilling order on a proof-required method the demotion couldn't reach. They could adopt
   `ChangeOrderPaymentMethodAction` to inherit the invariant — a separate refactor, not done here.
2. **Hard finalization proof-gate** (block delivery finalization when a proof-required method has
   no verified proof) is a Finance/operator concern and a delivery-architecture change (§13); the
   UI surfaces the requirement, the gate enforces fulfilment eligibility, but finalization itself
   was not re-gated.

## Final Status

**IMPLEMENTATION STATUS: COMPLETE** — the driver can change the payment method during an active
delivery through a canonical endpoint that reuses `ReevaluateOrderFulfillmentAction`, never writes
`Order.status`, enforces ownership + delivery-state eligibility + the canonical vocabulary, and
provably cannot commit a new method beside a stale fulfilment state. This closes the Phase-4 §7 gap.

**FINAL CERTIFICATION: DEFERRED.**

---

**STOP.** No Phase 5, no commit/push/deploy, no DEV business-data mutation.
