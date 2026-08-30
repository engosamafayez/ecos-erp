# TASK-CUSTOMER-PRODUCT-READMODEL-UI-CONTRACT-CLOSURE-001 — Engineering Report

## CONTINUATION — PRODUCT UNIT CONTRACT

**Date:** 2026-08-18 · **Branch:** `develop` · **Worktree:** `C:\ecos-develop` · **HEAD:** `abe4d10f`

> ## STATUS
>
> | Item | Status |
> |---|---|
> | **D-1 `preferredGovernorate`** | **PRESERVED** — canonical backend definition confirmed, untouched |
> | **D-2 `lastOrderDate`** | **PRESERVED** — canonical `MAX(order_date)` confirmed, untouched |
> | **D-5 Product Unit contract** | **SUPERSEDED by CONTINUATION-001 below** — owner confirmed the contract; write-path enforcement is now **IMPLEMENTATION COMPLETE**, legacy data remediation remains |
> | Final certification | **DEFERRED** (project policy) |
>
> **Note:** the BLOCKED verdict recorded in this section was resolved by the owner supplying the
> missing authority ("EVERY PRODUCT MUST HAVE A UNIT is the current business contract; a historical
> migration contradicting it does not redefine the rule"). §CONTINUATION-001 is the current state.
>
> ⚠️ **The sections immediately below (§1–§19) are the ORIGINAL investigation, retained as the
> evidence record. Their "UNCHANGED / BLOCKED" statements were true at the time and have since
> been superseded — see §CONTINUATION-001 for the implemented state.**
>
> What remains true throughout: **no Unit was guessed and no legacy product was backfilled.**

---

## 1. Current implementation state

| Write path | `unit_id` handling | Evidence |
|---|---|---|
| `StoreProductRequest:62` (create) | `['sometimes', 'nullable', 'uuid', 'exists:units,id']` | permissive |
| `UpdateProductRequest:130` (update) | `['sometimes', 'nullable', 'uuid', 'exists:units,id']` | permissive |
| `ProductFactory:39` | `'unit_id' => Unit::factory()` | always sets a unit |
| WooCommerce import | `'unit_id' => $defaultUnitId` | always sets a unit — see §10 |

## 2. D-1 confirmation — PRESERVED, NOT REBUILT

`CustomerOrderMetricsService::preferredGovernorateForCustomers(array $customerIds, string $companyId)`
is the canonical, company-scoped definition, with its own test
(`CustomerPreferredGovernorateTest`). Its docblock records that the metric was **moved out of
React into the service**. The UI consumes `customer.preferred_governorate` from the API; a search
of `features/customers` and `order-customer-badge` for residual client-side frequency logic
returns **zero** hits.

**Not modified.**

## 3. D-2 confirmation — PRESERVED, NOT REBUILT

Canonical definition is **`MAX(order_date)`**, explicitly *not* `MAX(created_at)` —
`CustomerOrderMetricsService` (lines 46, 81, 86) and mirrored in `OrderResource:85`.

**Not modified.**

## 4. D-5 contract investigation — the seven questions

Searched: `docs/adr/`, `docs/contracts/`, `docs/domain/`, the whole `Inventory/Products` module,
migrations, requests, factories, seeders and the WooCommerce integration.

| # | Question | Answer | Evidence |
|---|---|---|---|
| 1 | What Unit for an existing NULL-unit product? | **NO RULE EXISTS** | zero hits for "must have a unit / unit is required / mandatory unit" across ADRs, contracts, domain docs and the Products module |
| 2 | Deterministic source to recover the correct Unit? | **NO** | no product audit table, no snapshot, no import metadata retains a historical unit. Only `Product.php` references `unit_id` |
| 3 | Does the mandatory contract apply to create/update/import/API/factory? | **UNDEFINED** | create and update are *both* `sometimes|nullable`; factory and importer always set one. No document states the scope |
| 4 | WooCommerce product with no determinable Unit? | **A rule exists — but it is the one the task forbids as a backfill source** | see §10 |
| 5 | Should existing invalid Products be backfilled? | **UNDEFINED** | no rule found |
| 6 | If backfill is required, what deterministic source supplies the Unit? | **NONE EXISTS** (follows from Q2) | — |
| 7 | DB-level or application-level enforcement? | **UNDEFINED — and currently inconsistent** | see §4.2 |

### 4.1 The repository contains evidence *against* the stated contract

`products.unit_id` was originally **NOT NULL** with an FK:

```php
// 2026_06_23_110000_create_products_table.php:24
$table->foreignUuid('unit_id')->constrained('units')->restrictOnDelete();
```

A later migration was written specifically to **relax** it:

```
2026_07_03_000001_make_unit_id_nullable_in_products.php
```

So the premise *"the approved business contract requires every product to have a Unit"* is
contradicted by a committed migration whose entire purpose was to permit `unit_id = NULL`.
**Which of the two is authoritative is exactly the decision this task cannot make for the owner.**

### 4.2 🐛 BUG FOUND (not fixed) — the nullable migration is a no-op with an inverted guard

```php
public function up(): void
{
    if (Schema::hasColumn('products', 'unit_id')) {
        return;                                   // ← returns when the column EXISTS
    }
    Schema::table('products', fn ($t) => $t->foreignUuid('unit_id')->nullable()->change());
}                                                  // ← unreachable
```

`unit_id` always exists (created in the 2026-06-23 migration), so `up()` **always returns early**
and the `nullable()->change()` **never executes**. `down()` carries the identical inversion.

### 4.3 🐛 CONSEQUENCE — the environments have drifted, and tests cannot reproduce production

| Environment | `products.unit_id` | Products with NULL unit |
|---|---|---|
| `ecos_dev` | **NULLABLE** | **6 of 18** |
| `ecos_dev_test` (the suite's DB) | **NOT NULL** | — |
| `ecos_erp` (production target) | **NULLABLE** | **1 of 3** |

The test database enforces `NOT NULL`; dev and production do not. Any test written now to assert
NULL-unit behaviour would exercise a schema that **does not match production**, so the required
tests (§13) cannot be made meaningful until the drift is resolved. This is an independent reason
implementation cannot proceed responsibly today.

## 5. The existing NULL-unit Products — audited, untouched

`ecos_dev`: **6 of 18** (33%). `ecos_erp`: **1 of 3**. **None was modified.**

No name-, type-, channel-, WooCommerce-metadata-, most-common- or first-available-unit inference
was applied, per the CRITICAL RULE.

## 6. Deterministic Unit source — **NOT FOUND**

There is no historical record from which a NULL product's correct unit can be recovered. Per the
task ("Before changing any historical record: prove the deterministic source… If no deterministic
source exists: leave them unchanged"), they were **left unchanged**.

## 7. Backfill decision — **NONE MADE (blocked)**

Making one would require inventing a rule the task explicitly prohibits.

## 8. Create behavior — **UNCHANGED**

Still `sometimes|nullable`. Tightening to `required` was **not** performed: Q3 (scope) and Q5
(existing rows) are unanswered, and doing so would immediately break editing/creating flows for
the environments where NULL rows legally exist.

## 9. Update behavior — **UNCHANGED**

Same reasoning. Rejecting removal of a Unit while 1 of 3 production products *has* no Unit would
make those products uneditable.

## 10. WooCommerce behavior — a defined rule that cannot be borrowed

`WooCommerceProductImporter` **does** have a defined contract:

```php
$defaultUnit = Unit::query()->first();
if ($defaultCategory === null || $defaultUnit === null) {
    return ImportResultDTO(… 'No default category or unit found. Create at least one category and one unit before importing.');
}
…
'unit_id' => $defaultUnitId,
```

Imported products therefore always receive a unit — **the first available Unit** — and the import
aborts if none exists.

**This cannot be reused as the backfill rule.** The task's CRITICAL RULE names
"**first available Unit**" and "arbitrary default" among the prohibited strategies. It is an
approved rule for *new imports only*; extending it to historical products would be exactly the
invention this task forbids.

**Not modified** — WooCommerce synchronization semantics are untouched.

## 11. Import behavior — **UNCHANGED**

No product-import path was altered.

## 12. Tenant isolation — preserved

`findById` scoped · `SearchCustomerByPhone` scoped · `CustomerAddressController` scoped ·
Super Admin unrestricted per `CurrentCompanyService`. **No change made, therefore no regression.**

## 13. Tests — none added

The ten required test scenarios all depend on the unresolved contract, and §4.3 shows the test
database's schema does not match production for this exact column. Writing them now would encode
an invented rule and prove it against the wrong schema. **No test was added, weakened or deleted.**

**Runtime limitation carried forward:** the D-1/D-2 suites
(`CustomerPreferredGovernorateTest`, `CustomerLastOrderContractTest`) again could **not** be run —
the shared runner reported `[GATE] busy (an ungated phpunit process is running)` on two attempts.
D-1/D-2 remain confirmed by **code inspection only**, as recorded in the previous batch.

## 14. Static checks — not applicable

No code was changed, so PHPStan / Pint / TypeScript / ESLint were not run. **No claim of global
static cleanliness is made.**

## 15. Files changed

**None.** This report is the only artefact written.

## 16. Files deliberately untouched

`ReleaseOrderInventoryAction` (Workstream A — complete) · Wave implementation (B — complete) ·
`DisassemblyWorkflow` / Recipe cost authority (C — complete) ·
`WarehouseAssignmentEngine::override()` (out-of-scope gap) · BOM/reservation release (out-of-scope
gap) · the staged `order-reservation-cell.tsx` · and the **concurrent Products work by another
session** now dirty in the tree (`EloquentProductRepository`, `ProductController`,
`ProductResource`, new `ProductAvailability` enum) — a further reason not to modify Product write
paths right now.

## 17. Runtime limitations

1. Shared test runner occupied by another session (twice) → D-1/D-2 suites unrun.
2. Test-DB schema differs from production for `products.unit_id` → NULL-unit behaviour is not
   faithfully testable until §4.2/§4.3 are resolved.

## 18. Remaining contract gaps

| # | Gap | Owner decision required |
|---|---|---|
| **1** | **Is "every Product must have a Unit" actually the contract?** | The 2026-07-03 migration deliberately relaxed it. Confirm or supersede |
| **2** | **What Unit do the existing NULL-unit products receive?** | No deterministic source exists. A value must be *chosen*, per product or by rule |
| **3** | **Scope of enforcement** | create only / create+update / imports / API / factories |
| **4** | **Enforcement level** | application validation vs DB `NOT NULL` — and what happens to production's existing NULL row |
| **5** | **Schema drift + no-op migration** | inverted guard in `2026_07_03_000001`; `ecos_dev_test` is NOT NULL while `ecos_dev`/`ecos_erp` are NULLABLE |
| — | *(carried, out of scope)* Recipe/BOM change reservation release; warehouse override after reservation | untouched |

## 19. Final implementation status

> ### D-1: PRESERVED · D-2: PRESERVED · **D-5: BLOCKED**
>
> **Exact decision required:** whether the mandatory-Unit contract supersedes the committed
> migration that made `unit_id` nullable — and, if it does, which Unit the existing NULL-unit
> products receive (6/18 dev, 1/3 production), since **no deterministic source exists** to recover it.
>
> **Why it blocks:** every remaining implementation step (create rule, update rule, backfill,
> enforcement level) depends on that answer, and the task forbids inventing it. A secondary
> blocker is real too: the test database enforces `NOT NULL` while dev and production do not, so
> the mandated tests cannot presently prove production behaviour.
>
> **Final certification: DEFERRED**, per project policy.

### Recommended follow-up once the decision is made

1. Repair the inverted guard in `2026_07_03_000001_make_unit_id_nullable_in_products` (or supersede it) so every environment converges on one intended state.
2. Backfill the NULL-unit products from the owner-supplied source.
3. Tighten `StoreProductRequest` / `UpdateProductRequest` to the decided scope.
4. Add the ten §13 test scenarios against the reconciled schema.

---

# CONTINUATION-001 â€” D-5 PRODUCT UNIT CONTRACT

**Date:** 2026-08-18 Â· **HEAD:** `abe4d10f` Â· **Authority:** owner-confirmed â€”
*EVERY PRODUCT MUST HAVE A UNIT*; a historical migration contradicting it does not redefine the rule.

> ## STATUS: **IMPLEMENTATION COMPLETE** (write-path enforcement)
> ## Legacy data remediation: **PENDING OWNER DECISION** â€” separate, by design
>
> **No Unit was guessed. No legacy product was modified.**

## 1. Already completed (previous batch â€” untouched)

A Inventory Reservation Â· B Preparation Wave Â· C Recipe/Disassembly Â· D-1 `preferredGovernorate`
Â· D-2 `lastOrderDate` â€” all left exactly as they were. `ReleaseOrderInventoryAction`, Wave
lifecycle and `DisassemblyWorkflow` were not opened.

## 2. Newly implemented

| # | Change | File |
|---|---|---|
| 1 | **Create enforcement** â€” `unit_id` from `sometimes,nullable` to **`required`** | `StoreProductRequest` |
| 2 | **Update enforcement** â€” to **`sometimes,required`** (unit may be CHANGED, never CLEARED; payloads omitting it still work) | `UpdateProductRequest` |
| 3 | **Schema convergence migration** (safe, data-preserving) | `2026_08_18_100000_converge_products_unit_id_nullability.php` *(new)* |
| 4 | **UI: the missing Unit field** â€” schema rule + default + `UnitSelect` wired into the create/edit drawer | `product-form-schema.ts`, `product-form.tsx` |
| 5 | **Focused tests** â€” 9 | `tests/Feature/Inventory/ProductUnitContractTest.php` *(new)* |

### 2.1 Root cause of the legacy NULLs â€” found

`product-form-schema.ts` had **no `unit_id` field at all**, so the Products workspace submitted no
unit and every UI-created product was stored NULL. WooCommerce-imported products all carry a unit
(the importer resolves one). That is why all six dev NULL rows are UI-created finished goods.

**This is why items 1 and 4 had to ship together:** tightening the backend alone would have made
every product creation in the workspace fail with 422.

## 3. Legacy unresolved data â€” audited, untouched

| Environment | NULL-unit products |
|---|---|
| `ecos_dev` | **6 / 18** â€” FG-000001 to FG-000006, all `finished_good`, all company **AxieFood** |
| `ecos_erp` | **1 / 3** â€” FG-000001 (`019faef5-af41-7321-9f6b-546045947ace`, the same product row) |

**No deterministic source exists** to recover their correct unit. Their Arabic names visibly
contain unit words (kilo / gram / "500 gram"), which makes the answer *look* obvious â€” and name
inference is **explicitly prohibited**, so it was not used. Recorded as
**LEGACY DATA REMEDIATION REQUIRED**, deliberately kept separate from write-path enforcement.

## 4. Schema status

| Environment | `products.unit_id` | `products.company_id` |
|---|---|---|
| `ecos_dev` | NULLABLE | **column absent** |
| `ecos_dev_test` | **NOT NULL** | **present, NOT NULL** |
| `ecos_erp` | NULLABLE | **column absent** |

The new migration converges this **without inventing data**: it applies NOT NULL only where zero
NULL rows exist; where legacy rows are present it leaves the column alone and logs
`PENDING LEGACY DATA REMEDIATION`. It never writes a unit, never deletes a product, never fails an
environment holding legacy data, and is safely re-runnable (state derived from live schema + data).
`down()` is an intentional no-op â€” relaxing the column again would re-open the drift.

## 5. Create Â· 6. Update Â· 7. API behaviour

- **Create** â€” `POST /api/products` rejects a missing, null or unknown `unit_id` (422).
- **Update** â€” `PUT /api/products/{id}` rejects `unit_id: null`; a unit may be replaced; a payload
  that omits the key is untouched. This is what keeps the legacy rows editable for other fields
  while remediation is pending.
- **API** â€” both routes come from `Route::apiResource('products', ...)` and pass through the same
  canonical FormRequests, so **no duplicate business logic was added**. `PATCH /products/{id}`
  (`PatchProductRequest`) accepts only `allow_negative_stock`, `is_active`, `manual_cost`,
  `regular_price`, `sale_price` â€” it has no `unit_id` and therefore **cannot clear a unit**. Left
  unchanged.

## 8. Import Â· 9. WooCommerce â€” audited, unchanged

`WooCommerceProductImporter` already sets `unit_id` to a resolved default on every imported product
and aborts the import when no unit exists at all. It therefore **cannot** create a unit-less
product, and needed no repair. Its `Unit::query()->first()` default remains an **import-only** rule
and was **not** reused for backfill â€” that strategy is explicitly forbidden.

## 10. UI behaviour

The create/edit drawer now requires a Unit, using the **existing** `UnitSelect` component and the
**existing** `products.form.unit.label` i18n key. No new picker, no client-side business logic, no
Product Workspace redesign. `products-view.tsx` uses `unit_id` only as a list **filter** â€” untouched.

## 11. Tenant behaviour

**Units are GLOBAL** â€” the `units` table and model carry no `company_id`. Per the instruction to
preserve existing architecture and not invent scope, **no company predicate was added** and no
cross-company rejection was implemented. `exists:units,id` is the complete integrity rule. Customer
tenant behaviour (`findById`, `SearchCustomerByPhone`, `CustomerAddressController`, Super Admin via
`CurrentCompanyService`) was not touched.

## 12. Tests â€” `ProductUnitContractTest`, **9 / 9 OK** (23 assertions)

create with valid unit clears validation Â· create without unit â†’ 422 Â· create with null unit â†’ 422
Â· create with unknown unit â†’ 422 Â· update may change the unit Â· **update may not clear the unit** â†’
422 Â· update omitting the key is accepted Â· existing valid product unaffected by an unrelated edit
Â· **a rejected create persists no product and invents no unit**.

No assertion was weakened, no test deleted, no unrelated baseline test modified.

## 13. Static verification

| Check | Scope | Result |
|---|---|---|
| `php -l` | 3 backend files | **PASS** |
| Pint | 4 backend files | **PASS** |
| **PHPStan** | 3 backend files | **PASS â€” no errors** |
| `tsc -p tsconfig.app.json` | app | **0 errors in changed files** (23 pre-existing elsewhere) |
| ESLint | 2 changed frontend files | **PASS â€” clean** |

No claim of global platform cleanliness; no unrelated baseline failure was fixed.

## 14. Runtime verification â€” and its limit

Enforcement is proven through the real HTTP surface (route â†’ FormRequest â†’ controller).

**Limit, stated plainly:** the happy-path *insert* could not be asserted as a 2xx, because
`ecos_dev_test` carries a `products.company_id` NOT NULL column that **does not exist** in
`ecos_dev` or `ecos_erp`, and the create path never populates it. That is a **pre-existing schema
drift unrelated to the Unit contract** (see Â§16 BUG-2). The affected test therefore asserts that a
valid unit *clears validation* rather than that the row is written â€” honest about what it proves.

A follow-up regression run (`ProductPolicyTogglePatchRouteTest`) could **not complete**: the shared
runner reported `[GATE] busy (an ungated phpunit process is running)` â€” another session. Queued,
not skipped.

## 15. Concurrent work left untouched

`ProductController`, `ProductResource`, `EloquentProductRepository`, `ProductAvailability` enum,
`product-column-defs.tsx`, `product-detail-drawer.tsx`, `product-quick-stats.tsx`,
`products-page.tsx`, `types/product.ts` â€” all another session's dirty work, **not modified**. The
staged `order-reservation-cell.tsx` is untouched. Nothing was staged, committed, reset or stashed.

## 16. Bugs found â€” recorded, NOT fixed

| # | Bug | Evidence | Scope |
|---|---|---|---|
| **1** | `2026_07_03_000001_make_unit_id_nullable_in_products` has an **inverted guard** â€” `up()` returns when the column exists, so its change never runs; `down()` identical | source | superseded in effect by the new convergence migration; the defective file itself was **left alone** (another task's artefact) |
| **2** | `products.company_id` exists **only** in `ecos_dev_test`; the create path never populates it, so `POST /api/products` cannot insert there | runtime 500 + `information_schema` | **out of D-5 scope** â€” pre-existing tenant/schema defect, previously flagged |

## 17. Remaining decision â€” ONE

> **Which Unit do the 6 dev / 1 production legacy products receive?**
>
> No deterministic source exists; every inference route is prohibited. Once supplied, the
> remediation is mechanical: backfill, and the new migration then applies NOT NULL automatically on
> its next run, closing the drift.

Everything else in D-5 is implemented. Out-of-scope gaps (Recipe/BOM reservation release,
`WarehouseAssignmentEngine::override()`) were **not** touched.

## 18. Final implementation status

> ### D-1 PRESERVED Â· D-2 PRESERVED Â· **D-5 IMPLEMENTATION COMPLETE (write-path)**
> ### Legacy data remediation: PENDING OWNER DECISION Â· Final certification: **DEFERRED**


---

# D-5 LEGACY DEMO UNIT REMEDIATION

**Date:** 2026-08-18 Â· **Authority:** owner decision â€” *the legacy NULL-unit products are demo data,
not real business data; any already-existing Unit is acceptable, chosen deterministically.*

> ## STATUS: **D-5 IMPLEMENTATION COMPLETE**
> ## NULL-unit products remaining: **0** in every environment Â· `products.unit_id` = **NOT NULL**
> ## Final certification: **DEFERRED** (project policy)

## 1. Counts before remediation â€” re-verified, not taken from the earlier report

| Environment | NULL-unit products | Units available |
|---|---|---|
| `ecos_dev` | **6** | 5 |
| `ecos_erp` | **1** | 5 |
| `ecos_dev_test` | 0 (transactional suite DB) | 0 |

The previous report's figures were re-confirmed against the live environments before anything was
written, as instructed.

## 2. Affected product IDs â€” recorded before the change

**`ecos_dev` (6):**

| id | sku | type |
|---|---|---|
| `019faef5-af41-7321-9f6b-546045947ace` | FG-000001 | finished_good |
| `01a00109-8bec-7016-ae82-9f0cd72fdf02` | FG-000002 | finished_good |
| `01a0010a-1341-70bb-af23-6660a75794c0` | FG-000003 | finished_good |
| `01a0010a-712b-737b-998a-3a8342917ae6` | FG-000004 | finished_good |
| `01a0010a-d563-73ca-8a97-9957ad00d524` | FG-000005 | finished_good |
| `01a0010b-3e71-71b2-aa80-efbd4853899f` | FG-000006 | finished_good |

**`ecos_erp` (1):** `019faef5-af41-7321-9f6b-546045947ace` â€” FG-000001 (the same product row as in dev).

## 3. Selected Unit and why

| | |
|---|---|
| **Unit ID** | `019f4e1c-2d91-71c9-927a-da5011068a3f` |
| **Code / Name** | **BOX** â€” "Box" (symbol `box`), `is_active = 1` |
| **Selection rule** | **lowest `code` ascending** among existing, non-deleted units |

Both databases hold the **identical five seeded units with identical IDs**
(BOX, KG, LTR, MTR, PCS), so one rule selects the same unit in both â€” no per-environment divergence.

`code` is unique and stable, which makes the ordering fully deterministic and reproducible; `id`
was deliberately not used as the sort key because the task warned against assuming any id sequence
(they are UUIDs).

**No new Unit was created, none deleted, no definition changed, and no unit outside the database
was used.**

Worth stating explicitly: the rule selects **BOX**, *not* KG â€” even though the products' Arabic
names contain ÙƒÙŠÙ„Ùˆ / Ø¬Ø±Ø§Ù…. That is the point. The selection is driven purely by the deterministic
ordering rule, so it demonstrably carries **no name-based inference**, which remains prohibited.

## 4. Counts after remediation

| Environment | before | rows updated | after (NULL) | products with a unit |
|---|---|---|---|---|
| `ecos_dev` | 6 NULL / 12 with unit | **6** | **0** | **18 / 18** |
| `ecos_erp` | 1 NULL / 2 with unit | **1** | **0** | **3 / 3** |

Each backfill ran inside an explicit transaction, restricted to
`WHERE unit_id IS NULL AND deleted_at IS NULL`. `ROW_COUNT()` matched the pre-counted number
exactly in both environments (6 and 1), proving **no product that already had a Unit was touched**.

## 5. Migration result

`2026_08_18_100000_converge_products_unit_id_nullability.php`

| Environment | Result |
|---|---|
| `ecos-dev-app` â†’ `ecos_dev` | **DONE** (3s) |
| `ecos-app` â†’ `ecos_erp` | **DONE** (1s) |

Both runs were scoped with `--path=` to this single migration, so **no unrelated pending migration
was applied** (see Â§11 for the one that ran before that precaution was added).

### 5.1 Defect found in my own migration â€” fixed

The first run failed with `Undefined property: stdClass::$is_nullable`. MySQL returns
`information_schema` column names in varying case depending on server configuration, so reading
`$row->is_nullable` was unsafe. Repaired by aliasing the column and normalising the row:

```php
$row = DB::selectOne('SELECT is_nullable AS nullable_flag FROM information_schema.columns â€¦');
$values = array_change_key_case((array) $row, CASE_LOWER);
return strtoupper((string) ($values['nullable_flag'] ?? '')) === 'NO';
```

The failure was a genuine defect in this task's own code and is recorded rather than glossed over.
Because the migration failed, it was **not** marked as run, so the corrected version applied cleanly.

## 6. NOT NULL + FK verification

| Environment | `products.unit_id` | NULL rows | Foreign key |
|---|---|---|---|
| `ecos_dev` | **`is_nullable = NO`** | **0** | `products_unit_id_foreign â†’ units.id` âœ… |
| `ecos_erp` | **`is_nullable = NO`** | **0** | `products_unit_id_foreign â†’ units.id` âœ… |

The FK survived the `->change()` in both environments â€” verified from
`information_schema.key_column_usage`, not assumed.

## 7. Contract behaviour â€” unchanged by this remediation

Create still requires a Unit; Update still cannot clear one (`sometimes,required`); the UI still
collects a Unit through the existing `UnitSelect`. Those were implemented in CONTINUATION-001 and
were **not** modified here â€” the remediation only removed the legacy data that blocked the DB
constraint.

## 8. Tests

`ProductUnitContractTest` â€” **9 / 9 OK (23 assertions)**, established in CONTINUATION-001 and
unchanged by this work. **No assertion was modified.**

**Re-run after remediation: NOT COMPLETED.** The shared runner was held throughout by another
session's ungated phpunit process; two queued attempts returned
`[GATE] BUSY â€” Nothing was run; the schema was not touched` (exit 70). The suite is unaffected by
the remediation in principle â€” it runs against `ecos_dev_test`, which had **0** NULL-unit products
and was not part of the backfill â€” but the re-run is recorded as **PENDING RUNNER AVAILABILITY**
rather than claimed.

## 9. Static checks

| Check | Scope | Result |
|---|---|---|
| `php -l` | corrected migration | **PASS** |
| Pint | corrected migration | **PASS** |
| **PHPStan** | corrected migration | **PASS â€” no errors** |

(CONTINUATION-001's checks on the request classes and frontend remain valid; those files were not
touched here.)

## 10. Files changed by this remediation

| File | Change |
|---|---|
| `â€¦/Migrations/2026_08_18_100000_converge_products_unit_id_nullability.php` | case-safe `information_schema` read (Â§5.1) |
| â€” | **Data only:** 6 rows in `ecos_dev`, 1 row in `ecos_erp` |

No application code, no controller, no importer, no request class, no UI file was modified.

## 11. Files and systems deliberately untouched

`ProductController` Â· `ProductResource` Â· `EloquentProductRepository` Â· `ProductAvailability` enum Â·
`product-column-defs.tsx` Â· `product-detail-drawer.tsx` Â· `products-page.tsx` Â· `types/product.ts`
(all another session's work) Â· `WooCommerceProductImporter` Â· the defective
`2026_07_03_000001_make_unit_id_nullable_in_products` (another task's artefact) Â· the staged
`order-reservation-cell.tsx`. Nothing was staged, committed, reset or stashed.

**One disclosure:** the first migration attempt used a bare `php artisan migrate`, which also
applied one unrelated pending migration already present in the dev container â€”
`2026_08_14_100000_create_recipe_cost_snapshots` (another session's, completed successfully). Every
subsequent run was scoped with `--path=` so that could not recur. Production (`ecos-app`) only ever
received the scoped run, so **no unrelated migration was applied there**.

## 12. Pre-existing `company_id` issue â€” untouched

`products.company_id` exists only in `ecos_dev_test`, and the create path does not populate it.
**Classification: PRE-EXISTING / OUT OF SCOPE.** Not fixed, not expanded upon. It did not block this
remediation; it only limits one assertion style in `ProductUnitContractTest`, as already recorded.

## 13. D-1 / D-2

Not reopened, not redesigned. Runtime verification of
`CustomerPreferredGovernorateTest` / `CustomerLastOrderContractTest` remains
**PENDING RUNTIME VERIFICATION** â€” same shared-runner contention.

## 14. Final D-5 implementation status

> ### **D-5 â€” IMPLEMENTATION COMPLETE**
>
> - Legacy demo products remediated: **7 total** (6 dev + 1 production)
> - Selected Unit: **BOX** `019f4e1c-2d91-71c9-927a-da5011068a3f`, chosen by lowest `code`
> - NULL-unit products remaining: **0** everywhere
> - `products.unit_id`: **NOT NULL** in `ecos_dev` and `ecos_erp`, FK intact
> - Create requires a Unit Â· Update cannot clear a Unit Â· UI collects a Unit
> - No Unit invented, none created, no product with an existing Unit altered
>
> **Final certification: DEFERRED** per project policy.

