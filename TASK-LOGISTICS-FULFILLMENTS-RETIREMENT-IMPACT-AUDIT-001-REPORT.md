# TASK-LOGISTICS-FULFILLMENTS-RETIREMENT-IMPACT-AUDIT-001 — REPORT

**Strict read-only dependency & impact audit. No code, schema, data, route,
navigation, permission, or deployment was changed.**

## 1. Executive Summary

The legacy **Fulfillments workspace** (`Modules\Commerce\Fulfillments` + the
`frontend/src/features/fulfillments` UI) is a **self-contained, isolated surface**.
Nothing canonical (Orders, the order-lifecycle engine, Inventory, Reservation,
Preparation, Distribution, Zones, Templates, Loading, Trips, Vehicles, Drivers,
Delivery, Manufacturing) references its model, table, or `fulfillment_id`. The only
inbound references to the UI are three navigation-chrome entries; the only inbound FK to
its tables is its own child (`fulfillment_lines`).

The one coupling is **outbound and severable**: the `fulfill` action reaches *into*
shared inventory (deducts `StockBalance`, would write a `SalesIssue` stock-ledger row
tagged with the *string* `reference_type='fulfillment'`, and best-effort channel sync).
This is a one-way call via a string label, not a schema FK — and it has **never been
exercised in this database** (the sole fulfillment is `pending`, and there are **0**
ledger rows referencing a fulfillment).

Because (a) the UI is provably isolated and (b) minimal **historical data exists**
(1 `fulfillments` row + 1 `fulfillment_lines` row) and the backend is a real, if
dormant, stock-out capability, the safe recommendation is to retire the **UI only** and
**preserve the backend module, tables, routes and data**.

**FINAL VERDICT: A — SAFE TO RETIRE UI ONLY.** (See §19.)

## 2. Current Fulfillment Architecture — two DISTINCT "Fulfillment" things

| | Legacy **Workspace** (retirement target) | Canonical **Engine** (NOT the target) |
|---|---|---|
| Namespace | `Modules\Commerce\Fulfillments` (plural) | `Modules\Operations\Fulfillment` (singular) |
| Backing table | `fulfillments` + `fulfillment_lines` | none — mutates `orders` / `order_events` |
| Controller | `Commerce\...\FulfillmentController` (index/show/store/update/destroy/fulfill/cancel) | `Operations\...\FulfillmentController` + `BulkFulfillmentController` + `FulfillmentEngine` + 25 workflows |
| Routes | `apiResource fulfillments` + `/fulfill` + `/cancel` (`api.php:586-591`) | `fulfillment/orders/{order}/...` (`api.php:1086+`) |
| Permission ns | `sales.fulfillments.{view,create,update,delete}` | `operations.fulfillment.{view,manage}` |
| UI | `features/fulfillments` (`/fulfillments`) | order status transitions in `features/orders` (`/fulfillment/...` singular) + `fulfillment-wave-workspace-page` (Preparation) |
| Domain object | a `Fulfillment` record (FUL-xxxxx) | an `Order` in a state machine |

`FulfillmentEngine` imports only `Order`/`OrderEvent`; it never touches the
`fulfillments` table or the workspace model. The name collision + a shared audit label
(`module: 'fulfillment'`) are the only things linking them — **no code dependency**.

## 3. Frontend Dependency Audit

Feature `frontend/src/features/fulfillments/`: pages `fulfillments-page`,
`create-fulfillment-page`, `view-fulfillment-page`; components `fulfillment-status-badge`,
`fulfillment-header-fields`, `fulfillment-lines-editor`, `fulfillment-form-schema`;
hooks `use-fulfillments` (+ `use-order-options`, `use-warehouse-options`); service
`fulfillments-service` (→ `/fulfillments*` only); types `fulfillment`; i18n namespace
`fulfillments` (en+ar, registered in `namespaces.ts:44`, typed in `types.ts:52`).

**Inbound links (the only things that break if the page is removed) — all navigation
chrome:**
1. `config/module-navigation.ts` — Shipping module `fulfillments` item **and the
   module's `defaultPath: ROUTES.fulfillments`** (so a new Shipping default landing page
   must be chosen).
