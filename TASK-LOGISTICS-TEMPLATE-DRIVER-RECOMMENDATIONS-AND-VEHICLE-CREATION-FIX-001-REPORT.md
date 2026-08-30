# TASK-LOGISTICS-TEMPLATE-DRIVER-RECOMMENDATIONS-AND-VEHICLE-CREATION-FIX-001 — REPORT

**Date:** 2026-08-24 · **Branch:** `develop` · **Not committed, not deployed.**

---

## 1. Executive Summary

Two independent Logistics issues were fixed, each at its root cause, with no change to
any Distribution / Driver / Loading / Delivery / Trip architecture.

- **Issue A — Recommended Drivers.** The section rendered a permanent *"No recommendation
  available"* because there was nowhere to persist a per-template driver list. The
  previously-approved pivot (`distribution_group_template_drivers`) is now implemented,
  and the placeholder is replaced by a real, searchable, multi-select of the tenant's
  own drivers. Recommendations are metadata only — applying a template never assigns a
  driver.
- **Issue B — Vehicle creation "The selected shipping company id is invalid."** Diagnosed
  as a **tenancy divergence**, not an id/type mismatch: the carrier dropdown listed *all*
  active carriers, but create/update validation (correctly) requires the carrier to be
  **mapped** to the operator's company. "osamafayez (SHC-003)" is active but unmapped, so
  it was offered and then rejected. Fixed by scoping the dropdown to mapped carriers — the
  validation was left untouched.

All static checks pass. 16 new focused tests pass. The Vehicle fix is browser-verified
against the real tenant data. No migration was run against `ecos_dev`; the new migration
ran only on the test schema.

---

## 2. Recommended Drivers — Root Cause

Confirmed against TASK-DISTRIBUTION-TEMPLATE-DRIVER-RECOMMENDATIONS-001:
`distribution_group_templates` had only a Zones relation and no column or table able to
hold a driver list. The prior task STOPPED pending migration authorization (now granted,
Part 3). The UI therefore honestly showed *"No recommendation available"*. There was no
scoring engine, and none was added — the operator chooses the drivers.

## 3. Recommended Drivers — Persistence

New pivot, mirroring the Zones pivot exactly (bigint PK, `uuid` parent FK, no FKs, no
`company_id`, no soft-deletes, guarded `up()`):

`distribution_group_template_drivers` — `(id, distribution_group_template_id uuid,
logistics_driver_id bigint, timestamps)`
- **UNIQUE (`distribution_group_template_id`, `logistics_driver_id`)** — a driver is
  recommended at most once per template.
- **NO unique on `logistics_driver_id` alone** — the same driver may be recommended by many
  templates (recommendations are not ownership). A test pins this.
- Index on `logistics_driver_id` for the reverse lookup.

Backend wiring:
- `DistributionGroupTemplate::recommendedDrivers()` + `recommendedDriverIds()`.
- New pivot model `DistributionGroupTemplateDriver`.
- `GroupTemplateService`: `create()`/`update()` take a `driverIds` list; `assertDriversUsable()`
  validates ids against the **tenant-scoped** `Driver` model (rejecting foreign-company and
  archived drivers); `replaceDrivers()` delete-then-insert, mirroring `replaceZones()`.
  `update()` keeps the same null-vs-absent contract as zones (absent leaves them; `[]` clears).
- `GroupTemplateController`: `driver_ids` / `driver_ids.*` validation on store & update;
  `payload()` now returns `driver_ids` + `drivers_count`.

`logistics_drivers` is the sole canonical driver source; the pivot stores ids only.

## 4. Recommended Drivers — UI

`distribution-templates-tab.tsx`:
- The placeholder is replaced by `DriverPicker` — a real multi-select fed by the canonical
  `useDrivers()` (tenant-scoped `logistics_drivers`). It shows **all eligible** drivers,
  is searchable by **name / code / mobile** (fields already in the read model), supports
  multiple selection, removable chips for the selected set, and a clear empty state
  ("No recommended drivers selected", not "No recommendation available"). No score, no
  ranking, no auto-select. Loads existing selections when editing; saves the exact ids.
- Table column renders the recommended names (`Ahmed · Mohamed +2`), never "Assigned".
- EN + AR keys added at parity.

## 5. Template Apply Safety

