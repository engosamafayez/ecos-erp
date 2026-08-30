# TASK-PROCUREMENT-PURCHASE-MATERIAL-MYSQL-CAST-REPAIR-001 — Engineering Report

**Date:** 2026-08-17 · **Branch:** `develop` · **Runtime:** MySQL **8.4.10** / PHP 8.4.24 / PHPUnit 11.5.55
**Scope:** SQL-compatibility repair only. One keyword, one line.

**Status: IMPLEMENTATION COMPLETE — FINAL CERTIFICATION DEFERRED**

---

## 1. Existing defect

`EloquentPurchaseMaterialRepository::nextRequestNumber()` ordered by a **PostgreSQL-only** cast:

```php
->orderByRaw("CAST(REPLACE(request_number, 'PM-', '') AS BIGINT) DESC")
```

`BIGINT` is not a valid cast target in MySQL — MySQL accepts `SIGNED` / `UNSIGNED`. The file was
**committed and clean** at task start (last touched by `8ef069f7`), so the defect was live, not a
dirty-tree artifact.

The location was found by inspection, not from the prior report's line number: the expression sits
at **line 123**, inside `nextRequestNumber()`.

---

## 2. Exact runtime reproduction

Executed against the real `ecos-dev-mysql` (**MySQL 8.4.10**), not SQLite, not PostgreSQL.

| Probe | Result |
|---|---|
| `SELECT request_number FROM purchase_materials ORDER BY CAST(REPLACE(request_number,'PM-','') AS BIGINT) DESC LIMIT 1` | **rc=1 — ERROR 1064 (42000)** … *near `'BIGINT) DESC LIMIT 1'`* |
| Same query with `AS UNSIGNED` | **rc=0 — success** |
| `SELECT CAST('00042' AS BIGINT)` — no table at all | **rc=1 — ERROR 1064 (42000)** |
| `SELECT CAST('00042' AS UNSIGNED)` | **rc=0 → `42`** |
| `SELECT COUNT(*) FROM purchase_materials` | **0 rows** |

- **Endpoint/action:** `POST /api/purchase-materials` (`routes/api.php:613`, middleware
  `permission:purchasing.materials.create`) → `PurchaseMaterialController::store()` →
  `CreatePurchaseMaterialAction::execute()` (line 23) → `nextRequestNumber()`.
- **MySQL error code:** **1064**, SQLSTATE **42000** (parse error).
- **Severity:** the third probe proves the failure is **parse-time and data-independent**. The table
  holds **zero rows**, and it still fails. So Purchase Material creation was broken **100% of the
  time, including the very first request** — not an edge case that appears once numbering grows.

---

## 3. Root cause

A PostgreSQL cast target compiled into a raw `orderByRaw` fragment. MySQL parses the whole statement
before execution, so the error precedes any row access — which is why an empty table does not mask it.

The cast is **not decorative**: it exists because lexicographic ordering is wrong for this data.
Proven on MySQL:

| Ordering | Result over `PM-00009, PM-00010, PM-99999, PM-100000` |
|---|---|
| Numeric (with cast) | `PM-100000, PM-99999, PM-00010, PM-00009` ✅ |
| Lexicographic (no cast) | `PM-99999, PM-100000, PM-00010, PM-00009` ❌ |

So "just drop the cast" would execute cleanly and then silently reissue an existing number at every
digit-width boundary, colliding with the unique index. The cast had to be **translated**, not removed.

---

## 4. Existing canonical pattern inspected

Two structurally identical siblings already use the MySQL-correct form — same method shape, same
`withTrashed()`, same PHP-side parse, same `str_pad`:

| Repository | Expression |
|---|---|
| `EloquentPurchaseOrderRepository::nextPoNumber()` :100 | `CAST(REPLACE(po_number, 'PO-', '') AS UNSIGNED) DESC` |
| `EloquentGoodsReceiptRepository` :116 | `CAST(REPLACE(receipt_number, 'GR-', '') AS UNSIGNED) DESC` |

`AS UNSIGNED` is therefore the established Purchasing convention; the repair aligns to it rather than
inventing a third approach.

**An exhaustive sweep of the PurchaseMaterials module (36 PHP files) found exactly ONE hazard — this
line.** The only other file carrying raw SQL (`GetPurchaseMaterialStatsAction`) is fully portable
(`COALESCE`/`SUM`/`CASE WHEN`/`COUNT`). The repair is genuinely one line.

Two nearby hits were checked and are **not** defects: `CAST(NULL AS CHAR)` in `GetSupplierTimelineQuery`
(`CHAR` is a valid MySQL target) and `CAST(... AS INTEGER)` in a CostManagement migration (inside a
`match($driver)` arm keyed `'sqlite'`).

