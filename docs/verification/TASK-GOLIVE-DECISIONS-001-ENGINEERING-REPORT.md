# TASK-GOLIVE-DECISIONS-001 — Engineering Report
## Go-Live Decision Closure

**Date:** 2026-08-08
**Type:** Evidence preparation and engineering diagnosis. No Phase 3 implementation. No code changed.
**Inputs:** [Decision Register](EPIC-ENTERPRISE-GOLIVE-001-PHASE2.5-DECISION-REGISTER.md) ·
[Certification](ECOS-ERP-ENTERPRISE-CERTIFICATION-FINAL.md) ·
[Phase 1.5](EPIC-ENTERPRISE-GOLIVE-001-PHASE1.5-SERVER-ENFORCEMENT.md) ·
[Phase 2 Design](EPIC-ENTERPRISE-GOLIVE-001-PHASE2-DESIGN.md)

---

# HEADLINE

Two findings from this task change the go-live picture materially.

| # | Finding | Effect |
| --- | --- | --- |
| **1** | **RC-6 root cause is PROVEN.** Warehouse **writes** take `company_id` from the client payload; **every read** filters by `Auth::user()->company_id`. Two independent filters, one source, never reconciled. | RC-6 moves from `Unknown` effort to **XS**, and the same file explains part of RC-1. |
| **2** | **RC-10 is substantially overstated.** All 15 dedicated routes bypass the broken vocabulary table and **do** enforce their contracts through `FulfillmentEngine::run()` → `workflow->guard()`. `MoveToPreparationWorkflow` already enforces the reservation rule RC-10 says exists nowhere. | **PD-1's blocking scope shrinks to almost nothing.** The enforcement layer is built and correct. |

**Neither finding is a licence to start Phase 3.** Three decisions remain unmade by their owners, and one new engineering defect was discovered.

---
---

# 1 — OD-2 DECISION BRIEF
## Pilot vs Multi-Tenant Launch

**Prepared from existing certification evidence only. The decision itself is not made here.**

## 1.1 What changes under a Pilot (single company)

| Dimension | Effect |
| --- | --- |
| **Blocking findings** | Reduces from 4 blockers to **3** — RC-9, RC-10, RC-6 |
| **RC-1 (tenant leak)** | **Not exploitable.** Certification recorded it as *"confirmed in 4 of 4 modules where data existed to leak"* — with one company, no other company's data exists to serve |
| **RC-2 (governance)** | Not exploitable for the same reason — one tenant cannot corrupt another's reference data |
| **GD-1 / GD-2 / GD-4** | Move off the go-live path; become the gate to onboarding tenant #2 |
| **Effort removed from critical path** | RC-1 = `M`, RC-2 = `XS`–`L`, plus GD-4 = `XS` |
| **Commercial model** | Deferred, not abandoned |

## 1.2 What changes under Multi-Tenant

| Dimension | Effect |
| --- | --- |
| **Blocking findings** | All 4 blockers stay, plus RC-2 becomes a hard prerequisite of RC-1 |
| **RC-1** | Must be enforced server-side, failing closed, across **every** endpoint — not only the four where leakage was demonstrated |
| **RC-2** | Certification states RC-1's fix **depends** on it: strict isolation would break the apparent group-buyer capability (`All companies` browsing on Purchases and Recipes) |
| **GD-4 (exports)** | Becomes urgent — unaudited CSV export on ~11 grids converts RC-1 from exposure to exfiltration |
| **Legal exposure** | Certification: *"a reportable disclosure event and a probable contractual breach"* |
| **Verification cost** | Every module needs multi-company re-testing, not just the four audited |

## 1.3 Go-Live blockers under each option

| Finding | Pilot | Multi-Tenant |
| --- | --- | --- |
| RC-9 — state computed independently of source | **BLOCKER** | **BLOCKER** |
| RC-10 — orchestration without enforcement | **BLOCKER** *(reduced — see §3)* | **BLOCKER** *(reduced)* |
| RC-6 — created record invisible | **BLOCKER** | **BLOCKER** |
| RC-1 — tenant scope not applied | **Not exploitable** | **BLOCKER** |
| RC-2 — no governance model | **Not exploitable** | **BLOCKER** (prerequisite of RC-1) |
| RC-11 — unexercised integrations | HIGH | HIGH |
| RC-4 — currency completeness | HIGH | HIGH |

## 1.4 Tenant-2 onboarding gates under Pilot

If Pilot is selected, these become a **hard, enforced gate** before any second company is created:

1. **GD-1** — tenant scope contract signed; every entity classified GLOBAL / SHARED / COMPANY SCOPED
2. **RC-1** — server-side scoping enforced, failing closed, verified at request level
3. **GD-2** — write authority over shared/global data decided and enforced
4. **GD-4** — export permission + audit in place
5. **RC-6 fix verified under two companies** — this defect is itself a company-scoping defect (§2) and will resurface at tenant #2 in other modules if fixed only locally

