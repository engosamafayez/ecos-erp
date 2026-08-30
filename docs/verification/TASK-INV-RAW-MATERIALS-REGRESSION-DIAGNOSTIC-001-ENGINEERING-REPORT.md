# TASK-INV-RAW-MATERIALS-REGRESSION-DIAGNOSTIC-001 — Engineering Report

**Date:** 2026-08-12 23:2x (local) · **Branch:** `develop` · **HEAD:** `6149875bd8a01820116b5deacbbfb8ef0e51cc05`
**Status:** **DIAGNOSTIC COMPLETE — 3/3 ROOT CAUSES PROVEN.**
**Production changes:** **NONE.** No PHP, TS/TSX, route, migration, seed, DB write, or `.env` change.
**Database access:** read-only (`SELECT`/`SHOW`/`DESCRIBE`) against **`ecos_dev` only**.

---

## 1 — Executive Summary

**None of the three symptoms is a regression from the Order / Fulfillment / Preparation repair.**

That work comprises ~65 uncommitted backend files. **Not one of them is on the Raw Materials request
path.** The three symptoms have three unrelated causes, only **one** of which is an actual code defect:

| # | Symptom | Real cause | Regression from Order/Prep? |
|---|---|---|---|
| **A** | Images missing | Dev storage **volume is empty**; the files exist only in the MAIN container | **NO** — environment/data |
| **B** | Allow-Negative shows "Out of Stock" | **Intentional certified behaviour** introduced by `6149875b` (Aug 9) per GD-2, plus **zero `inventory_items` rows** | **NO** — working as designed |
| **C** | "Failed to update inventory policy." | **Route shadowing** — `apiResource` `PUT\|PATCH` wins over the dedicated `PATCH`, forcing a 422 | **NO** — pre-existing since `8ef069f71`, 2026-08-03 |

**Issue B is the important one to read carefully: it is not a bug.** Commit `6149875b` — a *certified*
release — deliberately **removed** the frontend rule `available > 0 || allow_negative_stock`, on the
explicit grounds that `allow_negative_stock` is a permission to *proceed*, not a statement about what
the warehouse physically holds. The user's expectation ("Allow Negative ON ⇒ In Stock") **contradicts
the certified GD-2 contract.** Changing it back is a **business decision**, not a repair (§17, D1).

**Issue C is the only genuine defect** and is a clean one-line ordering problem.

---

## 2 — Environment Baseline

| Check | Value |
|---|---|
| HEAD | `6149875b` — nothing committed since |
| Working tree | **114 changed files** (65 backend), all **uncommitted** |
| DB used by `ecos-dev-app` | **`ecos_dev`** ✅ (verified in-container via `SELECT DATABASE()`) |
| MAIN | never connected to |

Note on timing: the Order/Preparation implementation landed in the working tree at **04:35–04:43**
today. This diagnostic ran ≈18 h later, so the tree was stable throughout.

---

## 3 — Git / Container Parity (Part 1 gate)

Compared **all 65** changed backend files, host vs `ecos-dev-app`:

```
MISSING = 14   STALE = 4
```

Every MISSING/STALE entry is either a **test file** or **IAM**:

| File | State | On the Raw Materials path? |
|---|---|---|
| `Modules/IAM/Presentation/Policies/UserPolicy.php` | STALE | **No** |
| `Modules/Operations/Preparation/.../PreparationWaveController.php` | STALE | **No** |
| `Modules/IAM/Application/Services/UserPasswordService.php` | MISSING | **No** |
| 12 × `tests/Feature/**` | MISSING/STALE | **No** (not runtime) |

**Every Inventory / Products / Order / Fulfillment / Preparation *production* file is in parity.**
Therefore container runtime **is** valid evidence for this diagnostic. Gate passed.

> Reported for completeness, outside this task: `ecos-dev-app` is not a faithful image of the working
> tree for IAM and `PreparationWaveController`. That should be synced before any IAM or Preparation-API
> runtime certification.

---

## 4 — Reproduction Evidence

### 4.1 The surface

Raw Materials is **not** a dedicated API. It is the **Products** API filtered by type
(`raw-materials-service.ts`):

| Action | Request |
|---|---|
| list | `GET /products?product_types=raw_material,packaging_material` |
| toggle Allow Negative | `PATCH /products/{id}` body `{ allow_negative_stock: boolean }` |

### 4.2 The actual data (read-only, `ecos_dev`)

