# TASK-SALES-CUSTOMERS-POST-360-HARDENING-001

**Date:** 2026-08-14 · Static verification + read-only SQL only. No PHPUnit, no E2E, no runtime tests.

> **IMPLEMENTATION COMPLETE — STATIC VERIFIED — RUNTIME VERIFICATION PENDING USER REVIEW**

Everything in PARTs 1–6 and 8–21 is implemented. PART 7 originally hit its own STOP condition; it was
subsequently **authorized and closed** by TASK-SALES-CUSTOMERS-POST-360-HARDENING-CLOSURE-001 —
see **§S — PART 7 Resolution** at the end. Not certified.

---

## A — Customer list data source

`GET /customers` → `ListCustomersAction` → `EloquentCustomerRepository::paginate()` →
company-scoped `customers` query. Order figures composed in the controller from
**`CustomerOrderMetricsService`**. No new service was created.

## B — Customer 360 data source

The drawer's KPIs read the **same objects the list returned** — literally the same payload, so the
list and the drawer cannot disagree (PART 13 satisfied by construction). The Products tab calls
`GET /customers/{id}` → `forCustomer()` + `purchasedProducts()`, again the same service.

**The drawer no longer calls `useCustomerOrderStats`.** It previously fetched up to 200 orders and
summed them in the browser.

## C — Products Purchased + Quantity formula

`orders → order_lines → products`, grouped by product:

```sql
SUM(order_lines.quantity)  GROUP BY ol.product_id, p.name, p.sku
WHERE o.customer_id = ? AND o.company_id = ? AND o.deleted_at IS NULL
```

`Quantity` = **total units of that product across all the customer's orders** — the example shape you
asked for (Honey 1kg → 5). The tab shows **Product · Quantity · Orders · Last Ordered**. Already
correct from the previous task; verified, not rebuilt. No React aggregation.

## D — Top Products source + meaning

Deliberately **not** the same thing as Products Purchased, and the semantics were **not changed**:

| | Meaning |
|---|---|
| **The number** | count of **DISTINCT products** the customer has ordered — not units |
| **The popover** | those products **ranked by `SUM(quantity)` descending**, top 5 |

Both come from one windowed query (`ROW_NUMBER` + `COUNT(*) OVER`). PART 2 asked that the UI make the
number's meaning unambiguous, so I added it in words rather than leaving it to inference:

- hovering the number: *"N distinct products ordered — hover to see the top ones by quantity"*
- the popover heading is now **"Top products by quantity"**, not the bare column name

## E — Tenant isolation audit (PART 4 / 19)

**The audit found three unscoped read paths, not the one in the previous report.**

| Path | Before | After |
|---|---|---|
| `EloquentCustomerRepository::findById()` | `Customer::query()->find($id)` — **no scope** | scoped; `$companyId` is a **required parameter** |
| `SearchCustomerByPhoneAction` | `Customer::where(phone)…->first()` — **no scope** | scoped. This one leaked **name, addresses and order stats** by phone number alone |
| `CustomerAddressController` (**all 4 methods**) | `Customer::findOrFail($customer)` — **no scope** | scoped via one `customer()` helper. This was read **and write** — `store`/`update`/`destroy` could modify another company's addresses |

Already correct and left alone: `paginate()` (company-scoped), and every
`CustomerOrderMetricsService` query — all four take `$companyId` and filter
`company_id` + `deleted_at IS NULL`, verified method by method.

### The decisive detail: `null` means "unrestricted", not "error"

`CurrentCompanyService::id()` documents that it returns **null for super-admins**, "allowing those
callers unrestricted access". So the correct rule — and the one now applied everywhere — is:

```
company context present  →  scope to it        (fail-closed for every normal user)
company context null     →  unrestricted       (documented super-admin behaviour, unchanged)
```

**This also corrects a regression I introduced last round.** The guard I added to `show()` was
`if ($companyId === '' || …) return 404;` — which denied super-admins. That guard is now removed
entirely; the boundary lives in the repository, which is where PART 5 says it belongs.

## F — CustomerRepository behaviour (PART 5)

**Callers checked first.** `CustomerRepositoryInterface` is consumed by exactly five files, **all
inside `Modules/Sales/Customers`**: Create/Get/Update/Delete/List actions. **Nothing outside Sales
uses it**, so the PART 5 STOP condition ("callers outside this task") does not trigger.