2. `config/navigation.ts:77` — legacy nav entry (dead; not imported anywhere).
3. `components/command-center/command-groups.ts:119` — command-palette `nav.fulfillments`.
Plus route mounts `router.ts:357-359` and constants `routes.ts:32-33`. **No** dashboard
card, quick action, order page, distribution page, breadcrumb, or mobile menu links in,
and **no other feature imports** `@/features/fulfillments/*`. No UI permission guards.

## 4. Backend Dependency Audit

`Modules\Commerce\Fulfillments`: controller, actions (`Create/Update/Delete/Get/List/
Fulfill/Cancel`), repository (`EloquentFulfillmentRepository`, `FUL-` numbering), DTO,
resource, requests, enum (`Pending/Fulfilled/Cancelled`), exceptions, provider, seeder.
**No policy, no events, no listeners, no observers, no jobs, no commands, no
notifications.** Routes `api.php:586-591` gated by `sales.fulfillments.*`. Grep of
`Modules\**` for `fulfillment_id`/workspace-model/`'fulfillments'` returned hits **only
inside the workspace module** (plus one unrelated IAM landing-page string). Nothing
canonical imports the model.

## 5. Database Dependency Audit

Two tables (migrations `2026_06_23_300000/300001`):
- `fulfillments` (uuid PK, `fulfillment_number` unique, `order_id`→orders restrict,
  `warehouse_id`→warehouses restrict, `fulfillment_date`, `status` default pending,
  `notes`, softDeletes) — **1 row** (status `pending`).
- `fulfillment_lines` (`fulfillment_id`→fulfillments **cascade**, `product_id`→products
  restrict, `quantity`) — **1 row**.

**The only inbound FK to `fulfillments` is `fulfillment_lines.fulfillment_id`** (its own
child). `fulfillments` points *outward* to orders/warehouses; no orders/inventory/
preparation/distribution/loading/vehicle/driver table has a `fulfillment_id` column.
`stock_ledger_entries` / `stock_movements` carry a generic `reference_type/reference_id`
pair but **0 rows** reference a fulfillment today.

## 6. Orders Impact — NO dependency

1. Order created without a Fulfillment? **Yes.** 2. Full lifecycle without a Fulfillment
record? **Yes** — it runs through the **engine** on `orders`. 3. Any Order
service/action creates a Fulfillment? **No.** 4. Any status transition requires one?
**No.** 5. Any certified Order workflow queries `fulfillment_id`? **No.** 6. Part of
ADR-010 / Order-Driven Fulfillment? **No** — ADR-010/015 is the *engine*
(`Modules\Operations\Fulfillment`), not the workspace. Direction is reversed: the
workspace `belongsTo(Order)` / validates `exists:orders,id`; Orders does not know the
workspace exists.

## 7. Inventory Impact — one-way, severable, currently un-exercised

1. Creating a Fulfillment reserves stock? **No** (create only persists the record).
2. Consumes stock? **Yes, at `fulfill`** — `FulfillFulfillmentAction` locks
`StockBalance` (Purchasing) and decrements it, throwing `InsufficientStockException` if
short. 3. Creates Stock Ledger entries? **Yes at `fulfill`** — a `SalesIssue` movement
with `reference_type='fulfillment'`, `reference_id=<uuid>` (a **string** tag, not a FK).
4. Inventory references `fulfillment_id`? **No** — no such column; Inventory has zero
structural knowledge of fulfillments. 5. Preparation depends on Fulfillment? **No.**
6. Distribution depends on Fulfillment? **No.** The coupling is outbound only and, with
the sole record still `pending`, has produced **0** ledger rows.

## 8. Preparation Impact — NO dependency

Canonical flow is `Order → Reservation → Preparation Wave → Distribution`. Grep of
`Modules\Operations\Preparation` for the workspace model / `fulfillment_id`: **zero
hits.** Preparation reads reservations and `orders`, never `fulfillments`.

