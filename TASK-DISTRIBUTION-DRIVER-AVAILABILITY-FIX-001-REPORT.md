# TASK-DISTRIBUTION-DRIVER-AVAILABILITY-FIX-001 — REPORT

STATUS: IMPLEMENTED

DONE:
- One canonical engagement predicate (`TripService::assignmentsEngagedElsewhere`) used by BOTH the read and the write path.
- READ (`GET …/fleet-options`): a driver/vehicle pairing engaged by a live trip on another Group is no longer offered.
- WRITE (`GroupVehicleAssignmentService::assign`): the same pairing is refused, fail-closed, inside the assignment transaction — with a pairing-row lock so two concurrent cross-group assigns cannot both pass.
- Current-Group idempotency preserved (a pairing engaged on its OWN group stays selectable and re-assignable).
- Focused tests added to `GroupVehicleAssignmentTest` (CASES 1–6) + the existing suite as the regression net.

NOT DONE (deliberately out of scope — see §14, §16, §17):
- Vehicle master-status filtering in the selector (an `out_of_service` / `maintenance` vehicle with capacity > 0 is still listed). This is a pre-existing, SEPARATE gap and requirement 6 says "add trip engagement rather than replacing existing vehicle rules", so it is not touched here.
- No repair of the live triple-booking (pairing 209 / TRP-001·002·003). Untouched by explicit instruction (§9/§10).
- No database migration / unique index (§10) — the application-level guard + row lock hold the invariant.

NO LONGER NEEDED:
- Nothing was removed or superseded. The change is purely additive.

NEXT:
- Owner decision on repairing the pre-existing triple-booking (pairing 209) in a separate, authorized data task.
- A separate small task to make the selector honor vehicle master status (exclude non-dispatchable vehicles), if desired.

> Not Browser Verified. Not Certified. Not committed / pushed / deployed.

---

## 1. Root Cause

The driver/vehicle **availability** decision consulted only static/master attributes and never the operational trip state:

- vehicle "available" ⇔ `capacity_orders > 0` (a capacity/config number);
- driver "available" ⇔ `status = active` (an employment status);
- `distribution_trips` was never consulted, and `driver_vehicle_assignment_id` carries only a plain (non-unique) index.

So a pairing already attached to a live (non-terminal) trip was still both **offered** by `fleet-options` and **accepted** by `assign-vehicle` for another Group. (Diagnosed in TASK-DISTRIBUTION-DRIVER-AVAILABILITY-DIAGNOSIS-001; root cause **D** — trip/assignment state absent from the availability logic.)

## 2. Before Behavior

- `fleet-options` for Group B listed a vehicle whose active pairing was mid-trip for Group A, and offered its driver under `driver_ids`.
- `assign-vehicle` for Group B accepted that pairing and bound it to a second Group's trip — no conflict raised.
- Live proof: pairing 209 (driver 396 ↔ vehicle 580, plate "1336") bound to three concurrent `loading` trips on DG-001 / DG-003 / DG-004.

## 3. After Behavior

- `fleet-options` withholds an engaged pairing: the busy driver is dropped from that vehicle's `driver_ids` (the vehicle still lists, but the busy combination is not selectable).
- `assign-vehicle` refuses an engaged pairing with HTTP 422 (`FleetAssignmentException::pairingEngagedElsewhere`), even if the selector is bypassed.
- Both use the **same** predicate, so the drawer can never present a combination the write path would reject.

## 4. Canonical Availability Rule

`TripService::assignmentsEngagedElsewhere(array $assignmentIds, ?string $currentGroupId, bool $lock = false): array`

Returns the subset of pairing ids that are **ENGAGED**:

```
ENGAGED(pairing, currentGroup) :=
  ∃ distribution_trip t :
      t.driver_vehicle_assignment_id = pairing.id
      AND t.status ∈ TripStatus::nonTerminalValues()      -- derived from isTerminal()
      AND t.virtual_slot_id IS NOT NULL
      AND t.virtual_slot_id <> currentGroup                -- another Group
```

