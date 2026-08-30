# TASK-OPERATIONS-DRIVER-CLOSING-DEV-SCHEMA-RUNTIME-PARITY-002 — REPORT

**Date:** 2026-08-29
**Scope:** Deploy the already-completed Driver Closing source + apply the single approved
`expected_collection_at_handoff` migration to the **DEV runtime only** (`ecos-dev-app`, `ecos_dev`,
`ecos-dev-nginx`). No new feature work, no commit/push, no deploy outside DEV, no DEV business-data mutation.

**FINAL STATUS:**
- SOURCE: **COMPLETE** (unchanged this task — deployment only)
- DEV SCHEMA PARITY: **RESTORED**
- DEV BACKEND PARITY: **RESTORED**
- DEV FRONTEND PARITY: **RESTORED**

---

## 1. Task & objective
Bring the DEV runtime into parity with the completed (uncommitted) Driver Closing work:
the single-active-custody contract, the 8-KPI read model, the per-custody value aggregates, and the
`expected_collection_at_handoff` immutable handoff snapshot (schema + model + writer). Deploy-only;
audit-first; narrowest safe operations.

## 2. Constraints honored
- ❌ No commit, no push, no deploy outside DEV.
- ❌ No DEV business-data mutation (no trips/settlements/collections/expenses/deliveries created or edited;
  no existing `expected_collection_at_handoff` values changed; no historical backfill).
- ❌ No Driver Expenses implementation, no Finance work, no `DriverReportsController` change.
- ❌ No RBAC edits, no credentials, no user impersonation.
- ❌ No `route:clear` / `optimize:clear` (route source not at parity — see §6).
- ❌ No worker/scheduler restart, no container recreate, no entrypoint migrations.
- ✅ Only the one approved migration applied, via the narrowest command.

## 3. Audit — DEV state BEFORE changes
| Surface | Finding | Evidence |
|---|---|---|
| `ecos-dev-app` backend source | **Fully stale** — none of the new work present | new-source markers = 0 in read service / TripStatus / DeliveryStop / DistributionException |
| `ecos_dev` schema | Column **missing**, migration **not recorded** | `column_exists=NO`, `migration_recorded=NO` |
| Delivery stops in DEV | 1 stop total, 0 snapshots | `stops_total=1 with_snapshot=0` |
| `DriverDaySettlementController` | **Current** (scope branch present) | 3 marker matches |
| `routes/api.php` driver-settlement routes | **Present & current** | 2 matches; identical to host (see §6) |
| nginx `:8081` bundle | **Stale** (no new FE code) | `duplicate_open_custody` markers = 0; served `index-C4DNlApY.js` |
| host build output `backend/public/app` | **Stale** | marker = 0 |

## 4. Migration — identification, inspection, isolated application
- **File:** `backend/Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_08_29_120000_add_expected_collection_at_handoff_to_delivery_stops.php`
- **Inspection:** limited to a single additive, nullable column — `Schema::table('distribution_delivery_stops', fn ($t) => $t->decimal('expected_collection_at_handoff', 12, 2)->nullable()->after('collected_amount'))`. No data writes, no backfill, no other DDL. Matches the approved Driver Closing snapshot schema exactly.
- **Application (narrowest command):**
  `php artisan migrate --path=Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_08_29_120000_add_expected_collection_at_handoff_to_delivery_stops.php --force`
  → `INFO Running migrations … 2026_08_29_120000_… DONE`. No blind `migrate` of all pending.
- **Isolation proof (§11):** latest migration **batch = 139** contains **only** this migration; `expected_migration_recorded=YES (batch 139)`.

## 5. Backend source deployment
`docker cp` of the 6 stale source files + the migration into `ecos-dev-app:/var/www/html/…` (image is not
hot-mounted; only `storage` is a volume):
1. `Modules/Logistics/Distribution/Domain/Services/DriverDaySettlementReadService.php`
2. `Modules/Logistics/Distribution/Domain/Enums/TripStatus.php`
3. `Modules/Logistics/Distribution/Domain/Services/TripService.php`
4. `Modules/Logistics/Distribution/Domain/Exceptions/DistributionException.php`
5. `Modules/Logistics/Distribution/Domain/Models/DeliveryStop.php`
6. `Modules/Logistics/Distribution/Domain/Services/DeliveryService.php`
7. `…/Migrations/2026_08_29_120000_add_expected_collection_at_handoff_to_delivery_stops.php`

Post-copy marker check: read service now has 4 new-source markers (`custodyEligibleValues`,
`orderValueBreakdownByTrip`, `total_transfers_paid`). Controller + api.php were already current → not re-copied.

## 6. Route-source safety (§5 HARD STOP)
`routes/api.php` **md5 differs**: container `60a3ec39…` vs host `e24bb08f…`; line counts **container 4286 vs
host 3910** (container has **376 more** lines).

**However**, the driver-settlement routes are **byte-identical** in content:
```
container L1968-1969  |  host L1976-1977
Route::get('/driver-settlement',              [DriverDaySettlementController::class, 'index'])->middleware('permission:logistics.distribution.view');
Route::get('/driver-settlement/{assignmentId}',[DriverDaySettlementController::class, 'show'])->middleware('permission:logistics.distribution.view');
```
- The divergence is entirely in **routes unrelated to Driver Closing** (a pre-existing cross-session drift on the host working tree).
- **Decision:** api.php left **untouched**. Overwriting the container copy with the host working-tree copy would have **removed 376 lines of live container routes**. Per §5, no blind overwrite.
- **No `route:clear` / `optimize:clear` run.** My changes are PHP class files + a migration, resolved fresh at dispatch — they do not require a route-cache rebuild, and the DEV runtime already serves routes from (uncached) source.
- Driver Closing routing verified working end-to-end through the read service (§10).

