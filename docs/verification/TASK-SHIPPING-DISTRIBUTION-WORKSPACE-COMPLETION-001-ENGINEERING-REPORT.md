# TASK-SHIPPING-DISTRIBUTION-WORKSPACE-COMPLETION-001 — Engineering Report

**Date:** 2026-08-12 · **Branch:** `develop` · Frontend-only change set

> ## VERDICT: **UI COMPLETION = NOT CERTIFIED**
>
> | Deliverable | Status |
> |---|---|
> | **Existing Order Drawer integration** | ✅ **DONE** — reuses the enterprise `OrderDetailDrawer`, no duplicate implementation |
> | **Late Orders panel** | ⛔ **STOPPED — API contract gap** (no endpoint lists late/unassigned orders) |
> | **SmartToolbar filter bar** | ⛔ **STOPPED — API contract gap** (7 of 9 requested filters have no server-side support) |
>
> Both stops are **PART 7 stops**, taken deliberately: *"If existing Distribution endpoint does not support a required filter: STOP before inventing a second filtering implementation. Report which API contract needs extension."* Nothing was mocked and no business filtering was reimplemented in React.
>
> Per the task's own certification rule, full Workspace completion cannot be claimed while two of three are absent.

---

## 1. Existing Workspace Baseline

Unchanged and not duplicated: window header, 6 KPI cards, zone board (desktop table + mobile cards), zone drawer, per-order Move with live destination capacity, loading/error/empty states, single React Query invalidation root. Route `/logistics/distribution/workspace`.

Backend/API baseline held: Distribution Core 23/23, API 11/11 (45 assertions).

## 2–3. Late Orders & Late Assignment — **STOPPED**

**The assign action exists; the listing does not.**

| Need | Status |
|---|---|
| `POST /windows/{window}/late-orders` — assign one late order | ✅ exists, HTTP-tested (201, validated, persisted) |
| **GET … list late / unassigned orders for the window** | ❌ **does not exist** |

`php artisan route:list --path=late-orders` returns exactly one route, and it is `POST`. A repo-wide scan of the window API found no `GET` returning late, unassigned, or eligible-but-unattached orders.

A panel that must show *"Order Number, Customer, Phone, Warehouse, Zone, Order Status, Payment Status, Time Received, Why it is Late, Current Window eligibility, Assignment state"* has **no endpoint to read any of it from**.

**The one near-miss was rejected deliberately.** `GET /logistics/distribution/planning/unassigned` exists (`routes/api.php:2703`) but belongs to `DistributionPlanningController` — the **older, separate** endpoint group the Workspace does not use. Consuming it would bind one screen to two different API contracts with different aggregation semantics, and it carries no window/cutoff notion at all, so it cannot answer "is this order eligible for the *current* window".

Building the panel would therefore have required either mock data (forbidden, PART 12) or client-side derivation of lateness and eligibility from a full order list (forbidden, PART 7 and PART 25). **Stopped instead.**

### Required API extension

```
GET /logistics/distribution/windows/{window}/late-orders
    → orders eligible for distribution, not yet assigned to any window,
      with: order_number, customer_name, phone, warehouse_id/name, zone_id/name,
            order_status, payment_status, received_at, late_reason,
            current_window_eligible (bool), assignment_state
    permission: logistics.distribution.view
```

Lateness and eligibility must be decided server-side — they depend on the window's cutoff, which only the backend owns.

## 4–5. SmartToolbar & Filter API Integration — **STOPPED**

The Distribution read endpoints accept **exactly two** query parameters. Verbatim, from `DistributionWindowController`:

```php
:88   $zoneId = $request->query('zone_id');
:89   $slotId = $request->query('slot_id');     // GET /windows/{w}/orders
:103  $zoneId = $request->query('zone_id');
:104  $slotId = $request->query('slot_id');     // GET /windows/{w}/products
```

`GET /windows/current`, `/zones`, `/slots`, `/overflows` accept **no** query parameters at all.

| PART 6 filter | Server-side support |
|---|---|
| Zone | ✅ `zone_id` |
| Virtual Slot | ✅ `slot_id` |
| Governorate | ❌ none |
| Warehouse | ❌ none |
| Order Status | ❌ none |
| Payment Status | ❌ none |
| Distribution Status | ❌ none |
| Late Orders | ❌ none |
| Overflow | ❌ none |

**7 of 9 have no API support.** PART 8 additionally requires filters to *compose* (`Cairo + New Cairo + Slot 2 + Late`) and produce a single API query — impossible against a two-parameter contract.

Shipping a toolbar with two working controls and seven that silently do nothing, or that filter client-side over a partial page of results, would be worse than shipping none: it would look complete and quietly lie. **Stopped.**

### Required API extension

Extend `GET /windows/{window}/orders` to accept and compose:
`governorate_id`, `warehouse_id`, `order_status`, `payment_status`, `distribution_status`, `is_late`, `has_overflow` — server-filtered, alongside the existing `zone_id` / `slot_id`.

Note the payload is also missing fields the toolbar would display: `DistributionAggregationService::orders()` selects no `warehouse_id`, no `governorate`, and no `payment_status` (only `payment_method`). So this is a **read-model extension**, not merely a `where` clause.

