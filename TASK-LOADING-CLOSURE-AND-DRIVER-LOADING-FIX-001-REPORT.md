# TASK-LOADING-CLOSURE-AND-DRIVER-LOADING-FIX-001

**Status: IMPLEMENTED / VERIFICATION BLOCKED**

Both defects are fixed and proven by tests. The status is **not** VERIFIED because §F
requires browser confirmation of both screens and the app requires authentication I do not
perform. Per §G, "compiles and passes tests" is not verification of a runtime error.

Date: 2026-08-25 · Branch: `develop` · Not committed, not deployed

| Surface | Backend | Frontend | Browser |
|---|---|---|---|
| **Operator Loading Workspace** | unchanged (contract was correct) | fixed · **5/5** new contract tests | **BLOCKED** |
| **Driver Loading** | fixed · **23/23**, 268 assertions | unchanged · 12/12 (previous run) | **BLOCKED** |
| Wider Distribution/Loading regression | **PENDING** — gate held by another agent | — | — |

---

## 1. Operator Loading Workspace — root cause

`sessions.data?.map is not a function` at `loading-os-workspace-page.tsx`.

The API envelope is `ApiResponse::make()`:

```json
{ "success": true, "message": "OK", "data": …, "errors": [] }
```

`LoadingSessionController::index()` **paginates**, so its payload is itself nested:

```json
{ "data": { "data": [ …sessions… ], "meta": { page, per_page, total, last_page } } }
```

The service read `data.data` — which is the paginator **object**, not the array:

```ts
const { data } = await apiClient.get<{ data: LoadingSession[] }>(`${BASE}/sessions`, …);
return data.data;               // → { data: [...], meta: {...} }
```

**This endpoint is the only paginated one in the service.** `VehicleAssignmentController::index()`
returns `$this->success(VehicleAssignmentResource::collection(...))` — a bare collection — so
`data.data` is correct for `listAssignments` and `listAllocations`. The bug was one method,
not a systemic mismatch.

## 2. Operator Loading Workspace — fix

Corrected at the **service boundary**, per §A.6: the frontend was reading the wrong shape;
the backend contract is the standard paginated envelope and was left untouched.

```ts
const { data } = await apiClient.get<{ data: { data: LoadingSession[]; meta?: unknown } }>(…);
return data.data.data;
```

**No `Array.isArray()` guard was added**, and §A.4 is right that one would have been worse
than the crash: it would have suppressed the error and rendered a permanently empty session
list — a silent failure in place of a loud one.

Business logic, permissions, session lifecycle and Group → Trip → Loading relationships are
untouched. No Driver Mobile UI or behaviour was applied to this screen.

## 3. Operator Loading Workspace — browser verification

**BROWSER NOT VERIFIED — AUTHENTICATION CONSTRAINT.**

`http://127.0.0.1:5173/app/operations/loading/workspace` redirects to `/app/login`; there is
no stored session (`localStorage` holds only `ecos:activeCompanyId`, `ecos:activeWarehouseId`,
`language`, `ecos-orders-workspace-v1` — no auth token). Confirming the page renders requires
signing in, which I do not do.

What *is* proven: the service now returns an array for the real paginated payload, asserted
directly against the crash (`expect(() => sessions.map(...)).not.toThrow()`).

## 4. Driver Loading — P1 root cause

The chain in the brief is accurate and confirmed in code:

1. `LoadProductAction` uses absolute-SET semantics and computes `delta = loaded − previouslyLoaded`.
2. A downward correction makes `delta` negative.
3. That negative was passed to `VehicleInventoryService::recordLoad()`.
4. `vehicle_inventory_movements` enforces `CHECK (quantity > 0)`, so the insert fails.
5. The transaction rolls back.
6. `DriverLoadingController::loadProduct()` caught `RuntimeException` — and
   **`QueryException extends PDOException extends RuntimeException`** — so a database fault
   was reclassified as a business refusal and its raw SQL, including table, column and
   constraint names, was returned to a mobile client as a 422.

## 5. Driver Loading — correction implementation

**Already implemented in the tree when this task began** (the code carries a
`TASK-DRIVER-02` reference), so this task verified it rather than rewriting it.

