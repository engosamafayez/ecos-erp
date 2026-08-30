# TASK-CUSTOMER-360-FRONTEND-VISIBILITY-DIAGNOSTIC-001

**Date:** 2026-08-14 · Static + deployment evidence. No PHPUnit, no E2E, no `ecos_dev` data modified.
**Status:** **NOT CERTIFIED — FRONTEND VISIBILITY DIAGNOSTIC**

You were right and the previous report was wrong to say "Frontend complete". The code existed; it was
never reaching a browser. **Three independent causes**, two of them fixed, one awaiting your decision.

---

## 1 — Active routes: there are TWO Customers screens

Both are registered, both are reachable from the sidebar, and **both are labelled "Customers"**.

| | **Commerce → Customers** | **CRM → Customers** |
|---|---|---|
| Route | `/customers` | `/crm/customers` |
| Nav | `module-navigation.ts:168`, group **Commerce** | `module-navigation.ts:313`, group **CRM** |
| Nav label (`common.json`) | `nav.items.customers` = **"Customers"** | `nav.items.crm-customers` = **"Customers"** |
| Page component | `features/customers/pages/customers-page.tsx` | `features/crm/pages/crm-customers-workspace-page.tsx` |
| List UI | hand-rolled `<table>` | `DataGridColumnDef[]` |
| 360 drawer | `features/customers/components/customer-drawer.tsx` | `features/crm/components/crm-customer-drawer.tsx` |
| Service | `customers-service.ts` → **`GET /customers`** | `crm-customers-service.ts` → **`GET /crm/customers`** |
| Backend controller | `Modules\Sales\Customers\…\CustomerController` (`api.php:504`) | `Modules\Crm\Customers\…\CustomerController` (`api.php:3239`) |
| Page title (en) | "Customers" | "Customers" |
| Subtitle | "Search by phone, name, order number or address." | "Every customer relationship in one **workspace**" |
| **Touched by my task?** | **NO — never** | **YES — all of it** |

Also routed: `/customers/:customerId` → `customer-profile-page.tsx` (legacy). Nothing deleted.

The legacy Commerce page already has its own columns — **Customer · Phones · Default Address ·
Previous Orders · Intel** — which is very likely why it looked like "the customers screen that should
have changed".

---

## 2 — Root causes

### RC-1 — Route/component mismatch *(needs your decision — not fixed)*

Everything I built lives on **`/crm/customers`**. If you opened **Commerce → Customers**
(`/customers`) you were looking at a different page, backed by a different controller in a different
module. No amount of cache clearing would have shown the new columns there.

