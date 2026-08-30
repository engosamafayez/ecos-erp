# TASK-ECOS-ADR-042-TARGETED-REMEDIATION-001 — Engineering Report

**Task type:** WRITE (exclusive writer in `C:\ecos-develop`, branch `develop`).
**Objective:** Correct ONLY the ADR-042 deviations identified by
`TASK-ECOS-ADR-042-DEFERRED-IMPLEMENTATION-CERTIFICATION-001-REPORT.md`, so the ADR-042
Class-A implementation becomes eligible for future baseline reconciliation — without
redesigning the Order FSM, broadening into cleanup, or mutating Git/DEV state.
**Final status:** ✅ **COMPLETE** (D1–D6 remediated; D7 = documented no-change decision).
**Distribution unblock:** **SOURCE READY FOR RECONCILIATION.**
**Class-A:** **READY** for the future preservation-first reconciliation (gated only on the
existing ADR-042 certification sign-off + §5/§8 collision handling owned by the integration task).

---

## 1. Task and constraints

Correct the seven deviations (D1–D7) the certification enumerated, reusing the canonical
ADR-042 authorities. **Forbidden** (and not performed): commit, push, merge, rebase,
cherry-pick, reset, restore, stash, clean, `git add`, branch change, remote reconciliation,
migrations, DEV-data mutation, deploy, lane-clone creation, relocation. **Preserve the
certified contract:** 11 canonical `OrderStatus` cases, no `new`, `confirmed` first-class,
§2.2 lock model, PaymentFulfillmentGate, payment-proof control, and each operational
subsystem's OWN explicit closed `['in_progress','confirmed']` eligibility list — no
`!isTerminal()` widening, no FSM-V3 redesign.

## 2. Starting state

- Working directory `C:\ecos-develop`, branch `develop`, dirty ADR-042 deferred working set.
- Published Guardian-clean baseline on remote develop: `2b851c14` (descendant of `72ecaddc`).
- No Git state was changed by this task; HEAD is unchanged.

## 3. Scope fidelity — in vs. out

**In scope (done):** D1 (`VerifyPaymentAction`), D2 (`DistributionPlanningController`),
D3 (wave/preparation test fixtures + docblock), D4 (`DistributionCoreTest`), D5 (live FE
vocabulary), D6 (4 missing tests, execution deferred), D7 (§11 migration disposition).

**Explicitly preserved / out of scope (untouched):** `manual-order-form.tsx` and the
broader 24-error tsc WIP set (§10); the navigation restructure (§11); the §5/§8 reconciliation
collision files (`phpstan-baseline`, `V3TransitionResolutionTest`, `routes/api.php`
source/runtime drift, nav) which belong to the integration task; two **DEAD** FE components
that still carry legacy vocab (reported, not deleted — deletion is unrelated cleanup); and the
two legacy-named orders-service methods that still have live callers (reported, not renamed).

## 4. Method

Audit-first, then reuse the already-certified canonical authority for each fix rather than
introduce a parallel one. Every production change was checked for symbol consistency and
passed `php -l`; every FE change was checked with `tsc -p tsconfig.app.json` and `eslint`.
Added tests were **not executed** (project freeze) — marked **TEST EXECUTION DEFERRED**.

## 5. D1 (HIGH) — `VerifyPaymentAction` auto-confirm removed

**File:** `backend/Modules/Commerce/Orders/Application/Actions/VerifyPaymentAction.php`

**Deviation:** the legacy endpoint `POST /orders/{order}/verify-payment` injected
`FulfillmentEngine` + `ConfirmOrderWorkflow` and ran `engine->run(confirmWorkflow, …)`,
auto-advancing `awaiting_payment → confirmed`. This violates §7.1, which requires the payment
trigger to advance `awaiting_payment → in_progress` **only** — "it NEVER confirms".

**Change:** the endpoint is now a thin compatibility adapter over THE canonical §7.1 authority.
The action injects `ReevaluateOrderFulfillmentAction $reevaluate` (same namespace — no new
`use`), removed the `FulfillmentEngine` and `ConfirmOrderWorkflow` imports, keeps the
`awaiting_payment` precondition and the proof-path attach, then calls
`$this->reevaluate->execute($order)` and returns `OperationResult::success($fresh, …)`.

