# TASK-ORDERS-PAYMENT-CONFIRMATION-FULFILLMENT-DECISION-002 — REPORT

**Status:** DECISION / ARCHITECTURE AUDIT — **NO IMPLEMENTATION**
**Date:** 2026-08-21
**Branch:** `develop`
**Basis:** `TASK-ORDERS-PAYMENT-CONFIRMATION-FULFILLMENT-IMPLEMENTATION-001-FINAL-REPORT.md` §17.2 and §18 item 4
**Scope:** decide the two findings that task reported and deliberately did not fix.

**Nothing was implemented, committed, migrated, seeded or deployed. No RBAC seeder was run.
No user was created. No payment was recorded. No proof was uploaded or verified. No order
status was changed. ORD-00003 was read only.**

---

## 1. Executive Summary

Both findings are real, both are decidable from existing evidence, and **they are different
kinds of problem**.

**D1 (creation-time bypass) is a contract that has gone out of date, not a coding mistake.**
`CreateManualOrderAction::resolveManualOrderStatus()` implements ADR-042 §3.1 exactly as §3.1
is written. §3.1 was authored on **2026-08-13**; the `payment_proofs` table that became the
canonical proof source was created on **2026-08-19** and made authoritative on **2026-08-21**.
When §3.1 said *"no proof was supplied"*, `orders.payment_proof_path` was the only proof that
existed. The creation path is therefore faithful to an ADR whose factual premise has since
been replaced underneath it.

The decisive structural fact is that **a canonical proof cannot exist at creation time**: the
only route that writes `payment_proofs` is `POST /orders/{order}/payment-proofs`, which needs
an order that already exists. So the creation path cannot be made to honour the canonical
contract by reading a different column — the contract itself has to say what creation is
allowed to accept. That is a business decision, not an engineering one.
**Recommendation: D1-C — amend ADR-042 §3.1 first, implement afterwards.**

**D2 (`channel_id IS NULL`) is a skipped policy evaluation, and the platform contract already
says what should happen instead.** `ENTERPRISE-CONFIGURATION-PLATFORM.md` §8 documents the
scope-inheritance chain — Channel, then **Company** — and GOV-003/GOV-006/GOV-007 forbid
hardcoding configuration values, skipping policy evaluation, and resolving scope manually in
code. `ConfirmOrderWorkflow::paymentProofRequirement()` does all three: it returns the literal
`'none'` and stops, instead of continuing down the chain. A complete, brand-agnostic default
(`instapay`/`bank_transfer`/`mobile_wallet` = `required`, `cod` = `none`) already exists in
code at `BrandPolicy::defaultSettings('order')` and is already the authority for every brand
that has no policy row — so resolving NULL correctly needs **no migration, no schema change and
no invented channel**. **Recommendation: D2-B — NULL must resolve down the documented chain.**

**Neither is a go-live BLOCKER on the evidence.** Both are **HIGH-RISK FOLLOW-UP**, with one
explicit escalation trigger for D1 stated in §10.

**Three STOP conditions are triggered** (§27): condition 1 and condition 8 for D1, condition 6
for D2's implementation step. All three are *authorisation* stops, not evidence stops — the
audit reached a conclusion in every case; what is missing is the business's authority to change
an Approved ADR and to touch a file the previous task certified. **No workaround was applied.**

---

## 2. Existing Implementation Boundary

The following are **out of scope and were not reopened**. They are recorded so the boundary of
this audit is unambiguous.

| Certified by TASK-…-IMPLEMENTATION-001 | Status here |
|---|---|
| `ReevaluateOrderFulfillmentAction` — the one canonical re-evaluation entry point | Read as evidence. Not questioned, not changed. |
| `ConfirmOrderWorkflow::paymentPermitsConfirmation()` — payment **AND** verified proof for proof-required methods | Read as evidence. **Endorsed.** Not questioned. |
| `hasVerifiedPaymentProof()` — `payment_proofs` is the only proof source at confirmation | Read as evidence. **Endorsed.** |
| RBAC separation of duties (upload = Sales, verify/reject = Finance + company-admin) | Untouched. See §21. |
| WooCommerce `OrderStatusSyncJob` left unchanged | Untouched. See §22. |

Current working-tree state, verified:

```
 M backend/Modules/Commerce/Orders/Application/Actions/CreateManualOrderAction.php
 M backend/Modules/Operations/Fulfillment/Application/Workflows/ConfirmOrderWorkflow.php
 M backend/config/permissions.php
?? backend/Modules/Commerce/Orders/Application/Actions/ReevaluateOrderFulfillmentAction.php
```

The hardening is **on disk, uncommitted, and not deployed** — the dev application still runs
the previous code. This matters for both go-live classifications (§10, §19): D2's exposure is
latent today and becomes live at the moment this work deploys.

Note for the record: the `M` on `CreateManualOrderAction.php` is **not** from the hardening
task. `git diff` shows it is the ADR-042 §3.1 *audit-event* work from an earlier session
(`resolveManualOrderStatus` changed from returning `string` to returning an array carrying
`submitted` / `override_reason`). The proof-check line itself —
`if ($requirement === 'required' && empty($data['payment_proof_path']))` — appears as unchanged
context in that diff. **The behaviour D1 is about is pre-existing and committed.**

---

## 3. D1 — Creation-Time Bypass

**The finding, restated precisely.** There are exactly **two** implementations of the
payment-proof rule in the codebase, and after the hardening they no longer agree:

| | Creation | Confirmation |
|---|---|---|
| Where | `CreateManualOrderAction.php:310-313` | `ConfirmOrderWorkflow.php:110-131` |
| Proof source | `orders.payment_proof_path` (request-supplied string) | `payment_proofs` (active + `verified`) |
| Money considered? | **No** | Yes — `deposit_amount >= total` |
| Combinator | proof present ⇒ pass | paid **AND** verified proof |

`POST /api/orders/manual` with `payment_method_manual: instapay`, `payment_proof_path: "x"`
and `status: in_progress` stores the order at `in_progress`.

**The load-bearing consequence is not "the creation check is weak". It is that the creation
check is the *only* check.** `ConfirmOrderWorkflow::guard()` runs the payment gate under one
condition (`ConfirmOrderWorkflow.php:82`):

```php
if ($order->status === OrderStatus::AwaitingPayment && ! $this->paymentPermitsConfirmation($order)) {
```

An order that never enters `awaiting_payment` is never payment-gated **anywhere, at any later
point in its life**. There is no re-entry. `in_progress` and `confirmed` are exactly the two
statuses Preparation, Distribution and the Wave Engine collect
(`OrderStatus::fulfilmentEligible()`, ADR-042 §7), so the order is fulfilment-eligible from the
instant of creation.

### 3.1 Other creation paths with the same or adjacent behaviour

The audit brief asks whether any other manual-order creation path behaves this way. Three
further paths were found. All are live.

**(a) `POST /api/orders` — `CreateOrderAction`.** Registered at `routes/api.php:560` via
`apiResource`, behind the **same** `permission:sales.orders.create`. It evaluates **no** payment
policy at all — `CreateOrderAction` simply writes `$dto->orderAttributes()`. `StoreOrderRequest:29`
validates `status` as `Rule::in(array_column(OrderStatus::cases(), 'value'))`, i.e. **all eleven
statuses**, so this endpoint can create an order directly at `confirmed` — a value ADR-042 §3
says "is **never** offered as an entry status". The `Order` model's P9 status guard does not
catch it: the guard is registered on `static::updating` only (`Order.php:146`), so creation is
exempt by construction. This path carries no payment method at all, so it is an **ADR-042 §3
entry-contract** gap rather than a payment-proof gap — but it is the same class of hole and
should be decided together.

