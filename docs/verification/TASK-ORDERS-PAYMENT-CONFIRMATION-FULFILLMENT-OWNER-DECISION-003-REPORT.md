# TASK-ORDERS-PAYMENT-CONFIRMATION-FULFILLMENT-OWNER-DECISION-003 — REPORT

**Status:** OWNER DECISION ONLY — **NO IMPLEMENTATION**
**Date:** 2026-08-21
**Branch:** `develop`
**Source of truth:** `TASK-ORDERS-PAYMENT-CONFIRMATION-FULFILLMENT-DECISION-002-REPORT.md`
and `TASK-ORDERS-PAYMENT-CONFIRMATION-FULFILLMENT-IMPLEMENTATION-001-FINAL-REPORT.md`

**No code, ADR, migration, schema, API, RBAC, test, permission or business data was changed.
No user was created. No payment was recorded. No proof was verified. No order status was
changed. `RbacSeeder` was not run. ORD-00003 was read only. Nothing was committed.**

---

## 1. Purpose and Standing

This task obtains two explicit Owner decisions and nothing else.

| | State entering this task | State leaving this task |
|---|---|---|
| **D1** — creation-time payment-proof semantics | Open. DECISION-002 §27 raised it as a business question the audit could not answer | **Recommended: D1-A (MANDATORY FINANCIAL CONTROL).** **PENDING explicit Owner approval** |
| **D2** — `channel_id IS NULL` policy resolution | Audited conclusion D2-B, blocked on authorisation | **CONFIRMED by the Owner in this brief.** Authorisation granted |

The completed Fulfillment Re-evaluation implementation is **not reopened**. Nothing established
by the two source reports is reinterpreted — every statement below is either carried forward
from them or backed by fresh, direct repository/database evidence, which is cited where it is
new.

---

## 2. D1 — The Owner Question, Answered

### 2.1 The recommendation

# D1-A — MANDATORY FINANCIAL CONTROL

> A payment method whose brand `payment_proof_policy` is `required` **blocks fulfilment
> eligibility** until **both** of the following hold:
>
> **(a)** payment is sufficient — `deposit_amount >= total`; **and**
> **(b)** an **active, verified** `payment_proofs` record exists
> (`superseded_at IS NULL` and `state = verified`).
>
> This is evaluated at **every point where an order would become or remain
> fulfilment-eligible — including creation.** Until both facts hold, the order's lifecycle
> status is `awaiting_payment`.

One rule, one proof source, one combinator, evaluated everywhere. **STOP condition 1 is not
triggered.**

### 2.2 Why D1-B is not selectable

This is the decisive point, and it is not a matter of preference.

D1-B ("a fully paid order may become fulfillment-eligible without verified proof") is **exactly
the pre-hardening OR-based gate** that was removed under **already-approved Decisions 1 + 3**
(`TASK-…-DECISION-AUDIT-003` → implemented and certified 2026-08-21). The shipped gate reads:

```php
// ConfirmOrderWorkflow.php:110-131 — as certified
if ($this->paymentProofRequirement($order, $method) !== 'required') {
    return true;
}
return $this->isPaidInFull($order) && $this->hasVerifiedPaymentProof($order);
```

Selecting D1-B would require **reverting** that AND to an OR — i.e. restoring the state in which
"a fully-paid instapay order confirmed with **no proof at all**" (IMPLEMENTATION-001 §4.2). That
is a direct weakening of proof verification, which **triggers STOP condition 7 of this very
task**.

**Therefore D1-A is the only option consistent with the Owner's own existing approvals.** The
Owner has already ruled, on 2026-08-21, that at confirmation `required` means payment **AND**
verified proof. D1 asks whether that same meaning extends to the creation path. Answering "yes"
keeps one rule; answering "no" would mean the business deliberately applies a *stricter* standard
to orders that happen to pass through `awaiting_payment` than to orders that never do — which is
the inconsistency this task exists to remove.

**This choice was not made on implementation convenience.** D1-A is the *more* expensive option:
it changes operational throughput (§3.7) and, by the trigger declared in advance in
DECISION-002 §10, it escalates D1 to a **go-live BLOCKER** (§5). D1-B would be cheaper and would
require no ADR amendment at all.

### 2.3 D1-C is not needed