## 9. Distribution Impact — NO dependency

Distribution Planning, Groups, Zones, Templates, `distribution_window_orders`,
`distribution_slot_zones`, `distribution_trips` reference **orders**, not fulfillments.
Grep of `Modules\Logistics\Distribution` for the workspace model / `fulfillment_id`:
**zero hits.** **The current Distribution workflow operates entirely without the
Fulfillments workspace.**

## 10. Loading Impact — NO dependency

`Modules\Operations\Loading` / loading sessions / `vehicle_assignments`: **zero**
workspace-model or `fulfillment_id` references. (Incidental: the Loading UI page
currently throws a pre-existing runtime error — `sessions.data?.map is not a function` —
**unrelated** to Fulfillments; not in scope and not touched.)

## 11. Driver / Delivery Impact — NO dependency

Trips, Driver Wave 1/2, Delivery, Driver Home, payment proof, failure reasons, vehicle/
driver assignment: **zero** workspace references. Any "fulfillment" in these paths is the
Operations engine (e.g. `LoadVehicleWorkflow`), on `orders`.

## 12. API Impact

Five workspace endpoints only: `GET/POST /fulfillments`, `GET/PUT/DELETE
/fulfillments/{id}`, `POST /fulfillments/{id}/fulfill`, `POST /fulfillments/{id}/cancel`.
Callers: **only** the `features/fulfillments` UI. Not called by Orders, Inventory,
Preparation, Distribution, Loading, Drivers, Delivery, any external integration,
scheduled job, or event listener. None is unsafe to retire (all UI-driven).

## 13. Event / Job Impact — none

The workspace module has no events, listeners, observers, queued jobs, scheduled
commands, notifications, or webhooks. No background process depends on it (including
indirect). All "Fulfillment"-named events/listeners/commands belong to the **engine**
(operate on orders).

## 14. Permission Impact

`sales.fulfillments.{view,create,update,delete}` (`config/permissions.php`) is consumed
**only** by the five workspace routes; no Orders/Inventory/Preparation/Distribution/
Loading/Driver route reuses it. The engine's `operations.fulfillment.{view,manage}` is a
separate namespace. Permission strings appear in many role rows in `config/permissions.php`
(config, not a functional dependency).

## 15. Browser Verification (read-only; no mutation, no form submit, no create)

- **Fulfillments** page loads at `/app/fulfillments` (breadcrumb Home › Fulfillments,
  "New Fulfillment" present, row FUL-00001 → ORD-00008 = the 1 DB row).
- **Distribution Planning** loads independently (wave PREP-202608-000008 active).
- **Preparation Workspace** loads independently.
- **Drivers** loads independently. **Vehicles** loads independently.
- **Loading** page throws a pre-existing, Fulfillment-unrelated runtime error (noted §10).
- No Fulfillment was created; no destructive control clicked; no form submitted.

## 16. Impact Matrix

| Area | Depends on Fulfillment? | Evidence | Retirement Impact |
|------|-------------------------|----------|-------------------|
| Orders | NO | no `fulfillment_id`/model ref in `Modules\Commerce\Orders`; lifecycle via engine on `orders` | None |
| Reservation | NO | workspace deducts `StockBalance` directly, never reservations | None |
| Inventory | NO (inbound) | no `fulfillment_id` column; only outbound `reference_type='fulfillment'` string tag, 0 rows | None (a dormant stock-out path is removed) |
| Stock Ledger | NO | `stock_ledger_entries`/`stock_movements` have generic ref cols; **0** fulfillment rows | None |
| Preparation | NO | zero refs in `Modules\Operations\Preparation` | None |
| Manufacturing | NO | zero refs in manufacturing code | None |
| Distribution | NO | zero refs in `Modules\Logistics\Distribution`; uses `orders` | None |
| Zones | NO | `distribution_zones`/`slot_zones` no fulfillment ref | None |
| Templates | NO | `distribution_group_templates` no fulfillment ref | None |
| Loading | NO | zero refs; loading uses `orders`/sessions | None |
| Trips | NO | `distribution_trips` references orders | None |
| Vehicles | NO | `vehicle_assignments` references orders/drivers | None |
| Drivers | NO | zero refs | None |
| Delivery | NO | zero refs | None |
| External integrations | NO | only best-effort outbound `SyncStockAction` from `fulfill`; nothing inbound | None |

