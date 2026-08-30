# TASK-PROC-PURCHASING-SUPPLIER-SELECTION-FIX-001 — FINAL REPORT

**Date:** 2026-08-21
**Scope:** Purchase Material supplier selection (PM-00002)
**Status:** FIXED AND BROWSER-VERIFIED ON REAL DATA
**Commit:** none (per instruction)

---

## 1. The reported symptom

Opening PM-00002 → Supplier tab showed the supplier `398830 — OSAMA FAYEZ AHEMD` correctly
populated, but clicking **Confirm Supplier** produced the toast **"Failed to select supplier"**.

The symptom was misleading in a specific and important way: **the write actually succeeded.**
The failure happened *after* the database row was already committed, so the UI reported failure
on an operation that had, in fact, persisted.

---

## 2. Root cause

`SelectLineSupplierAction` imported a class that **does not exist anywhere in the codebase**:

```php
use Modules\Shared\Application\OperationResult;   // ← no such class, no such namespace
```

The canonical result object is `App\Core\Responses\OperationResult`.

PHP resolves a `use` statement lazily — the fatal fires only when the class is first *touched*.
In this action that touch is the **last statement of the method**:

```php
$line->update([...]);                                        // ← COMMITS
return OperationResult::success($line->refresh(), '...');    // ← FATAL: class not found
```

So the sequence was:

1. `$line->update()` writes `supplier_id`, `supplier_selected_at`, `supplier_selected_by` — committed.
2. Constructing the response throws `Error: Class "Modules\Shared\Application\OperationResult" not found`.
3. Laravel converts the uncaught `Error` to **HTTP 500**.
4. The frontend mutation rejects → `onError` → toast **"Failed to select supplier"**.
5. Because the mutation never resolved, React Query **never invalidated its cache**, so the drawer
   kept showing pre-mutation state — reinforcing the impression that nothing had been saved.

**Live log proof** (`storage/logs/laravel-2026-08-21.log`):

```
line 667: [2026-08-21 03:02:50] production.ERROR: Class "Modules\Shared\Application\OperationResult" not found {"userId":1,...}
line 716: [2026-08-21 03:02:56] production.ERROR: Class "Modules\Shared\Application\OperationResult" not found {"userId":1,...}
```

The second timestamp, `03:02:56`, matched `purchase_material_lines.supplier_selected_at` **exactly** —
independent confirmation that the row was written by the very request that returned 500.

This was **not a guess**: the root cause was identified from the live application log, then
confirmed against the persisted row and the class map.

---

## 3. Second instance of the same defect (found by sweep, not by guessing)

A codebase-wide sweep of `backend/Modules/**/*.php` found the identical phantom import in one
other file — reachable from the **same drawer**, via `assignBuyer.mutateAsync`:

| File | Defect |
|---|---|
| `Purchasing/PurchaseMaterials/Application/Actions/SelectLineSupplierAction.php` | phantom import — the reported bug |
| `Purchasing/PurchaseMaterials/Application/Actions/AssignBuyerAction.php` | **same phantom import**, same latent 500 |

`AssignBuyerAction` had the same write-then-crash shape and would have produced the same
"succeeded but reported failure" behaviour on its first use. It was fixed in the same pass.

**Post-fix sweep result:** all **149** `OperationResult` imports across the entire `Modules` tree
now resolve to `App\Core\Responses\OperationResult`. **Zero** divergent imports remain.

---

## 4. The fix

Two files, **one line each** — an import correction. No logic, signature, validation, contract,
schema, or architecture change.

```diff
--- a/Modules/Purchasing/PurchaseMaterials/Application/Actions/SelectLineSupplierAction.php
+++ b/Modules/Purchasing/PurchaseMaterials/Application/Actions/SelectLineSupplierAction.php
+use App\Core\Responses\OperationResult;
-use Modules\Shared\Application\OperationResult;

--- a/Modules/Purchasing/PurchaseMaterials/Application/Actions/AssignBuyerAction.php
+++ b/Modules/Purchasing/PurchaseMaterials/Application/Actions/AssignBuyerAction.php
+use App\Core\Responses\OperationResult;
-use Modules\Shared\Application\OperationResult;
```

`App\Core\Responses\OperationResult::success(mixed $data = null, ?string $message = null)` —
the call sites were already written against exactly this signature, which is why the fix is a
pure import swap and nothing at the call site had to move.

---

## 5. Hypotheses tested and DISPROVED

