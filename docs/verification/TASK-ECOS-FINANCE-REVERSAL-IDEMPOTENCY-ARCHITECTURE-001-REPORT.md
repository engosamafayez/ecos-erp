# TASK-ECOS-FINANCE-REVERSAL-IDEMPOTENCY-ARCHITECTURE-001 — Architecture Decision Report

**ECOS Finance — Allocation Reversal / Contra-Allocation + Finance Write-Endpoint Idempotency**

Mode: **ARCHITECTURE DECISION ONLY**. No production code, migration, schema, configuration, or data was modified. No tests were run. No commits/pushes/deploys. Every claim below is either a direct file:line read or an explicit, labeled decision.

Date: 2026-09-02 · Workspace: `D:\ECOS-Work\ecos-finance` · Branch: `task/finance-gap-closure` · Verified HEAD at start: `d561516b`

Batch: Finance Gap Closure Batch 01 — Task 1 of 5.

---

## A. Executive decision summary

Both architecture questions are **decided**, with implementable, evidence-grounded contracts. Neither decision requires new infrastructure classes beyond one genuinely new (but pattern-derived) service; both extend canonical authorities already in the tree rather than duplicating them.

1. **Allocation reversal / contra-allocation.** An allocation is correctable but never mutated or deleted. Correction is a new, append-only **contra-allocation row** — same shape as today's `PaymentAllocation`/`ReceiptAllocation`, negative amount, referencing the same payment/bill (or receipt/invoice) pair plus a new `reverses_allocation_id` back-link and a mandatory `reversal_reason`. This is not invented from nothing: it is the literal generalization of `ReceiptAllocation`'s own docblock (*"an error is undone by a reversing allocation, not an edit"*, `ReceiptAllocation.php:17`) and mirrors the exact reversal shape `JournalEngine::reverse()` already uses one layer up. `AllocationEngine` gains two new methods; no new service class. `JournalEngine::reverse()` gains one new precondition so a payment/receipt-sourced journal cannot be reversed while active allocations remain — closing a **live, currently-unguarded divergence path** this investigation found (§B.7).

2. **Finance write-endpoint idempotency.** "Idempotent" is given one precise meaning for ECOS Finance, and the three patterns the Recovery Gate found are kept, un-weakened, each scoped to what it actually protects (§E). A fourth, new, thin pattern — a client-supplied `Idempotency-Key` plus a small receipt table modeled directly on the proven `PostingCoordinator`/`finance_posted_event_receipts` mechanism — is added at the **command layer** (payment/receipt/reversal creation), which is the layer none of the three existing patterns actually covers.

**Both decisions are additive to the existing schema (nullable columns, one new table) and additive to existing classes (new methods on `AllocationEngine`, one new guard on `JournalEngine::reverse()`).** No existing table loses a column, no existing method signature changes, no existing permission is removed.

---

## B. Current-state evidence (Existing Capability Gate)

Every item the task asked to be inspected, classified against **PRESERVE / REUSE / EXTEND / ARCHITECTURE GAP / LEGACY-DO-NOT-USE**, with file:line evidence.

