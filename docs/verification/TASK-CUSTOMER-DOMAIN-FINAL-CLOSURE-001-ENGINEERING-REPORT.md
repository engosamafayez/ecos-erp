# TASK-CUSTOMER-DOMAIN-FINAL-CLOSURE-001 — Engineering Report

**Date:** 2026-08-14 · Static verification, read-only SQL, real-HTTP probes, focused test runs.

> # FINAL VERDICT: **NOT CERTIFIED**
>
> **ENGINEERING = PASS with 1 unverified item · REGRESSION = BLOCKED (shared runner occupied by
> another agent) · REAL E2E = PENDING USER BROWSER SMOKE**
>
> Browser is **not** the only remaining gate, so the verdict is NOT CERTIFIED. The exact blockers are
> in §24.

---

## 1 — Executive Summary

Three things were closed this round:

1. **LAST_ORDER_DATE_CONTRACT — resolved, not guessed.** The Order domain already defines it. I found
   the definition rather than choosing a field, and aligned the Customer service to it.
2. **A real tenant defect in the customer timeline.** `OrderTimelineSource` accepted `$companyId`
   and never used it — another company's orders could appear on a customer's timeline, along with
   soft-deleted orders. Fixed.
3. **A non-deterministic timeline merge.** `TimelineService` sorted on timestamp alone; entries from
   different sources with equal timestamps had no stable order. Fixed with the platform's
   `[occurred_at, id]` convention.

Two findings are **reported, not changed**, because changing either would alter access or behaviour
beyond this task's authority (§13, §24).

**Nothing from the previous task was redone.** All PART 1 guarantees remain intact and are verified
in §14.

---

## 2 — Customer Domain Architecture

There is **one customer master and no second authority**:

| | Sales | CRM |
|---|---|---|
| Table | `customers` | `customers` — **same table** |
| Migration owner | `Modules/Sales/Customers/…/2026_06_23_160000_create_customers_table.php` | — (consumes it) |
| Model | `Modules\Sales\Customers\Domain\Models\Customer` | `Modules\Crm\Customers\Domain\Models\Customer` (`protected $table = 'customers'`) |
| Route | `/customers` | `/crm/customers` |
| Controller | `Modules\Sales\…\CustomerController` | `Modules\Crm\…\CustomerController` |
| Order metrics | `CustomerOrderMetricsService` | **the same service** |

**CRM is not a projection and not a copy — it is a second module-level view over the same rows.**
No synchronisation exists and none is needed. Addresses (`customer_addresses`) are likewise a single
table owned by Sales and read by both.

`CustomerOrderMetricsService` lives in `Commerce\Orders` (next to `orders`), so **Sales → Commerce**
and **CRM → Commerce**, never Sales ↔ CRM. The dependency never inverts, which is the contract
`Customer360Service` states in its own docblock.

---

## 3 — Sales Customer Path — **PASS**

| Step | Canonical source | Tenant | Status |
|---|---|---|---|
| Create | `CreateCustomerAction` | `company_id` forced from context; 422 without one | PASS |
| Search | `EloquentCustomerRepository::paginate()` | `where company_id` | PASS |
| Phone lookup | `SearchCustomerByPhoneAction` | scoped (fixed previous round) | PASS |
| Profile / 360 | `GET /customers/{id}` | repository-scoped, 404 on foreign | PASS |
| Address | `CustomerAddressController` | scoped on all 4 methods | PASS |
| Orders / Metrics | `CustomerOrderMetricsService` | `company_id` + `deleted_at IS NULL` | PASS |
| Preferred Governorate | same service | company scoped | PASS |
| Last Order | same service — **now `MAX(order_date)`** | company scoped | PASS (test not yet executed — §24) |

Super-admin (`CurrentCompanyService::id() === null`) remains **unrestricted**, unchanged.

---

## 4 — CRM Customer Path — **PASS**

`ResolvesCustomerContext::customer()` is company-scoped and `firstOrFail()`s → **404**, never 403.

```php
Customer::query()->where('company_id', $this->companyId($request))->where('id', $id)->firstOrFail();
```

CRM consumes the canonical customer and the canonical metrics service. **No duplicate CRM customer
authority exists and none was created.**

**One divergence, reported not changed (§13-B):** CRM resolves the company as
`(string) $request->user()->company_id`, so a super-admin (null company) becomes `''` and matches
nothing — CRM is **fail-closed** for super-admins while Sales is **unrestricted**. Both are safe; CRM
is the stricter of the two. Making CRM unrestricted would *widen* access, which I will not do without
an explicit decision.

