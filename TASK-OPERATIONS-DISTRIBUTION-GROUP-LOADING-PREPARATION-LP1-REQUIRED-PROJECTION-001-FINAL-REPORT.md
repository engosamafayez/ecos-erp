# TASK-OPERATIONS-DISTRIBUTION-GROUP-LOADING-PREPARATION-LP1-REQUIRED-PROJECTION-001 — FINAL REPORT

**Date:** 2026-08-21
**Scope:** LP-1 only — the Group-scoped Required product projection.
**Commit status:** **NOT COMMITTED** (per instruction).

---

## 1. Existing capability reused

LP-1 is overwhelmingly a **consumer**, not a builder. Everything below already existed and was reused unchanged:

| Capability | Where it already lived | Reused as-is |
|---|---|---|
| Required-quantity calculation | `DistributionAggregationService::productAggregation()` | **yes — untouched logic** |
| HTTP exposure | `GET /windows/{window}/products?slot_id=&warehouse_id=` | **yes — no new endpoint** |
| Permission | `logistics.distribution.view` | **yes — no new permission** |
| Warehouse scoping | `scopeWarehouse()` on `orders.assigned_warehouse_id` | **yes** |
| Eligibility contract | `PreparationEligibilityReader::constrainToEligible()` | **yes** |
| Group context | `slotSummaries()` → `SlotSummary` | **yes** |
| Live refresh | the single React Query root `['logistics-distribution-workspace']` | **yes — zero mutations changed** |
| Grid presentation | `UniversalDataGrid` + `DataGridColumnDef` | **yes** |
| i18n | existing `logistics` namespace, `distributionWorkspace` block | **yes** |

**Nothing new was created on the backend except one additive field pair** (§2.1). No migration, no new service, no new endpoint, no new permission, no new domain event, no second synchronisation mechanism.

### Files changed

**Backend (2):**
- `Modules/Logistics/Distribution/Domain/Services/DistributionAggregationService.php` — additive only (§2.1)
- `tests/Feature/Logistics/DistributionGroupLoadingPreparationTest.php` — **new**, focused

**Frontend (7):**
- `components/group-loading-preparation.tsx` — **new**
- `components/distribution-groups-panel.tsx` — entry point wired in
- `hooks/use-distribution-workspace.ts` — one query key + one query hook
- `services/distribution-workspace-service.ts` — one GET method
- `types/index.ts` — one type
- `i18n/locales/{en,ar}/logistics.json` — 13 keys each, purely additive (`181 added / 0 removed`)

**Migrations created: zero.** The Distribution migration set is unchanged from Part 5B.

---

## 2. Product aggregation source

The projection calls exactly one endpoint:

```
GET /api/logistics/distribution/windows/{window}/products?slot_id={group}&warehouse_id={warehouse}
```

which is a thin controller wrapper over:

```php
DistributionAggregationService::productAggregation($windowId, $zoneId = null, $slotId, $warehouseId)
```

The client performs **no summing, no filtering and no re-derivation**. The component maps server rows to cells and nothing more, so a second quantity engine cannot come into existence in the frontend. This was verified live, not asserted: the rendered table rows and a direct API call returned byte-identical values (§15).

`warehouse_id` is sent alongside `slot_id` even though a Group belongs to exactly one warehouse. Relying on `slot_id` alone would be correct only *by accident*; sending both makes the Part 5B boundary explicit rather than incidental.

### 2.1 The one backend change — and why it exists

Part 3 mandates **Unit** in the minimum view. `productAggregation` did not return it. The two available routes were:

- a second client-side query for product units → duplicates product business data in the frontend, which Part 3 forbids;
- carry the unit on the same server row as the quantity.

I took the second. The change is:

```php
->leftJoin('units as u', 'u.id', '=', 'p.unit_id')
// …
->groupBy('ol.product_id', 'p.name', 'p.sku', 'u.code', 'u.symbol')
->select([..., 'u.code as unit_code', 'u.symbol as unit_symbol', ...])
```

**Why this is safe, stated precisely:**

