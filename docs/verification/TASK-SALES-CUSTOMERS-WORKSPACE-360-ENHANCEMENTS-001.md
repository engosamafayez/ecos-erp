# TASK-SALES-CUSTOMERS-WORKSPACE-360-ENHANCEMENTS-001

**Date:** 2026-08-14 · Static verification + read-only SQL only. No PHPUnit, no E2E, no runtime tests.
**Status:** **IMPLEMENTATION COMPLETE — AWAITING MANUAL REVIEW.** Not certified.

Scope respected: **Sales/Commerce `/customers` only. CRM Customers Workspace was not modified.**

---

## A — Root cause for the Customer list

**There is no defect. Nothing was hiding order-linked customers.** I checked before assuming:

```
customers          : 4 rows, ALL with company_id = 019f4e1c…, none soft-deleted, all is_active = 1
orders (not deleted): 6 rows, 3 distinct customers
every order's company_id == that customer's company_id
```

The list query (`EloquentCustomerRepository::paginate`) filters on `company_id` only; SoftDeletes adds
`deleted_at IS NULL`. The `customerBrands` relation is `whereHas` **only when a brand filter is passed**,
so a customer without a brand row is never dropped. All three order-linked customers already qualify.

So the page was not showing "test data" — it was showing real customers with **no order columns at all**.
That was the actual gap, and it is what this task fills.

---

## B — Canonical source for every value

| Value | Canonical source | Notes |
|---|---|---|
| **Phone** | `customers.phone` (primary), `customers.mobile` (secondary) | unchanged, never rewritten |
| **Address** | `customer_addresses` default row → falls back to `customers.address/area/city/governorate` | same precedence the CRM workspace uses — not re-decided here |
| **Location** | **`orders.google_maps_url`** | most recent order carrying one. See §I |
| **Orders Count** | `COUNT(*)` over `orders` | `customer_id` + `company_id` + `deleted_at IS NULL` |
| **Total Order Value** | `SUM(orders.total)` | cancelled/returned included — the approved definition, unchanged |
| **Receiving Rate** | `delivered / ALL orders × 100` | `OrderStatus::Delivered` only; **NULL** when no orders |
| **Products** | `orders → order_lines → products`, grouped by product | `SUM(order_lines.quantity)` |

All of it comes from **`CustomerOrderMetricsService`** (`Modules\Commerce\Orders\Domain\Services`) — the
**same service the CRM workspace uses**. No `SalesCustomerMetricsService` was created, and no order
semantics were redefined. PART 11 satisfied: the service lives in Commerce/Orders next to `orders`, so
Sales depends on Commerce, never on CRM.

---

## C — Files changed

| File | Change |
|---|---|
| `Modules/Commerce/Orders/Domain/Services/CustomerOrderMetricsService.php` | **+2 batch methods**: `topProductsForCustomers()`, `locationUrlForCustomers()`. Existing methods untouched, so CRM behaviour is unchanged |
| `Modules/Sales/Customers/Presentation/Http/Controllers/CustomerController.php` | composes metrics/top-products/location/address into `index()` and `show()`; `fullAddress()` helper; **tenant guard on `show()`** (§K) |
| `Modules/Sales/Customers/Infrastructure/Repositories/EloquentCustomerRepository.php` | eager-loads the default address in `paginate()` and `findById()` |
| `features/customers/types/customer.ts` | order-derived fields, `CustomerTopProduct`, `CustomerPurchasedProduct` |
| `features/customers/pages/customers-page.tsx` | new columns, `PhoneCell` reuse, address+location cell, Top Products popover |
| `features/customers/components/customer-drawer.tsx` | 6 canonical KPIs, Address+Location, **Products Purchased tab** |
| `i18n/locales/{en,ar}/customers.json`, `common.json` | new column/tab/product labels + `common.copy` |

**No CRM file was touched.**

---

## D — APIs changed

Both are **additive** — existing keys keep their shape and meaning.

`GET /customers` — each item gains:
```
orders_count, total_order_value, delivered_count, receiving_rate,
average_order_value, last_order_at, top_products_count, top_products[],
location_url, full_address
```