---

## 5 — Customer 360 Data Contract (PART 3) — **PASS**

| Field | Frontend source | Backend canonical | Verdict |
|---|---|---|---|
| Name | `customer.name` | `customers.name` | direct read |
| Phone | `PhoneCell` | `customers.phone` / `.mobile` | direct read, formatting only |
| Addresses | `full_address` | `customer_addresses` default row → master columns fallback | server-composed |
| Preferred Governorate | `preferred_governorate` | `MAX-count(orders.governorate)` | server-computed |
| Last Order | `last_order_at` | `MAX(orders.order_date)` | server-computed |
| Order count | `orders_count` | `COUNT(*)` | server-computed |
| Order metrics | `total_order_value`, `receiving_rate`, `delivered_count`, `average_order_value` | `CustomerOrderMetricsService` | server-computed |
| Top products | `top_products_count`, `top_products[]` | windowed SQL | server-computed |
| Activity | timeline entries | `TimelineService` + sources | server-composed |
| Status | `is_active` | `customers.is_active` | direct read |
| Company | `company_id` | `customers.company_id` | direct read |

**No business metric is calculated in React.** Verified by grep across both customer surfaces: no
`.reduce(`, no status `.filter(`, no `Object.entries(...).sort(...)`. Only presentation formatting
(`toLocaleDateString`, number formatting, truncation) remains, which is legitimate.

`preferred_governorate` was in the API but not displayed; it is now surfaced in the Sales 360 as a
direct read.

---

## 6 — Search — **PASS**

`paginate()` filters `company_id`; SoftDeletes adds `deleted_at IS NULL`. `customerBrands` is a
`whereHas` **only** when a brand filter is supplied, so no customer is silently dropped. Sort keys are
a whitelist (`SORTABLE`).

## 7 — Phone — **PASS**

`SearchCustomerByPhoneAction` is company-scoped (`->when($companyId !== null, …)`), preserving the
unrestricted super-admin path. Response shape `data.customer.*` unchanged. Cross-company lookup
returns `data: null` — proven by test. **Phone semantics unchanged.**

Frontend: the number itself is the control (Call `tel:` / WhatsApp / Copy) via the shared
`components/ecos/phone-cell.tsx`, with `stopPropagation` so it never opens the drawer. **No second
PhoneCell exists.**

## 8 — Addresses — **PASS**

All four paths (`index`, `store`, `update`, `destroy`) resolve through one scoped helper. Read **and
write** cross-company are denied with 404, and the write test asserts nothing persists.

## 9 — Metrics — **PASS**

Company-scoped, `deleted_at IS NULL`, no N+1. The list costs a **fixed** number of queries:

| # | Query |
|---|---|
| 1–3 | paginate + eager-load `customerBrands.brand` + eager-load default `addresses` |
| 4 | `forCustomers()` |
| 5 | `topProductsForCustomers()` |
| 6 | `locationUrlForCustomers()` |
| 7 | `preferredGovernorateForCustomers()` |

Seven for a normal user regardless of row count; for a super-admin, seven **per distinct company on
the page** — bounded by companies, never by customers. Each customer's metrics use **their own**
company context, so the super-admin case is not zeroed.

## 10 — Preferred Governorate — **PASS**

Contract kept exactly as established: `COUNT(*) per governorate → highest wins → governorate ASC`
deterministic tie-break; NULL/blank never win; company scoped. React's `Object.entries` ranking was
removed and **is provably absent from the served bundle**.

---

## 11 — Last Order — **CONTRACT RESOLVED**

**The canonical definition already existed in the Order domain.** I traced it rather than choosing:

```php
// Modules/Commerce/Orders/Presentation/Http/Resources/OrderResource.php:85
MIN(order_date) as first_order_date, MAX(order_date) as last_order_date
```

Supporting evidence that `order_date` is the business date:

- `orders.order_date` is `date`, **NOT NULL**, cast `date:Y-m-d`
- `required|date` on `StoreOrderRequest` / `UpdateOrderRequest` — user-supplied
- `EloquentOrderRepository` filters date ranges with `whereDate('order_date', …)`
- `OrderTimelineSource` already orders the customer timeline by `order_date`
- `created_at` is row-insertion time only