**(b) The shipping override outranks the §3.1 override.** `CreateManualOrderAction.php:139`:

```php
'status' => $shippingResult['status_override'] ?? $statusResolution['status'],
```

When shipping validation returns `requiresReview()`, `status_override` is `on_hold`
(`CreateManualOrderAction.php:787-788`) and it **displaces** the §3.1 `awaiting_payment`
decision. `on_hold` is an allowed Confirm source (`ConfirmOrderWorkflow.php:57-64`) and is not
`awaiting_payment`, so the payment gate never runs for it either. Two further consequences:

- ADR-042 §3.1 calls itself "**the one** sanctioned exception"; this is a second, undocumented
  override that beats it.
- The audit record becomes false. The override `OrderEvent` is still written
  (`CreateManualOrderAction.php:199-213`) and reports `stored_status: awaiting_payment`, while
  the row actually holds `on_hold`.

**(c) WooCommerce import — not affected.** `WooCommerceOrderImporter.php:366` always sets
`channel_id`, and `on-hold` maps to `awaiting_payment`, so imported orders reach the gate
normally. See §17 and §22.

---

## 4. D1 Evidence

**Code — the creation rule.** `CreateManualOrderAction.php:299-321`:

```php
$method = (string) ($data['payment_method_manual'] ?? '');
...
if ($method !== '') {
    $proofPolicy = $orderPolicy['payment_proof_policy'] ?? [];
    $requirement = (string) ($proofPolicy[$method] ?? 'none');

    if ($requirement === 'required' && empty($data['payment_proof_path'])) {
        return ['status' => OrderStatus::AwaitingPayment->value, ...];
    }
}
// ── PICK-AND-STAY ──
if ($submittedStatus !== '' && self::isEntryStatus($submittedStatus)) {
    return ['status' => $submittedStatus, ...];
}
```

**Code — what `payment_proof_path` is.** `StoreManualOrderRequest.php:46`:

```php
'payment_proof_path' => 'nullable|string|max:500',
```

No storage check, no existence check, no MIME check, no tenant check. `empty()` is the only
test applied to it anywhere in the status decision.

**Code — the canonical proof cannot exist yet.** The only writer of `payment_proofs` is
`UploadPaymentProofAction` (`:63`), reachable only through
`POST /orders/{order}/payment-proofs` (`routes/api.php:571`), which requires an existing order.
**At creation time there is no order id, therefore no canonical proof is possible.**

**Code — the creation-time upload is real, and it is invisible to the canonical contract.**
The UI is not forging anything: `order-payment-section.tsx:71-76` uploads the file to
`/media/upload` and stores the returned server path into `payment_proof_path`. But that flow
writes **no `payment_proofs` row** — `CreateManualOrderAction:595-600` only logs a
`proof_uploaded` `OrderEvent`. So a genuine receipt attached at creation:

- satisfies the creation rule and skips `awaiting_payment` entirely; and
- is **never routed to Finance for verification**, because the verify surface acts on
  `payment_proofs` rows, of which there are none.

The separation of duties the hardening task just established (Sales uploads, Finance verifies)
is therefore **structurally skipped for every creation-time proof**. This is the sharpest
real-world harm in D1, and it is systematic rather than adversarial.

**Code — the confirm-side docblock is now false.** `ConfirmOrderWorkflow.php:73-79` still
states the gate "mirrors the creation-time contract in
`CreateManualOrderAction::resolveManualOrderStatus` **EXACTLY**". After the hardening it does
not: the sources differ (`payment_proofs` vs `payment_proof_path`) and the combinator differs
(AND vs presence). The comment should be corrected whichever decision is taken.

**Tests — the §3.1 override has zero coverage.** A repository-wide search for
`entry_status_overridden_by_payment_proof_policy` returns **three** files: the action, ADR-042,
and one engineering report. **No test.** The sanctioned exception of an Approved ADR is not
asserted anywhere.

**Tests — the certified creation suite never exercises a proof-required creation.**
`OrderLifecycleV3SupersessionTest::payload()` (`:55-71`) sets **no `channel_id`**, so every
order it creates resolves `$brandId = null` → `$orderPolicy = []` → requirement `'none'` for
every method. `test_case_10_payment_method_cannot_silently_replace_the_selected_entry_status`
loops `['cod','instapay','mobile_wallet']` × all three entry statuses and asserts the entry
status survives — which passes **only because the fixture is channel-less**. This is the
"fixture shape = false green" pattern: the ADR-042 §3.1 path is certified exclusively in the
shape where it does not fire.

**Live data — §3.1 does work when a channel is present.** `ORD-00008` carries the override
event, with payload:

```json
{"reason":"payment_proof_required_but_missing","stored_status":"awaiting_payment",
 "payment_method":"instapay","submitted_status":"in_progress"}
```

The mechanism is sound. It is the *acceptance criterion* that is out of date.

**Live data — no order in `ecos_dev` currently holds a `payment_proof_path`.** All 14 rows
have `payment_proof_path IS NULL`. The bypass is presently **unused**, not merely unexploited.

---

## 5. D1 ADR Contract

ADR-042 (`docs/adr/ADR-042-order-fsm-v3-canonical.md`), **Status: Approved, dated 2026-08-13**.

§3 — Entry Status Contract (Pick-and-Stay):

> An order may be created in exactly three states: Normal → `in_progress`; Future-dated
> delivery → `scheduled`; Payment required first → `awaiting_payment`.
> **Pick-and-stay is binding.** When an operator explicitly submits an entry status, that
> status is what gets stored. The creation path may not substitute a different one.

§3.1 — The one sanctioned exception:

> If the brand's `payment_proof_policy` marks the chosen payment method as `required` and **no
> proof was supplied**, the order is created `awaiting_payment` regardless of the submitted
> status. This is permitted because: it is a **blocking business condition**, not a preference;
> `awaiting_payment` is the existing payment-state mechanism …; and it is **never silent** — an
> `entry_status_overridden_by_payment_proof_policy` order event is written…

§4 — Payment method may not determine lifecycle status. §3.2 — fallback ordering, with rule 1
(proof required but missing → `awaiting_payment`) the only fallback permitted to displace a
submitted status.

**What §3.1 settles, and what it does not.**

| Question | §3.1 |
|---|---|
| Is a proof-required, unproven order allowed into fulfilment at creation? | **No** — it is a blocking business condition |
| Which policy decides? | The **brand's** `payment_proof_policy` |
| Must the override be audited? | **Yes**, always |
| **What counts as "proof was supplied"?** | **Not defined** |
| May money substitute for proof at creation? | Not addressed — creation ignores payment entirely |

**The gap is the fourth row, and it is a dating artefact, not an oversight.**

| Date | Event |
|---|---|
| 2026-08-13 | ADR-042 approved. `orders.payment_proof_path` is the only proof mechanism in existence. |
| 2026-08-19 | `2026_08_19_140000_create_payment_proofs_table.php` — the first-class proof lifecycle. |
| 2026-08-20 | TASK-PAYMENT-PROOF-LIFECYCLE-001 report: *"The old `orders.payment_proof_path` single-path model is **superseded**"*; the column is removed from the order detail UI. |
| 2026-08-21 | Hardening makes `payment_proofs` the sole proof source **at confirmation**. Creation is left on the superseded model. |