> **The gate must be technically enforced, not procedural.** RC-1 is invisible on a single-company system; nothing in the platform today would stop a second company being created, and nothing would signal that isolation was never certified.

## 1.5 Operational risks

| Option | Risks |
| --- | --- |
| **Pilot** | • Tenant-2 gate erodes under commercial pressure — the single largest risk<br>• Isolation defects stay latent and untested; the fix lands later against more code<br>• Multi-tenant revenue deferred<br>• Single customer carries all early operational risk |
| **Multi-Tenant** | • Longest path to production; no revenue and no production feedback in the interim<br>• RC-1 remediation must be exhaustive — the certification confirmed leakage in 4 of 4 modules **where data existed**, which is a sampling result, not a bound<br>• RC-2 must be decided first, and its Categories branch is the register's only `L`-effort governance item<br>• Launching before both are complete exposes the disclosure risk on day one |

## 1.6 Engineering recommendation

**Recommend: Pilot (single company), with a technically enforced tenant-2 gate.**

Based only on existing evidence:

- It removes **two of four** go-live blockers by construction, not by fixing them
- The three remaining blockers are all now tractable: RC-9 is designed (Phase 2 Steps 1–3), RC-10 is far smaller than certified (§3), and RC-6 has a proven root cause (§2)
- The isolation programme is not skipped — it is resequenced behind a gate, and its scope is better understood once RC-6's scoping fix establishes the pattern

**Reservation on this recommendation:** the pilot's safety depends entirely on the tenant-2 gate holding. If the organisation cannot commit to enforcing it technically, Pilot is not materially safer than Multi-Tenant — it is Multi-Tenant with the risk hidden.

> ### DECISION REQUIRED — OD-2
> **Owner: Executive.** Not made in this task. Recorded in the register as awaiting signature.

---
---

# 2 — RC-6 ROOT CAUSE REPORT
## Warehouse created (HTTP 201) then denied to exist

# ROOT CAUSE PROVEN

*(from the codebase; the runtime database confirmation was not obtainable — see §2.9)*

## 2.1 Root cause

**The write path and every read path use different sources for `company_id`, and nothing reconciles them.**

| Path | Source of `company_id` | Evidence |
| --- | --- | --- |
| **CREATE** | **The client request payload** | `StoreWarehouseRequest:28` — `'company_id' => ['required','uuid','exists:companies,id']`. The form has a **`CompanySelect` dropdown** (`warehouse-form.tsx:16–21`), so the user chooses it. |
| **LIST** | **The authenticated user's own company** | `WarehouseController:34` — `'company_id' => (string) (Auth::user()?->company_id ?? '')` |
| **LIST (second filter)** | **The authenticated user's own company, again** | `Warehouse::booted()` global scope `tenant`, `Warehouse.php:60–69` |
| **SHOW** | **The authenticated user's own company** | Same global scope, applied to `findById()` |

**If the company selected in the form ≠ the authenticated user's `company_id`, the record is written correctly and is then invisible to every read path, permanently.**

This is not a cache, transaction, soft-delete or consistency problem. **The row exists and is correct. The reads simply never ask for it.**

## 2.2 Reproduction

| Step | Action |
| --- | --- |
| 1 | Authenticate as a user whose `users.company_id` = Company **A** |
| 2 | Open Warehouses → Create |
| 3 | In the **Company** dropdown, select Company **B** (any company the user can see — e.g. one just created during onboarding) |
| 4 | Submit |
| 5 | `POST /api/warehouses` → **201 Created**, response body contains the new warehouse |
| 6 | Grid refresh, page reload, re-navigation → **the warehouse never appears** |
| 7 | `GET /api/warehouses/{id}` → **404** |

Step 3 is the trigger, and it is the **natural** path during onboarding: the certification's Campaign 1 scenario was creating a new company and then its first warehouse.

## 2.3 Exact request

```
POST /api/warehouses
Authorization: Bearer <session token>          → Auth::user()->company_id = A
Content-Type: application/json

{ "company_id": "<B>", "name": "…", "city": "…", "is_active": true }
```

Route: `api.php:383` — `Route::apiResource('warehouses', WarehouseController::class)`
Middleware: `auth:sanctum`, `throttle:120,1`, `permission:inventory.warehouses.create` (store only)

## 2.4 Exact response

`201 Created` — `WarehouseController::store()` → `$this->created(new WarehouseResource($result->data()), …)`.
The resource is built from the **returned model instance**, not from a re-query, so the response is correct and the record is real. **Nothing in the response hints at the problem.**

## 2.5 Created record identity

`Warehouse` — UUID PK (`HasUuids`), `company_id = B` (the payload value), auto-generated `code` via
`WarehouseCodeGeneratorService::next($dto->company_id)`, `deleted_at = NULL`.

