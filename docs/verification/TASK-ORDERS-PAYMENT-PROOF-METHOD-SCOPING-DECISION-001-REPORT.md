# TASK-ORDERS-PAYMENT-PROOF-METHOD-SCOPING-DECISION-001 — REPORT

**Status:** FINAL ARCHITECTURE / BUSINESS DECISION — **READ ONLY**
**Date:** 2026-08-22
**Branch:** `develop`
**Closes:** the single open STOP from `…-IMPLEMENTATION-002-FINAL-REPORT.md` §23.1
**Written in English** to match every other report in `docs/verification/`; the brief was in Arabic.

**No code, schema, migration, permission, financial record, order or payment proof was created or
modified. Nothing was committed. Every database statement was a `SELECT`.**

> **Note on the brief.** The instruction in PART 10 ends mid-sentence at «إلا». I have applied the
> rule as written and in its strictest form: **no payment method is inferred from filename, storage
> path, amount, order name, user, or timestamp anywhere in this report.** The one backfill source I
> do identify (§10) is the order's own `payment_method_manual` column plus the *absence* of any
> change event — a direct authoritative attribute, not an inference from any of the forbidden
> signals. If the truncated clause intended a further restriction, this conclusion should be
> re-checked against it.

---

## Executive Summary — the decision

# OPTION B — PAYMENT-FACT PROOF

**A `payment_proofs` record proves that money arrived for an order. It does not, and structurally
cannot, prove which rail it arrived on. It must not be method-scoped.**

This is not the cheap answer chosen to avoid a migration. It is what the code, the contract, the
state machine, the API, the UI and the financial model all already say, consistently, in five
independent places:

1. **The proof record carries no payment semantics whatsoever** — no amount, no transaction
   reference, no value date, no method. `UploadPaymentProofAction` accepts exactly `(Order,
   UploadedFile)`. It is a *document attached to an order*, not a payment instrument record (§1).
2. **The system states outright that a proof is not a payment.** `PaymentProofState`'s own
   docblock: *"These are NOT Order statuses and NOT payment states. A proof is evidence: its state
   never marks a payment PAID."* Certified test B11.9 pins the same thing (§1).
3. **The money itself has no method binding to inherit.** There is **no order-payments table**.
   Payment is `orders.deposit_amount` — one cumulative scalar. `payment_method_manual` is a
   *mutable order attribute*, not a property of any payment event. Method-scoping the proof would
   bind the **evidence** more tightly than the **money it evidences** (§6).
4. **The gate asks an order-level question, and both of its facts are order-level.**
   `isPaidInFull(Order)` **AND** `hasVerifiedProof(Order)`. Making one method-scoped and the other
   not produces a mismatched pair that cannot be reasoned about (§8).
5. **The control that catches a mismatched document already exists, and it is human.** A named
   Finance actor opens the file and accepts or rejects it, with a mandatory reason. A schema column
   cannot judge whether a receipt matches a claim; it can only compare a label an operator typed
   (§7).

**OPTION C is not merely expensive — it is unimplementable within the approved contract.** There is
**no transition out of `Verified`**. `VerifyPaymentProofAction` accepts only `Uploaded → Verified`;
`RejectPaymentProofAction` only `Uploaded → Rejected`. The only field that deactivates a proof is
`superseded_at`, written **exclusively** by a new upload and paired with `replaces_proof_id`.
Setting it on a method change would assert *"this proof was replaced by a newer proof"* — a
statement that would be **false**, corrupting the supersession chain and the audit trail (§5).

**Severity of the open ambiguity: LOW.** It is a bookkeeping-accuracy concern, not a security or
financial-integrity breach (§7). **No migration. No backfill. No remediation of the 4 existing
proofs (§10). No STOP condition triggered.**

One **optional, migration-free** safeguard is recommended for a *separate* task (§3.4): emit an
`OrderEvent` when the payment method changes on an order carrying an active verified proof, so
Finance can re-review. Observability, not enforcement — no schema, no new state, no new policy
source.

---

## PART 1 — Current Proof Contract

