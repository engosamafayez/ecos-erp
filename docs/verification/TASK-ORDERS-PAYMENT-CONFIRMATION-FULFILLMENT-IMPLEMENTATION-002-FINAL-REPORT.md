# TASK-ORDERS-PAYMENT-CONFIRMATION-FULFILLMENT-IMPLEMENTATION-002 — FINAL REPORT

**Status:** IMPLEMENTED / VERIFIED / BROWSER VERIFIED (focused gates) — **ONE STOP CONDITION TRIGGERED AND REPORTED**
**Date:** 2026-08-21
**Branch:** `develop` — **NOT COMMITTED, NOT DEPLOYED TO PRODUCTION**
**Owner decisions implemented:** **D1-A** (MANDATORY FINANCIAL CONTROL) · **D2-B** (resolve NULL through the documented policy chain)
**Basis:** `…-DECISION-002-REPORT.md`, `…-OWNER-DECISION-003-REPORT.md`, preserving `…-IMPLEMENTATION-001`

---

## 1. Executive Summary

**One financial control, one implementation, evaluated everywhere.**

The defect this closes was not a wrong rule — it was the *same rule written twice*. The payment
control lived in `ConfirmOrderWorkflow` and, in a second drifted copy, inline in
`CreateManualOrderAction`. When IMPLEMENTATION-001 hardened the confirmation copy to read
`payment_proofs`, the creation copy stayed on the superseded `orders.payment_proof_path` string.
An order created with `payment_proof_path: "x"` entered `in_progress` directly, and because the
gate fires **only** from `awaiting_payment`, that order was never payment-evaluated again for the
rest of its life.

**What was built:**

1. **`PaymentFulfillmentGate`** — the single implementation of the contract. Creation and
   confirmation now consult the same object, so they cannot drift again. It adds no state, no
   column, no proof source and no status; every value it returns comes from a store that already
   existed.
2. **Creation aligned to D1-A.** A canonical proof is a `payment_proofs` row, and that row cannot
   exist before the order does — so a proof-required method is now **always** created
   `awaiting_payment`, regardless of submitted status, amount paid, or any proof string.
3. **D2-B: the `channel_id IS NULL → 'none'` hardcode is gone.** Resolution continues down the
   documented chain (Channel → Company → `BrandPolicy::defaultSettings('order')`). That literal
   was never a default — it *bypassed* a default that already answers `required`.
4. **Payment-method change is now a re-evaluation trigger, in both directions**, through the
   existing canonical entry point. Advancing uses `ConfirmOrderWorkflow`; returning uses the
   **already-existing** `ReturnToPaymentWorkflow`. **No new workflow, no second engine, no direct
   status write.**
5. **RBAC seeded.** The approved separation of duties was config-only and in **no** database. It
   is now live in `ecos_dev` and verified: no role holds both `proof_upload` and `proof_verify`.

**Gates: 115 backend tests / 379 assertions GREEN across 7 suites. 43 frontend tests GREEN.
PHPStan clean. Pint clean on all 11 touched files. ESLint clean. No new TypeScript errors.
Vite build green. Browser verified on the live dev stack in both English and Arabic.**

**Live proof, on the real stack.** ORD-00015 was created through the real endpoint with the exact
bypass payload from the audit — `status: in_progress`, `payment_method_manual: instapay`,
`payment_proof_path: "x"`, **paid in full** — and stored **`awaiting_payment`**. Before this task
that payload produced `in_progress`.

**One STOP condition is triggered and is NOT worked around (§23.1):** `payment_proofs` carries no
payment-method association, so a proof raised for one rail would satisfy another proof-required
rail. The schema cannot express the relationship; **no schema relationship was invented**, the
existing order-scoped semantics were preserved exactly, and the unresolved question is stated in
full. It does not affect either critical scenario.

**No migration. No schema change. No new payment state. No new permission or role. Preparation
untouched. WooCommerce untouched. No commit, no deploy to production.**

---

## 2. Owner Decisions

| Decision | Approved contract | Implemented where |
|---|---|---|
| **D1-A — MANDATORY FINANCIAL CONTROL** | For a `required` method, fulfilment eligibility needs **sufficient payment AND an active VERIFIED `payment_proofs` record** — at manual creation, normal creation, payment recording, proof verification, payment-method change, and confirmation/re-evaluation. Otherwise the order remains/returns to `awaiting_payment`. | `PaymentFulfillmentGate` (single authority), consulted by `ConfirmOrderWorkflow`, `CreateManualOrderAction`, `CreateOrderAction`, `ReevaluateOrderFulfillmentAction` |
| **D2-B — NULL channel policy** | `channel_id IS NULL` must resolve through Channel → Company → `BrandPolicy::defaultSettings('order')`, never hardcode `'none'`. COD stays `none`. No new source, no new default, no migration. | `PaymentFulfillmentGate::requirementFor()` |

Neither decision was weakened. The previous OR-gate was **not** restored. `payment_proof_path` is
**not** accepted as a substitute for a canonical proof anywhere.

---

## 3. ADR-042 Amendment