No third rule is required. The state (`awaiting_payment`), the proof source (`payment_proofs`),
the combinator (AND) and the trigger (`ReevaluateOrderFulfillmentAction`) all already exist and
are already certified. D1-A composes them; it invents nothing.

---

## 3. D1-A — Consequences (all 16 required items)

### 3.1 Confirmation behaviour — **UNCHANGED**

The confirmation gate **already implements D1-A exactly**. D1-A ratifies the shipped code; it
requires **no change** to `ConfirmOrderWorkflow::paymentPermitsConfirmation()`,
`isPaidInFull()` or `hasVerifiedPaymentProof()`. The gate call site
(`ConfirmOrderWorkflow.php:82`) is unchanged.

### 3.2 Manual order creation — **CHANGES** (the substance of D1)

`POST /api/orders/manual` with a proof-required `payment_method_manual` must store
`awaiting_payment` **regardless of the submitted status and regardless of
`payment_proof_path`**. Today (`CreateManualOrderAction.php:310-313`) any non-empty
`payment_proof_path` skips `awaiting_payment` and PICK-AND-STAY then honours `in_progress`.

`payment_proof_path` **stops being a lifecycle input** and remains a display + audit field
(`proof_uploaded` `OrderEvent`, `CreateManualOrderAction:595-600`). This is consistent with the
already-certified contract that proof is not payment
(`OrderPaymentStateTest::test_payment_proof_alone_does_not_make_an_order_paid`, B11.9) and with
TASK-PAYMENT-PROOF-LIFECYCLE-001's declaration that the single-path column is **superseded**.

**Blocked on the ADR-042 §3.1 amendment (§3.13). Not implemented by this task.**

### 3.3 Normal order creation — **CHANGES, on the entry-status axis only**

`POST /api/orders` → `CreateOrderAction` (`routes/api.php:560`, same
`permission:sales.orders.create`) carries **no payment method at all**, so the payment rule is
**inert** there: the gate's empty-method short-circuit (`ConfirmOrderWorkflow.php:114-117`)
already returns non-blocking. D1-A therefore imposes no payment behaviour on this path.

What is real on this path is the **entry-status** gap: `StoreOrderRequest.php:29` admits all
eleven statuses, including `confirmed`, which ADR-042 §3 says "is **never** offered as an entry
status". The `Order` P9 guard does not catch it — it is registered on `static::updating` only
(`Order.php:146`), so creation is exempt by construction. Rule on this in the same amendment.

### 3.4 Payment recording — **UNCHANGED**

`RecordOrderPaymentAction` writes the money, then calls `ReevaluateOrderFulfillmentAction` after
the financial fact persists. Under D1-A, recording payment in full on a proof-required order
**does not advance it** unless a verified proof also exists — which is already the shipped and
tested behaviour (`test_full_payment_is_retained_when_the_gate_still_blocks`). A blocked gate
remains a **no-op, not an error**: the payment stays committed.

### 3.5 Payment-proof verification — **UNCHANGED at the action; one addition scoped to the
implementation task**

`VerifyPaymentProofAction` → `ReevaluateOrderFulfillmentAction` is unchanged. Under D1-A,
verification is the **second of two required facts**, never a substitute for the first.

One additional item belongs to the eventual implementation task, **not to this decision**: a
receipt attached at creation today writes **no `payment_proofs` row**, so it never reaches
Finance's queue. Under D1-A the creation-time attachment should become a real `payment_proofs`
row in state `uploaded`. This introduces **no new state and no new proof source** — `uploaded`
is an existing state of an existing table. **STOP condition 3 and 10 are not triggered.**

### 3.6 Finance separation of duties — **STRENGTHENED; RBAC untouched**

Today the separation is **structurally skipped for every creation-time proof**: no
`payment_proofs` row is produced, so `sales.orders.proof_verify` is never reached. Finance is
not bypassed by a permission hole — the artefact it reviews is never created. Under D1-A every
proof-required order must pass through Finance's verify verb.

**No RBAC change of any kind.** The approved matrix (upload → `sales`, `sales-manager`,
`sales-representative`; verify + reject → `company-admin`, `finance-manager`; no role holds
both) stands exactly as certified. **STOP condition 7 is not triggered** — D1-A strengthens
verification, it does not weaken it.

### 3.7 Fulfillment eligibility — **list UNCHANGED; timing changes**