`company_id` is in `$fillable` (`Warehouse.php:38–46`) and is passed through
`WarehouseDTO::fromArray()` → `CreateWarehouseAction` → `EloquentWarehouseRepository::create()` →
`Warehouse::query()->create($attributes)` **unmodified**. There is no observer, no `creating` hook, and no mutator — verified by search.

## 2.6 Subsequent failing read

**List** — `WarehouseController::index()` builds `company_id` from `Auth::user()->company_id` (= **A**), passes it to `EloquentWarehouseRepository::paginate()`, which applies `where('company_id', A)` (`:24–27`). The `tenant` global scope applies the **same** predicate a second time. Record has `company_id = B` → **not returned**.

**Show** — `findById()` → `Warehouse::query()->find($id)` with the global scope active → `null` → `GetWarehouseAction` throws `WarehouseNotFoundException` → **404**.

This is the "denied" half of the symptom: the platform does not merely omit the record, it **actively asserts it does not exist**.

## 2.7 Hypotheses tested

| Hypothesis | Verdict | Evidence |
| --- | --- | --- |
| **Company scoping** | ✅ **CONFIRMED — this is the cause** | Write uses payload; reads use `Auth::user()->company_id`, in two independent places |
| Branch scoping | ❌ Excluded | `branch_id` was removed from warehouses by migration `2026_07_05_160000_remove_branch_id_from_warehouses_table` |
| Authorization | ❌ Excluded | `permission:inventory.warehouses.create` is enforced and **passed** — the 201 proves it. `index` carries no permission middleware at all. |
| Transaction commit/rollback | ❌ Excluded | No transaction is opened anywhere in the create path; a single `create()` autocommits. A rollback could not return 201 with a populated resource. |
| Repository write/read mismatch | ✅ **CONFIRMED — the mechanism** | Same repository, but `create()` takes attributes verbatim while `paginate()`/`findById()` are filtered by caller-supplied and scope-supplied company |
| Cache invalidation | ❌ Excluded | No cache layer exists in this module — no `Cache::` usage in the warehouse path |
| Query filters | ✅ Contributing | `paginate()` filter **plus** the model global scope — two filters, one source |
| Soft deletes | ❌ Excluded | `SoftDeletes` is present but `deleted_at` is never set on create; no delete occurred |
| Warehouse ownership | ✅ **CONFIRMED** | Ownership is assigned from user input and validated only for existence (`exists:companies,id`) — never against the actor's own company |
| Read-after-write consistency | ❌ Excluded | Single database, no replica configuration in the connection path; failure persists indefinitely across reloads, which rules out replication lag |

## 2.8 Two further defects found in the same file

| # | Defect | Evidence |
| --- | --- | --- |
| **a** | **The grid's Company filter is inert.** The frontend sends `company_id` as a query parameter (`warehouses-page.tsx:64`), and `WarehousesQuery` types it — but `WarehouseController::index()` **overwrites it** with the authenticated user's company (`:34`). The user's filter selection is silently discarded. | A user filtering by company changes nothing and is given no indication. |
| **b** | **RC-1 leak vector, same file.** If `Auth::user()->company_id` is `NULL`, the global scope returns early (`Warehouse.php:65–67`, commented *"super-admin sees all warehouses"*) **and** the repository filter is skipped because the string is empty (`:25`). Both guards fail open simultaneously → **every warehouse of every company is returned.** | This is a concrete instance of RC-1, and matches the certification's observation that `/api/warehouses` behaves inconsistently on one page. |

**Defect (b) means RC-6 and part of RC-1 share one file and one design flaw:** company identity is taken from the wrong place on write, and from a nullable place on read.

## 2.9 Database evidence — NOT OBTAINED

**Direct database confirmation was not obtained.** Reading the application's database credentials was blocked by the environment's permission classifier, and I did not attempt to work around it.

| | |
| --- | --- |
| **What is missing** | A read-only `SELECT id, company_id, deleted_at FROM warehouses` alongside `SELECT id, company_id FROM users`, to show the created row exists with a `company_id` differing from the reporting user's |
| **What it would add** | Confirmation of the *specific instance* observed in Campaign 1 |
| **What it would not change** | The root cause. The code path is unambiguous and fully traced; no database state can make a payload-sourced write match an `Auth`-sourced read filter when the two differ. |
| **How to obtain it** | Grant database read access, or reproduce via the UI per §2.2 while capturing the `POST` response body (which contains `company_id`) and the authenticated user's company from `/auth/me` — **no database access required** |

**The root cause is proven from the codebase. The instance evidence is pending and is not required to act.**

## 2.10 Severity

**P0 — confirmed.** It blocks onboarding at the first step, produces silent data divergence, and invites duplicate records. Certification's assessment stands; only the `Unknown` effort rating changes.

## 2.11 Proposed fix *(proposal only — not implemented, not approved)*