`docs/adr/ADR-042-order-fsm-v3-canonical.md` — **§3.1 restated, §3.2 rule 1 aligned. No other
section touched.**

The amendment is marked in place with a dated banner recording why: §3.1's original acceptance
criterion (*"no proof was supplied"*) was written on 2026-08-13, when `orders.payment_proof_path`
was the only proof that existed. `payment_proofs` arrived 2026-08-19 and the string column was
declared superseded 2026-08-20, so the original criterion no longer named a proof source the
system recognises.

§3.1 now states, explicitly and in this order:

- **the rule** — both conditions, named against `payment_proofs` (`superseded_at IS NULL`,
  `state = 'verified'`) and `deposit_amount >= total`;
- **creation-time behaviour** — condition 2 is *unsatisfiable* at creation because the proof route
  requires an existing order, therefore a proof-required method is **always** created
  `awaiting_payment`;
- **behaviour when payment is insufficient** — stays parked; the payment stays committed;
- **behaviour when proof is absent, unverified, or rejected** — `uploaded` is evidence submitted
  not accepted; `rejected` never satisfies; a superseded proof is history;
- **relationship to the canonical lifecycle** — a creation-time proof *reference* keeps display
  and audit value and carries **no lifecycle authority**;
- **precedence** — the payment block outranks any other creation mechanism, including the shipping
  `status_override`;
- **payment method is not fixed at creation** — the evaluation re-runs on payment recorded, proof
  verified/rejected, and payment-method changed, returning via the existing `return_to_payment`
  workflow.

§3.2's fallback rule 1 was updated to point at §3.1 so the two cannot contradict.

---

## 4. Payment Policy Resolution

**One resolver, `PaymentFulfillmentGate` (221 lines, new).** It is not a new policy *source*:

```
1. Channel scope  → channels.brand_id → config_brand_policies   (unchanged when a channel resolves)
2. Company scope  → ConfigurationManager::getCompanySettings(company_id, 'order')
3. System default → BrandPolicy::defaultSettings('order')['payment_proof_policy']
```

**Why the old `'none'` was not a default.** `ConfigurationManager::getBrandPolicy()` already falls
back to `BrandPolicy::defaultSettings()` for any brand with **no policy row**, and that default
says `instapay/bank_transfer/mobile_wallet => required`. The only way to reach `'none'` for
instapay was to have **no brand at all** — reachable by an ordinary order edit nulling
`channel_id`. A control an order editor can switch off is not a control.

**Deliberately unchanged:** the key-miss default. A method the resolved policy does not mention
still yields `'none'`, which is what keeps WooCommerce gateway ids (`bacs`, `ppcp`) inert rather
than silently proof-required (§13).

Verified live against `ecos_dev` (read-only, no mutation):

```
ORD-00003 awaiting_payment instapay channel=set  req=required paid=Y proof=Y => permits=YES
ORD-00004 awaiting_payment instapay channel=set  req=required paid=N proof=N => permits=NO
ORD-00005 awaiting_payment instapay channel=set  req=required paid=N proof=Y => permits=NO
ORD-00008 awaiting_payment cod      channel=NULL req=none     paid=N proof=N => permits=YES
```

ORD-00008 is the live channel-less order. It resolves `none` **through the chain** rather than
through the removed hardcode — same outcome, correct reasoning. This is the empirical confirmation
of the decision record's "0 orders change outcome".

---

## 5. Creation Path

**`CreateManualOrderAction`** — `resolveManualOrderStatus()` no longer reads
`$data['payment_proof_path']` and no longer resolves policy from the brand-only `$orderPolicy`.
It asks `PaymentFulfillmentGate::permitsAtCreation()`. `$orderPolicy` is still used for
`source_entry_policies`, which is genuinely brand-scoped and unchanged.

**Status-override precedence (audit defect closed).** The write was
`$shippingResult['status_override'] ?? $statusResolution['status']`, so a proof-required order that
also needed shipping review was stored `on_hold` — a status the payment gate never evaluates, so it
could be confirmed straight out of `on_hold` with no payment and no proof. Worse, the §3.1 audit
event still claimed `stored_status: awaiting_payment`, which the row contradicted. The payment
block now wins, and the event reports `$order->status->value` — **the status actually written**.

**`CreateOrderAction`** (`POST /api/orders`) — the same guard is wired in. **Stated plainly: it is
inert today.** `OrderDTO` carries no payment method, so there is no payment contract to bypass on
that path and none is invented. It is wired so the day a payment method is added to that DTO the
control applies automatically rather than that endpoint becoming the next bypass. Its ability to
accept `confirmed` as an entry status is an ADR-042 §3 *entry-contract* gap, not a payment gap; it
is **preserved** per "preserve all unrelated creation semantics" and reported in §24.

**The invariant holds:** no proof-required order may reach a fulfilment-eligible status without the
mandatory control — and creation does not pretend a `payment_proofs` row can exist before the order.

---

## 6. Payment Re-evaluation