**Therefore: Last Order = `MAX(orders.order_date)`.** `CustomerOrderMetricsService` was the
inconsistent side and now matches; the frontend was already reading `order_date`, so the two agree.
Also aligned: `purchasedProducts.last_ordered_at`, and "most recent order" for the Location URL
(`order_date DESC, created_at DESC, id DESC`).

**No Order-domain file, and neither `order_date` nor `created_at`, was modified.**

`CustomerLastOrderContractTest` deliberately makes `order_date` and `created_at` disagree so a
regression to `MAX(created_at)` fails loudly. **It has not yet been executed — see §24.**

---

## 12 — Activity / Timeline — **DEFECT FOUND AND FIXED**

| Source | Before | After |
|---|---|---|
| `OrderTimelineSource` | accepted `$companyId`, **never applied it**; no `deleted_at` filter | `where('company_id', …)` + `whereNull('deleted_at')` + `order_date DESC, id DESC` |
| `ConversationTimelineSource` | already scoped (`Schema::hasColumn` guard) | unchanged |
| `CrmNoteTimelineSource` | not scoped by company | **unchanged — `crm_customer_notes` has no `company_id` column** (verified against the schema); it can only be scoped via `customer_id`, which is already company-scoped upstream |
| `TimelineService::collect()` | `usort` on timestamp **only** | `[occurredAt, refId]` — the same `[occurred_at, id]` convention `ActivityTimelineService` documents |

`ActivityTimelineService` (Logistics) was **not touched**; its `[occurred_at, id]` ordering is intact.

Two tests were written for the timeline (foreign-company order excluded; soft-deleted order excluded).
**Not yet executed — §24.**

---

## 13 — Permissions — **PASS with 1 CONTRACT GAP**

**One canonical namespace, `crm.customers.*`, shared by both stacks.** No new namespace invented, no
permission weakened.

| Route | Permission |
|---|---|
| Sales `POST /customers` | `crm.customers.create` |
| Sales `PUT /customers/{id}` | `crm.customers.update` |
| Sales `DELETE /customers/{id}` | `crm.customers.delete` |
| Sales addresses store/update/destroy | `crm.customers.update` |
| CRM index / show / profile | `crm.customers.view` |
| CRM store / update / archive / merge | create / update / archive / merge |

**(A) CONTRACT GAP — Sales customer *reads* have no permission gate.**
`GET /customers`, `GET /customers/{id}`, `search-by-phone` and the address `index` require
authentication only, while the equivalent CRM reads require `crm.customers.view`. Every one of them is
still **tenant-scoped**, so this is an authorisation-granularity gap, **not a data leak**. Adding the
gate would *strengthen* the routes but could lock out users who rely on them today — a decision for
you, not a silent change.

**(B) CONTRACT GAP — super-admin divergence.** Sales = unrestricted, CRM = fail-closed (§4).

---

## 14 — Tenant Isolation Matrix (PART 15)

| # | Case | Status | Evidence |
|---|---|---|---|
| 1 | Customer detail | PASS | test, 404 not 403 |
| 2 | Search by phone | PASS | test, `data: null` |
| 3 | Address list | PASS | test |
| 4 | Address create | PASS | test + `assertDatabaseMissing` |
| 5 | Address update | PASS | shared scoped helper |
| 6 | Address delete | PASS | shared scoped helper |
| 7 | Metrics | PASS | `company_id` on all queries |
| 8 | Preferred governorate | PASS | test |
| 9 | Orders / metrics | PASS | test |
| 10 | CRM Customer | PASS | `ResolvesCustomerContext`, 404 |
| 11 | **Customer timeline** | **FIXED — test written, not yet run** | §12 |
| — | Super-admin unrestricted | PASS | test |

---

## 15 — API — **PASS**

No new endpoint was created in this task or the previous one. Both customer APIs were extended in
place.

`GET /customers` item shape: identity fields + `orders_count`, `total_order_value`, `delivered_count`,
`receiving_rate`, `average_order_value`, `last_order_at`, `top_products_count`, `top_products[]`,
`location_url`, `full_address`, `preferred_governorate`. Meta: `current_page`, `per_page`, `total`,
`last_page`.

`GET /customers/{id}` adds `purchased_products[]`. `purchased_products` is deliberately **absent from
the list** — it is the heavier per-product query.