**Why:** `ReevaluateOrderFulfillmentAction` is the single §7.1 authority already used by the
canonical `/payment-proofs/{proof}/verify` surface (`VerifyPaymentProofAction`). It ADVANCES
via `ProcessOrderWorkflow → in_progress`, RETURNS via `ReturnToPaymentWorkflow`, is idempotent
and transactional, writes no status directly, and holds a single `PaymentFulfillmentGate`.
Routing the legacy endpoint through it removes the auto-confirm **without** a second engine, a
duplicate gate, or a direct `Order.status` write, and preserves the route/response contract.

**Verification:** `php -l` clean; grep confirms no `FulfillmentEngine`/`ConfirmOrderWorkflow`
references remain except the docblock that documents the fix; `execute(mixed …$arguments)`
signature accepts the `Order` first argument.

## 6. D2 (MED) — `DistributionPlanningController` READY list derived from the contract

**File:** `backend/Modules/Logistics/Distribution/Presentation/Http/Controllers/DistributionPlanningController.php`

**Deviation:** `private const READY_STATUSES = ['confirmed','preparing']` — hardcoded and
carrying pre-V3 `preparing`. This is a KPI-header read surface; the **core** Distribution
admission was already correct.

**Change:** replaced the constant with
`private function readyStatuses(): array { return array_values((array) config('distribution.eligible_order_statuses', [])); }`
and updated all five usages (`->whereIn('status'|'o.status', $this->readyStatuses())` at lines
40, 70, 98, 228, 493).

**Why:** `config('distribution.eligible_order_statuses')` is derived at
`config/distribution.php:57-60` from `OrderStatus::fulfilmentEligible()` = `[in_progress,
confirmed]` — the SAME SSOT that already gates the Distribution WRITE paths
(`DistributionCollectionService`, `OrderCityBinder`) and the late-order triage read. The KPI
header now reports over exactly the set the engine admits; `preparing` is gone.

**Verification:** `php -l` clean; grep confirms zero remaining `READY_STATUSES` references.

## 7. D3 — wave/preparation test fixtures + docblock

- `backend/tests/Feature/Operations/WavePostponeOrderTest.php:405` and
  `backend/tests/Feature/Operations/WaveDeferredOrderCutoffReturnTest.php:133`:
  `'eligible_order_statuses' => ['new','in_progress']` → `['in_progress','confirmed']`
  (with an ADR-042 §7 note). `new` is not a runtime case; the fixture asserted a pre-V3 set.
- `backend/tests/Feature/Operations/PreparationEntryGateTest.php`: docblock corrected from the
  pre-V3 "`new, in_progress`" rule and the stale "Confirmed IS In Progress" conclusion to the
  ADR-042 statement — the closed `[in_progress, confirmed]` set and `confirmed` **RESTORED** as
  a first-class status. Test body was already correct (asserts the closed set).

**Verification:** `php -l` clean on all three.

## 8. D4 — `DistributionCoreTest` confirmed-window case uses a real status

**File:** `backend/tests/Feature/Logistics/DistributionCoreTest.php:215-224`

**Deviation:** `test_3_confirmed_order_before_cutoff_enters_current_window` created an
`in_progress` order and force-filled `confirmed_at` — asserting a *timestamp*, not the
`Confirmed` STATUS, so it did not actually exercise §2.1's first-class `confirmed`.

**Change:** `$order = $this->order(OrderStatus::Confirmed->value);` with a corrected comment;
assertion unchanged (still admits via the closed `[in_progress, confirmed]` list).

**Verification:** `php -l` clean.

## 9. D5 — frontend legacy vocabulary alignment (live files only)

- `frontend/src/features/orders/components/order-status-tabs.tsx` (**LIVE**, 1 importer): removed
  pre-V3 keys (`pending/processing/preparing/completed/rescheduled/review`) from the three
  `Partial<Record<string,string>>` colour maps; added an ADR-042 §8 note. All three maps are
  indexed with a `?? default` fallback, so removal is type-safe.
