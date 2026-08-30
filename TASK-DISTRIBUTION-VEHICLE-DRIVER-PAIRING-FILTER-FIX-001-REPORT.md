# TASK-DISTRIBUTION-VEHICLE-DRIVER-PAIRING-FILTER-FIX-001 — REPORT

**Date:** 2026-08-25 · **Branch:** `develop` · **Not committed, not pushed, not deployed.**
**Owner decision applied:** Option **A** with the idempotency clarification (reuse an existing active
pairing; never mint a duplicate; preserve all certified backend contracts and tests).

---

## 1. Root Cause

Three facts combined into the observed defect:

**(a) The API returned every active driver, globally — no vehicle filter existed.**
`DistributionWindowController::groupFleetOptions` (before):
```php
$drivers = Driver::query()->where('status', Driver::STATUS_ACTIVE)->orderBy('full_name')->get()
```
No join to the pairing ledger, no vehicle parameter.

**(b) The frontend rendered that list independently of the selected vehicle.**
`group-vehicle-assignment.tsx` — `drivers = data?.drivers ?? []` and `{drivers.map(...)}`; the driver
`<Select>` never read `vehicleId`.

**(c) The deeper cause — the endpoint PAIRS rather than SELECTS.** `GroupVehicleAssignmentService`
called `DriverVehicleAssignmentService::assign()`, whose job is to *create* a pairing. A global list is
the logical input for "choose who to pair with this vehicle". Critically, that service **refuses** to
re-assign a driver to the vehicle they already hold:
```php
// DriverVehicleAssignmentService.php:79-81
if ($current !== null && $current->vehicle_id === $vehicle->id) {
    throw VehicleAssignmentException::alreadyAssignedToSameVehicle($vehicle->plate_number);
}
```
So the **only valid selection (OSAMA + 1336) returned 422**, and the Group was effectively unassignable
— while the *invalid* unrelated driver was the one being offered.

---

## 2. Canonical Vehicle ↔ Driver Relationship (discovered, not inferred)

**`logistics_driver_vehicle_assignments`** — the single pairing authority.
- Written **only** by `Modules/Logistics/Drivers/Domain/Services/DriverVehicleAssignmentService`.
- `active_flag` = `1` while live, `NULL` once released. DB-enforced uniqueness:
  `driver_one_active_vehicle_unique` (BR-6) and `vehicle_one_active_driver_unique` (BR-7).
- `distribution_trips` carries **no** `driver_id`/`vehicle_id` — only `driver_vehicle_assignment_id`
  (a certified test asserts the Trip must never gain a `vehicle_id` column).
- Chain: `Group → Trip (virtual_slot_id) → driver_vehicle_assignment_id → ledger`.

**Live data matched the report exactly:** 2 drivers, 1 vehicle, 1 active pairing —
`OSAMA FAYEZ AHEMD` (DRV-001) ↔ vehicle plate **1336**; `ahmed` (DRV-002) paired with nothing.

No second relationship table and no new assignment engine were introduced.

---

## 3. Frontend Fix

`frontend/src/features/logistics/distribution-workspace/components/group-vehicle-assignment.tsx`
- `eligibleDrivers` derives from the **selected vehicle's** `driver_ids` (server-decided), intersected
  with the published driver list. Empty before a vehicle is chosen — the list narrows *up front*
  rather than after the fact.
- `onVehicleChange()` clears `driverId` when the new vehicle is not paired with it (**CASE 4**).
- Driver `<Select>` is **disabled** until a vehicle is chosen and when no eligible driver exists;
  placeholder switches to "Select a vehicle first".
- Explicit empty state **"No drivers assigned to this vehicle"** (`data-testid="group-driver-none"`).
- Layout, cards, capacity display, the assign button and ECOS UI language are **unchanged** — only the
  dependency/filtering behaviour.
- `FleetVehicleOption.driver_ids` is optional in the type, so a not-yet-updated backend degrades to
  "no eligible drivers" instead of crashing the drawer.
- EN/AR keys added (`driverSelectVehicleFirst`, `noDriversForVehicle`).

---

## 4. Backend Validation

**(a) Eligibility is server-decided (additive).** `groupFleetOptions` now returns `driver_ids` per
vehicle — the drivers with an **active** ledger pairing to it — via a new private helper
`activeDriverUuidsByVehicleId()`. Driver uuids are resolved **through the `Driver` model**, so the
tenant global scope applies and a pairing pointing at another company's driver is omitted, not leaked
(S-6, fail-closed).