`ReevaluateOrderFulfillmentAction` is **preserved and extended, not rebuilt**. Its transaction,
`lockForUpdate()`, status-re-read-inside-the-lock, and "blocked gate is a no-op" semantics are
unchanged. It still never writes `Order.status`.

It is now **bidirectional**:

```
awaiting_payment        + gate satisfied    -> ConfirmOrderWorkflow      (advance)
in_progress/confirmed   + gate unsatisfied  -> ReturnToPaymentWorkflow   (return)
```

The advance direction is untouched — the workflow's own guard still decides, deliberately not
re-implemented in the action. The return direction is bounded by
`OrderStatus::fulfilmentEligible()`, the same closed list Preparation, Distribution and the Wave
Engine use, so it covers exactly the statuses from which an order could otherwise be collected
without satisfying the control.

---

## 7. Payment Method Change Re-evaluation

**New trigger, wired to the existing canonical entry point in both change paths:**

| Path | Endpoint | Where |
|---|---|---|
| `PatchOrderAction` | `PATCH /orders/{id}/quick-update` | after the field update + `field_updated` event |
| `UpdateOrderAction` | `PUT /orders/{id}` | after persistence + the `order_updated` audit event |

Both compare the method captured **before** the write (it is a SOFT field, editable even on a
structurally locked order) and call `ReevaluateOrderFulfillmentAction` only on an actual change.
**No separate action. No `ConfirmOrderWorkflow` logic duplicated. No direct status assignment.**

Against the required contract:

| # | Requirement | Status |
|---|---|---|
| 1 | Persist the new payment method | Unchanged — persisted first |
| 2 | Resolve the new payment policy | `PaymentFulfillmentGate` re-resolves from the current method |
| 3 | Ignore obsolete assumptions from the previous method | The gate reads current state only; nothing is cached |
| 4 | Re-evaluate fulfilment | `ReevaluateOrderFulfillmentAction` |
| 5 | Use the canonical `ConfirmOrderWorkflow` | Yes, for the advance direction |
| 6 | Preserve historical payment/proof records | Nothing is written to `payment_proofs` or payments |
| 7 | Old proof must not automatically satisfy a new method | **STOP TRIGGERED — see §23.1.** Not silently accepted, not invented around |
| 8 | Do not delete historical payment records | None deleted; `payment_proofs` count unchanged (4 → 4) |
| 9 | Do not create a second proof source | None created |
| 10 | Do not directly assign `Order.status` | Every transition runs through `FulfillmentEngine` |

**Critical scenario — both directions verified (automated §18 D-1/D-3, browser §20):**

```
Instapay → awaiting_payment → method changed to COD → gate passes (cod = none) → Confirmed
COD      → in_progress      → method changed to Instapay → gate blocks → Awaiting Payment
```

**A consequence stated plainly:** `ReturnToPaymentWorkflow` releases held inventory — that is its
existing certified behaviour and was not modified. Returning an order therefore frees its
reservation, which is correct (an ineligible order should not hold stock) but is a real operational
effect. Orders already downstream (`ready_for_dispatch` and later) are **not** pulled back: that
workflow's guard blocks them and the exception is caught as a no-op. Unwinding physical execution
is not a payment concern.

---

## 8. Proof Contract

Canonical source: **`payment_proofs`, and only `payment_proofs`.**

```php
PaymentProof::query()->where('order_id', $order->id)
    ->whereNull('superseded_at')                    // active
    ->where('state', PaymentProofState::Verified->value)
    ->exists();
```

The gate does **not** inspect `payment_proof_path` anywhere. None of these satisfy it: a non-empty
path, an arbitrary path, an unverified (`uploaded`) proof, a `rejected` proof, or a superseded
proof. Each is pinned by a test (§18 E1–E5, B3).

`orders.payment_proof_path` is still persisted and still audited (`proof_uploaded` event) — it has
display value and no lifecycle authority.

---

## 9. RBAC

**Seeded and verified. Nothing weakened, no user created, no role invented, no grant hand-assigned.**

The approved separation of duties from IMPLEMENTATION-001 was config-only and present in **no**
database — verified before seeding: zero roles held any `sales.orders.proof*` permission. The
approved seeder was run against `ecos_dev`. Resulting matrix, read back from the database:

| Role | upload | verify | reject |
|---|:--:|:--:|:--:|
| `Sales`, `Sales Manager`, `Sales Representative` | ✅ | ❌ | ❌ |
| `Company Admin` | ❌ | ✅ | ✅ |
| `Finance Manager` | ❌ | ✅ | ✅ |

A direct SQL join for any role holding **both** `proof_upload` and `proof_verify` returns **zero
rows**. Separation of duties is structural.

`approverCannotBeMaker()` is a **Finance** invariant (`AccountsPayableService`, `ClosingService`,
`BudgetService`). It was not touched, weakened, or approached — no Finance file is in the change
set. No fake users were created; no financial approval privilege was granted to Sales.

---

## 10. Concurrency

**Unchanged from the certified model.** One `DB::transaction`, one `lockForUpdate()` on the order
row held across both the decision and the transition, status re-read **inside** the lock. The
`Order` passed in is treated as an identifier, never as trusted state.