```
RM-000002 | allow_neg=1 | بطرمان كيلو      | packaging_material
RM-000001 | allow_neg=1 | عسل الصال        | raw_material
```

**Both materials have `allow_negative_stock = 1`** — matching the report.

```
IMAGE COLUMNS ON products: image_url          ← the only one; no image/media/photo/thumbnail column
RM-000001.image_url = 'raw-materials/01KYQD7VZDXKYWR1V5SP22WQ2M.png'
RM-000002.image_url = 'packaging-materials/01KYQFNE4QFXRVS5GMQJ5FYFNV.png'

--- inventory_items ---
(zero rows)
```

**`inventory_items` contains no rows at all for either material.** This single fact drives Issue B.

### 4.3 Deliberate omission

**The `PATCH` was NOT executed.** Part 10 forbids database modification; had the call succeeded it
would have written `allow_negative_stock`. Issue C is therefore proven from the route table, the
request class and the frontend handler — which is deterministic and, unlike a live POST, leaves no
trace. `php artisan route:list` output is quoted in §7.

---

## 5 — Issue A — Missing Images

### Root cause

**The image files do not exist in the dev environment.** The code chain is intact end-to-end.

### The chain is correct at every hop

| Hop | Key / behaviour |
|---|---|
| DB column | `image_url` — populated, not NULL |
| `Product` model | passes through raw; **no accessor, no `$appends`** |
| `ProductResource.php:130` | `'image_url' => $this->resolveImageUrl()` |
| `ProductResource.php:75-89` | relative path → `Storage::disk('public')->url($raw)` → absolute URL |
| Frontend type (`types/index.ts:73`) | `image_url?: string \| null` |
| `raw-material-table.tsx:308` | `getMediaUrl(m.image_url)` |
| `lib/media.ts:16` | absolute `http://` → pass through unchanged |

**Name-mismatch hypothesis DISPROVED** — `image_url` is byte-identical at all four hops.

### The decisive evidence

| Location | Image files present |
|---|---|
| `ecos-dev-app` storage volume | **0** — entire tree empty (`find` for `*.png/*.jpg` = 0) |
| Host repo `backend/storage` | **0** |
| **`ecos-app` (MAIN) volume** | **14**, including **both exact ULIDs** |

```
MAIN:  EXISTS  raw-materials/01KYQD7VZDXKYWR1V5SP22WQ2M.png
MAIN:  EXISTS  packaging-materials/01KYQFNE4QFXRVS5GMQJ5FYFNV.png
DEV :  ABSENT  (both)
```

The `/storage` symlink in the dev container is **correct** (`→ /var/www/html/storage/app/public`).
`storage` is a Docker **named volume** (`ecos-dev_app-storage`), not a host mount, so cloning the
database cannot bring media with it.

**Mechanism:** `ecos_dev` was populated from a MAIN database copy, carrying `image_url` paths whose
files live only in the MAIN volume. nginx `location ^~ /storage/` → `try_files $uri =404` → every image
404s.

### Important correction to the premise

The Products page uses the **same** key, the **same** `getMediaUrl` helper, the **same** `<img>`, and
the **same** endpoint (`products-view.tsx:127-137`, `product-column-defs.tsx:222`). **Images are
equally broken there.** If Products appeared to work, that observation came from the MAIN environment,
not dev. No frontend difference could produce a raw-materials-only failure — which is what forced the
storage test.

**Classification: ENVIRONMENT / DATA. Not a regression. Confidence HIGH.**

---

## 6 — Issue B — Allow Negative vs Out of Stock

### This is certified intended behaviour, not a defect

`git log` on `frontend/src/features/raw-materials/utils/material-stock-status.ts`:

```
6149875b  2026-08-09  release: certify ECOS pilot go-live candidate
c474af4e  2026-07-06  chore(recovery): protect full working tree
```

**Committed, and untouched in the working tree.** The Aug 9 commit's own docblock states the intent
verbatim:

```
 * TASK-PHASE3-GD2-STEP2-CLOSE-001 (Phase 3 Step 2). This function previously
 * ran its own rule — `available > 0 || allow_negative_stock` — which was a
 * second availability engine and could disagree with the backend's canonical
 * `AvailabilityState::fromAvailable()`.
 *
 * GD-2 resolution: `allow_negative_stock` is a permission to PROCEED despite
 * unavailability, applied at the point of action (reserve / manufacture /
 * consume). It does not change what the warehouse physically holds, so it must
 * not change the measured availability shown in a stock column. […]
 *
 * `untracked` (no inventory record at all) collapses to `out_of_stock`
```