So §3.1 is **not wrong about intent**. It is **silent on a distinction that did not exist when
it was written**, and the answer it implicitly gave (`payment_proof_path`) has since been
formally superseded by a later approved task.

---

## 6. D1 Business Interpretation

Against the four classifications the brief asks for:

| | Verdict |
|---|---|
| **A — intentional business exception** | **No.** §3.1 is written as a *blocking condition*, the opposite of an exception granting entry. Nothing in ADR-042, in `BrandPolicy`, or in any configuration says a proof-required order may enter fulfilment on unverified evidence. |
| **B — legacy implementation defect** | **Partly — this is the mechanism.** The creation path reads a source that a later approved task declared superseded, and applies presence-of-string where the canonical contract applies verified-state. But the code matched its ADR when written; calling it purely a defect misstates the history. |
| **C — outdated ADR contract** | **Yes — this is the root.** §3.1's acceptance criterion was overtaken by `payment_proofs` six days after approval. The ADR is the artefact that must change first, because it is the artefact that authorises the current behaviour. |
| **D — intentional creation workflow that should eventually enter the same canonical evaluation** | **Yes — this is the destination.** Once §3.1 names `payment_proofs`, the only coherent creation-time answer is `awaiting_payment`, from which the now-repaired re-evaluation chain carries the order forward. |

**Intended business behaviour, stated plainly.** A payment method marked `required` means the
business will not commit goods until payment is *evidenced and accepted*. Sales attaching a
receipt is evidence submitted; Finance verifying it is evidence accepted. The hardening task
already established that distinction and the business already approved it (Decisions 1 + 3).
Applying the same distinction at creation yields exactly one outcome: **a proof-required manual
order is created `awaiting_payment`, always**, because acceptance cannot have happened yet.
`awaiting_payment` is not a penalty — it is the state ADR-042 provides for precisely this
condition, and §3.2 already ranks it first among the fallbacks.

### Does `payment_proof_path` retain a legitimate role at creation?

**Yes — as evidence, never as a lifecycle input.**

- It is populated by a real server-side upload (`order-payment-section.tsx:71-76`), not by
  free-typed text, on the normal UI path.
- It is still displayed and still audited (`proof_uploaded` `OrderEvent`).
- The certified contract already states that proof is not payment:
  `OrderPaymentStateTest::test_payment_proof_alone_does_not_make_an_order_paid` (B11.9).

What it must stop doing is **deciding the entry status**. A cleaner successor exists and needs
no new concept: creation could carry the attachment forward into a `payment_proofs` row in
state `uploaded`, so the receipt a sales rep attaches actually reaches Finance's queue instead
of dead-ending in a display column. **That is a design note for the implementation task, not a
recommendation this audit is authorised to make.**

---

## 7. D1 Security Impact

| Dimension | Assessment |
|---|---|
| **Privilege required** | `sales.orders.create` — a legitimate, high-trust verb held by every Sales role. This is **not** a privilege-escalation vector: the actor may already create orders. |
| **Control actually bypassed** | The **separation of duties** established by the hardening task. `sales.orders.proof_verify` is never reached, because no `payment_proofs` row is ever created. Finance is not skipped by a permission hole — it is skipped because the artefact it reviews is never produced. |
| **Forgery surface** | Real but secondary. `payment_proof_path` is `nullable\|string\|max:500` with no validation of any kind, so a non-UI client can send `"x"`. The realistic harm is systematic (no verification ever happens) rather than adversarial. |
| **Same-class vector already closed** | Yes — §17.1 of the implementation report closed the identical hole on `POST /orders/{order}/verify-payment`. Leaving the creation twin open means the proof control is **not enforced on the primary path**. |
| **Tenant isolation** | Unaffected. `company_id` is derived from the authenticated actor (`CreateManualOrderAction.php:134`), never from the request body. |
| **Money safety** | No money is written or moved by this path. `deposit_amount` is unaffected. |
| **Audit trail** | Weak on this path: no override event is written when the bypass succeeds (the override branch is not taken), and — separately — the override event records a false `stored_status` when the shipping override wins (§3.1(b)). |

**Net:** an authorisation-model problem, not an authorisation-bypass problem. The control is
correctly permissioned and simply never invoked.

---

## 8. D1 Fulfillment Impact

- **Today:** a proof-required order created at `in_progress` is immediately fulfilment-eligible
  (`OrderStatus::fulfilmentEligible() = ['in_progress','confirmed']`, ADR-042 §7). It is
  reserved at creation by `ProcessOrderWorkflow` (`CreateManualOrderAction.php:291-303`) and
  collected by Preparation, Distribution and the Wave Engine with **no payment evaluation ever
  having run, and none scheduled to run later**.
- **Under an aligned contract:** the order is created `awaiting_payment`, which is deliberately
  **not** fulfilment-eligible, and which `decidesAvailabilityAtCreation()` already admits — so
  it still takes its availability decision at creation and still learns whether stock exists.
  It advances the moment payment and a verified proof coexist, through the canonical
  `ReevaluateOrderFulfillmentAction` chain the hardening task built.
- **Preparation is not touched by either option.** Its eligibility list is unchanged; only the
  status an order is born in changes. This satisfies STOP condition 7.
- **Operational cost, stated honestly:** alignment means every instapay / bank-transfer /
  mobile-wallet order parks until Finance verifies. That is a throughput change the business
  must accept knowingly — it is the substance of the question in §27.

---

## 9. D1 Recommendation

# D1-C — Change the ADR/business contract first, then implement alignment later.

**Why not D1-A (preserve the exception).** The behaviour is not an exception the business chose;
it is the residue of a superseded proof model. Preserving it would leave two contradictory
definitions of the same rule in one codebase and leave the creation path — the primary path —
outside the control the business approved three days ago.

**Why not D1-B (align now).** ADR-042 is **Approved** and §3.1 explicitly authorises the current
behaviour. Implementing alignment without amending it would (i) contradict a live ADR, (ii)
change documented lifecycle semantics without business authorisation — STOP condition 8 — and
(iii) silently change operational throughput for every proof-required order. Two certified
assertions would also flip (§24).

**Why not D1-D.** No evidence supports a fourth option. The mechanism is sound, the state
already exists, and no new concept is needed.

**What D1-C means concretely.** ADR-042 §3.1 should be amended to state what "proof was
supplied" means under the post-2026-08-19 model, and — because a canonical proof cannot exist
before the order does — to state the entry status for a proof-required method. The two adjacent
gaps found in §3.1(a) and §3.1(b) should be ruled on in the same amendment, since both are
entry-contract questions and both are live today.

| Question | Answer |
|---|---|
| Exact evidence | §4 |
| Relevant files / classes | `CreateManualOrderAction.php:65-66,139,199-213,299-321,595-600`; `StoreManualOrderRequest.php:46,83`; `ConfirmOrderWorkflow.php:73-79,82,110-131`; `CreateOrderAction.php`; `StoreOrderRequest.php:29`; `Order.php:146`; `UploadPaymentProofAction.php:63`; `routes/api.php:549,560,571` |
| Relevant ADR section | **ADR-042 §3, §3.1, §3.2, §4, §7** (plus §12 Enforcement) |
| Current behaviour | Any non-empty `payment_proof_path` lets a proof-required order be created at `in_progress`, permanently ungated |
| Intended business behaviour | Proof-required ⇒ created `awaiting_payment`; advance only on payment **and** a verified `payment_proofs` row |
| Security consequence | Separation of duties structurally skipped on the primary creation path (§7) |
| Fulfillment consequence | Ungated orders enter Preparation today; aligned orders park in `awaiting_payment` until verified (§8) |
| **Implementation required?** | **Yes — but only after the ADR amendment.** Not authorised by this task. |
| **Migration required?** | **No.** No schema change, no new state, no data rewrite. |
| **RBAC changes required?** | **No.** Every needed permission already exists and is already granted (§21). |
| **Go-live blocker?** | **No — HIGH-RISK FOLLOW-UP.** See §10 for the one escalation trigger. |