- **Purely additive.** Two nullable string fields. Every pre-existing field keeps its name, type and value.
- **No quantity semantics changed.** `SUM(ol.quantity)` is untouched. The added GROUP BY columns are functionally dependent on `product_id` (a product has one unit), so grouping granularity is unchanged — proven by test 7, which asserts the quantity is still exactly `2.0` after the join.
- **Blast radius of one.** `productAggregation` has exactly one call site (`DistributionWindowController::products`) and, before LP-1, **zero** frontend consumers. There was nothing to break.
- **Verified live** against DG-001 before and after: same products, same quantities (2 and 1), units now present (`PCS`/`pcs`, `KG`/`kg`).

**This is the only backend behaviour change in LP-1, and I am flagging it rather than burying it.** If you would rather LP-1 touch no backend at all, the alternative is to drop the Unit column from Part 3's minimum view; say so and I will revert the join.

---

## 3. Group context

Every context field comes from the existing `slotSummaries()` response. **Nothing is recomputed in the frontend**, so the strip cannot disagree with the Group card above it.

| Field shown | Server source |
|---|---|
| Group (code / name) | `SlotSummary.code`, `.name` |
| Warehouse | `SlotSummary.warehouse_id` → existing `warehouseNames` map |
| Zones | `SlotSummary.zone_names` |
| Order Count | `SlotSummary.orders_count` |
| Max Orders | `SlotSummary.capacity_orders` |
| Remaining Capacity | derived — see below |

**The single derived value** is `remaining_capacity = capacity_orders − orders_count`. The server does not expose it. This is a subtraction of two server fields in the presentation layer, not a second count: both inputs are canonical, and when `capacity_orders` is `NULL` the field reads "Not limited" rather than a number.

`NULL` capacity means **unconstrained**, never zero — the same rule the existing read model and the Group create path already honour.

---

## 4. Required quantity source

```
Required(group, product) = Σ order_lines.quantity
  WHERE order_lines.order_id ∈ this Group's orders  (distribution_window_orders.virtual_slot_id)
    AND orders.assigned_warehouse_id = the Group's warehouse
    AND constrainToEligible(orders)                 (status + Preparation postponement)
```

`order_lines.quantity` (`decimal(12,4)`) remains the only definition of an order's product quantity. No second order-quantity engine exists.

**Live evidence — DG-001, real data:**

| Product | SKU | Required | Unit |
|---|---|---|---|
| Honey Jar 250g | FG-HONEY-250 | 2 | pcs |
| تجربة التعليقات | ECOS-FG-000001 | 1 | kg |

### A nuance worth naming

The Group card shows **Products 3** while Loading Preparation lists **2 rows**. Both are correct and they answer different questions:

- `SlotSummary.products_count` = `SUM(per-order COUNT(DISTINCT product_id))` — three orders each carrying one product.
- Loading Preparation = **distinct products across the Group** — one product appears in two of those orders.

I did **not** "fix" either number, because neither is wrong. Flagged so an operator comparing the two is not left thinking one is broken.

---

## 5. Prepared attribution decision — D-4 Option A, implemented as "Required only"

**No Prepared and no Remaining column is shown at all.**

Part 8 permits wave-level context *if* it can be presented unambiguously, and requires Required-only otherwise. Required-only is what LP-1 ships, for a concrete reason: the canonical `/products` endpoint does not return wave quantities, so showing them would have meant an API change requiring separate authorisation (Part 16). Rather than request one to display a number that cannot be group-attributed anyway, LP-1 omits it.

The omission is **stated in the UI**, not silent:

> "Prepared quantities are recorded per preparation wave, not per group, so they are not shown here."
>
> «تُسجَّل الكميات المُجهَّزة على مستوى موجة التجهيز وليس على مستوى المجموعة، لذلك لا تظهر هنا.»

And the caption states the positive half:

> "Products required for this group's planned departure. Required quantities are specific to this group."

There is therefore **no way to read a group-scoped Prepared figure from this screen**, because none is rendered. The `GroupRequiredProduct` type carries no `prepared` or `remaining` field, and test 6 asserts the payload contains none under any of five plausible names.

No allocation rule was invented. No Preparation schema, API or code was touched.

---

## 6. Warehouse isolation

Enforced server-side by the existing `scopeWarehouse()`, and the client always sends `warehouse_id`.

**Proven three ways:**

1. **Focused test** — two warehouses both planning Maadi: Warehouse A's Group reports only its own `7`, never B's `100` for the same product in the same geography, and never B's second product at all.
2. **Live negative control** — the same DG-001 request with a valid-shaped warehouse id that owns nothing returned **0 rows** (versus 2 for the real warehouse). The filter is genuinely applied, not silently ignored.
3. **Live request inspection** — the network call carries both `slot_id` and `warehouse_id`.