`OrderStatus::fulfilmentEligible()` remains the closed list `['in_progress', 'confirmed']`
(ADR-042 §7). What changes is **when** a proof-required order enters it: only after payment
**and** verified proof, via the canonical re-evaluation.

Verified as non-disruptive: `AwaitingPayment` is already in `decidesAvailabilityAtCreation()`
(`OrderStatus.php:130-136`), and is excluded from **both** `yieldsToStockBlock()` and
`advancesToInProgressOnReservation()` (`:176-211`). So a proof-required order parked at
`awaiting_payment` **still takes its availability decision at creation** and still learns whether
stock exists, while its lifecycle status is preserved in both directions. **Reservation timing is
unchanged** — ADR-027 is not touched.

**The honest operational cost:** every instapay / bank-transfer / mobile-wallet order parks until
Finance verifies. That is the substance of what D1-A commits the business to.

### 3.8 Preparation eligibility — **UNCHANGED. No Preparation change of any kind.**

No Preparation code, eligibility list, session policy or configuration is affected. Preparation
continues to collect exactly `['in_progress', 'confirmed']`. **STOP condition 5 is not
triggered.**

### 3.9 COD behaviour — **UNCHANGED. ZERO impact.**

`cod` and `cash` resolve `'none'` in both authorities — the live brand policy
(`{"cod":"none","cash":"none",…}`) and the code default (`BrandPolicy.php:159-165`). Requirement
`'none'` satisfies the gate at `ConfirmOrderWorkflow.php:121` **before payment is ever
considered**, so a COD order still confirms unpaid and still enters fulfilment immediately. This
is the approved COD contract (COD-AUDIT-002 §14) and D1-A does not touch it.

### 3.10 Credit / payment-method behaviour

| Method | Live brand policy | Under D1-A |
|---|---|---|
| `cod`, `cash` | `none` | Non-blocking — unchanged |
| `credit_card` | `required` (live) — but the `ConfirmOrderWorkflow` docblock at `:124` still says `optional` | Whatever the **policy** resolves governs. The code already reads the policy, so behaviour is correct; **the stale docblock must be corrected** (COD-AUDIT-001 Finding P-2, still open) |
| `instapay`, `bank_transfer`, `mobile_wallet` | `required` | Blocked until paid in full **and** verified proof |
| Empty / unrecognised method key | `'none'` by short-circuit / key-miss | Unchanged |

### 3.11 WooCommerce implications — **NONE MATERIAL**

D1 concerns `POST /api/orders/manual`. Imported orders take their status from
`WooCommerceOrderStatusTranslator` and never call `resolveManualOrderStatus()`. They always
carry `channel_id` (`WooCommerceOrderImporter.php:366`), and their `payment_method` is the raw
gateway id (`:401` — `bacs`, `ppcp`, `stripe`), which is not a key in the ECOS
`payment_proof_policy` vocabulary, so the requirement resolves `'none'` **by key-miss**
regardless of anything D1 decides.

**Standing ambiguity, reported and deliberately not resolved, no mapping invented:** a Woo order
paid by bank transfer (`bacs`) is proof-not-required while the equivalent manual order
(`bank_transfer`) is proof-required. Whether that asymmetry is intended is a separate business
question. **WooCommerce semantics remain defined — inert by key-miss, exactly as today — so STOP
condition 9 is not triggered.**

### 3.12 Security implications — **the creation-time twin of an already-closed vector is closed**

IMPLEMENTATION-001 §17.1 closed this exact hole at `POST /orders/{order}/verify-payment`. D1-A
closes it at creation. `payment_proof_path` — validated only as `nullable|string|max:500`, with
no storage, existence, MIME or tenant check — stops being able to decide a lifecycle status
**anywhere in the system**.

No new privilege is granted, no permission is weakened, no `Gate` or policy check is bypassed,
and tenant isolation is untouched (`company_id` is derived from the authenticated actor,
`CreateManualOrderAction.php:134`).

### 3.13 Must ADR-042 §3.1 be amended? — **YES, and it must land FIRST**

§3.1 currently authorises today's behaviour, so the code cannot change before the ADR does. The
amendment must:

1. Define what "**no proof was supplied**" means under the post-2026-08-19 model — i.e. against
   `payment_proofs`, not `orders.payment_proof_path`.