No distributed locks, no idempotency-key infrastructure, no second engine were introduced. The
third trigger (payment-method change) enters through the *same* action and therefore inherits the
same guarantee: a concurrent record-payment, proof-verification and method-change serialise at
`lockForUpdate()`, and the loser observes committed state instead of racing it.

---

## 11. Idempotency

Existing mechanisms only — nothing new invented.

| Concern | Mechanism |
|---|---|
| Duplicate transitions | Status re-read inside the lock; the second evaluation sees the transitioned order |
| Duplicate events | Only one caller reaches `engine->run()` |
| Duplicate audit rows | `OrderEvent` written by `FulfillmentEngine` per successful run |
| Duplicate reservation | Reservation runs inside the single workflow execution; `ConfirmOrderWorkflow`'s `$alreadyReserved` short-circuit is unchanged |
| Repeated method change to the same value | No change detected → no re-evaluation call at all |

Pinned by `test_d7_re_evaluating_the_same_method_twice_is_a_no_op` (exactly one `return_to_payment`
event) and by the preserved `test_re_evaluation_is_idempotent`.

---

## 12. COD

**Unchanged. Verified at every layer.**

`cod` and `cash` resolve `'none'` in both authorities — the live brand policy and
`BrandPolicy::defaultSettings()`. Requirement `'none'` satisfies the gate *before* payment is ever
considered, so a COD order still confirms unpaid and still enters fulfilment immediately.

No artificial COD payment is created anywhere. `test_c3` pins `cod`/`cash` = `none` across all three
scopes including a NULL channel; `test_b6` pins COD creation entering `in_progress`;
`OrderPaymentStateTest` (COD partial/unpaid semantics) passes unchanged. Live: ORD-00008 —
channel-less COD — evaluates `permits=YES`, identical to before.

---

## 13. WooCommerce

**Not modified. No mapping invented. No STOP triggered.**

Imported orders always carry `channel_id` (`WooCommerceOrderImporter:366`), so the NULL branch is
unreachable at import. Their `payment_method` is the raw gateway id (`bacs`, `ppcp`, `stripe`),
which is not a key in the ECOS `payment_proof_policy` vocabulary, so the requirement resolves
`'none'` **by key-miss** — behaviour deliberately preserved (§4).

`OrderStatusSyncJob`'s unmapped-status behaviour (`markFailed(); return;`) is untouched, so a sync
failure still records a failure without throwing and cannot roll back an internal fulfilment
transition. `WooCommerceOrderStatusTranslator` is unchanged.

**Documented known limitation (unchanged, not introduced here):** a Woo order paid by bank transfer
(`bacs`) is proof-not-required while the equivalent manual order (`bank_transfer`) is
proof-required. The two vocabularies do not share a namespace. Reported, not resolved.

---

## 14. ORD-00003

**Read-only throughout. Not modified, not re-triggered, no payment recorded, no proof verified, no
status changed.** Baseline and final state are byte-identical, `updated_at` included
(`2026-08-19 22:20:30`).

**Determination required by the brief — does a legitimate re-trigger exist?** **Yes.** Evaluated
read-only against the deployed code:

```
ORD-00003  status=awaiting_payment  method=instapay  req=required  paid=Y  proof=Y  => permits=YES
```

The gate now passes. ORD-00003 is stuck solely because the orchestration that re-asks it did not
exist when its two trigger events fired, and it has already consumed both.

**Legitimate re-triggers available — reported as an explicit operational step, deliberately NOT
executed:**

1. **Operator Confirm** — `POST /fulfillment/orders/{id}/confirm`. A real, permissioned business
   action, not a fabricated event. The gate would now permit it.