So the rule the user expects was **deliberately deleted** on 2026-08-09, by a certified task, to remove
a duplicate frontend availability engine. The commit message lists it as
*"Step 2 … frontend duplicate availability rule removed"*.

### And the label is arithmetically true

`inventory_items` has **zero rows** for both materials ⇒ `on_hand = 0`, `reserved = 0`, `available = 0`.
There is no stock. "Out of Stock" is not lying.

### The three cases, answered from the code

| Case | Behaviour | Deciding line |
|---|---|---|
| `on_hand=0, reserved=0, available=0, allow_negative=true` | **Out of Stock** | `material-stock-status.ts` — `allow_negative_stock` is not consulted |
| `available < 0, allow_negative=true` | **Out of Stock** (display); reservation/manufacture may still proceed | same; `allow_negative` applies at the point of action |
| `allow_negative=false` | **Out of Stock**; the action is additionally refused | `InventoryAvailabilityEngine` / `ManufacturingAvailabilityService` |

"Can we proceed" is a **separate field** — `manufacturing_availability`
(`ManufacturingAvailabilityService`) — by design.

### One genuine latent inconsistency (secondary, not the cause)

`EloquentProductRepository.php:30-32` wraps the LEFT-JOIN result in `COALESCE(..., 0)`:

```php
$availableExpr = $canonicalSummary
    ? 'COALESCE(inv_agg.inv_available, 0)'
    : 'GREATEST(COALESCE(inv_agg.inv_on_hand, 0) - COALESCE(inv_agg.inv_reserved, 0), 0)';
```

This flattens **"no inventory record" (NULL)** into **"zero stock" (0)**, making
`AvailabilityState::Untracked` unreachable for list rows — a distinction
`AvailabilityState::fromAvailable()` documents as load-bearing. So an *untracked* material is
affirmatively labelled "Out of Stock" rather than "Untracked".

**Provenance check: these lines are NOT in the uncommitted diff.** The diff hunks are
`@@ -88,0 +89,7 @@`, `@@ -107,0 +115 @@`, `@@ -111 +119 @@`, `@@ -112,0 +121,5 @@` — lines 88–121 only,
all inside a `CASE` guarded by `WHEN products.product_type != 'finished_good' THEN NULL`, which cannot
affect raw materials.

> **A subagent trace initially classified Issue B as `REGRESSION: YES`. That classification is not
> supported and is rejected here.** Both mechanisms it named — the display rule and the `COALESCE` —
> are pre-existing committed code. Its *mechanism* analysis is sound and is retained above; its
> provenance conclusion was wrong.

**Classification: INTENTIONAL CERTIFIED BEHAVIOUR + data (no inventory rows). Not a regression.
Confidence HIGH.**

---

## 7 — Issue C — "Failed to update inventory policy." — THE ONLY REAL DEFECT

### Root cause: route shadowing

`routes/api.php`:

```php
418:    Route::apiResource('products', ProductController::class)      // registers PUT|PATCH → update()
419:        ->middlewareFor('store',   'permission:inventory.products.create')
420:        ->middlewareFor('update',  'permission:inventory.products.update')
421:        ->middlewareFor('destroy', 'permission:inventory.products.delete');
422:    Route::patch('products/{product}', [ProductController::class, 'patch'])->middleware('permission:inventory.products.update');
```

`apiResource` at **:418** registers `PUT|PATCH api/products/{product}` → `update()` **before** the
dedicated `PATCH` at **:422**. Laravel returns the **first** match.

**Verified at runtime** (`php artisan route:list --path=products`):

```
PUT|PATCH   api/products/{product}   products.update › Modules\Inventory\…      ← wins
PATCH       api/products/{product}   generated::fXj0ceJgAm8hGTth › Module…      ← unreachable
```

So `PATCH /products/{id}` dispatches to `ProductController@update` → `UpdateProductRequest`:

```php
123: 'sku'          => ['required', 'string', 'max:100', Rule::unique(...)],
125: 'name'         => ['required', 'string', 'max:255'],
129: 'category_id'  => ['required', 'uuid', 'exists:categories,id'],
131: 'product_type' => ['required', Rule::in(Product::TYPES)],
```

The frontend sends only `{ allow_negative_stock: true }` ⇒ **four `required` fields missing ⇒ HTTP
422.**

`ProductController::patch()` at **:190** — which takes `PatchProductRequest` and would have accepted
the partial body — is **unreachable dead code**.