The correct fix depends on **GD-1**, so it is stated as options rather than a decision:

| | Approach | Depends on |
| --- | --- | --- |
| **Minimum** | Derive `company_id` on write from the same authority the reads use, instead of accepting it from the payload. Reject a payload company that does not match. | Nothing — safe under either OD-2 option |
| **Correct** | Introduce one company-context resolver used by **both** write and read paths, so a mismatch is structurally impossible. Decide whether the active header company or the user's home company is authoritative. | **GD-1** |
| **Also required** | Make the two read filters fail **closed** when `company_id` is `NULL`, rather than returning all companies. | **GD-1** — "super-admin sees all" may be intended |

**Do not fix defect (a) by simply honouring the client's `company_id` filter** — that would widen the RC-1 leak.

## 2.12 Affected modules

| Module | Status |
| --- | --- |
| **MasterData / Warehouses** | **Confirmed** — the traced path |
| Any module where a create form offers a company selector while reads scope to `Auth::user()->company_id` | **Suspected — not surveyed.** The pattern, not the file, is the defect. |
| Organization / Companies | Related — the certification's currency-less company (RC-4) came from the same onboarding flow |

> **This survey was not performed and is not claimed.** Scoping it is engineering input for GD-1.

## 2.13 Regression risk

| Factor | Assessment |
| --- | --- |
| **Test coverage** | **ZERO.** No warehouse CRUD test exists anywhere in `backend/tests` — the only match for "Warehouse" is `OrderImportWarehouseTest`, unrelated. |
| **Blast radius of the fix** | Any existing warehouse whose `company_id` does not match its creator's becomes visible or invisible depending on the option chosen. **Existing data must be inspected before the fix ships.** |
| **Interaction with RC-1** | Changing the read filters to fail closed will hide records from any user with `company_id = NULL` who currently sees everything. If that is how an administrator operates today, it is a behaviour change. |
| **Recommended precondition** | Write the missing create-then-list test **first**, so the fix is provably verified. |

---
---

# 3 — SD-4 ROUTE ENFORCEMENT MATRIX

## 3.1 Scope and the headline correction

The 15 dedicated routes are `api.php:979–993`, all inside
`Route::middleware(['auth:sanctum','permission:operations.fulfillment.manage'])->prefix('fulfillment')`.

**`OrderFulfillmentController` is an alias for the same `FulfillmentController` Phase 1.5 traced**
(`api.php:221`). All 16 routes — the 15 dedicated plus the generic `/transition` — live in one class.

> ### The correction
>
> **Every dedicated route bypasses `resolveTransitionWorkflow()` entirely.** Each calls
> `$this->engine->run($workflow, $order, …)` directly with a statically injected workflow. The V2/V3
> vocabulary mismatch that kills the generic endpoint **does not touch them.**
>
> And `FulfillmentEngine::run()` (`:43`) calls **`$workflow->guard($ctx)`** before opening the
> transaction. The interface mandates it (`FulfillmentWorkflowInterface:24`). **Every dedicated route
> therefore has a real, domain-layer enforcement point.**
>
> Guard failures throw `WorkflowPreconditionException` → **HTTP 422** with the message
> (`bootstrap/app.php:114–117`).
>
> **All 22 workflows use the V3 `OrderStatus` enum.** The V2 vocabulary exists in exactly one place:
> `resolveTransitionWorkflow()`.

## 3.2 Common properties (all 15)

| Property | Value |
| --- | --- |
| **HTTP method** | `POST` |
| **Permission** | `operations.fulfillment.manage` — group-level, enforced |
| **Company scope** | ✅ Route-model binding on `{order}`; `Order` carries the same `tenant` global scope (`Order.php:116–124`). **Fails open when `company_id` is `NULL`** — same RC-1 vector as §2.8(b) |
| **Branch scope** | Not applicable |
| **Warehouse scope** | Enforced inside `ShipOrderInventoryAction` at dispatch, not at the route |
| **Business rule enforcement** | Domain layer — `workflow->guard()`, outside the transaction |
| **Execution** | `DB::transaction()`, events after commit, `OrderEvent` audit with actor and previous/new status |
| **Tests** | ❌ **ZERO** — see §3.5 |

## 3.3 The matrix

