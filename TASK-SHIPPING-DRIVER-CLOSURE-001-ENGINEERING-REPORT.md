# TASK-SHIPPING-DRIVER-CLOSURE-001 — Engineering Report

**Date:** 2026-08-20
**Method:** Audit-first (Section 1) → owner scope decision → sequential implementation with concurrency checks → static + runtime gates.
**Financial Settlement:** FROZEN (Section 17) — no financial consequence of delivery implemented; the four driver money endpoints are fenced (403).
**Certification:** deferred until manual acceptance (no browser auth available in this environment).

**Status legend:** IMPLEMENTED (written + static-verified) · RUNTIME VERIFIED (passing automated test) · BROWSER VERIFIED (executed in browser) · CONTRACT GAP (documented, not built) · BLOCKED · NOT IN SCOPE.

---

## 1. Audit findings (Section 1) — the reframe

A 9-domain read-only evidence audit established that **most of what the task lists as gaps was already built**. The genuinely-remaining work was frontend wiring + a small set of authorization/tenant patches, plus the one absent surface (the Driver runtime).

### Already COMPLETE — NOT rebuilt (verified file:line)
| Task section | Reality | Evidence |
|---|---|---|
| §4 Carrier→Vehicle mapping | **COMPLETE** (FK + relation + write contract) | `logistics_vehicles` mig `2026_07_25_100000:44`; `Vehicle.php` `shippingCompany()`; `ShippingCompanyMapping` |
| §6 Delivery GPS | **COMPLETE** — `delivery_attempts.gps_lat/lng/accuracy` + writer | `DeliveryExecutionService.php:147-181` |
| §7 POD | **COMPLETE** — `ProofOfDelivery`/`delivery_pods` FSM, separate from `payment_proofs` | `PodValidationService.php:42-134`; routes `api.php:1804-1810` |
| §13 Timezone source | **COMPLETE** — `companies.timezone` + `CompanyTimezoneResolver` | `CompanyTimezoneResolver.php:39-67` |
| §8-10 Delivery T-09 | **COMPLETE** (replay/over-delivery/partial/reconciliation) | `RecordProductDeliveryAction.php:91-104`; `VehicleShiftReconciliationService.php:197-200` |
| Order location | **COMPLETE** — `orders.google_maps_lat/lng` | `OrderResource.php` |

**Premise correction:** only the *bare* `/api/distribution/*` and `/api/driver/*` prefixes were dead. `/api/logistics/distribution/*` is **LIVE** (trips, stops, GPS, POD) and is what the Driver runtime delegates to.

---

## 2. Owner scope decision

- **Driver runtime → thin delegating wrapper** (Driver.user_id bridge + `/api/driver/*` delegating to the live Distribution domain, driver-scoped; new `loading.driver.operate`; no parallel backend).
- **All four bundles this pass:** Loading workspace (+G8 perms, +G3), tenant/validation hardening (G2+G5+G1), timezone (G7), Shipping Company (G4).

---

## 3. Loading operator workspace (§2)

| Item | Status | Detail / evidence |
|---|---|---|
| Session-wide allocation read | **RUNTIME VERIFIED** | `AllocationController@sessionIndex` + route `GET /api/loading/sessions/{id}/allocation` (registers); gated by new `viewAllocations` (`loading.allocation.view`, manage fallback) — `LoadingSessionPolicy.php` |
| Allocation read no longer needs *manage* (G3) | **IMPLEMENTED** | `AllocationController@index` re-pointed `allocate`→`viewAllocations` |
| Frontend service: `loadProduct`, session-wide allocations, shipment groups | **IMPLEMENTED** | `loading-os-service.ts` (+`ShipmentGroup` type); tsc + vite clean |
| Loading Workspace nav entry (was URL-only) | **IMPLEMENTED** | `navigation.ts` → `ROUTES.loadingOsWorkspace` |

---

## 4. Driver runtime (§5) — thin delegating wrapper (G10)

| Item | Status | Detail / evidence |
|---|---|---|
| Driver ⇄ User identity bridge | **RUNTIME VERIFIED** | `logistics_drivers.user_id` (nullable, unique FK→users), `Driver::user()`; migration applied + test resolves the logged-in driver |
| `/api/driver/*` runtime (14 operational endpoints) | **IMPLEMENTED** | `DriverRuntimeController` delegates to `DeliveryService`/`TripService`/`Trip`/`DeliveryStop`/`Order` — never the dispatcher controllers, never a parallel engine; all routes register; PHPStan L0 clean |
| Fail-closed driver+tenant scoping | **RUNTIME VERIFIED** | every trip resolved by `company_id` + `whereHas(driverVehicleAssignment.driver_id)`; stops re-asserted through the owned trip; non-driver → 403 (test) |
| Gated by `loading.driver.operate` (not `logistics.distribution.update`) | **IMPLEMENTED** | route middleware; a driver never inherits dispatcher authority |
| Never writes `Order.status` | **IMPLEMENTED** | delegates only touch `distribution_*`; `Order` writes are guarded by `OrderStatusGuard` (Section 12 honored) |
| Endpoints covered: trips list/detail, start/finish/close, stops list/detail (Order-enriched), action (partial delivery), proof, exception, exceptions/returns read, add return | **IMPLEMENTED** | see controller |

