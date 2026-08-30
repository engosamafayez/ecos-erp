# TASK-ORDERS-LIVE-RUNTIME-REMEDIATION-002 — Engineering Report

**Status:** GPS half = code-complete, all static/test gates green, browser smoke pending sign-in.
**Inventory/reservation half = BLOCKED on owner decision (approved-ADR conflict).**
**Certification: DEFERRED** (as instructed).
**Production: NOT touched. DEV database NOT reset. Manual test data (ORD-00001/00002) NOT deleted.**

Date: 2026-08-20. Branch: `develop`. Environment: DEV only (`ecos-dev-*`, DB `ecos_dev` / test DB `ecos_dev_test`).

---

## 1. Previous forensic finding (accepted source of truth)

- **GPS:** ORD-00002 persisted a full `google.com/maps/place/…@…/data=…!3d!4d` URL but `google_maps_lat/lng = NULL`, `location_source = NULL`. Cause: the operator pasted the URL without clicking "Import Location" (the only client-side extractor), and the backend `GoogleMapsUrlResolver::backfillCoordinates()` deliberately skipped full `www.google.com` URLs (short-link only). API returned `location: null` → grid showed "No GPS". ORD-00001's simple `?q=lat,lng` URL had captured coordinates, so it displayed fine.
- **Stock:** ORD-00002 contains Honey Jar 250g (FG, `on_hand = 0` in every inventory table). Reservation correctly produced Awaiting Stock. The Products page showed "In Stock" because the finished-good badge reflected recipe **buildability** (components Raw Honey 100, Glass Jar 500 in stock), not FG fulfillability.

## 2. Owner-approved corrected contract (supersedes the first stock-semantics section)

Canonical order-fulfillability chain: RM fulfillability → recipe executability → FG fulfillability → reservation → lifecycle. A FG with `on_hand = 0` **is Fulfillable when its recipe is executable** (every RM physically available **OR** `allow_negative_stock = true`) → order reserves → In Progress. If any required RM is insufficient **and** `allow_negative_stock = false` → recipe not executable → Awaiting Stock. Awaiting-Stock orders must **auto-recover** when RM availability or the `allow_negative_stock` policy changes (reuse existing event/recovery arch, no polling, idempotent/tenant-safe/scoped). `can_manufacture` must NOT be silently used as a substitute for recipe executability.

## 3. Existing architecture audit (read-only, evidence-based)

| Question | Finding | Evidence |
|---|---|---|
| `can_manufacture` meaning | Master-data **capability/permission** flag ("has a recipe and may be produced"), user-set, default `false`. Separate from runtime executability. | `Product.php:53,169`; migration `2026_06_29_000001_*:24`; `ManufacturingPolicy.php:98`; `ProductController.php:146-153` (preserved, not derived) |
| Reservation decision | `ReserveOrderInventoryAction`: Case 1 physical FG; **Case 2 manufacturing gated on `can_manufacture && manufacturingIsExecutable`**; Case 3 `allow_negative_stock`; else Awaiting. | `ReserveOrderInventoryAction.php:157,191,219,257,326-333` |
| Recipe executability owner | Single authority `ManufacturingAvailabilityService::evaluate()` — rule is **exactly** "RM available>0 OR allow_negative_stock". Already consumed by reservation. | `ManufacturingAvailabilityService.php:88-118`; ADR-027 §16.3 (line 555) |
| ADR-027 ownership | §3/§11 FG-only rule **superseded** by §17 (v1.3): Orders may derive RM from BOM and read executability. BUT the **Case 2 trigger is still `can_manufacture = true`** (decision tree line 183; §16 only *adds* executability on top; P05 line 414). | `docs/adr/ADR-027-…md:16-17,183,187-190,414,555` |
| Auto-recovery (A) RM available | **EXISTS & reused as-is.** `RetryReservationOnStockAvailableListener` subscribes `InventoryStockReceived/Released/Adjusted`, maps RM→consuming FG via `finishedGoodsConsuming()`, scoped by company+warehouse+product, idempotent, tenant-safe. | `OrderServiceProvider.php:74-85`; listener 178-311 |
| Auto-recovery (B) `allow_negative_stock` flip | **NO event path.** Product model dispatches no domain event on update. | `Product.php:83-106`; no `event()`/observer in Products actions |
| Dead/other engines | `MRPCalculationService` = dead (0 callers). `AnalyzeMaterialsAction` = live but Preparation-wave route only, not the reservation gate. `InventoryAvailabilityEngine` = separate surface. | grep; `routes/api.php:846` |

