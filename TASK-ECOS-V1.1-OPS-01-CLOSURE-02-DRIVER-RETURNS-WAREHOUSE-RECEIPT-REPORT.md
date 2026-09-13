# OPS-01 CLOSURE 02 — DRIVER RETURNS + WAREHOUSE RECEIPT — ENGINEERING REPORT

## STATUS: **SOURCE COMPLETE (RECONCILIATION) — NO NEW SOURCE CHANGES IN THIS PASS**

This closure produced no code diff. The reconciliation this ticket asked for (§2) found that the large majority of what §5–§17 describe **already exists**, built by an earlier, careful redesign (`TASK-ECOS-SHIPPING-OS-REDESIGN-001 — Returns & Settlement`) that reached the same conclusions this pass would have reached independently. The one item that genuinely needed new source (§3, Pool stock timing) surfaced a real cross-module risk serious enough that implementing it blind — with no ability to runtime-test in this task's policy — would very likely have broken order dispatch outright. See §3 below. §4 turned out to already be solved, just in a different place than assumed.

Given that, creating a commit with no content would be empty ceremony; this report **is** the checkpoint deliverable for this pass.

---

## 0. OWNERSHIP / MIGRATION

Workspace `E:\ECOS\ECOS-NEXT-OPS`, branch `feature/operations-v1.1`, HEAD confirmed `24c0889f` before starting; working tree clean. `ListAgents` showed no peer session claiming this worktree (the earlier `ecos-next-ops-*`-named peer from Closure 01 is gone from the roster).

The isolated OPS MySQL migration carried over from the prior ticket was left completely untouched during this task's work, per instruction. It has since **completed successfully** (exit code 0, every migration `DONE`) — recorded here as the supplemental evidence the prior report was waiting on. Now that its command has ended, its container (`ecos-ops01-verify-mysql-044a`) was stopped and removed as the natural conclusion of that lifecycle; the shared `ecos-dev` stack was re-confirmed untouched and healthy throughout.

---

## 1. RECONCILIATION FINDINGS (§2)

### §3 — Pool loading stock timing: **BLOCKED, not a narrow fix**

The Pool flow's dispatch-time decrement is not the same mechanism as the Group flow's load-time transfer:

- Group flow: `DriverLoadingController::confirmReceived()` calls `TransferLoadedStockToVehicleAction` → `ShipStockAction` (`reference_type=vehicle_custody_transfer`, keyed by `loading_task_id`) inside the same transaction as the driver's confirmation.
- Pool/Group-shared dispatch: `DispatchVehicleAction` → `LoadVehicleWorkflow::execute()` → **`ShipOrderInventoryAction`** (`Modules\Commerce\Orders\Application\Actions\ShipOrderInventoryAction.php`) — a completely different, order-centric mechanism, keyed `reference_type=sales_order`. Per order line it: (1) calls `ShipStockAction` itself, which **requires `reserved_qty >= quantity`** ("Cannot ship stock that is not reserved" — `ShipStockAction.php:99-103`); (2) decrements `OrderLine.reserved_qty`; (3) runs FIFO layer consumption (`InventoryLayerConsumptionService::consume()`) to compute real COGS; (4) accumulates `Order.actual_cogs_amount`/`actual_margin_amount` and, once every vehicle carrying the order has dispatched, flips `reservation_status` to `Transferred` with an audit record.

Adding a load-time call to `TransferLoadedStockToVehicleAction` for the Pool flow (the literal reading of §3) would decrement `reserved_qty` to 0 at load time. `ShipOrderInventoryAction`'s own `ShipStockAction` call at dispatch would then hit its `reservedBefore < quantity` guard and **throw**, inside `LoadVehicleWorkflow`'s transaction — meaning `DispatchVehicleAction` itself would fail for every order on that vehicle. This is not a subtle double-count; it is a hard failure of dispatch for the whole flow. It would also silently skip FIFO consumption/COGS for that quantity, since `TransferLoadedStockToVehicleAction` never consumes FIFO layers — `ShipOrderInventoryAction` is the only caller of `InventoryLayerConsumptionService` in this chain.

Making this transition safely requires `ShipOrderInventoryAction`/`LoadVehicleWorkflow` to become aware that a line's stock (and its FIFO consumption/COGS) may already have moved at load time, and skip re-shipping it while still completing the order-level reservation-status/COGS bookkeeping correctly. That is a real design task touching `Commerce\Orders`, not an `Operations\Loading` "narrowest correction" — and it is exactly the kind of change this task's own testing-policy deferral (no MySQL/PHPUnit run) makes too risky to attempt blind. **Not implemented.** Recommend a dedicated, jointly-scoped task with Commerce\Orders ownership, verified with real dispatch-flow tests before merge.