2. State that, because **a canonical proof cannot exist before the order exists**
   (`UploadPaymentProofAction` is reachable only via `POST /orders/{order}/payment-proofs`), a
   proof-required method is **always** created `awaiting_payment`.
3. Rule on the two adjacent entry-contract gaps found by the audit:
   - `POST /api/orders` accepting `confirmed` as an entry status (§3.3);
   - the shipping `status_override` (`on_hold`) at `CreateManualOrderAction.php:139`, which
     **displaces** the §3.1 override — contradicting "the **one** sanctioned exception" — and
     leaves the override `OrderEvent` reporting a false `stored_status`.

**No ADR was edited by this task.**

### 3.14 Does `ReevaluateOrderFulfillmentAction` remain valid? — **YES, entirely**

It becomes **more** load-bearing, not less. Under D1-A every proof-required order transits
`awaiting_payment`, so the canonical re-evaluation becomes the **only** route by which such an
order advances. Its single-transaction + `lockForUpdate()` + status-re-read-inside-the-lock model,
its idempotency guarantees and its "blocked gate = no-op, not error" semantics are **untouched**.
**STOP condition 6 is not triggered.**

### 3.15 Do certified tests need reinterpretation? — **Yes, three items — all additive or
re-fixturing. Nothing is weakened.**

Verified directly: **no existing test asserts the bypass.** A search of `backend/tests` for a
creation-with-`payment_proof_path` status assertion returns nothing; the only adjacent comment
(`OrderPaymentConfirmationGateTest.php:130`) documents the *correct* direction. So no certified
assertion has to be reversed to accommodate D1-A.

| Item | Why | Action |
|---|---|---|
| `OrderLifecycleV3SupersessionTest::test_case_10` | `payload()` (`:55-71`) sets **no `channel_id`**, so `instapay` resolves `'none'` and the entry status survives for the wrong reason | Add a channel-bound counterpart. Existing assertion stays valid for the channel-less shape |
| `PaymentProofLifecycleTest::test_10` | **D2-driven, not D1.** Fixture is unpaid + channel-less + instapay and asserts the order *advances* | Re-fixture (add a channel, or add payment). **Do not weaken the assertion** |
| `entry_status_overridden_by_payment_proof_policy` | **Zero coverage anywhere** — the sanctioned exception of an Approved ADR is asserted by no test | Add coverage |

### 3.16 Is a one-time remediation required for existing orders? — **NO**

Verified read-only against `ecos_dev`:

```sql
SELECT order_number,status,payment_method_manual,payment_method,total,deposit_amount
  FROM orders
 WHERE status IN ('in_progress','confirmed')
   AND COALESCE(payment_method_manual,payment_method,'') NOT IN ('cod','cash','');
-- 0 rows
```

**No order is fulfilment-eligible under a proof-required method.** Every `in_progress` order is
`cod`. No order carries a `payment_proof_path` at all (14/14 NULL) — the bypass is unused, not
merely unexploited. ORD-00003 / 00004 / 00005 are already correctly parked at `awaiting_payment`.

**Zero rows require remediation. STOP condition 8 is not triggered.**

---

## 4. D2 — Owner Confirmation

### 4.1 The confirmed rule

# D2-B — RESOLVE NULL THROUGH THE DOCUMENTED POLICY CHAIN

> When `channel_id` is NULL, payment-policy resolution **MUST continue through the documented
> fallback/default chain** rather than immediately resolving to `'none'`. The system must not
> silently interpret **NULL channel = no payment requirement**.

**Confirmed by the Owner in this brief.** The authorisation that DECISION-002 §27 (STOP 6) was
waiting for is hereby granted; implementation remains a separate task.

The chain is the one already documented and already in code — **no new default, no new policy,
no change to policy storage**:

1. **Channel scope** → `channels.brand_id` → `config_brand_policies` (unchanged, still first)
2. **Company scope** → `ConfigurationManager::getCompanySettings($order->company_id, 'order')` —
   exists today, returns `[]` until configured
3. **System default** → `BrandPolicy::defaultSettings('order')['payment_proof_policy']` — already
   the authority for every brand with no policy row (`ConfigurationManager.php:41`)

