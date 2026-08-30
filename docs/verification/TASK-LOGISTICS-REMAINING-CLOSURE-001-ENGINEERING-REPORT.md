# TASK-LOGISTICS-REMAINING-CLOSURE-001 — ENGINEERING REPORT

# FINAL STATUS: **PARTIAL**

**T-01 (Network Service Area tenant isolation) — IMPLEMENTATION COMPLETE and locally proven.**
**T-02 / T-04 / T-05 / T-06 / T-09 / T-10 — audited; all BLOCKED on missing authoritative contracts. Nothing invented.**

*Final certification is deliberately deferred to the unified project certification phase. This report does not certify.*

| | |
|---|---|
| Task | TASK-LOGISTICS-REMAINING-CLOSURE-001 |
| Date | 2026-08-18 |
| Branch | `develop` |
| Production files changed | **1** — `Modules/Logistics/Network/Domain/Models/ServiceArea.php` (+44 / −0) |
| Migrations added | **0** |
| Permissions changed | **0** |
| `ecos_erp` touched | **No** |
| Concurrent work touched | **No** |
| Committed | **No** — see §20.1 |

---

## 1. Previous Certified Release

`TASK-LOGISTICS-PERMISSIONS-RELEASE-CLOSURE-001` — **CERTIFIED**, preserved unchanged by this task.

## 2. Certified Commit

| | |
|---|---|
| Release commit | **`2aefe0fb`** — `fix(logistics): restore certified two-segment permissions` |
| Follow-on docs commit | `721800c6` — release closure report |
| Both still HEAD-most | ✅ `git log` shows `721800c6`, `2aefe0fb` — neither amended, reverted or duplicated |

**Certified state re-verified at the end of this task — unchanged:**

```
perms=595  two_part=17  admin=413  viewer=90
```

No permission was added, removed or re-granted. `finance.admin` and `routing.manage` remain unrestored. Commit `2aefe0fb` was not touched.

## 3. Current Worktree State

Recorded before any change (Workstream D):

| Item | At task start | At task end |
|---|---|---|
| Branch / HEAD | `develop` @ `721800c6` | unchanged |
| Staged | **1 entry** — `D frontend/src/features/orders/components/order-reservation-cell.tsx` | **identical, untouched** |
| Modified tracked | 204 | 205 (+1 = mine) |
| Untracked | 251 | 252 (+1 = this report) |

Ownership determined per file: the only path this task modified is `ServiceArea.php`. Everything else — including newly-appeared `Modules/Logistics/Distribution/**` files (`DistributionWindow`, `VirtualCapacitySlot`, `DistributionWindowController`, four migrations) belonging to a concurrent session — was left alone.

---

## 4. T-01 Investigation

**Defect (pre-existing, carried from the release closure report §11):**

`NetworkController::area()` resolved a service area by `ServiceArea::where('uuid', $id)->firstOrFail()` with no company predicate. A Company A admin holding `network.view` read a Company B service area over HTTP and received **200**.

Confirmed origin: commit `90ea0082 feat(logistics): Phase 2 — Network, Dispatch, Routing, Carrier foundation`. The file was **not** modified by the permissions release; the release only made the path reachable by restoring `network.view`.

**Surface reached through `area()`** — `show`, `attachMember`, `detachMember`, `setStatus`, `capacityPlans`. All inherited the same hole.

## 5. Existing Tenant Authority

Identified before writing any code (A-1). **No second mechanism was invented.**

| Component | Role |
|---|---|
| `App\Core\Company\TenantOwnershipResolver` | *"The single server-side authority for tenant (company) ownership… Ownership is resolved here and nowhere else."* Introduced by TASK-GOLIVE-RC6-REPAIR-001. |
| `App\Core\Company\CurrentCompanyService` | resolves the actor's `company_id` |
| Enforcement pattern | an Eloquent **global scope named `tenant`** that consults the resolver |
| Canonical semantics | foreign row becomes **invisible** → `firstOrFail()` → existing not-found exception → **HTTP 404** |

The pattern's own docblock on `GoodsReceipt` names the models already using it and states the contract:

> *"Verbatim the scope the four already-scoped models use (Order, Warehouse, Supplier, ShippingPricingRule) — a foreign row becomes invisible, the repository lookup returns null, and the existing not-found exception produces the 404 the certified ECOS tenant contract expects. **No new tenant mechanism is introduced.**"*

Models already carrying it: `Order`, `Warehouse`, `Supplier`, `ShippingPricingRule`, `GoodsReceipt`, `SupplierInvoice`. `ServiceArea` now joins them as the seventh.

**Canonical cross-company response = 404, not 403.** That answers A-3 #7 directly: not-found semantics are required, so the response must not reveal whether the foreign UUID exists.

## 6. T-01 Implementation

One file, one addition. The scope body is **copied verbatim** from the certified `GoodsReceipt` implementation.

`Modules/Logistics/Network/Domain/Models/ServiceArea.php` — `+44 / −0`:

- added `addGlobalScope('tenant', …)` inside the **existing** `booted()`, consulting `TenantOwnershipResolver`;
- **preserved** the pre-existing `creating` hook that generates the UUID;
- early-returns when `! appliesTo()` (console, queue, seeders, migrations run with no actor);
- early-returns when `isUnrestricted()` (is_system role);
- `whereRaw('1 = 0')` when the actor has a null company — a null company closes the query rather than removing the filter (RC-6 invariant);
- otherwise `where('company_id', $companyId)`.

### 6.1 What was deliberately NOT changed (A-2, A-4)

| Item | Status |
|---|---|
| `NetworkController` | **untouched** — no endpoint redesigned, no resolver rewritten |
| Response shapes / resources | unchanged |
| `index()` company filter | unchanged (already auth-derived; now double-filtered with the identical value — a no-op) |
| `store()` company assignment | unchanged (already auth-derived, never client input) |
| Permissions | **none added, removed or changed** — this is not a permission task |
| Other modules | none touched |

### 6.2 `CapacityCommitment` — same defect class, deliberately NOT fixed (STOP-12)

`NetworkController::commitment()` has the identical bare-UUID pattern (`CapacityCommitment::where('uuid',$id)->firstOrFail()`), reached by `commitReservation` and `releaseReservation`.

It was **not** scoped, because doing so would change business behaviour with no authoritative contract:

- `CapacityLedgerService::sweepExpired()` queries `CapacityCommitment::query()->where('status','reserved')` **globally**;
- it is invoked from two authenticated HTTP paths — `NetworkController::sweepExpired()` and `Operations\CapacityReservationService::reconcile()` — and from **no scheduler entry**;
- adding the scope would silently convert a **global** expired-hold sweep into a **per-company** one, potentially leaving other companies' expired holds un-reclaimed and their capacity permanently consumed.

Whether a capacity sweep is global or per-tenant is a business rule. None exists. Per **STOP condition 12** ("a new business rule would need to be invented") this workstream item stopped rather than working around it.

Assessed and ruled out as a risk for `ServiceArea`: capacity is tracked by **counters on the slot** (`committed_orders`, `available_orders`, …) via `remainingFor()`, **not** by aggregating commitment rows — so scoping `ServiceArea` cannot distort capacity accounting.

**Recorded as a blocker — see §26 B-1.**

---

## 7. Same-Company Proof

Live HTTP against `http://127.0.0.1:8081` → `ecos-dev-nginx` → `ecos-dev-app` (DB `ecos_dev`). Two real companies; every fixture created through the API, never by direct row mutation.