**The flat `drivers` list is deliberately unchanged.** It is a certified contract: one test asserts a
fresh **unpaired** driver is still published there, another asserts a foreign company's list is empty.
Filtering it would have broken both. The selector narrows; the payload does not stop publishing.

**(b) Assignment is idempotent (your clarification).** `GroupVehicleAssignmentService::assign()` now
reuses a live pairing instead of attempting a duplicate:
```php
$assignment = $this->activePairing($driver, $vehicle)
    ?? $this->ledger->assign($driver, $vehicle, ...);
```
- Pairing exists → **reuse** it, attach to the Trip. Running it twice attaches the same ledger row.
- No pairing → **unchanged**: the ledger still creates it, so the certified contract and the Fleet
  screens' own semantics are untouched. This service still owns and writes no pairing itself.
- The change is scoped to the Distribution path only; `DriverVehicleAssignmentService` was **not**
  modified, so "Assign/Change Vehicle" in the Drivers module behaves exactly as before (your point 6).

**(c) Invalid pairs are rejected server-side** by the canonical ledger's own BR-7 guard — verified
live, see §6 CASE 5.

---

## 5. Tests

**Backend — `tests/Feature/Logistics/GroupVehicleAssignmentTest.php` (4 tests ADDED, none altered):**
- `test_fleet_options_lists_only_the_drivers_actively_paired_to_each_vehicle` — paired driver only in
  `driver_ids`; **both** drivers still in the flat list (certified contract).
- `test_fleet_options_reports_no_eligible_drivers_for_an_unpaired_vehicle` — `driver_ids === []`.
- `test_assigning_a_driver_already_paired_to_the_vehicle_reuses_the_pairing` — 200 (was 422), exactly
  one active pairing, Trip references the pre-existing row.
- `test_assigning_the_same_valid_pair_twice_is_idempotent` — still one active pairing.

**Result: `OK (15 tests, 120 assertions)`** — my 4 plus all 11 pre-existing certified tests, including
`test_assignment_writes_the_canonical_ledger_and_never_a_second_pairing` (the create path) and both
fleet-options contract tests. **No certified test was modified.**

**Frontend — `group-vehicle-assignment.test.tsx` (new, 4 tests): `4 passed`**
- no driver offered until a vehicle is chosen
- **CASE 1** only the paired driver offered; `ahmed · DRV-002` absent
- **CASE 3** empty state + disabled selector
- **CASE 4** stale driver cleared on vehicle change