**Not implemented. Awaiting explicit approval.**

---

## 10. D1 Go-Live Classification

# HIGH-RISK FOLLOW-UP

**Not a BLOCKER, because:**

- it is **pre-existing baseline behaviour**, explicitly authorised by an Approved ADR, and the
  business has been operating under it;
- it is reachable only by an actor who already holds `sales.orders.create` and can create
  orders regardless;
- it writes no money, corrupts no data, breaks no tenant boundary, and requires no remediation;
- **no order in `ecos_dev` currently carries a `payment_proof_path` at all** — the path is
  presently unused;
- deploying the hardening does not make it worse. D1's exposure is identical before and after.

**High-risk, because** the confirmation path is now hardened and the creation path is not, so
the system holds two contradictory definitions of one rule, and the *primary* path is the
unhardened one. Any statement that "payment proof is enforced" is currently false for orders
created `in_progress`.

**The one condition that escalates this to BLOCKER — the business must rule on it:**

> If `payment_proof_policy: required` is intended at go-live as an **enforced financial control**
> — goods must not be committed before payment evidence is accepted — then D1 **is a BLOCKER**,
> because that control is not enforced on the path most orders take.
> If it is intended as an **operational prompt** — a reminder to Sales, with commercial trust
> covering the gap — then D1 is correctly a follow-up.

This audit cannot answer that question from code, configuration or any ADR. It is stated as a
STOP in §27.

---

## 11. D2 — `channel_id` IS NULL

**The finding, restated precisely.** `ConfirmOrderWorkflow.php:169-183`:

```php
private function paymentProofRequirement(Order $order, string $method): string
{
    if ($order->channel_id === null) {
        return 'none';
    }

    $channel = Channel::find($order->channel_id);
    if ($channel === null) {
        return 'none';
    }

    $policy = $this->config->getBrandPolicy((string) $channel->brand_id, 'order');

    return (string) ($policy['payment_proof_policy'][$method] ?? 'none');
}
```

A channel-less order requires no proof **for any method**. The creation path reaches the same
outcome by a different route (`CreateManualOrderAction.php:65-66`):

```php
$brandId = $this->resolveBrandId($data['channel_id'] ?? null);
$orderPolicy = $brandId !== null ? $this->config->getBrandPolicy($brandId, 'order') : [];
```

`[]` → `$proofPolicy[$method] ?? 'none'`. **The same NULL semantics exist on both paths.** These
are the only two implementations of the rule in the codebase (verified by repository-wide
search for `payment_proof_policy` / `paymentProofRequirement`).

**Why it is live now and was not before.** Under the old code nothing re-evaluated the gate
after a payment fact changed, so the `'none'` result was reached and then never acted on. The
canonical re-evaluation makes it act: a channel-less, **unpaid**, proof-required order now
advances to `confirmed` the moment any payment is recorded or any proof is verified.

---

## 12. D2 Evidence

**Structural — an order has exactly one route to a payment policy, and it is optional.**
Verified against the live schema:

```
orders:  company_id char(36) NULL,  channel_id char(36) NULL     -- and NO brand_id column
```

`channels.brand_id → config_brand_policies.brand_id` is the **only** path from an order to
`payment_proof_policy`. `channel_id` is nullable at the database, nullable in
`StoreManualOrderRequest.php:78`, and nullable in `UpdateOrderRequest.php:27`.

**A complete default policy already exists, brand-agnostically, in code.**
`BrandPolicy.php:150-174`:

```php
'payment_proof_policy' => [
    'cod' => 'none', 'instapay' => 'required', 'bank_transfer' => 'required',
    'mobile_wallet' => 'required', 'credit_card' => 'optional',
],
```

and `ConfigurationManager.php:31-43` already treats it as authoritative:

```php
return $policy?->settings ?? BrandPolicy::defaultSettings($group);
```

**This is the decisive point.** A brand with *no configured policy row at all* still gets
`instapay => required`. The **only** way to obtain `'none'` for instapay is to have **no brand**
— i.e. no channel. So the `'none'` default is not "the system's default policy"; it is a
**short-circuit around a default policy that already says `required`**.

**Live data.**

```
channel_is_null   n
1                 1        <- ORD-00008
0                 13
```

Every one of the 14 orders has `company_id` set (0 NULL), so the company scope is reachable for
all of them.

**A channel is not permanent — an order can become channel-less after creation, and one has.**
ORD-00008's event history proves the transition:

| Time | Event | Meaning |
|---|---|---|
| 2026-08-20 02:35:22 | `entry_status_overridden_by_payment_proof_policy`, payload `payment_method: instapay` | The §3.1 override fired — which is **only reachable through a channel→brand policy lookup**, so the order **had** a channel |
| 2026-08-20 03:53:54 | `order_updated` | An edit ran |
| now | `channel_id = NULL`, `payment_method_manual = cod` | Channel gone; method changed repeatedly afterwards |

The server-side mechanism is explicit. `UpdateOrderAction.php:98`:

```php
$attributes = array_diff_key($dto->orderAttributes(), ['status' => true]);
```

`OrderDTO::orderAttributes()` **always** contains `'channel_id' => $this->channel_id`, and
`OrderDTO::nullableString()` (`:84-89`) coerces a missing **or empty** key to `null`. So any
update whose payload omits or blanks `channel_id` writes `channel_id = NULL` — and
`UpdateOrderRequest.php:27` explicitly permits it. The UI has code paths that clear the field
(`manual-order-form.tsx:1122`, `resetDownstreamOfBrand()`, invoked by the company and brand
change handlers).

*Stated with the right confidence:* the server unambiguously accepts and applies
`channel_id = null` on update, and ORD-00008 demonstrably lost a channel it had at creation.
The exact UI action that did it was **not** reproduced, because reproducing it would require
creating or modifying business data, which this task forbids.

**Consequence:** `payment_proof_policy` is **not stable across an order's life**. An order that
correctly entered `awaiting_payment` under a `required` policy can have that requirement removed
by an ordinary order edit under `sales.orders.update` — no payment verb, no proof verb, no audit
entry naming the policy change.

**A certified test currently depends on the `'none'` default.**
`PaymentProofLifecycleTest::makeOrder()` (`:49-63`) builds `instapay`, `deposit 0`,
`awaiting_payment`, **no `channel_id`**. Its own docblock (`:172-175`) says so:

> *"Note on the fixture: `makeOrder()` sets no channel_id, so the brand proof policy resolves to
> 'none' and the gate does not block despite the order being unpaid."*

`test_10_verification_never_writes_order_status_itself` asserts that this **unpaid** order
**advances** on verification. Under D2-B it would correctly block. This is a named, concrete
implementation dependency (§24), and it is the same false-green shape found in §4.

---

## 13. D2 Policy Resolution

**There is an existing, documented contract for this, and the current code contradicts it.**

`docs/architecture/ENTERPRISE-CONFIGURATION-PLATFORM.md` §8 — *Scope Hierarchy*:

```
1. Check User scope        → none
2. Check Warehouse scope   → none
3. Check Channel scope     → configured (Channel wins — stop here)
```
> "If no channel-level config existed:"
```
5. Check Company scope     → "ECOS Food" has partial_delivery = true (Company wins — stop here)
```