## 4. Files changed

**GPS only (inventory half not started — blocked):**
- `backend/Modules/Commerce/Orders/Application/Services/GoogleMapsUrlResolver.php` — rewrote: single shared `extractCoordinates()` parser; `backfillCoordinates()` recovers coordinates from a full `google.com/maps` URL **offline** (no network), keeps short-link network resolution, stamps `location_source = google_maps`.
- `frontend/src/features/orders/components/manual-order-form.tsx` — auto-capture without Import: `captureLocationSilently()` on URL-field **blur**, plus a **submit-time safety net** in `handleSubmit` (covers create + edit). Reuses `parseGoogleMapsUrl`.
- `backend/tests/Feature/Commerce/OrderGpsPersistenceTest.php` — replaced obsolete "full URLs stay untouched" test with an offline-recovery test; added the **URL-only HTTP feature test** (ORD-00002-shaped place URL, no lat/lng → `location.lat/lng` returned).

No `routes/api.php`, no `operations.json`, no git destructive operations. Concurrency: all GPS targets last modified Aug 13–19 (not churning); two were untracked (uncommitted Finding 05 work).

## 5. Recipe executability implementation — NOT DONE (blocked, see §12)
## 6. Finished-product fulfillability implementation — NOT DONE (blocked, see §12)
## 7. Automatic Awaiting-Stock recovery — trigger (A) already exists; trigger (B) NOT DONE (blocked)

## 8. Test matrix and results

**GPS (ran via serialized gate inside `ecos-dev-testrunner`):**
```
tests/Feature/Commerce/OrderGpsPersistenceTest.php
OK (8 tests, 20 assertions)
```
Includes: offline full-URL recovery, coordinateless full-URL noop, short-link resolution (regression), URL-only create feature test (ORD-00002 case), supplied-coords, no-gps.

**Static gates (GPS changed files):** Pint PASS · PHPStan L0 OK · `tsc -p tsconfig.app.json` — no new errors from changed lines (pre-existing EPIC-L10N baseline errors unrelated) · ESLint 0 errors (1 pre-existing warning).

**Stock/reservation test matrix (Cases 1–10):** NOT WRITTEN — blocked on the reservation-contract decision (§12).

## 9. Browser smoke — PENDING sign-in
GPS scenarios and stock scenarios not yet executed: the dev UI (`http://localhost:5173`) requires an authenticated session; entering passwords to authenticate is outside my permitted actions and no dev credentials are available to me. Requires the owner to sign in (I will then drive the smoke).

## 10. GPS results
Code-complete and green on every automated gate. Operational browser proof pending sign-in (§9).

## 11. Reservation semantics confirmation
The reservation engine was **NOT modified**. `ReserveOrderInventoryAction` and `ManufacturingAvailabilityService::evaluate()` are untouched. GPS changes are confined to the Orders create/update GPS path and the manual-order form.

## 12. Remaining gaps / STOP conditions (owner decision required before inventory work)

1. **STOP #1 + #3 — TRIGGERED.** ADR-027's approved decision tree (line 183) makes `can_manufacture = true` the entry condition for the Case-2 manufacturing commit. A recipe-executable FG with `can_manufacture = false` (Honey Jar) therefore lands in Case 4 (Awaiting Stock) under the current approved ADR. Satisfying the new contract for such products requires **amending ADR-027 §3 Case 2 / §16 / P05** and redefining `can_manufacture`'s reservation role. Per instructions I did not invent a resolution. **Note:** FGs already flagged `can_manufacture = true` already satisfy Cases 1–4 today (zero FG + executable recipe already reserves → In Progress). The conflict is confined to the `can_manufacture = false` case.
2. **STOP #2 — PARTIAL.** Auto-recovery for RM-becomes-available already exists and is reused. `allow_negative_stock` policy-flip recovery has **no event path**; implementing it adds a new product-change domain event feeding the existing listener.
3. **STOP #4 — not a blocker.** Recipe executability has a single authority already integrated; no duplicate engine needed.

**Decisions requested:** (1) how to resolve the `can_manufacture` gate (amend ADR-027 vs treat Honey Jar as master-data vs repurpose the flag); (2) whether to add the `allow_negative_stock`-change recovery event now or defer.

## Certification status
**DEFERRED.** GPS half awaits browser proof; inventory half awaits the §12 decisions. Nothing is certified. No "Fixed + Verified" claim is made.