(The service is mocked and the real React-Query pipeline runs, following the repo idiom. jsdom pointer
-capture shims are environment-only; the component's filtering logic runs unmodified.)

**Static:** `php -l` clean · **Pint** passed · **ESLint** clean · **tsc** (`-p tsconfig.app.json`) 0
errors in touched files · **i18n parity** EN 2362 = AR 2362.

**PHPStan:** 1 error — `RuntimeException` at `GroupVehicleAssignmentService.php:253` resolves to the
namespace-local class (a latent fatal on the cross-company path). Verified **pre-existing and not
baselined**: `git diff` shows my change does not touch that line. **Not fixed — out of scope**
(unrelated behaviour). Flagged in §10.

---

## 6. Browser Verification

Authenticated session, real tenant data, against the dev app with the backend changes deployed into
the container.

**CASE 1 — PASS.** `GET .../fleet-options` for group DG-001:
```json
vehicles: [ { plate: "1336", driver_ids: ["1c727aa8-…"] } ]        ← OSAMA / DRV-001 only
all_drivers_published: [ {ahmed / DRV-002}, {OSAMA / DRV-001} ]     ← flat list still complete
```
The unrelated driver from your screenshot (**ahmed / DRV-002**) is **excluded** from the vehicle's
eligible set. This is the exact defect, fixed at the source that drives the dropdown.

**CASE 5 — PASS.** Submitting the invalid pair (vehicle **1336** + **ahmed/DRV-002**):
```
HTTP 422 — "Vehicle 1336 is already assigned to OSAMA FAYEZ AHEMD. Release it first."
```
Rejected server-side by BR-7 (one active driver per vehicle) — the canonical ledger's own guard, not a
new check. Pairings verified **unchanged** afterwards (still exactly the one original row), and no Trip
could be created because the exception precedes trip resolution.

**CASE 3 — PASS** (frontend test; and `driver_ids: []` is produced by the backend for an unpaired
vehicle, covered by a backend test).
**CASE 2 / CASE 4 / CASE 7 — PASS at component level** (frontend tests); **CASE 6** covered by the
backend reuse test.

**UI-level (visual) verification — NOT PERFORMED.** The Distribution workspace only renders the
Vehicle & Driver section when a window resolves as *current* (`window_date == today`). Today is
2026-08-25 and the only window holding groups is dated **2026-08-21**, so the workspace shows "No
distribution window". Rendering it would require creating/retargeting a window — a **write** to live
operational data, which this task forbids. I verified the payload that drives the dropdown instead, and
covered the dropdown behaviour itself with component tests.

---

## 7. Regression Results

- `GroupVehicleAssignmentTest` — **OK (15 tests, 120 assertions)**, including all 11 pre-existing.
- `GroupTripLoadingIntegrationTest`, `DistributionWorkspaceFinalizationTest`,
  `DriverLoadingCustodyHandoffTest` — the three other suites that exercise `assign-vehicle`: **queued
  behind another agent's `migrate:fresh` on the shared test schema at the time of writing.** Result
  appended below when the gate frees.
- Frontend: 4/4 new tests pass; no existing frontend test touches this component.

---

## 8. Files Changed

**Backend (2):**
- `Modules/Logistics/Distribution/Presentation/Http/Controllers/DistributionWindowController.php`
  — `driver_ids` per vehicle + `activeDriverUuidsByVehicleId()` helper + import.
- `Modules/Logistics/Distribution/Domain/Services/GroupVehicleAssignmentService.php`
  — reuse an active pairing (`activePairing()`) instead of minting a duplicate + imports.

**Backend tests (1):** `tests/Feature/Logistics/GroupVehicleAssignmentTest.php` — **4 tests added**, a
`pair()` helper added; **no existing test modified**.

**Frontend (4):**
- `features/logistics/distribution-workspace/components/group-vehicle-assignment.tsx`
- `features/logistics/distribution-workspace/components/group-vehicle-assignment.test.tsx` (new)
- `features/logistics/distribution-workspace/types/index.ts` (`driver_ids?`)
- `i18n/locales/en/logistics.json`, `i18n/locales/ar/logistics.json` (2 keys each)

Nothing else was touched: Distribution Group lifecycle, Wave lifecycle, Trip architecture, Loading,
Driver Loading, Settlement, Vehicle inventory, Distribution Zones, Geography and Shipping navigation
are unchanged.

---

## 9. Data Safety

- **No migration, no schema change.**
- **No live business data mutated.** The only write *attempted* was CASE 5, which the server rejected
  (422) and rolled back; pairings were re-verified unchanged afterwards.
- CASE 6 (a successful assignment) was **not** executed against live data on purpose — it would write
  `distribution_trips.driver_vehicle_assignment_id`. It is covered by the backend reuse test instead.
- Changed files were `docker cp`-ed into the dev containers for verification (source is not
  hot-mounted); those are running-container copies, not repository or schema changes.

---

## 10. Remaining Gaps

1. **Unpaired pair via raw API is not rejected — deliberate.** A vehicle with *no* driver plus any
   driver still **creates** the pairing (unchanged behaviour). Rejecting it would break four certified
   suites, which your point 5 required me to preserve. Your reported case *is* rejected (BR-7), and the
   UI never offers an unpaired driver. Closing this fully is a separate, owner-approved contract change.
2. **Pre-existing latent fatal** at `GroupVehicleAssignmentService.php:253` — `RuntimeException` without
   an import resolves to the namespace-local class. Likely unreachable today (the tenant-scoped
   resolver throws first), but it is a real defect. Reported, not fixed.
3. **UI visual verification** pending a current-dated window with groups (§6).
4. With today's data, vehicle 1336 offers exactly one driver; `ahmed`/DRV-002 is unselectable
   everywhere in Distribution until someone pairs them in Drivers/Fleet. The drawer already links to
   those screens (`group-fleet-manage-drivers`).

---

# FINAL STATUS

## IMPLEMENTED / VERIFIED — with one scope deviation and one pending regression run

The reported defect is fixed at its source and verified on real data: **vehicle 1336 now offers only
its actively assigned driver (OSAMA/DRV-001)**, the unrelated driver is gone, the previously-broken
valid pair now assigns idempotently, and an invalid pair is refused server-side with 422.

Backend `GroupVehicleAssignmentTest` is fully green (15/15) with **no certified test modified**; three
further regression suites were still queued on the shared test gate at the time of writing (§7).
The one requirement intentionally not met is documented in §10.1.

**Not committed. Not pushed. Not deployed. No other task started.**