**No frontend consumer depends on an undocumented field, and no UI-required field is absent from the
API** — verified by matching every field read in both customer UIs against the controller output.

---

## 16 — Frontend — **PASS**

No mock data, no static business metrics, no duplicate calculation, no dead API calls, no stale field
names — all verified by grep across `features/customers` and `features/crm`. The only client-side
logic is presentation formatting.

---

## 17 — Cross-System Consistency — **PASS**

For the same customer, Sales and CRM resolve the **same rows**: identity and phone from `customers`,
addresses from `customer_addresses`, company from `customers.company_id`, and all order metrics —
including preferred governorate and last order — from the **single** `CustomerOrderMetricsService`.
CRM is not a projection (§2), so no mapping or synchronisation logic is required, and none was added.

---

## 18 — Tests

| Suite | Result |
|---|---|
| `CustomerPreferredGovernorateTest` | **7/7, 11 assertions — PASS** (previous round) |
| `CustomerTenantIsolationTest` | **12/12, 23 assertions — PASS** (previous round) |
| `CustomerTenantIsolationTest` — +2 new timeline tests (now 14) | **NOT YET RUN** |
| `CustomerLastOrderContractTest` — 5 tests | **NOT YET RUN** |
| `Modules/Sales/Customers/Tests` (full) | **BLOCKED — §19** |

## 19 — Control Runs — **NOT PERFORMED, and why**

PART 17 requires a pristine-HEAD control before calling any failure pre-existing. **I did not run one,
and I am not classifying any failure as pre-existing**, because the full suite could not be executed
at all.

**The shared runner is occupied by another agent.** Checked correctly via `/proc` — the container has
**no `ps` binary**, so `ps` is not a valid occupancy signal here:

```
php vendor/bin/phpunit tests/Feature/Commerce/OrdersInventoryExecutionLifecycleTest.php
```

That process holds `ecos_dev_test`; my earlier directory run died with
`SQLSTATE[40001] Deadlock found` inside `migrate:fresh`'s `drop table`. Per PART 16 I **did not
compete and did not touch that process**. Re-checked repeatedly through this task — still running.

## 20 — Static Quality — **PASS**

| Check | Result |
|---|---|
| `php -l` — every changed file | no syntax errors |
| PHPStan — level 0, entire platform | **[OK] No errors** |
| PHPStan — core level 6 | **[OK] No errors** |
| PHPStan — `Modules/Sales/Customers` + `Modules/Crm/Engagement` | **[OK] No errors** |
| Pint — my files | **passed** (14 pre-existing violations untouched, per PART 18) |
| TypeScript — total | **24 = documented baseline** |
| ESLint — `features/customers`, `features/crm`, `use-orders.ts` | **clean** |
| Vite build | **✓ built in 6.75s** |
| `git diff --check` | **clean** |

## 21 — Deployment — **PASS**

```
HOST == TEST RUNNER == APP   md5 MATCH on all 14 changed backend files
frontend -> ecos-dev-nginx AND ecos-dev-app
served: assets/index-IUTsJvDp.js
  preferred_governorate · last_order_at · top_products_count · location_url   ALL PRESENT
  govCounts  REMOVED
MAIN untouched — ecos-nginx index.html still Aug 7 19:37
```

## 22 — HTTP Verification — **PARTIAL**

Unauthenticated probes over the real nginx→PHP-FPM stack — all seven customer endpoints answer
**401**, proving routing, middleware and auth are live on the deployed code:

```
/api/customers                                401
/api/customers/{id}                           401
/api/customers/search-by-phone                401
/api/customers/{id}/addresses                 401
/api/crm/customers                            401
/api/crm/customers/{id}/profile               401
/api/crm/customers/{id}/timeline              401
```

**Authenticated HTTP** is covered by the feature tests, which issue real requests through the HTTP
kernel (`getJson` / `postJson` — full routing, middleware, permissions, controllers). **Authenticated
`curl` was not performed:** it needs a session or token, and I do not enter credentials. This is the
`PARTIAL` rather than `PASS`.

## 23 — Browser E2E — **PENDING USER BROWSER SMOKE**

Both the in-app browser and the local Chrome redirect to `/app/login`; I do not enter credentials.
**REAL E2E = PENDING USER BROWSER SMOKE.** No browser flow is claimed as verified.

Checklist for you (all 14 flows in PART 21) is in §25.

---

## 24 — Remaining Gaps — every item classified