- `frontend/src/features/orders/components/smart-status-selector.tsx` (**LIVE**, 2 importers):
  removed the same six pre-V3 keys from the `Record<string,string>` `STATUS_COLOR` map (indexed
  with `?? 'text-foreground'`).

**Reported, deliberately NOT touched** (out of this targeted scope):
- `order-inline-status-cell.tsx` — **DEAD** (0 importers), still carries legacy vocab.
- `order-workflow-actions-panel.tsx` — **DEAD** (0 importers).
- `ordersService.workflowReturnToPending` / `workflowReturnToProcessing` — legacy-named but
  **wired** (called from `use-orders.ts:329,337`); renaming is FSM-vocab cleanup, not an
  ADR-042 correctness fix.

**Verification:** `tsc -p tsconfig.app.json` — neither changed file appears in the error set
(23 pre-existing errors, all in 13 unrelated WIP files incl. the §10 `manual-order-form.tsx`);
`eslint` on both changed files exits 0.

## 10. D6 — missing test coverage added (execution deferred)

Added SOURCE for the four coverage gaps; **not executed** (freeze) — each file is marked
**TEST EXECUTION DEFERRED**:

- `backend/tests/Unit/Commerce/Orders/OrderStatusV3ContractTest.php` (pure enum, no DB):
  **§2.2** `isLocked()` false only for `{in_progress, scheduled, awaiting_payment}` and equal to
  the negation of `entryStatuses()`; **§8** every legacy value (`new/pending/processing/
  preparing/completed/review/rescheduled`) `tryFrom() === null` (rejected at the edge, no
  read-time repair); plus a guard that the enum holds exactly the 11 canonical cases.
- `backend/tests/Feature/Orders/OrderFsmV3RemediationHttpTest.php` (real HTTP surface,
  `DatabaseTransactions`): **§12** `GET /orders/statuses` `data.all` equals
  `OrderStatus::cases()` verbatim and exposes no legacy vocabulary; manual entry options exclude
  `confirmed`; **§6.1 (operator side)** an order with no resolved warehouse (real RC-10 path via
  manual POST without geography) is still Confirmable, and Confirm fabricates no warehouse.

Fixtures for the feature file are modeled precisely on the certified
`OrdersFinalCertificationHttpTest` harness (same manual-order surface, same RC-10 mechanism).

**Verification:** `php -l` clean on both new files.

## 11. D7 — §11 normalisation migration disposition (NO CHANGE)

**File:** `backend/Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_08_13_100000_supersede_order_lifecycle_v3_canonical.php`

**Evidence gathered:**
1. **Not applied / not historical.** The migration is **untracked** (`??`) in the dirty working
   set and is **absent from the published baseline `2b851c14`** (0 tracked in the clean clone).
   It has never been deployed (ADR-042 impl is deferred). So there is no applied migration
   history to rewrite — §8's "do not rewrite applied history" is not the operative constraint.
2. **It already satisfies §11's SAFETY INTENT.** The `OrderStatus` mentions in the file are in
   the **docblock only** (explaining *why* the enum must not be referenced). The executable code
   uses literal-string `DB::table('orders')->where(…)->update(['status' => …])` and
   `Schema::table(…, fn … $table->string('status', …)->default('in_progress')->change())` — **no
   enum, no Eloquent model, no cast**. It therefore runs correctly whether the old or the new
   code is loaded (the exact §11 guarantee), is idempotent (`Schema::hasColumn`/`hasTable`
   guards, keyed `where` updates), and is same-deploy.

**Decision — NO forward change.** The only gap is the literal wording "raw SQL only" vs. the
query-builder + schema-builder mechanism actually used. Both emit enum-independent SQL, so the
mechanism meets §11 in substance. Rewriting `DB::table()->update()` to hand-authored
`DB::statement('UPDATE …')` would be a stylistic conformity change that adds **no** deploy
safety while introducing SQL-dialect risk (the builder handles MySQL quoting/binding). Per the
task's D7 rule, that is not a genuine requirement → **smallest forward-safe action is none**.
**Flag for the ADR owner** to confirm the §11 letter is satisfied by the enum-independent
builder mechanism. The migration was **not executed**.