**Limitation, stated plainly:** the live environment contains exactly **one** warehouse ("Main Warehouse"), and Part 22 forbids creating a second. Two-warehouse isolation is therefore **test-verified, not browser-verified**. I am not upgrading that classification.

---

## 7. Eligibility behavior

The projection consumes `PreparationEligibilityReader::constrainToEligible()` — the same reader every other Distribution read model uses. **No second eligibility implementation exists.**

Both halves of the contract remain in force, and each has its own focused test:

- **Order status** — an order moved to `cancelled` stops contributing (100 → 4). Its Group membership row is deliberately **not** deleted: the order remains in the Group, it is simply no longer eligible work. Nothing is silently removed.
- **Preparation postponement** — an active wave membership (`released_at IS NULL`) carrying `postponed_at` takes the order out of the cycle (66 → 6) even though its status never changed.

In both cases the change lands with **zero Distribution writes**. The projection is a filtered view, so eligibility is honoured the instant it changes at source.

---

## 8. Live refresh behavior

**No new synchronisation mechanism was built, no mutation was modified, no polling was added, no WebSocket was introduced, and no domain event was created.**

The LP-1 query key is a strict prefix extension of the existing invalidation root:

```ts
all:           ['logistics-distribution-workspace']
groupProducts: [...KEYS.all, 'group-products', windowId, slotId, warehouseId]
```

TanStack Query invalidates by prefix, so the **seven** existing mutations — all of which already call `invalidateQueries({ queryKey: KEYS.all })` — refresh Loading Preparation automatically. Counts before and after LP-1: 7 mutations, 7 root invalidations. Unchanged.

| Required behaviour | Covered by | Mechanism |
|---|---|---|
| Add Zone | existing mutation | root invalidation |
| Remove Zone | existing mutation | root invalidation |
| Move Zone | existing mutation | root invalidation |
| Order becomes ineligible | live query | next fetch re-evaluates `constrainToEligible` |
| Order becomes eligible again | live query | same |

The last two produce a **stale view, never stale data** — because every quantity is computed per request rather than stored, there is no second copy that can drift. A full browser reload re-derived the identical projection (§15).

The query is enabled only while a Group's panel is open, so a window with many Groups costs one request per *opened* Group rather than one per Group that exists.

---

## 9. Empty Group behavior

An empty Group is already representable, and LP-1 adds **no** status column, no placeholder row, no fake product and no automatic deletion.

- A Group owning no Zone owns no orders → the endpoint returns `[]` and the grid shows *"No products required for this group." / «لا توجد منتجات مطلوبة لهذه المجموعة.»*
- **Live proof without creating anything:** requesting DG-001's window with a non-existent `slot_id` returned **HTTP 200 with 0 rows** — importantly, *not* a fallback to the window-wide product list, which would have been the dangerous failure mode.
- Part 10 (eligible orders but zero aggregated products) resolves to the same empty state; no product rows are invented, and the Group context strip still shows the order count, so the operator sees the discrepancy rather than an empty screen with no explanation.

---

## 10. API reuse

**No new endpoint was created.** The existing `GET /windows/{window}/products` was already sufficient and already accepted `slot_id`, `zone_id` and `warehouse_id`. It had simply never been called by any client.

The only contract movement is the two additive response fields in §2.1 — same endpoint, same route, same permission, same request shape, no removed or renamed field.

---

## 11. Permission reuse

**No permission was created.** The projection is served by `logistics.distribution.view`, unchanged, on the pre-existing route registration.

This is the correct boundary: anyone who can view a Group can already see its orders and those orders' products. A separate permission would be a new access boundary with no new data behind it.

---

## 12. i18n