| # | Check | Result | Expected |
|---|---|---|---|
| A3-1 | Admin A reads **own** area by uuid | **200** | 200 ✅ |
| A3-3 | Admin B reads **own** area by uuid | **200** | 200 ✅ |
| A3-2w′ | Admin A `PATCH …/status` on **own** area | **422 — domain message**: *"A service area cannot be activated with no members…"* | reachable ✅ |
| A3-2m′ | Admin A `POST …/members` on **own** area (valid payload) | **200** | 200 ✅ |

A3-2w′ is the strongest same-company evidence: a **domain** rejection proves the area was resolved and business logic executed — the scope did not hide the row from its owner.

## 8. Cross-Company Proof

| # | Check | Before fix | After fix | Expected |
|---|---|---|---|---|
| A3-2 | Admin A reads **Company B** area by uuid | **200** 🔴 | **404** | 404 ✅ |
| A3-6b | Viewer (A) reads Company B area | — | **404** | 404 ✅ |
| A3-2w | Admin A `PATCH …/status` on Company B area | — | **404** | 404 ✅ |
| A3-2m | Admin A `POST …/members` on Company B area (valid payload) | — | **404** | 404 ✅ |

Both the read path and the two write paths are closed.

### 8.1 No information leakage (A-3 #7)

Response bodies for a **foreign** uuid and a **non-existent** uuid are byte-identical:

```
foreign      : {"message":"No query results for model [Modules\\Logistics\\Network\\Domain\\Models\\ServiceArea]."}
non-existent : {"message":"No query results for model [Modules\\Logistics\\Network\\Domain\\Models\\ServiceArea]."}
```

A cross-company request cannot distinguish "exists but not yours" from "does not exist". This satisfies the canonical not-found contract.

## 9. Spoofed `company_id` Proof

| # | Check | Result | Expected |
|---|---|---|---|
| A3-4 | Admin A lists with `?company_id=<B>` → Company B rows | **0 matches** | 0 ✅ |
| A3-4b | Admin A `GET …/{B uuid}?company_id=<B>` | **404** | 404 ✅ |

The authoritative company context comes from the authenticated actor. A client-supplied `company_id` cannot widen it — the global scope applies regardless of query string, and the controller's own filter is auth-derived.

## 10. Authorization Regression

Permission behaviour is unchanged — the certified contract holds.

| # | Check | Result | Expected |
|---|---|---|---|
| A3-5 | Role-less user reads an own-company area | **403** | 403 ✅ |
| A3-6 | Viewer (A) reads own-company area (`network.view`) | **200** | 200 ✅ |
| A3-6c | Viewer (A) `POST` create (needs `network.manage`) | **403** | 403 ✅ |

Permission denial (403) and tenant invisibility (404) remain distinct and correctly layered: permission is evaluated first, tenancy second.

---

## 11–16. Roadmap Audits (Workstream B)

Each item was audited against the **current** repository — not assumed from the prior report. Route table re-dumped (1,856 routes), models and services re-read, ADRs and design docs searched.

### The governing finding

`docs/logistics-v2/README.md:3-5` states, for the entire V2 document set (16 documents incl. state machines, driver mobile, fleet, API architecture):

> **Status:** Architecture & Design — **awaiting CTO Architecture Review**
> **Authorization: Design only. No implementation, no migrations, no code.**

`docs/adr/` contains **17** ADRs; **none** covers Distribution/Loading convergence, shipping state-machine unification, delivery-return restock, vehicle reconciliation, or a shipping→finance bridge. Adjacent ADRs (ADR-027 reservation ownership, ADR-042 order FSM, ADR-022 allocation) **constrain** these items but do not specify them.

Therefore every remaining roadmap item is **BLOCKED on an approved contract**, and per B-1 nothing was implemented.

### 11. T-02 — Driver / Vehicle / Carrier tenancy

| Field | Finding |
|---|---|
| **Status** | **NOT STARTED** — implementation absent |
| **Gate** | **BLOCKED** — business decision required |
| Evidence | `logistics_drivers.company_id` column count = **0** (cannot be tenant-isolated at schema level). Tenant scope present on `Driver` / `Vehicle` / `ShippingCompany` models = **0 / 0 / 0**. `DriverController` retains 10 bare `findOrFail`; `ShippingCompanyController` 9. |
| Contract gap | Are drivers **company-owned**, **shipping-company-owned**, or **shared** across companies? Determines whether a `company_id` migration is even correct. No ADR or spec answers this. |
| Mechanism | **Available** — the canonical scope proven by T-01 applies directly once ownership is decided. |
| Owner / dependency | Business owner (ownership model) → then Logistics. Independent of T-04/05. |

### 12. T-04 — Distribution / Loading / Dispatch-Gate convergence

| Field | Finding |
|---|---|
| **Status** | **NOT STARTED** |
| **Gate** | **BLOCKED** — architecture decision required |
| Evidence | `api/distribution/*` = **0 routes**; `api/driver/*` = **0 routes**; `api/loading/*` = **24 routes** with still **zero** frontend callers. Unchanged from the full-stack audit. |
| Contract gap | Three-way choice (build `/api/distribution/*` as a facade · rewrite the 16 pages onto existing APIs · retire the pages and surface the working `TripsWorkspacePage`). This is the pivotal decision; T-05, T-07 and T-09 cannot be scoped until it is made. |
| Note | A concurrent session is actively adding `Modules/Logistics/Distribution/**` files (DistributionWindow, VirtualCapacitySlot, four migrations). **Not this task's work**; it may change T-04's shape and should be re-audited before T-04 is scoped. |
| Owner | Architecture / CTO — ADR required before code. |

### 13. T-05 — Shipping state-machine unification

| Field | Finding |
|---|---|
| **Status** | **NOT STARTED** |
| **Gate** | **BLOCKED** — no approved contract |
| Evidence | Listeners for `TripDispatched::class` = **0**; `DeliveryStopCompleted::class` = **0**. References to `ShipOrderInventoryAction` inside `Modules/Logistics` = **0**. Files in `Modules/Logistics` referencing any inventory model / stock ledger / restock = **0**. |
| Meaning | Dispatch in the reachable stack still moves no stock; completing a delivery still changes no order status. Unchanged. |
| Contract gap | `docs/logistics-v2/09-STATE-MACHINES.md` exists but is design-only and unapproved. Constrained by ADR-027 and ADR-042. |
| Owner | Architecture; depends on T-04. |

### 14. T-06 — Delivery return restock

| Field | Finding |
|---|---|
| **Status** | **NOT STARTED** |
| **Gate** | **BLOCKED** — business decision required |
| Evidence | Restock/stock references in `DeliveryReturnService` + Distribution `DeliveryService` = **0**. `ReturnReceived::class` listeners = **0**. |
| Contract gap | Restock on **receipt** or on **verification**? Who owns damaged-vs-sellable disposition? Two parallel return implementations still exist with no cross-check. |
| Owner | Business owner → Inventory + Logistics (cross-module; STOP-6 applies — would require changing Inventory). |

### 15. T-09 — Vehicle → Warehouse reconciliation