---

## 5. Minimal repair

One keyword on one line:

```diff
-            ->orderByRaw("CAST(REPLACE(request_number, 'PM-', '') AS BIGINT) DESC")
+            ->orderByRaw("CAST(REPLACE(request_number, 'PM-', '') AS UNSIGNED) DESC")
```

Nothing else changed. No redesign of number generation, no schema change, no migration, no change to
sequence semantics, ownership, supplier logic, demand, health, timeline, Goods Inward, Supplier
Return, Inventory or FIFO.

### A challenged alternative, checked and rejected on evidence

The investigation flagged a project precedent — `backend/verify_medium_low.php:823-833` — which asserts
a numbering method must use `lockForUpdate()` + `orderByDesc` and **must NOT contain `AS UNSIGNED`**,
and argued that made this repair unsanctioned. I verified it directly:

- **Line 824 hardcodes its target:** `file_get_contents(__DIR__.'/Modules/Inventory/CountSessions/Application/Actions/CreateCountSessionAction.php')`.
  The assertion is scoped to that **single Inventory file** and never reads the Purchasing repository.
- It is the **only** `AS UNSIGNED` prohibition in the repo, and **no** verification script anywhere
  references `EloquentPurchaseMaterialRepository` or `nextRequestNumber`.

So this change trips no gate. The precedent's `orderByDesc` approach also relies on "zero-padded to 5
digits ⇒ lexicographic equals numeric", which **§3 disproves the moment numbering reaches six digits**.
Adopting it here would have introduced the duplicate-number bug this repair exists to avoid. Rejected
on evidence, not preference.

---

## 6. Value / precision preservation

The cast is used **only as an ORDER BY sort key — its result is never SELECTed**. `->value('request_number')`
returns the raw string, and the sole numeric extraction happens in PHP at line 130
(`(int) str_replace('PM-', '', ...)`), entirely independent of the SQL cast target.

Equivalence proven on MySQL across the full domain (11 values, incl. `PM-1000000`):

| | Result |
|---|---|
| `CAST(x AS UNSIGNED)` vs `CAST(x AS SIGNED)` | **identical for every value** (`identical = 1` on all rows) |
| Descending order | strictly numeric, correct at every boundary |
| Leading zeros | stripped identically (`'00042'` → `42`) |

Because request numbers are non-negative by construction, `UNSIGNED` and a signed 64-bit `BIGINT` order
identically. **Returned value, precision, ordering and uniqueness are unchanged; existing generated
numbers are untouched** (no data was read, written or migrated).

---

## 7. Number-generation preservation

Verified end-to-end through the repository against MySQL, inside a transaction that was **rolled back**
(final row count `0` — no pollution):

| Existing state | Next number | Expected | |
|---|---|---|---|
| empty table | `PM-00001` | `PM-00001` | ✅ |
| `PM-00009` | `PM-00010` | `PM-00010` | ✅ |
| `PM-00009, PM-00010` | `PM-00011` | `PM-00011` | ✅ lexicographic would wrongly yield `PM-00010` |
| `… PM-99999` | `PM-100000` | `PM-100000` | ✅ 5→6 digit rollover |
| `… PM-100000` | `PM-100001` | `PM-100001` | ✅ lexicographic would wrongly yield `PM-100000` |
| `PM-100000` soft-deleted | `PM-100001` | `PM-100001` | ✅ `withTrashed()` preserved |

Runtime confirmation of the actual generated SQL and PHP type:

```
RETURNED: 'PM-00001'   PHP TYPE: string   DRIVER: mysql / ecos_dev
SQL: select `request_number` from `purchase_materials`
     order by CAST(REPLACE(request_number, 'PM-', '') AS UNSIGNED) DESC limit 1
```

`withTrashed()`, ordering, `str_pad` to 5 digits and the `'PM-'` prefix are all untouched.

---

## 8. Tenant behaviour — documented, deliberately unchanged

Purchase Material numbering is **GLOBAL across tenants**, and that is the schema-enforced contract:

- The unique index is **`purchase_materials_request_number_unique` on `request_number` ALONE** — not
  `(company_id, request_number)`. A per-company sequence would therefore **collide** across tenants.
- `PurchaseMaterial` extends `Model` directly with only `HasFactory, HasUuids, SoftDeletes`; it has no
  `booted()`, no `addGlobalScope()`, and no company-scoping trait. (`addGlobalScope` appears in
  `Modules/Purchasing` only on `GoodsReceipt`, `SupplierInvoice` and `Supplier`.)
