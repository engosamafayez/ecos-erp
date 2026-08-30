# TASK-DISTRIBUTION-PHASE2-SCOPE-DIAGNOSIS-001 — REPORT

Distribution Phase 2 — Scope & Existing-Capability Audit (read-only diagnosis).

---

**STATUS:** DIAGNOSIS COMPLETE

**DONE:**
- Phase 1 (Distribution Planning workspace UI) — implemented & browser-verified.
- The full operational spine ALREADY EXISTS in code (mostly built by prior tasks, much
  uncommitted): Windows → Groups → Trips → Vehicle/Driver → Finalize → Loading →
  Delivery stops → Settlement, plus the Driver Mobile runtime and the operator
  Driver-Day-Settlement read model.

**NOT DONE:**
- One real end-to-end defect (the stranded-trip trap) blocks a trip from being walked
  from loading → delivery by the driver path.
- Consolidation of duplicate/parallel flows (Loading ×2, Delivery ×2, Assignment ×2,
  Order-dispatch ×2) and removal of dead frontend stacks.
- End-to-end commit + certification of the (uncommitted) Group→Trip→Loading→Delivery→
  Settlement chain.
- Returns hardening (idempotency + inventory write-back).

**NO LONGER NEEDED (do NOT rebuild — already exists):**
- Group lifecycle, order assignment, zone/map behavior, vehicle & driver assignment, trip
  lifecycle, loading integration, driver handoff, delivery execution, per-trip settlement,
  delivered-qty projection, secure POD, day-closing rollup. All present.

**NEXT:**
- A small, consolidation-and-hardening Phase 2 (see §9, §10) — NOT a rebuild. Get owner
  decisions on the duplicate-flow ownership before any wiring.

---

## 1. Phase 1 confirmed baseline

