# TASK-ORDERS-PAYMENT-CONFIRMATION-FULFILLMENT-DECISION-AUDIT-003 — Decision Audit

**Date:** 2026-08-21 · **Environment:** DEV (`ecos_dev`)
**AUDIT / DECISION ONLY — no code, database, permission, role, user, proof, payment, order
status, FulfillmentEngine run, WooCommerce sync, Preparation change, migration, test or commit.**

---

## 1. Executive Summary

All four STOP areas are now closed **analytically**, and the outcome is better than AUDIT-002
suggested: **no new proof system, no new payment state, no migration, and no new locking
strategy are required.** Every needed mechanism already exists in the codebase.

| Area | Can the existing architecture express the safe contract? | Schema change? |
|---|---|---|
| PR-1 proof security | **YES** — `payment_proofs` already satisfies the full contract | **No** |
| PR-2 RBAC | **YES** — suitable existing roles exist | **No** (administrative grant) |
| Payment gate semantics | **YES**, but the target rule **differs** from today's certified behaviour | **No** |
| WooCommerce mapping | **YES**, but requires an explicit business decision | **No** |
| Concurrency | **YES** — an Order row-lock precedent already exists in this codebase | **No** |

**The one genuine business conflict** is in the gate: the proposed contract requires *payment
**AND** verified proof* for proof-required methods, whereas the certified implementation today
passes on **payment alone**. That is a deliberate tightening, not a bug fix, and needs explicit
approval (§18, Decision 3).

---

## 2. PR-1 Evidence

Two representations, only one of which enforces anything:

| | `payment_proofs` (real lifecycle) | `orders.payment_proof_path` |
|---|---|---|
| Storage | `Storage::disk('local')->put()` — **private disk** | none — string only |
| Path | `payment-proofs/{company_id}/{ulid}.{ext}` | arbitrary |
| Tenant | `company_id` column **+** controller scoping (404 cross-tenant) **+** company in the path | none |
| Order link | `order_id` FK | column on the order |
| Type safety | `mimes:jpeg,jpg,png,webp,gif,pdf`, `max:10240`, MIME **content-sniffed** via `getMimeType()` | none |
| Verification | explicit `Uploaded → Verified` state machine, actor + timestamp | none |
| Validation | full | **`nullable\|string\|max:500`** |

The gate accepts **either**, and the legacy branch is checked **first**:

```php
if (! empty($order->payment_proof_path)) { return true; }   // ← any non-empty string
```

---

## 3. Payment Proof Contract — answers to A–J

| | Question | Finding |
|---|---|---|
| **A** | Is `payment_proof_path` merely a storage reference? | **No.** It is an unvalidated arbitrary string. `payment_proofs.storage_path` is the real storage reference. |
| **B** | What proves the referenced object exists? | For `payment_proofs`: the upload action physically wrote it. For `payment_proof_path`: **nothing**. |
| **C** | How is tenant ownership established? | `payment_proofs`: `company_id` + controller scoping + company-scoped path. `payment_proof_path`: **not established**. |
| **D** | How is the proof associated with the Order? | `payment_proofs.order_id` FK; upload resolves the order within the tenant first. |
| **E** | What constitutes "verified"? | `state = Verified` **AND** `superseded_at IS NULL` (the ACTIVE proof). An `uploaded` proof is evidence submitted, not accepted. |
| **F** | Can an arbitrary string satisfy the gate? | **YES — this is PR-1.** |
| **G** | Can another tenant's proof be referenced? | Through `payment_proofs`: **no** (cross-tenant resolves to 404). Through `payment_proof_path`: the string is never resolved or read, so the risk is **forgery, not disclosure**. |
| **H** | Can a verified proof be replaced after verification? | **YES.** Upload supersedes the active proof **regardless of state**, so a new upload supersedes a Verified one and the active proof becomes `uploaded` again. Re-verification is then required — sound, but it means an order confirmed earlier can later hold **no verified proof**. Documented, not a defect. |
| **I** | Can a rejected proof reopen verification? | **No.** Verify requires `state === Uploaded`, so a rejected row is terminal. The flow reopens only by uploading a **new** proof. Correct. |
| **J** | What if the underlying file disappears? | The gate reads only the DB row — **it never touches the file**. The gate would still pass while download fails. Proof validity is not tied to file existence. |