The project already has a convention for this — `ClaudeBridge` uses both
`findByIdForCompany($id, $companyId)` (Artifact) and `findById($id, $companyId)` (Task, Worker). I
used the **Task/Worker shape**, because it is the fail-closed one:

```php
public function findById(string $id, ?string $companyId): ?Customer;
```

There is deliberately **no unscoped overload left to forget** — omitting the argument is now a PHP
error, not a silent leak. The three actions inject `CurrentCompanyService` themselves rather than
having the controller thread it in, so the action cannot be invoked without a boundary. CRM keeps its
own lookup untouched; no second Customer lookup was introduced.

## G — `useCustomerOrderStats` audit — **STOPPED, per PART 7**

> **Superseded in part by §S.** `preferred_governorate` was subsequently authorized and moved
> server-side. The remaining fields below (`cancelled`, `firstOrderDate`, and the 200-order
> client-side aggregation of `totalSpend`/`completed`/`aov`) are **still open and unchanged**.

**Usages (3):**

| Consumer | Status |
|---|---|
| `features/customers/components/customer-drawer.tsx` | **no longer uses it** — canonical server metrics |
| `features/orders/components/order-customer-badge.tsx` | still uses it — **untouched** |
| `features/orders/pages/order-detail-page.tsx` | still uses it — **untouched** |

**Why I stopped instead of fixing it.** The two Orders screens read three fields the canonical
service does not provide:

| Field | Canonical service | Notes |
|---|---|---|
| `cancelled` | absent | a new aggregate |
| `firstOrderDate` | absent | a new aggregate |
| `preferredGovernorate` | absent | **"the governorate appearing most often" is a business rule that exists only in React today** |

Moving these server-side means **defining new metrics on the canonical service**, and
`preferred_governorate` in particular is a business rule nobody has ratified. PART 7 and PART 22 both
say to stop in exactly this case, so I did.

A second divergence worth your attention: the hook's `lastOrderDate` is `orders.order_date`, while the
canonical `last_order_at` is `MAX(orders.created_at)`. **Different columns.** They agree on all 6
current orders (checked), but they are not the same field and could drift.

I did **not** raise the 200 limit — you correctly called that a cosmetic fix; the problem is the
client-side aggregation itself.

**Consequence to be aware of:** for a customer with more than 200 orders, the Orders screens will
disagree with the Customers workspace. The Customers workspace is the correct one.

## H — N+1 verification (PART 8 / 16)

The list is a **fixed query count, independent of customer count**:

| # | Query |
|---|---|
| 1 | paginate `customers` |
| 2 | eager-load `customerBrands.brand` |
| 3 | eager-load `addresses` where `is_default` |
| 4 | `forCustomers()` — one aggregate |
| 5 | `topProductsForCustomers()` — one windowed query |
| 6 | `locationUrlForCustomers()` — one windowed query |
| 7 | `preferredGovernorateForCustomers()` — one windowed query *(added in the closure round, §S)* |

**Changed this round:** queries 4–7 are now grouped by the customer's **own** `company_id`. For a
normal user every row shares one company, so it stays at exactly these seven. For a super-admin it is
four per distinct company on the page — bounded by companies, never by customers.

That grouping also fixes a **real bug I shipped last round**: with `CurrentCompanyService::id()` null
(super-admin), the old code passed an empty company and every metric came back **0**. Now a
super-admin sees real figures.

No `useQuery` inside any row. No aggregation in React anywhere in the Customers surface.

## I — Location source (PART 9) — unchanged

`orders.google_maps_url`, from the **most recent order carrying one** (`created_at DESC, id DESC`).
Never derived from city or lat/lng; `—` when absent. `customer_addresses.google_maps_url` remains a
second source; it still **agrees exactly** for the one customer holding both. **Precedence not changed.**

## J — Phone interaction (PART 10) — unchanged

`components/ecos/phone-cell.tsx`, reused — no second PhoneCell. The **number itself** is the control:
Call (`tel:`) · WhatsApp · Copy, with `stopPropagation` on both the cell and the trigger so it never
opens the drawer. Secondary number renders as its own `PhoneCell`.