## 17. Safe-to-Retire Classification (per DB object / artifact)

- `fulfillments` table — **B/C boundary → keep (Class B "legacy but historical data
  should remain").** Isolated (only inbound FK is its own child), but holds 1 record.
- `fulfillment_lines` table — same; child of the above.
- Backend module (`Modules\Commerce\Fulfillments`) — isolated; a dormant stock-out
  capability. **Keep** (do not drop; nothing else depends, but it holds data + a real
  action).
- Frontend feature + nav/route/command-palette entries — **Class A: safe to retire.**
- No artifact is Class C (shared by another workflow) or Class D (unclear).
**No table should be dropped.** Historical data exists → it should remain.

## 18. Required Follow-up (for a future, separately-approved removal task — NOT done here)

If the UI is retired later, the removal task would need to: remove the `fulfillments`
nav item and **reassign the Shipping module's `defaultPath`** (currently
`ROUTES.fulfillments`) to another Shipping page; remove the command-palette entry and the
dead `navigation.ts` line; unmount the three routes (or redirect them); optionally remove
the `fulfillments` i18n namespace. Backend module, routes, tables, permissions and data
**remain**. None of this is performed in this audit.

## 19. Final Verdict

**A — SAFE TO RETIRE UI ONLY.**
The Fulfillments UI can be removed safely: it is reachable only through three
navigation-chrome references and no other frontend/backend workflow depends on it. The
backend module, its five routes, its two tables, its `sales.fulfillments.*` permissions,
and its 1 historical record **should remain** — because historical data exists, the
`fulfill` action is a real (currently dormant) stock-out path that writes
`reference_type='fulfillment'` ledger rows, and the task forbids dropping tables that
merely appear unused. This is **not** verdict B (backend is not "unused" — it holds data
and a live capability), **not** C (no evidence the operational capability is obsolete —
only that it is unused by other modules), and **not** D (no real blocking dependency
exists).

## 20. Exact files inspected

**Backend:** `Modules\Commerce\Fulfillments\` (Controller, `Domain\Models\Fulfillment`,
`FulfillmentLine`, Actions `Create/Update/Delete/Get/List/Fulfill/Cancel`,
`EloquentFulfillmentRepository`, `FulfillmentDTO`, `FulfillmentResource`, Store/Update
requests, `FulfillmentStatus` enum, exceptions, `FulfillmentServiceProvider`,
`FulfillmentSeeder`, migrations `2026_06_23_300000/300001`); `backend/routes/api.php`
(586-591, 1086+); `backend/config/permissions.php`; `Modules\Operations\Fulfillment\`
(engine + workflows, for contrast); grep of `Modules\**` for
`fulfillment_id`/model/`'fulfillments'`.
**Frontend:** `features/fulfillments/**`; `router/routes.ts`, `router/router.ts`;
`config/module-navigation.ts`, `config/navigation.ts`,
`components/command-center/command-groups.ts`; `i18n/namespaces.ts`, `types.ts`,
`locales/{en,ar}/fulfillments.json`.
**Database (read-only):** `information_schema` (tables/columns/FKs), `fulfillments`,
`fulfillment_lines`, `stock_movements`, `stock_ledger_entries` row/reference checks.
**Tests:** no fulfillment-workspace tests were required to be run; none were modified.

## 21. Data Safety Statement (Part 17 confirmation)

- No files modified. No migration created. No migration executed. No database data
  changed. No routes changed. No navigation changed. No permissions changed. No canonical
  workflow changed. No deployment performed. No commit created. No follow-up task started.
- All DB access was read-only `SELECT` / `information_schema`. All browser interaction was
  navigation + DOM reads; no forms submitted, no "New Fulfillment" created, no destructive
  control clicked.