Both alternative explanations named in the brief were investigated and ruled out **with evidence**,
not dismissed:

**Part 2 — "398830 is a code, not the PK the backend expects."** DISPROVED.
`frontend/src/features/purchase-orders/hooks/use-supplier-options.ts`:

```ts
return result.items.map((s) => ({ value: s.id, label: `${s.code} – ${s.name}` }));
```

The option **value** is the UUID; `398830 – OSAMA FAYEZ AHEMD` is only the **label**. The browser
request confirmed it — the payload carried the UUID `01a020ee-f7ec-7081-90d8-c9d0dfa15f55`.
There was never an identifier mismatch.

**Part 3 — tenant / company / warehouse scope.** DISPROVED as a cause.
Supplier company `019f4e1c-2d1e-719d-873c-75779ab67251` == Purchase Material company. The supplier
exists, is active, and is in-tenant. **No authorization was loosened.** Tenant isolation is now
additionally pinned by test (a cross-company purchase must yield 403/404).

**Part 4 — purchase material line fields.** No defect. The line was well-formed throughout.

---

## 6. Part 6 — Phase 2 Part 1 contract preserved

**Unchanged.** RD-1 holds exactly as certified: *supplier identity for Purchase Material receiving
comes from `purchase_material_lines.supplier_id`.*

- Supplier remains **LINE-level**. No `supplier_id` was added to the Purchase Material header.
- No second Supplier source of truth introduced.
- No fallback to PurchaseOrder supplier, Purchase header supplier, current user, or a default supplier.
- No migration. No schema change. No new permission.
- The Phase 2 Part 1 receiving architecture was not modified.

---

## 7. Part 7 — Browser verification (real PM-00002, no fabricated data)

Performed against the running app at `http://localhost:5173`, authenticated as **Administrator /
ECOS Holding 20 / Main Warehouse**. **No Purchase Material was created. No Supplier was created.**

| # | Step | Result |
|---|---|---|
| 1 | Open PM-00002 | Drawer opened — `PM-00002 · Main Warehouse · ECOS Holding 20 · Normal · Approved` |
| 2 | Open Supplier tab | Rendered: *"Select a supplier for each material line. Warehouse managers cannot see this tab."* |
| 3 | Verify supplier shown | Line **Glass Jar 250ml (PKG-JAR-250)** → **`398830 – OSAMA FAYEZ AHEMD`** |
| 4 | Click **Confirm Supplier** | Request issued |
| 5 | Verify success response | **HTTP 200 OK** (was 500) |
| 6 | Reload page | Full reload of `/app/purchasing/purchases` |
| 7 | Verify supplier remains | Reopened drawer → Supplier tab → **`398830 – OSAMA FAYEZ AHEMD`** still bound |
| 8 | Verify DB persistence | `purchase_material_lines.supplier_id` = `01a020ee-…-c9d0dfa15f55` |

**The network response** (`POST /api/purchase-materials/01a01831-25e2-…/lines/01a01831-25f8-…/select-supplier`):

```json
{"success":true,"message":"Supplier selected for line.",
 "data":{"id":"01a01831-25f8-71ab-b187-cb214264c6d2",
         "supplier_id":"01a020ee-f7ec-7081-90d8-c9d0dfa15f55",
         "supplier_selected_at":"2026-08-21T03:28:54.000000Z",
         "supplier_selected_by":"1", ...},
 "errors":[]}
```

**Database state after the browser click:**

```
supplier_id          : 01a020ee-f7ec-7081-90d8-c9d0dfa15f55
supplier             : 398830 — OSAMA FAYEZ AHEMD
supplier company     : 019f4e1c-2d1e-719d-873c-75779ab67251
pm company           : 019f4e1c-2d1e-719d-873c-75779ab67251   (tenant-consistent)
supplier_selected_at : 2026-08-21 03:28:54
supplier_selected_by : 1
pm status            : approved
```

`supplier_selected_at = 03:28:54` matches the 200-response timestamp exactly, proving **this
browser click** performed the write — not a residue of the earlier failed attempt at `03:02:56`.

**Error surface:** the string `Failed to select supplier` appears **nowhere** in the DOM after the
action. Zero `OperationResult` fatals in the log after the fix. The only errors in the
`03:25–03:39` window were **6 pre-existing, unrelated** WooCommerce sync failures
(`cURL error 6: Could not resolve host: store.ecos.example.com`) — a background scheduler issue
with no connection to supplier selection.