`GET /customers/{id}` — the same, plus `purchased_products[]` (Product · Quantity · Orders · Last Ordered).
`purchased_products` is deliberately **absent from the list** — it is the heavier per-product query and
the list only needs the top few.

---

## E — Migration

**None. No schema change.** Every column already existed: `orders.google_maps_url`,
`customer_addresses.*`, `order_lines.quantity`, `orders.total`, `orders.status`.

---

## F — How N+1 is avoided

The list costs a **fixed number of queries regardless of row count**:

| # | Query |
|---|---|
| 1 | paginate `customers` |
| 2 | eager-load `customerBrands.brand` |
| 3 | eager-load `addresses` **where `is_default`** |
| 4 | `forCustomers()` — one `GROUP BY customer_id` aggregate |
| 5 | `topProductsForCustomers()` — one windowed query for the whole page |
| 6 | `locationUrlForCustomers()` — one windowed query for the whole page |

25 customers or 100, it is the same six. No loop issues a query.

The drawer's **Products Purchased** tab is one query for one customer, fired only when that tab opens.

---

## G — How Top Products works

One query does the grouping, ranking and counting **in the database**:

```sql
SELECT * FROM (
  SELECT o.customer_id, ol.product_id, p.name, SUM(ol.quantity) AS total_quantity,
         ROW_NUMBER() OVER (PARTITION BY o.customer_id ORDER BY SUM(ol.quantity) DESC) AS rn,
         COUNT(*)     OVER (PARTITION BY o.customer_id)                                AS distinct_products
  FROM order_lines ol
  JOIN orders o ON o.id = ol.order_id
  LEFT JOIN products p ON p.id = ol.product_id
  WHERE o.customer_id IN (…) AND o.company_id = ? AND o.deleted_at IS NULL
  GROUP BY o.customer_id, ol.product_id, p.name
) t WHERE rn <= 5
```

- `COUNT(*) OVER` counts the **grouped rows** = number of **DISTINCT products**, not units — which is
  the number shown in the column.
- `ROW_NUMBER` caps the payload at 5 per customer, so the popover list is bounded server-side.
- React only maps the array to `<li>`. **No sorting, summing or slicing in the client.**

**I executed this against `ecos_dev` read-only to prove the SQL is valid** (static analysis cannot catch
malformed SQL) — it returned correct per-customer ranking and `distinct_products` = 1 for each of the
three customers. Window functions are supported by both MySQL 8 and PostgreSQL, so this stays portable.

If a customer has more than 5 distinct products, the popover shows a **"View all N products"** action
that opens their orders — no new screen was created.

---

## H — How Phone was made interactive

**I reused `components/ecos/phone-cell.tsx` rather than writing anything new**, per your instruction.

Correction to the brief's premise: the phone was **already partly interactive** — there was a `tel:` icon
button and a WhatsApp button. What was **not** interactive was the number itself, which was a plain
`<span>`; you had to hit a 20px icon. Now:

- the **number itself** is the control, opening **Call (`tel:`) / WhatsApp / Copy**
- `stopPropagation` on the cell and on the trigger keeps it from opening the drawer
- the number is never rewritten — `tel:` strips non-digits only for the href
- a **secondary number** (`mobile`) renders as its own `PhoneCell` beneath the primary; all numbers also
  appear in the drawer's Phones tab
- Copy was already supported by the shared component, so it came for free

---

## I — Location URL source, precisely

**`orders.google_maps_url`** — the link captured on the order itself.

Selection rule (PART 5): the **most recent order that actually carries a link**, by
`created_at DESC, id DESC`. An older order without a link never blanks out a known location, and the
first order is not assumed to be the source.

Nothing is synthesised from `city`, `zone`, `delivery_zone`, or `google_maps_lat/lng`. When no order
has a link, the cell shows **—**, never a fabricated URL.

### Second source — checked, currently NO conflict

`customer_addresses.google_maps_url` also exists. I compared them rather than assume:

```
customer 019ff80d…  orders.google_maps_url          = https://www.google.com/maps/place/Air+Force+Specialized…
                    customer_addresses.google_maps_url = https://www.google.com/maps/place/Air+Force+Specialized…   ← identical
```