- "Non-terminal" is `TripStatus::nonTerminalValues()`, **derived from** the existing `TripStatus::isTerminal()` (Closed / Cancelled) — no second status list is invented.
- "Another Group" excludes a trip on the current group (idempotent re-entry) and a group-less ad-hoc trip (outside this rule by definition).

## 5. Read Path Change

`DistributionWindowController::groupFleetOptions` → `activeDriverUuidsByVehicleId($vehicleIds, $currentGroupId)`:
the helper now also selects the pairing `id`, calls `assignmentsEngagedElsewhere(...)`, and **skips** any pairing engaged on another Group when building each vehicle's `driver_ids`. The flat `drivers` roster and the vehicle listing are unchanged (a certified contract), so the payload shape is identical — only busy combinations are withheld. The existing frontend renders this without modification.

## 6. Write Path Change

`GroupVehicleAssignmentService::assign` (inside the existing `DB::transaction`, after the pairing is resolved and before the trip is linked):
1. lock the resolved pairing row (`DriverVehicleAssignment … lockForUpdate`);
2. call `assignmentsEngagedElsewhere([$assignment->id], $group->id, lock: true)`;
3. if non-empty, throw `FleetAssignmentException::pairingEngagedElsewhere` → controller maps it to 422 (existing mechanism).

No new permission, no new lifecycle state, order-assignment rules untouched.

## 7. Current-Group Idempotency Behavior

The predicate excludes trips on the **current** group (`virtual_slot_id = currentGroup`), so:
- re-opening a group's drawer still offers the pairing it already holds;
- re-assigning the same pair to the same group resolves/reuses the existing trip (no conflict, no second trip, no second pairing) — the certified idempotency contract is preserved.

## 8. Vehicle/Driver Status Interaction

- The engagement signal is the **non-terminal trip**, NOT `Vehicle.status` — an `assigned` vehicle (which every paired vehicle is) stays available, exactly as required. `VehicleStatus` behavior is untouched.
- Driver employment status is preserved: the existing `status = active` filter still hides inactive/archived drivers (CASE 6).
- Asymmetry noted honestly: driver status *is* already filtered by the selector; vehicle master status is *not* (see §14).

## 9. Database/Schema Impact

None. No migration, no column, no index. The invariant is held at the application layer by the availability predicate plus a **row lock on the already-existing pairing row** — justified because the pre-existing transaction locks only the Group, so two different Groups claiming one pairing would never contend without it (requirement 4).

## 10. Live Data Handling

No live (`ecos_dev`) business data was mutated. Pairing 209 and trips TRP-001/002/003 were left exactly as found. No driver/vehicle released, no trip status changed, no assignment detached. Write verification ran only on the isolated `RefreshDatabase` test schema.

## 11. Focused Verification Results

Run through the mandatory shared-DB isolation gate inside the testrunner
(`GATE_WAIT=2400 scripts/test-gate.sh tests/Feature/Logistics/GroupVehicleAssignmentTest.php`,
schema `ecos_dev_test`, `RefreshDatabase`):

```
PHPUnit 11.5.55 — PHP 8.4.24
....................                                              20 / 20 (100%)
OK (20 tests, 187 assertions)   [gate acquired + released; exit 0]
```

15 pre-existing tests (the regression net) + 5 new. Mapping to the required cases:

| Case | What it asserts | Result |
|---|---|---|
| CASE 1 | idle valid pairing IS offered | ✅ `test_case1_an_idle_pairing_is_offered` |
| CASE 2 | pairing engaged on another group NOT in fleet-options | ✅ `test_case2_and_3_…` (READ half) |
| CASE 3 | assigning that pairing via backend is rejected (422), no trip left behind | ✅ `test_case2_and_3_…` (WRITE half) |
| CASE 4 | pairing with only terminal trips is available again (read + write) | ✅ `test_case4_…` |
| CASE 5 | pairing engaged on the CURRENT group stays available; re-assign idempotent (no 2nd trip) | ✅ `test_case5_…` |
| CASE 6 | inactive driver not offered (roster + driver_ids) | ✅ `test_case6_…` |
| CASE 7 | vehicle out-of-service unavailable | ⚠️ Pre-existing behavior — vehicle master status is NOT a selector gate today; out of scope (see §14). NOT claimed as fixed. |
| CASE 8 | one-order-per-trip guard unchanged | ✅ by construction — `TripService::assignOrder` untouched |
| CASE 9 | no duplicate trip/assignment on repeated valid assignment | ✅ existing `…same_valid_pair_twice_is_idempotent` + CASE 5 |
| CASE 10 | existing assignment behavior otherwise unchanged | ✅ all 15 pre-existing tests (cross-tenant, capacity, ledger, uuid, reuse) green |

## 12. Files Changed

| File | Change |
|---|---|
| `backend/Modules/Logistics/Distribution/Domain/Enums/TripStatus.php` | + `nonTerminalValues()` (derived from `isTerminal()`) |
| `backend/Modules/Logistics/Distribution/Domain/Services/TripService.php` | + `assignmentsEngagedElsewhere()` — the one canonical predicate |
| `backend/Modules/Logistics/Distribution/Domain/Services/GroupVehicleAssignmentService.php` | write guard + pairing-row lock inside the assign transaction |
| `backend/Modules/Logistics/Distribution/Presentation/Http/Controllers/DistributionWindowController.php` | inject `TripService`; `activeDriverUuidsByVehicleId` skips engaged pairings |
| `backend/Modules/Logistics/Drivers/Domain/Exceptions/FleetAssignmentException.php` | + `pairingEngagedElsewhere()` factory |
| `backend/tests/Feature/Logistics/GroupVehicleAssignmentTest.php` | + CASES 1–6 and a `fleetOptions()` helper |

Worktree tracking state (this worktree carries large pre-existing uncommitted work): `TripStatus.php` and `TripService.php` were already tracked+modified; `GroupVehicleAssignmentService.php`, `DistributionWindowController.php`, `FleetAssignmentException.php` and the test file are still **untracked** (the VP-1 assignment feature is not yet committed). My edits layer additively onto that existing uncommitted state; nothing was committed, staged, or reverted.

## 13. Existing Functionality Preserved

- Cross-tenant rejection, capacity (order-count) rejection, canonical-ledger write, uuid contract, pairing reuse, same-group idempotency — all covered by the pre-existing tests in the same file, run as the regression net.
- One-order-per-trip guard (`TripService::assignOrder` → `orderAlreadyOnAnotherTrip`) is not touched.

## 14. Known Limitations

- **Vehicle master status is still not a selector gate.** An `out_of_service` / `maintenance` vehicle with `capacity_orders > 0` is still listed by `fleet-options` (and not blocked at assign time). This is a pre-existing, separate defect; requirement 6 scoped this task to trip-engagement ("add trip engagement rather than replacing existing vehicle rules"), so it is intentionally left for a follow-up. CASE 7 therefore reflects existing behavior, not a new guarantee.
- The guard is application-level. Under pathological concurrency a DB partial-unique index would be a stronger backstop; it was deliberately NOT added (§10, requirement 10).

## 15. What Is NOW DONE

The confirmed bug is fixed: a driver/vehicle pairing engaged by a live trip on another Group is neither offered by the selector nor accepted by the assignment endpoint, while the same pairing remains usable for its own group and once its trips go terminal.

## 16. What Remains

- Owner decision + separate authorized task to remediate the pre-existing triple-booking (pairing 209).
- Optional follow-up: selector to honor vehicle master status.
- Optional follow-up: DB partial-unique backstop, if the application guard is judged insufficient.

## 17. Recommended Next Task

`TASK-DISTRIBUTION-PAIRING-209-DATA-REPAIR-001` — decide and (if approved) collapse the three concurrent DG-001/003/004 trips on pairing 209 to a single legitimate assignment, in the live `ecos_dev` data, under explicit authorization.