| # | Route | Action → Workflow | Guard enforces | Verdict |
| --- | --- | --- | --- | --- |
| 1 | `/confirm` | `confirm` → `ConfirmOrderWorkflow` | Status ∈ {New, AwaitingPayment, AwaitingStock, OnHold, Returned, Cancelled, InProgress}; resets lifecycle fields when re-confirming a Returned order | **PASS** |
| 2 | `/cancel` | `cancel` → `CancelOrderWorkflow` | Blocks OutForDelivery (*"execute Return Workflow first"*); blocks Cancelled/Delivered/Returned; **requires `force_cancel_preparation` from ReadyForDispatch**; releases reservation | **PASS** |
| 3 | `/move-to-preparation` | `moveToPreparation` → `MoveToPreparationWorkflow` | **Status must be InProgress**; blocks terminal reservation states (Released/Consumed/Transferred, *"H-2 fix: prevents entering dispatch with zero stock"*); **PartialReserved requires prior manager approval**; and in `execute()` **auto-reserves when unreserved, diverting to AwaitingStock if stock is insufficient** | **PASS** |
| 4 | `/complete-delivery` | `completeDelivery` → `CompleteDeliveryWorkflow` | Status must be OutForDelivery **and `inventory_shipped_at !== null`** — cannot deliver what was never dispatched; consumes the reservation | **PASS** |
| 5 | `/complete` | `complete` → `CompleteOrderWorkflow` | Status must be Delivered | **PARTIAL** — §3.4(a) |
| 6 | `/awaiting-stock` | `markAwaitingStock` → `MarkAwaitingStockWorkflow` | Status ∈ {New, AwaitingPayment, InProgress, OnHold, Scheduled, Cancelled} | **PASS** |
| 7 | `/return` | `returnOrder` → `ReturnOrderWorkflow` | Status guard + reason handling; restores inventory | **PASS** |
| 8 | `/reschedule` | `reschedule` → `RescheduleOrderWorkflow` | Blocks Delivered/Cancelled/Scheduled; **requires `next_delivery_date`** | **PASS** |
| 9 | `/resume` | `resume` → `ResumeOrderWorkflow` | Status ∈ {OnHold, AwaitingStock} only | **PASS** |
| 10 | `/review` | `moveToReview` → `MoveToReviewWorkflow` | Blocks OnHold/ReadyForDispatch/OutForDelivery/Delivered/Returned | **PARTIAL** — §3.4(b) |
| 11 | `/dispatch` | `dispatch` → `DispatchOrderWorkflow` | Status must be **ReadyForDispatch**; `ShipOrderInventoryAction` inside the transaction fails on missing warehouse, missing reservation or insufficient reserved qty, rolling the status back atomically | **PASS** |
| 12 | `/return-to-pending` | `returnToPending` → `ReturnToPendingWorkflow` | Status ∈ {AwaitingPayment, InProgress, AwaitingStock, OnHold, Cancelled, Scheduled} → New; releases reservation | **PASS** |
| 13 | `/revert-to-confirmed` | `revertToConfirmed` → `RevertToConfirmedWorkflow` | Status guard present (V3 enum) | **PASS** |
| 14 | `/return-to-processing` | `returnToProcessing` → `ReturnToProcessingWorkflow` | Status guard present (V3 enum) | **PASS** |
| 15 | `/approve-partial-reservation` | `approvePartialReservation` → `ApprovePartialReservationWorkflow` | **`reservation_status` must be PartialReserved**; order status ∈ {InProgress, AwaitingStock, New}; stamps `partial_reservation_approved_at` | **PASS** |
| — | `/transition` *(generic, for contrast)* | `transition` → `resolveTransitionWorkflow()` | **V2 vocabulary only — zero V3 tokens** | **FAIL** — Phase 1.5 |

**13 PASS · 2 PARTIAL · 0 FAIL · 0 UNVERIFIED** across the 15 dedicated routes.

## 3.4 PARTIAL findings

### (a) `/complete` — status transition is a no-op

`CompleteOrderWorkflow::guard()` requires `status === Delivered`; `execute()` then sets
`status = OrderStatus::Delivered` (`:37`) — the state it already had, because **V3 has no `Completed` case**.

- **Why:** the V2 chain ended `delivered → completed`; V3 dropped `Completed` and the workflow was
  re-pointed at `Delivered` rather than removed.
- **Consequence:** `FulfillmentEngine` skips the audit stamps because `$previousStatus === $result->order->status->value` (`:59`), so no `previous_status`, no `status_entered_at`, and `OrderEvent` records `previousValue: null` / `newValue: null`. **The financial-completion metadata (revenue, COGS, margin) is still emitted and its events still fire** — so the route is not inert, but it does not transition anything.
- **Affected workflow:** financial completion / revenue recognition. Directly entangled with **PD-3**.
- **Classification:** **ENGINEERING DEFECT**, minor — but its *resolution* is a product decision (**PD-2**: does `completed` exist?). Not fixed here.

### (b) `/review` — route name does not match its effect

`MoveToReviewWorkflow::execute()` sets `OrderStatus::OnHold` (`:48`), and its own error message reads
*"cannot be placed On Hold"*. The route, the controller method and the workflow class are all named
`review`; **V3 has no `Review` case.**

- **Consequence:** functionally correct — the order is placed on hold. The naming is stale.
- **Risk:** a caller reading the route list would reasonably expect a review state that does not exist.
- **Classification:** **ENGINEERING DEFECT**, cosmetic. Resolution belongs to **PD-2**.