The decisive fact carried forward from DECISION-002: `'none'` today is **not** the system default
— it **short-circuits a default that already says `required`**. A brand with no policy row at all
still gets `instapay => required`. The only way to reach `'none'` for instapay is to have **no
brand**. **STOP condition 10 is not triggered.**

### 4.2 D2-B — Consequences (all required items)

| Item | Consequence |
|---|---|
| **Channel-less manual orders** | Requirement resolves down the chain instead of `'none'`. Affects **only** `instapay` / `bank_transfer` / `mobile_wallet` |
| **Channel-less normal orders** (`POST /api/orders`) | **Unaffected** — this path carries no payment method, so the empty-method short-circuit (`:114-117`) returns non-blocking before any policy lookup |
| **Brand policy** | **Unchanged.** Still first in the chain, still authoritative whenever a channel resolves. Storage untouched |
| **Company fallback** | Uses the existing `getCompanySettings()` API. Inert until configured, but structurally correct. `company_id` is populated on **100%** of live orders (0 NULL of 14) |
| **Default policy** | `BrandPolicy::defaultSettings('order')` — already the existing fallback. **Nothing invented** |
| **COD** | **ZERO impact.** `cod` = `'none'` in both the live policy and the code default |
| **instapay** | `'required'` — a channel-less instapay order can no longer advance unpaid |
| **bank_transfer** | `'required'` — same |
| **mobile_wallet** | `'required'` — same |
| **credit_card** | Code default `optional`, live policy `required`. Whatever resolves governs; the stale `:124` docblock must be corrected (§3.10) |
| **WooCommerce** | **Unaffected.** Import always sets `channel_id` (`:366`), so the NULL branch is unreachable at import; and gateway ids miss the policy keys regardless. **Semantics remain defined — STOP 9 not triggered** |
| **Existing orders** | **ORD-00008 is the only channel-less order** (1 of 14) and it is `cod` → `'none'` under both the old and the new rule. **0 orders change outcome** |
| **Remediation required?** | **NO.** No migration, no schema change, no data rewrite. **STOP 8 not triggered** |
| **Effect on the certified re-evaluation path** | **The certified contract is untouched.** `paymentPermitsConfirmation()`, `isPaidInFull()`, `hasVerifiedPaymentProof()`, the lock, the transaction boundary and idempotency all stay exactly as certified. Only the NULL branch of `paymentProofRequirement()` changes. One certified test — `PaymentProofLifecycleTest::test_10` — needs **re-fixturing, not weakening**. **STOP 6 not triggered** |

### 4.3 The standing structural exposure D2-B mitigates but does not eliminate

Recorded so it is not mistaken for solved: `channel_id` remains **mutable to NULL by an ordinary
order edit** — `UpdateOrderAction.php:98` writes it from the DTO on every update, and
`OrderDTO::nullableString()` coerces a missing or empty key to `null`, with
`UpdateOrderRequest.php:27` permitting it. ORD-00008 demonstrates this live: it fired the §3.1
override with `instapay` at creation (only reachable via a channel→brand lookup), then an
`order_updated` event, and is now channel-less.

D2-B removes the **security consequence** (the requirement no longer disappears with the
channel). It does not stop the channel itself from being nulled. The durable fix — resolving the
requirement once at creation and storing it on the order — **requires a migration**, is therefore
outside both this decision and STOP condition 4, and is **explicitly not recommended here**.

---

## 5. Go-Live Classification — the direct consequence of choosing D1-A

DECISION-002 §10 declared this trigger **in advance of the choice**, so this is not a post-hoc
escalation:

> "If `payment_proof_policy: required` is intended at go-live as an **enforced financial
> control** … then D1 **is a BLOCKER**, because that control is not enforced on the path most
> orders take."

**Selecting D1-A pulls that trigger.**

| | Classification | Reasoning |
|---|---|---|
| **D1** | **BLOCKER** (conditional on Owner approval of D1-A) | A mandatory financial control that is unenforced on the primary creation path is not a control. The creation-path alignment must ship before go-live |
| **D2** | **HIGH-RISK FOLLOW-UP — release-coupled** | 0 live orders change outcome and no remediation is needed, but deploying the hardening is precisely what converts the bypass from latent to live. **Must ship in the same release as TASK-…-IMPLEMENTATION-001** |