2. **A payment-method change** (this task's new trigger) — but only if the method genuinely changes,
   which would alter business data.

**No event was fabricated. No status was written.** Option 1 is the recommended operational step and
requires explicit authorisation this task does not carry.

---

## 15. ORD-00008

**Read-only. Not modified.** Baseline and final state identical, `updated_at`
(`2026-08-21 03:06:27`) unchanged.

Used exactly as the brief directs — as a read-only regression reference for D2-B. It is the only
channel-less order in `ecos_dev`, it is COD, and it resolves `req=none` **through the chain**
instead of through the deleted hardcode. Same outcome, correct reasoning, **no remediation needed** —
the empirical confirmation of the decision record's prediction.

---

## 16. Backend Changes

| # | File | Change |
|---|---|---|
| 1 | `Modules/Commerce/Orders/Domain/Services/PaymentFulfillmentGate.php` | **NEW (221 lines).** The single implementation: chain resolution + the D1-A gate + `permitsAtCreation()` + proof/payment helpers |
| 2 | `Modules/Operations/Fulfillment/Application/Workflows/ConfirmOrderWorkflow.php` | Four private methods replaced by one delegation to the gate; `ConfigurationManager` dep swapped for the gate; 4 now-unused imports removed; two stale docblocks corrected |
| 3 | `Modules/Commerce/Orders/Application/Actions/CreateManualOrderAction.php` | Creation aligned to D1-A; payment block outranks the shipping override; audit event reports the status actually stored; gate injected |
| 4 | `Modules/Commerce/Orders/Application/Actions/CreateOrderAction.php` | Payment guard wired (inert today, stated as such) |
| 5 | `Modules/Commerce/Orders/Application/Actions/ReevaluateOrderFulfillmentAction.php` | Made bidirectional using the existing `ReturnToPaymentWorkflow`; concurrency model untouched |
| 6 | `Modules/Commerce/Orders/Application/Actions/PatchOrderAction.php` | Payment-method change triggers re-evaluation |
| 7 | `Modules/Commerce/Orders/Application/Actions/UpdateOrderAction.php` | Payment-method change triggers re-evaluation; previous method captured pre-write |

**No migration. No schema change. No new enum case. No new permission row. No new role. No new
workflow. No new event type. No change to Preparation, Distribution, the Wave Engine, WooCommerce,
or any Finance module.**

`ConfirmOrderWorkflow`'s stale comments were corrected: it claimed to mirror the creation contract
"EXACTLY" (false since IMPLEMENTATION-001) and asserted `credit_card → 'optional'` while the live
policy says `required` (COD-AUDIT-001 Finding P-2, now closed by removing the per-method claim from
the docblock rather than restating it).

---

## 17. Frontend Changes

The payment-method change UI **already existed** — the inline grid cell — so per the brief it was
wired to the canonical backend behaviour rather than replaced. **No new page, no new visual system,
no policy logic duplicated in React.**

| File | Change |
|---|---|
| `features/orders/components/order-payment-cell.tsx` | Reports the server-decided outcome via the existing DS `useToast`. It compares the **server-returned** status to the previous one and shows nothing when the server made no move — the component predicts nothing |
| `i18n/locales/en/orders.json` | 3 keys under `workspace.paymentCell` |
| `i18n/locales/ar/orders.json` | The same 3 keys, translated |

Without this, a status change caused by a payment-method edit was silent: the grid re-rendered a
different status with no explanation. The cell's stale comment (claiming the requirement is
"re-evaluated at confirmation time") was corrected — it is now re-evaluated on the change itself.

**No hardcoded user-visible strings.** Both locales verified *rendered*, not merely present (§20).

---

## 18. Tests

### 18.1 Results — 7 suites, one gated run

```
OK (115 tests, 379 assertions)          backend, via scripts/test-gate.sh
```

| Suite | Role |
|---|---|
| `OrderPaymentContractImplementation002Test` (**NEW**, 25 tests) | B/C/D/E coverage below |
| `OrderPaymentFulfillmentReevaluationTest` | A — preserved re-evaluation contract |
| `OrderPaymentConfirmationGateTest` | Certified gate contract |
| `PaymentProofLifecycleTest` | E — proof lifecycle |
| `OrderPaymentStateTest` | F — payment-state regression |
| `OrderLifecycleV3SupersessionTest` | F — ADR-042 creation semantics |
| `OrderEditReservationAndPaymentGuardsTest` | F — edit/reservation/payment guards |

Frontend: `npx vitest run src/features/orders` → **43 tests, 2 files, all passing.**

### 18.2 Required coverage, mapped

| Group | Required case | Test |
|---|---|---|
| **A** | record payment / verify proof trigger re-evaluation; blocked gate is a no-op; transaction+lock | preserved suite, unchanged |
| **B** | required + insufficient payment ⇒ `awaiting_payment` | `test_b1` |
| **B** | required + **sufficient payment** + no verified proof ⇒ `awaiting_payment` | `test_b2` |
| **B** | required + sufficient + verified proof ⇒ fulfilment eligible | `test_b7` (full lifecycle), `test_e3` |
| **B** | arbitrary `payment_proof_path` must not satisfy | `test_b3` (+ asserts no `payment_proofs` row is fabricated) |
| **B** | creation cannot bypass via submitted status | `test_b4`, `test_b2` |
| **B** | override is audited and reports the stored status | `test_b5` |
| **C** | NULL channel resolves through the chain | `test_c1`, `test_c2` |
| **C** | no hardcoded NULL ⇒ none | `test_c2` |
| **C** | default policy authoritative; COD stays none | `test_c3`, `test_c4` |
| **C** | channel-less proof-required order parked | `test_c5`; COD unaffected `test_c6` |
| **D-1** | Instapay → COD, incomplete payment ⇒ fulfilment continues | `test_d1` |
| **D-2** | Instapay → COD with an old proof ⇒ no false COD requirement | `test_d2` |
| **D-3/4** | COD → Instapay ⇒ must not remain fulfilment-eligible | `test_d3`, `test_d4` (canonical workflow attribution) |
| **D-5** | Instapay → Bank Transfer ⇒ new policy applies | `test_d5` (see §23.1) |
| **D-6** | change while already fulfilment-eligible (`confirmed`) | `test_d6` |
| **E** | pending / rejected / verified-active / superseded / verified-but-unpaid | `test_e1`–`test_e5`, `test_10b` |
| **F** | COD, Preparation, reservation, concurrency unchanged | full regression above |