## 3.5 Known gaps

| # | Gap | Severity |
| --- | --- | --- |
| **1** | **Zero test coverage.** Searching `backend/tests` for `FulfillmentEngine`, `MoveToPreparationWorkflow`, `DispatchOrderWorkflow`, `ConfirmOrderWorkflow` and `fulfillment/orders` returns **0 matches across 0 files**. The entire order-lifecycle enforcement layer — 22 workflows, 16 routes, the engine — is untested. | **HIGH** |
| **2** | **Permission granularity.** One permission, `operations.fulfillment.manage`, governs all 16 routes. Cancelling a prepared order and rescheduling one require identical authority. GD-3's override model has no permission to hang on. | Medium |
| **3** | **`Order` tenant scope fails open on `NULL`** — same vector as §2.8(b). A user with `company_id = NULL` can transition any company's orders. | **HIGH** *(only exploitable multi-tenant — see OD-2)* |
| **4** | **Warehouse is enforced only at dispatch**, inside `ShipOrderInventoryAction`. An order can reach `ReadyForDispatch` with no warehouse and fail later. This is a **sequencing** question, not an absence of enforcement. | Medium — **PD-1 Q3** |
| **5** | The 13 **bulk** routes (`api.php:1001–1013`, `BulkFulfillmentController`) were **not surveyed** — outside SD-4's stated scope. They catch `WorkflowPreconditionException` per order, which suggests they run the same guarded workflows, **but this is not verified.** | **UNVERIFIED — flagged** |

## 3.6 What this means for RC-10

| Certification claim | Status after this survey |
| --- | --- |
| *"The state machine exists and the inventory read exists; neither consults the other."* | **False for the dedicated routes.** `MoveToPreparationWorkflow` consults `reservation_status`, blocks terminal states, requires approval for partial reservations, and auto-reserves or diverts to `AwaitingStock`. |
| *"Is stock reserved before Ready for Dispatch? — enforced nowhere"* (Phase 1.5) | **Superseded.** Enforced in `MoveToPreparationWorkflow`. Phase 1.5 explicitly scoped itself to the generic endpoint and flagged these 15 routes as untraced; this survey closes that gap. |
| *"Is a warehouse assigned before dispatch? — enforced nowhere"* | **Partially superseded.** Enforced at `/dispatch`, not at `/move-to-preparation`. Whether that is early enough is **PD-1 Q3**. |
| *"`Mark Ready` is offered as the primary action on an unreserved order"* | **Still true, and still a P0.** The order drawer posts to the **generic** `/transition` endpoint (frontend service line 190), which 422s on the vocabulary mismatch. |

**RC-10's true shape:** the enforcement layer is built, correct and V3-native. **The UI is wired to the one endpoint that does not use it.** That is a routing defect, not a missing-guard defect.

---
---

# 4 — BLOCKING DECISION STATUS

Separated as required. **No decision below has been made on the owner's behalf.**

## 4.1 DECISION REQUIRED — owner action, no engineering blocker

| ID | Decision | Owner | Status | Evidence available | Engineering recommendation | What remains |
| --- | --- | --- | --- | --- | --- | --- |
| **OD-2** | Pilot vs Multi-Tenant | Executive | **OPEN** | §1 brief complete | **Pilot**, with a technically enforced tenant-2 gate | Owner signature. Nothing else blocks it. |
| **PD-1** | Transition preconditions | Business Ops + Sales | **OPEN — scope greatly reduced** | §3 matrix: Q2 (reserved) **already enforced**; Q9 (partial) **already enforced** via manager approval; Q1 (backorder) **already implemented** as divert-to-AwaitingStock | Confirm the **existing** behaviour rather than specify new rules. Only **Q3** (warehouse at ready vs at dispatch) is genuinely open; Q4–Q8 remain deferrable | Owner ratification of current behaviour + an answer on Q3 |
| **PD-2** | Lifecycle vocabulary | Product + Business Ops | **OPEN** | §3.4(a)/(b) give two concrete instances: `completed` and `review` both survive as routes with no enum case | V3 as-is; retire `completed` and `review` | Owner decision. §3.4 is the evidence they need. |
| **PD-5** | Channel stock status | Product + Channel | **OPEN** | Unchanged from the register | Retain, relabel, restrict | **Q1 unanswered** — see §4.2 |
| **GD-1** | Tenant scope contract | Exec + Product + Arch | **OPEN** | §2.8(b) and §3.5(3) add two concrete fail-open instances to the RC-1 evidence | Deny by default, exceptions justified | Owner decision. **Under Pilot this moves to the tenant-2 gate.** |

## 4.2 ENGINEERING INPUT REQUIRED — work to commission, not decisions