Stated plainly so the trade-off is visible: **had the Owner selected D1-B, D1 would have remained
a HIGH-RISK FOLLOW-UP and no ADR amendment would have been needed.** D1-A is the more demanding
choice, and it is the one the evidence and the Owner's own prior approvals support.

---

## 6. ORD-00003 — Read-Only, Unchanged

**Not modified. Not re-triggered. No payment recorded. No proof re-verified. No status
changed.**

The established finding stands and no new evidence contradicts it:

> ORD-00003 is stuck because the old system did not re-evaluate fulfilment after payment /
> proof events.

Carried forward from DECISION-002 §20: `channel_id` is **set** (ECOS Main Store), its brand
policy correctly says `instapay => required`, it is paid in full (10 000 / 10 000) and carries
an active **verified** proof. It therefore **passes** the hardened gate; it is stuck only
because the repaired orchestration is not deployed, and it has already consumed both trigger
events.

**It is neither a D1 nor a D2 data defect.** Clearing it requires a one-time operational
re-trigger **after** deployment, which this task does not authorise and did not perform.

---

## 7. RBAC Boundary

**`RbacSeeder` was not run. No user was created. No role was assigned. No grant was modified.**

The approved RBAC design is untouched by both decisions. D1-A **needs no RBAC change** — every
permission already exists and is already granted; its problem is that the creation path never
*produces* the artefact Finance reviews. D2-B **needs no RBAC change** — it changes how a
requirement is resolved, not who may act.

One RBAC-adjacent observation, reported and **not** acted on: `sales.orders.update` can null
`channel_id` (§4.3). D2-B removes the security consequence without any permission change; whether
an order editor should be able to do it at all is a separate question for after implementation.

---

## 8. STOP Conditions

| # | Condition | Verdict |
|---|---|---|
| 1 | Owner decision cannot be represented as one unambiguous business rule | **Not triggered.** Stated as a single rule in §2.1 |
| 2 | Contradicts an approved ADR other than ADR-042 §3.1 | **Not triggered.** Checked ADR-005 (no payment coupling), ADR-015, ADR-020 (snapshot triggers at confirm only), ADR-023 (customer snapshot is status-independent, written at creation regardless), ADR-027 (already lists `awaiting_payment` in its `WarehouseAssigned` retry trigger, §498), ADR-042 §3/§7 |
| 3 | Requires a new payment state | **Not triggered.** `awaiting_payment` and `payment_proofs.uploaded` both already exist |
| 4 | Requires a migration not already identified | **Not triggered.** Neither decision needs one. The migration-requiring variant (§4.3) is explicitly **not** recommended |
| 5 | Requires changing Preparation | **Not triggered.** Eligibility list and session policy unchanged (§3.8) |
| 6 | Requires changing the certified concurrency model | **Not triggered.** Lock, transaction boundary and idempotency untouched (§3.14, §4.2) |
| 7 | Requires weakening proof verification or RBAC | **Not triggered by D1-A / D2-B — both strengthen.** **This condition is precisely what excludes D1-B** (§2.2) |
| 8 | Existing business data requires irreversible remediation | **Not triggered.** 0 rows for D1 (§3.16); 0 outcome changes for D2 (§4.2) |
| 9 | WooCommerce semantics become undefined | **Not triggered.** They remain defined and inert by key-miss. One **standing** ambiguity reported, no mapping invented (§3.11) |
| 10 | Requires inventing a new payment-policy source | **Not triggered.** Both reuse existing sources only (§4.1) |

**No STOP condition is triggered. No workaround was applied.**

Discharge of the prior audit's stops: DECISION-002 §27 raised **STOP 1** and **STOP 8** (business
ruling on §3.1 + authorisation to change lifecycle semantics) and **STOP 6** (authorisation to
touch a certified file). This Owner task **answers all three** — STOP 6 outright, STOP 1/8
subject to the explicit approval the Final Stop awaits.

---

## 9. REQUIRED OWNER DECISION TABLE