| Field | Finding |
|---|---|
| **Status** | **NOT STARTED** (scaffolding only) |
| **Gate** | **BLOCKED** — depends on T-04 (no UI owner) and an inventory contract |
| Evidence | Reconciliation controllers = **0**; `api/loading/**reconcil*` routes = **0**; callers of `VehicleInventoryService::recordReturn()` / `recordDelivery()` / `unallocate()` = **0** (the only `recordReturn` hits are Distribution's unrelated `DeliveryService::recordReturn` and a Marketing webhook). Tables `vehicle_shift_reconciliations` / `_lines` exist and are empty. |
| Meaning | The vehicle ledger still only ever increases; loaded stock neither delivered nor returned remains unaccounted. |
| Owner | Architecture; constrained by ADR-027 and the inventory Architecture Freeze. |

### 16. T-10 — Shipping → Finance bridge

| Field | Finding |
|---|---|
| **Status** | **NOT STARTED** |
| **Gate** | **BLOCKED** — no approved posting contract |
| Evidence | Listeners for `TripSettled::class` = **0**; `CodCollected::class` = **0**. |
| Meaning | Settlement finalisation and doorstep COD still reach no journal. |
| Contract gap | Which Finance rule/journal each event maps to. Must post only via `PostingCoordinator` (Finance F2 contract). |
| Owner | Finance + Logistics (cross-module; STOP-6 applies). Depends on T-01/T-02 for tenant-safe settlement. |

## 17. Status of Each Item

| Item | Implementation status | Gate | Contract exists? |
|---|---|---|---|
| **T-01** Network Service Area tenant isolation | **IMPLEMENTATION COMPLETE** | — | ✅ canonical tenant contract |
| T-01b `CapacityCommitment` (same class) | **NOT STARTED** | **BLOCKED** (STOP-12) | ❌ sweep semantics undefined |
| **T-02** Driver/Vehicle/Carrier tenancy | **NOT STARTED** | **BLOCKED** | ❌ ownership model undefined |
| **T-04** Distribution/Loading convergence | **NOT STARTED** | **BLOCKED** | ❌ design-only, unapproved |
| **T-05** State-machine unification | **NOT STARTED** | **BLOCKED** | ❌ design-only, unapproved |
| **T-06** Delivery return restock | **NOT STARTED** | **BLOCKED** | ❌ restock point undefined |
| **T-09** Vehicle reconciliation | **NOT STARTED** (scaffolding) | **BLOCKED** | ❌ depends on T-04 |
| **T-10** Shipping→Finance bridge | **NOT STARTED** | **BLOCKED** | ❌ posting map undefined |

**No feature was invented. No item was implemented without an approved contract.**

## 18. O-2 — `ecos_erp` Status

**Still OPEN.** Scope check (C-2): this task's brief does **not** explicitly authorize deployment to `ecos_erp`; it directs that absent explicit authorization the environment be left untouched. The preceding release closure recorded an explicit decision to leave it untouched. **No authorization exists → not deployed.**

Current `ecos_erp` state (read-only `SELECT` only):

| Metric | Value |
|---|---|
| Total permissions | 578 |
| Two-segment permissions | **0** |
| Of the certified 17 | **0** |
| `company-admin` grants | 396 |
| `viewer` grants | 83 |
| Release migration recorded | **0** (not applied) |

Unchanged from the release closure observation — an exact mirror of the pre-repair state.

## 19. Whether `ecos_erp` Was Touched

**No.** Verified:

| Check | Result |
|---|---|
| Writes issued to `ecos_erp` | **none** — every statement was `SELECT` |
| Release migration present in `ecos-app` container | **ABSENT (untouched)** |
| Tenant scope present in `ecos-app`'s `ServiceArea.php` | **0 — untouched** |

`ecos_erp` therefore also does **not** carry the T-01 fix. It remains exposed to the cross-company read on any environment where `network.view` is later granted — noted in §26.

---

## 20. Files Changed

| File | Change | Lines |
|---|---|---|
| `backend/Modules/Logistics/Network/Domain/Models/ServiceArea.php` | modified — tenant global scope added | **+44 / −0** |
| `docs/verification/TASK-LOGISTICS-REMAINING-CLOSURE-001-ENGINEERING-REPORT.md` | new (this report) | — |

Nothing else. `NetworkController.php` is untouched; no controller, service, resource, route, config or test file was modified.

### 20.1 Not committed

This task's brief contains no commit workstream and defers certification, so the change is **left uncommitted** — consistent with committing only when asked.

**Risk worth flagging:** the worktree holds 205 modified tracked files from concurrent sessions, so an unrelated session could sweep this fix into its own commit. To commit it in isolation (the index still carries another session's staged deletion, so a pathspec commit is required):

```bash
git commit -m "fix(logistics): scope Network service areas to the owning company" -- backend/Modules/Logistics/Network/Domain/Models/ServiceArea.php
```

## 21. Migrations

**None.** No migration was created, applied, rolled back or reverted. `ecos_dev` pending list is unchanged — `2026_08_14_100000_create_recipe_cost_snapshots` remains Pending and unapplied.

## 22. Tests Actually Run

| Suite | Command | Result |
|---|---|---|
| `tests/Feature/Logistics` | `GATE_WAIT=2400 scripts/test-gate.sh tests/Feature/Logistics` | **598 tests, 3599 assertions, 5 failures** |

Gate reported the schema free before the run and released the lock after.

**Not run:** `tests/Feature/IAM`, `tests/Unit/IAM` — this task changed no IAM/permission code (the permissions release is untouched). Per Workstream F, only checks relevant to work actually changed were run.

## 23. Static Checks Actually Run

| Tool | Target | Result |
|---|---|---|
| **Pint** (`--test`) | `ServiceArea.php` | **PASS** — 1 file |
| **PHPStan** level 0 (`phpstan.neon.dist`) | `ServiceArea.php` | **[OK] No errors** |

**No platform-wide cleanliness is claimed.** Only the changed file was analysed; the project's pre-existing PHPStan baseline was neither added to nor burned down.

## 24. Regression Results

| Suite | Before this task (release closure) | After T-01 | Verdict |
|---|---|---|---|
| `tests/Feature/Logistics` | 598 / 3599 / **5 failures** | 598 / 3599 / **5 failures** | **identical — no new failures** |

Failing set byte-identical, and identical to the control runs performed during the permissions certification:

1. `DistributionOrdersFilterApiTest::test_new_filters_compose_with_existing_ones_using_and`
2. `DistributionReadModelApiTest::test_each_filter_narrows_server_side`
3. `DistributionReadModelApiTest::test_filters_compose_in_a_single_query`
4. `VehicleModuleTest::test_maintenance_is_immutable_without_permission`
5. `VehicleModuleTest::test_maintenance_permission_endpoint_reflects_capability`

All **PRE-EXISTING**. Notably `Phase2ModuleTest` — which covers Network service areas, coverage resolution and `sweepExpired` — remains **green** with the tenant scope in place, corroborating that existing Network functionality was preserved.

**No test was modified, weakened or deleted.**

## 25. Concurrent Work Left Untouched

| Item | Handling |
|---|---|
| `frontend/src/features/orders/components/order-reservation-cell.tsx` | **Completely untouched** — not added, unstaged, reset, restored, cleaned, modified or committed. Still staged as `D`, byte-identical to task start. |
| 204 other modified tracked files | untouched |
| 251 untracked files (incl. new `Modules/Logistics/Distribution/**` from a concurrent session and 115 untracked reports) | untouched |
| Deployment method | single-file `docker cp` (`ServiceArea.php`) — never a bulk copy of the dirty worktree |

## 26. Remaining Blockers

| # | Blocker | Type | Needed to unblock |
|---|---|---|---|
| **B-1** | `CapacityCommitment` carries the same bare-UUID cross-company defect as `ServiceArea` (reachable via commit/release reservation). Scoping it silently converts `sweepExpired()` from a **global** to a **per-company** sweep on two HTTP paths with no scheduler fallback. | **STOP-12** — business rule | A decision: should an expired capacity-hold sweep be global or per-tenant? Then the same canonical scope applies. |
| **B-2** | T-02 — `logistics_drivers` has no `company_id`; drivers cannot be tenant-isolated at schema level. | Business decision | Driver ownership model (company / shipping-company / shared). |
| **B-3** | T-04, T-05, T-09 — no approved contract; `docs/logistics-v2/` is explicitly *"Design only. No implementation, no migrations, no code."* | **STOP-5** | CTO architecture review + ADR. |
| **B-4** | T-06, T-10 — would require changing Inventory / Finance. | **STOP-6** (cross-module) | Approved contract, then a cross-module task. |
| **B-5** | `ecos_erp` carries both the permission drift **and** (now) the un-fixed tenant defect. | Authorization | Explicit deployment authorization (O-2). |
| **B-6** | Other bare-UUID lookups outside Network remain open — `TripController`, `SettlementController`, Delivery OS sub-controllers (COD/POD/attempts/returns), `DriverController`, `VehicleController`, `ShippingCompanyController`, `RoutingController`. | Scope | These are the remainder of T-01/T-02's original scope; T-01 fixed the reported and reachable one. Each needs the same canonical scope, and several need the ownership decision first. |

**B-6 is the honest limit of this task:** T-01 closed the *reported, proven-reachable* defect on `ServiceArea`. It did not sweep the whole codebase for the pattern, because most remaining instances sit behind either an ownership decision (B-2) or a behavioural one (B-1).

## 27. Final Status

# PARTIAL

| Workstream | Outcome |
|---|---|
| **A — T-01 tenant isolation** | **IMPLEMENTATION COMPLETE.** Canonical authority identified and reused verbatim; cross-company read and write now 404 with no information leakage; same-company access, spoof resistance and authorization behaviour all proven; regression and static clean. |
| **B — roadmap audit** | **COMPLETE as an audit.** All six items audited against current code; all **BLOCKED** on missing approved contracts. Nothing implemented, nothing invented. |
| **C — `ecos_erp` (O-2)** | **NOT AUTHORIZED → untouched.** O-2 remains open. |
| **D — release integrity** | **HELD.** One file changed; concurrent work untouched; certified release preserved. |
| **E — cross-module** | **No other module modified.** Two items (T-06, T-10) identified as requiring it and stopped. |
| **F — verification** | Focused tests + static run on what changed; no platform-wide claim. |

### Definition of Done

| Item | Status |
|---|---|
| Certified permission release preserved unchanged | ✅ `595/17/413/90`; commit `2aefe0fb` intact |
| T-01 canonical tenant authority identified | ✅ `TenantOwnershipResolver` |
| Same-company Service Area access works | ✅ §7 |
| Cross-company denied per canonical contract | ✅ §8 — 404 |
| `company_id` spoofing cannot bypass | ✅ §9 |
| Authorization behavior preserved | ✅ §10 |
| No permission expansion | ✅ 0 permission changes |
| No `finance.admin` restoration | ✅ |
| No `routing.manage` restoration | ✅ |
| T-02 / T-04 / T-05 / T-06 / T-09 / T-10 audited | ✅ §11–17 |
| No feature invented without contract | ✅ |
| `ecos_erp` scope explicitly determined | ✅ not authorized |
| `ecos_erp` untouched | ✅ §19 |
| No concurrent work touched | ✅ §25 |
| Focused tests run | ✅ §22 |
| Static checks run | ✅ §23 |
| Engineering Report created | ✅ this document |
| Mobile notification sent | see §27.1 |

### 27.1 Notification

Sent via the project's notification mechanism after this report was written. Message content:

```
TASK-LOGISTICS-REMAINING-CLOSURE-001
Status: PARTIAL
T-01 Tenant Isolation: IMPLEMENTATION COMPLETE (cross-company now 404, no leak)
Remaining Logistics Audit: COMPLETE
T-02: NOT STARTED — BLOCKED (driver ownership model undefined)
T-04: NOT STARTED — BLOCKED (design-only, unapproved)
T-05: NOT STARTED — BLOCKED (no approved contract)
T-06: NOT STARTED — BLOCKED (restock point undefined, cross-module)
T-09: NOT STARTED — BLOCKED (scaffolding only, depends on T-04)
T-10: NOT STARTED — BLOCKED (posting map undefined, cross-module)
ecos_erp O-2: untouched — deployment not authorized
Remaining blockers: 6
Engineering Report updated.
```

**Delivery status — stated exactly as returned:** the mechanism reported **"Mobile push requested."** That is an acknowledgement of the request, **not** a confirmation of delivery to the device. Delivery is therefore **NOT confirmed** and is not claimed as such.

---

**FINAL CERTIFICATION IS DEFERRED** to the unified project certification phase. This task claims **PARTIAL** and nothing more. No further Logistics task was started.

---
---

# CONTINUATION — DISTRIBUTION RE-AUDIT

**Continuation status: PARTIAL** (unchanged). Zero files modified in this continuation — it is an audit only.

> **Headline: two of my own earlier conclusions were wrong and are corrected below.**
> 1. The concurrent Distribution work is **not active** — it has been dormant for ~5 days. My previous report called it "actively adding files"; that was inferred from git status, not from evidence.
> 2. **T-09 does have an approved contract.** `docs/architecture/ENTERPRISE-FULFILLMENT-PLATFORM.md` (Status: **APPROVED**, ADR-015) specifies `VehicleShiftReconciliation` in full. My previous report classified T-09 as "BLOCKED — no approved contract" because I searched `docs/adr/` and `docs/logistics-v2/` but not `docs/architecture/`. That classification was incorrect.

## C1. Current Worktree State

| Item | Previous section | Now | Δ |
|---|---|---|---|
| HEAD | `721800c6` | `721800c6` | unchanged |
| Staged | `D order-reservation-cell.tsx` | **identical** | untouched |
| Modified tracked | 205 | **205** | 0 |
| Untracked | 252 | **253** | +1 (this report) |
| `ServiceArea.php` | ` M` +44/−0 | ` M` **+44/−0** | unchanged |

No file was modified, staged, reset, cleaned, restored or committed during this continuation.

## C2. Concurrent Distribution Work Status

**Determined from repository evidence (file mtimes), not from an old dirty-file list.**

| Evidence | Value |
|---|---|
| Audit time | 2026-08-17 22:12 UTC |
| Newest file under `Modules/Logistics/Distribution/**` | **2026-08-13 00:02** — `DistributionAggregationService.php` |
| Second newest | 2026-08-13 00:01 — `DistributionWindowController.php` |
| Bulk of the set | 2026-08-11 05:43–06:04 |
| Its test files | 2026-08-13 04:41 |
| Newest file anywhere under `Modules/Logistics` | `Network/Domain/Models/ServiceArea.php` — **2026-08-18 00:46 (mine, T-01)** |

**Status: NOT ACTIVE — dormant ~5 days.** Nothing under `Distribution/**` has changed in that window, and the only file touched today is the T-01 file.

**Correction to the previous section:** it stated *"A concurrent session is actively adding `Modules/Logistics/Distribution/**` files."* They were **newly visible in `git status`** (untracked), not newly written. The correct statement is: an earlier session left uncommitted Distribution work in the tree, and it has been dormant since 2026-08-13.

## C3. Ownership Determination

| Artefact | Tracking | mtime | Owner |
|---|---|---|---|
| `Distribution/**` source (models, services, events, enums, controller, 4 migrations) | **untracked (`??`)** | 2026-08-11 → 08-13 | earlier/parallel session — **not mine** |
| `tests/Feature/Logistics/DistributionWindowApiTest.php` | **untracked** | 2026-08-13 04:41 | same session |
| `tests/Feature/Logistics/DistributionReadModelApiTest.php` | **untracked** | 2026-08-13 04:41 | same session |
| `tests/Feature/Logistics/DistributionOrdersFilterApiTest.php` | **untracked** | 2026-08-13 04:41 | same session |
| `tests/Feature/Logistics/VehicleModuleTest.php` | **tracked, unmodified** | — | committed baseline |
| `Network/Domain/Models/ServiceArea.php` | tracked, ` M` +44/−0 | 2026-08-18 | **mine (T-01)** |

Ownership is unambiguous. The Distribution set was **read only** for this audit; nothing in it was touched.

### C3.1 Regression-ownership refinement (corrects §24)

The 5 Logistics failures are **not** one homogeneous "pre-existing" group. They split cleanly by ownership:

| # | Failing test | File tracking | Owner | Classification |
|---|---|---|---|---|
| 1 | `DistributionOrdersFilterApiTest::test_new_filters_compose_with_existing_ones_using_and` | untracked | dormant Distribution session | **CONCURRENT WORK — in progress** |
| 2 | `DistributionReadModelApiTest::test_each_filter_narrows_server_side` | untracked | dormant Distribution session | **CONCURRENT WORK — in progress** |
| 3 | `DistributionReadModelApiTest::test_filters_compose_in_a_single_query` | untracked | dormant Distribution session | **CONCURRENT WORK — in progress** |
| 4 | `VehicleModuleTest::test_maintenance_is_immutable_without_permission` | **tracked** | committed baseline | **PRE-EXISTING** |
| 5 | `VehicleModuleTest::test_maintenance_permission_endpoint_reflects_capability` | **tracked** | committed baseline | **PRE-EXISTING** |

So only **2** of the 5 are genuinely pre-existing in committed code; **3** belong to another session's unfinished, uncommitted work. Neither group is attributable to T-01 or to the permissions release — the control runs already proved that — but the ownership is now precise. `DistributionWindowApiTest` (the fourth file from that session) **passes**.

## C4. T-01 Current State

**IMPLEMENTATION COMPLETE — unchanged and intact.**

| Check | Result |
|---|---|
| `ServiceArea.php` diff | `+44 / −0` |
| Added lines outside the T-01 tenant-scope pattern | **0** — the diff is purely T-01 |
| Modified by any concurrent session | **No** — mtime is my edit; no other session touched it |
| HOST md5 | `910dd0f4d2a2935f95cd13de112d3b47` |
| APP (`ecos-dev-app`) md5 | `910dd0f4d2a2935f95cd13de112d3b47` |
| RUNNER (`ecos-dev-testrunner`) md5 | `910dd0f4d2a2935f95cd13de112d3b47` |
| `NetworkController` | untouched |
| Second tenant mechanism introduced | none |

T-01 was not redesigned or reopened. No regression evidence was found that would require touching it.

**Ownership is proven, so the isolated commit is prepared (Step 9) — and deliberately NOT executed**, per "Do not commit it automatically":

```bash
git commit -m "fix(logistics): scope Network service areas to the owning company" -- backend/Modules/Logistics/Network/Domain/Models/ServiceArea.php
```

A pathspec commit is required because the index still carries another session's staged deletion.

## C5. T-04 Current Repository State

The dormant Distribution work is **stack A** (`Modules\Logistics\Distribution` → `api/logistics/distribution/windows/*`), which the original full-stack audit already recorded as connected to `DistributionWorkspacePage`. **It is not T-04's subject.** T-04 concerns the orphan `/api/distribution/*` and `/api/driver/*` namespaces and the UI-less `Operations\Loading`.

## C6. T-04 Routes

Re-dumped from the running app (1,856 routes total — unchanged):

| Namespace | Routes | vs previous |
|---|---|---|
| `api/distribution/*` | **0** | unchanged |
| `api/driver/*` | **0** | unchanged |
| `api/loading/*` | **24** | unchanged |
| `api/logistics/distribution/*` | 73 | — of which `windows` = **11** (the dormant work), `trips` = 43, `zones` = 6, `planning` = 6 |

## C7. T-04 Controllers

`Operations\Loading` retains its 7 controllers (LoadingSession, VehicleAssignment, Allocation, DriverAssignment, LoadingException, LoadingDashboard, VehicleInventory) — all route-registered and callable. The dormant work adds `DistributionWindowController` (registered, 11 routes). **No controller exists for `/api/distribution/*` or `/api/driver/*`.**

## C8. T-04 Services

`Operations\Loading` services/actions unchanged and functional (`LoadProductAction`, `DispatchVehicleAction`, `AutoAllocationService`, `VehicleInventoryService`). The dormant work adds `DistributionWindowService`, `DistributionCollectionService`, `DistributionAggregationService`, `ManualAssignmentService`, `OrderZoneResolver`, `RedistributionSuggestionService` — all invoked by `DistributionWindowController`.

## C9. T-04 Callers

| Check | Result |
|---|---|
| Frontend references to `api/loading/*` | **0** — still no caller for the 24-route Loading OS |
| Frontend still bound to `'/api/distribution'` | **1** (`distribution-board-service.ts`) — backend still absent |
| Driver Mobile still on its own `axios.create({baseURL:'/api'})` | **1** — backend still absent |

**T-04's defining evidence is unchanged.**

## C10. T-04 Tests

The dormant Distribution work ships 4 test files (3 currently failing — see C3.1). **No test exists for `/api/distribution/*` or `/api/driver/*`**, because no such implementation exists. Per Step 10, **no artificial tests were created for nonexistent architecture.**

## C11. T-04 Architecture Authorization

Re-checked against current documentation.

| Source | Status | Covers T-04? |
|---|---|---|
| `docs/logistics-v2/README.md` | *"awaiting CTO Architecture Review"* · *"Authorization: Design only. No implementation, no migrations, no code."* — **unchanged** | design only |
| `docs/adr/` (17 ADRs, unchanged) | — | none addresses the orphan namespaces |
| **`docs/architecture/ENTERPRISE-FULFILLMENT-PLATFORM.md`** | **Status: APPROVED** (2026-07-04, TASK-FULFILLMENT-ARCH-001, ADR-015) | **partially — see below** |

**What the APPROVED spec does settle** (this is new evidence, not previously cited):

- §13 Module Ownership — **Loading & Allocation OS → Operations** bounded context; Vehicle Mobile Warehouse → Operations/Logistics; Logistics OS → Logistics, *"Redesigned (starts after loading)"*.
- §9.1–9.2 — *"Logistics OS now starts **after** Loading & Allocation OS completes… receives loaded vehicles, not preparation batches."*
- §14 Responsibility Matrix — "Transfer pool → vehicle inventory", "Open loading sessions", "Assign vehicles" → **Loading & Allocation OS**; "Dispatch vehicles", "Confirm deliveries", "Capture proof of delivery" → **Logistics OS**.

This **confirms `Operations\Loading` is the architecturally correct home for loading** — i.e. the existing UI-less backend sits exactly where the approved architecture puts it, and a third `/api/distribution/*` loading stack has no place in it.

**What it does not settle:** the fate of the 16 orphan frontend pages. Choosing between *build a facade*, *rewrite the pages onto the existing APIs*, or *retire them and surface the working workspaces* is a product/UI decision. The approved spec governs backend architecture and is silent on it.

**Authorization was not inferred from code existence.** No implementation was performed.

## C12. T-04 Final Status

**BLOCKED — CTO Architecture Review**, but the blocker is now narrower and better identified:

- ✅ **Backend ownership is settled** by the APPROVED fulfillment spec — Operations owns Loading; Logistics starts after it.
- ❌ **The orphan-UI decision is unmade** — 16 pages call two namespaces that do not exist, and no approved source says whether to build, rewrite or retire them.

The previous classification ("no approved contract at all") was too broad; the architecture exists, the product decision does not.

## C13. T-02 Status — **BLOCKED — CTO Architecture Review** (unchanged)

Not implemented, not modified. `logistics_drivers.company_id` still absent; driver ownership model still undefined; the approved fulfillment spec does not address driver tenancy.

## C14. T-05 Status — **BLOCKED — CTO Architecture Review** (unchanged, better characterised)

Not implemented, not modified. Listeners for `TripDispatched` / `DeliveryStopCompleted` remain **0**; `Modules/Logistics` still contains **0** inventory references.

New nuance from the APPROVED spec: §14 and §6.3 define the *handoff points* ("Transfer pool → vehicle inventory" = Loading OS; delivery confirmation = Logistics OS; each arrow *"creates an immutable inventory movement record"*). What remains uncontracted is the **event/inventory wiring** between the reachable stack and `ShipOrderInventoryAction`, constrained by ADR-027 and ADR-042. Depends on T-04.

## C15. T-06 Status — **BLOCKED — CTO Architecture Review** (unchanged)

Not implemented, not modified. Restock references in both return services remain **0**; `ReturnReceived` listeners remain **0**.

The APPROVED spec contracts the *movement* — §6.3 "Return to Warehouse" and §6.4 `quantity_returned` *(physically returned to warehouse)* — but contains **no restock rule**: a grep for `restock|damaged|sellable` across all 1,009 lines returns only those two movement references. **When stock re-enters and who owns damaged-vs-sellable disposition remain undefined.** Cross-module (Inventory) — STOP-6 also applies.

## C16. T-09 Status — **CORRECTED: contract EXISTS**

**Previous classification: "BLOCKED — no approved contract". That was wrong.**

`docs/architecture/ENTERPRISE-FULFILLMENT-PLATFORM.md` §6.4 (**Status: APPROVED**) specifies End-of-Shift Reconciliation in full:

```
VehicleShiftReconciliation
├── vehicle_id, shift_date, operator_id
├── products[]
│   ├── quantity_loaded
│   ├── quantity_delivered   (from confirmed deliveries)
│   ├── quantity_returned    (physically returned to warehouse)
│   └── quantity_variance    (loaded - delivered - returned; must be 0)
├── variance_approved_by, variance_notes, reconciled_at
```

§9.3 and §14 additionally assign ownership: *"End-of-shift vehicle reconciliation | Logistics OS → Loading & Allocation OS"*, split as Loading OS (vehicle) + Logistics OS (route).

The existing scaffolding matches this contract's shape — `vehicle_shift_reconciliations` + `vehicle_shift_reconciliation_lines` tables, models, relations and `ReconciliationLineRequest` all exist; only a controller, route and writer are missing, and `VehicleInventoryService::recordReturn()` / `recordDelivery()` / `unallocate()` still have **0 callers**.

**Revised status: NOT STARTED — approved contract available; implementation absent.**
Remaining dependency: T-04 (no UI owner for `Operations\Loading`) and the inventory-effect question shared with T-06. **Deliberately not implemented here** — Step 6 forbids it.

## C17. T-10 Status — **BLOCKED — CTO Architecture Review** (unchanged)

Not implemented, not modified. `TripSettled` and `CodCollected` listeners remain **0**. The approved fulfillment spec does not define a Finance posting map; posting must go through Finance's `PostingCoordinator` (F2 contract). Cross-module — STOP-6 applies.

## C18. CapacityCommitment Status — **PRE-EXISTING · CONTRACT GAP · OUT OF SCOPE**

**Not modified in this continuation**, per Step 7.

Same bare-UUID cross-company pattern as the repaired `ServiceArea` (`CapacityCommitment::where('uuid',$id)->firstOrFail()`), reached via commit/release reservation. Scoping it would silently convert `CapacityLedgerService::sweepExpired()` from a **global** to a **per-company** sweep on two authenticated HTTP paths (`NetworkController::sweepExpired`, `Operations\CapacityReservationService::reconcile`) with **no scheduler fallback** — potentially stranding other companies' expired holds.

An authoritative contract for sweep semantics was searched for during this audit and **not found**, including in the APPROVED fulfillment spec. Classification stands.

## C19. `ecos_erp` / O-2 Status — **OPEN DEPLOYMENT ITEM**

No deployment authorization exists in this continuation's brief. **Not deployed, not modified.** Verified read-only at the end of this audit:

```
ecos_erp: perms=578  two_part=0
```

Unchanged. No permission was added; `finance.admin` and `routing.manage` remain unrestored; the certified 17 were not expanded.

## C20. Files Touched

**None.** This continuation modified zero files. The only new artefact is this appended report section.

| Protected item | Verified state |
|---|---|
| Certified release `2aefe0fb` | intact — `perms=595 two_part=17 admin=413 viewer=90` |
| The 17 permissions / Company Admin / Viewer / role-less | unchanged |
| `finance.admin`, `routing.manage` | still unrestored |
| T-01 `ServiceArea.php` | unchanged (`+44/−0`, md5 stable) |
| `NetworkController.php` | untouched |

## C21. Files Deliberately Left Untouched

| File / set | Reason |
|---|---|
| `frontend/src/features/orders/components/order-reservation-cell.tsx` | another session's staged deletion — not added, unstaged, reset, restored, cleaned, modified or committed |
| `Modules/Logistics/Distribution/**` (untracked source, 4 migrations, controller, services, models, events, enums) | dormant concurrent session's work — **read only** |
| `tests/Feature/Logistics/Distribution*ApiTest.php` (4 files) | same session |
| `Modules/Logistics/Network/Domain/Models/CapacityCommitment.php` | Step 7 — contract gap |
| 204 other modified tracked files, 253 untracked files | other sessions |
| `ecos_erp` / `ecos-app` | no deployment authorization |

## C22. Tests Run

**None re-run in this continuation** — no code changed, so a re-run would only re-measure the identical file. The T-01 regression is carried forward from the run against this byte-identical file (md5 `910dd0f4…` verified unchanged on HOST, APP and RUNNER):

```
tests/Feature/Logistics — 598 tests, 3599 assertions, 5 failures
```

with failure ownership now refined per C3.1 (3 concurrent-work, 2 pre-existing baseline).

## C23. Static Checks

Re-run in this continuation against the unchanged T-01 file:

| Tool | Target | Result |
|---|---|---|
| **Pint** (`--test`) | `ServiceArea.php` | **PASS** — 1 file |
| **PHPStan** level 0 (`phpstan.neon.dist`) | `ServiceArea.php` | **[OK] No errors** |

**No platform-wide static cleanliness is claimed.**

## C24. Remaining Blockers

| # | Blocker | Type | Change |
|---|---|---|---|
| **B-1** | `CapacityCommitment` sweep semantics undefined (global vs per-company) | contract gap | unchanged |
| **B-2** | T-02 driver ownership model undefined; `logistics_drivers` has no `company_id` | business decision | unchanged |
| **B-3** | T-04 orphan-UI decision unmade (build facade / rewrite / retire the 16 pages) | product decision | **narrowed** — backend ownership now settled by the APPROVED spec |
| **B-4** | T-06, T-10 cross-module (Inventory / Finance) | STOP-6 | unchanged |
| **B-5** | `ecos_erp` carries the permission drift **and** the unfixed tenant defect | authorization | unchanged |
| **B-6** | Bare-UUID lookups still open in `TripController`, `SettlementController`, Delivery OS sub-controllers, `DriverController`, `VehicleController`, `ShippingCompanyController`, `RoutingController` | scope | unchanged |
| **B-7** | 3 failing tests belong to a dormant, uncommitted concurrent session | concurrent work | **newly identified** (C3.1) |

**Removed as a blocker:** T-09's "no approved contract" — §6.4 of the APPROVED fulfillment spec provides one (C16).

## C25. Recommended Next Step

1. **Commit T-01 in isolation** — ownership is proven and the command is prepared (C4). It is a closed cross-tenant read/write hole sitting uncommitted in a 205-file dirty tree; the longer it waits, the likelier another session sweeps it up.
2. **Resolve B-7** — ask whoever owns the dormant Distribution work whether to finish or discard it. Three failing tests and ~20 uncommitted files have been static for five days, and they distort every Logistics regression baseline.
3. **T-09 is the cheapest unblocked item** — it now has an approved contract (§6.4), matching scaffolding, and a clear owner. It still depends on T-04 for a UI, but the backend reconciliation writer could be specified against the approved entity today.
4. **T-04 needs one product decision, not an architecture review** — the APPROVED spec already settles backend ownership. The open question is only what to do with the 16 orphan pages.

---

## CONTINUATION — FINAL STATUS

# PARTIAL

| Workstream | Outcome |
|---|---|
| Concurrent Distribution work | **NOT ACTIVE** — dormant since 2026-08-13; ownership established; untouched |
| T-04 re-audit | **BLOCKED — CTO Architecture Review**; evidence unchanged (0/0/24-with-0-callers); blocker narrowed to a product decision |
| T-01 | **IMPLEMENTATION COMPLETE** — intact, ownership proven, isolated commit prepared but not executed |
| T-02 / T-05 / T-06 / T-10 | **BLOCKED — CTO Architecture Review** (unchanged, not implemented) |
| T-09 | **NOT STARTED — approved contract found** (prior "no contract" classification corrected); not implemented |
| CapacityCommitment | **PRE-EXISTING · CONTRACT GAP · OUT OF SCOPE** — not modified |
| `ecos_erp` / O-2 | **OPEN DEPLOYMENT ITEM** — untouched |
| Files changed | **0** |

No business rule was invented. No blocked item was implemented. No concurrent work was touched. The certified permissions release and T-01 are both intact.

### C26. Continuation Notification

Requested via the project's notification mechanism after this section was written. Content:

```
TASK-LOGISTICS-REMAINING-CLOSURE-001 — Distribution Re-audit complete
T-01: IMPLEMENTATION COMPLETE (intact)
Concurrent Distribution work: NOT ACTIVE (dormant since 2026-08-13)
T-04: BLOCKED — CTO Architecture Review (narrowed to a product decision)
T-02: BLOCKED — CTO Architecture Review
T-05: BLOCKED — CTO Architecture Review
T-06: BLOCKED — CTO Architecture Review
T-09: CORRECTED — approved contract found (§6.4); NOT STARTED
T-10: BLOCKED — CTO Architecture Review
CapacityCommitment: PRE-EXISTING · CONTRACT GAP · OUT OF SCOPE
ecos_erp O-2: OPEN DEPLOYMENT ITEM — untouched
Remaining blockers: 7
Engineering Report updated.
```

**Delivery status — stated exactly as returned:** the mechanism reported **"Mobile push requested."** That is an acknowledgement of the request, **not** a confirmation of delivery. Delivery is **NOT confirmed** and is not claimed as such.

**FINAL CERTIFICATION REMAINS DEFERRED.**

---
---

# CONTINUATION — T-01 ISOLATED RELEASE

**Outcome: T-01 committed as an isolated release unit — `abe4d10f`.** One file, `+44 / −0`. No other file was staged, committed or touched. Nothing else was implemented.

## R1. Worktree State

Inspected before any action (`git status`, `git diff`, `git diff --cached`):

| Item | Before commit | After commit |
|---|---|---|
| HEAD | `721800c6` | **`abe4d10f`** |
| Index (`git diff --cached`) | `D order-reservation-cell.tsx` — sole entry | **identical, untouched** |
| Modified tracked | 205 | **204** (−1: the T-01 file moved into the commit) |
| Untracked | 253 | **253** (unchanged) |
| `ServiceArea.php` | ` M` `+44 / −0` | committed; working tree clean |

Matches the authoritative state recorded in the previous continuation exactly.

## R2. T-01 Ownership Proof

| Check | Result |
|---|---|
| Diff size | `+44 / −0` — **zero deletions** |
| Added lines outside the T-01 tenant-scope pattern | **0** |
| Modified by any concurrent session | **No** — sole author is this task |
| Contains a migration | No |
| Contains a permission change | No |
| Contains unrelated Logistics work | No |
| `NetworkController` included | No — untouched |

Ownership re-confirmed. The change is exactly the T-01 unit and nothing else.

## R3. Exact Diff

Two imports, one docblock, one global scope — and the pre-existing UUID generator preserved:

```diff
+use App\Core\Company\TenantOwnershipResolver;
+use Illuminate\Database\Eloquent\Builder;

     protected static function booted(): void
     {
+        static::addGlobalScope('tenant', static function (Builder $query): void {
+            $tenant = app(TenantOwnershipResolver::class);
+
+            // Console, queue workers, seeders and migrations run with no actor.
+            if (! $tenant->appliesTo()) { return; }
+
+            // Cross-company access is granted by an is_system role, never by the
+            // mere absence of a company. See TenantOwnershipResolver.
+            if ($tenant->isUnrestricted()) { return; }
+
+            $companyId = $tenant->companyId();
+
+            // RC-6: a null company must close the query, not remove the filter.
+            if ($companyId === null) { $query->whereRaw('1 = 0'); return; }
+
+            $query->where('company_id', $companyId);
+        });
+
         static::creating(function (self $area): void {
             if ($area->uuid === null) {
                 $area->uuid = (string) Str::uuid();
```

*(Braces collapsed above for readability; the committed file follows the project's formatting — Pint PASS.)*

## R4. Isolated Commit Hash

| | |
|---|---|
| **SHA** | **`abe4d10fd24c55bf6683bf44fe3b6882c0dac98c`** |
| Short | **`abe4d10f`** |
| Subject | `fix(logistics): scope Network service areas to the owning company` |
| Stat | **1 file changed, 44 insertions(+), 0 deletions(−)** |
| Branch | `develop` |
| Pre-commit hook | ran — **"All checks passed."** |
| Method | **pathspec commit** (`git commit -- <path>`) — the index was never written to, so the unrelated staged deletion could not be swept in |
| Pushed | **No** |

### R4.1 Commit-to-runtime parity

| Layer | md5 |
|---|---|
| **COMMIT** (`git show HEAD:…`) | `910dd0f4d2a2935f95cd13de112d3b47` |
| **HOST** (worktree) | `910dd0f4d2a2935f95cd13de112d3b47` |
| **APP** (`ecos-dev-app`) | `910dd0f4d2a2935f95cd13de112d3b47` |
| **RUNNER** (`ecos-dev-testrunner`) | `910dd0f4d2a2935f95cd13de112d3b47` |

**COMMIT = HOST = APP = RUNNER.** The committed artifact is byte-identical to what was verified over HTTP and by the regression run.

## R5. Files Committed

| File | Change |
|---|---|
| `backend/Modules/Logistics/Network/Domain/Models/ServiceArea.php` | `+44 / −0` |

**Exactly one file.** No migration, no permission, no config, no test, no report, no frontend file.

## R6. Files Deliberately Untouched

| File / set | Handling |
|---|---|
| `frontend/src/features/orders/components/order-reservation-cell.tsx` | **Untouched.** Still the sole entry in the index, byte-identical. Not added, unstaged, reset, restored, cleaned, modified or committed. A pathspec commit was used specifically so the index was never written. |
| `Modules/Logistics/Distribution/**` (dormant session: models, services, events, enums, controller, 4 migrations) | untouched — not committed |
| `tests/Feature/Logistics/Distribution*ApiTest.php` (4 files, untracked) | untouched |
| `Modules/Logistics/Network/.../CapacityCommitment.php` | untouched — no tenant scope added |
| 204 other modified tracked files · 253 untracked files | untouched |
| `ecos_erp` / `ecos-app` | untouched |
| Certified release `2aefe0fb` | intact |

## R7. T-04 — **BLOCKED — PRODUCT DECISION**

Not implemented. No API created, no page moved, no ownership invented.

The blocker is **not** absent architecture. `docs/architecture/ENTERPRISE-FULFILLMENT-PLATFORM.md` (**Status: APPROVED**, ADR-015) settles backend ownership: §13 places **Loading & Allocation OS in the Operations bounded context**, and §9.1 states *"Logistics OS now starts after Loading & Allocation OS completes."* A third `/api/distribution/*` backend stack therefore has **no approved architectural ownership**.

Current evidence (re-dumped, 1,856 routes):

| Namespace | Routes |
|---|---|
| `api/distribution/*` | **0** |
| `api/driver/*` | **0** |
| `api/loading/*` | **24** — with **0** frontend callers |
| `api/logistics/distribution/windows` | 11 — dormant Stack A work, **not** automatically T-04 |

**The unresolved item is the product decision for the 16 orphan pages** (build a facade · rewrite onto existing APIs · retire and surface the working workspaces).

## R8. T-09 — **NOT STARTED — CONTRACT AVAILABLE**

Not implemented in this continuation.

Approved contract: `docs/architecture/ENTERPRISE-FULFILLMENT-PLATFORM.md`, **ADR-015 §6.4**, which defines `VehicleShiftReconciliation` explicitly, including the invariant:

```
quantity_variance = loaded − delivered − returned   (must equal 0)
```

Ownership is assigned in §9.3 / §14 (*"End-of-shift vehicle reconciliation | Logistics OS → Loading & Allocation OS"*). Recorded accurately; implementation deliberately not begun.

## R9–R12. T-02 / T-05 / T-06 / T-10 — **BLOCKED**

All four remain **BLOCKED**. None implemented, none modified, and their architecture was not reinterpreted in this continuation.

| Item | Status |
|---|---|
| **T-02** Driver/Vehicle/Carrier tenancy | BLOCKED |
| **T-05** State-machine unification | BLOCKED |
| **T-06** Delivery return restock | BLOCKED |
| **T-10** Shipping→Finance bridge | BLOCKED |

## R13. CapacityCommitment — **UNTOUCHED**

No tenant scope added. The global-vs-per-company `sweepExpired()` behaviour remains an open **contract question**, unchanged from the previous continuation.

## R14. Test Baseline Clarification

The five Logistics failures are **not one baseline group**. Recorded separately, and neither group was modified:

| Group | Tests | File tracking | Classification |
|---|---|---|---|
| `DistributionOrdersFilterApiTest` ×1, `DistributionReadModelApiTest` ×2 | **3** | **untracked** — dormant concurrent session | **CONCURRENT WORK** — not a tracked regression; not claimed as baseline |
| `VehicleModuleTest` ×2 | **2** | **tracked** | **PRE-EXISTING** — not fixed here |

Only the 2 `VehicleModuleTest` failures are genuine tracked-baseline failures. The 3 Distribution tests belong to another session's uncommitted work and were left alone.

Regression figure carried forward against the byte-identical committed file: **598 tests / 3599 assertions / 5 failures**. Pint PASS, PHPStan L0 clean.

## R15. `ecos_erp` — **UNTOUCHED**

Not deployed, not modified. Verified read-only after the commit:

```
ecos_erp: perms=578  two_part=0
```

No permission changed; `finance.admin` and `routing.manage` remain unrestored; the certified 17 were not expanded.

## R16. Final Implementation State

| Item | State |
|---|---|
| **T-01** | **IMPLEMENTATION COMPLETE — released** as isolated commit `abe4d10f` |
| Certified permissions release `2aefe0fb` | intact — `ecos_dev perms=595 two_part=17 admin=413 viewer=90` |
| T-04 | BLOCKED — PRODUCT DECISION |
| T-09 | NOT STARTED — CONTRACT AVAILABLE |
| T-02 / T-05 / T-06 / T-10 | BLOCKED |
| CapacityCommitment | untouched — contract question open |
| `ecos_erp` / O-2 | OPEN DEPLOYMENT ITEM — untouched |
| Files committed | **1** |
| Concurrent work touched | **none** |

## R17. Remaining Product Decisions

| # | Decision | Blocks |
|---|---|---|
| **P-1** | The 16 orphan pages calling `/api/distribution/*` and `/api/driver/*` — build a facade, rewrite onto the existing APIs, or retire them and surface the working workspaces? Backend ownership is already settled by ADR-015. | T-04 (and, downstream, T-05 / T-09's UI) |
| **P-2** | Should `sweepExpired()` be global or per-company? | CapacityCommitment tenant scoping |
| **P-3** | Driver ownership model — company-owned, shipping-company-owned, or shared? | T-02 |
| **P-4** | Delivery-return restock point and damaged-vs-sellable disposition | T-06 |
| **P-5** | Shipping→Finance posting map (via `PostingCoordinator`) | T-10 |
| **P-6** | Is `ecos_erp` in the deployment scope? | O-2 |
| **P-7** | Finish or discard the dormant Distribution session's uncommitted work (3 failing tests distort every Logistics baseline) | regression clarity |

---

## CONTINUATION — T-01 ISOLATED RELEASE — FINAL STATUS

# IMPLEMENTATION COMPLETE *(for this isolated release operation)*

T-01 is implemented, verified and now released as a self-contained commit. Every other roadmap item was left exactly as it was, and no unrelated file entered the commit.

**FINAL CERTIFICATION REMAINS DEFERRED.**

### R18. Notification

Requested via the project's notification mechanism after this section was written. Content:

```
TASK-LOGISTICS-REMAINING-CLOSURE-001
T-01 isolated release: COMMITTED abe4d10f (1 file, +44/-0)
T-04: BLOCKED — PRODUCT DECISION
T-09: NOT STARTED — CONTRACT AVAILABLE
T-02/T-05/T-06/T-10: BLOCKED
CapacityCommitment: UNTOUCHED
ecos_erp: UNTOUCHED
Certification: DEFERRED
Engineering Report updated.
```

**Delivery status — stated exactly as returned:** the mechanism reported **"Mobile push requested."** That is an acknowledgement of the request, **not** confirmation of delivery to the device. Delivery is **NOT confirmed** and is not claimed as such.