| Question | Answer, from the code |
|---|---|
| **What does `payment_proofs` represent?** | A **file** attached to an **order**, plus its review state and attribution. Columns: `id, company_id, order_id, state, storage_disk, storage_path, original_filename, mime_type, size_bytes, uploaded_by, uploaded_at, verified_by, verified_at, rejected_by, rejected_at, rejection_reason, superseded_at, replaces_proof_id`. **There is no amount, no transaction reference, no value date, and no payment method.** |
| **Is it proof of a "financial transfer"?** | It is proof that *someone submitted a document as evidence for this order's payment*, and that a named reviewer accepted it. It asserts nothing about rail or amount by itself. |
| **Is it proof of "payment by a specific method"?** | **No.** Nothing in the record, the upload flow, the API resource or the UI carries a method. The claim cannot be made from the data. |
| **Who uploads?** | Sales — `POST /orders/{order}/payment-proofs`, `permission:sales.orders.proof_upload`. Payload is **the file only**; no metadata fields exist. |
| **Who approves?** | Finance / tenant admin — `POST /payment-proofs/{proof}/verify`, `permission:sales.orders.proof_verify`. No role holds both verbs (verified live). |
| **When does it become active?** | "Active" is not a state — it is `superseded_at IS NULL`. The active proof is the newest non-superseded one. |
| **What happens on rejection?** | `Uploaded → Rejected`, mandatory free-text reason, rejector + timestamp recorded. **The file is retained and is NOT superseded.** Payment amount and order status untouched. |
| **What happens when a new proof is uploaded?** | The current active proof is stamped `superseded_at` (retained, never deleted) and the new proof records `replaces_proof_id`. One action covers first upload and replacement. |
| **Can the payment method change after a proof is uploaded?** | **Yes.** `payment_method_manual` is a SOFT field on the order, editable via `PATCH /orders/{id}/quick-update` and `PUT /orders/{id}` under `sales.orders.update`. |
| **Can it change after verification?** | **Yes — including on a `confirmed` order**, because soft fields remain editable when the order is structurally locked. Since IMPLEMENTATION-002 the change re-runs the gate in both directions. |

**The decisive line, quoted from the system's own contract** (`PaymentProofState`):

> *"These are NOT Order statuses and NOT payment states. A proof is evidence: its state never marks
> a payment PAID and never moves the order lifecycle."*

Every action repeats it: upload — *"evidence only — payment state unchanged"*; verify — *"evidence
review only … does NOT write Order status, create a second payment, or modify deposit_amount"*;
reject — *"does NOT mark payment paid, modify the payment amount, write Order status"*.

---

## PART 2 — Payment Method Semantics

Methods actually reachable (`StoreManualOrderRequest`, `PatchOrderRequest`, `UpdateOrderRequest`
all whitelist the identical five):

```
cod · instapay · mobile_wallet · credit_card · bank_transfer
```

Resolved requirement (`BrandPolicy::defaultSettings('order')`, and the live `ecos_dev` brand policy):

| Method | Code default | Live brand policy | Proof required? |
|---|---|---|---|
| `cod` | `none` | `none` | **No** |
| `cash` | — | `none` | **No** — *and unreachable*: `cash` is a policy key with no matching method in any request whitelist. A dead key. |
| `instapay` | `required` | `required` | **Yes** |
| `bank_transfer` | `required` | `required` | **Yes** |
| `mobile_wallet` | `required` | `required` | **Yes** |
| `credit_card` | `optional` | `required` | Policy-dependent — `optional` does not block |

**1. Which methods require proof?** `instapay`, `bank_transfer`, `mobile_wallet` — and
`credit_card` under the live policy.

**2. Which do not?** `cod` (and `cash`, unreachable). This is the approved COD contract and is
untouched by every option here.

**3. Do all proof-required methods use the same kind of evidence?** **The system cannot express a
difference.** The upload accepts any image or PDF (`UploadPaymentProofRequest`) with no type,
category or method field. Operationally the documents differ — an InstaPay screenshot, a bank
transfer advice, a wallet SMS — but **that distinction exists only in the reviewer's head and in the
pixels of the file.** It is not, and never has been, a datum this system holds.

**4. Does a verified proof prove "paid", or "paid by this method"?** It proves **neither on its
own**. It proves *evidence was submitted and a named Finance actor accepted it*. "Paid" comes from a
separate, independent fact — `deposit_amount >= total` — which is why the gate **AND**s them. The
method claim is a third thing, carried by a mutable order attribute that no proof ever referenced.

---

## PART 3 — Business Decision

### 3.1 OPTION A — Method-scoped proof · **REJECTED**

| | |
|---|---|
| **Pros as briefed** | Prevents reuse across methods; explicit contract; removes ambiguity |
| **Cons as briefed** | Migration; contract change; must handle existing proofs |

**Additional findings that decide it, beyond the briefed cons:**