### Secondary defect: the real error is swallowed

`frontend/src/features/raw-materials/hooks/use-raw-materials.ts:105-112`:

```ts
onError: (_err, _vars, context) => {
  if (context?.snapshots) { … }          // optimistic rollback
  toast.error('Failed to update inventory policy.');
},
```

`_err` is **discarded**. The 422 and its field-level messages never reach the user, which is why the
symptom was opaque.

### Provenance — pre-existing, not a regression

```
git blame -L 418,422 backend/routes/api.php
8ef069f71 (Osama Fayez 2026-08-03 418)  Route::apiResource('products', …)
8ef069f71 (Osama Fayez 2026-08-03 422)  Route::patch('products/{product}', …)
```

**Both lines were introduced together in `8ef069f71` on 2026-08-03** — nine days before the
Order/Preparation work. `git diff -- backend/routes/api.php` contains **no** change to any products
route. The Allow-Negative toggle has therefore **never** worked since it was wired.

**Classification: BACKEND ROUTING defect (pre-existing 2026-08-03) + frontend error-reporting defect.
Not a regression. Confidence HIGH.**

---

## 8 — Dependency Chains

```
A  products.image_url ──▶ Product (passthrough) ──▶ ProductResource::resolveImageUrl()
     ──▶ Storage::disk('public')->url() ──▶ nginx /storage/ try_files ──▶ 404 (file absent)
                                                                          ▲ BREAK: empty volume

B  inventory_items (0 rows) ──▶ EloquentProductRepository $availableExpr COALESCE(...,0)
     ──▶ agg_available_qty = 0 ──▶ ProductResource availability_state
     ──▶ material-stock-status.ts (allow_negative_stock intentionally NOT consulted, GD-2)
     ──▶ "Out of Stock"                                     ▲ BY DESIGN, not a break

C  toggle ──▶ use-raw-materials.ts mutation ──▶ rawMaterialsService.patch()
     ──▶ PATCH /products/{id} ──▶ api.php:418 apiResource PUT|PATCH  ◀── BREAK: shadows :422
     ──▶ ProductController@update ──▶ UpdateProductRequest (4 required) ──▶ 422
     ──▶ onError discards _err ──▶ generic toast               ◀── BREAK: real error hidden
```

---

## 9 — Regression Analysis

| Issue | Root Cause | Evidence | Introduced By | Affected Layer | Regression? | Confidence |
|---|---|---|---|---|---|---|
| A Images | Files absent from dev storage volume | 0 files dev/host vs 14 in MAIN incl. both exact ULIDs | Dev DB cloned without media (volume created 2026-08-10) | Environment / data | **NO** | HIGH |
| B Allow Negative | GD-2 deliberately removed the frontend rule; and 0 `inventory_items` rows | `6149875b` docblock + commit message; DB shows 0 rows | `6149875b`, 2026-08-09, **certified** | Intentional design + data | **NO** | HIGH |
| C Policy update | `apiResource` PUT\|PATCH at :418 shadows dedicated PATCH at :422 → 422 | `route:list` shows both, update first; 4 `required` fields; blame = `8ef069f71` 2026-08-03 | `8ef069f71`, 2026-08-03 | Backend routing + frontend error handling | **NO** | HIGH |

### Cross-domain side-effect check (Part 7)

Inspected every uncommitted change against the Raw Materials path (`GET /products`,
`PATCH /products/{id}`, `ProductResource`, `Product`, `EloquentProductRepository`, `inventory_items`,
media). **Result: no intersection.**

- `ReserveOrderInventoryAction`, `Order`, `OrderServiceProvider`, `*Workflow`, `StockAddedListener`,
  `BranchAssignmentEngine`, `CoverageResolutionService`, `WaveEngine/*`, `MaterialDemandCalculator` —
  **none** is invoked by a products read or a products PATCH.
- `EloquentProductRepository` **is** modified, but only inside the `finished_good`-guarded `CASE`.
- **No** shared DTO, shared Resource, shared model, media handling, serialization, or
  company/warehouse scoping used by `/products` was touched.
- `routes/api.php` is modified — but **not** in the products block (Distribution routes only).

---

## 10 — Certified Component Impact