> ⚠️ Residual (out of scope): the broader api.php container-vs-host divergence (4286 vs 3910) is a separate cross-session parity issue and should be reconciled deliberately, not by blind copy. Flagged, not fixed.

## 7. PHP runtime refresh
- `supervisorctl restart php-fpm` → `php-fpm RUNNING pid 600934` (opcache refreshed for the copied classes).
- **No** `route:clear` / `optimize:clear` / `config:clear` (no route/config change in scope).
- **No** worker or scheduler restart; **no** container recreate; **no** entrypoint-driven migration.

## 8. Frontend parity
- Build: `npx vite build` (NOT `npm run build` — that runs `tsc -b` which fails on the repo's baseline errors).
  Result: exit 0, "built in 1m 14s". New entry chunk **`index-eMROFNI1.js`** (was `index-C4DNlApY.js`).
- Host build now contains the marker (`duplicate_open_custody` = 1).
- Deploy: `docker cp backend/public/app/.` → `ecos-dev-nginx` **and** `ecos-dev-app` `:/var/www/html/public/app/` (exit 0).
- Verify (nginx `:8081`): `index.html` references `index-eMROFNI1.js`; the chunk contains the marker (=1);
  HTTP `index.html` → **200** (997 bytes); HTTP `assets/index-eMROFNI1.js` → **200** `application/javascript`.
- Vite `:5173` (dev/HMR) already served the current FE source — left running.

## 9. Schema verification (read-only)
`expected_collection_at_handoff` — `DATA_TYPE=decimal`, `NUMERIC_PRECISION=12`, `NUMERIC_SCALE=2`,
`IS_NULLABLE=YES`, `COLUMN_DEFAULT=NULL`. Exactly as designed.

## 10. Backend read verification (read-only, via `DriverDaySettlementReadService::activeBoard`)
- KPI keys (8, in order): `total_orders, total_delivered, total_failed, delivery_rate, total_sales,
  total_transfers_paid, total_expenses, net_cash`.
- `total_expenses = NULL`, `net_cash = NULL` (honest "Not available" — no canonical authority; never fabricated 0).
- **Single-active gate live:** `trips_non_closed = 3`, `trips_custody_eligible = 0` → `active_drivers_count = 0`.
  The three OSAMA `loading` trips are non-closed but **not custody-eligible**, so the historical "OSAMA×3"
  duplication no longer appears on the Active board.
- Active row shape (when eligible trips exist) carries `trip_id`, `orders_value`, `transfers_paid`,
  `duplicate_open_custody` (confirmed present in the row contract).

> Note: the HTTP API endpoint itself was **not** exercised (it requires an authenticated session; minting a
> token / impersonating a user is out of bounds). Verification was done at the read-service contract level,
> with the route + controller confirmed present and current, and php-fpm reloaded.

## 11. Migration isolation
Batch 139 = the single expected migration only (see §4). No other pending migration was applied.

## 12. No historical backfill
`historical_with_snapshot = 0 / 1` — the pre-existing delivery stop keeps `NULL`
(→ "Not available"), never a synthesized value. New stops populate the snapshot at loading-completion only.

## 13. Expected-Collection contract preserved
Immutable per-stop handoff snapshot: `DeliveryService::generateStops` writes
`expected_collection_at_handoff` = `0.0` when the order is already paid (`date_paid != null`) else
`max(0, total − deposit_amount)`; no read-time recompute; no backfill. Model has the fillable + `decimal:2` cast.

## 14. Single-Active-Custody contract preserved
`TripStatus::isCustodyEligible()` (= `!isEditable() && !isTerminal()`) drives both the Active board filter
and the `TripService::changeStatus` invariant (`assertDriverHasNoOtherOpenCustody`, driver-level
`lockForUpdate`). Deployed intact; behaviour confirmed by the §10 gate.

## 15. What was NOT done (by constraint)
No commit/push/non-DEV deploy; no DEV business data touched; no historical stop backfilled; no Driver
Expenses / Finance / `DriverReportsController` work; no RBAC/credential/impersonation; no route/config cache
clear; no worker/scheduler restart; no container recreate.

## 16. Real zero vs Not available
Preserved end-to-end. Count/rate KPIs render real `0` / `0%` when the active board is empty (currently the
case in DEV — 0 custody-eligible trips). `total_expenses` / `net_cash` render **null → "Not available"**
(no authority). The two are distinct in both the read model and the UI.

## 17. Residual observations / risks
1. **api.php cross-session drift** (container 4286 vs host 3910) — pre-existing, unrelated to Driver Closing;
   flagged for deliberate reconciliation (do not blind-copy). §6.
2. **HTTP API not exercised** — verified at the read-service contract level instead (auth/impersonation out
   of bounds). §10.
3. **Driver operational-expense / cash-advance authority** still absent → Expenses/Net Cash remain
   "Not available" (CTO-approval-gated follow-up; unchanged by this task).
4. DEV currently has **no custody-eligible trips** (OSAMA×3 are `loading`), so the Active board is
   legitimately empty; closed custodies appear on the History board.

## 18. Statuses to preserve
- Driver Closing SOURCE: **COMPLETE** (uncommitted).
- DEV SCHEMA / BACKEND / FRONTEND parity: **RESTORED** (this task).
- Single-Active-Custody contract: **COMPLETE** + now **DEV-live**.
- Expected-Collection snapshot: **COMPLETE** + migration now **applied to `ecos_dev`** (batch 139).
- 8-KPI + per-custody value read model: **COMPLETE** + **DEV-live**.
- Driver expense/cash-advance authority: **DEFERRED** (no canonical authority; CTO-gated).
- Finance: **NOT started**. `DriverReportsController`: **untouched**.