- `withTrashed()` strips **only** `SoftDeletingScope` — it would not remove a tenant scope if one existed.
- **Empirical proof:** the captured runtime SQL (§7) has **no `WHERE` clause at all**.

`company_id` exists but is nullable with a plain non-unique index. The query touches no tenant data and
the repair changes no filter, so **the tenant contract is untouched**. It is now pinned by a test so a
future change cannot silently narrow the scan and start issuing duplicates.

---

## 9. Targeted tests

**No existing Purchase Materials test existed to run.** Confirmed independently: no
`backend/tests/**/*PurchaseMaterial*` file; `backend/tests/Feature/Purchasing/` holds 9 files, none for
PurchaseMaterials; the single textual hit repo-wide is `FinancialIntegrationTest:177`, an unrelated
`BusinessEventType::PurchaseMaterials` Finance enum that never loads this repository. Nothing exercised
`nextRequestNumber()` — which is precisely why a 100%-fatal defect shipped.

Added **`backend/tests/Feature/Purchasing/PurchaseMaterialNumberGenerationTest.php`** (new file; no
existing test was modified).

```
[GATE] acquired ecos:testrunner:ecos_dev_test (connection 1715)
PHPUnit 11.5.55 · PHP 8.4.24
..........                                       10 / 10 (100%)
OK (10 tests, 19 assertions)      Time: 07:20.135
[GATE] released ecos:testrunner:ecos_dev_test
```

Run under the T-6 advisory-lock gate, scoped to the single file — **not** the Purchasing, Orders or
Inventory suites.

| PART 8 requirement | Test |
|---|---|
| Creation path executes on MySQL 8.4 | `test_next_request_number_executes_on_mysql_and_seeds_the_first_number` |
| Number generation succeeds | same, plus `test_generated_sql_uses_a_mysql_compatible_cast` |
| Expected type/value | `assertIsString` + exact value assertions |
| Numbering semantics unchanged | `test_ordering_is_numeric_not_lexicographic`, `test_rollover_past_five_digits_keeps_counting`, `test_zero_padding_is_preserved_at_five_digits`, `test_soft_deleted_numbers_are_not_reissued` |
| Two consecutive creations differ | `test_two_consecutive_creations_do_not_produce_the_same_number` (`PM-00001` then `PM-00002`) |
| Tenant isolation | `test_numbering_is_global_across_companies_and_never_collides` |

**False-green guard.** `test_suite_runs_against_mysql_so_the_cast_is_actually_exercised` asserts the
driver is `mysql`. On SQLite `CAST(x AS BIGINT)` is perfectly legal, so without this the whole file
would pass while production stayed broken. (The suite does force MySQL — `phpunit.xml` pins
`DB_CONNECTION=mysql` / `DB_DATABASE=ecos_dev_test` — but the guard makes that a tested precondition
rather than an assumption.)

Fixtures seed rows **directly**, not through `nextRequestNumber()`, so the assertions cannot be circular.

**On the certified guard:** `ProcurementQueryRuntimeRepairTest::test_the_repaired_queries_contain_no_postgres_only_cast()`
belongs to the certified MySQL-compatibility task and missed this defect on two axes — its file list
covers only 3 Suppliers/DemandAnalysis files, and it checks only `::float`. Per PART 13 that certified
test was **not modified**; the equivalent guard lives in the new file instead.

---

## 10. Static checks

Affected files only.

| Check | Scope | Result |
|---|---|---|
| `php -l` | changed repository + new test | **PASS** — no syntax errors |
| Pint | changed repository | **PASS** — 1 file |
| PHPStan L0 | `Modules/Purchasing/PurchaseMaterials` | **`[OK] No errors`** |

No unrelated baseline errors were touched.

---

## 11. Deployment

Target: the **`ecos-dev-*`** stack (`C:\ecos-develop`), source baked into the image, so `docker cp` is required.

- `EloquentPurchaseMaterialRepository.php` → **`ecos-dev-app`** and **`ecos-dev-testrunner`**
- `PurchaseMaterialNumberGenerationTest.php` → **`ecos-dev-testrunner`** only (a test does not belong in the app container)
- `config:clear` on `ecos-dev-app`; opcache needed no action (`validate_timestamps=1`, `revalidate_freq=0`)

**No migration was created or applied. None is required** — this is SQL-expression compatibility only;
no schema object changed. No `migrate`, `migrate:fresh`, drop or reset was run. No unrelated file from
the ~400-file dirty tree was deployed; nothing from Orders, Inventory, Preparation, Loading, Vehicle,
Driver, Delivery or Settlement was touched.