§9 — *Architecture Governance Rules*:

| Rule | Statement | `paymentProofRequirement()` |
|---|---|---|
| GOV-003 | No configuration value may be hardcoded in application code | Violated — `return 'none';` is a hardcoded policy value |
| GOV-006 | No policy evaluation may be skipped, **even in tests** — use test-scoped configuration | Violated — the evaluation is skipped entirely, and two certified fixtures rely on the skip |
| GOV-007 | Scope inheritance is always resolved by the Configuration Resolver — never manually in code | Violated — the chain is walked by hand and terminated at Channel |

**Answering the brief's five sub-questions:**

1. **Where is payment policy resolved?** In exactly two places:
   `ConfirmOrderWorkflow::paymentProofRequirement()` (`:169-183`) and
   `CreateManualOrderAction` (`:65-66` + `:310-311`). Both terminate at the channel.
2. **What happens when `channel_id` is NULL?** Requirement is `'none'` for every method, on
   both paths.
3. **What does NULL actually mean?** On the documented contract it means **"no channel-scoped
   configuration"** — a signal to continue down the chain, not a policy answer. The four
   candidate readings resolve as follows:
   - *intentionally no channel* — plausible for walk-in POS, but it says nothing about payment;
   - *default channel policy* — **not available**: no default channel exists, and this audit is
     forbidden to invent one;
   - *company-level policy* — **the documented answer** (§8), and `company_id` is populated on
     100% of live orders;
   - *legacy/manual order* — contradicted: manual orders normally **do** carry a channel
     (13 of 14 live orders), and the manual form marks the field `required`
     (`manual-order-form.tsx:1698`) and gates product selection on it (`:507`);
   - *invalid/missing configuration* — partially true for the **post-edit** case (ORD-00008),
     which is data loss rather than a configuration choice.
4. **Are existing manual orders legitimately channel-less?** **Only marginally.** The UI treats
   the channel as mandatory. `POST /api/orders` (`CreateOrderAction`) can create channel-less
   orders and carries no payment method at all. The one live channel-less order became so *by
   edit*, not by design.
5. **Can WooCommerce/imported orders be channel-less?** **No at import** —
   `WooCommerceOrderImporter.php:366` always sets `channel_id`. **Yes afterwards**, because the
   generic update path can null it like any other order. See §17.
6. **Is the current behaviour documented anywhere?** **No.** No ADR, no config key, and no
   business contract states that a channel-less order requires no proof. It appears only as
   *observations* in three prior engineering reports (COD-AUDIT-001 §3 "Finding P-3 (silent
   bypass)", COD-AUDIT-002 §4, and IMPLEMENTATION-001 §18 item 4), each of which flagged it as a
   gap and deferred it. **The only written contract that speaks to it —
   `ENTERPRISE-CONFIGURATION-PLATFORM.md` §8 — says the opposite.**

**Also recorded (documentation defect, no behaviour change):** `ConfirmOrderWorkflow`'s docblock
(`:124`) says `credit_card → 'optional'`. The live brand policy says `required`. The code reads
the policy, so behaviour is correct; the comment misleads. Previously reported as COD-AUDIT-001
Finding P-2 and **still open**.

---

## 14. D2 Business Interpretation

A payment-proof requirement is a **financial control**. Two properties follow, and the current
implementation fails both:

1. **It must not be silently removable by a non-financial actor.** Today `sales.orders.update`
   — an ordinary order-edit verb — can null `channel_id` and thereby delete the requirement, with
   no event naming the policy change. A control an editor can switch off is not a control.
2. **It must have a defined answer everywhere it is asked.** Today the answer is defined only
   when a channel happens to be attached.

`'none'` is a **substantive policy statement** — "this business does not require proof for this
method". Returning it because a *lookup key was absent* conflates "the policy says no" with "we
did not ask". `BrandPolicy::defaultSettings('order')` proves the system already knows how to
answer without a configured row; the NULL branch simply returns before reaching it.

Against the brief's options:

| | Verdict |
|---|---|
| **A — NULL legitimately means "no requirement"** | **No.** No contract says so; the one applicable contract (§8 + GOV-003/006/007) says the opposite; and a system-wide default already exists that answers `required`. |
| **B — NULL must resolve through a company/default policy** | **Yes.** This is the documented chain, it is implementable with zero schema change, and it makes the requirement independent of a mutable, nullable foreign key. |
| **C — NULL is invalid and must be rejected** | **No.** Disproportionate and unsafe: `POST /api/orders` legitimately creates channel-less orders, POS walk-in has a channel-less shape, and rejecting NULL would strand ORD-00008 and any future channel-less order with no recovery path. It also does not address the real defect — that the requirement is derived from a mutable key. |
| **D — another option** | Not required. One variant is noted below and is deliberately **not** recommended. |

**Variant considered and rejected as out of scope.** The most robust fix is to resolve the
requirement **once, at creation, and store it on the order**, so it cannot drift. That is
architecturally correct and would also close the edit-driven removal completely — but it
**requires a migration**, which triggers STOP condition 4. It is therefore recorded as a future
architectural option and **explicitly not recommended by this audit**.

---

## 15. D2 Payment Impact

Behaviour under D2-B, by order shape. "Changed" is relative to the hardened (not yet deployed)
code.

| Order shape | Today | Under D2-B | Changed? |
|---|---|---|---|
| Channel set, any method | Brand policy (falls back to system default) | Identical | **No** |
| Channel NULL, `cod` / `cash` | `'none'` → gate permits | `'none'` → gate permits | **No** |
| Channel NULL, `credit_card` | `'none'` | `'optional'` → gate still permits | **No** (both non-blocking) |
| Channel NULL, `instapay` / `bank_transfer` / `mobile_wallet` | `'none'` → **unpaid order advances** | `'required'` → paid-in-full **AND** verified proof | **Yes — this is the entire change** |
| Method empty / unmapped key | `'none'` (empty-method short-circuit, `:114-117`) | Unchanged | **No** |

**Live exposure of the only changed row: zero orders.** The single channel-less order
(ORD-00008) is `cod`, whose requirement is `'none'` under both the configured brand policy and
the system default.

**Proof-required payment methods** are the entire point of the change: under D2-B the
requirement survives the loss of a channel, so the AND-gate the business approved on 2026-08-21
actually governs those orders instead of being skipped by a NULL key.

---

## 16. D2 COD Impact

# NONE. ZERO. The COD contract is untouched.

`cod` resolves `'none'` in **both** authorities:

- live configured policy (`config_brand_policies`, brand `019f4e1c-2d4c-…`):
  `{"cod":"none","cash":"none","instapay":"required","credit_card":"required","bank_transfer":"required","mobile_wallet":"required"}`
- code default (`BrandPolicy.php:159-165`): `'cod' => 'none'`

Requirement `'none'` satisfies `paymentPermitsConfirmation()` at `ConfirmOrderWorkflow.php:121`
before payment is ever considered, so a COD order confirms unpaid — exactly as today. This is
the approved COD contract (COD-AUDIT-002 §14: *"No COD change is proposed or required"*), and
D2-B does not touch it in any shape, channel-bound or channel-less. ORD-00008, the only live
channel-less order, is COD and is **unaffected**.

---

## 17. D2 WooCommerce Impact

**No material effect. Two independent reasons, either sufficient.**

1. **Imported orders always carry a channel.** `WooCommerceOrderImporter.php:366` writes
   `'channel_id' => (string) $channel->id` unconditionally. The NULL branch is unreachable for
   them at import, so D2-B changes nothing on the import path.