---

## 4. PR-1 Minimum Safe Fix (proposal — NOT implemented)

**The existing architecture already expresses the contract.** `payment_proofs` satisfies every
requirement in §3. The minimum fix is therefore **subtractive**:

> Stop treating a non-empty `payment_proof_path` as *acceptance*. Let the ACTIVE + VERIFIED
> `payment_proofs` row be the only clearance for a proof-**required** method.

- **No second source of truth is created** — the opposite: two sources collapse to one.
- **No new proof system**, no schema change, no migration.
- The legacy column stays **readable** for historical and WooCommerce-imported orders; the
  decision is only whether it may *clear a required gate*.
- **Optional hardening, not required:** tie validity to file existence (§3-J).

---

## 5. PR-2 Evidence

- Permissions: `sales.orders.proof_upload`, `sales.orders.proof_verify`, `sales.orders.proof_reject`.
- Protected routes: `POST /orders/{id}/payment-proofs` (upload), `POST /payment-proofs/{id}/verify`,
  `POST /payment-proofs/{id}/reject`.
- Declared **only** in the catalogue (`config/permissions.php:63`); grep finds them in **no role**.
- Live grants: **0 each**.
- Super Admin bypasses via `userHasSystemRole()` (`is_system = true`).
- Frontend **hides** the controls (`payment-proof-section.tsx` → `usePermission()` →
  `canUpload` / `canVerify` / `canReject`), so it is not merely a 403.
- **Backend protection is correct** — route middleware enforces the permission independently, so
  bypassing the UI gains nothing. The defect is over-restriction, not under-protection.

Roles currently holding `sales.orders.*`: `company-admin`, `sales`, `sales-manager`,
`sales-representative` (plus view-only roles). **None** holds any proof permission.

---

## 6. Recommended RBAC (recommendation — requires approval)

Suitable existing roles **do** exist, so no new role need be invented.

| Permission | Recommended existing role(s) | Rationale |
|---|---|---|
| `proof_upload` | `sales-representative`, `sales-manager`, `company-admin` | Sales collects the customer's payment evidence |
| `proof_verify` | **`finance-manager`**, `company-admin` | Verifying financial evidence is a finance function |
| `proof_reject` | **`finance-manager`**, `company-admin` | Same authority as verify |

**Separation of duties is the point:** whoever uploads evidence should not be the one who
accepts it. Granting verify to sales would let one person both submit and approve their own
proof — reintroducing PR-1's weakness through legitimate permissions.

This is **administrative configuration, not an engineering change**. A legitimate grant is not a
reason to weaken authorization, and no permission should be relaxed to make the flow work.

---

## 7. Payment Gate — Exact Semantics

**Today (certified):** blocks only when status is `awaiting_payment` **and** none of these hold —

1. `deposit_amount >= total`; or
2. resolved method string is empty; or
3. `paymentProofRequirement(order, method) !== 'required'`; or
4. `hasAcceptedPaymentProof(order)`.

They are **OR-ed**, and condition 1 is evaluated first.

**Proposed target contract:**

| Case | Rule |
|---|---|
| COD / payment not required | passes without payment |
| Method does **not** require proof | sufficient payment is enough |
| Method **requires** proof | sufficient payment **AND** valid proof **AND** proof verified |

**Compatibility verdict:** structurally compatible — the policy (`payment_proof_policy`), the
proof lifecycle and the requirement resolver all already exist. **But it is a real behavioural
change**, in two places:

1. **`OR` → `AND` for proof-required methods.** Today a fully-paid instapay order passes on
   payment alone; under the target it would also need a verified proof.
2. It **removes** the `payment_proof_path` branch (PR-1).

Change 1 **tightens** the certified contract. It is not a defect fix and must not be applied
silently — **Decision 3 (§18)**.