### 18.3 Tests rewritten — and exactly why

**Not one assertion was weakened.** Each rewrite is recorded in the test file itself.

| Test | Why it changed | What was done |
|---|---|---|
| `OrderLifecycleV3SupersessionTest::test_case_10` | Its fixture sets no `channel_id`, so `instapay`/`mobile_wallet` resolved `'none'` — the loop was testing **nothing** about proof. It was green for the wrong reason. | **Split along the line ADR-042 always drew:** §4 (payment method is not a *preference*) keeps the original assertion verbatim for `cod`; new `test_case_10b` pins the §3.1 *blocking condition* for proof-required methods **and** asserts the override event exists — the audit being the precondition ADR-042 attaches to permitting it |
| `PaymentProofLifecycleTest::test_10` | Relied on the `channel_id NULL → 'none'` hole: an **unpaid** order advanced on verification alone. D2-B removes the hole. | Fixture paid in full — which is what its assertions always *meant* to set up. **Both assertions unchanged.** New `test_10b` pins the blocked direction the old fixture could not express |
| `OrderEditReservationAndPaymentGuardsTest` (×2) | Reflected on `ConfirmOrderWorkflow::hasVerifiedPaymentProof()`, which moved into the single gate. | Retargeted at `PaymentFulfillmentGate::hasVerifiedProof()` — public, so **no reflection needed at all**. All four assertions byte-identical |

### 18.4 A fixture artifact found, and deliberately not "fixed"

Four tests initially failed for a cause unrelated to this work: a COD order created with **no
submitted status** was parked at `awaiting_payment`. Root cause —
`BrandPolicy::defaultSettings('order')['source_entry_policies']['manual']` still reads
`["pending","awaiting_payment","processing","confirmed"]`, pre-V3 vocabulary whose first
*canonical* member is `awaiting_payment`. So **any brand with no configured policy row defaults its
manual orders to Awaiting Payment.**

This is pre-existing, contradicts ADR-042 §8 (the normalisation migration fixed stored config rows
but not the code default), and is **outside this task's authorisation** — changing it would alter
entry-status behaviour for every unconfigured brand. The fixtures were corrected instead to submit
an explicit status, which is exactly what the real client does
(`order-form-schema.ts`: `status: values.status || 'in_progress'`). Reported in §24.

---

## 19. Static Gates

| Gate | Result |
|---|---|
| **PHPStan** (`phpstan.neon.dist`, 7 touched source files) | **`[OK] No errors`** |
| **Pint** (all 11 touched backend files) | **`PASS 11 files`** |
| **ESLint** (`order-payment-cell.tsx`) | **Clean — no output** |
| **TypeScript** (`tsc -p tsconfig.app.json`) | **23 errors, ZERO in any file this task touched.** Pre-existing across 13 unrelated files (admin configuration, HR, marketing, logistics, engineering, stock-ledger, `manual-order-form.tsx`) |
| **Vite build** | **`✓ built in 10.92s`** |

The one Pint failure encountered was `global_namespace_import` on `\DateTimeInterface` in a helper
of `OrderEditReservationAndPaymentGuardsTest` that this task never edited — the pre-existing failure
IMPLEMENTATION-001 recorded in the same file. It was cleared with a one-line import (zero behavioural
change) rather than reported as an ambiguous gate.

---

## 20. Browser Verification

**Performed on the live dev stack** (Vite `127.0.0.1:5173` → `ecos-dev-app`, code deployed via
`docker cp`, `config:clear` + `route:clear` run). The browser pane was not compositing, so the live
DOM was driven through real React handlers and results read from the rendered grid — the same
technique this codebase's prior sessions established.

| # | Check | Result |
|---|---|---|
| 1 | Order with payment-method change | ✅ ORD-00015, both directions |
| 2 | **Instapay → COD** | ✅ `Awaiting Payment` → **`Confirmed`**, toast: *"Payment requirement cleared — the order continues to fulfilment."* |
| 3 | **COD → Instapay** | ✅ `Confirmed` → **`Awaiting Payment`**, toast: *"Returned to Awaiting Payment — this method requires a verified payment proof."* |
| 4 | Proof-required method, no verified proof | ✅ ORD-00015 created **`awaiting_payment`** despite `status: in_progress` **and paid in full** |
| 5 | Proof-required method **with** verified proof | ⚠️ **Verified read-only, not by UI action** — ORD-00003 evaluates `permits=YES` against deployed code (§4). Doing it in the UI would require verifying a proof on real data |
| 6 | Method change updates UI state | ✅ Status column and method badge both re-render from the invalidated query |
| 7 | Order does not become falsely fulfilment-eligible | ✅ Check 3; and ORD-00015 ends at `awaiting_payment` |
| 8 | COD can progress without payment | ✅ Check 2 — COD reached `Confirmed` with `deposit_amount` 150 of 150 unchanged and no payment posted |
| 9 | Finance proof-verification path | ⚠️ **Not browser-verified** — requires a Finance user; creating users is forbidden. Covered by the seeded RBAC matrix (§9) and `test_a_role_holding_only_proof_verify_can_verify…` |
| 10 | Sales cannot approve its own proof | ⚠️ **Not browser-verified** — same reason. Proven structurally: the SQL join for a role holding both verbs returns zero rows |
| 11 | No cross-tenant proof access | ⚠️ **Not browser-verified** — covered by the certified `PaymentProofController` company scoping (cross-tenant → 404), unchanged by this task |
| 12 | No raw `payment_proof_path` bypass | ✅ Check 4 — the exact audit payload, live |

