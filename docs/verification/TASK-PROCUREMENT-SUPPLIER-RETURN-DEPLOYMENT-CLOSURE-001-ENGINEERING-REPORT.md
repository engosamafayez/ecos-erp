# TASK-PROCUREMENT-SUPPLIER-RETURN-DEPLOYMENT-CLOSURE-001 — Engineering Report

**Date:** 2026-08-17 · **Branch:** `develop` · **HEAD:** `ec43b470`
**Scope:** deployment closure only. No business logic was written, redesigned or reopened.

**Outcome: SUPPLIER RETURN DEPLOYMENT = COMPLETE**
**FINAL SYSTEM CERTIFICATION = DEFERRED** (project is in the modification phase).

---

## 1. Certified source identified

Authoritative source: `TASK-SUPPLIER-RETURN-VALUATION-001` (CERTIFIED, 2026-08-15), per
`docs/verification/TASK-SUPPLIER-RETURN-VALUATION-001-ENGINEERING-REPORT.md` §2.

The certified changeset is **uncommitted working-tree state** — there is no commit for it.
`git log --all --grep="SUPPLIER-RETURN"` returns nothing relevant; HEAD `ec43b470` is the
unrelated reservation-chain commit. The changeset was therefore taken from the certified
report's own §2 "Change surface", not guessed from filenames.

**8 files (7 production + 1 test), 1 deletion:**

| Kind | File |
|---|---|
| New | `backend/Modules/Purchasing/SupplierReturns/Application/Actions/ApproveSupplierReturnAction.php` |
| New | `backend/Modules/Purchasing/SupplierReturns/Domain/Services/ReturnableQuantityService.php` |
| New | `backend/Modules/Purchasing/SupplierReturns/Domain/Exceptions/SupplierReturnValidationException.php` |
| New (test) | `backend/tests/Feature/Purchasing/SupplierReturnValuationTest.php` |
| Modified | `backend/Modules/Inventory/ReceiptLayers/Application/Services/InventoryLayerConsumptionService.php` |
| Modified | `backend/Modules/Purchasing/SupplierReturns/Presentation/Http/Controllers/SupplierReturnController.php` |
| Modified | `backend/Modules/Purchasing/SupplierReturns/Presentation/Http/Requests/StoreSupplierReturnRequest.php` |
| Modified | `backend/Modules/Purchasing/SupplierReturns/Presentation/Http/Resources/SupplierReturnResource.php` |
| Modified (FE) | `frontend/src/features/supplier-returns/types/supplier-return.ts` |
| **Deleted** | `backend/Modules/Purchasing/SupplierReturns/Application/Actions/ReverseSupplierReturnInventoryAction.php` |

### Excluded — dirty but not this task's

The working tree carries **400 dirty files**. Everything below sits in adjacent Purchasing
paths and was deliberately **not** deployed, per the certified report §7 which names them as
another agent's concurrent work:

`GetSupplierProductDemandQuery.php` · `GetProcurementHealthQuery.php` ·
`GetSupplierPriceHistoryQuery.php` · `SupplierAnalyticsController.php` ·
`SupplierProductDemandTest.php` · `PostSupplierInvoiceService.php` · `SupplierInvoice.php` ·
`SupplierInvoiceController.php` · `ReturnToPendingWorkflow.php` (Operations) ·
`CreateReceiptLayersAction.php` (ReceiptLayers — modified, but **not** in the certified change
surface) · all `frontend/src/features/suppliers/**` and `i18n/locales/**/suppliers.json`.

Nothing from Orders, Inventory, Manufacturing, Preparation, Distribution, Loading, Vehicle,
Driver, Delivery or Settlement was touched.

---

## 2. Target environment

The dev application is the **`ecos-dev-*`** stack, all containers labelled
`com.docker.compose.project.working_dir = C:\ecos-develop` — i.e. this worktree.