Page: `DistributionWorkspacePage` at `/logistics/distribution/workspace` (nav "Distribution
Planning", via `module-navigation.ts`). Browser-verified
(TASK-DISTRIBUTION-PHASE1-FINAL-BROWSER-VERIFICATION-001): 5-KPI header, Group grid
(4/2/1), group cards, inline Group-Detail section (tabs: Map & Group Details / Orders /
Zones / Vehicle & Driver / Trip — no Loading tab), 60/40 map split, embedded map w/o
toolbar, clickable Google-Maps coordinate link, zone-panel group names, Trip compact,
orders/zones scoped, geocoding read-only, responsive + Arabic RTL. Phase 1 is closed — do
not reopen.

## 2. Existing Distribution capabilities (verified this audit)

Module `Modules\Logistics\Distribution` owns the whole spine (routes in `backend/routes/api.php`):

| Capability | Backend | Frontend |
|---|---|---|
| Group (slot) lifecycle: create / capacity / zone assign·move·remove / finalize→Trip | `DistributionWindowController` + `ManualAssignmentService`, `GroupFinalizationService`, `GroupCapacityGuard` | `distribution-groups-panel`, `group-detail-section`, `group-zone-manager`, `distribution-settings-tab` |
| Group templates (create/apply/archive) | `GroupTemplateController` + `GroupTemplateService` | `distribution-templates-tab` |
| Daily/wave group automation | `DailyGroupLifecycleService`, `StartWaveDistributionGroupsListener` | (server-driven) |
| Order assignment: collect / change zone·slot / batch / late / awaiting-group | `collect()` + `DistributionCollectionService`, `ManualAssignmentService` | Groups/Zones tabs, exceptions panel |
| Zone / map behavior (Phase 1) | aggregation read models | `distribution-map-tab`, `distribution-leaflet-map`, `map-cluster-panel`, `map-order-panel`, `zones-review-table` |
| Vehicle & Driver assignment (VP-1) | `groupFleetOptions()` + `GroupVehicleAssignmentService`, Trip `assignDriverVehicle` → canonical `DriverVehicleAssignment` ledger | `group-vehicle-assignment` |
| Trip lifecycle (full state machine), readiness, reconciliation, acceptance | `TripController` + `TripService`, `TripStatus` | `group-trip-panel`, `trip-readiness-panel`, Trip tab |
| Loading (integrated, group grain) | `openGroupLoading()` + `GroupLoadingContextService`, `GroupLoadingWorkspaceController` (`/loading/groups/*`) | canonical `loading-os` workspace |
| Driver handoff / runtime | `DriverRuntimeController` (`/driver/*`), `DriverLoadingController`, `startTrip` departure walk | `driver-mobile` module |
| Delivery execution + proof/returns | `DeliveryController` + `DeliveryService`, `EnsureStopDeliveryAllocationsAction`, `UploadDeliveryProofAction` | driver-mobile delivery components |
| Settlement (per-trip) + day-closing rollup | `SettlementController` + `SettlementService`; `DriverDaySettlementController` + `DriverDaySettlementReadService` | `driver-settlement` (operator), driver-mobile settlement |

## 3. Existing capabilities delivered by OTHER completed tasks (mostly uncommitted)

These are already built — Phase 2 must NOT re-implement them:
- **Group→Trip→Vehicle→Driver→Finalize** (Option A; `distribution_trips.virtual_slot_id`; capacity split) — live-verified DG-001→TRP-001.
- **Trip departure/dispatch walk** (`startTrip` → LoadingCompleted→Dispatched→InProgress) — TASK-TRIP-LIFECYCLE-AND-VEHICLE-CUSTODY-BRIDGE-001, certified 14/14.
- **Wave→Group automation** (GAP1 auto-create on wave start, GAP4 map/fleet wave isolation) — 54/54.
- **Driver delivery + delivered-qty projection + secure POD** — DELIVERY-ALLOCATION-BRIDGE-001 (`deliver` route + `EnsureStopDeliveryAllocationsAction` reusing `RecordProductDeliveryAction`), delivered_qty projection listener, driver-first secure POD upload; delivery UI wired.
- **Loading OS** group-grain workspace (`/api/loading/*`); loading capacity resolved to order-count.
- **Operator Driver-Day-Settlement** ("تقفيل اليوم") UI + read model over the per-trip `SettlementService`.
- **Fulfillments legacy UI retired**; **Distribution map UX + explicit-geocoding + KPI cleanup** (this workstream).

## 4. Phase 2 candidates (genuinely open work)

1. **End-to-end trip completion defect (stranded-trip trap).** `DriverLoadingController::complete()`
   early-returns when the vehicle assignment is already `LoadingComplete`, *before* the
   finalize/stops bridge — so trips whose assignment completed earlier are stuck at
   `distribution_trips.status='loading'` forever. **Live instance: DEV TRP-003** (the trip
   seen in Phase-1 verification). This blocks a real driver from walking loading→delivery.
2. **Consolidation of duplicate/parallel flows** (see §6) — decision + convergence, not rebuild.
3. **Commit + end-to-end certification** of the uncommitted Group→Trip→Loading→Delivery→
   Settlement chain (currently proven only in pieces, across worktrees).
4. **Returns hardening** — `distribution_trip_returns` has weak idempotency (no unique on
   (trip_id,order_id,product_id,kind)), no inventory write-back on confirm, and
   `VehicleInventoryService::recordReturn` is dead (0 callers).
5. **Dead-code / dead-nav removal** (frontend), see §6.

## 5. Already-complete items that must NOT be rebuilt

Group lifecycle · templates · order collection/assignment · manual zone/slot moves · late
orders · zone & Leaflet map · vehicle/driver assignment (VP-1 + canonical pairing) · trip
state machine, readiness, reconciliation, finalize, capacity split · trip departure walk ·
group-grain loading · driver runtime/handoff · delivery stops + delivered-qty writer +
allocation bridge · secure POD · per-trip settlement engine + payment collections ledger ·
driver-day-settlement rollup. Rebuilding any of these would duplicate working code.

## 6. Conflicting / duplicated functionality (the #1 Phase-2 risk)

**Backend parallel modules (all routed):**
- **Loading — two grains:** Distribution integrated Group loading (`GroupLoadingContextService`,
  `/loading/groups/*`, driver `/driver/loading/*`) **vs** standalone `Modules\Operations\Loading`
  session engine (`/loading/sessions/*`, its own vehicle/driver assignment + allocation +
  `dispatch`). Two vehicle-assignment paths, two "dispatch" verbs.
- **Delivery — two aggregates:** Distribution `DeliveryStop` (`/logistics/distribution/trips/{id}/stops/*`)
  **vs** `Modules\Logistics\Delivery` "Delivery & Tracking OS" (`/logistics/delivery/*`,
  `delivery.*` perms, own PoD/COD/Return controllers). (Delivery OS reads Distribution's
  DeliveryStop and defers cash to "Distribution = Single Cash Authority" — a declared boundary.)
- **Assignment — two paths:** Distribution `GroupVehicleAssignmentService`/`TripService`
  **vs** `Modules\Logistics\Dispatch` ("Logistics V2 — Dispatch (Phase 2)", proposal engine
  that "PROPOSES; V1 COMMITS" back into TripService).
- **Order dispatch — two paths:** `Modules\Operations\Fulfillment`
  (`/fulfillment/orders/{order}/dispatch|complete-delivery|transition`) **vs** the
  Trip/DeliveryStop execution path.
- **Empty retired header:** `api.php:1068` "Distribution Board OS" section has no routes.

**Frontend parallel/dead stacks:**
- **Three distribution "planning" screens:** canonical `distribution-workspace` (active) **vs**
  legacy `distribution-planning` (route-registered, non-navigable; per code comments "3
  endpoints 500, second zone resolver, no tenant filter") **vs** legacy
  `operations/distribution-board` "Distribution OS" (calls `/api/distribution/*` with no
  backend registered). Each is a full parallel hook/service/type/component stack.
- **Dead nav config:** `src/config/navigation.ts` (`NAV_GROUPS`) is unimported dead code
  still advertising Distribution-OS / Loading-OS / legacy planning (superseded by
  `module-navigation.ts`).
- **Dead group-detail cluster:** `group-detail-drawer.tsx` + `group-loading-preparation.tsx`
  + `group-loading-execution.tsx` (Phase-1 overlay + its Loading tab) — unused, superseded
  by inline `group-detail-section.tsx` + the Loading OS workspace.
- **Two Loading UIs:** canonical `loading-os` **vs** legacy `distribution-board` loading pages.
- **Fulfillments** routes now redirect to the workspace; Zones aliased under Geography.

## 7. Missing functionality (true gaps, not duplicates)

- End-to-end trip walk blocked by the stranded-trip trap (§4.1).
- Returns: idempotency + inventory write-back (§4.4).
- Driver reaching the canonical delivery writer for **operator-created allocations** vs
  driver-custody allocations was bridged (DELIVERY-ALLOCATION-BRIDGE-001); confirm it holds
  end-to-end on a real trip during certification.
- No expense engine / driver wallet (only if a Phase-2 requirement is stated — none is).
- Wave→Group GAP2 (collect at wave start) & GAP3 (broaden collection eligibility) are
  **owner-blocked by design** (violate the window cutoff invariant / global-unique + manual
  late-order rule) — not "missing", deliberately reserved.

## 8. Dependencies

- Consolidation decisions (§6) are **owner decisions** and gate everything else — do not
  wire before they are made (which module owns Loading? Delivery? Assignment? Order-dispatch?).
- Certification depends on: a resolvable distribution window (active Preparation Wave +
  `activeWarehouseId`), and the stranded-trip fix (§4.1) to walk a trip end-to-end.
- Test baseline: `DistributionModuleTest` shows ~38 pre-existing 403s (empty `permissions`
  in `ecos_dev_test` for a `DatabaseTransactions` suite) — reproduces in isolation, not a
  regression; do not chase.
- Large **uncommitted surface** across worktrees/containers (files docker-cp'd, not
  committed) — a commit/consolidation step is a prerequisite to trustworthy certification.

## 9. Recommended Phase 2 scope

Phase 2 is **consolidation, wiring-completion, defect-fix, and certification — NOT new build.**
Recommended, in order:

- **P2-A — Duplicate-flow ownership decision (owner):** rule which module owns Loading,
  Delivery, Assignment, and Order-dispatch; mark the others legacy/retire-or-keep-backend.
  (Diagnosis/decision only; no code.)
- **P2-B — End-to-end trip completion fix:** repair the stranded-trip trap so a driver can
  walk loading→delivery→settlement on one trip (targeted backend fix + focused test).
- **P2-C — Frontend dead-code & dead-nav removal:** delete/retire `navigation.ts`,
  `distribution-planning`, `operations/distribution-board`, and the `group-detail-drawer`
  cluster (guarded by the P2-A decision), keeping backend untouched.
- **P2-D — Commit + end-to-end certification:** commit the uncommitted chain and certify
  Group→Trip→Loading→Delivery→Settlement once (on a controlled, authorized dataset).
- **P2-E — Returns hardening (if in scope):** idempotency + inventory write-back
  (backend, owner-gated).

## 10. Recommended task count after consolidation

**5 focused tasks** (P2-A … P2-E). P2-A is a prerequisite decision; B/C can run in parallel
after it; D depends on B; E is optional/owner-gated. Resist splitting further — the codebase
already exists, so more tasks = more re-audit overhead, not more delivery.

## 11. Explicit OUT OF SCOPE

- Rebuilding any spine capability in §5 (all exist).
- Reopening / redesigning Phase 1.
- Wave→Group GAP2 & GAP3 (owner-blocked by design).
- New expense engine, driver wallet, cross-trip cash ledger (no stated requirement).
- Building a *new* Loading / Delivery / Dispatch / Fulfillment module (the problem is too
  many, not too few).
- The separate `Logistics\Delivery` OS and `Logistics\Dispatch` V2 — leave as-is unless P2-A
  designates one canonical; do not extend both.

## 12. Risks

- **Divergence risk:** four sets of parallel flows can drift; a wrong consolidation choice
  entrenches the wrong one. Mitigate via P2-A first.
- **Uncommitted mountain:** much of the spine is docker-cp'd but uncommitted across
  worktrees; a build/commit deploys everyone's WIP together. Certify on a controlled dataset.
- **Data-mutation risk:** certifying end-to-end requires dispatch/loading/delivery mutations;
  `DispatchVehicleAction`/`LoadVehicleWorkflow` decrement stock + write immutable `sales_issue`
  + COGS with zero test coverage — never exercise on real data casually.
- **Test/permission baseline:** RefreshDatabase vs DatabaseTransactions suites collide on
  seeded permissions; ~38 pre-existing 403s are environmental.
- **Login/window preconditions:** the workspace needs an authenticated session + an
  `activeWarehouseId` + an active wave to render populated — verification can be mistaken for
  "empty/broken" without them (as seen in Phase-1 verification).

## 13. Final recommendation

Treat Phase 2 as **"consolidate, fix, certify"** — not "build." The Distribution operational
spine is already implemented end-to-end; the value now is (1) an owner decision on which of
the duplicated Loading/Delivery/Assignment/Order-dispatch flows is canonical, (2) fixing the
single stranded-trip defect that stops a trip completing, (3) deleting the dead frontend
stacks, and (4) committing and certifying the chain once, on controlled data. Start with the
ownership decision (P2-A); everything else depends on it. Do not open Phase 2 implementation
until that decision is recorded.

---

*Diagnosis only. No code, DB, schema, API, or data was modified; no certification/browser
run; no commit/push/deploy. Evidence gathered by read-only code survey (backend module/route
inventory + frontend route/component inventory) cross-checked against prior task records.*