**Routing, at the canonical writer** — `LoadProductAction` now sends the signed delta to the
matching method instead of forcing a negative through `recordLoad()`:

- `delta > 0` → `recordLoad()`
- `delta < 0` → `recordLoadCorrection(quantityRemoved: |delta|)`

**`recordLoadCorrection()`** holds `lockForUpdate`, writes a **positive magnitude** with the
direction carried in `movement_type`, and therefore respects `CHECK (quantity > 0)` rather
than weakening it. No constraint was disabled, no negative row is written, no second custody
engine exists, and warehouse stock is deliberately untouched (deduction happens at dispatch,
so un-loading must not credit it).

**Guards inside the correction:**
- custody may not go below zero;
- a quantity already delivered or returned may not be un-loaded — it is accounted for by its
  own movement and cannot be retracted by a loading correction.

## 6. Custody invariants

| Invariant | Mechanism |
|---|---|
| Absolute SET on the task | `quantity_loaded` written as an absolute value |
| Custody moves by DELTA | `delta = loaded − previouslyLoaded` |
| Idempotent | re-posting the same value ⇒ delta 0 ⇒ no movement |
| Never negative | correction refuses below zero |
| Ledger positive-only | magnitude + `movement_type`, `CHECK (quantity > 0)` intact |
| Atomic | `DB::transaction` in both the action and the writer |
| Over-load refused | `loaded − planned > EPSILON` throws |

**Planned, Actual and Custody remain three distinct quantities.** `quantity_planned` is what
was required; `quantity_loaded` is what was physically loaded; custody receives the *actual*,
by delta, and accumulates rather than being overwritten.

## 7. Authentication behaviour

Unchanged by this task. Driver identity is still derived server-side —
`Driver::where('user_id', Auth::id())`, then the trip via `driverVehicleAssignment.driver_id`
within the company scope — so `driver_id` is never taken from the client.

## 8. Error boundary behaviour

`QueryException` is now caught **before** the `RuntimeException` catch and rethrown, in both
`loadProduct()` and `openAssignment()`. A database fault is therefore logged and handled as a
server fault with no schema disclosure, while genuine business refusals (over-load, correction
guards) still answer 422 with their own operator-readable message.

## 9. Tests

| Suite | Result |
|---|---|
| `DriverLoadingCustodyHandoffTest` + `GroupGrainDriverLoadingTest` | **23 / 23, 268 assertions** |
| `loading-os-service.test.ts` (new) | **5 / 5** |
| `driver-loading-page.test.tsx` | 12 / 12 |

The backend suites cover the §B cases directly: `0 → 18`, `18 → 18` (retry does not double),
`18 → 12` (downward correction reflected), accumulation across cycles, over-load refused with
nothing written, below-zero refused, and completion idempotent.

The new frontend tests pin **both** envelope shapes — sessions nested, assignments flat — so a
future attempt to make them uniform cannot silently break whichever one it did not test.

## 10. Regression

`GroupTripLoadingIntegrationTest | DistributionGroupTripTest | GroupTripReconciliationVisibilityTest`

**PENDING — could not run.** The gate refused:

```
[GATE] busy (an ungated phpunit process is running) — queueing up to 2400s
[GATE] could not establish the advisory lock.
```

The advisory lock on `ecos_dev_test` is held by another agent's connection (156976), which is
running phpunit **ungated**. §G covers exactly this: a suite that cannot run is reported as
BLOCKED/PENDING, never assumed green. It is therefore **not** counted as evidence here.

This does not weaken the evidence for either fix. The Driver correction is proven by
`DriverLoadingCustodyHandoffTest` + `GroupGrainDriverLoadingTest` (**23/23**, run to
completion before the contention began), and the Operator fix is frontend-only and proven by
its own Vitest contract suite. What remains unproven is only that the *wider* Distribution/
Loading suites are unaffected — and this task changed one frontend service method plus one
new test file, so its blast radius there is nil.

Static: PHPStan **No errors** and Pint **PASS** on the three Part B files; ESLint clean on
`loading-os`; `tsc` at the pre-existing **23**-error baseline with none in `loading-os`.