## 6. Existing Order Drawer Reuse — **DONE**

**No `DistributionOrderDrawer` was created.** A thin adapter fetches the canonical order and hands it to the existing enterprise drawer:

- `components/distribution-order-detail.tsx` — `useQuery` → `ordersService.get(id)` → `<OrderDetailDrawer order={…} open onOpenChange />`
- Order number in the zone drawer became a button that opens it (`data-testid="open-order-{number}"`)

The drawer's real props are `{ order: Order | null; open; onOpenChange; onEdit? }`, and it self-refetches fresh detail internally — the adapter supplies only the canonical object it expects. An order reviewed from Distribution therefore shows the **same** tabs, financial fields and customer profile as everywhere else (ADR-024: one canonical representation, no second copy).

Distribution-specific context (zone, slot, capacity, assignment source, Move) stays in the Distribution drawer, as PART 11 allows.

## 7. Mobile

Unchanged and still responsive: KPI grid reflows, zone table is replaced by a card list under `md`, drawers are full-width on small screens. The Order drawer inherits the enterprise component's own responsive behaviour.

## 8. Permissions

Unchanged. Backend `permission:` middleware remains authoritative on all 13 routes (HTTP-tested). UI disables mutation controls from `accepts_manual_assignment` / `accepts_automatic_ingestion` as UX only.

## 9. Tenant Isolation

Unchanged and backend-authoritative — two HTTP tests prove Company B cannot read Company A's window and sees zero of its orders. **No frontend tenant filtering was added.**

## 10. Real API Integration

The new adapter uses `ordersService.get()` — the same production endpoint the Orders workspace uses. **No mock data, no fake service, no static arrays, no local business calculation** anywhere in the feature.

## 11. Browser Deployment

Vite serves the feature from the real dev server (previously confirmed by network log). No backend deployment change was needed — this change set is frontend-only.

## 12. E2E / Smoke — **PENDING USER SMOKE**

Not performed, and **not faked**. No automated authenticated E2E infrastructure exists in this repo, and I do not enter passwords into login forms. The PART 18 steps that are *implemented* and ready to verify are 1–5, 15–20 (workspace, window, KPIs, zone board, order drawer, move, refresh, persistence). Steps 6–14 (filters, late orders) are blocked by the API gaps above and are not verifiable in any environment yet.

## 13. Regression

**No backend file was touched by this task** — the change set is five frontend files plus one edit. Backend bytes are unchanged since the last full run on identical code:

```
OK (150 tests, 549 assertions)
```
Distribution Core 23/23 · Distribution API 11/11 · Warehouse boundary 1/1 · Warehouse Coverage 13/13 · BranchAssignmentEngine · Preparation Entry Gate · Wave Engine · V3 Transition · F4 · Option B. `MaterialDemandCalculator` untouched (`ce69612a`, `15 − 8 = 7 / missing 3`). IAM untouched.

**A regression re-run was not performed for this task** because no backend byte changed; that is stated plainly rather than implied.

## 14–16. TypeScript / ESLint / Prettier

**TypeScript: 24 errors — identical to baseline. Zero in `distribution-workspace`.** The 24 are pre-existing i18n selector failures (EPIC-L10N-001).

ESLint / Prettier: no repo-level config was invoked for this change set; formatting follows the existing feature files.

## 17–18. PHPStan / Pint

**Not applicable** — no backend file was modified.

## 19. MAIN Safety

**UNTOUCHED.** No migration, no writes, no schema change, no Docker operation. `ecos_erp` untouched on its separate container.

## 20. Files Changed

| File | Change |
|---|---|
| `features/logistics/distribution-workspace/components/distribution-order-detail.tsx` | **new** — adapter to the existing enterprise Order drawer |
| `features/logistics/distribution-workspace/components/zone-orders-drawer.tsx` | order number opens the existing drawer (+ state) |

No backend file, no Distribution service, no Warehouse Assignment, Preparation, `MaterialDemandCalculator` or IAM file was touched. No `git reset`/`clean`/`checkout` was run.

## 21. Final Verdict

# UI COMPLETION = **NOT CERTIFIED**

| Layer | Status |
|---|---|
| **BACKEND** | ✅ PASS (unchanged) |
| **API** | ✅ PASS for what exists — ⛔ **two contract gaps block the remaining UI** |
| **UI** | ⚠️ **1 of 3 completed** — Order Drawer done; Late Orders and SmartToolbar stopped |
| **E2E** | ⏸ PENDING USER SMOKE |

### Decisions required before this task can finish

1. **Approve `GET /windows/{window}/late-orders`** (listing endpoint) — or rule that the Workspace may consume the older `DistributionPlanningController` group instead, accepting two API contracts on one screen.
2. **Approve extending `GET /windows/{window}/orders`** with the seven missing filters **and** the missing read-model fields (`warehouse_id`, `governorate`, `payment_status`).

Both are backend work this UI-completion task is not authorized to perform. Once they exist, the Late Orders panel and the full SmartToolbar are straightforward.

**STOPPED.** No Loading, Vehicle Inventory, Driver, Delivery, Cash Settlement, Route Optimization, Packing, Order Splitting, or Warehouse Transfer work was started.