- **It would bind evidence to a mutable attribute.** `payment_method_manual` can be changed at any
  time under an ordinary edit verb. A proof stamped `instapay` on an order now labelled
  `bank_transfer` states a contradiction the schema cannot resolve — so A necessarily drags in C's
  semantics ("then invalidate it"), and C is unimplementable (§3.3).
- **It inverts the strictness of the model.** The money — the thing that actually matters — carries
  **no** method binding at all (§6). Method-scoping the evidence while the payment itself stays
  method-agnostic makes the weaker artifact the stricter one.
- **It cannot capture what it claims to.** A `payment_method` column records *the order's label at
  upload time*, not *what the document shows*. A sales user who mislabels the order produces a
  proof stamped with the wrong method and the system would treat that stamp as authoritative. The
  column creates an appearance of rigour it cannot deliver.
- **UI/UX contract change.** `PaymentProofResource` exposes no method; `payment-proof-section.tsx`
  has no method concept. Honouring A means the uploader must declare a method — a new input on a
  flow whose entire payload is currently one file.

### 3.2 OPTION B — Payment-fact proof · **SELECTED**

**Proof proves the payment fact for the order; the payment method is a separate, independent
attribute.** This is the contract as already written, in every layer (§1, §2, §6, §8).

- No migration, no schema change, no backfill, no data remediation.
- Existing data, API shape, UI and tests all remain correct **as they are** — not tolerated, but
  *correct*, because they already encode exactly this reading.
- `PaymentFulfillmentGate` remains the single source of truth with **zero** code change (§8).

**Its one briefed con — "does not prevent reuse of a proof across methods" — is true but overstated,
and is mitigated by controls that already exist:**

- Reaching the ambiguity requires an order **already paid in full** and **already carrying Finance-
  accepted evidence**, then a method change to another proof-required method. The money is real and
  the evidence was accepted; only the label moved.
- Finance can already `reject` an unconvincing proof, and any party can `upload` a replacement,
  which supersedes the old one. **The corrective mechanism exists and needs no schema.**
- COD is untouched: no proof is required, so the ambiguity cannot arise there.

### 3.3 OPTION C — Method change invalidates proof · **REJECTED — UNIMPLEMENTABLE AS CONTRACTED**

This is not a cost judgement. The approved state machine cannot express it (§5):

- **No transition leaves `Verified`.** `VerifyPaymentProofAction` guards `state === Uploaded`;
  `RejectPaymentProofAction` guards `state === Uploaded`. Nothing accepts a `Verified` proof as
  input. Implementing C requires **a new state or a new transition** — both forbidden.
- **`superseded_at` cannot be borrowed for it.** It is written only by `UploadPaymentProofAction`
  and always paired with `replaces_proof_id` on the successor. Writing it on a method change would
  assert a replacement that never happened, breaking the supersession chain and lying in the audit
  trail.
- **It retroactively voids accepted financial evidence on the strength of a label edit** —
  precisely what IMPLEMENTATION-002's brief forbade ("do not retroactively rewrite historical
  financial facts").

### 3.4 OPTION D — not required

No fourth contract is needed. The code and contract do not prove a different necessity; they prove
B. For completeness, the **optional safeguard** that would close B's observability gap is recorded
here — it is **not** a different option, it is an implementation detail of accepting B, and it is
**out of scope for this decision task**:

> When `payment_method_manual` changes on an order that carries an **active verified** proof, emit
> an `OrderEvent` recording the previous method, the new method, and the proof id — so Finance can
> re-review the evidence against the new claim.

Uses the existing `OrderEvent` audit mechanism only. **No schema, no migration, no new state, no
new policy source, no change to `PaymentFulfillmentGate`'s decision.** It makes the situation
*visible* rather than *enforced*, which matches where the real control lives — with the human
reviewer.

---

## PART 4 — Existing Data (read-only)

`ecos_dev`, `SELECT` only.

**Total `payment_proofs`: 4.**

| # | Order | State | Active (`superseded_at IS NULL`) | Uploaded | Verified | Order method **now** | Order status | Total / Paid |
|---|---|---|:--:|---|---|---|---|---|
| 1 | ORD-00003 | `verified` | ✅ | 2026-08-19 21:40 | 2026-08-19 21:41 | `instapay` | `awaiting_payment` | 10 000 / 10 000 |
| 2 | ORD-00004 | `rejected` | ❌ superseded | 2026-08-19 21:42 | — | `instapay` | `awaiting_payment` | 10 000 / 0 |
| 3 | ORD-00004 | `uploaded` | ✅ | 2026-08-19 21:43 | — | `instapay` | `awaiting_payment` | 10 000 / 0 |
| 4 | ORD-00005 | `verified` | ✅ | 2026-08-19 21:47 | 2026-08-21 06:41 | `instapay` | `awaiting_payment` | 10 000 / 3 000 |