| Decision | Owner Choice | Approved? | ADR Amendment Required | Implementation Required | Migration | Schema Change | API Change | RBAC Change | Business Data Change | Go-Live Classification |
|----------|--------------|-----------|------------------------|-------------------------|-----------|---------------|------------|-------------|----------------------|------------------------|
| **D1 — creation-time payment proof** | **MANDATORY FINANCIAL CONTROL** (D1-A) | **PENDING — awaiting explicit Owner approval** | **YES** — ADR-042 §3.1, must land first | **YES** — separate task, not authorised here | **NO** | **NO** | **NO** — no contract change; the `status` value returned for proof-required methods changes | **NO** | **NO** — 0 rows require remediation | **BLOCKER** (direct consequence of D1-A; pre-declared in DECISION-002 §10) |
| **D2 — `channel_id` NULL** | **RESOLVE NULL THROUGH DOCUMENTED POLICY CHAIN** (D2-B) | **YES — confirmed by Owner in this brief** | **NO** | **YES** — separate task, not authorised here | **NO** | **NO** | **NO** | **NO** | **NO** — 0 orders change outcome | **HIGH-RISK FOLLOW-UP** — must ship in the same release as IMPLEMENTATION-001 |

---

## 10. Implementation Preview — **NOT IMPLEMENTED, NOT CREATED**

A preview of the eventual **single combined** implementation task. **Nothing below was done, and
the implementation task file was deliberately not created.**

| # | Scope item | Shape | Not-implemented note |
|---|---|---|---|
| 1 | **ADR-042 §3.1 amendment** | Define "proof supplied" against `payment_proofs`; state that a proof-required method is always created `awaiting_payment`; rule on the two adjacent entry-contract gaps (§3.13) | **Must land first.** No ADR was edited |
| 2 | **Creation-path alignment** | `CreateManualOrderAction::resolveManualOrderStatus()` stops reading `payment_proof_path` as a lifecycle input; `payment_proof_path` retained as display + audit; creation-time attachment becomes a `payment_proofs` row in the **existing** `uploaded` state so it reaches Finance | No new state, no new source, no migration |
| 3 | **`channel_id` NULL policy-chain resolution** | Remove the literal `return 'none';` from `ConfirmOrderWorkflow::paymentProofRequirement()` and apply the same resolution at `CreateManualOrderAction.php:65-66`, so one rule has one answer | Reuses `getCompanySettings()` + `BrandPolicy::defaultSettings('order')`. No new policy source |
| 4 | **Test coverage for both behaviours** | Add: channel-bound proof-required creation; the `entry_status_overridden_by_payment_proof_policy` event (**currently zero coverage**); channel-less proof-required confirmation. Re-fixture `PaymentProofLifecycleTest::test_10` and add a channel-bound counterpart to `OrderLifecycleV3SupersessionTest::test_case_10` | **Re-fixture, never weaken.** No test was added or modified by this task |
| 5 | **Regression verification of the certified re-evaluation path** | Re-run the six payment/proof suites (79 tests / 222 assertions) plus the adjacent creation suites; confirm the gate contract, lock, transaction and idempotency are unchanged | Use `GATE_WAIT=2400 ./scripts/test-gate.sh`; `route:clear` first. **No regression was run** |
| 6 | **Browser verification** | Verify over HTTP on the dev stack: proof-required manual creation parks at `awaiting_payment`; verify-then-pay advances; COD unchanged. Requires deploying the code (`docker cp`) — the dev app still runs the old build | **Not browser verified.** Would require creating business data, which is forbidden here |
| 7 | **ORD-00003 — read-only verification only** | Confirm post-deployment that it still holds paid-in-full + active verified proof, and that it advances **only** via an explicitly authorised one-time re-trigger | **Read-only. Do not re-trigger, pay, verify or change status without separate authorisation** |

**Sequencing constraint:** items 1 → 2 must be strictly ordered (the ADR authorises the code).
Item 3 is independent of items 1–2 and, per §5, **must ship in the same release as
IMPLEMENTATION-001**.

---

## 11. Final Stop

**Nothing was implemented. No ADR was edited. No code, test, permission, schema, migration, API
or business data was changed. `RbacSeeder` was not run. No user was created. ORD-00003 was read
only. Nothing was committed. The implementation task file was not created.**

**Awaiting:**

1. **Explicit Owner approval of D1 — MANDATORY FINANCIAL CONTROL (D1-A)**, which by its own
   pre-declared trigger classifies D1 as a **go-live BLOCKER** and requires the ADR-042 §3.1
   amendment to land before any code change.
2. **Owner confirmation to proceed** on D2-B, which is recorded as **approved in this brief**
   and now needs only a release slot — the same release as IMPLEMENTATION-001.

**STOP.**