2. **Woo payment methods are not in the ECOS proof-policy vocabulary.** The importer writes the
   raw WooCommerce gateway id (`:401`, e.g. `bacs`, `ppcp`, `stripe`), while
   `payment_proof_policy` is keyed on `cod`, `cash`, `instapay`, `bank_transfer`,
   `mobile_wallet`, `credit_card`. The confirm gate reads
   `$order->payment_method_manual ?? $order->payment_method` (`:112`), so a Woo order resolves
   `$policy['payment_proof_policy']['bacs'] ?? 'none'` — `'none'` **by key-miss**, regardless of
   channel. D2-B does not change key-miss behaviour.

Woo orders **do** reach the gate: `on-hold → awaiting_payment`
(`WooCommerceOrderStatusTranslator::MAP`), so they can sit in `awaiting_payment` and be
re-evaluated. They simply never resolve a `required` requirement.

**Ambiguity reported, not resolved (as instructed).** The vocabulary mismatch means a WooCommerce
order paid by bank transfer (`bacs`) is treated as proof-not-required, while the equivalent
manual order (`bank_transfer`) is proof-required. Whether that asymmetry is intended is a
business question. **No mapping was invented, and none should be added as a side effect of
either decision.**

---

## 18. D2 Recommendation

# D2-B — `channel_id IS NULL` must resolve through the documented scope chain, not short-circuit to `'none'`.

Concretely: when no channel-scoped policy is resolvable, continue down the chain that
`ENTERPRISE-CONFIGURATION-PLATFORM.md` §8 already documents — company scope
(`ConfigurationManager::getCompanySettings($order->company_id, 'order')`, which exists today and
returns `[]` until configured), then the system default that every brand already falls back to
(`BrandPolicy::defaultSettings('order')['payment_proof_policy']`). **The literal `return 'none';`
must go.**

This **invents no default channel** and **adds no new policy source**. It reuses the exact
fallback `ConfigurationManager::getBrandPolicy()` already applies at `:41`.

| Question | Answer |
|---|---|
| Exact evidence | §12 |
| Relevant files / classes / config | `ConfirmOrderWorkflow.php:169-183`; `CreateManualOrderAction.php:65-66,310-311`; `ConfigurationManager.php:31-43,107-120`; `BrandPolicy.php:82,150-174`; `UpdateOrderAction.php:98`; `OrderDTO.php:84-89`; `UpdateOrderRequest.php:27`; `config_brand_policies`; `ENTERPRISE-CONFIGURATION-PLATFORM.md` §8, §9 (GOV-003/006/007) |
| Existing business behaviour | Channel-less ⇒ no proof requirement for any method. Undocumented; contradicted by the platform contract |
| Affected order types | **Only** channel-less orders with `instapay` / `bank_transfer` / `mobile_wallet`. Live count: **0** |
| COD impact | **None** (§16) |
| Proof-required payment impact | The requirement stops being erasable by nulling a foreign key (§15) |
| WooCommerce impact | **None** (§17); one vocabulary ambiguity reported, not resolved |
| Security impact | Closes an operator-reachable removal of a financial control via `sales.orders.update` |
| **Implementation required?** | **Yes** — one method body. Blocked on the STOP-6 authorisation in §27 |
| **Migration required?** | **No.** No schema change, no new column, no data rewrite |
| **Existing data remediation?** | **None.** 0 orders change outcome |
| **Go-live blocker?** | **No — HIGH-RISK FOLLOW-UP**, but it must be resolved **in the same release that deploys the hardening** (§19) |

**Not implemented. Awaiting explicit approval.**

---

## 19. D2 Go-Live Classification

# HIGH-RISK FOLLOW-UP

**Not a BLOCKER, because:**

- **zero live orders change outcome** — the only channel-less order is COD, `'none'` either way;
- no data remediation, no migration, no schema change is required;
- the exposure is **latent in production today**: the hardened re-evaluation is uncommitted and
  undeployed, so nothing currently re-asks the gate.

**High-risk, and release-coupled, because** deploying the hardening is precisely what converts
this from latent to live. From that deploy onward, a channel-less unpaid instapay order advances
to `confirmed` on any payment-record or proof-verify event, and the path to becoming
channel-less is an ordinary order edit — demonstrated on real data (§12).

**Recommended sequencing:** resolve D2 **in the same release** that deploys
TASK-…-IMPLEMENTATION-001. Deploying the hardening without D2 ships a known live bypass of the
control the hardening exists to enforce.

---

## 20. ORD-00003

**Read-only. Not re-triggered, not paid, not verified, not modified.**

Live state:

```
order_number: ORD-00003        status: awaiting_payment
channel_id:   019f4e1c-2f68-73f7-b3a3-fdb85fd96a4f   (ECOS Main Store — NOT null)
total: 10000.00                deposit_amount: 10000.00      (paid in full)
payment_method_manual: instapay    payment_proof_path: NULL
confirmed_at: NULL             reservation_status: pending
payment_proofs: 1 row — state=verified, superseded_at=NULL, verified_at=2026-08-19 21:41:40
brand policy: instapay => required
```

Event history: `order_created` + `awaiting_payment` (00:38:10) → `payment_proof_uploaded`
(00:40:43) → `payment_recorded` (00:41:19) → `payment_proof_verified` (00:41:40) → later
address/zone edits only.

### Cause — attributed precisely

| Candidate | Verdict |
|---|---|
| **The old missing re-evaluation** | **YES — this is the sole cause.** Both trigger events fired (`payment_recorded`, `payment_proof_verified`) and neither re-asked the gate. Every precondition of the hardened gate is satisfied: paid in full **and** an active verified proof. |
| The new payment contract | **No.** ORD-00003 *passes* the hardened AND-gate. The tightening did not strand it. |
| Channel policy (D2) | **No.** `channel_id` is **set**, and its brand policy correctly says `instapay => required`. §3.1 fired correctly at creation (`payment_proof_path` was NULL), which is why the order is in `awaiting_payment` at all. **ORD-00003 is not a D2 case.** |
| D1 creation-time bypass | **No.** The opposite — the creation gate worked exactly as designed. |
| Another cause | None found. |

**ORD-00003 is evidence *for* the hardening, not against it.** It is stuck because the repaired
orchestration is **not deployed** — the fix would advance it on the *next* payment or
proof-verification event, but it has already received both. Clearing it needs a one-time
operational re-trigger after deployment, which **this task does not authorise and did not
perform**.

**Adjacent read-only observation, recorded for completeness.** The `awaiting_payment` population
is: ORD-00003 (verified proof, paid in full — gate passes), ORD-00004 (proof `uploaded` only,
plus a superseded `rejected` one, deposit 0 — gate correctly blocks), ORD-00005 (verified proof
but deposit 3 000 of 10 000 — **gate correctly blocks under the new AND-rule; it would have
passed under the old OR-rule**), ORD-00008 (channel-less, COD, no proof — gate permits under
both D2-A and D2-B). Nothing was touched.

---

## 21. RBAC Boundary

**`RbacSeeder` was not run. No user was created. No user was assigned to any role. No permission
row was added, removed or changed.**

The previous task's approved RBAC model stands unchanged. This audit found **no contradiction**
with it:

- **D1 needs no RBAC change.** `sales.orders.proof_upload`, `proof_verify` and `proof_reject`
  already exist and are already granted per Decision 2. D1's problem is that the creation path
  never *produces* a `payment_proofs` row, so `proof_verify` is never reached — a workflow gap,
  not a permission gap. Aligning creation would route those orders **into** the existing,
  correctly-permissioned verification flow rather than around it, which strengthens Decision 2
  rather than revisiting it.