**All four belong to orders whose method is `instapay`.**

**Would any become ambiguous under method-scoping?** **No — and this is verifiable, not assumed.**
A direct query for `field_updated` events carrying `payment_method` on ORD-00003 / ORD-00004 /
ORD-00005 returns **zero rows**: the payment method on those three orders has **never changed since
creation**. The method in force when each proof was uploaded is therefore the method the order
carries today.

**This does not generalise.** ORD-00008 demonstrates that methods *do* get changed in practice — its
event history shows `payment_method_manual` moving between `instapay`, `cod`, `mobile_wallet`,
`credit_card` and `bank_transfer` repeatedly. Today's clean population is a property of these four
rows, not a property of the system.

---

## PART 5 — Existing Proof State Machine

**States actually implemented** (`PaymentProofState`) — exactly three:

```
uploaded · verified · rejected
```

`active` and `superseded` are **not states**. They are one nullable timestamp: `superseded_at`,
where `NULL` = active. `PaymentProofResource` exposes this as a derived boolean with the comment
*"Active = the newest non-superseded proof (no separate 'active' state)."*

**Transitions that exist:**

```
(new upload) ─────────────► uploaded
uploaded ──[verify]───────► verified          guard: state === uploaded
uploaded ──[reject]───────► rejected          guard: state === uploaded
any active ──[new upload]─► superseded_at set + successor.replaces_proof_id
```

**Transitions that do NOT exist:**

- `verified → anything`
- `rejected → anything`
- any un-supersede
- any state change triggered by an order field

**Answer to the question posed: can a Verified proof survive a payment-method change?**

**Under the contract as written — yes, necessarily, because nothing can act on it.** There is no
transition that takes a `Verified` proof as input, and `superseded_at` is written only by a new
upload. A verified proof therefore remains verified and active until a *replacement is uploaded*,
regardless of anything that happens to the order. **No new state was invented to answer this.**

---

## PART 6 — Financial Safety

| Area | Impact of this decision | Evidence |
|---|---|---|
| **Payment** | None. Proofs never write `deposit_amount`. Payment is recorded only by `RecordOrderPaymentAction`, with its overpayment guard and idempotency intact | Verify/reject/upload actions all state and enforce it |
| **Order payment state** | None. `PaymentState` is derived from `deposit_amount` vs `total` and never consults a proof | `PaymentState::fromAmounts()` |
| **Financial ledger / journals** | **No coupling exists.** A repository search of `Modules/Finance` for `payment_recorded`, `payment_proof_verified`, `PaymentProof`, and order-payment references returns **no matches** | grep, `Modules/Finance` |
| **Accounts Payable** | Unaffected — `finance_supplier_payments` / `finance_payment_allocations` are supplier-side. `approverCannotBeMaker()` lives there and is untouched | Table survey + §9 of IMPLEMENTATION-002 |
| **Accounts Receivable** | **There is no customer-payments table.** Order payment is the single scalar `orders.deposit_amount`; the only `%payment%` tables are `distribution_payment_collections`, `finance_payment_allocations`, `finance_supplier_payments`, `payment_proofs`, `pos_payments` — none of which is order AR | `SHOW TABLES LIKE '%payment%'` |
| **Supplier invoice** | No relationship to order payment proofs |
| **COD** | Untouched under every option — `cod`/`cash` resolve `none`, so the gate never reaches the proof clause |
| **Audit trail** | Under B: unchanged and intact. Under C it would be **damaged** — `superseded_at` would record a replacement that never occurred (§3.3) |

**The load-bearing financial fact for this decision:** the money carries no method binding. There is
no record anywhere that says *"this 10 000 arrived via instapay"*. There is an order-level label and
a cumulative scalar. **A method-scoped proof would be the only method-bound financial artifact in
the system**, attached to the weakest layer — the evidence, not the money.

---

## PART 7 — Security

**Is method-scoping required as a security boundary? — No.**
**As a financial-integrity control? — No.**
**As a business rule? — Optional, and better served by the human control that already exists.**

Honest severity assessment of the ambiguity:

| Dimension | Finding |
|---|---|
| **Privilege required** | `sales.orders.update` — an ordinary order-edit verb. **No escalation**: the actor can already edit the order |
| **Reachability** | Requires an order **paid in full** *and* carrying an **active verified** proof, then changed between two proof-required methods. COD cannot reach it |
| **What is actually false afterwards** | Only the *label*. The money arrived; Finance accepted the evidence. Neither fact is fabricated |
| **Money movement** | None. No option here can move, create or release money |
| **Tenant isolation** | Unaffected — `PaymentProofController` scopes every lookup to the company (cross-tenant → 404) |
| **Separation of duties** | Unaffected — no role holds both `proof_upload` and `proof_verify` (verified live) |
| **Fraud vector?** | Weak. To exploit it deliberately, an actor must already have caused real money to be received and real evidence to be accepted by a different person in Finance. The "gain" is a mislabelled channel |
| **Bypass of the D1-A control?** | **No.** Both required facts — sufficient payment AND an active verified proof — genuinely hold |

**Severity: LOW.** This is a **reconciliation and bookkeeping-accuracy** concern: the order's stated
channel may not match the channel the accepted document evidences. It is worth *seeing* (§3.4); it
does not warrant a schema change, and it should not be described as a security defect.

---

## PART 8 — Existing Implementation / No Duplicate Gate Logic

**`PaymentFulfillmentGate` must remain the single source of truth — and under OPTION B it does so
with zero code change.**

Everywhere `payment_proofs`, `payment_proof_path`, `payment_method` or proof review is read today:

| Location | Role | Impact under B |
|---|---|---|
| `PaymentFulfillmentGate` | **THE decision authority** — `permits()`, `permitsAtCreation()`, `requirementFor()`, `isPaidInFull()`, `hasVerifiedProof()` | **None** |
| `ConfirmOrderWorkflow` | Delegates to the gate | **None** |
| `CreateManualOrderAction`, `CreateOrderAction` | Delegate to the gate | **None** |
| `ReevaluateOrderFulfillmentAction` | Asks the gate; runs `ConfirmOrderWorkflow` / `ReturnToPaymentWorkflow` | **None** |
| `PatchOrderAction`, `UpdateOrderAction` | Trigger re-evaluation on method change | **None** |
| `Upload/Verify/RejectPaymentProofAction`, `PaymentProofController` | Proof **lifecycle** — deliberately holds no eligibility logic | **None** |
| `PaymentProofResource` | API shape — no method field | **None** |
| `OrderResource` (`payment_proof_path`), `order-column-defs.tsx` ("Proof Received" badge) | Display only — no lifecycle authority | **None** (badge accuracy remains the separate follow-up already logged) |
| `payment-proof-section.tsx`, `order-payment-cell.tsx` | UI — no policy logic | **None** |

**Had OPTION A been chosen**, the method comparison would have to live **inside**
`PaymentFulfillmentGate::hasVerifiedProof()` and nowhere else — the moment a second component
compares a proof's method to an order's method, the exact defect class IMPLEMENTATION-002 eliminated
is recreated. **Selecting B removes that risk entirely.**

---

## PART 9 — Migration Impact (conditional — NOT recommended, NOT to be executed)

Recorded only because the brief requires it should A or C ever be revisited. **No migration is
proposed and none should be run on the strength of this report.**