**i18n parity:** `logistics` 2360/2360. `operations` shows three keys present only in Arabic —
`distribution.coverageMap.outliers_few` / `_many` / `_two`. These are **Arabic plural
categories that English does not have**, so that is correct ICU pluralization rather than a
gap; they are also `coverageMap` keys unrelated to this task and were left alone.

## 11. Browser verification

**BROWSER NOT VERIFIED — AUTHENTICATION CONSTRAINT** for both surfaces.

The dev stack is up (Vite on 5173; `ecos-dev-nginx` healthy but 8081 not reachable from the
host shell). The app redirects to `/app/login` with no stored token. Verifying either screen
requires credentials, and §29/§30 forbid mutating live data to construct scenarios.

Consequently **this task is not claimed VERIFIED**, exactly as §G requires.

## 12. Files changed

| File | Change | By |
|---|---|---|
| `loading-os-service.ts` | `listSessions` reads the paginated envelope | **this task** |
| `loading-os-service.test.ts` | **New** — 5 contract tests | **this task** |
| `LoadProductAction.php` | signed delta routed to the matching custody method | pre-existing in tree |
| `VehicleInventoryService.php` | `recordLoadCorrection()` | pre-existing in tree |
| `DriverLoadingController.php` | `QueryException` caught and rethrown before the business catch | pre-existing in tree |

## 13. Migrations

**None.** No migration was required or created.

## 14. Data safety

Read-only verification. Live state after all work:

| Fact | Value |
|---|---|
| `loading_sessions` | **0** |
| `loading_tasks` | **0** |
| `vehicle_inventory_items` | **0** |
| `vehicle_inventory_movements` | **0** |
| orders | 19 |
| trips | 2 |
| **ORD-00007** | unmodified — `in_progress` |

No Loading session, inventory movement, vehicle stock, transfer, delivery, waste, liability
or financial entry was created against live data.

## 15. Concurrent-agent conflicts

This matters for how the evidence should be read.

**Part B was fixed by another agent while this task was auditing it.** Two turns before this
report I ran the driver suites and saw **5 failures**, including
`test_a_downward_correction_is_reflected_in_custody` returning 422. I attributed those
failures to another agent's in-flight debugging — the file did contain their
`fwrite(STDERR, "=== DEBUG manifest ===")` statements — and my reasoning that *my own*
TASK-1-C guard was inert there was correct (the fixture never finalizes, so the guard returns
early).

But "not mine" is not "not real": that 422 was this task's P1 bug. Re-running the same suites
after their fix landed gives **23/23** — 4 more tests than before, all green. The earlier
5-failure reading is superseded and should not be cited as current state.

Other files in the same module (`Trip.php`, `PaymentCollection.php`, `SettlementService.php`)
also show as modified by work outside this task.

**The same concurrency blocked the regression run** (§10): another agent holds the
`ecos_dev_test` advisory lock with an ungated phpunit process, so the gate could not acquire
it. Reported as PENDING rather than retried into the same contention or assumed green.

## 16. Remaining gaps

1. **Browser verification of both surfaces** (§11) — needs an authenticated session.
2. **`LoadingSessionController::index()` uses `ilike`** (line 48) for the `search` filter.
   `ilike` is PostgreSQL; this deployment is MySQL 8.4, so a search query would fail. Not
   triggered by the workspace (which sends only `per_page`), and out of scope here per the
   STOP rule — recorded rather than fixed.
3. **§25.5/25.6 on the driver path** — "READY trip can load" / "BLOCKED trip cannot" are not
   covered by a driver-specific test; readiness is covered at the operator boundary by the
   TASK-1-C suite.
4. **Driver Loading lives at `features/operations/driver-mobile/`** while the brief places the
   Driver Experience under Shipping/Logistics. Relocation belongs to a separate task; flagged,
   not moved.

---

## Final status

**IMPLEMENTED / VERIFICATION BLOCKED**

Both defects are fixed and evidenced: the Operator workspace no longer receives a paginator
where it expects an array, and a downward driver correction now writes an auditable
positive-magnitude correction movement instead of violating the ledger's CHECK constraint —
with raw SQL no longer reachable by a mobile client.

Neither screen has been opened in a browser, so per §G neither is claimed VERIFIED.

Nothing committed, nothing deployed. No new Loading architecture, no second custody engine,
no new status, no migration.