Live impact if adopted: **ORD-00003** (instapay, 10 000/10 000 paid, proof **VERIFIED**) passes
under **both** the current and target contracts, because it satisfies payment *and* verified
proof. ORD-00004/00005 remain blocked under both.

---

## 8. COD Contract — unchanged under both rules

`"cod": "none"`, `"cash": "none"` ⇒ requirement ≠ `required` ⇒ the gate never demands payment.

The target contract preserves this exactly: COD stays **fulfillment-eligible without deposit**,
is **never artificially marked paid** (`deposit_amount` stays `0.00`), and remains governed by
the existing policy. Verified live: 10 of 11 COD orders progressed with `deposit_amount = 0.00`.

**No COD change is proposed.**

---

## 9. WooCommerce Mapping Audit

| Question | Finding |
|---|---|
| Which ECOS statuses can reach Woo? | **Only `cancelled`** — the sole intersection |
| Unmapped | `in_progress`, `confirmed`, `ready_for_dispatch`, `out_for_delivery`, `delivered`, `awaiting_payment`, `awaiting_stock`, `scheduled`, `on_hold`, `returned` (**10 of 11**) |
| Behaviour on unmapped | `markFailed("No WooCommerce mapping for status [x]")` |
| Silent? | **Yes** — `markFailed` records and returns; it never throws, so the order transition is unaffected |
| Does `confirmed` have an equivalent? | Not in the map today (proposal §10) |
| Does `in_progress`? | Not in the map today |
| Should `awaiting_payment` map? | Debatable — Woo `pending`/`on-hold` are candidates; **not required** for this transition |
| Sync before or after the transition? | **After, necessarily** — the observer fires on `Order::updated`, i.e. after the status write |
| Is Woo sync idempotent? | Re-PUTting an identical status is harmless, but **ECOS emits one PUT per hop**, so a multi-hop chain produces several |
| Recursion risk? | **None found** — inbound import and outbound push are separate paths |

`STATUS_MAP`'s `pending` / `processing` / `completed` are **not ECOS statuses at all** — the map
was authored against a different vocabulary. **This is pre-existing and independent of the
proposed transition.**

---

## 10. WooCommerce Proposed Mapping (transition-relevant statuses only)

Scoped strictly to what the automatic transition can produce — this is **not** a redesign of all
statuses.

| ECOS status | Proposed Woo status | Note |
|---|---|---|
| `confirmed` | `processing` | Woo's `processing` = accepted and being fulfilled — the closest equivalent |
| `in_progress` | `processing` | Same target; ECOS distinguishes them, Woo does not |

**No exact equivalent exists** — Woo has a coarser vocabulary, so two ECOS states collapse into
one. Consequences requiring an explicit decision:

- The sync becomes **lossy** (ECOS `in_progress` and `confirmed` are indistinguishable in Woo).
- A transition `in_progress → confirmed` would PUT `processing` **twice** — harmless to Woo, but
  it produces two sync log rows.

**Alternative equally valid:** leave both unmapped and accept that these statuses do not sync,
recording that ECOS is the system of record for them. **Decision 4 (§18).**

---

## 11. Concurrency Audit

`FulfillmentEngine::run()`:

```php
$workflow->guard($ctx);                 // ← OUTSIDE the transaction
...
DB::transaction(fn () => $workflow->execute($ctx));
```

No `lockForUpdate()`, no row lock, no optimistic version column, no idempotency key, no unique
constraint on the transition. With `QUEUE_CONNECTION=sync` everything runs inline, so two
overlapping HTTP requests are genuinely concurrent.

**Two triggers (record-payment, proof-verified) can therefore both read `awaiting_payment`, both
pass the guard, and both execute** — producing duplicate `OrderConfirmedEvent`s, duplicate
`OrderEvent` audit rows, and duplicate outbound Woo PUTs.

**Existing patterns in this codebase (no new strategy needed):**

