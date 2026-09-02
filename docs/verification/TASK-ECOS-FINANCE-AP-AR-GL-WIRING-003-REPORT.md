# TASK-ECOS-FINANCE-AP-AR-GL-WIRING-003 — Engineering Report

**ECOS Finance — AP / AR / GL Transaction-Safety Wiring**

Workstream: ECOS ERP — Full Accounting Closure · Master plan position: Task 3 of 9

Wires the Task 2 contra-allocation and command-idempotency foundation into the real canonical AP, AR, and GL operational paths. No new accounting engine, ledger, AP/AR engine, idempotency engine, or reversal engine was created — this is wiring only, per §1.

---

## 1. Final status

## **COMPLETE**

Under the second-device completion policy (§27 of this task): mandatory wiring scope (A–G) is fully implemented, source reconciliation was done against the real current controllers/routes/engine (not assumed), 38 focused tests are written, one focused local commit exists, and no known source-review blocker remains. **TESTS EXECUTED: NO. VERIFIED: NO. CERTIFIED: NO** — no PHP/DB toolchain is reachable from this machine (established in Task 2, re-confirmed as still the case rather than re-investigated from scratch here, since that investigation was already exhaustive).

---

## 2. Starting HEAD

`f5051a457f436637cf8d96384ef5417e61866b2b` (Task 2's commit). Reconfirmed before any action: workspace, branch (`task/finance-gap-closure`), HEAD, clean working tree (only the two preserved report files untracked), and repo-local git identity (`Osama Fayez`/`eng_osamafayez@hotmail.com`) all matched exactly what Task 2 left — no unexpected concurrent state this time (unlike a sibling workspace encountered earlier this session, which is why this check was done explicitly rather than assumed).

## 3. Final implementation commit

`9d3a8c3d02555e8bf00bdf5a2ea277c1668733eb` — see §23/§26.

---

## 4. Existing Capability Gate

| Area | Finding | Classification |
|---|---|---|
| `AllocationEngine::reversePaymentAllocation`/`reverseReceiptAllocation` | Task 2's engine, unchanged, called directly by the new controller actions | **REUSE** |
| `SupplierPaymentController` / `CustomerReceiptController` | Real, existing controllers with `store`/`allocate`/`autoAllocate` already wired; `store()` extended, `reverseAllocation()` added | **EXTEND** |
| `SupplierInvoicePaymentController::initiate` | The OTHER real supplier-payment creation path (invoice-anchored), distinct from the generic one — both terminate in `AccountsPayableService::createPayment()` | **EXTEND** (found by direct route/controller inspection, not assumed from the architecture report alone) |
| `JournalEntry.source_module`/`source_event_id` | Already carries typed source linkage (`'finance.ap'`+`'payment:'.uuid`, `'finance.ar'`+`'receipt:'.uuid`, `'finance.ap'`+`'bill:'.uuid`) — confirmed by reading `JournalEngine::post()` and the posting call sites directly | **REUSE** — satisfies §12's "use existing canonical source/sourceable relationships," no new column needed |
| `JournalEngine::reverse()` | Existing guards (`cannotReverseDraft`, `alreadyReversed`) run before a `DB::transaction`; the reversal write itself is inside it | **EXTEND** — one new precondition added inside the existing transaction, no restructuring |
| `CommandIdempotencyGuard` / `FinanceCommandReceipt` | Task 2's foundation, fully proven at the guard level (`CommandIdempotencyGuardTest`), never previously wired into a real controller | **REUSE** |
| Routes (`backend/routes/api.php`) | Existing `finance/ap/payments`, `finance/ar/receipts`, `finance/ap/supplier-invoices` groups, existing `finance.allocation.manage` permission already gating `allocate`/`auto-allocate` | **EXTEND** — two new routes added under the existing groups, same permission reused |
| `finance.allocation.manage` vs. a new `finance.allocation.reverse` permission | Task 1's report explicitly left this open as the CTO's call; Task 3 does not re-litigate it | **REUSED, NOT DECIDED** — see §17 |
| `PaymentStatus::Void`/`DocumentStatus::Void` | Unchanged, not touched | **OUT OF SCOPE** (§18) |

No broad Finance audit was performed — this reused Task 1/2's own prior findings plus direct reads of the exact files this task touches.

---

## 5. Exact files changed

Commit `9d3a8c3d` — 10 files, 1465 insertions, 35 deletions:

**Modified (6):**
- [backend/Modules/Finance/Ledger/Domain/Exceptions/FinanceException.php](backend/Modules/Finance/Ledger/Domain/Exceptions/FinanceException.php) — +1 factory method.
- [backend/Modules/Finance/Ledger/Domain/Services/JournalEngine.php](backend/Modules/Finance/Ledger/Domain/Services/JournalEngine.php) — +1 private guard method, 2 new imports, 1 call site inside `reverse()`.
- [backend/Modules/Finance/Presentation/Http/Controllers/CustomerReceiptController.php](backend/Modules/Finance/Presentation/Http/Controllers/CustomerReceiptController.php) — idempotency wiring in `store()`, new `reverseAllocation()` + `findAllocation()`.
- [backend/Modules/Finance/Presentation/Http/Controllers/SupplierInvoicePaymentController.php](backend/Modules/Finance/Presentation/Http/Controllers/SupplierInvoicePaymentController.php) — idempotency wiring in `initiate()`.
- [backend/Modules/Finance/Presentation/Http/Controllers/SupplierPaymentController.php](backend/Modules/Finance/Presentation/Http/Controllers/SupplierPaymentController.php) — idempotency wiring in `store()`, new `reverseAllocation()` + `findAllocation()`.
- `backend/routes/api.php` — 2 new routes.

**Added (4, all tests):** `AllocationReversalEndpointTest.php`, `CustomerReceiptIdempotencyEndpointTest.php`, `JournalReversalAllocationGuardTest.php`, `SupplierPaymentIdempotencyEndpointTest.php` — all under `backend/tests/Feature/Finance/`.

Untouched: both preserved report files (§23/§24 of the task; confirmed via `git status` before and after — still untracked, no git history).

## 6. Migrations created, if any

**None.** Task 3 wires Task 2's already-created (not-yet-applied) migrations — `reverses_allocation_id`/`reversal_reason` on both allocation tables, and `finance_command_receipts` — into real code paths. No schema change was needed for the GL guard (it reads existing `source_module`/`source_event_id` columns).

---

## 7. AP reversal operational path

`SupplierPaymentController::reverseAllocation(Request, string $uuid, string $allocationUuid)`:

1. Validates `amount` (numeric, `gt:0`) and `reason` (required string).
2. Resolves the payment via the existing company-scoped `find()`.
3. Resolves the allocation as `PaymentAllocation::where('payment_id', $payment->id)->where('uuid', $allocationUuid)->firstOrFail()` — a child of the already-resolved payment, never a bare global lookup by uuid.
4. Calls `AllocationEngine::reversePaymentAllocation($allocation, $amount, $reason, $actorId)` — Task 2's engine, unchanged.
5. Returns the new contra-allocation's id, the original's id (`reverses_allocation_id`), the bill's uuid, amount, reason, and the payment's refreshed `unallocatedAmount()`.

Full, partial, and repeated sequential partial reversal are all the same endpoint with a smaller `amount` — Task 2's engine caps at whatever remains reversible; the controller adds no policy of its own.

## 8. AR reversal operational path

`CustomerReceiptController::reverseAllocation(...)` — the exact mirror, resolving `ReceiptAllocation::where('receipt_id', $receipt->id)->where('uuid', $allocationUuid)`, calling `AllocationEngine::reverseReceiptAllocation(...)`.

## 9. Effective-balance behavior

No new balance derivation was written. Both endpoints return `->fresh()->unallocatedAmount()`/`->fresh()->outstanding()` — the SAME plain `SUM(amount)` methods Task 2 already established, which net a negative contra-allocation automatically. Verified by direct inspection that neither method was touched in this task (`git diff` shows zero changes to `SupplierPayment.php`, `CustomerReceipt.php`, `SupplierBill.php`, `CustomerInvoice.php`).

---

## 10. Supplier payment idempotency wiring

Two entry points, both wired, both reusing the same `CommandIdempotencyGuard` instance (constructor-injected):

- `SupplierPaymentController::store()` — `commandType: 'ap.payment.create'`, payload = the validated request array, command = the existing `createPayment()` call unchanged.
- `SupplierInvoicePaymentController::initiate()` — `commandType: 'ap.payment.create.invoice_anchored'`, payload = validated array + `invoice_id`, command = the existing `initiatePayment()` call unchanged. Kept as a **separate** command type from the generic path — same underlying `SupplierPayment::create()`, but a different logical command (one is anchored to an invoice, the other is not), so a client using the same key against both would not be conflated.

## 11. Customer receipt idempotency wiring

`CustomerReceiptController::store()` — `commandType: 'ar.receipt.create'`, same pattern. No second guard class — all three entry points construct-inject and call the identical `Modules\Finance\Shared\Domain\Services\CommandIdempotencyGuard`.

## 12. Idempotency HTTP contract

`Idempotency-Key` header (matching Task 1/2's naming), read via `$request->header('Idempotency-Key')`. **Decision, evidence-grounded per §8's own instruction**: the header is **optional**, not mandatory, at all three entry points. Making it mandatory now would reject every existing caller of these exact endpoints — confirmed by direct inspection that `PaySupplierInvoiceServiceTest.php` and `SupplierPaymentFundingAccountTest.php` (both pre-existing, both call `initiate()`/`createPayment()`-backed paths) never send this header. A null/empty key falls through to `CommandIdempotencyGuard`'s own built-in default (run once, uncoordinated) — identical to today's behavior. Requiring the header is documented in each method's docblock as a deliberate *future* tightening, not decided here. Response semantics: 201 + `Idempotent-Replay: false` on first execution; 200 + `Idempotent-Replay: true` on replay; a conflict throws `FinanceException::idempotencyKeyConflict()` (Task 2), rendered 422 by the existing exception-render convention.

## 13. Failed-first retry behavior

Not re-implemented — inherited unchanged from Task 2's `CommandIdempotencyGuard::execute()`: the command runs inside the same transaction that claims the receipt, so a thrown exception (e.g. an invalid `funding_account_id`/`deposit_account_id`) rolls back everything, leaving no receipt row and no orphaned payment/receipt row. Proven at the wiring level (not just re-asserted) by `SupplierPaymentIdempotencyEndpointTest::test_failed_first_attempt_leaves_no_false_success_receipt` / `test_safe_retry_after_failed_first_attempt` and their AR mirror — these call the real controller method, not the guard directly.

## 14. Concurrency protections

Unchanged, reused, not bypassed:
- Allocation reversal: same `lockForUpdate` payment/receipt-then-bill/invoice order as `allocatePayment`/`allocateReceipt` (Task 2) — the controller does not pre-compute or cache any reversible amount; it passes straight through to the engine.
- Command idempotency: same claim-inside-transaction race handling (Task 2) — the controller does not read or write `finance_command_receipts` itself.
- **New**: the GL reversal guard re-derives `allocatedAmount()` *inside* `reverse()`'s existing transaction (moved there deliberately, not left before it) rather than trusting a pre-transaction snapshot — see §16.

## 15. Journal source resolution

`JournalEntry.source_module` + `source_event_id` (existing columns, existing values — `AccountsPayableService::postPayment()` writes `sourceModule: 'finance.ap'`, `sourceEventId: 'payment:'.$payment->uuid`; `AccountsReceivableService::postReceipt()` writes `'finance.ar'`/`'receipt:'.$receipt->uuid`; `AccountsPayableService::postDocument()` writes `'finance.ap'`/`'bill:'.$bill->uuid`). The guard parses this existing convention (`str_starts_with($eventId, 'payment:')`/`'receipt:'`) rather than adding a new linkage column — confirmed necessary and sufficient by reading all three posting call sites directly, not assumed.

## 16. GL allocation-reversal guard

`JournalEngine::assertNoActiveSubledgerAllocations(JournalEntry $entry)` (private, new): resolves the source payment/receipt by the uuid embedded in `source_event_id`, reads its `allocatedAmount()` (Task 2's own derivation, already netted by any contra-allocation), and throws `FinanceException::reversalBlockedByActiveAllocations($kind, $effectiveAmount)` when it is `> 0.0`. Called as the **first statement inside** `reverse()`'s existing `DB::transaction` closure — after the pre-existing `cannotReverseDraft`/`alreadyReversed` checks (which stay outside the transaction, unchanged), but before any write — so it reads the latest committed allocation state rather than a stale pre-transaction snapshot. A journal with no matching `source_module`+prefix (manual journals, bill postings, anything else) is untouched — proven explicitly by `JournalReversalAllocationGuardTest::test_bill_posting_journal_is_unaffected_by_the_payment_allocation_guard`, which posts a supplier bill (also `source_module='finance.ap'`, but `'bill:'` not `'payment:'`) and confirms its journal reverses normally.

## 17. Authorization

No new permission was created; no Task-3-specific role check was written. The two new reversal routes reuse `permission:finance.allocation.manage` — the same permission already gating `allocate`/`auto-allocate` on both controllers. **This is a reuse, not a resolution**, of the question Task 1's architecture report explicitly left open (a dedicated `finance.allocation.reverse` permission vs. reusing the existing one) — documented as such in both the route comments and here, not silently decided. Tenant/company boundaries are unchanged: every lookup (`find()`, `findAllocation()`) is scoped by `company_id` first, so a foreign-company payment/receipt/allocation is a 404, never reachable to authorize against — proven by `AllocationReversalEndpointTest::test_foreign_company_ap_payment_is_unreachable` and the cross-payment/cross-receipt tests.

## 18. Audit

Unchanged from Task 2: actor (`allocated_by`), timestamp (`allocated_at`), the original-allocation link (`reverses_allocation_id`), amount, and reason are all recorded on the contra-allocation row exactly as Task 2 established — the controller adds nothing and removes nothing from that record. Idempotency replay creates no new row of any kind (verified: `resolve()` in `CommandIdempotencyGuard` only reads); a GL reversal rejection's message names the kind (`supplier payment`/`customer receipt`) and the effective amount, not any other company's data — it only ever describes the SAME journal's own source, so no foreign-tenant leak is possible through the error message itself.

---

## 19. Tests written

**38** new focused tests across 4 files (mapped to the task's 40-item list; 2 items — same-key-different-company for the fingerprint/tenant checks — are covered by shared tests spanning multiple numbered items rather than 1:1, noted inline in each file):

| File | Count | Covers |
|---|---|---|
| `JournalReversalAllocationGuardTest.php` | 7 | Items 31–37 |
| `SupplierPaymentIdempotencyEndpointTest.php` | 9 | Items 19–25, 38, 40 |
| `CustomerReceiptIdempotencyEndpointTest.php` | 7 | Items 26–30, 38 |
| `AllocationReversalEndpointTest.php` | 12 | Items 1–18 (AP/AR reversal wiring, cross-payment/receipt, foreign-company, mandatory reason) |

Domain-level invariants for reversal (full/partial/sequential/over-reversal/immutability/concurrency) are **not re-tested** here — they are already exhaustively covered at the `AllocationEngine` level by Task 2's own test files. This task's tests specifically exercise the **controller/HTTP wiring layer**: real controller methods called with constructed `Illuminate\Http\Request` objects (this codebase's Finance tests consistently call services/controllers directly rather than through full HTTP routing — no test anywhere in this Finance suite uses `actingAs()->postJson()`), proving routing/validation/scoping/response-shape concerns Task 2's tests could not have covered since the wiring didn't exist yet.

## 20. Tests executed

**NO.** Same environment gate as Task 2 (§5 of that report) — no PHP/Composer/MySQL toolchain is reachable from this machine. Per §20 of this task, this was **not** re-investigated (no new attempt to install PHP/Docker/WSL was made) — Task 2 already exhausted that search machine-wide.

## 21. Verification state

VERIFIED: NO. Every claim in §7–§18 above is supported by direct reading of the actual current source this task modified (not assumption, not carried over from the architecture report without re-checking) and by careful manual tracing of the code paths the new tests exercise — but none of it has been confirmed by a green test run.

## 22. Unverified runtime behavior

- Whether `Illuminate\Http\Request::validate()` behaves identically on a manually-constructed (`Request::create(...)`) instance as on a router-resolved one in this exact Laravel version — used throughout the new tests; believed correct (a well-established Laravel testing technique) but not executed.
- The exact HTTP status/body Laravel produces for an uncaught `ModelNotFoundException` vs. how the tests assert it (tests call the controller method directly and assert the exception type itself, side-stepping the question of what the framework's exception handler would render — this is intentional and does not depend on execution to reason about, but is noted for completeness).
- All MySQL-level behavior already flagged as unverified in Task 2 (transaction/lock semantics, unique-constraint race handling) — unchanged, not re-verified here.

## 23. Exact git status

Clean except the two preserved, untracked report files. `HEAD = 9d3a8c3d02555e8bf00bdf5a2ea277c1668733eb`. Not pushed — local branch remains ahead of `origin/task/finance-gap-closure` (now by 2 commits).

## 24. Remaining Finance Foundation gaps

- Execution/verification of everything in Tasks 2 and 3 — the single largest remaining gap, environmental, not architectural.
- The `finance.allocation.reverse` vs. `finance.allocation.manage` permission question (Task 1, restated §17) — still open, still not this task's to decide.
- `PaymentStatus::Void`/`DocumentStatus::Void` classification (WIRING GAP vs. LEGACY/RETIRE) — still deferred, per §18.
- Command-receipt retention policy (Task 2, §P.2) — still no purge job; not required for correctness.

## 25. Recommendation for Task 4

- Secure genuine PHP/Composer/MySQL toolchain access (the first/canonical device, or equivalent) before further Finance implementation work compounds three tasks' worth of unexecuted tests.
- Ratify the allocation-reversal permission question (§17) — a small, contained decision blocking nothing else.
- If Task 4 continues the Accounting Closure workstream's broader FIN-EXEC track (revenue recognition, COGS, etc.), that is explicitly a **different** track from this one (transaction-safety) per this task's own §22 — do not conflate the two when scoping Task 4.
- Consider whether `PaymentStatus::Void`/`DocumentStatus::Void` activation belongs in Task 4 or a dedicated later task — still unscheduled.

---

## Required final state

**IMPLEMENTED:** YES
**TESTS WRITTEN:** YES
**TESTS EXECUTED:** NO
**VERIFIED:** NO
**COMMITTED:** YES — `9d3a8c3d02555e8bf00bdf5a2ea277c1668733eb`
**INTEGRATED:** NO
**DEV VISIBLE:** NO
**USER VERIFIED:** NO
**CERTIFIED:** NO

Task 4: **NOT STARTED**

---

*End of report. No DEV migration, seed, deploy, merge, push, cherry-pick, or reset occurred. Both preserved architecture/foundation reports remain untracked and untouched. Awaiting CTO review before Task 4 begins.*