| Container | Role | Notes |
|---|---|---|
| `ecos-dev-nginx` | web edge | `127.0.0.1:8081` → app |
| `ecos-dev-app` | PHP-FPM, `APP_ENV=production`, `APP_URL=http://localhost:8081`, `DB_DATABASE=ecos_dev` | **deployment target** |
| `ecos-dev-testrunner` | PHPUnit | `ecos_dev_test` |
| `ecos-dev-mysql` | MySQL 8.4 | `127.0.0.1:3316` |

**Source is baked into the image — the only code mount is the `storage` volume.** No bind
mount for `Modules/`, so `docker cp` is mandatory (consistent with the standing project note).
The separate `ecos-*` stack (ports 80/443, DB `ecos_erp`) is **not** the dev application and
was left untouched.

---

## 3. Existing deployment state (before any change)

`ecos-dev-app` was serving **fully stale, pre-certification code**:

| File | RUNNER | APP (before) |
|---|---|---|
| `ApproveSupplierReturnAction.php` | present | **MISSING** |
| `ReturnableQuantityService.php` | present | **MISSING** |
| `SupplierReturnValidationException.php` | present | **MISSING** |
| `InventoryLayerConsumptionService.php` | `7edb4e1d…` | `8e995325…` **stale** |
| `SupplierReturnController.php` | `a78625f8…` | `8731ab89…` **stale** |
| `StoreSupplierReturnRequest.php` | `63821400…` | `8eb4c6c9…` **stale** |
| `SupplierReturnResource.php` | `4d7e7a7a…` | `4cd656eb…` **stale** |
| `ReverseSupplierReturnInventoryAction.php` (deleted by the task) | **still present** | **still present** |

`ecos-dev-testrunner` was already at exact parity with HOST on all 8 certified files — the
certifying run had been executed there. Its only deviation was the undeleted superseded action.

**Conclusion: the certified work had never reached the running dev application.** This task's
existence was justified.

---

## 4. Files deployed

7 production files `docker cp`-ed HOST → `ecos-dev-app` (the test file was not copied to the
app container — it belongs to the runner, which already had it):

- created `Domain/Services/` and `Domain/Exceptions/` (did not exist in the image)
- copied the 3 new files + 4 modified files
- `chmod 755` to match surrounding image ownership (`root:root`, 755)
- **removed** `ReverseSupplierReturnInventoryAction.php` from `ecos-dev-app` **and**
  `ecos-dev-testrunner`, completing the certified deletion

Removal was gated on proof, not assumption: `ReverseSupplierReturnInventoryAction` has **zero
references** anywhere in the host tree (`backend/**` excluding vendor) and zero in the deployed
tree (`Modules/`, `app/`, `routes/`, `config/`, `bootstrap/`).

**Framework caches cleared** on `ecos-dev-app`: `config:clear`, `route:clear`, `cache:clear`.

**Opcache required no action** — the image runs `opcache.validate_timestamps=1` with
`revalidate_freq=0`, so it revalidates every request and picks the new files up immediately.
Verified from the live `php -i`, not assumed.

**Autoloader required no action** — `composer.json` maps `Modules\ => Modules/` via PSR-4 and
the vendor autoloader is **not** classmap-authoritative (`setClassMapAuthoritative(true)` absent),
so the three new classes resolve through the PSR-4 fallback. Confirmed by live `class_exists()`.

### Frontend: nothing to deploy — proven, not assumed

The single certified frontend change makes `SupplierReturnLinePayload.goods_receipt_line_id`
required instead of optional. `supplier-return.ts` contains **10 `export type` declarations and
zero runtime exports** (no `const`/`function`/`class`/`enum`/`default`). It is entirely erased
at compile time and contributes **no bundle artifact**, so no frontend build or copy is required
and no served asset can be stale with respect to it. Its only effect is compile-time — a future
create form fails `tsc` rather than 422-ing at runtime, which is exactly the certified intent.

---

## 5. Migration status

**No migration is required. Proven three ways:**

1. The certified report §2 states plainly: *"No migration was written. No schema was changed."*
2. `git status` shows **zero** dirty or untracked migration files anywhere in
   `backend/database/migrations` or any module `Database/Migrations`.