`applyToNewGroup()` was **not modified**. It reads only name / capacity / zones and never
consults the recommendation pivot. A dedicated test proves applying a template with
recommended drivers:
- creates the Group, copies zones/capacity as before,
- creates **no** driver/vehicle pairing (`logistics_driver_vehicle_assignments` count
  unchanged),
- leaves the template's recommendation pivot untouched.

Recommendations remain template metadata only.

## 6. Vehicle Creation — Root Cause

Full chain traced:
- UI `<Select>` value = `logistics_shipping_companies.id` (correct canonical key); payload
  `shipping_company_id` = that numeric id.
- `POST /logistics/vehicles` → `VehicleController::rules()` validates
  `shipping_company_id` with, for a tenant user:
  `Rule::exists('logistics_shipping_company_mappings','shipping_company_id')->where('company_id', $companyId)`.
- The carrier list feeding the dropdown (`ShippingCompanyController::index`) was **not**
  mapping-filtered, so it offered carriers with no mapping row for the operator's company.
- "SHC-003" (id 237, active) has **no mapping** to the company, so it appeared in the
  dropdown yet failed the `exists` rule → *"The selected shipping company id is invalid."*

It was a divergence between an unfiltered list and a fail-closed, mapping-scoped
validation — **not** an id/uuid/code/serialization mismatch (there is no uuid on that
table; the id was always correct).

## 7. Vehicle Fix

`ShippingCompanyController::index` gained an opt-in `assignable_only` flag: when set and
the user has a company, it restricts to carriers with a mapping row for that company
(`whereHas('mappings', …company_id)`). A global user (no company) sees all — mirroring the
validation's own fallback. `vehicle-drawer.tsx` now requests
`{ status:'active', assignable_only:true }`, so the dropdown offers only carriers that will
pass validation.

The validation rule was **not weakened**, tenancy was **not removed**, and the management
screen (which omits the flag) is unaffected.

## 8. Tenancy / Security

- Recommended-driver ids are validated against the tenant-scoped `Driver` model; a
  foreign-company or archived driver id is rejected (422). The pivot carries no
  `company_id` — the tenant is the parent template's, and reads reach it only through the
  company-scoped template.
- Carrier validation for vehicles is unchanged and still fail-closed; foreign-company
  carriers remain rejected. The list flag is additive and tenant-aware.

## 9. Tests

New (`backend/tests/Feature/Logistics/`), run via the isolation gate — **16 passed, 54
assertions**:
- `DistributionTemplateDriverRecommendationsTest` (10): pivot unique-per-template (not
  per-driver); create with many; reload persistence; empty valid; edit replace / omit
  (untouched) / empty (clears); foreign-company rejected; archived rejected; same driver in
  many templates; apply assigns no driver.
- `VehicleShippingCompanyTenancyTest` (6): create with a mapped carrier (+ reload relation);
  unmapped carrier rejected (the exact bug); foreign-company mapping does not qualify;
  `assignable_only` returns only mapped; default list unaffected.

Static: `php -l` (all), **Pint** (passed; one trailing-comma auto-fix applied), **PHPStan**
(no errors, `--memory-limit=1G`), **ESLint** (clean on all touched files), **tsc**
(`-p tsconfig.app.json` — 0 errors in any touched file), **i18n parity** (EN/AR equal,
2272 keys).

## 10. Browser Verification

- **Vehicle — VERIFIED** (authenticated dev session, real tenant data). Through the app's
  own API: `?assignable_only=1&status=all` → exactly the 3 mapped carriers
  (SHC-001, HTC-19558, W30402); `?status=all` (no flag) → all 5 including the unmapped
  SHC-002/SHC-003. Active-only + assignable → empty, because the only active carrier
  (SHC-003) is unmapped — i.e. the carrier that produced the 422 is now correctly *not
  offered*. The dropdown and the validation now agree.
- **Recommended Drivers — PENDING (data-limited).** `logistics_drivers` holds **0 rows** for
  the tenant, so the picker renders its empty state and no selection can be exercised
  live; and the new pivot migration was intentionally **not** applied to `ecos_dev`. The 16
  tests fully exercise the persistence/edit/tenancy/apply behaviour on a migrated schema.
  No driver data was fabricated.

## 11. Data Safety

No `ecos_dev` business data was mutated. The new migration ran **only** against the test
schema (`RefreshDatabase`). The existing 2026-08-21 distribution window and its orders,
groups, and waves were not touched. For verification only, changed backend files were
`docker cp`-ed into the dev containers (testrunner for tests; dev-app for the Vehicle API
check) — these are running-container copies, not repository or schema changes.