**i18n verified rendered, in both locales.** With the app switched to Arabic (`dir="rtl"`), the
return toast rendered as
*"أُعيد الطلب إلى بانتظار الدفع — هذه الطريقة تتطلب إثبات دفع مُعتمَد."* with a programmatic
assertion of **no Latin-script leakage**, and the status column read *"في انتظار الدفع"*. The
environment language was restored to English afterwards.

**No irreversible financial action was taken to obtain any of this.** No payment was recorded, no
proof uploaded, verified or rejected.

---

## 21. Data Safety

Full baselines were captured **before any write** (orders, `payment_proofs`, `order_events` count,
proof role grants).

| Item | Baseline | Final | Verdict |
|---|---|---|---|
| ORD-00001 … ORD-00014 | 14 rows | **Identical — status, method, deposit and `updated_at` all unchanged** | ✅ untouched |
| **ORD-00003** | `awaiting_payment`, `updated_at 2026-08-19 22:20:30` | identical | ✅ read-only |
| **ORD-00008** | `awaiting_payment`, `updated_at 2026-08-21 03:06:27` | identical | ✅ read-only |
| `payment_proofs` | 4 rows | **4 rows** | ✅ none created, verified, rejected or deleted |
| Proof role grants | **zero roles** | 5 roles per the approved matrix | ✅ **expected** — the authorised RBAC seeding |
| Orders count | 14 | 15 | ⚠️ **declared:** ORD-00015, the browser-verification order |

**ORD-00015 is the one business-data addition, and it is declared rather than incidental.** It was
created because browser verification of a *creation* contract cannot be performed without creating,
and creating a dedicated order was the alternative to mutating a pre-existing one. It carries no
payment beyond its own `deposit_amount`, no proof record, and it rests at `awaiting_payment`. It can
be cancelled or deleted at the Owner's discretion.

No `Order.status` was written by hand. No fake payment or proof was created. No financial
transaction was posted. No test user was created.

---

## 22. Regression

| Area | Evidence |
|---|---|
| **COD** | Unchanged at policy, gate, creation and browser level (§12) |
| **Preparation** | **Zero changes.** `fulfilmentEligible()` still `['in_progress','confirmed']`; no session policy, list or file touched |
| **Inventory reservation** | `ConfirmOrderWorkflow`'s reservation branch untouched; `decidesAvailabilityAtCreation()` unchanged, so a parked order still takes its availability decision at creation. Reservation *release* on a return is `ReturnToPaymentWorkflow`'s pre-existing behaviour (§7) |
| **Concurrency** | Lock/transaction model unchanged (§10) |
| **Certified payment gate** | `OrderPaymentConfirmationGateTest` + `OrderPaymentFulfillmentReevaluationTest` pass unchanged |
| **Payment state derivation** | `OrderPaymentStateTest` passes unchanged |
| **Full ERP suite** | **NOT RUN** — the brief forbids it absent a concrete failure, and none occurred |

---

## 23. Known Limitations

### 23.1 STOP CONDITION 5 — TRIGGERED, REPORTED, NOT WORKED AROUND

> **`payment_proofs` cannot express which payment method a proof evidences.**

The table has `order_id`, `state`, storage, attribution, `superseded_at`, `replaces_proof_id` — and
**no payment-method column**. `UploadPaymentProofAction` captures none either. The proof contract is
therefore **order-scoped**: "does this order carry accepted evidence of payment?" — not
method-scoped.

**Consequence.** An order paid by instapay with a verified proof, whose method is then changed to
`bank_transfer` (also proof-required), continues to satisfy the gate on evidence raised for a
different payment rail.

**Why this was not resolved here.** The brief is explicit on both horns:

- *"Do not treat an old proof for another payment method as automatically satisfying the new method
  **unless the existing proof contract explicitly establishes that relationship**"* — it does not; and
- *"If the existing `payment_proofs` schema cannot express association with the current payment
  method safely: **STOP.** Do not invent a new schema relationship without approval."*

Making proofs method-scoped requires a column, therefore a migration — which is also STOP condition
8 (a migration not identified by the approved decision record). **No schema relationship was
invented, no proof was auto-superseded on a method change** (that would silently rewrite a
historical financial fact), and the certified order-scoped semantics were preserved **exactly**.

**Blast radius is bounded and does not touch either critical scenario.** It requires
proof-required → *proof-required*, on an order already paid in full **and** carrying an active
verified proof. Both approved critical scenarios (instapay ↔ COD) are unaffected, because COD
requires no proof at all. `test_d5` documents the current behaviour explicitly and names this STOP.