## K — API changes

**No new endpoint.** Both existing endpoints were extended, as PART 17 requires.

- `GET /customers` — items gain `last_order_at` alongside the previously-added order fields.
- `GET /customers/{id}` — unchanged shape; now **404s** for a customer outside the caller's company.
- `GET /customers/search-by-phone` — unchanged shape; now tenant-scoped.
- `GET|POST|PUT|DELETE /customers/{customer}/addresses` — unchanged shape; now tenant-scoped.

## L — Frontend changes

**Added `Last Order` column — it was in PART 11's required list and I had missed it last round.**
Top Products number and popover now state what they mean. Nothing else in the UI changed.

Current columns: Customer · Phone · Orders · Total Value · Receiving Rate · **Last Order** ·
Address (+📍 Location) · Top Products · Intel · Actions.

Note on PART 11 "do not delete existing columns": the old **Previous Orders** column was a button, not
a figure. It is now the **Orders Count** cell — the number is the button, same action. Nothing was
lost. The old **Default Address** column became **Full Address + Location**.

## M — Files changed

**Backend (9)** — `Modules/Sales/Customers/`: `Domain/Contracts/CustomerRepositoryInterface.php` ·
`Infrastructure/Repositories/EloquentCustomerRepository.php` ·
`Application/Actions/{Get,Update,Delete,SearchCustomerByPhone}CustomerAction.php` ·
`Presentation/Http/Controllers/{Customer,CustomerAddress}Controller.php`; plus
`Modules/Commerce/Orders/Domain/Services/CustomerOrderMetricsService.php`.

**Frontend (3)** — `features/customers/pages/customers-page.tsx` ·
`i18n/locales/{en,ar}/customers.json`.

**No CRM file touched. No Orders file touched.**

## N — Migrations

**None.** No schema change this round or last.

## O — Static verification

| Check | Result |
|---|---|
| `php -l` — all 9 backend files | **no syntax errors** |
| PHPStan — level 0, entire platform | **[OK] No errors** |
| PHPStan — core level 6 | **[OK] No errors** |
| Pint — my 9 files | **passed** |
| TypeScript — total | **24 = documented baseline** |
| ESLint — `src/features/customers` | **clean** |
| Vite build | **✓ built in 6.12s** |
| `git diff --check` | **clean** |
| PHPUnit / E2E / runner | **not run**, per instruction. No other agent's process was touched |

Pint still reports 14 failures inside `Modules/Sales/Customers`. **`git status` confirms all 14 are
unmodified** — pre-existing, not mine. Only my 9 files are modified.

## P — Deployment verification

Frontend and backend deployed together — no partial deployment.

```
9 backend files -> ecos-dev-app        md5 MATCH on all 9
frontend        -> ecos-dev-nginx AND ecos-dev-app
served index.html -> assets/index-o2vxmMQF.js   Last-Modified: Fri, 14 Aug 2026 01:59:01 GMT
  last_order_at · top_products_count · location_url · full_address · purchased_products · receiving_rate  ALL PRESENT
customers-Bc_gZ9K_.js (http 200): "Top products by quantity" · "distinct products ordered"  PRESENT
routes: /api/customers 401 · /api/customers/{id} 401   (exist, auth required)
```

**MAIN untouched and verified:** `ecos-nginx` index.html still **Aug 7**; `ecos-app` has **0**
occurrences of the new scoping code.

## Q — Remaining issues

1. **`useCustomerOrderStats` still aggregates in React on two Orders screens** — §G. Needs your
   decision on three new canonical metrics, one of which (`preferred_governorate`) is an unratified
   business rule.
2. **`order_date` vs `created_at`** for "last order" — two different columns in play across surfaces.
   Currently agree; worth settling.
3. **14 pre-existing Pint failures** in `Modules/Sales/Customers` (files I did not touch).
4. Super-admin metrics now work, but a super-admin viewing many companies pays 3 queries per company
   on the page. Bounded, but worth knowing.

## R — Optional / Future

- **Sorting on aggregate columns (PART 12).** I checked for a canonical pattern first, as instructed:
  `SORTABLE` in the repository is a whitelist of plain columns
  (`code, name, country, city, is_active, created_at`), and I found **no existing server-side
  aggregate-sort pattern** anywhere in the project to follow. Per PART 12 I did **not** invent one.
  **Deferred.**