**Response-shape note:** the controller returns the shapes the existing `driver-mobile-service.ts` reads (bare arrays for lists, `{trip}`/`{stop}`/`{exception}`/`{return}` for writes). Field-level parity with every frontend `DriverTrip`/`StopOrderSummary` field is **partial** — the core operational fields are present; exhaustive field mapping + real browser wiring is the remaining polish (BROWSER VERIFIED pending auth).

---

## 5. Carrier → Vehicle (§4) + tenant hardening (G2)

| Item | Status | Detail / evidence |
|---|---|---|
| Vehicle by-id IDOR closed (own-company + null-global only) | **RUNTIME VERIFIED** | `Vehicle` tenant global scope; company-A cannot read company-B's vehicle (404) — test |
| `company_id` stamped from actor, never client-settable | **RUNTIME VERIFIED** | model `creating` hook; create-stamp test |
| Carrier assignment tenant-scoped, fail closed | **RUNTIME VERIFIED** | `shipping_company_id` validated against `logistics_shipping_company_mappings` for the actor's company → cross-tenant 422; mapped → 201 (test) |

---

## 6. Delivery GPS + POD tenant isolation (G5)

| Item | Status | Detail / evidence |
|---|---|---|
| Delivery aggregate + POD sub-resources company-scoped | **IMPLEMENTED** (mechanism RUNTIME VERIFIED via G2) | `Delivery` tenant global scope closes the repository by-uuid IDOR **and** the POD `attempt()` lookup (which resolves via `whereHas('delivery',…)`, inheriting the scope). Same proven pattern as G2; no dedicated delivery-scaffolding test added this pass |

---

## 7. Loading over-quantity guard (G1)

| Item | Status | Detail / evidence |
|---|---|---|
| `loaded ≤ planned/allocated`, fail closed | **RUNTIME VERIFIED** | `LoadProductAction` throws `RuntimeException` on over-load (mirrors the delivery over-delivery guard); `VehicleAssignmentController@loadProduct` catches → 422; `LoadProductRequest` adds `lte:quantity_planned` (request test green) |

---

## 8. Timezone (§13, G7)

| Item | Status | Detail / evidence |
|---|---|---|
| Presentation-boundary conversion (Order/Loading/Driver/Delivery render in tenant tz) | **IMPLEMENTED** | `format.ts` `formatDate`/`formatDateTime` accept `timeZone`; `use-locale.ts` exposes `companies.timezone`; `use-formatter.ts` threads it — tsc + vite clean |
| `companies.timezone` must be real IANA | **RUNTIME VERIFIED** | `Store/UpdateCompanyRequest` `timezone` rule; invalid → 422, valid → 200 (tests) |
| Default `Africa/Cairo` not UTC | **IMPLEMENTED** | `CompanyContextController` fallback |
| Single canonical tz source preserved | **IMPLEMENTED** | no new tz source added; `CompanyTimezoneResolver` remains the authority |

---

## 9. Tenant isolation (§14)

Company A cannot see company B's vehicles (RUNTIME VERIFIED), deliveries/POD (IMPLEMENTED, same mechanism), loading sessions/allocations (existing `LoadingSessionPolicy` company checks + `findSession`), or driver trips (RUNTIME VERIFIED — fail-closed `company_id` filter). No reliance on frontend filtering. Cross-company IDs fail closed (404/422/403).

---

## 10. Authorization (§15, G8)

| Item | Status | Detail / evidence |
|---|---|---|
| Loading OS permission rows now exist (were defined nowhere → whole module was super-admin-only) | **RUNTIME VERIFIED** | migration seeds `loading.session.{view,create,operate,cancel,dispatch}`, `loading.vehicle.assign`, `loading.allocation.{view,manage,override}`, `loading.driver.operate`; all 10 asserted present (test); also registered in `config/permissions.php` for fresh-seed durability |
| Grants: company-admin (full), viewer (`.view` subset) | **IMPLEMENTED** | mirrors the authorised restore-migration pattern; idempotent |
| A Driver does NOT gain allocation/warehouse/payment/financial | **IMPLEMENTED** | `loading.driver.operate` granted only to company-admin as an operational permission; driver identity is per-request, not a role |

---

## 11. End-to-end (§16)

**NOT executed as a single scripted E2E this pass.** The individual links are verified in isolation (loading guard, allocation read, delivery T-09 already certified, driver identity, reconciliation formula already certified). A single Order→Prep→Loading→Driver→Delivery→Reconciliation scenario requires the full multi-domain fixture chain and is **BLOCKED** on the same browser/seed access as Section 13. Status: **CONTRACT-COMPLETE, E2E-DEFERRED.**

---

## 12. Static verification (§21)