| # | Item | Status |
|---|---|---|
| 1 | LAST_ORDER_DATE_CONTRACT | **PASS** — canonical found in the Order domain, service aligned |
| 2 | Full Customer regression | **BLOCKED** — shared runner occupied by another agent |
| 3 | Customer API coverage | **PASS** |
| 4 | Sales UI | **PASS** |
| 5 | CRM UI | **PASS** |
| 6 | Tenant isolation | **PASS** (item 11 of the matrix fixed; its test not yet run) |
| 7 | Permissions | **CONTRACT GAP** — Sales reads ungated vs CRM gated (§13-A) |
| 8 | Customer timeline | **PASS (code)** / **PENDING (test execution)** |
| 9 | Customer metrics | **PASS** |
| 10 | Deployment parity | **PASS** |
| 11 | Browser E2E | **PENDING USER** |

### Exact blockers to CERTIFIED

1. **`CustomerLastOrderContractTest` (5 tests) and the 2 new timeline tests have never been
   executed.** New code paths — the last-order change and the timeline tenant fix — are covered by
   tests that have not run. I will not certify a test I have not seen pass.
2. **Full `Modules/Sales/Customers/Tests` regression not run**, and therefore no HEAD control either.
3. **Browser E2E pending user.**

Blockers 1 and 2 have the same cause: the shared test runner is occupied and I did not compete.
**Both clear on a single run once the other agent's suite finishes** — nothing needs re-engineering.

### Independent repair candidates (other domains — recorded, NOT fixed, per PART 22)

1. **`OrderResource` customer-stats query is not company-scoped.**
   `Modules/Commerce/Orders/Presentation/Http/Resources/OrderResource.php:82` filters `customer_id`
   + `deleted_at` with **no `company_id`**, exposing `total_orders`, `lifetime_value`,
   `first_order_date`, `last_order_date`. Practical risk is low — a `customer_id` belongs to exactly
   one company — but the defence-in-depth filter is missing. **Orders domain; the Customer paths do
   not consume it, so it is out of scope here.**
2. **`useCustomerOrderStats` still aggregates in React** for the two Orders screens
   (`totalSpend`/`completed`/`cancelled`/`aov` over a 200-order page). Only `preferredGovernorate` was
   moved server-side. Unchanged and still open — a customer with 200+ orders will see Orders screens
   disagree with the Customers workspace.

---

## 25 — Final Verdict

> ## **NOT CERTIFIED**
>
> - **Customer Backend** — PASS
> - **Sales Customer API** — PASS · **CRM Customer API** — PASS
> - **Tenant Isolation** — PASS (timeline fixed; its test unrun)
> - **Permissions** — PASS with a documented CONTRACT GAP
> - **Metrics · Preferred Governorate · Addresses** — PASS
> - **Last Order** — PASS (contract resolved; test unrun)
> - **Timeline** — code PASS, test execution PENDING
> - **Sales UI · CRM UI** — PASS
> - **HTTP** — PARTIAL (unauthenticated PASS; authenticated via feature tests, not curl)
> - **Regression** — **BLOCKED**
> - **Deployment** — PASS
> - **REAL E2E** — **PENDING USER BROWSER SMOKE**
>
> Browser is **not** the only outstanding gate — the unrun tests and blocked regression are engineering
> gates — so the verdict is **NOT CERTIFIED**, not "ENGINEERING = PASS, E2E pending".

### To reach CERTIFIED

1. Re-run `Modules/Sales/Customers/Tests` once the other agent's suite releases the runner
   (expected: 7 + 14 + 5 + `CustomerCreationTest`). If anything fails, run a HEAD control before
   classifying it.
2. Your browser smoke of the 14 flows below.
3. Decide the two contract gaps in §13.

### Browser smoke checklist (PART 21)

**Sales — `/app/customers`** (hard-reload once): 1 open Customers · 2 search · 3 open Customer 360 ·
4 phone → Call/WhatsApp/Copy, and it must **not** open the drawer · 5 address · 6 orders/metrics ·
7 preferred governorate · 8 last order.

**CRM — `/app/crm/customers`**: 9 open customer · 10 profile · 11 activity/timeline · 12 same
canonical data as Sales for the same customer · 13 metrics · 14 address/contact.

---

> **NOT CERTIFIED — ENGINEERING PASS WITH 1 UNVERIFIED ITEM · REGRESSION BLOCKED · REAL E2E PENDING USER**