### §4 — Driver/Vehicle identity contract: **already implemented — in a different place than assumed**

Closure 01 investigated `Operations\Loading`'s own HTTP endpoints (`AssignVehicleRequest`/`AssignDriverRequest`, `VehicleAssignmentController::store`/`DriverAssignmentController::store`) and found bare, unvalidated UUIDs with no confirmed mapping. That was the wrong place to look: **those two `store` endpoints have zero frontend callers** — confirmed by a dedicated trace of the real UI.

The real, live vehicle+driver assignment flow is Distribution's Group/Trip pipeline:
- `frontend/src/features/logistics/distribution-workspace/components/group-vehicle-assignment.tsx` — vehicle/driver options come from `useGroupFleetOptions()`, and **driver choices are already filtered by the selected vehicle**: `eligibleDrivers` intersects all drivers with the selected vehicle's `driver_ids`, the vehicle-change handler clears an invalidated driver selection, and the driver `<Select>` is disabled until a vehicle is chosen. This is fully built, not a stub.
- Backend: `DistributionWindowController::groupFleetOptions()` (`backend/Modules/Logistics/Distribution/Presentation/Http/Controllers/DistributionWindowController.php:1026`) publishes `Logistics\Vehicles\Vehicle`/`Logistics\Drivers\Driver` rows keyed by their **`uuid`** column (never the internal bigint — `:1112`, `:1131`), so the id the frontend calls `vehicleId`/`driverId` genuinely is the canonical `uuid`.
- The pairing itself is written by `GroupVehicleAssignmentService::assign()` against `DriverVehicleAssignment` (the same canonical, bigint-keyed table identified in Closure 01) — the real Vehicle↔Driver relationship the ticket asked me to find and enforce against.
- When "Start Loading" is pressed, `GroupLoadingContextService::open()` calls `AssignVehicleToSessionAction::execute()` **in-process** (not via the dead HTTP route) with `vehicleId: (string) ($vehicle->uuid ?? $vehicle->id)` — confirming `Operations\Loading\VehicleAssignment.vehicle_id` genuinely is `Vehicle.uuid` for the live flow.

**A genuine, separate gap found in the same trace:** `GroupLoadingContextService` never calls `Operations\Loading\AssignDriverAction` at all — confirmed by grep (its only caller, `DriverAssignmentController::store`, is itself uncalled by any frontend code) and by an explicit comment in `GroupVehicleAssignmentService.php:235-240` acknowledging exactly this. `Operations\Loading\DriverAssignment` is therefore never populated for live (Group/Trip) sessions. This is **benign**, not a correctness bug: the only consumer that would hard-fail without it, `DispatchVehicleAction`, is a Pool-flow-only action never invoked by the Group/Trip completion path (`DriverLoadingController::complete()` has its own separate completion route). `LoadVehicleWorkflow` reads it defensively (`?->driver_id`, nullable, used only for an event payload/audit log).

**Conclusion: no new enforcement code needed.** The constraint the ticket asked for already exists, correctly, in the code path that actually runs. `Operations\Loading\AssignDriverAction`/`DriverAssignmentController::store`/`VehicleAssignmentController::store` are dead code for this purpose — flagged as a cleanup candidate for a future task, not touched here (removing dead code wasn't asked for, and touching it risks confusing a future reader who assumes it's load-bearing).

### §5 — Retryable delivery outcomes preserve history: consistent, not independently re-traced end-to-end

`Order.status` remains `FulfillmentEngine`-guarded (Closure 01 architecture finding, unchanged); nothing observed in `DeliveryStop`/`TripReturn`/`VehicleInventoryItem` models has delete/overwrite behavior — every adjacent history mechanism seen in this codebase (`LoadingTaskAdjustment`, `DriverVehicleAssignment`) is deliberately append-only. No code path was found that clears old custody or deletes a prior attempt when a new execution starts. This is consistent with the required rule but was not proven by a dedicated runtime trace (that would need the deferred testing pass).

### §6 — Expected Driver Returns: **already correctly gap-carded**

`frontend/.../returns-settlement/components/expected-returns-tab.tsx` already states, in its own docblock, the same finding this reconciliation would have made: there is no operator-facing aggregate over `TripReturn` today, and computing one client-side would mean inventing a shortage rule this workspace has no authority to define — so it shows a `GapCard` (named gap + working deep link), never a fake number. This **is** the "if unavailable, show unavailable/incomplete" behaviour §6 asks for.

**Possible future improvement, not attempted here:** the data to build a real aggregate (`VehicleInventoryItem.quantity_on_hand` for still-on-vehicle, `VehicleShiftReconciliationLine.quantity_accepted/damaged` for received-good/damaged, `TripReturn.discrepancy_qty` for declared shortage) is more complete now than when that redesign task ran. Building it is a real, scoped, separate task — not attempted blind at the end of this one.