3. Every schema object the certified implementation depends on already exists in `ecos_dev`:

| Table | Column | Nullable | Type |
|---|---|---|---|
| `supplier_returns` | `warehouse_id` | **NO** | `char(36)` — the ceiling scopes through it |
| `supplier_returns` | `company_id` | YES | `char(36)` — NULL-tolerant by design |
| `supplier_returns` | `inventory_restocked` | **NO** | `tinyint(1)` — idempotency + cancellation discriminator |
| `supplier_returns` | `inventory_restocked_at` | YES | `timestamp` |
| `supplier_return_lines` | `goods_receipt_line_id` | YES | `char(36)` — the SR-2 anchor |
| `supplier_return_lines` | `original_received_qty` / `original_unit_cost` | YES | `decimal(18,4)` |
| `goods_receipt_lines` | `net_received_quantity` | YES | `decimal(15,4)` — net-vs-gross ceiling |
| `goods_receipt_lines` | `received_quantity` | **NO** | `decimal(15,4)` |
| `inventory_receipt_layers` | `goods_receipt_line_id` | YES | `char(36)` — receipt-scoped FIFO |
| `inventory_receipt_layers` | `supplier_id` / `remaining_qty` | YES / **NO** | — |

**One unrelated migration is pending and was deliberately NOT applied:**
`2026_08_14_100000_create_recipe_cost_snapshots`. It belongs to the recipe/cost work, not to
Supplier Return. Per scope, another agent's migration was not touched. No `migrate`,
`migrate:fresh`, drop or reset was run against any database.

---

## 6. Deployment parity

All `docker exec` calls used `MSYS_NO_PATHCONV=1` so Git Bash could not rewrite
`/var/www/...` into `C:/Program Files/Git/var/www/...` and produce false mismatches.

SHA-256 (first 16 hex), **HOST == RUNNER == APP**:

| Hash | File | Verdict |
|---|---|---|
| `f2676035bd0c28d7` | `ApproveSupplierReturnAction.php` | MATCH |
| `8282b83f53307b63` | `ReturnableQuantityService.php` | MATCH |
| `11c49d2f1be2ada9` | `SupplierReturnValidationException.php` | MATCH |
| `7edb4e1d1415f6a5` | `InventoryLayerConsumptionService.php` | MATCH |
| `a78625f8303220c9` | `SupplierReturnController.php` | MATCH |
| `63821400e281250a` | `StoreSupplierReturnRequest.php` | MATCH |
| `4d7e7a7a6dc9c845` | `SupplierReturnResource.php` | MATCH |

`SupplierReturnValuationTest.php` — HOST `6dd2458186dedc09` == RUNNER `6dd2458186dedc09`.

`ReverseSupplierReturnInventoryAction.php` — **absent on HOST, APP and RUNNER alike.**

**7/7 production files at three-way parity. Deployed code == certified source.**

---

## 7. Minimum runtime smoke

Scoped to proving deployment validity only. No regression suite, no browser, no full static analysis.

| # | Check | Result |
|---|---|---|
| 1 | Supplier Return routes resolve | **PASS** — `route:list --path=supplier-returns` returns **13** routes, including `POST api/supplier-returns/{supplierReturn}/approve` |
| 2 | Certified classes autoload | **PASS** — all 5 probed classes `class_exists() == true` in `ecos-dev-app` |
| 2b | DI container builds them | **PASS** — `app(ApproveSupplierReturnAction::class)` and `app(SupplierReturnController::class)` both construct, so every constructor dependency resolves |
| 2c | Superseded class gone | **PASS** — `class_exists(ReverseSupplierReturnInventoryAction) == false` |
| 3 | Required DB structures exist | **PASS** — §5 table |
| 4 | No runtime exception on the certified path | **PASS** — `php -l` clean on all 7 files; HTTP via `ecos-dev-nginx:8081`: `GET /api/supplier-returns` → **401**, `GET /api/supplier-returns/stats` → **401**, `POST /api/supplier-returns/{id}/approve` → **401**. Auth-gated as expected, **no 500** |
| 5 | Deployed code == certified source | **PASS** — §6 |