- **13 new keys** under `distributionWorkspace.loadingPreparation`, in the existing `logistics` namespace. No new namespace, so no `namespaces.ts` / `i18n/types.ts` registration was needed.
- **EN/AR parity verified programmatically:** 139 keys each in `distributionWorkspace`, zero EN-only, zero AR-only.
- **No duplicate keys.** Warehouse, Zones and Orders labels reuse the existing `groups.warehouse`, `metrics.zones` and `metrics.orders`.
- **No hardcoded UI strings** — ESLint `ecos-i18n/no-hardcoded-ui-strings` is at **0** across the whole feature.
- **No "Vehicle" or "Driver" terminology** appears anywhere in the LP-1 keys. (The Group card's pre-existing inert `Vehicle: Not assigned` / `Driver: Not assigned` rows are Part 4 context and were not touched.)
- Both languages rendered correctly in the browser against real DG-001 data (§15).

---

## 13. Focused tests

**Suite:** `tests/Feature/Logistics/DistributionGroupLoadingPreparationTest.php` — **8 tests**, run together with `DistributionCoreTest` as the regression guard for the additive aggregation change.

Deliberately **not** re-tested: `DistributionCoreTest` already proves the canonical aggregation sums order lines window-wide; `DistributionWarehouseScopedReadsTest` already proves zones, slots and the order pool are warehouse-scoped. Neither covered `productAggregation` narrowed to a **Group**, warehouse-scoped, or eligibility-filtered — that gap is exactly what this file closes, and nothing else.

| # | Test | Part 19 requirement |
|---|---|---|
| 1 | `..._are_the_canonical_aggregation_result` — endpoint output is identical to the service's own output | 1. canonical aggregation consumed |
| 2 | `two_warehouses_planning_the_same_zone_get_only_their_own_work` | 2. warehouse isolation |
| 3 | `an_order_that_becomes_ineligible_stops_contributing` | 3. eligibility (status) |
| 4 | `a_postponed_preparation_member_stops_contributing` | 3. eligibility (postponement) |
| 5 | `an_empty_group_reports_no_required_products` | 5. empty Group |
| 6 | `..._never_reports_a_group_prepared_or_remaining_quantity` | 6. no false Prepared attribution |
| 7 | `the_unit_of_measure_travels_with_the_required_quantity` | guards the §2.1 additive change |
| 8 | `reading_loading_preparation_writes_nothing` | Parts 13/15 — projection only |

Requirement 4 (Group change refresh/invalidation) is a frontend concern and is evidenced structurally in §8 — the LP-1 key is a strict prefix extension of the root that all 7 existing mutations already invalidate — plus tests 3 and 4, which prove the underlying data re-derives with no Distribution write.

No fabricated live business data. Every fixture is created inside `RefreshDatabase` and torn down with it.

**RESULT: 31 / 31 tests, 203 assertions, 7m44s — GREEN.**

That is my 8 LP-1 tests plus the 23 `DistributionCoreTest` tests, run together so the additive aggregation change (§2.1) is regression-guarded by the suite that already owned `productAggregation`.

**One correction worth recording rather than hiding.** The first run errored on all 8 LP-1 tests with `Undefined array key "slot_id"`. That was a **fixture mistake of mine, not an implementation defect**: `POST /windows/{window}/slots` returns the raw `VirtualCapacitySlot` model, whose key is `id`, while `slot_id` is the key used by the *read model* (`slotSummaries()`). I had carried the read-model name into the create path. Every failure was at group-creation, so no test reached the code under audit. Corrected to `$group['id']` (22 call sites) and re-run clean. Notably, `DistributionCoreTest` passed 23/23 in that first run too, so the aggregation change was already clear at that point.

---

## 14. Static gates

| Gate | Scope | Result |
|---|---|---|
| ESLint (`ecos-i18n` + TS rules) | whole `distribution-workspace` feature | **0 problems** |
| TypeScript `tsc -p tsconfig.app.json` | app | **23 errors — unchanged baseline; 0 in this feature** |
| Vite production build | app | **exit 0**, built in 6.01s |
| PHPStan | the 2 touched backend files | **No errors** |
| Pint | the 2 touched backend files | new test file **clean**; service reports 9 fixers — **all pre-existing** |

**On the Pint result, measured rather than assumed:** I reconstructed the pre-LP-1 version of `DistributionAggregationService.php` by reversing my edit and ran Pint against it. It fails with the **identical 9 fixers**. LP-1 therefore adds **zero** new Pint debt; the file carries pre-existing style debt from Parts 1–5C and has never been committed, so it has never passed a Pint gate. I did not reformat it — that would be an unrelated whole-file diff.

Backend files *were* touched, so the backend static gates above were run rather than skipped.

---

## 15. Browser acceptance

Real dev stack, real **DG-001**, real data. No Group created, no Warehouse created, no order created, no permanent alteration.

| # | Check | Result |
|---|---|---|
| 1 | Open DG-001 | **PASS** |
| 2 | Open Loading Preparation | **PASS** — its own entry point on the Group card |
| 3 | Group identity | **PASS** — DG-001 |
| 4 | Warehouse | **PASS** — Main Warehouse |
| 5 | Zones | **PASS** — Maadi |
| 6 | Order count | **PASS** — 3, matching the Group card |
| 7 | Capacity | **PASS** — "Not limited" (live `capacity_orders` is `NULL`) |
| 8 | Required products displayed | **PASS** — 2 rows with SKU and unit |
| 9 | Quantities match canonical backend | **PASS** — UI rows byte-identical to a direct API call |
| 10 | No other warehouse's products | **NOT BROWSER VERIFIED** — only one warehouse exists live; negative control returned 0 rows; two-warehouse case is test-verified (§6) |
| 11 | Ineligible/postponed excluded | **NOT BROWSER VERIFIED** — would require mutating live business data; test-verified (§7) |
| 12 | Empty state | **PASS** — unknown `slot_id` returned 200 with 0 rows, no window-wide fallback |
| 13 | Full reload preserves projection | **PASS** — re-derived identically |
| 14 | No side effects | **PASS** — see §16 |

Rendered English:

```
Loading Preparation
Products required for this group's planned departure. Required quantities are specific to this group.
WAREHOUSE Main Warehouse | ZONES Maadi | ORDERS 3 | MAX ORDERS Not limited | REMAINING CAPACITY Not limited
Product              SKU              Required  Unit
Honey Jar 250g       FG-HONEY-250     2         pcs
تجربة التعليقات        ECOS-FG-000001   1         kg
Prepared quantities are recorded per preparation wave, not per group, so they are not shown here.
```

Rendered Arabic:

```
تجهيز التحميل
المنتجات المطلوبة لمغادرة هذه المجموعة. الكميات المطلوبة خاصة بهذه المجموعة وحدها.
المستودع Main Warehouse | المناطق Maadi | الطلبات 3 | الحد الأقصى للطلبات غير محدود | السعة المتبقية غير محدود
المنتج | رمز الصنف | المطلوب | الوحدة
تُسجَّل الكميات المُجهَّزة على مستوى موجة التجهيز وليس على مستوى المجموعة، لذلك لا تظهر هنا.
```

Browser language was restored to `en` afterwards.

**Overall browser classification: BROWSER VERIFIED for items 1–9 and 12–14; items 10 and 11 are NOT BROWSER VERIFIED** and are covered by focused tests instead. That classification is not upgraded.

---

## 16. Business-data side effects

**None.** LP-1 issues only `GET` requests.

Post-verification database state:

```
loading_sessions        0        vehicle_plans        0
loading_tasks           0        vehicle_plan_slots   0
vehicle_assignments     0        prepared_products_pool 0
stock_movements         0        stock_ledger_entries  24  (pre-existing; LP-1 issued no writes)

DG-001 — orders_in_group: 3   zones_in_group: 1   capacity_orders: NULL   (unchanged)
```

No Group, Warehouse, order, receipt, vehicle, driver or loading task was created. No inventory was reserved, consumed or moved. No FIFO layer was created. No Preparation quantity was altered. No Order status was changed.

Test 8 proves the same property in a controlled environment by snapshotting seven tables plus the order's status before and after repeated reads.

---

## 17. Limitations

Stated rather than implied:

1. **D-1 (capacity enforcement) is NOT implemented — reported, per the task's own STOP clause.** Enforcing `current_orders <= capacity_orders` requires backend **mutation** changes, which are outside LP-1's approved read/projection scope. The exact required change:
   - a capacity guard in `ManualAssignmentService::assignZoneToSlot()` (line 41), `::moveZone()` (line 148), `::changeOrderSlot()` (line 228) and `::assignLateOrder()` (line 264) — `detachZone()` needs none, since removing cannot overflow;
   - a decision on `DistributionCollectionService::collect()`, where auto-assignment can push a Group over capacity with no operator action involved;
   - the read model already supplies everything needed (`capacity_orders`, `demand_orders`, `overflow_orders`, `is_over_capacity`), so no migration and no new query would be required.

   **Note:** the rule is currently unenforceable in practice regardless — the Group create path deliberately never sends capacities, so **every live Group has `capacity_orders = NULL`**. There is nothing to enforce until a maximum can be set, which is itself a separate UI change.

2. **D-2 — the canonical count.** LP-1 uses `SlotSummary.orders_count`, the Group's canonical read-model headline (it comes from the same aggregate as value, products and the payment split). The backend separately computes `demand_orders` via `slotOrderCounts()` for its capacity maths. **Both returned 3 for DG-001 live**, so they agree today, but they remain two queries answering one question — a divergence risk in the backend that LP-1 does not introduce and is not authorised to fix.

3. **Two-warehouse isolation and eligibility exclusion are test-verified, not browser-verified** (§15 items 10–11), because the live environment has one warehouse and proving exclusion would mean mutating live business data.

4. **"No Warehouse" behaviour is unchanged and remains out of scope**, exactly as Part 11 directs. Orders with `assigned_warehouse_id IS NULL` are still excluded from every warehouse-scoped read and therefore cannot enter Loading Preparation. This is a pre-existing finding from the architecture audit (decision **D-6**), not a regression introduced here.

5. **Window closure / carry-over is untouched** (Part 12). BLOCKER-2 from the architecture report stands: Windows never close, so a stale Group will keep reporting required products indefinitely. LP-1 makes that consequence more visible without solving it.

6. **Prepared and Remaining are absent by design**, not deferred by oversight (§5).

7. **The §2.1 backend join is the one deviation from a pure-frontend LP-1.** It is additive, single-call-site and test-guarded, but it is a backend change and is reported as such.

---

## 18. Final verdict

### **LP-1 — IMPLEMENTED / VERIFIED**

Measured against the task's own verdict criteria:

| Criterion | Status | Evidence |
|---|---|---|
| Canonical Required projection consumed correctly | **PASS** | Test 1 asserts endpoint output is identical to `productAggregation()`'s own output; live UI rows byte-identical to a direct API call |
| Group / warehouse scope correct | **PASS** | Test 2 (two warehouses, one shared Zone); live negative control returned 0 rows for a non-owning warehouse |
| Eligibility correct | **PASS** | Tests 3 and 4 — status and Preparation postponement, both halves |
| Refresh behaviour works | **PASS** | LP-1 key is a strict prefix extension of the root all 7 existing mutations already invalidate; full browser reload re-derived identically |
| No duplicate quantity engine | **PASS** | Client maps server rows only; `order_lines.quantity` remains the single definition; test 1 would drift if the endpoint grew its own arithmetic |
| Prepared not falsely attributed | **PASS** | No Prepared/Remaining column exists; test 6 asserts the payload carries none under five names; the UI states why |
| Focused tests green | **PASS** | **31 / 31, 203 assertions** |
| Static gates green | **PASS** | ESLint 0 · tsc 23 (unchanged baseline, 0 in feature) · Vite exit 0 · PHPStan no errors · Pint adds no new debt |

### Browser classification — reported separately, and not upgraded

**BROWSER VERIFIED** for Part 21 items 1–9 and 12–14, against real DG-001 in both English and Arabic.

**NOT BROWSER VERIFIED** for items 10 and 11:

- **10 (no other warehouse's products)** — the live environment contains exactly one warehouse, and Part 22 forbids creating a second. Covered by test 2 and by a live negative control.
- **11 (ineligible/postponed excluded)** — proving it live would require mutating real business data. Covered by tests 3 and 4.

### Boundaries held

No Prepared attribution. No Group-specific Remaining. No Actual Loading, Loading Task, Vehicle Plan, Vehicle or Driver assignment, Approval, Finalize or Dispatch. No Window closure or carry-over. No "No Warehouse" redesign. No Preparation change. No Inventory change. No migration. No new permission. No new endpoint.

### Two items needing your decision before the next slice

1. **D-1 capacity enforcement is reported, not implemented** — correctly, under the task's own STOP clause: it requires backend *mutation* changes outside LP-1's read/projection scope. The exact surface is named in §17.1. It is also unenforceable today regardless, since every live Group has `capacity_orders = NULL`.
2. **The one backend touch (§2.1)** — a two-field additive unit join, made because Part 3 mandates a Unit column the canonical aggregation did not return. If you would rather LP-1 touch no backend at all, the alternative is dropping Unit from the minimum view; say so and I will revert the join.

---

**No commit was made.**