| Item | Specification |
|---|---|
| **Column** | `payment_proofs.payment_method` |
| **Type** | `varchar(30)` — matching the reachable whitelist vocabulary, not an enum (the method list is configuration-adjacent and has already shifted once) |
| **Nullable?** | **NULLABLE, and permanently so.** Historic rows predate the concept; a `NOT NULL` column would force a fabricated value for every pre-existing proof. Nullable is the only honest shape |
| **Backfill strategy** | **This report does not choose a value.** The only defensible *source* is `orders.payment_method_manual` **restricted to orders with no `payment_method_manual` change event** — a direct authoritative attribute, never an inference from filename, path, amount, order name, user or timestamp. Rows on orders whose method has changed must be left `NULL` for human review |
| **Existing records** | 4 rows today, all eligible for safe backfill (§10). The rule must nonetheless be written for the general case, because methods demonstrably change (ORD-00008) |
| **Gate semantics** | `hasVerifiedProof()` becomes method-aware **inside the gate only** (§8). A `NULL` method must be decided explicitly — treating NULL as "matches anything" reproduces the current behaviour; treating it as "matches nothing" would invalidate every historic proof, including ORD-00003's. **This is itself a business decision and is not made here** |
| **API impact** | `PaymentProofResource` gains a field; `UploadPaymentProofRequest` gains an input, or the method is captured server-side from the order at upload time |
| **Frontend impact** | `payment-proof-section.tsx` must display it; the upload flow must supply or show it; EN + AR strings required |
| **Tests impact** | `PaymentFulfillmentGate` coverage, `OrderPaymentContractImplementation002Test` group D (especially `test_d5`), `PaymentProofLifecycleTest`, and every fixture calling `PaymentProof::create()` |
| **Rollback** | Dropping the column is mechanically safe (the gate's pre-change behaviour is method-agnostic), but any *business decision* recorded through it — e.g. proofs rejected because of a method mismatch — is not recoverable from the column alone |

**STOP check required by PART 9:** *"if existing data does not permit a safe backfill: STOP."*
Current data **does** permit a safe backfill (§10). **No STOP is triggered.** This is a property of
the 4 rows present today, not a standing guarantee.

---

## PART 10 — Existing Proof Records

**What do we do with the 4 existing proofs?**

# Nothing. No action is required, and none is proposed.

**Path selected: they remain exactly as they are.** Under OPTION B there is no `payment_method`
column, so there is nothing to backfill, nothing to review, and nothing to leave NULL. The four rows
are already correct under the contract this report confirms.

For completeness, had A or C been selected, the classification of these same four rows would be:

| Path | Applies? | Basis |
|---|---|---|
| **Safe backfill available** | ✅ **Yes, for all 4** | All four sit on orders whose `payment_method_manual` is `instapay`, and a direct query proves **no `payment_method` change event has ever occurred** on ORD-00003 / ORD-00004 / ORD-00005. The method at upload time is therefore the method the order holds now — read from the order's own authoritative column, corroborated by absence of change, **not inferred** from filename, path, amount, order name, user or timestamp |
| Needs human review | ❌ Not for these 4 | Would apply to any proof on an order with a method-change event — none of these three have one. ORD-00008 shows such orders exist in general |
| Remains NULL historically | ❌ Not needed for these 4 | Would be the correct treatment for change-affected orders |
| Different migration strategy | ❌ Not needed | — |

**No corrective record was created. No proof was modified, superseded, verified or rejected. No
payment method was written to any proof.** The `payment_proofs` table stands at 4 rows, unchanged.

---

## Decision Record

| Item | Outcome |
|---|---|
| **Decision** | **OPTION B — PAYMENT-FACT PROOF.** A proof evidences the payment fact for an order; it is not method-scoped |
| **Rejected** | **A** — inverts model strictness, binds evidence to a mutable label, cannot capture what it claims. **C** — unimplementable: no transition out of `Verified`, and `superseded_at` cannot be borrowed without lying in the audit trail. **D** — not required |
| **Implementation required** | **None.** Zero code, zero schema, zero data change |
| **Migration** | **No** |
| **Schema change** | **No** |
| **Backfill / remediation** | **No** — the 4 existing proofs stand as-is |
| **RBAC change** | **No** |
| **API / frontend change** | **No** |
| **Source of truth** | `PaymentFulfillmentGate`, unchanged — no duplicate gate logic created |
| **COD impact** | **None** |
| **Financial impact** | **None** — no ledger, AP or AR coupling exists |
| **Security severity** | **LOW** — bookkeeping accuracy, not a security or integrity breach |
| **STOP conditions** | **None triggered.** The IMPLEMENTATION-002 §23.1 STOP is hereby **CLOSED** by decision |
| **Optional follow-up** (separate task, migration-free) | Emit an `OrderEvent` when the method changes on an order holding an active verified proof, so Finance can re-review (§3.4) |

---

## Final Statement

The open STOP from IMPLEMENTATION-002 asked whether a proof raised for one payment rail may satisfy
another. The answer the system has been giving all along — in its schema, its state machine, its
actions, its API, its UI and its financial model — is that **a proof was never a statement about a
rail.** It is a document, submitted by Sales and accepted by Finance, that this order's payment is
evidenced. The rail is a separate, mutable, order-level label that no proof has ever referenced.

Method-scoping would not tighten a loose control; it would **invent a new claim the data cannot
support**, attach it to the weakest artifact in the chain, and bind it to a field an operator can
edit — while the money it evidences remains entirely method-agnostic.

**The ambiguity is real, it is low severity, and the control that addresses it already exists and is
human: Finance can reject, and anyone can supersede.**

**READ ONLY. NOTHING IMPLEMENTED. NO MIGRATION. NO SCHEMA CHANGE. NO DATA MODIFIED. NO PROOF
TOUCHED. NO COMMIT.**

**STOP — awaiting Owner ratification of OPTION B.**