I did **not** rewire it. Pointing Commerce's "Customers" at the CRM page changes which backend,
which permissions (`crm.customers.*` vs the Sales controller's) and which feature set that module
exposes — a product decision, not a diagnostic fix. **See §7.**

### RC-2 — The dev nginx was serving a 4-day-old bundle *(FIXED)*

`ecos-dev-nginx` has its **own baked-in copy** of `/var/www/html/public`. Its only mounts are:

```
bind   docker\nginx\local.conf      -> /etc/nginx/conf.d/default.conf
volume ecos-dev_app-storage         -> /var/www/html/storage
```

There is **no mount for `public/`** — so nginx serves static assets from its own image layer, not from
`ecos-dev-app`. Evidence before the fix:

| | `ecos-dev-app` | `ecos-dev-nginx` (what you actually get) |
|---|---|---|
| `index.html` | Aug 14 03:47 | **Aug 10 00:02** |
| references | `index-BqI_PetQ.js` | **`index-B9wSrZoZ.js`** |
| new fields present | yes | **no — zero occurrences** |

```
Last-Modified: Mon, 10 Aug 2026 00:02:29 GMT
Cache-Control: no-cache, no-store, must-revalidate   ← not browser caching. Genuinely stale on disk.
```

A build had been deployed into `ecos-dev-app` — the container that runs PHP but **serves no static
files**. So the bundle was correct in the wrong container.

### RC-3 — Backend never reached the container *(FIXED)*

Independently: the containers have no source mount, and my three backend files were only on the host.

```
ecos-dev-app  Customer360Service.php  : 0 occurrences of full_address   ← old file
host          Customer360Service.php  : present
```

So even on the right page with a fresh bundle, `full_address` and `location` would have come back
absent, and both columns would have rendered as `—`.

---

## 3 — The fix

1. `npx vite build` → `backend/public/app` (hash `index-BqI_PetQ.js`, identical to the existing build — deterministic, nothing new introduced).
2. `docker cp backend/public/app/. ecos-dev-nginx:/var/www/html/public/app/` ← **the actual fix for RC-2**
3. Same copy into `ecos-dev-app` for parity.
4. `docker cp` the 3 backend files into `ecos-dev-app`, **verified by md5 hash**.

**`ecos-nginx` / `ecos-app` (MAIN) were not touched.** MAIN's `index.html` is still Aug 7.

### Verified after the fix, over HTTP on 127.0.0.1:8081

```
GET /app/index.html      -> assets/index-BqI_PetQ.js
Last-Modified: Fri, 14 Aug 2026 00:50:30 GMT

index-BqI_PetQ.js   receiving_rate 1 · purchased_products 1 · full_address 1 · order_metrics 1
crm-C8M4-A1C.js     "Receiving Rate" 1 · "Products Purchased" 1        (EN, http 200)
crm-UZq8M3od.js     "معدل الاستلام" · "المنتجات المشتراة"                (AR)

host↔container md5:  Customer360Service MATCH · CustomerSearchService MATCH · CustomerController MATCH
opcache: validate_timestamps=On, revalidate_freq=0  → new PHP live immediately, no reload needed
no config/route cache in bootstrap/cache
```

Route existence (401 = exists + auth required, not 404):

```
/api/customers                          -> 401
/api/crm/customers                      -> 401
/api/crm/customers/{id}/profile         -> 401
```

---

## 4 — Render chain on `/crm/customers`

`crm-customers-workspace-page.tsx` → `useCrmCustomersQuery` → `crmCustomersService.list()` →
`GET /crm/customers` → `CustomerController::index()` → `identity()` + `CustomerOrderMetricsService::forCustomers()`.

| Element | Where it renders | Source field |
|---|---|---|
| Location | page:176 | `location` |
| Full Address | page:186 | `full_address` |
| Orders Count | page:195 | `orders_count` |
| Total Order Value | page:201 | `total_order_value` |
| Receiving Rate | page:208 | `receiving_rate` (`—` when null) |
| Last Order | page:220 | `last_order_at` |
| 6 × KPI | drawer, Overview tab | `order_metrics.*` |
| Products Purchased | drawer:392, own tab | `purchased_products[]` |

Products Purchased renders Product · SKU · Quantity · **Orders count** · Last Ordered — the orders
count is in the API contract (`CustomerOrderMetricsService::purchasedProducts()`), so it is included.

---

## 5 — One inconsistency found and fixed in my own change

`identity()` read the address only when the caller had eager-loaded it. The list and `profile()` do;
**`GET /crm/customers/{id}` (show) did not** — so the same customer's `full_address` would have come
back from the structured address on one endpoint and from the legacy `customers.*` columns on
another. Now resolved through one `defaultAddress()` helper that loads the relation if absent: zero
extra queries on the list (already eager-loaded), one query on the single-record paths.

§7 of your brief is respected — address source is still **structured `customer_addresses` default row**.
That decision was not revisited.

---

## 6 — What I could NOT verify, and why

**Visual browser confirmation was not obtained.** Both the in-app browser and your local Chrome
redirect to `/app/login`, and I do not enter credentials. So I can prove the code is *served* — I
cannot prove it *rendered*. That last step needs you (§8).

I also could not read the live JSON of `GET /crm/customers`; it requires auth. Its shape is proven
statically (controller composition) and by route existence, not by a live response body.

---

## 7 — Decision: SETTLED

**You confirmed you were on CRM → Customers (`/crm/customers`), and the scope is CRM only.**

That means **RC-1 was not your cause** — you were already on the right screen. **RC-2 (stale nginx
bundle) + RC-3 (backend absent from the container) fully explain the invisibility**, and both are fixed.

Commerce → Customers (`/customers`, Sales module) is **left exactly as it was**. Nothing was rewired,
nothing deleted. RC-1 is documented above only so the two screens are not confused again.

---

## 7b — Last check: nothing hides the columns

`UniversalDataGrid` does filter columns (`universal-data-grid.tsx:180`):

```ts
if (col.alwaysVisible) return true;
if (columnVisibility) return columnVisibility[col.key] ?? col.defaultVisible !== false;
return col.defaultVisible !== false;
```

The page passes **no** `columnVisibility`, and none of the six new columns sets `defaultVisible`, so
each resolves `undefined !== false` → **true**. All 12 columns render:

```
code · name · type · status · phone · email
location · full_address · orders_count · total_order_value · receiving_rate · last_order_at
```

**Correction to the previous report:** it said "no Columns Manager exists". More precisely — a
`ColumnVisibilityMenu` **does** exist in the design system (`components/data-grid/column-visibility-menu.tsx`);
this page simply does not use it. If you want the six new columns toggleable, that component is
already available and is a small addition. Not done, since item 2 was conditional.

---

## 8 — Please confirm in the browser

Open **CRM → Customers** (`/app/crm/customers`) — **hard-reload once** (Ctrl+F5); your browser may
still hold the Aug 10 `index.html`.

1. List shows Location · Address · Orders · Total Value · Receiving Rate · Last Order.
2. `CUS-00029` → Location reads **Shubra، Cairo** (structured address), not Maadi.
3. Open a customer → Overview shows 6 KPIs; a never-ordered customer shows `—`, not `0%`.
4. **Products Purchased** tab lists one row per distinct product.

If CRM → Customers is *not* the screen you were opening, that confirms RC-1 and the answer to §7 is B or C.

---

## 9 — Files changed this round

| File | Change |
|---|---|
| `Modules/Crm/Customers/Domain/Services/Customer360Service.php` | `defaultAddress()` helper — consistent address across all three endpoints |

Deployment actions (no source change): frontend build → `ecos-dev-nginx` + `ecos-dev-app`;
3 backend files → `ecos-dev-app`.

## 10 — Static verification

| Check | Result |
|---|---|
| TypeScript total | **24 = baseline** |
| ESLint `src/features/crm` | **clean** |
| Vite build | **✓ built in 4.84s** |
| `git diff --check` | **clean** |
| PHPStan L0, entire platform | **[OK] No errors** |
| Pint — `Customer360Service.php` | **passed** |
| PHPUnit / E2E | **not run**, per instruction |

---

> **NOT CERTIFIED — FRONTEND VISIBILITY DIAGNOSTIC.** The bundle now served by dev nginx provably
> contains the code, and host↔container parity is hash-verified. Rendering is unconfirmed until §8.