| Precedent | Pattern |
|---|---|
| `ReserveOrderInventoryAction:138` | `Order::whereKey($order->id)->lockForUpdate()->first()` — **an Order row lock already exists** |
| `WaveLifecycleService:50` | `lockForUpdate()` + existence re-check, documented as "the concurrency guard" |
| `WaveMembershipService::postponeOrder` | scoped `UPDATE ... WHERE postponed_at IS NULL` — **the update is its own idempotency guard** |

---

## 12. Idempotency Contract

Required guarantee: *at most one lifecycle transition from a given Order state; repeated
evaluation is safe; concurrent triggers yield one advance and one no-op.*

**Current architecture:**

| Property | Status |
|---|---|
| Sequential repeat | **SATISFIED** — the guard rejects a non-allowed source status |
| Concurrent repeat | **NOT SATISFIED** — guard evaluated outside the lock |
| Duplicate events | **POSSIBLE** |
| Duplicate audit rows | **POSSIBLE** |
| Duplicate Woo sync | **POSSIBLE** |

**Minimum required change** (proposal): perform the re-evaluation inside a transaction that
takes `Order::whereKey($id)->lockForUpdate()`, re-read the status **inside** the lock, and only
then run the workflow. This reuses precedent verbatim and needs no schema, no new column and no
new locking strategy. Either of the two existing patterns above is sufficient.

---

## 13. Canonical Re-evaluation Entry Point

**Strongly recommended: ONE entry point, called from both triggers.**

```
record-payment ─┐
                ├─► [single re-evaluation service] ─► lock ─► re-read ─► ConfirmOrderWorkflow
proof verified ─┘                                    ─► FulfillmentEngine ─► existing events
```

Two independently implemented transition paths would double the concurrency surface, double the
places PR-1/PR-2 rules must be honoured, and make the "at most one transition" guarantee
untestable. A single entry point is also the only way the concurrency guard in §12 can be
enforced once rather than twice.

---

## 14. Preparation Boundary — confirmed, no change

`eligible_order_statuses = ["in_progress","confirmed"]`; Preparation reads **order status only** —
never payment status, never payment method, never proof.

So once the order reaches `confirmed`, the existing collector sees it **naturally**. **No
Preparation change, and no new Preparation event, is required** — the audit found no gap that
would need one.

---

## 15. Security Classification (kept separate — not one patch)

| Item | Classification |
|---|---|
| **PR-1** — arbitrary string satisfies proof | **Security defect** |
| **PR-2** — zero grants, controls hidden | **Authorization / operational defect** |
| **WooCommerce mapping** | **Integration correctness defect** (pre-existing, independent) |
| **Concurrency** | **Data integrity / workflow correctness defect** |

These have different owners, different risk profiles and different urgencies. **They must not be
combined into a single patch.** PR-2 in particular is administrative, not engineering.

---

## 16. Implementation Dependency Graph (proposal)

```
A. PR-1 proof security contract        ── must precede E
                                          (automation would advance forged proofs
                                           faster and more silently than a human)
B. PR-2 RBAC grant  (administrative)   ── must precede F
                                          (no operator can exercise the flow otherwise)
C. Payment gate semantics (Decision 3) ── must precede E; changes what E enforces
D. WooCommerce mapping (Decision 4)    ── INDEPENDENT of E; may run in parallel
E. Concurrency-safe canonical entry    ── the actual orchestration fix
F. Focused E2E acceptance              ── requires B
```

**The brief's preferred order A → B → C → D → E → F holds**, with one refinement: **D is
independent** of the others and need not block E, because Woo sync failures are silent and never
roll back a transition. Sequencing D before E only avoids generating new failed sync logs.

---

## 17. Focused Test Plan (proposed — none implemented)

1. COD passes without payment.
2. Non-proof method passes with sufficient payment.
3. Proof-required method fails with no proof.
4. **Proof-required method fails with an arbitrary `payment_proof_path` string** (PR-1 regression).
5. Proof-required method fails with an **uploaded but unverified** proof.
6. Proof-required method passes only with an ACTIVE **verified** proof.
7. Cross-tenant proof cannot satisfy the gate.
8. Recording payment triggers exactly one safe re-evaluation.
9. Verifying proof triggers exactly one safe re-evaluation.
10. Concurrent triggers ⇒ **one** transition, **one** event, **one** audit row.
11. Preparation eligibility still correct after the transition.
12. WooCommerce receives the mapped status **exactly once**.