### §7 — Physical trip return vs. warehouse receipt: **already correctly separated**

`DeliveryService::confirmReturn()` (`TripReturn`'s own confirm) only ever updates `TripReturn`'s own columns (`warehouse_confirmed_qty`, `discrepancy_qty`, `driver_liable`) — verified by reading the full method body. It never touches `InventoryItem`, `VehicleInventoryItem`, or any stock table. `ReceiveVehicleReturnAction` remains the sole authority that moves real stock. The two are already independent, exactly as required.

### §8 — Warehouse Receipt: **already implemented and correctly scoped**

`ReceiveVehicleReturnAction` (Closure 01) plus its read path (`GET .../reconciliation`, `VehicleShiftReconciliationLineResource`) already give the warehouse operator good/damaged/missing distinctions. `warehouse-receipt-tab.tsx` is an honest gap card for the *cross-session* rollup only (no such aggregate endpoint exists), linking to the real per-assignment workspace where the actual receipt happens today. No fabricated numbers found.

### §9 — Custody release invariant: **confirmed, not violated**

`SettlementService.php` (`Modules\Logistics\Distribution\Domain\Services`) has zero references to `VehicleInventoryItem`/`quantity_on_hand` — grepped directly. Settlement structurally cannot clear or reduce custody. The only writers of vehicle custody found anywhere in this and the prior reconciliation remain `VehicleInventoryService::recordLoad/reconcileReturn`, both gated by real physical evidence (`LoadProductAction`, `ReceiveVehicleReturnAction`).

### §10 — Loaded/Delivered/Remaining: unchanged from Closure 01

Same conclusion as before: the safe, already-fixed piece (`loading_pct` null-vs-zero) is in place from the prior checkpoint (`24c0889f`). A full session-level Delivered/Remaining aggregation across custody + `AllocationRecord` was not attempted here either, for the same reason as §6 — it would be new cross-model arithmetic with no way to verify it under this task's deferred-testing policy, and the existing UI already prefers an honest gap card over inventing one.

### §11 — Goods/Reconciliation consistency: **confirmed — no fake zeros found**

All four non-trivial tabs in `returns-settlement` (`expected-returns`, `warehouse-receipt`, `return-discrepancies`, and by extension the pattern) use `GapCard` rather than presenting `0`/empty when the backing aggregate doesn't exist. `returning-to-warehouse-tab.tsx` and `driver-settlement-tab.tsx` are the two tabs backed by genuine aggregates (`GET /logistics/distribution/trips?status=completed`, `GET /logistics/distribution/driver-settlement`) and show real data, not zeros. No redesign performed or needed.

### §12 — Collection difference boundary: not independently re-derived; no change made

`SettlementService.php` was checked and contains no reference to a "collection discrepancy" concept by name; the cash-expected figures shown in `driver-settlement-tab.tsx` come from `DriverDaySettlementReadService`/`DriverDaySettlementQuery`, which were not fully traced in this pass given the explicit instruction not to implement or alter Settlement/Finance policy here. No evidence of a violation was found, and — per §12's own instruction — nothing was changed.

### §13 — Settlement final-close policy: **unchanged, as instructed**

Confirmed via Closure 01's architecture finding and re-confirmed here (`SettlementService` never references `VehicleShiftReconciliation`): the question "should cash settlement block on physical-return reconciliation" remains open and undecided. No implementation attempted.

### §14, §15, §16 — Historical safety / tenant isolation / idempotency

No shared DEV data read or mutated in this pass (only the isolated OPS container, now removed). Tenant scoping spot-checked and confirmed at `VehicleShiftReconciliationService` (writes `company_id` from the owning assignment/item) and `DeliveryController::resolveTrip()` (company-scoped trip resolution, already fixed under TASK-DRIVER-02 per its own comment). `ReceiveVehicleReturnAction`'s idempotency (same-split no-op, different-split refused) reconfirmed unchanged from Closure 01 — not modified.

### §17 — Frontend/operator workspace

The `returns-settlement` workspace already gives operators driver/vehicle/trip context, expected/received/damaged/missing distinctions (where an authority exists), and receipt state, via the existing tabs. No further UI changes made.

---

## RETURNS_CLOSURE_SHA: **NONE** (no source diff produced by this pass)

## POOL STOCK TIMING

- Previous behaviour: warehouse `on_hand`/`reserved` decrement deferred to `DispatchVehicleAction`→`LoadVehicleWorkflow`→`ShipOrderInventoryAction`, keyed `reference_type=sales_order`.
- Attempted final behaviour: none implemented.
- No-double-deduction proof: **not applicable** — blocked before implementation once the `ShipOrderInventoryAction` reservation/FIFO/COGS coupling was found; implementing the literal ask would have caused dispatch to hard-fail, not merely double-count.

## DRIVER / VEHICLE CONTRACT

- Pool id authorities: `Operations\Loading\VehicleAssignment.vehicle_id`/`DriverAssignment.driver_id` — genuinely `Logistics\Vehicles\Vehicle.uuid`/`Logistics\Drivers\Driver.uuid` **for the live Group/Trip flow**; the direct Pool HTTP assignment endpoints that accept bare unvalidated UUIDs are unused (zero frontend callers).
- Canonical mapping/relationship: `Logistics\Drivers\DriverVehicleAssignment` (bigint-keyed, `active_flag`-gated), written by `GroupVehicleAssignmentService::assign()`.
- Filtering/validation result: **already enforced**, in `group-vehicle-assignment.tsx` + `DistributionWindowController::groupFleetOptions()`. No new code required.

## RETURN AUTHORITY

`ReceiveVehicleReturnAction` reused as-is; not modified; still the sole canonical warehouse-receipt writer.

## EXPECTED DRIVER RETURNS

Authority: none dedicated yet (deliberately, per the existing `expected-returns-tab.tsx` gap card). Statuses/quantities: not built in this pass — see §6.

## PHYSICAL TRIP RETURN

Route/action: `PATCH .../returns/{returnId}/confirm` → `DeliveryController::confirmReturn` → `DeliveryService::confirmReturn` (restored at `941089a3`). Separation from warehouse receipt: confirmed real (§7) — it touches no inventory table.

## WAREHOUSE RECEIPT

Good/Damaged/Missing: already distinguished by `ReceiveVehicleReturnAction`/`VehicleShiftReconciliationLine`; not modified.

## CUSTODY

Loaded/Delivered/Returned/Remaining: unchanged from Closure 01 (`loading_pct` fix only); no new aggregation added (§10).

## RETRYABLE DELIVERY OUTCOMES

No Answer/Postponed behaviour and old-custody preservation: consistent with the required rule per model-level inspection; not independently re-traced end-to-end in this pass (§5).

## GOODS / RECONCILIATION

Canonical authority: `returns-settlement` workspace's existing tabs. Fake-zero removal/unavailable handling: already correctly implemented via `GapCard`; nothing to fix.

## TENANT

Boundaries spot-checked at `VehicleShiftReconciliationService` and `DeliveryController::resolveTrip`; both correctly company-scoped. No exhaustive re-audit of every listed area was performed given no code changed.

## IDEMPOTENCY

`ReceiveVehicleReturnAction`'s existing contract reconfirmed unchanged (same-split no-op, different-split refused).

## SETTLEMENT FINAL-CLOSE POLICY

Current source state only: still undecided/unimplemented (`SettlementService` never checks `VehicleShiftReconciliation`). No policy decided or implemented here.

## STATIC SANITY

No files were changed in this pass, so `php -l`/Pint/PHPStan/TypeScript/ESLint have nothing new to check. `git diff --check`: pass (`git status --porcelain` empty before and after this task's investigation).

## OPEN IMPLEMENTATION ITEMS

- Pool-flow warehouse-decrement timing (§3) — needs a joint `Operations\Loading` + `Commerce\Orders` design (how `ShipOrderInventoryAction`/`LoadVehicleWorkflow` recognize stock already transferred at load time, including FIFO/COGS attribution) and a real test pass before any implementation; not safe to attempt without runtime verification.
- `Operations\Loading\AssignDriverAction`/`DriverAssignmentController::store`/`VehicleAssignmentController::store` — confirmed dead code (zero frontend callers); candidate for removal or documentation as legacy, in a future task.
- Expected Driver Returns / session-level Delivered-Remaining real aggregates (§6/§10) — buildable now from canonical data that didn't fully exist when the current gap-cards were written; a real, scoped follow-up task, not attempted blind here.
- §12's collection-discrepancy boundary — not fully traced (`DriverDaySettlementReadService`/`DriverDaySettlementQuery`); no evidence of a violation found, but no exhaustive proof either.

## DEFERRED TO OPS-01 CONSOLIDATED TESTING

Everything already deferred from Closures 01: the full test suites, the completed-but-unexamined-in-depth migration run (now confirmed successful), any MySQL/PHPUnit/browser/integration/security pass.

## CONFIRM

- No duplicate Return engine.
- No direct stock edits.
- No status-based physical inference introduced.
- No settlement-policy change.
- No Closure 03.
- No OPS-02/03/04.
- No push, no merge, no deploy.
- `E:\ECOS\ECOS-V1-STAGING` untouched.

## STOP

For CTO source review. Do not start Closure 03 automatically.