- **D2 needs no RBAC change.** It changes how a requirement is *resolved*, not who may act.

One RBAC-adjacent observation, reported and **not** acted on: `sales.orders.update` can null
`channel_id` (`UpdateOrderRequest.php:27` + `UpdateOrderAction.php:98`) and thereby remove a
payment-proof requirement. Whether an order editor should be able to do that is a permissions
question the business may wish to consider **after** D2 is decided — D2-B removes the security
consequence without needing any permission change.

---

## 22. WooCommerce Boundary

**No WooCommerce code, mapping, translator or job was changed or proposed for change.**

Material effect of each decision on WooCommerce lifecycle synchronisation:

| | Effect |
|---|---|
| **D1** | **None.** D1 concerns `POST /api/orders/manual`. Imported orders take their status from `WooCommerceOrderStatusTranslator` and never call `resolveManualOrderStatus()`. |
| **D2** | **None material.** Imported orders always carry `channel_id` (`:366`), so the NULL branch is unreachable at import; and Woo gateway ids are not proof-policy keys, so the lookup misses regardless (§17). |

**Ambiguity reported, not resolved, no mapping invented:** the ECOS
`payment_proof_policy` vocabulary (`cod`, `cash`, `instapay`, `bank_transfer`, `mobile_wallet`,
`credit_card`) and WooCommerce gateway ids (`bacs`, `ppcp`, `cheque`, `stripe`, …) do not
share a namespace, so proof policy is effectively inert for imported orders. Whether Woo orders
should be subject to proof policy at all is a business question for a separate task.

Also carried forward unchanged from IMPLEMENTATION-001 §18 item 8: once deployed,
`confirmed`/`in_progress` sync attempts will surface as failed sync logs for channel orders.
That is the approved behaviour, and neither decision alters it.

---

## 23. Data Safety

| Action | Performed? |
|---|---|
| Code changed | **No** |
| Migration created or run | **No** |
| Schema changed | **No** |
| API contract changed | **No** |
| RBAC changed / `RbacSeeder` run | **No** |
| Users created or assigned | **No** |
| Business data created or modified | **No** |
| Payment recorded | **No** |
| Proof uploaded / verified / rejected | **No** |
| Order status changed | **No** |
| ORD-00003 touched or re-triggered | **No — read-only** |
| Tests written or modified | **No** |
| Regression suite run | **No** |
| Committed | **No** |

**Database access was strictly read-only.** Every statement issued was a `SELECT`, `DESCRIBE`,
`SHOW TABLES` or `SHOW COLUMNS` against `ecos_dev`. No `INSERT`, `UPDATE`, `DELETE`, `CREATE`,
`ALTER` or `DROP` was issued, and no `artisan` command, seeder, migration or tinker session was
run. **Neither decision requires any data remediation** (§15, §18).

---

## 24. Implementation Dependencies

Recorded so a future implementation task inherits an accurate blast radius. **None of this was
implemented.**

**If D1 is implemented (only after the ADR-042 §3.1 amendment):**

| Dependency | Detail |
|---|---|
| ADR-042 §3.1 | Must be amended **first**. It is the artefact that authorises today's behaviour |
| `OrderLifecycleV3SupersessionTest::test_case_10` | Its fixture is channel-less (`payload()`, `:55-71`), so it is green today for the wrong reason. It becomes load-bearing the moment either decision lands and will need a channel-bound counterpart |
| `ConfirmOrderWorkflow.php:73-79` | Docblock claims the gates "mirror … EXACTLY". Already false; must be corrected either way |
| §3.1(a) `POST /api/orders` | Accepts all 11 statuses including `confirmed`; no payment evaluation. Should be ruled on in the same amendment |
| §3.1(b) shipping `status_override` | Displaces the §3.1 override and falsifies the override event's `stored_status`. Same amendment |
| Creation-time proof orphaning | A receipt attached at creation writes no `payment_proofs` row and never reaches Finance. Design note only |
| Migration / RBAC | **Neither required** |

**If D2 is implemented:**

| Dependency | Detail |
|---|---|
| `ConfirmOrderWorkflow::paymentProofRequirement()` (`:169-183`) | One method body. **Requires the STOP-6 authorisation in §27** — task 001 declared this method "untouched" in a certified file |
| `PaymentProofLifecycleTest::test_10` | **Will go red.** Its fixture is unpaid + channel-less + instapay and it asserts the order *advances*. The assertion is correct only under the `'none'` short-circuit. Needs re-fixturing (add a channel, or add payment), not weakening |
| `CreateManualOrderAction.php:65-66` | Carries the identical NULL semantics. For one rule to have one answer, the same resolution should apply there — **but whether creation honours `payment_proof_path` remains strictly a D1 question** (§25) |
| `ConfirmOrderWorkflow.php:124` | `credit_card → 'optional'` docblock contradicts the live policy (`required`). Pre-existing (COD-AUDIT-001 P-2), still open |
| Migration / RBAC / data remediation | **None required** |

---

## 25. Decision Summary

| | D1 — Creation-Time Bypass | D2 — `channel_id` IS NULL |
|---|---|---|
| **Question** | **How an order enters the lifecycle** | **How the payment requirement is resolved** |
| **Recommendation** | **D1-C** — amend ADR-042 §3.1 first, implement afterwards | **D2-B** — resolve NULL down the documented scope chain |
| Classification | **C** (outdated ADR contract), mechanism **B**, destination **D** | Skipped policy evaluation contradicting a documented contract |
| Root cause | `payment_proofs` (2026-08-19) superseded §3.1's premise (2026-08-13) | `return 'none';` short-circuits a default that already says `required` |
| Governing contract | ADR-042 §3, §3.1, §3.2, §4, §7 | `ENTERPRISE-CONFIGURATION-PLATFORM.md` §8; GOV-003/006/007 |
| Live exposure | 0 orders carry a `payment_proof_path` | 0 orders change outcome |
| Migration | **No** | **No** |
| RBAC change | **No** | **No** |
| Data remediation | **No** | **No** |
| Preparation change | **No** | **No** |
| WooCommerce effect | **None** | **None material** (one ambiguity reported) |
| COD effect | None | **None** |
| Go-live | **HIGH-RISK FOLLOW-UP** (escalation trigger in §10) | **HIGH-RISK FOLLOW-UP**, release-coupled to the hardening deploy |
| Blocked on | Business ruling + ADR amendment (STOP 1, STOP 8) | Authorisation to touch a certified file (STOP 6) |

**The two were kept separate throughout, as instructed.** D1 is not solved by changing D2:
aligning creation to `awaiting_payment` is required whether NULL resolves to `'none'` or to
`'required'`. D2 is not solved by changing D1: an order can become channel-less by *edit*, long
after creation, so no creation-time rule can fix requirement resolution. They share one
implementation surface — the requirement lookup exists in both files — which is a sequencing
note in §24, not a merged decision.

---

## 26. Risks