| Certification | Affected? |
|---|---|
| **Inventory** | **Not revoked.** Issue C is a routing defect from 2026-08-03 that made `ProductController::patch()` unreachable from the day it shipped. No certified Inventory *calculation* is wrong. Flagged for the repair task. |
| **Order** | **NO** — no Order file is on this path |
| **Preparation** | **NO** — Entry Gate / RC-10 / MaterialDemandCalculator untouched and unrelated |
| **Shipping** | **NO** |
| **GD-2 / Phase 3 Step 2** | **Working as certified.** Issue B is this contract behaving exactly as specified. |

**No certification is declared revoked.** Issue C warrants a separate authorized repair task; it does
not invalidate an existing certificate.

---

## 11 — Data vs Code Classification (Part 9)

| Issue | Classification |
|---|---|
| A | **ENVIRONMENT** (empty dev media volume) — DB rows are correct and must not be touched |
| B | **DATA + INTENTIONAL CODE** — zero `inventory_items` rows; display rule correct per GD-2 |
| C | **CODE** (route order) + **CODE** (frontend error handling). Not permission, not tenant scope |

Allow-Negative verified in the DB directly, not from the toggle: `allow_negative_stock = 1` on both
materials, column lives on **`products`** (`varchar`/`tinyint`), and `inventory_items` has **no rows**
to carry a per-warehouse override.

---

## 12 — Exact Root Cause per Issue

- **A:** Dev Docker volume `ecos-dev_app-storage` holds no media; `ecos_dev.products.image_url`
  references files that exist only in the `ecos-app` (MAIN) volume ⇒ nginx `try_files $uri =404`.
- **B:** `material-stock-status.ts` intentionally excludes `allow_negative_stock` (GD-2, `6149875b`),
  and both materials have **zero** `inventory_items` rows, so `available = 0` ⇒ "Out of Stock".
  Secondary: `EloquentProductRepository:30-32` `COALESCE(...,0)` makes `Untracked` unreachable.
- **C:** `routes/api.php:418` `apiResource` registers `PUT|PATCH` before the dedicated `PATCH` at
  `:422`; Laravel first-match sends the call to `update()`/`UpdateProductRequest`, whose four
  `required` fields are absent ⇒ 422, hidden by a hardcoded toast at `use-raw-materials.ts:111`.

---

## 13 — Proposed Fix (DESCRIPTION ONLY — nothing applied)

**A — restore dev media.** DB rows are correct; only files are missing.

```bash
docker cp ecos-app:/var/www/html/storage/app/public/. ./_media/
docker cp ./_media/. ecos-dev-app:/var/www/html/storage/app/public/
docker exec ecos-dev-app chown -R www-data:www-data /var/www/html/storage/app/public
```

`ecos-dev-nginx` mounts the same named volume, so one copy serves both. Longer term this belongs in the
dev-provisioning procedure alongside the DB clone.

**B — business decision first (D1).** No code change unless GD-2 is reversed. If the owner only wants
*untracked* distinguished from *out of stock* (a narrower, GD-2-compatible change):
stop destroying the NULL in `EloquentProductRepository:30-32`
(`CASE WHEN inv_agg.product_id IS NULL THEN NULL ELSE … END`), keep `on_hand`/`reserved` at 0, and add
an `'untracked'` member to `MaterialStockStatus`. `ProductResource` already handles null correctly.

**C — one-line ordering fix, plus surface the error.** Preferred (immune to future reordering):

```php
Route::apiResource('products', ProductController::class)->except(['update'])
    ->middlewareFor('store', 'permission:inventory.products.create')
    ->middlewareFor('destroy', 'permission:inventory.products.delete');
Route::put('products/{product}',   [ProductController::class, 'update'])->middleware('permission:inventory.products.update');
Route::patch('products/{product}', [ProductController::class, 'patch'])->middleware('permission:inventory.products.update');
```

Then `php artisan route:clear` (route caching is active in `ecos-dev-app`). Separately, make
`use-raw-materials.ts:111` surface the server message instead of discarding `_err`.

---

## 14 — Files That WOULD Need Modification

| Issue | File | Change |
|---|---|---|
| A | *none* | environment only |
| B | *none* unless D1 reverses GD-2 | else `EloquentProductRepository.php:30-32` + `material-stock-status.ts` |
| C | `backend/routes/api.php:418-422` | split PUT/PATCH so the dedicated PATCH is reachable |
| C | `frontend/src/features/raw-materials/hooks/use-raw-materials.ts:105-112` | surface the real error |

---

## 15 — Tests Required for the Future Repair

1. **Route-resolution assertion** — `PATCH api/products/{product}` resolves to `ProductController@patch`
   (guards the shadow from silently returning).