- Scoping `findById` at the model layer (a global tenant scope) rather than per-repository — a
  platform-wide decision, well outside this task.

---

---

# S — PART 7 Resolution

**Closed by TASK-SALES-CUSTOMERS-POST-360-HARDENING-CLOSURE-001 (2026-08-14).**
Scope was limited to `preferred_governorate`. Nothing else in §G was changed.

## S.1 — preferred_governorate source

**`orders.governorate`**, aggregated by
`CustomerOrderMetricsService::preferredGovernorateForCustomers()`. No new service, no new
endpoint, no schema change.

## S.2 — Aggregation logic

One windowed query for many customers at once:

```sql
SELECT customer_id, governorate FROM (
  SELECT customer_id, governorate,
         ROW_NUMBER() OVER (PARTITION BY customer_id
                            ORDER BY COUNT(*) DESC, governorate ASC) AS rn
  FROM orders
  WHERE customer_id IN (…) AND company_id = ? AND deleted_at IS NULL
    AND governorate IS NOT NULL AND governorate <> ''
  GROUP BY customer_id, governorate
) t WHERE rn = 1
```

**Definition unchanged from React:** the most frequent governorate across the customer's orders.

**Tie-breaking — the STOP condition, resolved by finding an existing contract, not inventing one.**
PART 6 required stopping if no canonical tie-break rule existed. One does; it is consistent across
three independent services:

- `EnterpriseQueueSorterService` — *"7. order_number ASC — stable tiebreaker"*
- `ActivityTimelineService` — *"each row carries a unique id as the tiebreak"*, sorting `[occurred_at, id]`
- `RuleEvaluationPipeline` — documents determinism as an explicit requirement

The platform convention is therefore: **every ordering ends in a deterministic ascending tiebreaker
on a natural key.** Here the natural key is the governorate name, so equal counts resolve
alphabetically. The previous client-side version resolved ties by JS object insertion order — which
was incidental, not specified, and could change between loads.

**Excluded from the count:** orders whose `governorate` is NULL or empty. They are not a
governorate and must not win by default.

## S.3 — Tenant behaviour

Identical to every other metric on this surface: `company_id` + `deleted_at IS NULL`. The controller
groups by the customer's **own** `company_id`, so the documented super-admin context
(`CurrentCompanyService::id() === null`) still resolves correctly per company rather than zeroing.
**No fail-closed behaviour was added for super-admins**, per PART 2.

## S.4 — Tests

**`CustomerPreferredGovernorateTest` — 7 tests, 11 assertions, all passing.**

| Case | Result |
|---|---|
| A×3 vs B×1 → A | pass |
| Tie A×2/B×2 → deterministic by name ASC, asserted over 3 consecutive runs | pass |
| Company A cannot see Company B's orders in the metric (same customer id, 3 foreign orders vs 1 local — the local one still wins) | pass |
| Unrestricted context resolves each company separately | pass |
| Customer with no orders → NULL, nothing invented | pass |
| Orders with NULL/empty governorate never count (majority NULL, minority real → real wins) | pass |
| Customer whose orders are all blank → NULL | pass |

**`CustomerTenantIsolationTest` — 12 tests, 23 assertions, all passing.** PART 9's guarantees had
only ever been argued statically; they are now executable regression:

| PART 9 case | Result |
|---|---|
| Company A → Company A = ALLOW | pass |
| Company A → Company B = DENY (404) | pass |
| Company B → Company A = DENY | pass |
| 404 not 403 (existence not confirmed) | pass |
| No attribute of the foreign record leaks | pass |
| Super Admin → unrestricted = ALLOW | pass |
| Phone search cross-company = DENY | pass |
| Customer Address cross-company **read** = DENY | pass |
| Customer Address cross-company **write** = DENY, and nothing persists | pass |
| List excludes other companies | pass |

Two fixture faults were found and fixed **in the tests, never by weakening an assertion**:
`order_confirmed_at` does not exist on `orders` (carried over from the preparation-wave fixtures),
and the phone-search payload nests under `data.customer.*`. A third assertion was corrected on
merit: the 404 body echoes the id the caller themselves supplied, which is not a leak — the test now
asserts on the foreign record's **name and phone** instead.