Reuse `PaymentProofLifecycleTest` (23 green) as the existing base. No full regression at this
stage.

---

## 18. Open Decisions

| # | Decision | Category |
|---|---|---|
| 1 | May `payment_proof_path` continue to clear a **required**-proof gate? *(Recommendation: no)* | **G — user approval** |
| 2 | Grant `proof_verify`/`proof_reject` to `finance-manager` + `company-admin`, and `proof_upload` to sales roles? | **G — user approval (administrative)** |
| 3 | Adopt **payment AND verified proof** for proof-required methods, replacing today's **payment OR proof**? *(This tightens a certified contract)* | **G — user approval** |
| 4 | Map `confirmed`/`in_progress` → Woo `processing`, or leave unmapped? | **G — user approval** |
| 5 | Trigger re-evaluation from record-payment, proof-verified, or both? *(Recommendation: both, via one entry point)* | **F — recommendation** |
| 6 | Should confirmation remain an explicit human action rather than automatic? | **G — user approval** |

### Classification summary

| Category | Items |
|---|---|
| **A — Existing contract confirmed** | Gate structure (§7); COD (§8); Preparation boundary (§14); sequential idempotency (§12); proof state machine — rejected is terminal, replacement requires re-verification (§3 H/I) |
| **B — Confirmed security defect** | PR-1 (§2–§4) |
| **C — Confirmed operational blocker** | PR-2 (§5) |
| **D — Confirmed integration blocker** | WooCommerce map covers 1 of 11 statuses (§9) |
| **E — Confirmed concurrency blocker** | Guard outside the lock; no row lock (§11) |
| **F — Recommendation** | Single canonical entry point (§13); dependency graph (§16); test plan (§17) |
| **G — Requires explicit user approval** | Decisions 1, 2, 3, 4, 6 (§18) |

---

## 19. STOP Conditions

All four AUDIT-002 STOP conditions are **analytically closed** — each has a minimum safe path
that needs **no schema change**:

| STOP condition | Closed by | Schema? |
|---|---|---|
| Proof forgeable | §4 — make `payment_proofs` the only clearance | **No** |
| Proof permissions unusable | §6 — grant to existing roles | **No** (administrative) |
| Woo cannot consume the transition | §10 — map, or explicitly accept non-sync | **No** |
| Engine not concurrent-safe | §12 — reuse the existing `lockForUpdate` precedent | **No** |

**No new STOP condition was discovered.** **No migration is required for any part of this work.**

They are closed *analytically*, not *operationally* — each still needs the corresponding
decision in §18 before implementation may begin.

---

## 20. Final Recommendation

**Authorise in this order, and only by explicit decision:**

1. **Decision 1 + Decision 3 together** — they are the same question seen twice (what counts as
   proof, and whether payment alone suffices). Deciding them separately risks an inconsistent
   gate.
2. **Decision 2** — administrative RBAC grant; unblocks all end-to-end verification. Nothing can
   be browser-verified until this is done.
3. **Decision 4** — WooCommerce mapping; independent, may proceed in parallel.
4. **Then** implement the concurrency-safe canonical re-evaluation entry point (§13 + §12) — the
   actual orchestration fix, and the smallest piece of the work.

The orchestration fix everyone is waiting for is genuinely small. What makes it unsafe today is
not its own complexity but the three defects it would automate: forgeable proof, an unusable
permission model, and a broken outbound map. **Fix the evidence contract before automating the
decision that relies on it.**

**COD requires no change. Preparation requires no change. No migration is required anywhere.**

---

**AUDIT / DECISION ONLY. Nothing was implemented, modified, granted, created, triggered or
committed. STOPPING — awaiting explicit authorization.**