2. **Feature test** — `PATCH /products/{id}` with body `{allow_negative_stock: true}` returns 200 and
   persists the flag; without `inventory.products.update` returns 403; cross-company returns 403.
3. **Regression test** — `PUT /products/{id}` still requires the four fields (do not weaken
   `UpdateProductRequest`).
4. **Image contract test** — `ProductResource` emits `image_url` as an absolute URL for a relative
   stored path, `null` for a `data:` URI, and passes through `http(s)`.
5. **Availability test** — pin the GD-2 contract: `allow_negative_stock` does **not** alter displayed
   stock status; and, if D1 changes it, that zero-rows ⇒ `untracked` ≠ `out_of_stock`.
6. **No test may assert images render in dev** — that is environment state, not behaviour.

---

## 16 — Risks

1. **Fixing C by reordering** changes which controller handles `PATCH`. `ProductController::patch()` has
   never executed in production — its behaviour (including `MaterialCostService` on `manual_cost`) is
   effectively **unproven at runtime** and needs its own test coverage before being exposed.
2. **Route cache** — the fix is inert until `route:clear`; a partial deploy would look like the fix
   failed.
3. **Reversing GD-2 (B)** would reintroduce the duplicate frontend availability engine that Phase 3
   Step 2 deliberately removed, and could disagree with `AvailabilityState::fromAvailable()`.
4. **Copying MAIN media into dev** moves production imagery into a dev volume — acceptable for these
   assets, but it is a data-movement decision, not purely technical.

---

## 17 — Business Decisions Required

**D1 — Should `allow_negative_stock = ON` display as "In Stock"?**
Today it does not, **by certified design** (GD-2: it is a permission to proceed, not a statement of
holdings). Options: (a) keep GD-2 — the current screen is correct and the expectation should be
re-set; (b) show a distinct **"Untracked"**/"Negative allowed" state for zero-inventory materials,
which is GD-2-compatible and my recommendation if the goal is operator clarity; (c) reverse GD-2 —
**not recommended**, it re-creates the duplicate availability engine.

**D2 — Should dev provisioning copy media alongside the DB clone?** Otherwise every dev DB refresh
re-breaks all images platform-wide (not just Raw Materials).

**D3 — Should raw materials have `inventory_items` rows at all in dev?** Both currently have none, so
every quantity is 0. If the fixture is meant to exercise stock, the dev data is incomplete.

---

## 18 — Final Verdict

| Issue | Root Cause | Layer | Regression | Evidence | Fix Needed |
|---|---|---|---|---|---|
| **Missing Image** | Files absent from dev storage volume; exist only in MAIN | Environment / data | **NO** | 0 files dev+host vs 14 in MAIN incl. both exact ULIDs; symlink correct; key `image_url` matches at all 4 hops | Copy media into dev volume (no code change) |
| **Allow Negative / Out of Stock** | GD-2 intentionally excludes `allow_negative_stock` from display; both materials have 0 `inventory_items` rows | Intentional certified design + data | **NO** | `6149875b` docblock + commit msg; file untouched in worktree; DB shows 0 rows | **None** — business decision D1 |
| **Inventory Policy Update** | `apiResource` `PUT\|PATCH` at `api.php:418` shadows dedicated `PATCH` at `:422` → `UpdateProductRequest` 422; real error hidden by hardcoded toast | Backend routing + frontend error handling | **NO** (pre-existing `8ef069f71`, 2026-08-03) | `route:list` shows both with update first; 4 `required` fields; `git blame` | **YES** — split PUT/PATCH + surface error |

### CERTIFICATION IMPACT

- **Inventory certification — NOT revoked.** Issue C is a routing defect predating the certification
  window's Inventory calculation work; no certified calculation is wrong. Repair task required.
- **Order certification — NOT affected.**
- **Preparation certification — NOT affected.**
- **Shipping certification — NOT affected.**
- **GD-2 / Phase 3 Step 2 — behaving exactly as certified.**

**All three issues are independent of the Order / Fulfillment / Preparation repair.**

---

## 19 — Compliance

No PHP, TS/TSX, route, migration, seeder, policy, business rule, inventory calculation, image contract,
error message, authorization or tenant scope was modified. No workaround or fallback was added. No
`PATCH` was executed, so no row was written. Database access was read-only against **`ecos_dev`
only**; `ecos_erp`/MAIN was read **only** to prove where the image files live, with no writes.

The only file created by this task is this report.