| # | Mechanism | Classification | Evidence |
|---|---|---|---|
| 1 | `AllocationEngine` (`Modules/Finance/Allocation/Domain/Services/AllocationEngine.php`) | **REUSE** (base) + **EXTEND** (add reversal) | Four methods only: `allocateReceipt`/`autoAllocateReceipt`/`allocatePayment`/`autoAllocatePayment` (:34,93,133,189). Class docblock: *"Allocation is a pure subledger relationship: it never posts a journal... Append-only matching, derived outstanding"* (:20-27). Zero matches for `reverse`/`contra`/`unallocate` anywhere in the directory. |
| 2 | `PaymentAllocation` model | **REUSE** (base) + **EXTEND** (add columns) | `finance_payment_allocations`; `booted()` hard-blocks mutation: `static::updating(fn(): bool => false); static::deleting(fn(): bool => false)` (:41-42). No status field, no reversal field today. |
| 3 | `ReceiptAllocation` model | **REUSE** (base) + **EXTEND** (add columns) | Same immutability guard (:45-46). Docblock states the intended correction model explicitly: *"Allocations are immutable; an error is undone by a reversing allocation, not an edit"* (:17) — **a documented intent with no implementation**. |
| 4 | `SupplierPayment` / `PaymentStatus` | **PRESERVE** (Draft→Approved→Posted flow); `Void` transition = **ARCHITECTURE GAP** | `PaymentStatus.php`: `Draft`, `Approved`, `Posted`, `Void` (:17-20), docblock *"Void cancels a pre-posting payment"* (:12-13). Repo-wide search for any **assignment** to `Void` (`'status' => ... void`, `Void->value`) across all of `Modules/Finance` returns **zero writers** — the only occurrences are the enum's own `self::Void` and the two *comparison* guards at `AccountsPayableService.php:227` and `AccountsPayableService.php:291`. Void is fully modeled, fully guarded against, and currently unreachable. |
| 5 | `CustomerReceipt` / `DocumentStatus` | **PRESERVE** (Draft→Posted flow); `Void` transition = **ARCHITECTURE GAP** | `DocumentStatus.php`: `Draft`, `Posted`, `Void` (:16-18), docblock *"void is a pre-posting cancellation... corrections are notes or reversals"* (:11-12) — again, "reversals" is named as the intended post-posting path but not built. Same dead-Void finding as #4, confirmed at `AccountsReceivableService.php:203,314`. |
| 6 | `AccountsPayableService` | **PRESERVE + REUSE**, unchanged | `createPayment` (:161), `approvePayment` (:197, maker≠checker), `postPayment` (:222). No change proposed to this class; reversal lives in `AllocationEngine`, not here. |
| 7 | AR receipt service (`AccountsReceivableService`) | **PRESERVE + REUSE**, unchanged | `createReceipt` (:166, **no approval step** — by design, per `PaymentStatus`'s own docblock contrasting payment vs. receipt), `postReceipt` (:195), and **`writeOff`** (:265-305) — a directly relevant existing precedent, see §B.8. |
| 8 | `writeOff()` — existing correction precedent | **PRESERVE**, cited as design precedent, not modified | Corrects an invoice's outstanding balance **without ever touching the original invoice or its allocations**: creates a *new* receipt funded by a bad-debt expense account, posts it normally, then allocates it via the ordinary `AllocationEngine::allocateReceipt()` (:300-301). This is ECOS Finance's own established philosophy — *never mutate a posted document or its allocations; issue a new, fully-audited transaction that nets against it* — already shipped and tested. It solves a different case (irrecoverable balance / bad debt) from contra-allocation (undo a specific mis-match and free the source for correct reallocation), but the underlying philosophy is identical and directly informs Decision C. |
| 9 | `PaySupplierInvoiceService` / `SupplierInvoicePaymentController` | **PRESERVE + EXTEND** (idempotency-key wiring only, Task 3/4) | Full file read; orchestrates `initiatePayment`→`AccountsPayableService::createPayment` and `settleInvoice`→`AllocationEngine::allocatePayment`. No idempotency-key handling of any kind today. |
| 10 | `JournalEngine::reverse()` | **PRESERVE** (mechanics) + **EXTEND** (new precondition) | `JournalEngine.php:137-195`. Guards: refuses a Draft entry (`cannotReverseDraft`, :140), refuses an already-reversed entry (`alreadyReversed`, :146-148, checked via **both** `status === Reversed` **and** `reversed_by_journal_id !== null`). Creates a new mirrored `JournalEntry`, links `reverses_journal_id`/`reversed_by_journal_id` bidirectionally, requires a mandatory `reason` string. **Confirmed gap: `reverse()` takes only a `JournalEntry`; it has no awareness of `source_module`/`source_event_id` semantics and performs no check against `PaymentAllocation`/`ReceiptAllocation` rows for the payment/receipt that produced the journal.** |
| 11 | `JournalController::reverse()` | **PRESERVE + EXTEND** (same new guard surfaces here as an error, no route change) | `JournalController.php:82-90`. Fetches **any** `JournalEntry` by `company_id`+`uuid` — **no filter on `source` ('manual' vs 'posting') or `source_module`** — and calls `engine->reverse()` unconditionally. Gated only by the generic checker permission `finance.journal.post` (class docblock, :19-23) — **not** by any AP/AR-specific authority. **This confirms the divergence risk in #10 is live and reachable today**, not hypothetical: a payment/receipt journal can be reversed through this endpoint with zero regard for its allocations. |
| 12 | `PostingCoordinator` | **PRESERVE**, reused **as a pattern**, not modified | `PostingCoordinator.php`, full read. Exactly-once posting keyed on `(source_module, source_event_id)`: check existing receipt → return its journal (:46-49); pre-flight validate; engine posts; record receipt, and on a race (`UniqueConstraintViolationException`) fall back to the winner (:64-78). This is a complete, correct, genuinely idempotent (no-op-on-replay) mechanism — the template Decision D reuses one layer up. |
| 13 | `finance_posted_event_receipts` | **PRESERVE**, unchanged; the model for the new command-receipt table | `unique(['source_module','source_event_id'])` (`2026_08_10_100010_...:39`, confirmed in Recovery Gate). |
| 14 | Payment/receipt DB uniqueness (`company_id`+`number`) | **PRESERVE**, unchanged; explicitly **not** the idempotency mechanism | `finance_supplier_payments` (`...100007:50`) and `finance_customer_receipts` (`...100002:49`) both carry `unique(['company_id','number'])`. Caller-supplied, not retry-safe (same-key retry throws a constraint violation; different-key retry is invisible to it). See Decision D, B4/B6. |
| 15 | Current payment/receipt status guards | **PRESERVE**, unchanged | `documentAlreadyPosted` / `documentVoided` (`AccountsPayableService.php:224-231`, `AccountsReceivableService.php:200-205,309-316`) — reject-on-repost, not a no-op. Stays exactly as-is; it protects the *posting* step, which Decision D does not touch. |
| 16 | Reversal/cancel/void/refund/correction/retry/dedup — exhaustive sweep | See #4, #5, #8, #10, #12 | No refund mechanism, no generic "correction" or retry/dedup mechanism exists anywhere else in `Modules/Finance` beyond what is listed above. |
| 17 | `ADR-022-allocation-orchestration-engine.md` | **LEGACY / DO NOT USE for this purpose** | Read in full header. Scope: *"Operations / Loading module"* — vehicle-loading/dispatch allocation (`PreparedPool`→`AllocationRecord`), a completely different bounded context from Finance's AR/AP cash-application `AllocationEngine`. Confirmed unrelated; a naming collision only, already flagged generically by the 2026-08-28 Finance audit (§14 of that report) — not specific to this ADR. Do not let Task 2 implementation search accidentally land here. |
| 18 | Finance permission model (F2 seed) | **PRESERVE**, extended by one new row (recommendation, §I) | `2026_08_11_100018_seed_finance_f2_permissions_table.php:20-48`. Notably: `finance.allocation.manage` is a **single, unsplit** permission ("Allocate receipts and payments to documents") — no maker/checker split, unlike `finance.ap.payment.create`/`finance.ap.payment.approve`. Precedent for splitting a sensitive action out of a `.manage` permission exists: `finance.bank.manage` vs. `finance.bank.reconcile` (:44-47). |

---

## C. Allocation reversal decision (answers A1–A10)

**A1 — Is an allocation immutable forever, or must Finance support correction?**
Immutable **as a row** (this invariant is preserved — see §B.2/§B.3, the model-level `updating`/`deleting` guards are **not** relaxed). But Finance **must** support correction as a *business capability*: A6's scenarios (bounced supplier payment, reversed customer receipt, payment posted to the wrong invoice, receipt allocated to the wrong invoice, duplicate allocation) are real and currently have no path at all. Decision: **the row is immutable; the relationship it represents is correctable via a new row.**

**A2 — Canonical model.**
**Append-only contra-allocation.** Rejected alternatives, with reasons:
- *Destructive deletion/unallocation* — violates the model-level immutability guard already enforced (`updating`/`deleting` return `false`) and destroys audit history (violates invariant #3/#4).
- *Status mutation* (e.g., an `is_reversed` flag on the original row) — still a mutation of an append-only row; also insufficient for **partial** reversal (A6's "payment posted to the wrong invoice" may need only part of an allocation corrected).
- *Replacement/superseding allocation* (delete-and-recreate) — same mutation problem, plus loses the "what did the system believe between T1 and T2" audit trail.
- *Append-only contra-allocation* — the only option consistent with the existing immutability guard, and it is not new: it is the exact generalization of `ReceiptAllocation.php:17`'s own stated intent and structurally identical to how `JournalEngine::reverse()` already corrects a posted journal (new mirrored row, old row linked and marked, nothing edited or deleted).

**Shape (conceptual — no migration in this task, see §G):** a contra-allocation is a new `PaymentAllocation`/`ReceiptAllocation` row carrying:
- The **same** `payment_id`+`supplier_bill_id` (or `receipt_id`+`customer_invoice_id`) pair as the original — required so `outstanding()`/`unallocatedAmount()` (both already defined as "the SUM of these rows, computed on read" per both models' docblocks) net correctly with **zero change to any read-side derivation logic**.
- A **negative** `amount`.
- A new `reverses_allocation_id` (nullable, self-referential FK) pointing at the original row.
- A new `reversal_reason` (string, mandatory on this write path only).
- `allocated_by`/`allocated_at` reused as-is for the reversal's own actor/timestamp — no new columns needed for "who/when," mirroring how `JournalEngine::reverse()` reuses `created_by`/`posted_by`/`posted_at` on its new reversal entry rather than adding parallel columns.
- The **original** row gains one new nullable `reversed_by_allocation_id` (self-referential FK, set once when the contra is created) — symmetric to `JournalEntry.reversed_by_journal_id` — so a reader sees "this row was reversed" without scanning for children.

Partial reversal is supported: the reversal amount is capped at *(original allocation amount − amount already reversed against that specific original row)*, derived the same SUM-of-rows way — not a new derivation mechanism.

**A3 — Auditability.**
100% of history remains visible: the original allocation row is never edited or deleted (unchanged guard); the contra row is a new, permanent, queryable record with its own reason/actor/timestamp; the bidirectional FK (`reverses_allocation_id` / `reversed_by_allocation_id`) makes the relationship discoverable in one query in either direction, exactly like journal reversal already is.

**A4 — Relationship: Financial document → payment/receipt → allocation → GL journal.**
Unchanged and reaffirmed, not redesigned:
`SupplierBill`/`CustomerInvoice` (financial document, posts its own journal) ⟷ `SupplierPayment`/`CustomerReceipt` (posts its own separate journal) ⟷ `PaymentAllocation`/`ReceiptAllocation` (subledger-only link between the two documents' journals, posts nothing) ⟷ each document's own `journal_entry_id` (GL). Allocation reversal adds a *fifth* node value (a contra-allocation row) at the *third* link in this chain only. It never touches a `journal_entry_id` and never calls `JournalEngine`/`PostingCoordinator` — invariant #2 ("Allocation... must not independently invent GL postings") is preserved by construction: the new methods live in `AllocationEngine`, which has no dependency on `JournalEngine` today, and none is added.

**A5 — GL reversal ↔ allocation reversal relationship.**
Ruled: **GL journal reversal of a payment/receipt-sourced journal is blocked while active (non-reversed) allocations remain against that payment/receipt.** Rejected alternatives:
- *May legally diverge* — rejected. §B.11 proves this is the **current, unguarded, live behavior** and it directly violates required invariant #8 ("explicit, deterministic relationship") and #9 (outstanding must stay mathematically correct). Not acceptable as an end state.
- *GL reversal automatically cascades to allocation reversal* — rejected. Too implicit: a GL-only actor (holding just `finance.journal.post`) reversing a journal should not silently trigger subledger-side financial consequences (freeing a bill's/invoice's outstanding) without the AR/AP domain — and its own authority gate — being explicitly invoked. It also makes the *amount* of the implied allocation reversal ambiguous when a payment was split across several bills.
- **Blocked while allocations remain active — chosen.** Deterministic, safe, and matches the codebase's own existing style of guard-before-mutation (`assertPostable`, `assertOpenPeriod`, `cannotReverseDraft`, `alreadyReversed` are all the same shape: refuse, with a precise exception, rather than silently do something implicit).

Mechanically (future implementation, Task 4): `JournalEngine::reverse()` (or a thin guard invoked immediately before it, still inside the **same** `DB::transaction`) checks whether the entry's `source_module` is `finance.ap`/`finance.ar` and its `source_event_id` matches the `payment:`/`receipt:` convention already used by `AccountsPayableService::postPayment` (:248) and `AccountsReceivableService::postReceipt` (:221); if so, it resolves the payment/receipt and refuses (a new, precise `FinanceException`) if any non-reversed `PaymentAllocation`/`ReceiptAllocation` row still references it. **`JournalEngine` remains the sole GL writer and the sole reversal path — no second reversal mechanism is created; this is one new precondition on the existing one.** Manual journals (`source = 'manual'`) are entirely unaffected — this preserves current behavior for every non-subledger journal (§M).

**A6 — Bounced/failed payment and mis-allocation scenarios.**
All five named scenarios resolve under the A2+A5 model without any scenario-specific code:
- *Supplier payment later rejected by bank* / *customer receipt later reversed*: reverse the allocation(s) first (freeing the bill's/invoice's outstanding and the payment's/receipt's unallocated balance back to their pre-allocation state), then reverse the GL journal (now unblocked). Two explicit steps, two explicit authorizations — not one implicit cascade.
- *Payment posted to the wrong invoice* / *receipt allocated to the wrong customer invoice*: a **partial or full contra-allocation** against the wrong bill/invoice (freeing the payment's/receipt's unallocated balance), followed by a normal `allocatePayment`/`allocateReceipt` call against the correct one. No GL reversal needed at all in this case — the underlying cash movement was correct, only the subledger match was wrong.
- *Duplicate allocation corrected operationally*: a contra-allocation against the duplicate row. No GL involvement.

**A7 — Outstanding-balance semantics after reversal.**
Guaranteed by construction, not by a new invariant-checking mechanism: because `outstanding()`/`unallocatedAmount()` are (per both models' own docblocks) *always* `SUM(allocations.amount)` for the relevant document/payment, and a contra-allocation is a same-shape row with a negative amount against the same FK pair, the sum is correct the instant the row commits — with **zero change** to any existing read path. `SupplierPayment.status`/`CustomerReceipt.status`/`SupplierBill.status`/`CustomerInvoice.status` are untouched by an allocation reversal (they only change via `postPayment`/`postReceipt`/`voidPayment`(future)/GL reversal) — so a payment that is fully un-allocated by contra-rows still correctly reads `status = Posted` (it is still a valid, posted payment; it is simply not currently matched to anything), which is the correct semantic (compare: this is exactly the state a *freshly posted, not-yet-allocated* payment is already in today).

**A8 — Concurrency invariants.**
The existing lock-before-rederive protection (`de10aca3`, `d561516b`) is **not weakened — it is reused verbatim, sign flipped.** `reversePaymentAllocation`/`reverseReceiptAllocation` open the identical `DB::transaction` → lock **payment/receipt before bill/invoice** `FOR UPDATE` (same order as `allocatePayment`/`allocateReceipt`, so no new deadlock ordering is introduced) → re-derive the original allocation's *already-reversed* total under the lock → assert the requested reversal amount does not exceed what remains → create the contra row. This is the same two-aggregate-lock shape already proven correct for allocation; reversal is not a structurally different operation from allocation's point of view, just a signed one.

**A9 — Permissions and authority.**
No new approval engine (per the task's explicit instruction). Two decisions:
- *Initiating/executing a reversal*: **recommend** a new, narrowly-scoped permission `finance.allocation.reverse`, split out from the existing unsplit `finance.allocation.manage` — mirroring the precedent already in the seed data (`finance.bank.manage` vs. `finance.bank.reconcile`, §B.18). Rationale: allocation today has no maker/checker split at all; giving reversal — which has the same financial blast radius as un-settling a bill/invoice — the *same* bare permission as ordinary allocation seems too permissive by default, and the bank-reconcile precedent shows this codebase already splits out sensitive state-changing actions this way. **This is a recommendation, not a blocking requirement** — reusing `finance.allocation.manage` unchanged is a one-line alternative if the CTO prefers not to add a permission row.
- *The subsequent GL reversal*, once unblocked, continues to require the existing `finance.journal.post` — unchanged.

**A10 — Reversal metadata requirements.**
| Field | Required? | Source |
|---|---|---|
| Reason | **Yes, mandatory** | New `reversal_reason` column, enforced at the service boundary (mirrors `JournalEngine::reverse($entry, string $reason, ...)`, which already requires this) |
| Actor | Yes | Reuses `allocated_by` on the new contra row |
| Timestamp | Yes | Reuses `allocated_at` on the new contra row |
| Source allocation reference | Yes | New `reverses_allocation_id` |
| Source payment/receipt reference | Yes (already implicit) | Same `payment_id`/`receipt_id` FK the contra row carries |
| Supporting attachment/reference | **Optional — out of scope for Task 2** | No generic attachment mechanism exists for AP/AR documents in `Modules/Finance` today (the `payment_proofs` table found in the Recovery Gate belongs to Orders, not Finance); `reversal_reason` (free text) is the mandatory minimum and an attachment FK can be layered on later without changing this model. |

---

## D. GL reversal ↔ allocation reversal relationship

Consolidated from §C.A4/A5: **allocation reversal is a precondition of GL reversal for subledger-sourced journals, never the other way around, and never automatic.** `JournalEngine` remains the sole writer and sole reversal authority for the GL (invariant #1); `AllocationEngine` remains the sole writer for subledger matching (invariant #2). The new guard is a *read check* `JournalEngine::reverse()` performs against `Allocation`-owned rows before proceeding — it does not give `JournalEngine` write access to allocations, and it does not give `AllocationEngine` write access to the GL. The two engines gain exactly one new *read* dependency (Ledger → Allocation), zero new *write* dependencies.

---

## E. Payment/receipt idempotency decision (answers B1–B10)

**B1 — What "idempotent" means in ECOS Finance, precisely.**
| Term | Definition here | Who/what handles it |
|---|---|---|
| Transport retry | The client's HTTP layer resends an identical request because it never saw a response (timeout, connection drop) — the server may or may not have completed the original. | New: Idempotency-Key (this decision) |
| Duplicate button submission | A human double-clicks/double-taps, issuing two *separate* HTTP requests for what the user intends as one action. | New: Idempotency-Key, **if** the client reuses one key across the double-fire (a UX/client responsibility, noted in §L) |
| Repeated API request | A caller (script, integration) sends the same logical command twice, deliberately or by bug. | New: Idempotency-Key |
| Repeated domain command | `postPayment`/`postInvoice` invoked twice on the *same already-created* row. | **Existing, unchanged** — Pattern 2 (status guard, reject-on-repost) |
| Repeated event delivery | A queue/bus redelivers `pos.sale.finalized` or similar. | **Existing, unchanged** — Pattern 1 (`PostingCoordinator`/`finance_posted_event_receipts`) |
| Legitimate second business transaction | A second, genuinely distinct supplier payment against the same invoice (e.g., two partial payments). | **Must always remain possible** — see B5 |

**B2 — Dedicated idempotency key: yes, for command-level creation endpoints.**
Required for: supplier payment creation (`AccountsPayableService::createPayment` / `PaySupplierInvoiceService::initiatePayment`), customer receipt creation (`AccountsReceivableService::createReceipt`), and — by the same reasoning, since it is also a state-creating financial command — the new allocation-reversal commands from Decision C.
- **Who generates it:** the **client** (caller), not the server — a server-generated key cannot detect a retry, since the retry would get a *new* key. Standard `Idempotency-Key` HTTP header, an opaque string (UUID recommended), minted once per logical user action and resent verbatim on every retry of that same action.
- **Scope/uniqueness boundary:** `(company_id, command_type, idempotency_key)` — company-scoped (tenant isolation, matching every other Finance uniqueness constraint in the tree) and command-type-scoped (so a key collision between, say, a payment-create and a receipt-create is structurally impossible, not just unlikely).
- **Storage authority:** a new table, deliberately parallel to `finance_posted_event_receipts` — see §G.
- **Retention:** propose 90 days as the default (shorter than F3's dead-letter 365-day retention, since a command receipt is a lighter-weight, purely-defensive record, not a compliance/dead-letter artifact) — **a tunable parameter, not a blocking decision.**
- **Replay behavior:** see B3.

**B3 — Replay behavior.**
Command-class-dependent, and this is the key distinction the Recovery Gate's three patterns blurred:
- **Same key + same request fingerprint** (an exact replay): return the **original successful result**, silently, with the original resource — no error, no new row created. This is the correct semantic for B1's "transport retry / duplicate submit / repeated API request" cases.
- **Same key + different request fingerprint** (key reuse with a different payload): **explicit reject** (409 Conflict) — never guess which payload was "real."
- **No key supplied:** falls back to today's behavior unchanged (Pattern 3's uniqueness constraint is still the only guard) — see §M for why this must be the default during rollout.
- This is deliberately **different** from Pattern 2 (`postPayment`/`postInvoice`/`postReceipt` re-invocation), which stays a hard reject (`documentAlreadyPosted`) exactly as it is today — posting is a distinct, deliberate, one-time checker action, not something a client is expected to retry the same way it retries a create.

**B4 — Caller-visible document numbers are explicitly NOT the idempotency architecture.**
`finance_supplier_payments`/`finance_customer_receipts`'s `unique(company_id, number)` is a **business-key** uniqueness guarantee (two payments may not claim the same human-readable reference) and stays exactly as-is. It is orthogonal to, and does not substitute for, request-retry idempotency: a same-`number` retry today gets a raw constraint-violation error (ungraceful, but *not unsafe* — no duplicate is created); a different-`number` retry (e.g., a client that mints a fresh reference each attempt) is invisible to it and **does** create a duplicate payment today. The new Idempotency-Key mechanism is what closes that second case; the uniqueness constraint is not, and was never designed to be, that mechanism.

**B5 — Business identity vs. request idempotency boundary.**
A legitimate second payment against the same invoice must remain possible (required invariant #6) — guaranteed structurally, because the idempotency key is **client-minted per logical action**, never derived from business fields (invoice id, amount, supplier id, etc.). Two genuinely separate user actions naturally receive two different keys (the client mints a new one each time the user initiates a new action); only an actual retry of the *same* action reuses the same key, by construction of how a client is expected to use the header. No server-side "is this a duplicate business transaction" heuristic is proposed — that would risk exactly the false-positive this invariant forbids.

**B6 — Interaction with current unique constraints.**
Unchanged, run alongside, never replaced. See B4.

**B7 — Event-driven idempotency stays separate.**
`PostingCoordinator`/`finance_posted_event_receipts` is **not modified, not replaced, not touched**. It already correctly solves exactly-once posting for event-sourced journals (`source_module`+`source_event_id`). The new command-level mechanism operates one layer *above* it (at "did this HTTP command already run," before a `PostingRequest` is even built) and is explicitly modeled on it (same check-then-record-then-fallback-on-race shape) rather than replacing it — no evidence justifies touching a proven mechanism (per the task's own B7 instruction).

**B8 — Concurrency guarantees.**
Idempotency and concurrency remain two separate guarantees, per required invariant #7:
- **Idempotency** (new) answers *"has this exact command already executed?"* — a lookup-then-insert-with-unique-constraint check, race-safe the same way `PostingCoordinator` already is (catch the constraint violation, return the winner's result).
- **Concurrency** (existing, `de10aca3`/`d561516b`, and its A8 extension) answers *"is the shared numeric balance (unallocated/outstanding) still correct when two different commands touch it at the same time?"* — `lockForUpdate`, unchanged.
Neither replaces the other; a duplicate-command retry and a concurrent-but-distinct second payment are different situations and are caught by different mechanisms.

**B9 — Response semantics for duplicate requests.**
| Situation | HTTP status | Body |
|---|---|---|
| First request, any key (or no key) | 201 Created (as today) | The created resource |
| Exact replay (same key, same fingerprint) | 200 OK | The **original** resource, plus an `Idempotent-Replay: true` response header for observability |
| Conflicting reuse (same key, different fingerprint) | 409 Conflict | Explicit error naming the key |
| Retry of an already-posted document (`postPayment` etc., unrelated to this mechanism) | Unchanged (existing `documentAlreadyPosted` exception path) | Unchanged |

**B10 — Observability/audit requirements.**
The command-receipt table (§G) *is* the audit trail, in the same way `finance_posted_event_receipts` already serves that role for events and `PostingAuditEntry`/`finance_posting_audit` (found in §B, Recovery Gate) already serves it for postings — no new logging framework is proposed. Each row's existence proves the original request; a query for `idempotency_key` with `created_at` far in the past relative to the current request proves a replay; a fingerprint mismatch on lookup is the conflict case and should itself be recorded (not just rejected silently) so a pattern of conflicting-key reuse by one integration is discoverable later.

---

## F. Command/API semantics

- New header contract: `Idempotency-Key: <opaque-string>` on `POST` endpoints that create a `SupplierPayment`, `CustomerReceipt`, or (once built) an allocation reversal.
- Behavior fully specified in §E.B3/B9. No change to any `GET`, to `approve`/`post`/`settle` endpoints, or to `JournalController`'s existing routes beyond the new refusal case from Decision C surfacing as a new, specific `FinanceException` subtype.

---

## G. Required data-model implications (conceptual only — no migrations in this task)

**New nullable columns** (additive, non-breaking):
- `finance_payment_allocations`: `reverses_allocation_id` (nullable, self-FK), `reversed_by_allocation_id` (nullable, self-FK), `reversal_reason` (nullable string).
- `finance_receipt_allocations`: same three columns, mirrored.

**New table** (additive, modeled on `finance_posted_event_receipts`):
- `finance_command_receipts` — conceptually: `company_id`, `command_type` (e.g. `ap.payment.create`, `ar.receipt.create`, `allocation.reverse`), `idempotency_key`, `request_fingerprint` (hash of the normalized payload, for B3/B9's conflict case), `result_type`+`result_id` (polymorphic pointer to the created row), `created_by`, `created_at`. Unique on `(company_id, command_type, idempotency_key)`.

No existing column is removed, renamed, or retyped anywhere in this decision.

---

## H. Required service/action boundaries

| Component | Action | Why here, not elsewhere |
|---|---|---|
| `AllocationEngine` | **Extend**: add `reversePaymentAllocation()`, `reverseReceiptAllocation()` | It is already the canonical, sole authority for payment/receipt ↔ document matching; reversing a match is the same domain, same two-aggregate locking shape, same invariants. Creating a separate "AllocationReversalService" would duplicate an existing canonical authority, which the task explicitly forbids. |
| `JournalEngine::reverse()` | **Extend**: one new precondition check, same transaction | Keeps `JournalEngine` the sole GL writer/reverser (invariant #1) — a new class here would create a second reversal path. |
| New: a thin command-idempotency guard (e.g. `Modules\Finance\Shared\Domain\Services\CommandIdempotencyGuard`) | **New class**, but pattern-derived, not invented | No existing canonical authority covers request-level (as opposed to event-level) idempotency — `PostingCoordinator` is scoped to events, by design (§B.12), and extending it to also mean "HTTP command dedup" would conflate two different keys and violate B7's "do not replace a proven mechanism." One new, small class, deliberately shaped like `PostingCoordinator` (check → act → record → race-fallback), used as a single choke point the same way `FundingAccountPolicy` already is (`AccountsPayableService.php:177`). |
| `SupplierInvoicePaymentController`, `SupplierPaymentController`, `CustomerReceiptController` | **Extend**: read the new header, call the guard | No new controller class needed for payment/receipt creation. |
| New: a thin `AllocationReversalController` (or a new action on an existing Finance controller) | **New, minimal**, mirrors `SupplierInvoicePaymentController`'s already-precedented shape (thin orchestration over canonical services) | Exposes Decision C's two new `AllocationEngine` methods; no business logic of its own. |

---

## I. Permission / maker-checker implications

- **Recommended** (not mandatory): new permission `finance.allocation.reverse`, split from `finance.allocation.manage`, following the `finance.bank.manage`/`finance.bank.reconcile` precedent already in the seed data (§B.18). One new row in the same migration-seed style as `2026_08_11_100018_...` — not a new approval engine.
- **Alternative, zero-new-permission path**: reuse `finance.allocation.manage` unchanged for both allocate and reverse. Either is implementable from this decision; the CTO's preference decides which Task 2 ships.
- No maker/checker split is proposed for allocation reversal itself, consistent with the task's instruction not to build a new approval engine — the existing single-actor `finance.allocation.manage` authority model is preserved, just possibly (see above) given a sibling permission rather than a checker step.
- The GL-reversal side needs no new permission: `finance.journal.post` continues to gate `JournalEngine::reverse()`/`JournalController::reverse()` exactly as today; Decision C only adds a new *reason it might refuse*, not a new *who may call it*.
- Idempotency (Decision D) needs **no new permission** — it is request plumbing, orthogonal to authorization.

---

## J. Audit/history requirements

Fully enumerated in §C.A3/A10 (allocation reversal) and §E.B10 (idempotency). Both mechanisms are additive audit trails by construction — nothing existing is made less auditable, and both new records (contra-allocation rows, command receipts) are themselves permanent, queryable evidence, consistent with how `finance_posting_audit`/`PostingAuditEntry` and `finance_posted_event_receipts` already work in this codebase.

---

## K. Concurrency requirements

Fully enumerated in §C.A8 (allocation reversal — reuses the exact `lockForUpdate` pattern and lock order from `de10aca3`/`d561516b`, sign flipped, plus a new "not more than remains" guard under the same lock) and §E.B8 (idempotency — a distinct, complementary guarantee, race-safe via the unique constraint + fallback, same shape as `PostingCoordinator`). Required invariant #7 (idempotency and concurrency are separate guarantees) is satisfied by keeping them as two mechanisms, never merged into one.

---

## L. Failure and retry semantics

- Command-level retry (create payment/receipt/reversal): governed by Decision D — safe no-op on exact replay, explicit 409 on conflicting reuse.
- Domain-command retry (post/approve): governed by existing, unchanged status guards (Pattern 2) — hard reject.
- Event-delivery retry: governed by existing, unchanged `PostingCoordinator` (Pattern 1) — safe no-op.
- Allocation-reversal-amount failure (requesting more than remains, or reversing an already-fully-reversed allocation): a new, specific `FinanceException`, raised inside the same locked transaction, no partial state — same style as every existing `AllocationEngine` guard (`allocationExceedsSource`, `allocationExceedsDocument`).
- GL-reversal-blocked-by-active-allocations: a new, specific `FinanceException`, raised before any GL mutation begins.
- **Client responsibility, noted but out of engineering scope**: a "duplicate button submission" is only caught by Decision D if the client's UI reuses one key across the double-fire (e.g., generates the key on button-press-intent, not per network call). This is a frontend implementation detail for Task 4, not an architecture gap — flagged here so it isn't lost.

---

## M. Backward-compatibility impact

- Allocation tables and the new command-receipt table: purely additive (nullable columns / new table). Zero impact on any existing read or write path that does not opt in.
- `AllocationEngine`, `AccountsPayableService`, `AccountsReceivableService`, `PostingCoordinator`, `PaySupplierInvoiceService`: **no existing method signature changes.**
- **One deliberate, flagged behavior change**: `JournalEngine::reverse()` will, after Task 4, start **refusing** some reversal attempts that currently silently succeed (§B.11's confirmed live gap). This is the correct, evidence-justified closing of an unguarded divergence path, not an accidental regression — but it is a real behavior change against today's code, and is called out explicitly here and again in §P so it is not mistaken for a side effect.
- Rollout of the `Idempotency-Key` header should be **optional-with-fallback** initially (no key supplied → today's behavior, Pattern 3 only) rather than mandatory-and-breaking, so existing callers (if any exist outside this codebase) are not broken on deploy. Whether/when to later make the header mandatory is a Task 3/4 rollout decision, not an architecture blocker.

---

## N. Explicit DO-NOT-REIMPLEMENT list

1. Do **not** build a new GL writer or a second reversal path — `JournalEngine`/`PostingCoordinator` remain the sole authorities.
2. Do **not** build a new "Allocation" service — extend the existing `AllocationEngine`.
3. Do **not** touch, replace, or generalize `PostingCoordinator`'s event-level idempotency (`finance_posted_event_receipts`) into also meaning command-level idempotency — reuse the *pattern*, keep the *mechanism* separate (B7).
4. Do **not** build a new approval/maker-checker engine — reuse permission-based gating exactly as F1–F4 already do; at most, add one permission row.
5. Do **not** repurpose `ADR-022`'s "Allocation Orchestration Engine" (Operations/Loading module, §B.17) — confirmed unrelated bounded context.
6. Do **not** treat `finance_supplier_payments`/`finance_customer_receipts`'s `unique(company_id, number)` as the idempotency mechanism (B4/B6).
7. Do **not** wire up `PaymentStatus::Void`/`DocumentStatus::Void` as part of the contra-allocation model. Void is reachable only from `Draft`/`Approved` (pre-posting cancellation); contra-allocation is strictly a post-posting correction path for an already-`Posted`, already-allocated document. These are two different lifecycle stages and must not be conflated by an implementer reaching for the nearest-looking enum case. (Activating `Void` at all is out of scope for this decision — it is a separate, smaller gap noted for the CTO in §P, not part of Tasks 2–5 as scoped here.)
8. Do **not** re-derive `outstanding()`/`unallocatedAmount()` differently for the reversal case — the existing SUM-of-rows derivation already produces the correct answer for a negative-amount contra row; adding a parallel derivation would create two sources of truth.

---

## O. Proposed implementation sequence for Finance Tasks 2–5

The task prompt's flat 2→3→4→5 hypothesis is refined below based on actual dependency evidence: Tasks 2 and 3 are independent of each other and can run in parallel; Task 4 depends on both; Task 5 depends on all three.

```
Task 2 ──┐
         ├──▶ Task 4 ──▶ Task 5
Task 3 ──┘
```

- **Task 2 — Allocation reversal domain implementation.** Migration adding the three nullable columns to both allocation tables (§G); `AllocationEngine::reversePaymentAllocation()`/`reverseReceiptAllocation()` (§C, §H); the `finance.allocation.reverse` permission if the CTO accepts the §I recommendation; feature tests in the style of the existing `SupplierPaymentTransactionIntegrityTest` proving A7/A8 (outstanding stays correct under concurrent reversal, over-reversal is refused, partial reversal works).
- **Task 3 — Finance write idempotency foundation.** Migration for `finance_command_receipts` (§G); the new `CommandIdempotencyGuard`-shaped service (§H); the `Idempotency-Key` header contract (§F) wired into **existing** payment/receipt creation endpoints only (no dependency on Task 2). Tests proving B3/B9 (exact replay returns the original result, conflicting reuse is rejected, no-key requests behave exactly as today).
- **Task 4 — GL↔subledger reversal linkage + endpoint integration.** Depends on both: extends `JournalEngine::reverse()` with the new precondition (§D) — needs Task 2's allocation-reversal rows to check against; wires the new `AllocationReversalController` (or equivalent) endpoint with idempotency-key support — needs Task 3's guard. Also the point at which `SupplierInvoicePaymentController`/`SupplierPaymentController`/`CustomerReceiptController` actually start requiring/accepting the header end-to-end.
- **Task 5 — Focused reconciliation, evidence report, Finance Batch Integration Gate prep.** Narrow, targeted regression (per this task's own validation policy — not broad/full): reversal nets outstanding correctly under concurrency; idempotent replay returns the original result; conflicting-key reuse is rejected; GL reversal is blocked while allocations are active and succeeds immediately after allocation-reversal; produces the closure evidence report for Finance Gap Closure Batch 01.

This is a planning hypothesis, per the task's own instruction — subject to revision when Task 2/3 scoping actually begins.

---

## P. Risks / unresolved CTO decisions

None of the following block **COMPLETE** status — both architecture questions have explicit, implementable decisions (§C, §E). These are residual parameter/policy choices flagged for CTO ratification, each with a stated default this report already recommends:

1. **`finance.allocation.reverse` as a new permission vs. reusing `finance.allocation.manage`** (§I). Recommendation given; either is implementable.
2. **Command-receipt retention window** — 90 days recommended (§E.B2); a tunable parameter, not an architectural fork.
3. **`Idempotency-Key` mandatory-vs-optional rollout timing** (§M) — optional-with-fallback recommended for initial rollout; when (or whether) to later require it is an operational decision, not part of this architecture.
4. **`PaymentStatus::Void`/`DocumentStatus::Void` activation** (§N.7) — confirmed dormant (modeled, guarded, unreachable) but explicitly **out of scope** for this decision and for Tasks 2–5 as proposed. Flagged here only so it is not lost: pre-posting payment/receipt cancellation has no implementation today, and someone will eventually ask for it. Recommend a separate, small future task if/when needed — it is unrelated to post-posting contra-allocation and should not be merged into Task 2's scope.
5. **`JournalController::reverse()`'s current lack of an AP/AR-specific permission gate** (§B.11) — today gated only by the generic `finance.journal.post`. Decision C's new precondition closes the *data-integrity* half of this gap (it will refuse to reverse while allocations are active, regardless of who calls it) but does not, by itself, add finer-grained *authorization*. Recommend no change beyond Decision C's guard — the generic checker permission remains sufficient once the data-integrity guard exists — but flagging so the CTO can weigh in if a stricter authorization boundary is wanted here too.

---

*End of report. No code, schema, data, or configuration was modified in producing it. Both architecture questions are decided; Tasks 2–5 await explicit CTO approval before any implementation begins.*