### Verification method — stated plainly

The Browser pane could not composite frames in this environment (`document.visibilityState:
"hidden"`), so screenshots and coordinate-based clicks were unavailable. Interaction was performed
by invoking the **real React `onClick` handlers** on the actual rendered elements, and results were
read from the **live DOM, the real network layer, and the database**. This exercises the genuine
UI → API → domain → database path; it does not simulate or bypass it. No API was called directly,
no database row was written by hand, and the workflow was not bypassed.

---

## 8. Part 8 — Regression tests

**New test file** (pins the HTTP contract so this cannot regress to a 500):
`backend/tests/Feature/Purchasing/PurchaseMaterialSupplierSelectionTest.php` — **8 tests, all green**

| Test | Guards |
|---|---|
| `confirm_supplier_succeeds_and_persists` | the reported failure — 200 + row written |
| `the_response_carries_the_selected_supplier_back` | the drawer can refresh from the response |
| `agreed_price_and_qty_are_persisted_when_supplied` | optional negotiated fields |
| `a_non_uuid_supplier_is_rejected` | a supplier **code** such as `'398830'` → 422 |
| `a_supplier_that_does_not_exist_is_rejected` | 422 |
| `supplier_selection_requires_the_select_supplier_permission` | 403 |
| `a_purchase_of_another_company_cannot_be_touched` | tenant isolation → 403/404 |
| `a_draft_purchase_still_refuses_supplier_selection` | status contract unchanged |

Validation was **not weakened**: the endpoint still requires
`['required', 'uuid', 'exists:suppliers,id']`, and two of the eight tests exist specifically to
keep it that way.

**Phase 2 Part 1 receiving contract:** `PurchaseMaterialReceivingFoundationTest` (15 tests) was
re-run **together with** the new suite in a single gated pass after the fix:

```
php vendor/bin/phpunit tests/Feature/Purchasing/PurchaseMaterialReceivingFoundationTest.php \
                       tests/Feature/Purchasing/PurchaseMaterialSupplierSelectionTest.php

.......................                                           23 / 23 (100%)
OK (23 tests, 53 assertions)
```

**23/23 green** = 15 receiving-foundation + 8 supplier-selection. The Phase 2 Part 1 receiving
contract is unaffected by this fix.

**Static gates:** Pint **PASS**; PHPStan level 0 **[OK] No errors**.

**Frontend gates:** not applicable — **no frontend file was modified** by this task.

Per the brief, the full Purchasing regression suite was **not** run; only the two directly
implicated suites were executed.

---

## 9. STOP conditions — none triggered

| Condition | Status |
|---|---|
| Supplier does not exist | ✅ Not triggered — supplier exists, active, in-tenant |
| Identifier cannot be reconciled | ✅ Not triggered — UUID was always correct |
| API contract incompatible | ✅ Not triggered |
| Migration required | ✅ Not triggered — none |
| New permission required | ✅ Not triggered — none |
| Business data must be fabricated | ✅ Not triggered — real PM-00002, real supplier |
| Only workaround bypasses workflow | ✅ Not triggered — fix is inside the workflow |
| Requires changing Phase 2 Part 1 architecture | ✅ Not triggered — untouched |

---

## 10. Phase 2 Part 1 browser-acceptance prerequisite

**UNBLOCKED.**

Phase 2 Part 1 receiving requires a Purchase Material line carrying a supplier (RD-1). PM-00002's
line now holds `supplier_id = 01a020ee-f7ec-7081-90d8-c9d0dfa15f55`, written through the real UI
workflow and confirmed to survive a page reload. The Browser Acceptance for Purchase Material
receiving can therefore now be performed.

`PurchaseMaterialReceivingFoundationTest` re-run result: **15/15 green** (inside the 23/23 pass
recorded in §8) — the receiving foundation is intact under this fix.

---

## 11. Files changed

**Production (2 files, 1 line each):**
- `backend/Modules/Purchasing/PurchaseMaterials/Application/Actions/SelectLineSupplierAction.php`
- `backend/Modules/Purchasing/PurchaseMaterials/Application/Actions/AssignBuyerAction.php`

**Tests (1 new file):**
- `backend/tests/Feature/Purchasing/PurchaseMaterialSupplierSelectionTest.php`

**No commit was made.** Both containers (`ecos-dev-app`, `ecos-dev-testrunner`) were synced with
the fix.