**The unresolved question for the Owner:** *when an order's payment method changes between two
proof-required methods, does the existing verified proof remain valid evidence for the new method,
or must a fresh proof be raised?* If a fresh proof is required, a schema relationship and a
migration must be approved first.

### 23.2 Other limitations

1. **The grid's proof column can assert something the payment contract rejects.**
   `order-column-defs.tsx:73` renders a green *"Proof Received"* badge from a non-empty
   `payment_proof_path` alone — ORD-00015 (`payment_proof_path: "x"`) displays it. Not a security
   hole (the gate ignores the column entirely) but a UI-truthfulness gap. Out of scope: the frontend
   mandate here was the payment-method change UI.
2. **`POST /api/orders` can still create `confirmed`** — an ADR-042 §3 entry-contract gap, not a
   payment gap (that path carries no payment method). Preserved deliberately.
3. **Returning an order releases its reservation** — `ReturnToPaymentWorkflow`'s existing behaviour,
   not modified (§7).
4. **Orders past `ready_for_dispatch` are never pulled back** by a method change; the workflow guard
   blocks it and the result is a no-op.
5. **WooCommerce gateway ids are outside the proof-policy vocabulary** (§13) — pre-existing.
6. **Not deployed to production.** Deployed to `ecos-dev-app` via `docker cp` for verification only.
   Not committed.
7. **The full ERP regression suite was not run** (§22).

---

## 24. Remaining Follow-ups

| # | Item | Why it is a follow-up |
|---|---|---|
| 1 | **Owner decision on §23.1** (proof ↔ payment-method association) | Requires a schema relationship + migration approval |
| 2 | **Stale code default:** `BrandPolicy::defaultSettings('order')['source_entry_policies']['manual']` = `["pending","awaiting_payment","processing","confirmed"]` — pre-V3 vocabulary that silently defaults unconfigured brands to `awaiting_payment`, contradicting ADR-042 §8 | Changes entry-status behaviour platform-wide; outside this authorisation (§18.4) |
| 3 | **Proof column UI truthfulness** (§23.2 item 1) | Display change; needs a decision on what the column should read from |
| 4 | **`POST /api/orders` entry-status clamp** | ADR-042 §3 question, not a payment question |
| 5 | **Creation-time proof → `payment_proofs` row** | A receipt attached at creation still writes only `payment_proof_path`, so it never reaches Finance's verify queue. Would use the existing `uploaded` state — no new architecture — but is a workflow addition beyond the approved contract |
| 6 | **ORD-00003 operational re-trigger** (§14) | Needs explicit authorisation |
| 7 | **WooCommerce proof-policy vocabulary** (§13) | Business question |

---

## 25. Final Verdict

# PAYMENT CONTRACT ALIGNMENT — IMPLEMENTED / VERIFIED / BROWSER VERIFIED

Both Owner decisions are implemented against a **single** implementation of the control. D1-A is
enforced at creation, at confirmation, on payment, on proof verification and — newly — on payment
method change, in both directions. D2-B removes the `channel_id IS NULL` hardcode without inventing
a default, a source, or a channel. The certified IMPLEMENTATION-001 work is preserved intact: its
entry point, its concurrency model, its idempotency and its AND-based gate are unchanged, and the
previous OR-gate was not restored.

| Claim | Status |
|---|---|
| Backend focused tests — 115 / 379 across 7 suites | ✅ **VERIFIED** |
| Frontend tests — 43 | ✅ **VERIFIED** |
| PHPStan · Pint · ESLint · Vite build | ✅ **VERIFIED** |
| TypeScript — no new errors | ✅ **VERIFIED** (23 pre-existing, none in touched files) |
| Browser — creation control, both method-change directions, EN + AR | ✅ **BROWSER VERIFIED** |
| Browser — checks 5, 9, 10, 11 | ⚠️ **NOT BROWSER VERIFIED** — each blocked by an explicit prohibition; covered by tests/config, stated in §20 |
| ADR-042 §3.1 amendment | ✅ **IMPLEMENTED** |
| RBAC seeded, separation of duties live | ✅ **VERIFIED** |
| Data safety — 14 pre-existing orders and all proofs untouched | ✅ **VERIFIED** (ORD-00015 declared, §21) |
| Proof ↔ payment-method association | 🛑 **BLOCKED — STOP 5 (§23.1)** |
| Full ERP regression | ❌ **NOT RUN** — not required, no failure occurred |

**Explicitly not claimed:** FULL ERP CERTIFIED · WooCommerce certified · payment E2E certified ·
deployed to production · committed.

**NOT COMMITTED. NOT DEPLOYED TO PRODUCTION. ORD-00003 AND ORD-00008 UNTOUCHED. NO FABRICATED
EVENTS. NO FAKE USERS. NO IRREVERSIBLE FINANCIAL ACTION.**

**STOP — awaiting Owner direction on §23.1 and authorisation to commit.**