**Deployment smoke:** `route:list --path=purchase-materials` resolves; `GET /api/purchase-materials` → **401**
(auth-gated, **no 500**) through `ecos-dev-nginx:8081`.

*A first `docker cp` attempt silently failed — a persisted `cd backend` made the relative source resolve
to `backend/backend`, and writing the source as an MSYS path (`/c/ecos-develop/...`) under
`MSYS_NO_PATHCONV=1` made Docker read it as `C:\c`. Caught because deployment was verified by
**content**, not just by hashes. Resolved with a repo-root-relative source path.*

---

## 12. Host / runner / app parity

`MSYS_NO_PATHCONV=1` used on every container path so Git Bash could not rewrite `/var/www/...` and
produce a false result.

| File | HOST | RUNNER | APP | |
|---|---|---|---|---|
| `EloquentPurchaseMaterialRepository.php` | `0bea408ecf5a9399` | `0bea408ecf5a9399` | `0bea408ecf5a9399` | **MATCH** |
| `PurchaseMaterialNumberGenerationTest.php` | `d7d268eb0d18e7dc` | `d7d268eb0d18e7dc` | *n/a by design* | **MATCH** |

Deployed content verified literally: line 123 reads `AS UNSIGNED` in both containers, and
`grep -rn "AS BIGINT" /var/www/html/Modules/Purchasing/` returns **nothing**.

---

## 13. Unrelated findings — recorded, NOT fixed

| # | Finding | Disposition |
|---|---|---|
| U-1 | **`nextRequestNumber()` has no `lockForUpdate()` and no transaction** — read-then-write, so concurrent creates can generate the same number and collide with the unique index (500 / SQLSTATE 23000). | **OUT OF SCOPE** per PART 7 — pre-existing concurrency defect, not introduced or worsened here. The repair adds no lock and removes none. The identical exposure exists in the `PurchaseOrder` and `GoodsReceipt` siblings. |
| U-2 | `CreatePurchaseMaterialAction` never writes `record_type` / `source_type` although both are validated, carried on the DTO and fillable; `PurchaseMaterialResource:19` masks the resulting NULL with `?? 'material_request'`. | **OUT OF SCOPE** — pre-existing data-quality defect on the same path. |
| U-3 | **~62 PostgreSQL-only expressions across 26 files outside Purchasing** — `::text` (MarketingKpiEngine), `DATE_TRUNC`, `EXTRACT(EPOCH FROM …)`, `TO_CHAR`, 41 `'ilike'` operators across 19 files, a Postgres JSON `->>` operator, and a raw `ON CONFLICT … EXCLUDED` statement. | **OUT OF SCOPE** — recorded only. Same defect class, different modules; each needs its own targeted task. |
| U-4 | `verify_medium_low.php` L3-01 mandates `orderByDesc` for `CreateCountSessionAction`, which is only safe while `CNT-` numbers stay at 5 digits — the same boundary bug §3 demonstrates. | **OUT OF SCOPE** — different module (Inventory CountSessions). |
| U-5 | The certified `ProcurementQueryRuntimeRepairTest` cast guard covers 3 files and only `::float`, so it cannot catch this defect class generally. | **OUT OF SCOPE** — certified work, not reopened (PART 13). |

Nothing in PART 14 (D-INB-03, Goods Inward browser smoke, TypeScript baseline, Procurement domain
certification) was touched.

### Process note

Two investigation lenses reported that "a concurrent agent" modified line 123 mid-run. That was **this
session's own edit** — the read-only investigators observed my `BIGINT → UNSIGNED` change land while
they were reading. No third-party agent altered the file, and no double-edit occurred: `git status`
shows exactly one modified file and one new test, both mine.

---

## 14. Final implementation status

**IMPLEMENTATION COMPLETE — FINAL CERTIFICATION DEFERRED**

- Defect reproduced on the real MySQL 8.4.10 runtime: **ERROR 1064 / SQLSTATE 42000**, proven
  parse-time and data-independent (fails on an empty table), so creation was broken 100% of the time
- Repaired with the smallest correct change — one keyword — matching the existing Purchasing convention
- Value, precision, ordering, padding, `withTrashed()` and uniqueness proven unchanged
- Tenant contract documented and provably untouched (no `WHERE` clause; globally unique index)
- 10/10 targeted tests green on MySQL under the T-6 gate, with a SQLite false-green guard
- Static: `php -l` PASS, Pint PASS, PHPStan L0 no errors
- Deployed to `ecos-dev-app` + `ecos-dev-testrunner`; HOST == RUNNER == APP
- No migration created or applied; no database reset; no unrelated file deployed

Final system-wide review and certification remain deferred until all remaining ECOS ERP modifications
are complete. No further Procurement work was started.