Only one customer currently has either, and **the two agree**. I used the Order as you specified. If they
ever diverge, this is the precedence to revisit — flagging it now rather than inventing a rule later.
`orders.location_source` and `location_set_by` exist but are **NULL on all 6 orders**, so neither could
be used to arbitrate.

---

## J — Static verification

| Check | Result |
|---|---|
| PHPStan — level 0, entire platform | **[OK] No errors** |
| PHPStan — core level 6 | **[OK] No errors** |
| Pint — my 3 backend files | **passed** |
| TypeScript — my files | **0 errors** |
| TypeScript — total | **24 = documented baseline** |
| ESLint — `src/features/customers` | **clean** |
| Vite build | **✓ built in 5.76s** |
| `git diff --check` | **clean** |
| PHPUnit / E2E / runner | **not run**, per instruction |

Deployed to DEV so you can review (last round's lesson): frontend → **both** `ecos-dev-nginx` and
`ecos-dev-app`; the 3 backend files → `ecos-dev-app`, **md5-verified MATCH**. Served bundle
`index-09VSlHaq.js` confirmed over HTTP to contain `top_products_count`, `location_url`, `full_address`,
`receiving_rate`, `purchased_products`; the `customers-CtqeOlbE.js` chunk carries the labels.
**MAIN untouched** — `ecos-nginx` still Aug 7.

---

## K — Not done, and two things you should decide

### 1. A pre-existing cross-tenant read on `GET /customers/{id}` — guarded, not fully fixed

`EloquentCustomerRepository::findById()` is **`Customer::query()->find($id)` with no company scope** — any
company's customer is readable. That predates this task, but `show()` now returns *more* about a customer,
so I would have been widening a leak. I added a **fail-closed guard in `show()`**: a customer whose
`company_id` differs from the current company returns **404**.

I did **not** change `findById()` itself — it has other callers and that is a broader decision. **The
repository is still unscoped.** Worth a dedicated fix.

### 2. `useCustomerOrderStats` aggregates in React — removed from Customers, still live in Orders

`features/orders/hooks/use-orders.ts:483` fetches **up to 200 orders** and computes in the browser:

```ts
const totalSpend = items.reduce((sum, o) => sum + o.total, 0);
completed: items.filter((o) => o.status === 'delivered').length,
aov: totalSpend / items.length,
```

`per_page: 200` means a customer with 201+ orders gets a **silently wrong** total. This was powering the
Sales customer drawer's Total Orders / Total Spend.

The drawer now uses the canonical server-computed values instead, so the Customers surface is clean.
**But the hook is unchanged and still used by `order-customer-badge.tsx` and `order-detail-page.tsx`** —
those are Orders screens and outside this task's fence, so I left them. They will disagree with the
Customers page for any customer past 200 orders.

### 3. Not implemented
- Sorting by the new columns. `SORTABLE` in the repository is `code, name, country, city, is_active, created_at`; adding `orders_count` etc. means sorting by an aggregate, which changes the query shape. Not attempted without your go-ahead.
- The `intelligence` column and `View all products` still route to existing screens; no new screen was created, as instructed.

---

## Manual review checklist

Open **Commerce → Customers** (`/app/customers`) and **hard-reload (Ctrl+F5)**.

1. Columns: Customer · Phone · Orders · Total Value · Receiving Rate · Address (+📍) · Top Products · Intel.
2. **Click the phone number itself** → Call / WhatsApp / Copy. It must **not** open the drawer.
3. `CUS-00029` → Orders **4**, Total **33,587.00**, Receiving Rate **0%** (4 orders, 0 delivered).
4. The customer with **no orders** → Orders 0, Total 0.00, Receiving Rate **—**, Top Products **—**.
5. Only `CUS-00029` shows the 📍 icon; the others show —. Clicking it opens the Google Maps link from their order.
6. Top Products shows **1** → popover lists the product and its quantity (5 for CUS-00029).
7. Open a customer → **Products** tab → Product · Quantity · Orders · Last Ordered.

---

> **IMPLEMENTATION COMPLETE — AWAITING MANUAL REVIEW.** Not certified.