| # | Input needed | Blocks | Status |
| --- | --- | --- | --- |
| **E-1** | **SD-4 route survey** | SD-4 | ✅ **COMPLETE — §3** |
| **E-2** | **RC-6 diagnosis** | RC-6 disposition | ✅ **COMPLETE — §2** |
| **E-3** | **Does outbound sync publish `products.stock_status`?** (Phase 2 Q1) | **PD-5**, Phase 2 Steps 2 & 8 | ❌ **NOT DONE** — outside this task's scope |
| **E-4** | **Which other modules share the RC-6 pattern?** (§2.12) | Scope of the RC-6 fix | ❌ **NOT DONE** — newly identified here |
| **E-5** | **Do the 13 bulk routes enforce the same guards?** (§3.5(5)) | Completeness of the enforcement claim | ❌ **NOT DONE** — outside SD-4's stated scope |
| **E-6** | **RC-6 instance confirmation** (§2.9) | Nothing — root cause already proven | ❌ Blocked on DB access; obtainable via UI without it |

## 4.3 ENGINEERING DEFECT — no decision required to acknowledge

| ID | Defect | Severity | Fix approved? |
| --- | --- | --- | --- |
| **D-1** | **RC-6** — write takes payload `company_id`, reads take `Auth::user()->company_id` (§2.1) | **P0** | ❌ Proposal only (§2.11). Correct form depends on **GD-1** |
| **D-2** | Warehouse grid **Company filter is inert** — silently overwritten (§2.8a) | P2 | ❌ Not fixed. Fixing it naively **widens RC-1** |
| **D-3** | Warehouse tenant scope **fails open** when `company_id` is `NULL` (§2.8b) | **P0 multi-tenant** / P3 pilot | ❌ Depends on **GD-1** |
| **D-4** | `Order` tenant scope **fails open** identically (§3.5(3)) | **P0 multi-tenant** / P3 pilot | ❌ Depends on **GD-1** |
| **D-5** | `/complete` performs **no status transition**; audit stamps skipped (§3.4a) | P2 | ❌ Resolution belongs to **PD-2** |
| **D-6** | `/review` sets `OnHold`; name is stale (§3.4b) | P3 | ❌ Resolution belongs to **PD-2** |
| **D-7** | **Zero test coverage** on the fulfillment lifecycle (§3.5(1)) and on warehouse CRUD (§2.13) | **HIGH** | ❌ Not written. Recommended **before** any RC-6 or RC-10 fix |

**Nothing in §4.3 was fixed in this task, per the standing instruction not to fix RC-6 before the root cause is approved, and not to implement Phase 3.**

---
---

# 5 — UPDATED DECISION REGISTER

The register has been updated in place with evidence from this task only:
[`EPIC-ENTERPRISE-GOLIVE-001-PHASE2.5-DECISION-REGISTER.md`](EPIC-ENTERPRISE-GOLIVE-001-PHASE2.5-DECISION-REGISTER.md)

**Changes made — all additive. No owner decision was recorded, changed or implied.**

| Decision | Change |
| --- | --- |
| **OD-2** | Added §1 brief by reference; recommendation recorded as **engineering recommendation, awaiting owner signature** |
| **PD-1** | **Materially reduced.** Q1, Q2 and Q9 annotated as *already enforced* with file evidence; Q3 identified as the only genuinely open question |
| **PD-2** | Two concrete instances added (`/complete`, `/review`) — the decision now has evidence, not just a description |
| **PD-5** | Unchanged; **E-3 still outstanding** |
| **GD-1** | Two fail-open instances added (warehouse and order tenant scopes) |
| **SD-4** | **CLOSED** — matrix complete; verdict recorded |
| **RC-6** | Moved from *"cause Unknown"* to **root cause proven**; effort `Unknown` → **XS**; disposition still requires approval |
| **New** | §4.3 defect list D-1…D-7 appended as an engineering annex |

---
---

# 6 — PHASE 3 READINESS GATE

# ⛔ PHASE 3 MAY NOT BEGIN

| # | Condition | Status |
| --- | --- | --- |
| 1 | **OD-2 has a recorded owner decision, or its effect on Phase 3 scope is explicitly resolved** | ❌ **NOT SATISFIED** — brief prepared (§1), recommendation given, **no owner decision recorded**. Its effect is not resolvable by engineering: it determines whether GD-1/RC-1 are in Phase 3 scope at all. |
| 2 | **RC-6 has a proven root cause and approved disposition** | ⚠️ **HALF SATISFIED** — root cause **proven** (§2). **Disposition NOT approved** — the correct fix form depends on GD-1 (§2.11). |
| 3 | **SD-4 has a completed 15-route evidence matrix** | ✅ **SATISFIED** — §3. 13 PASS, 2 PARTIAL, 0 FAIL, 0 UNVERIFIED. |
| 4 | **PD-1, PD-2, PD-5, GD-1 decided or explicitly reclassified as non-blocking** | ❌ **NOT SATISFIED** — all four remain open. PD-1's scope is much smaller and GD-1 may move off the path, but **neither reclassification is engineering's to make.** |

