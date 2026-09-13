# OPS-02 — SHIPPING CAPABILITY RECONCILIATION — ENGINEERING REPORT

## STATUS: **OPS-02 SHIPPING RECONCILED**

Mode: back-sync + read-only reconciliation, as instructed. No OPS-02 source changes were made. This report is the checkpoint deliverable.

---

## OPS BEFORE: `ab209009`
## NEXT SYNCED FROM: `bfa3797a`
## OPS AFTER BACK-SYNC: `bfa3797a`

## BACK-SYNC

- **Ownership confirmed first**: worktree `E:\ECOS\ECOS-NEXT-OPS`, branch `feature/operations-v1.1`, clean tree, HEAD `ab209009` (matches ticket's stated LAST OPS SOURCE). No `.git/index.lock`/`MERGE_HEAD`/`rebase-merge` anywhere. `ListAgents` showed 7 peer sessions, all on other lanes (NEXT/FIN/WOO/DB) — none contending for this worktree.
- **`04f3e133` was stale**: `redesign/v1.1` had already moved to `bfa3797a` (confirmed `04f3e133` is an ancestor of `bfa3797a` via `git merge-base --is-ancestor`). Per the ticket's own "use latest stable NEXT if newer" allowance, `bfa3797a` was used. Reflog on `redesign/v1.1` showed a clean, sequential, non-conflicted history reaching it: OPS-01 fast-forward-merged in at 09:01, FIN-01 merged at 09:09, CORE-01 (UI redesign) merged at 09:23, WOO fast-forwarded in at 10:08 — nothing mid-flight, nothing force-pushed.
- **Result: clean fast-forward, not a real merge.** `ab209009` was already an ancestor of `bfa3797a` (this branch's own prior OPS-01 work had already been folded into `redesign/v1.1`), so `git merge redesign/v1.1 --ff-only` moved the branch pointer forward with **zero conflicts, zero new commits, zero manual resolution**. 360 files changed (+20,101/−12,095) — entirely UI-redesign (CORE-01), Finance/HR (FIN-01), and WooCommerce-connector (WOO) work. **Zero files under `Modules/Logistics`, `Modules/Operations`, or `Modules/Commerce/Orders` were touched.**
- **Verified CORE-01/FIN-01/OPS-01 preserved**: the only Shipping-adjacent files the back-sync touched were two frontend tabs (`driver-settlement-tab.tsx`, `returning-to-warehouse-tab.tsx`) — diffed directly and confirmed to be **cosmetic-only** (component-library migration `EntityTable`→`UniversalDataGrid`, prop renames), zero logic/data changes. This morning's OPS-01 Closure-02 reconciliation findings (see below) were independently re-verified against current code and stand unchanged.
- Working tree clean after fast-forward; branch is ahead of `next/feature/operations-v1.1` by 37 commits (unpushed, per instruction).

---

## METHODOLOGY

Five parallel read-only investigation passes traced **live, registered routes → real (non-stub) controller/service logic → actual frontend callers → router+nav reachability** for every lettered item in the ticket's §3, rather than trusting file existence or prior documentation. Findings below cite `file:line` throughout. A same-branch, same-morning reconciliation (`TASK-ECOS-V1.1-OPS-01-CLOSURE-02-DRIVER-RETURNS-WAREHOUSE-RECEIPT-REPORT.md`) had already produced verified, citation-backed findings for Returns/Warehouse-Receipt/Custody-boundary; those were re-confirmed post-back-sync (unaffected — see above) rather than re-derived, and extended where that report had explicitly left items unverified (retry behavior; §12 collection-difference; Loading/Dispatch's fuller surface).

---

## SHIPPING CAPABILITY MATRIX

| # | Area | Status | Basis |
|---|---|---|---|
| A | Shipping Orders | **COMPLETE** | Deliberate read-only projection of `Commerce\Orders`, not an independently-created entity (own docblock, "§32... no mutation action lives here"). Real bounded read model, live route, nav+router reachable. One flagged permission-string mismatch — see Real Gaps. |
| B | Planning/Groups (incl. Prep→Distribution handoff, Waves) | **COMPLETE** | Wave (Preparation) → Window (Distribution day-bucket) → Group (capacity slot) are three genuinely distinct, correctly-composed concepts. Group creation/closure now fully event-driven (`WaveStarted`/`WavePreparationStarted`/`WaveClosed` listeners → `DailyGroupLifecycleService`), capacity-aware, idempotent. One asymmetry flagged (manual "Collect" step) — see Real Gaps/UX. |
| C | Trips | **COMPLETE** | Three converging creation paths (Group-finalize, manual, vehicle-assignment-first) correctly reconciled — Finalize *adopts* a pre-existing bare Trip rather than duplicating it. Reachable via contextual deep-links from 4 other pages (deliberate pattern, documented in code) rather than a standalone sidebar entry. |
| D | Driver/Vehicle assignment (incl. vehicle-linked filtering) | **COMPLETE** | Live, single mechanism: `group-vehicle-assignment.tsx` → `DistributionWindowController::groupFleetOptions()` → `GroupVehicleAssignmentService::assign()` → canonical `DriverVehicleAssignment`. Driver choices server-filtered by selected vehicle's `driver_ids`. Re-verified unchanged post-back-sync. |
| E | Loading | **COMPLETE** (live engine) | Full lifecycle traced start→scan/allocate→confirm→exceptions→complete via `GroupLoadingWorkspaceController`/`DriverLoadingController`/`LoadProductAction`/`LoadingSessionProgressCoordinator`. **Substantial dead HTTP surface found alongside it** — see Dead-Code Inventory. |
| F | Dispatch | **RECAPTURED CAPABILITY, RETIRED UI** | No warehouse-side "Dispatch" button survives live in the UI (the `Logistics\Dispatch` V2 module and Loading's own dispatch action are both orphaned — see below). The actual live mechanism is driver-initiated: `DriverRuntimeController::startTrip()`, gated on custody-confirmation, real and fully wired. Capability exists; the "Dispatch" branding/UI concept was deliberately retired in a prior redesign. |
| G | Driver execution | **COMPLETE** | `DriverRuntimeController` + `DeliveryService`, real end-to-end. Genuinely separate `DriverShell` (own router branch, tested) for driver-only logins, confirmed via post-login redirect logic. 27 real driver-mobile pages. |
| H | Delivery outcomes (incl. No Answer/Postponed retry) | **COMPLETE** | Three-layer vocabulary (`DeliveryStopStatus`/`action_type`/`FailureReason`) consistently wired; frontend fetches failure reasons live, never hardcodes them. **Retry/requeue mechanism definitively confirmed real and dual-path** (immediate event release for retryable reasons + end-of-day backstop sweep for never-settled stops), corroborated by existing passing tests (`RetryableDeliveryReleaseTest.php`). This resolves the one item this morning's report had explicitly left unverified. Minor UX inconsistency noted (not a gap) — see below. |
| I | Custody | **COMPLETE** (Group flow) / **PRODUCT DECISION** (Pool flow) | Vehicle-custody credit is one single path (`VehicleInventoryService::recordLoad`, called from one place, `LoadProductAction`) regardless of flow — confirmed no duplicate custody engine. Warehouse-stock decrement genuinely forks by flow: Group flow decrements at load-time (real, atomic, idempotent); Pool flow defers to dispatch-time and — newly confirmed this pass — **its sole trigger has zero callers anywhere**, so it is unreachable in the live product today, not merely unsafe to change. |
| J | Returns | **COMPLETE** | Physical trip return (`DeliveryService::confirmReturn`) and warehouse receipt are structurally independent — confirmed by full method-body read, no shared table writes. Expected Driver Returns aggregate is honestly gap-carded (no fake numbers) rather than built, because no shortage-rule authority exists yet — a deliberate, correct choice, not a bug. |
| K | Warehouse Receipt (incl. damaged/missing/good-return) | **COMPLETE** | `ReceiveVehicleReturnAction`/`VehicleShiftReconciliationLine` is the sole canonical writer, distinguishing good/damaged/missing; idempotent (same-split no-op, different-split refused). |
| L | Settlement | **COMPLETE** | Both settlement figures traced to real, null-guarded, non-fabricated aggregates: cash hand-back discrepancy (`TripSettlement::calculateDiscrepancy`) and the previously-untraced "collection difference" (`DriverDaySettlementReadService::collectionsBreakdown`, reports `_pending` rather than a partial number until every stop is settled). Structurally cannot touch physical custody (`SettlementService` has zero references to `VehicleInventoryItem`). |
| M | Treasury handover | **COMPLETE** (code), **BLOCKED IN PRACTICE** | `CashHandoverService` is careful, correct, race-safe money handling — but its target account role (`driver_cash_clearing`) is never seeded anywhere and there is no admin UI to create the mapping, so the confirm action 422s in every company today. InstaPay/Wallet confirmed real (not fabricated) for collection; physical handover is correctly cash-only by design (other channels never travel with the driver). See Real Gaps. |
| N | Fleet | **COMPLETE** | Full CRUD/lifecycle/documents/maintenance/fuel/inspections for Fleet/Vehicles/Drivers, tenant-scoped. Consolidated into one workspace's tabs — a deliberate IA decision (old per-feature nav section is now an intentional empty stub), not a regression. |
| O | Zones/configuration | **COMPLETE** (3 of 4 stacks) | Logistics Geography (governorates/cities/aliases), Distribution Zones, and Master Geography/Zones are all real and correctly layered (not accidentally duplicative). A **fourth** stack (Brand-level "Delivery Geography/Zones") is live-mounted in the UI but calls backend routes that don't exist anywhere — see Real Gaps. |
| P | External carriers | **COMPLETE** (for the business question asked) | `ShippingCompany` (with a real `TYPE_EXTERNAL`, contracts, tenant-scoped mappings) plus a real quote/rate engine (`ShippingQuoteController`) already answer "can the business hand a shipment to an outside carrier and quote a rate." The newer `CarrierAccount` adapter framework is sound architecture for a *future* live external-carrier API integration (tracking sync, webhooks) but currently only ships an "Own Fleet" adapter — see Product Decisions. |
| Q | Map/tracking | **COMPLETE — REAL AUTHORITY CONFIRMED** | Definitive, not inferred: `DriverLocationPing` is a genuinely persisted, timestamped GPS time-series (`distribution_driver_location_pings`), written by a real driver-side endpoint (`POST /driver/trips/{id}/gps`, deduped/config-gated), read with an explicit freshness flag (`fresh`/`stale`, never a substituted position), and rendered by a Leaflet canvas that plots only trips with a real recorded location. Backend tests for this exist but are documented in-repo as never executed — a coverage caveat, not a code-correctness one. |

---

## NO DUPLICATE ENGINES — CONFIRMED (ticket §4)

| Engine | Verdict |
|---|---|
| Trip engine | **One**, canonical (Distribution's Group/Trip pipeline + ad-hoc `TripController`). No duplicate. |
| Loading engine | **One live** (`GroupLoadingWorkspaceController`/`DriverLoadingController`/`LoadProductAction`). A large *dead* HTTP surface sits alongside it in `Operations\Loading` (never called) — not a competing engine, just unused code; see Dead-Code Inventory. |
| Custody engine | **One** (`VehicleInventoryService::recordLoad/reconcileReturn`), structurally isolated from Settlement. No duplicate. |
| Return engine | **One** (`ReceiveVehicleReturnAction`), reused, not modified. No duplicate. |
| Settlement engine | **One** for cash/discrepancy math (`SettlementService`/`DriverDaySettlementReadService`/`TripSettlement`/`CashHandoverService`). A **second**, currently-inert Finance driver-ledger subledger (`DriverFinanceService`/`DriverLedgerEntry`) exists in parallel with **zero production callers** and an **inverse sign convention** from Distribution's own figures — not a duplicate *today* (nothing populates it, so nothing can yet disagree), but a designed-in collision risk if wired up naively later. Flagged as a product decision, not implemented. |
| Driver assignment engine | **One live** (Distribution Group/Trip pipeline). `Operations\Loading`'s own assignment endpoints are fully dead (zero callers, HTTP or in-process). |
| Delivery/execution engine | **One live** (`DriverRuntimeController`+`DeliveryService`). Two *superseded* parallel systems found (`Distribution\DeliveryController` — mostly dead-by-design; the whole `Logistics\Delivery` module incl. `DeliveryOsController` — fully built, deliberately shelved by a later redesign, zero live callers). Neither competes with the live engine today. |
| Dispatch "engine" | **No live warehouse-side Dispatch UI survives at all** — both the `Logistics\Dispatch` V2 module and `Operations\Loading`'s dispatch action are orphaned (see Dead-Code Inventory). The actual capability (vehicle leaves warehouse) is delivered by the driver-initiated Start Trip action inside the live Driver execution engine, not a separate Dispatch engine. |

---

## REAL GAPS

Three concrete, code-level issues, none touching Shipping's core architecture — all safe to combine into one consolidated closure task:

1. **Treasury Cash Handover cannot succeed in any company today.** `CashHandoverService` posts against the `driver_cash_clearing` account role (`backend/Modules/Logistics/Distribution/Domain/Services/CashHandoverService.php:63`), but that role is never seeded by any migration/seeder (`AccountRoleSeeder.php` seeds `cod_clearing`/`driver_receivable`/`driver_shortage_recovery`, not this one), and there is no admin UI anywhere to create an account-role mapping. Every confirm attempt hits `AccountRoleResolver::resolve()`'s `accountRoleNotMapped()` exception, surfaced as a 422. The feature (service, controller, frontend panel) is otherwise correct and complete — this is a missing seed + a missing (small) admin mapping UI.
2. **A live Brand Configuration UI tab calls a backend that does not exist.** `DeliveryShippingWorkspace` (mounted in `brand-configuration-page.tsx:468`, reachable from any Brand's Configuration OS page) calls `/brands/{brandId}/geographies...` (`configuration-service.ts:103-154`) against `DeliveryGeographyController`/`DeliveryZoneController`, neither of which has **any** route registered anywhere in `backend/routes/api.php`. Every action in that tab 404s. Given three other real, working geography stacks already exist (Logistics Geography, Distribution Zones, Master Geography/Zones), the fix is most likely to point this tab at the correct existing stack (probably Master Geography/Zones, which this tab's own data shape — `master_zone_id` — already references) rather than building a fourth backend.
3. **Nav-gate vs. API permission mismatch on Shipping Orders.** The sidebar entry requires `logistics.shipping.view` (`module-navigation.ts:202`) while the API route itself requires `logistics.distribution.view` (`api.php:2171`). Needs a one-line role-matrix check: if every role that has one permission already has the other, this is a non-issue; if not, some users will see a nav link that 403s (or the reverse).

None of these require a new engine, a schema redesign, or a business-policy call — they are wiring/seeding fixes to already-correct code.

---

## PRODUCT DECISIONS REQUIRED

Exact open questions, none implemented, per instruction:

1. **Pool-flow warehouse-stock-decrement timing** (custody/Loading). Group flow decrements stock at load-time; Pool flow defers to dispatch-time via a mechanism (`ShipOrderInventoryAction`, FIFO/COGS-coupled) that cannot safely be changed to load-time without breaking dispatch outright (confirmed this morning, re-confirmed this pass). Newly relevant context: Pool-flow dispatch's only trigger has zero callers anywhere today, so this may be moot if Pool is not actually an active operating mode — **recommend the CTO first confirm whether Pool flow is still a live business requirement** before scoping any fix here.
2. **Should a confirmed Treasury cash-handover shortfall automatically (or via approval) become a Finance driver-liability posting?** Finance's `DriverFinanceService::recognizeDriverShortage()` exists, fully built, specifically to consume this fact, but nothing calls it — its own docblock says it's waiting on "a future listener, once Logistics exposes a real approval event; none exists today." This is the same class of decision as #3 below and should be made alongside it.
3. **Does cash-settlement final-close require physical-return reconciliation to complete first, or are they independent?** `SettlementService` never checks `VehicleShiftReconciliation` — genuinely undecided, not a bug.
4. **Is a real external-carrier API integration (Aramex/Bosta/etc.) needed now, or is "Own Fleet + external ShippingCompany contracts" sufficient for the current business need?** The `CarrierAccount` adapter framework is ready to receive a concrete adapter whenever this is decided; nothing needs to change until then.

---

## TEST_ONLY

None. Every item that a prior pass had left as "not independently re-traced" (retry behavior) was resolved definitively this pass via source tracing *and* existing passing tests — no remaining item depends on a runtime/DB verification this task's policy defers. (One honest caveat, not a blocker: the Live Map/GPS feature's own backend tests are written but documented in-repo as never executed — a coverage gap, not an unverified-capability claim, since the capability itself was confirmed via direct code tracing of the write/read/render chain.)

---

## ALREADY COMPLETE

A, B, C, D, E, G, H, I (Group flow), J, K, L, N, O (3 of 4 stacks), P (for the business question asked), Q — i.e. the large majority of the lifecycle. See matrix above for citations.

---

## DEAD-CODE INVENTORY (cleanup candidates — not capability gaps, not proposed as implementation work)

Confirmed via zero-caller grep evidence (frontend and in-process), listed for CTO awareness/future housekeeping only — none of this blocks or represents missing capability, since a live replacement already exists for each:

- `Operations\Loading`: `DriverAssignmentController::store`, `VehicleAssignmentController::store`/`dispatch` (→ `DispatchVehicleAction`, zero callers anywhere), `LoadingSessionController`'s entire direct HTTP lifecycle (`store/show/open/startLoading/completeLoading/cancel/close` — the real lifecycle runs in-process via other services), `CancelLoadingSessionAction`/`CloseLoadingSessionAction` (zero callers at all, in-process or HTTP), `LoadingDashboardController::index`, `LoadingExceptionController` (all 3 methods), `AllocationController::startAllocation/completeAllocation/override`.
- `Logistics\Dispatch` V2 module in full (`DispatchController`, `DispatchOperationsController` + 3 frontend pages) — deliberately retired in a prior "Shipping OS Redesign" (router comment names 19 retired direct-routes); all 3 URLs now redirect, nav `GATE` keys exist with no matching nav-array entries.
- `Logistics\Distribution\DeliveryController` (aliased `DistributionDeliveryController`) — only 1 of its routes is still registered; the rest were deliberately left unrouted per an explicit route-block comment noting supersession.
- `Logistics\Delivery` module in full (`DeliveryOsController` + Attempt/Cod/Pod/Return controllers, real code, real DB model) — fully built, zero live callers, traced to a specific prior commit (`aa241de7`) that redirected its route away as part of an IA consolidation.
- `frontend/src/features/operations/distribution-board/*` (service + 4 pages: Loading Dashboard, Loading Workspace, Dispatch Gate, Dispatch Gate Workspace) — its backend (`/api/distribution/*`) does not exist anywhere in this codebase; every call 404s; router already redirects away; its only nav entry lives in the separately-dead `frontend/src/config/navigation.ts` (zero importers anywhere in the frontend — the live nav config is exclusively `module-navigation.ts`).
- `backend/Modules/Logistics/Distribution/Presentation/Http/Controllers/DistributionPlanningController.php` + its 1013-line frontend page — a superseded zone-readiness engine, still routed and still fully built, but hard-redirected away with an explicit code comment: *"CTO retirement decision pending."* This is a literal, pre-existing invitation for exactly the kind of decision this reconciliation was commissioned to produce.
- `frontend/src/features/admin/configuration/pages/egypt-geography-page.tsx` (Master Zone CRUD UI) — fully working backend, never mounted anywhere in the app; shares its exact component name with an unrelated, live page of the same name in a different feature folder (naming-collision risk for future developers).

**None of the above is proposed as OPS-02 implementation work.** It is listed because the ticket asked whether duplicate engines exist — they do, in the form of dead code, not competing live behavior — and because two items on this list (`DistributionPlanningController`'s retirement, and whether to formally delete vs. document the `Operations\Loading`/`Logistics\Dispatch`/`Logistics\Delivery` dead surfaces) are themselves small CTO calls, not engineering decisions.

---

## PROPOSED OPS-02 IMPLEMENTATION

**TASK 1 — CONSOLIDATED SHIPPING CLOSURE**: Fix the three Real Gaps above:
(a) seed the `driver_cash_clearing` account role + add the minimal admin UI/seeder path needed to keep it configurable per company, so Treasury Cash Handover can actually complete;
(b) re-point (or remove) the Brand Configuration "Delivery Geography/Zones" tab so it stops calling a nonexistent backend — most likely by wiring it to the existing Master Geography/Zones stack it already conceptually matches;
(c) resolve the Shipping Orders nav-gate vs. API-permission string mismatch (either align the two permission strings, or confirm via the role matrix that no real user is affected and close as a false alarm).

All three are same-conceptual-bucket ("finish wiring something already built correctly"), independently small, and safe to land together. No new engine, schema, or business policy is required for any of them.

Optional, lower-priority, same-task addendum: formally document-or-delete the dead-code inventory above, if/when the CTO wants that housekeeping done — not required for Shipping to be considered functionally complete.

---

## IMPLEMENTATION TASK COUNT: **1**

(No Task 2: nothing found rises to a genuinely separate architecture/business engine — the Finance/Distribution ledger question and the Pool-flow stock-timing question are both product decisions, not engineering gaps, and are listed above rather than scoped as work.)

---

## RECOMMENDATION: **READY FOR ONE CLOSURE TASK**

Shipping/Distribution is, as the CTO expected, already functionally complete across essentially the entire lifecycle — order intake through settlement, including real GPS tracking and a real (if currently mis-wired) external-carrier/quote layer. The three Real Gaps are narrow, mechanical, and already-scoped; the four Product Decisions are genuine business calls that no amount of additional engineering investigation will resolve. No Shipping OS rebuild, and no second engine of any kind, is warranted.

---

## CONFIRM

- No Shipping rebuild performed or proposed.
- No duplicate engines created; the dead-code inventory above is pre-existing, not introduced by this task.
- No OPS-02 implementation performed in this pass.
- NEXT back-synced by fast-forward merge only (no rebase, no history rewrite).
- No runtime tests executed.
- No DB touched.
- No push performed (branch sits 37 commits ahead of `next/feature/operations-v1.1`, unpushed).
- `E:\ECOS\ECOS-NEXT` (redesign/v1.1) — read/inspected only (git log/status/reflog), never written to.
- `E:\ECOS\ECOS-V1-STAGING` — untouched, not visited.

## STOP

For CTO review.