## 12. Files Changed

**Backend — new:**
- `Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_08_24_100000_create_distribution_group_template_drivers_table.php`
- `Modules/Logistics/Distribution/Domain/Models/DistributionGroupTemplateDriver.php`
- `tests/Feature/Logistics/DistributionTemplateDriverRecommendationsTest.php`
- `tests/Feature/Logistics/VehicleShippingCompanyTenancyTest.php`

**Backend — modified:**
- `Modules/Logistics/Distribution/Domain/Models/DistributionGroupTemplate.php` (relation + helper)
- `Modules/Logistics/Distribution/Domain/Services/GroupTemplateService.php` (driverIds create/update, assert/replace)
- `Modules/Logistics/Distribution/Presentation/Http/Controllers/GroupTemplateController.php` (validation + payload)
- `Modules/Logistics/ShippingCompanies/Presentation/Http/Controllers/ShippingCompanyController.php` (`assignable_only`)

**Frontend — modified:**
- `features/logistics/distribution-workspace/components/distribution-templates-tab.tsx` (DriverPicker + table)
- `features/logistics/distribution-workspace/types/index.ts` (`driver_ids`, `drivers_count`)
- `features/logistics/vehicles/components/vehicle-drawer.tsx` (`assignable_only:true`)
- `features/logistics/shipping-companies/types/shipping-company.ts` (`assignable_only?`)
- `i18n/locales/en/logistics.json`, `i18n/locales/ar/logistics.json`

(The Distribution module and distribution-workspace frontend are an uncommitted baseline
from earlier tasks, so their files show as untracked in git.)

## 13. Regression Results

`DistributionTemplateZoneExclusivityTest` + `VehicleModuleTest` via the gate: **69 tests, 2
failures.** Both failures are `test_maintenance_permission_endpoint_reflects_capability` /
its sibling asserting a fresh user has `can_manage_maintenance = false` — they receive
`true`. This is the documented `TestCase::actingAs()`-grants-a-system-role behaviour in the
vehicle **maintenance-permission** path. It is **not caused by this task**:

- my only vehicle-side change is `ShippingCompanyController::index`, which `VehicleModuleTest`
  never calls;
- `VehicleController.php` / `Vehicle.php` show as modified by another source (not this task),
  and the container runs its own baked copies;
- every vehicle **create / list / edit** test passed, and `DistributionTemplateZoneExclusivityTest`
  (which exercises my changed controller/service, including the `test_a_template_stores_no_driver_or_vehicle`
  guard) passed in full.

Per Part 11, a certified test was **not** modified to pass, and the failure is not a genuine
consequence of this implementation. It is flagged for separate attention.

## 14. Remaining Issues

- Recommended Drivers browser walkthrough is pending real driver data + the migration on
  `ecos_dev` (deliberately not applied).
- The pre-existing 2 vehicle-maintenance-permission test failures (Section 13) warrant a
  separate look at RBAC/`actingAs` seeding; out of scope here.
- Deploy ordering: the new frontend expects `driver_ids` in the template payload; a guard
  (`?? []`) was added so a not-yet-updated backend cannot crash the templates table.

---

# FINAL STATEMENTS

- **Recommended Drivers are now persisted** — in a dedicated `distribution_group_template_drivers`
  pivot, surviving save / reload / edit (verified by 10 tests).
- **All eligible drivers are selectable** — the picker lists the tenant's canonical
  `logistics_drivers`, searchable, multi-select, no ranking.
- **Recommendations remain suggestions only** — applying a template assigns no driver and
  never touches Group driver selection (verified).
- **Vehicle creation with a Shipping Company is fixed** — the dropdown now offers only
  carriers mapped to the operator's company, matching the unchanged fail-closed validation;
  browser-verified against real data.
- **A migration was created** — `distribution_group_template_drivers` (authorized in Part 3).
  It was applied to the test schema only, not to `ecos_dev`.
- **No existing certified path was changed** — Group / Trip / Shipment / Driver / Vehicle
  assignment / Loading / Preparation / Reservation and `applyToNewGroup` are untouched; the
  carrier validation rule was not weakened.

**STOP — report complete. Not committed, not deployed. Wave 3 not started.**