**Certified wiring confirmed in the deployed bytes** (grep against the container, not the host):

- `SupplierReturnController` imports and constructor-injects `ApproveSupplierReturnAction`, and
  catches `SupplierReturnValidationException` → 422.
- `ApproveSupplierReturnAction` injects `AdjustmentOutAction`, `InventoryLayerConsumptionService`
  and `ReturnableQuantityService`; wraps in `DB::transaction`; performs the `lockForUpdate()`
  re-read of `inventory_restocked` **inside** the transaction; sets `inventory_restocked` +
  `inventory_restocked_at`.
- `StoreSupplierReturnRequest`: `lines.*.goods_receipt_line_id => ['required','uuid','exists:goods_receipt_lines,id']`.
- `SupplierReturnResource` exposes `goods_receipt_line_id`.

### Not run — and why

`SupplierReturnValuationTest` was **not** re-executed. The T-6 advisory lock
`ecos:testrunner:ecos_dev_test` was **held by a live connection (id 1052, `User sleep`, 288s)**
throughout. PART 11 requires waiting or stopping rather than competing for the environment, so
the runner was left alone. This is not a gap in deployment evidence: the runner was already at
byte-exact parity before this task began, and the certifying run (`OK 122 tests, 334 assertions`)
was executed there against the identical bytes now on `ecos-dev-app`.

---

## 8. Deployment-specific observations

**One deployment defect found and fixed:** `ecos-dev-app` was serving pre-certification code
entirely (§3). Fixed by the targeted deployment in §4. This was the defect the task existed to close.

**One benign residue, recorded not repaired:** `vendor/composer/autoload_classmap.php` and
`autoload_static.php` in `ecos-dev-app` still carry a classmap entry for the deleted
`ReverseSupplierReturnInventoryAction`. It is **provably inert** — the loader consults the
classmap only when something asks for that class name, and nothing in the deployed tree
references it (§4). It surfaces a `include(): Failed to open stream` warning *only* when the
name is probed directly, as this report's own check did.

It was **not** hand-edited: `composer` is not installed in the production image, so the only
options were a manual edit of a generated autoloader or leaving a proven-inert artifact.
Hand-patching vendor autoloader files inside a running container is a larger and less reviewable
risk than the residue itself. It clears on the next image build. The certified report §2
anticipated exactly this ("only stale PHPStan cache and composer classmap artifacts").

No unrelated pre-existing issue was repaired. The `create_recipe_cost_snapshots` pending
migration (§5) and the known-open `supplier_returns.company_id` create-path gap (certified report
§10) remain untouched and out of scope.

---

## 9. Certified business rules — unchanged

No rule was altered. Byte-for-byte parity (§6) is the proof: the deployed files are identical to
the certified source, so SR-1 (anchor supplier must match the return's supplier), SR-2 (returnable
ceiling), idempotency, accumulation across lines sharing one anchor, the Approved → Cancelled
allowance rule, the mandatory anchor in the read model/API contract, and the certified FIFO
valuation behaviour are all exactly as certified. Nothing was re-implemented, redesigned or reopened.

---

## 10. Final deployment status

**SUPPLIER RETURN DEPLOYMENT = COMPLETE**

- Certified changeset identified from the certified report, not from memory
- `ecos-dev-app` was fully stale; 7 production files deployed, 1 deletion propagated to app + runner
- No migration required — proven three ways; the one unrelated pending migration left alone
- HOST == RUNNER == APP on all 7 production files, `MSYS_NO_PATHCONV=1` throughout
- Minimum runtime smoke green: routes, autoload, DI, schema, lint, HTTP no-500
- No unrelated file deployed; no other agent's work touched; no database reset

**FINAL SYSTEM CERTIFICATION = DEFERRED** — the project is in the modification phase.
Full regression, browser E2E, static analysis and the certification matrix will run in the
project-wide review after all remaining ECOS ERP modifications are complete.

No further Procurement work was started.