## 12. Canonical-authority reuse (no parallel machinery introduced)

D1 reuses `ReevaluateOrderFulfillmentAction` (one §7.1 authority, one `PaymentFulfillmentGate`);
no second engine, no duplicate gate, no direct status write. D2 reuses
`config('distribution.eligible_order_statuses')` (the enum-derived SSOT already gating the write
paths); no new list. No new workflow, service, or enum case was created by this task.

## 13. Contract-preservation checklist (post-remediation)

| Invariant | State |
| --- | --- |
| 11 canonical `OrderStatus` cases; `new` absent | ✅ unchanged (verified) |
| `confirmed` first-class; reachable only via Confirm | ✅ preserved (D3/D4 assert it) |
| §2.2 unlocked = `{in_progress, scheduled, awaiting_payment}` | ✅ unchanged; now covered by D6 |
| §7 each subsystem keeps its OWN closed `[in_progress, confirmed]` list | ✅ no widening; D2 derives from it |
| §7.1 payment trigger → `in_progress` only (never `confirmed`) | ✅ **restored** by D1 |
| PaymentFulfillmentGate / payment-proof control | ✅ single gate, reused |
| No `!isTerminal()` generalisation | ✅ none introduced |

## 14. Out-of-scope items preserved

`manual-order-form.tsx` + the 24-error tsc WIP set (§10) untouched and still failing exactly as
before (net-neutral). Navigation restructure (§11) untouched. §5/§8 reconciliation-collision
files untouched. Two DEAD FE components and two legacy-named-but-wired service methods reported,
not modified.

## 15. Static verification summary

- **`php -l`:** clean on all 8 changed/added PHP files.
- **`tsc -p tsconfig.app.json`:** both changed FE files produce **zero** errors; the 23
  pre-existing errors are all in 13 unrelated WIP files (net-neutral).
- **`eslint`:** both changed FE files exit 0.
- **Not run (freeze):** PHPUnit/the added tests, full ESLint sweep, Vite build, migrations.

## 16. FILE / DEVIATION / CHANGE / WHY / VERIFICATION

| File | Deviation | Change | Why | Verification |
| --- | --- | --- | --- | --- |
| `…/Orders/Application/Actions/VerifyPaymentAction.php` | D1 §7.1 auto-confirm | inject `ReevaluateOrderFulfillmentAction`; drop engine+confirm-workflow; call `reevaluate->execute()` | canonical §7.1 authority; advances to `in_progress`, never `confirmed`; one gate | `php -l` ✓; grep ✓ |
| `…/Distribution/…/DistributionPlanningController.php` | D2 stale `['confirmed','preparing']` | `readyStatuses()` from `config('distribution.eligible_order_statuses')`; 5 usages | enum-derived SSOT `[in_progress,confirmed]`; drops pre-V3 `preparing` | `php -l` ✓; grep ✓ |
| `…/Operations/WavePostponeOrderTest.php` | D3a `['new','in_progress']` | → `['in_progress','confirmed']` | §7 fulfilment-eligible set | `php -l` ✓ |
| `…/Operations/WaveDeferredOrderCutoffReturnTest.php` | D3b `['new','in_progress']` | → `['in_progress','confirmed']` | §7 fulfilment-eligible set | `php -l` ✓ |
| `…/Operations/PreparationEntryGateTest.php` | D3c pre-V3 docblock | docblock → closed `[in_progress,confirmed]`; `confirmed` restored | doc accuracy; body already correct | `php -l` ✓ |
| `…/Logistics/DistributionCoreTest.php` | D4 `in_progress`+`confirmed_at` | → real `OrderStatus::Confirmed` | exercise first-class `confirmed` (§2.1) | `php -l` ✓ |
| `frontend/…/order-status-tabs.tsx` | D5 legacy keys (live) | remove 6 pre-V3 keys ×3 maps | §8 vocabulary; fallback-indexed | `tsc`/`eslint` ✓ |
| `frontend/…/smart-status-selector.tsx` | D5 legacy keys (live) | remove 6 pre-V3 keys | §8 vocabulary; fallback-indexed | `tsc`/`eslint` ✓ |
| `…/Unit/Commerce/Orders/OrderStatusV3ContractTest.php` | D6 §2.2/§8 gap | NEW enum contract test | prove lock model + no-legacy-repair | `php -l` ✓; **exec deferred** |
| `…/Feature/Orders/OrderFsmV3RemediationHttpTest.php` | D6 §12/§6.1 gap | NEW HTTP test | statuses-from-enum + operator confirm w/o warehouse | `php -l` ✓; **exec deferred** |
| `…/Migrations/2026_08_13_100000_supersede_order_lifecycle_v3_canonical.php` | D7 §11 wording | **no change** (documented) | already enum-independent + idempotent + same-deploy | evidence in §11 |