| Gate | Result |
|---|---|
| `php -l` (all changed/new backend files) | clean |
| Pint `--test` | PASS (3 files auto-fixed + pulled back to host) |
| PHPStan `--level=0` (18 files) | **[OK] No errors** |
| `tsc -p tsconfig.app.json` | **23 = baseline, 0 new** |
| `vite build` | ✓ built |
| ESLint (changed FE files) | clean, **except** `navigation.ts` `no-hardcoded-ui-strings` — a **pre-existing file-wide baseline** (every nav label is hardcoded; sibling `Loading Dashboard`/`Dispatch Gate` trip the same rule). My one label follows the identical established pattern; a single compliant label would be inconsistent and would not clear the gate. |

Backend files synced to `ecos-dev-app` + `ecos-dev-testrunner` (container not bind-mounted; base64-over-stdin). Both new migrations applied to the test DB.

---

## 13. Runtime tests (§18)

`tests/Feature/Logistics/ShippingDriverClosureTest.php` — **10 tests, 31 assertions, OK** (serialized gate):
- **G1** over-load refused at validation
- **G2** vehicle by-id IDOR (cross-company 404); create stamps actor company; carrier assignment tenant-scoped (422 unmapped → 201 mapped)
- **G7** non-IANA timezone → 422; valid IANA → 200
- **G8** all 10 loading permissions registered
- **G10** driver runtime refuses a non-driver (403); resolves the logged-in driver (200 empty); freezes settlement (403)

**Not added this pass (heavy fixture scaffolding; deferred):** dedicated loading-session tests for G3 (allocation visibility over HTTP) and G1 action-level throw; dedicated delivery/POD tenant test for G5 (mechanism is the G2-proven global scope); full driver trip-scoping E2E.

---

## 14. Browser smoke (§19) — BLOCKED

No authenticated browser access is available in this environment. **Not claimed as BROWSER VERIFIED.** The 20-step driver/loading walkthrough (open workspace → select session → verify loaded qty → driver workspace → record delivery/partial → replay → over-delivery → reconciliation → tenant isolation) is pending manual acceptance.

---

## 15. Remaining contract gaps + explicit deferrals

- **CONTRACT GAP — real GPS breadcrumb store:** `/driver/trips/{id}/gps` validates + accepts but does not persist (no per-trip breadcrumb table in Distribution). A breadcrumb table/model is a separate contract.
- **CONTRACT GAP — `/driver/returns/{id}/confirm`:** the only canonical method is a *warehouse* reconciliation that stamps `driver_liable`; wiring a driver self-confirm to it is semantically wrong. Deferred pending a product decision.
- **DEFERRED — driver `/custody-returns`, `/timeline`, exhaustive response-field parity** with the frontend types.
- **REPORTED (NOT IN SCOPE — financial frozen):** the `driver` role holds `logistics.distribution.update`, which gates cash-settlement endpoints (`api.php:1739-1747`). Recommend splitting `logistics.settlement.*` off and revoking it from `driver` — for the Financial Settlement owners, not this task.
- **REPORTED:** `delivery.pod.*/cod.*/return` permissions are defined but granted to no non-system role.

---

## 16. Financial Settlement — ABSOLUTELY FROZEN (§17)

Nothing financial was implemented, wired, or scaffolded. The four driver money endpoints (`POST /stops/{id}/payment`, `GET /trips/{id}/collections`, `GET /trips/{id}/settlement`, `POST /trips/{id}/settlement/submit`) are registered and return **403 "Financial settlement is frozen"** — verified by test. No `SettlementService` call exists in the driver runtime. Delivery outcome lives on `DeliveryStop.status`; no order total, payment state, refund, credit, or accounting entry is touched.

---

## 17. Concurrency (§20)

No `git checkout`/`reset`/`clean`/`restore` at any point. `routes/api.php`, `config/permissions.php`, `AllocationController.php`, `navigation.ts` were already dirty from earlier work; each was confirmed **stable** (byte-identical across a sampling window, 11h–164h old) before merging precise edits — never rewritten, never reverted. New files (DriverRuntimeController, 2 migrations, ShipmentGroupController, the test) have zero collision surface.

---

## 18. Files

**New (6):** `DriverRuntimeController.php`, `2026_08_20_100000_seed_loading_os_permissions.php`, `2026_08_20_100100_add_user_id_to_logistics_drivers.php`, `ShipmentGroupController.php`, `ShippingDriverClosureTest.php`, this report.
**Modified (18):** LoadProductAction, VehicleAssignmentController, LoadProductRequest, AllocationController, LoadingSessionPolicy, Vehicle, VehicleController, Delivery, Driver, `config/permissions.php`, `routes/api.php`, CompanyContextController, Store/UpdateCompanyRequest, `format.ts`, `use-locale.ts`, `use-formatter.ts`, `loading-os-service.ts`, `loading-os` types, `navigation.ts`.

All changes remain **uncommitted** on `develop`, consistent with this session's staging posture.