## S.5 — API behaviour

`preferred_governorate` added to the **existing** Sales Customer endpoints — `GET /customers`
(each item) and `GET /customers/{id}`. No new endpoint. No calculation metadata is sent to the UI.
NULL when the customer has no order carrying a governorate.

## S.6 — Frontend behaviour

`useCustomerOrderStats` no longer counts, groups or ranks anything. The `govCounts` loop and its
`Object.entries(...).sort(...)` are deleted; the value is read from the existing customer endpoint.
**Verified in the deployed bundle: `govCounts` no longer appears, `preferred_governorate` does.**

Every other field of that hook is untouched, so the two Orders screens keep their current behaviour.
The 200-order client-side aggregation of `totalSpend` / `completed` / `cancelled` / `aov` **remains**
— out of scope here and still open, exactly as §G describes.

## S.7 — LAST_ORDER_DATE_CONTRACT = **OPEN**

Not changed, not guessed.

```
Frontend  (useCustomerOrderStats):        orders.order_date
Canonical (CustomerOrderMetricsService):  MAX(orders.created_at)
```

Two different columns. They agree on all current orders, but `order_date` is a user-settable date
while `created_at` is insertion time, so they can diverge. **Resolving this needs a contract from the
Order domain** — which is authoritative for "when did this order happen". Neither column, nor the
Customer 360 contract, was modified.

## S.8 — Aggregate sorting = **DEFERRED**

Unchanged from §R: `SORTABLE` is a whitelist of plain columns and the project has no server-side
aggregate-sort pattern to follow. Not invented, not added.

## S.9 — Static verification (closure round)

| Check | Result |
|---|---|
| `php -l` — all changed/new files | **no syntax errors** |
| PHPStan — level 0, entire platform | **[OK] No errors** |
| PHPStan — core level 6 | **[OK] No errors** |
| Pint — my files | **passed** (the 14 pre-existing violations were left alone, per PART 10) |
| TypeScript — total | **24 = documented baseline** |
| ESLint — `features/customers` + `use-orders.ts` | **clean** |
| Vite build | **✓ built in 8.71s** |
| `git diff --check` | **clean** |

## S.10 — Deployment (PART 11)

```
HOST == TEST RUNNER == APP   md5 MATCH on all 4 backend files
frontend -> ecos-dev-nginx AND ecos-dev-app
served index.html -> assets/index-Bjbqrnsw.js   Last-Modified: Fri, 14 Aug 2026 16:17:23 GMT
  preferred_governorate PRESENT · preferredGovernorate PRESENT · govCounts REMOVED
MAIN untouched — ecos-nginx index.html still Aug 7
```

## S.11 — Regression (PART 12) — one item could NOT be completed

Both new suites pass in full when run as files: **7/7** and **12/12**.

**The whole-directory run (`Modules/Sales/Customers/Tests`, 27 tests) could not be completed.** It
fails in `RefreshDatabase` setUp with:

```
SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock
  … during migrate:fresh's `drop table ecos_dev_test.…`
```

**Classification: ENVIRONMENT — another agent's suite owns the test database.** Reading `/proc`
directly (the container has **no `ps` binary**, which is why my earlier "runner is free" check was
worthless and is corrected here) shows a concurrent run:

```
php vendor/bin/phpunit -c phpunit-orders.xml tests/Feature/Commerce/OrdersInventoryExecutionLifecycleTest.php
```

That is not mine — I have never used `phpunit-orders.xml`. Its `migrate:fresh` holds locks on
`ecos_dev_test`, which is exactly what my directory run deadlocked against. Per the standing rule I
**stopped rather than retrying, and did not touch that process**. No assertion was altered to pass.

Evidence it is not a defect in this change: the 3 failing tests in the mixed run were all in
`CustomerCreationTest`, a file untouched by this work, and the failure is a missing `migrations`
table / lock deadlock rather than any assertion.

**Outstanding:** re-run `Modules/Sales/Customers/Tests` as a whole once the other agent's suite
finishes, to confirm the three files pass together.

---

> **IMPLEMENTATION COMPLETE — STATIC VERIFIED — RUNTIME VERIFICATION PENDING USER REVIEW**