## Exact remaining blockers

| # | Blocker | Owner | Unblocked by |
| --- | --- | --- | --- |
| **B-1** | **OD-2 unsigned** | Executive | A signature. All supporting evidence is prepared. |
| **B-2** | **RC-6 disposition unapproved** | Exec + Architecture (with GD-1) | Choosing between the three options in §2.11 |
| **B-3** | **PD-1 unratified** | Business Ops + Sales | Ratifying existing enforcement + answering Q3 (warehouse at ready, or at dispatch?) |
| **B-4** | **PD-2 undecided** | Product + Business Ops | Deciding `completed`, `review`, `preparing`, and the `confirmed`/`processing` merge |
| **B-5** | **PD-5 undecided and E-3 unanswered** | Product + Channel | Commissioning E-3, then deciding |
| **B-6** | **GD-1 undecided** | Exec + Product + Arch | Signing the classification — **or** OD-2 = Pilot moving it to the tenant-2 gate |

**B-1 is the keystone.** Signing OD-2 as *Pilot* resolves B-6 by resequencing and reduces Phase 3's opening scope to RC-9, RC-10 and RC-6.

## What may proceed now, without any decision

| Work | Why it is safe |
| --- | --- |
| **E-3, E-4, E-5** — the three outstanding surveys | Read-only investigation; no decision presupposed |
| **D-7** — write the missing tests for warehouse CRUD and the fulfillment lifecycle | Characterisation tests capture behaviour **as it is today**; they presuppose no decision and are the precondition for safely changing either area |

**Neither is Phase 3 implementation.** Both are recommended, and neither is started here.

---
---

# 7 — EXACT OWNER DECISIONS REQUIRED

**Seven signatures. Nothing else blocks Phase 3.**

### ① OD-2 — Launch model · **Executive** · *take this one first*

> ☐ **A — Single-company pilot**, with a technically enforced gate before tenant #2 *(engineering recommendation)*
> ☐ **B — Multi-tenant launch** — accepts RC-1, RC-2, GD-1, GD-2, GD-4 as go-live blockers
> ☐ **C — Delay all launch**

### ② RC-6 disposition — **Executive + Architecture**

> ☐ **Minimum** — derive `company_id` on write from the same authority the reads use; reject mismatches
> ☐ **Correct** — one company-context resolver shared by write and read paths *(requires GD-1)*
> ☐ **Also fail the read filters closed on `NULL` company** — Yes / No *(requires GD-1)*

### ③ PD-1 — Transition preconditions — **Business Operations + Sales**

> ☐ **Ratify existing enforcement** — reservation required, partial reservations need manager approval, insufficient stock diverts to Awaiting Stock *(engineering recommendation — this is what the platform already does)*
> ☐ **Q3 — warehouse assignment:** required at **Ready for Dispatch** ☐ or at **Dispatch**, as today ☐
> ☐ Q4–Q8 (preparation, payment, driver, vehicle, POD): **defer to post-go-live** ☐ / specify now ☐

### ④ PD-2 — Lifecycle vocabulary — **Product + Business Operations**

> ☐ **V3 as-is**; retire `completed` and `review`; treat `preparing` as an Operations wave state; accept the `confirmed`/`processing` merge *(engineering recommendation)*
> ☐ Reintroduce missing states ☐ Other

### ⑤ PD-5 — Channel stock status — **Product + Channel owner**

> ☐ **Commission E-3 first** *(engineering recommendation — the decision is unsafe without it)*
> ☐ Retain, relabel, restrict ☐ Delete the field ☐ Leave as is

### ⑥ GD-1 — Tenant scope contract — **Executive + Product + Architecture**

> ☐ **Deny by default**, exceptions individually justified behind named permissions *(engineering recommendation)*
> ☐ Preserve current behaviour, scope selectively
> ☐ **Defer to the tenant-2 gate** — available only if OD-2 = Pilot

### ⑦ Test-first precondition — **Engineering leadership**

> ☐ **Approve writing characterisation tests for warehouse CRUD and the fulfillment lifecycle before any fix lands** *(engineering recommendation — both areas currently have zero coverage)*
> ☐ Proceed without

---

## Closing statement

**Phase 3 was not started. No code was modified. No schema, API, workflow, permission or seed data was
touched. No database was written to. No decision was made on the owner's behalf.**

**Two claims in this report revise earlier certified findings** — RC-6's cause (previously `Unknown`)
and RC-10's reach (previously *"enforced nowhere"*). Both revisions are supported by cited file and
line evidence, and both are stated in the direction that **reduces** the apparent defect count, so
they should be independently checked before being relied upon.

**One limitation is recorded rather than worked around:** RC-6's database instance evidence was not
obtained because credential access was denied. The root cause does not depend on it (§2.9).