| # | Risk | Severity | Mitigation |
|---|---|---|---|
| 1 | **Deploying the hardening without deciding D2** ships a live bypass of the control the hardening exists to enforce | **High** | Resolve D2 in the same release (§19) |
| 2 | Accepting D1-C but never scheduling the amendment leaves two contradictory definitions of one rule indefinitely | **High** | Time-box the ADR amendment; §10's escalation trigger decides its priority |
| 3 | D1 alignment parks every instapay / bank-transfer / mobile-wallet order until Finance verifies — a real throughput change | **Medium** | Must be accepted knowingly by the business; it is the substance of the §27 STOP-1 question |
| 4 | Creation-time receipts continue to dead-end in a display column, never reaching Finance | **Medium** | Design note in §6; belongs to the D1 implementation task |
| 5 | Two certified suites are green because their fixtures are channel-less (`OrderLifecycleV3SupersessionTest`, `PaymentProofLifecycleTest`). Other channel-less fixtures may exist | **Medium** | Sweep fixtures for `channel_id` before implementing either decision (§24) |
| 6 | `channel_id` remains nullable by ordinary edit, so the requirement stays derived from a mutable key even under D2-B | **Medium** | D2-B removes the security consequence. The durable fix (snapshot the requirement on the order) needs a migration and is **out of scope** (§14) |
| 7 | `POST /api/orders` can create an order directly at `confirmed`, contradicting ADR-042 §3 | **Medium** | Rule on it in the same ADR amendment (§3.1(a)) |
| 8 | The shipping `status_override` beats the §3.1 override and falsifies its audit event | **Medium** | Same amendment (§3.1(b)) |
| 9 | Woo gateway ids are outside the proof-policy vocabulary, so proof policy is inert for imported orders | **Low** | Reported only; **no mapping invented** (§17, §22) |
| 10 | `ConfirmOrderWorkflow` docblocks are stale in two places (`credit_card`, "mirrors … EXACTLY") | **Low** | Correct during whichever implementation lands first |

---

## 27. STOP Conditions

| # | Condition | Verdict |
|---|---|---|
| 1 | ADR-042 §3.1 contradictory or incomplete in a way that requires a business decision | **TRIGGERED** — see below |
| 2 | Creation-time bypass cannot be classified safely from existing evidence | **Not triggered.** Classified: **C** primary, **B** mechanism, **D** destination (§6), on dated, verifiable evidence |
| 3 | `channel_id` NULL semantics cannot be determined from existing contracts | **Not triggered.** Determined from `ENTERPRISE-CONFIGURATION-PLATFORM.md` §8 and GOV-003/006/007 (§13) |
| 4 | A decision requires a migration | **Not triggered.** Neither D1-C nor D2-B needs one. The migration-requiring variant was identified and **explicitly not recommended** (§14) |
| 5 | Existing real data requires irreversible remediation | **Not triggered.** 0 orders change outcome; nothing was modified (§23) |
| 6 | The solution would require changing the already-certified Payment Fulfillment implementation | **TRIGGERED for D2's implementation step** — see below |
| 7 | The solution would require changing Preparation | **Not triggered.** Neither decision touches Preparation; its eligibility list is unchanged (§8) |
| 8 | The solution would change Order lifecycle semantics without explicit business authorisation | **TRIGGERED for D1** — which is precisely why the recommendation is D1-C rather than D1-B |

**No workaround was applied to any triggered condition. The exact unresolved questions:**

> **STOP 1 / STOP 8 — for the business, before any D1 implementation.**
>
> ADR-042 §3.1 (2026-08-13) requires `awaiting_payment` when a proof-required method has
> **"no proof supplied"**, but never defines what *supplied* means. When it was written,
> `orders.payment_proof_path` was the only proof that existed. On 2026-08-19 `payment_proofs`
> was introduced; on 2026-08-20 TASK-PAYMENT-PROOF-LIFECYCLE-001 declared the old column
> **superseded**; on 2026-08-21 the hardening made a **verified** `payment_proofs` row the sole
> proof source at confirmation. A canonical proof **cannot exist at creation time**, because
> uploading one requires an order that already exists.
>
> **The question: at order creation, does an unverified receipt attached by Sales count as
> "proof supplied" for the purpose of ADR-042 §3.1?**
>
> - **If NO** — §3.1 must be amended to state that a proof-required method is always created
>   `awaiting_payment`. Consequence: every instapay / bank-transfer / mobile-wallet order parks
>   until Finance verifies. This is the coherent reading of the already-approved
>   Decisions 1 + 3 and is what this audit expects the answer to be.
> - **If YES** — §3.1 must be amended to say so **explicitly**, and to state that the creation
>   path deliberately applies a weaker standard than the confirmation path. The business is then
>   accepting that a proof-required order can complete its entire lifecycle without any payment
>   evaluation ever running.
>
> **Sub-question (§10):** is `payment_proof_policy: required` an **enforced financial control**
> or an **operational prompt**? If enforced, D1 escalates from HIGH-RISK FOLLOW-UP to **BLOCKER**.
>
> **This cannot be answered from code, configuration, or any existing ADR.**

> **STOP 6 — authorisation needed before any D2 implementation.**
>
> D2-B modifies `ConfirmOrderWorkflow::paymentProofRequirement()` (`:169-183`), a method that
> TASK-…-IMPLEMENTATION-001 explicitly recorded as **"untouched"**, inside a file that task
> certified. It also flips one certified assertion:
> `PaymentProofLifecycleTest::test_10_verification_never_writes_order_status_itself`, whose
> fixture is unpaid + channel-less + instapay and which asserts the order *advances*.
>
> **The certified payment *contract* is not affected** — `paymentPermitsConfirmation()`,
> `isPaidInFull()`, `hasVerifiedPaymentProof()` and `ReevaluateOrderFulfillmentAction` all stay
> exactly as certified — and the same task explicitly deferred this default to *"a separate
> decision"* (§18 item 4), which is this one. Even so, touching a certified file and re-fixturing
> a certified test **requires explicit authorisation** and is not assumed here.

---

## 28. Final Recommendation

# D1 → D1-C   ·   D2 → D2-B   ·   BOTH: HIGH-RISK FOLLOW-UP, NOT BLOCKERS

**D1 — Change the contract first.** The creation path is faithful to an ADR whose premise was
replaced six days after approval. Amend ADR-042 §3.1 to define "proof supplied" against
`payment_proofs`, and — since a canonical proof cannot exist before the order does — to state
the entry status for proof-required methods. Rule on the two adjacent entry-contract gaps
(`POST /api/orders` accepting `confirmed`; the shipping override beating §3.1 and falsifying its
audit event) in the same amendment. **Implement only afterwards.** No migration, no RBAC change,
no Preparation change, no WooCommerce change.

**D2 — Delete the short-circuit.** `return 'none';` is a hardcoded configuration value that
terminates a scope chain the platform contract says must continue, and it bypasses a system
default that already answers `required` for exactly the methods in question. Resolve NULL down
the documented chain instead. **Zero live orders change outcome, COD is untouched, WooCommerce
is untouched, and no migration or data remediation is required.** Sequence it into the **same
release** that deploys the hardening — that deployment is what makes the bypass live.

**Neither finding prevents a safe and correct production lifecycle today**, on the evidence: no
live order is mis-stated, no money is at risk, no data needs repair, and the hardened code that
activates D2's exposure is not deployed. One escalation trigger stands, in §10, and the business
must rule on it.

**Three STOP conditions are triggered — 1, 6 and 8 — all of them authorisation stops, none of
them evidence stops.** The audit reached a conclusion in every case; what is missing is the
authority to amend an Approved ADR and to touch a certified file.

**NOTHING WAS IMPLEMENTED. NOTHING WAS COMMITTED. ORD-00003 WAS NOT TOUCHED. NO RBAC SEEDER WAS
RUN. NO USER WAS CREATED. NO BUSINESS DATA WAS MODIFIED.**

**STOP — awaiting explicit approval.**