## 17. DEVIATION / RESOLVED / BLOCKER

| Deviation | Resolved? | Blocker |
| --- | --- | --- |
| D1 — verify-payment auto-confirm (§7.1) | ✅ Yes | — |
| D2 — DistributionPlanningController stale list (§2/§7/§12) | ✅ Yes | — |
| D3 — wave/preparation fixtures + docblock | ✅ Yes | — |
| D4 — DistributionCoreTest confirmed status | ✅ Yes | — |
| D5 — live FE legacy vocabulary | ✅ Yes (live files) | Dead components + wired legacy-named methods reported for later, not in scope |
| D6 — 4 missing tests | ✅ Source added | Execution **deferred** by project freeze (run in the integration task) |
| D7 — §11 migration | ✅ Decided (no change) | Owner confirmation of the §11 letter-vs-mechanism reading requested |

## 18. Distribution unblock decision

**SOURCE READY FOR RECONCILIATION.** The two Distribution-facing deviations are corrected: D2
routes the KPI read through the enum-derived `[in_progress, confirmed]` SSOT, and D4 exercises
the real `Confirmed` status. The core admission (config + collection service + city binder) was
already correct. No `preparing` and no `new` remain in Distribution source or its tests. There is
no remaining ADR-042 source deviation blocking Distribution reconciliation.

## 19. Class-A reconciliation-eligibility decision

**READY.** All ADR-042 source deviations (D1–D5) are corrected, the missing coverage (D6) is
present, and the migration (D7) is dispositioned. The certified contract is preserved intact
(§13). Class-A integration remains gated only on the items the certification/design already
assigned to the **integration task, not this one**: the ADR-042 certification sign-off, and the
§5/§8 collision handling (`phpstan-baseline`, `V3TransitionResolutionTest`, `routes/api.php`
source/runtime drift, navigation). The 24-error WIP set and nav restructure stay outside ADR-042.

## 20. Residual risks and deferred items

- **Test execution deferred:** the 4 new tests + the existing ADR-042 suite must be run in the
  future integration task (freeze forbids execution here).
- **D7 owner confirmation:** the §11 "raw SQL only" letter vs. the enum-independent builder
  mechanism — confirm before deploy; no code change proposed.
- **FE cleanup (non-ADR-042):** two DEAD components and two legacy-named service methods carry
  pre-V3 vocabulary; disposition is a separate cleanup task.
- **`routes/api.php` source/runtime drift:** pre-existing, untouched, owned by the integration task.

## 21. Constraint compliance (what was NOT done)

No commit, push, merge, rebase, cherry-pick, reset, restore, stash, clean, `git add`, branch
change, or remote reconciliation. No migration run, no DEV-data mutation, no deploy, no lane
clone, no relocation. HEAD and Git state are unchanged. Only source files were edited.

## 22. Final status and next actions

**COMPLETE.** D1–D6 remediated and statically verified; D7 dispositioned with evidence.
Distribution source **READY FOR RECONCILIATION**; Class-A **READY** subject to the
integration-task gates above.

**Next (integration task, not this one):** (1) run the ADR-042 suite incl. the 4 new tests;
(2) obtain ADR-042 certification sign-off; (3) resolve the §5/§8 collision files; (4) confirm
the D7 §11 reading; (5) proceed with the preservation-first Class-A reconciliation.
