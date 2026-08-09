# TASK-GOLIVE-PILOT-PHASE3-PREP-001 — Engineering Report
## E-3 / E-5 / Step 1 / Step 3 / Supplier Isolation

**Date:** 2026-08-08 · **Worktree:** `develop` @ `C:\ecos-develop` · **Host PHP 8.4.22**
**Context:** proceeding under the recorded owner decision **OD-2 = PILOT**. Not reopened.

---

# HEADLINE

| Part | Outcome |
| --- | --- |
| **1 — E-3** | ✅ **ANSWERED** — outbound sync does **not** publish `stock_status`. PD-5 unblocked |
| **2 — E-5** | ✅ **ANSWERED — all 13 bulk routes PASS.** SD-4's last gap closed |
| **3 — Step 1** (`availability_state`) | ⏸️ **NOT IMPLEMENTED** — deferred, no stop condition. See §3 |
| **4 — Step 3** (Products stats/list) | ⛔ **STOPPED** — investigation complete; reconciliation requires a **GD-1 architecture decision** |
| **5 — D-8 Supplier** | ✅ **FIXED AND CERTIFIED** — baseline reproduced, fixed, 22/22 green, Guardian pass |
| **6 — RC-10** | ✅ Untouched. Vocabulary, guards, transitions and UI controls unchanged |

---

# 1 — E-3 RESULT

## 1.1 The exact question

**Does the platform publish `products.stock_status` outbound to WooCommerce?** Phase 2 left this open,
and it gated Steps 2 and 8 because changing what writes the field could change what a real storefront
advertises to real shoppers.

## 1.2 Existing implementation

| Direction | Component | Behaviour |
| --- | --- | --- |
| **Inbound** | `WooCommerceProductImporter:520-531` | Reads `stock_status` from the Woo payload, validates against `VALID_STOCK_STATUSES`, defaults to `instock`, and writes it |
| **Outbound** | `ProductObserver:14-26` | Syncs **only** `PRODUCT_SYNC_FIELDS = ['name','sku','description','short_description']` and `PRICE_SYNC_FIELDS = ['regular_price','sale_price']` |

## 1.3 Evidence — the codebase answers this explicitly

`ProductObserver.php:14-26`:

```php
private const PRODUCT_SYNC_FIELDS = ['name', 'sku', 'description', 'short_description'];
private const PRICE_SYNC_FIELDS   = ['regular_price', 'sale_price'];

public function updated(Product $product): void
{
    $productFieldChanged = $product->wasChanged(self::PRODUCT_SYNC_FIELDS);
    $priceFieldChanged   = $product->wasChanged(self::PRICE_SYNC_FIELDS);

    // Skip sync entirely when no sync-relevant field changed (e.g. stock_status update).
    if (! $productFieldChanged && ! $priceFieldChanged) {
        return;
    }
```

**That comment names `stock_status` as the example of a non-sync-relevant change.** This is not
inference — the platform states its own intent.

Corroborating: a full-backend search for `stock_status` returns 18 files. **`ProductSyncJob` and
`PriceSyncJob` are not among them** — the outbound payload builders never mention the field.

## 1.4 Affected modules

`Commerce\ProductImport` (writes it) · `Commerce\Synchronization` (deliberately excludes it) ·
`Inventory\Products` (stores, validates, filters and displays it).

## 1.5 Current behaviour

**`products.stock_status` is an inbound-only channel mirror.** WooCommerce writes it into ECOS. ECOS
never writes it back. A human edit inside the ERP changes only the local mirror and **cannot** affect
the storefront.

## 1.6 Implications for PD-5

| Option | Was | Now |
| --- | --- | --- |
| **A — retain, relabel, restrict** | Recommended, unverified | ✅ **Confirmed safe** — no storefront impact |
| **B — delete the field** | *"Unsafe until Q1 is answered"* | **Now technically safe for the storefront**, but it would discard the channel's advertised state, which has diagnostic value when ERP availability and storefront status disagree |
| **C — leave as is** | Not viable | Still not viable — RC-9 stays open |

**Phase 2 Steps 2 and 8 are unblocked on the technical question.** Step 8 (removing human
editability) removes a capability, so it remains a product decision — but the *risk* that made it
dangerous is gone.

## 1.7 Recommendation

**Option A.** Retain the field as an explicitly labelled channel attribute; bind the ERP grid to
derived availability; remove human editability. No defect was found, so no code was changed.

## 1.8 Remaining decision

**PD-5 itself** — owner: Product + Channel. It is now a straightforward choice with no unknown risk.

---

# 2 — E-5 RESULT

## 2.1 The question

**Do the 13 bulk fulfillment routes enforce the same guards as the 15 dedicated ones?** SD-4 closed
the dedicated routes but recorded the bulk routes as **UNVERIFIED**.

## 2.2 Trace

```
routes/api.php:1001-1013     13 POST routes, prefix /fulfillment/bulk
                             middleware: auth:sanctum + permission:operations.fulfillment.manage
      ↓
BulkFulfillmentController    13 statically injected workflows — the SAME workflow objects the
                             dedicated routes use. resolveTransitionWorkflow() is never called.
      ↓
BulkWorkflowEngine::run()    foreach order: Order::find($id)  →  $this->engine->run($workflow, …)
      ↓
FulfillmentEngine::run()     $workflow->guard($ctx)  →  DB::transaction  →  events  →  OrderEvent audit
```

**Decisive line — `BulkWorkflowEngine.php:49`:**

```php
$result = $this->engine->run($workflow, $order, $data, $actorId);
```

The class docblock states it directly: *"Closes GAP-04: bulk actions previously updated status only.
Now they execute the full workflow (guard → inventory → events → audit) per order."*

## 2.3 Matrix

| # | Route | Workflow | Guard enforced | Verdict |
| --- | --- | --- | --- | --- |
| 1 | `bulk/confirm` | `ConfirmOrderWorkflow` | via `FulfillmentEngine` | **PASS** |
| 2 | `bulk/cancel` | `CancelOrderWorkflow` | ″ | **PASS** |
| 3 | `bulk/move-to-preparation` | `MoveToPreparationWorkflow` | ″ — incl. reservation guard + auto-reserve | **PASS** |
| 4 | `bulk/complete-delivery` | `CompleteDeliveryWorkflow` | ″ | **PASS** |
| 5 | `bulk/complete` | `CompleteOrderWorkflow` | ″ | **PASS** *(inherits D-5, §2.5)* |
| 6 | `bulk/dispatch` | `DispatchOrderWorkflow` | ″ | **PASS** |
| 7 | `bulk/awaiting-stock` | `MarkAwaitingStockWorkflow` | ″ | **PASS** |
| 8 | `bulk/resume` | `ResumeOrderWorkflow` | ″ | **PASS** |
| 9 | `bulk/review` | `MoveToReviewWorkflow` | ″ | **PASS** *(inherits D-6, §2.5)* |
| 10 | `bulk/reschedule` | `RescheduleOrderWorkflow` | ″ | **PASS** |
| 11 | `bulk/return` | `ReturnOrderWorkflow` | ″ | **PASS** |
| 12 | `bulk/return-to-confirmed` | `ReturnToConfirmedWorkflow` | ″ | **PASS** |
| 13 | `bulk/resume-to-confirmed` | `ResumeToConfirmedWorkflow` | ″ | **PASS** |

**13 PASS · 0 PARTIAL · 0 FAIL · 0 UNVERIFIED.**

## 2.4 Two properties worth recording

**Company isolation holds.** `BulkWorkflowEngine:40` uses `Order::find($orderId)`, so the `tenant`
global scope applies — a foreign-company id returns `null` and is reported as `"Order not found."`
**Since RC-6, that scope also fails closed.**

**Error shape differs by design.** Bulk endpoints always return **200** with a per-order
`succeeded`/`failed` breakdown; a guard failure appears as its message in `failed[]` rather than a
422. Documented behaviour, not a defect.

## 2.5 Known gaps (inherited, not new)

Routes 5 and 9 execute `CompleteOrderWorkflow` and `MoveToReviewWorkflow`, which carry the
pre-existing **D-5** (`/complete` performs no status transition) and **D-6** (`/review` sets
`OnHold`). Both resolve through **PD-2**. **Not touched** — RC-10 remains blocked.

## 2.6 Effect

**SD-4 is now complete: all 16 single + 13 bulk lifecycle entry points are surveyed.** The Step 5
prerequisite is satisfied. The generic `/transition` endpoint remains the only FAIL.

---

# 3 — STEP 1 (`availability_state`) — NOT IMPLEMENTED

**No stop condition was hit. This is a capacity decision, stated plainly rather than half-done.**

Step 1 is additive and carries no blocker: derive `availability_state` inside
`InventorySummaryService` from the `available` figure it already computes, expose it alongside the
existing fields, and wire it to nothing. Each verification cycle in this environment costs a ~10-minute
full-schema rebuild, and the work in Parts 2 and 5 consumed the available cycles.

**Implementing it without a verified test run would repeat the failure mode this programme has
already corrected once.** It is deferred intact:

- **Source of truth:** `InventorySummaryService` — unchanged, already canonical
- **Derivation:** a projection of `available` (clamp-per-warehouse, then sum), so it cannot disagree with the quantity beside it
- **Consumers to verify:** none — Step 1 is explicitly *"wire it to nothing"*
- **No UI**, therefore no i18n or RTL surface is introduced at this step

**Nothing was changed, so nothing is left unverified.**

---

# 4 — STEP 3 (Products stats/list) — ⛔ STOPPED

**Investigation complete. Implementation stopped under the task's own stop condition:
*"a genuine architecture decision is required."***

## 4.1 The traced contract

| Endpoint | Company scope | Source |
| --- | --- | --- |
| `GET /api/products` (list) | Applied **only if the caller supplies `company_id`** | `ProductController:57` → `EloquentProductRepository:134-138` — `if ($companyIdFilter !== '')` |
| `GET /api/products/stats` | **Always** the authenticated user's company | `ProductController:222-226` — `whereHas('brand', fn ($q) => $q->where('company_id', $companyId))` |

## 4.2 Cause — classified against the task's list

**Primary: different company scope.** With no `company_id` filter, the list returns **every
company's** products while the KPI counts **only the caller's own**. When the caller's company differs
from the products' brand company — precisely the UAT scenario, where a new company was created — the
KPI reads **0** above a populated table. That reproduces *"All Materials = 0"* over 2 rows exactly.
If `company_id` is `NULL`, `whereHas('brand', … company_id = null)` matches nothing, giving the same
result.

**Secondary: different filters.** `stats` defaults to
`whereIn('product_type', ['raw_material','packaging_material'])` when no type is supplied; `index`
applies no such default, and supports filters (`search`, `status`, `stock_status`, `brand_id`,
`channel_id`, `unit_id`, …) that `stats` does not.

**Excluded:** pagination (`stats` counts, does not paginate) · stale cache (no cache layer on either
path) · frontend interpretation (both render the server's numbers verbatim) · aggregation logic
(arithmetic is correct on both sides).

## 4.3 Why implementation stopped

The two endpoints can only be reconciled by choosing which population is authoritative:

| Direction | Consequence |
| --- | --- |
| **Tighten `index`** to the authenticated company | Makes them agree — **but this is fixing RC-1 for Products**, i.e. deciding that cross-company product browsing is not permitted. The certification recorded `All companies` browsing as a **possible deliberate group-buyer capability**. |
| **Loosen `stats`** to match `index` | Makes them agree — and **introduces a cross-company disclosure** by removing the only company scope on that endpoint. Unacceptable. |

**That choice is GD-1**, which OD-2 = PILOT has re-classified as a **tenant-2 gate**. Engineering
cannot make it, and either direction taken unilaterally would be wrong.

## 4.4 What is ready the moment GD-1 lands

The secondary cause (§4.2) is policy-free and could be corrected on its own — aligning the `stats`
product-type default with the list. It was **not** implemented, because fixing it alone would leave
the primary contradiction intact and could make the KPI *look* reconciled while the populations still
differ. Regression tests were **not** written, since the assertion they must encode depends on the
decision.

---

# 5 — D-8 SUPPLIER TENANT ISOLATION — ✅ FIXED AND CERTIFIED

## 5.1 Characterization tests first

`backend/tests/Feature/Purchasing/SupplierTenantIsolationTest.php` — 5 cases, written and executed
**before** any code change, with `$grantsBaselineAuthorization = false`.

**Baseline (pre-fix):**

```
..F..                                                               5 / 5 (100%)
There was 1 failure:
1) SupplierTenantIsolationTest::test_companyless_non_privileged_user_sees_no_suppliers
Tests: 5, Assertions: 11, Failures: 1.
```

## 5.2 What the baseline actually proved — a refinement worth recording

**Only the NULL-company path failed open.** `test_cannot_read_a_supplier_belonging_to_another_company`
**passed at baseline**: an actor *with* a company was already correctly scoped.

So D-8 is precisely a **null-company fail-open**, not a general cross-company hole. That matches the
production audit exactly — the one at-risk account (user 1767) has `company_id = NULL`. The earlier
report's framing was right about the mechanism; this narrows the reachable surface.

## 5.3 The fix

**One file, one method.** `Supplier.php` — the same three-branch scope already certified in
`Warehouse` and `Order`:

```
no actor          → skip   (console, queue, seeders, migrations)
isUnrestricted()  → skip   (documented is_system capability)
company is null   → whereRaw('1 = 0')   ← D-8
otherwise         → where('company_id', …)
```

`TenantOwnershipResolver` was reused unchanged. **No new permission was invented. No unrelated
Supplier functionality was touched. `ScopeResolver` was not touched.**

## 5.4 Required invariants

| Invariant | Result | Evidence |
| --- | --- | --- |
| Own-company supplier access works | ✅ | `test_own_company_supplier_is_listed_and_readable` |
| Cross-company access blocked | ✅ | `test_cannot_read_a_supplier_belonging_to_another_company` — empty list + 404 |
| NULL-company non-system fails closed | ✅ | `test_companyless_non_privileged_user_sees_no_suppliers` — **the baseline failure, now passing** |
| is_system capability preserved | ✅ | `test_unrestricted_user_retains_cross_company_visibility` — sees both companies |
| No new permission invented | ✅ | Routes unchanged; privilege via `userHasSystemRole()` |
| No unrelated Supplier change | ✅ | Diff is the scope closure + one import swap |
| Console/queue unscoped | ✅ | `test_unauthenticated_execution_is_not_scoped` |

## 5.5 Post-fix result — all tenant-isolation suites together

```
......................                                            22 / 22 (100%)
Time: 09:38.938
OK (22 tests, 62 assertions)
```

**5 Supplier + 13 Warehouse + 4 Order. D-8 fixed; RC-6 not regressed.**

---

# 6 — VALIDATION MATRIX

| Gate | Result |
| --- | --- |
| **Targeted PHPUnit** | ✅ `OK (22 tests, 62 assertions)` |
| **PHP lint — HOST PHP 8.4.22** | ✅ `No syntax errors detected` (Supplier model + test) |
| **PHPStan** — `phpstan.neon.dist` (level 0, platform) | ✅ `[OK] No errors` |
| **PHPStan** — `phpstan-core.neon.dist` (level 6) | ✅ `[OK] No errors` |
| **Guardian pre-push** | ✅ **All 8 validators passed — `GUARDIAN_EXIT=0`** |
| **TypeScript baseline** | ✅ Guardian TypeScript PASS (95s) — no regression |
| **ESLint** | ✅ Guardian ESLint PASS (128s) |
| **i18n missing keys** | ✅ **0** — no UI introduced; no key added or removed |
| **EN/AR parity** | ✅ Unaffected — no locale file touched |
| **RTL-unsafe additions** | ✅ **0** — no frontend file modified |
| `--no-verify` | ✅ Not used |
| RBAC seeding | ✅ None |
| Production deployment | ✅ None |
| `ecos-app` container used for verification | ✅ **No** — all validation ran on host against the develop worktree |

---

# 7 — DECISION REGISTER UPDATES

Applied additively. **OD-2 unchanged.**

| Item | Recorded |
| --- | --- |
| **E-3** | ✅ ANSWERED — outbound sync does not publish `stock_status`; PD-5 unblocked |
| **E-5** | ✅ ANSWERED — 13/13 bulk routes PASS; SD-4 fully closed |
| **Step 1** | ⏸️ Not implemented; no blocker; nothing left unverified |
| **Step 3** | ⛔ STOPPED — reconciliation requires GD-1 |
| **D-8** | ✅ **CLOSED** — fixed and certified across all three layers |
| **D-9 ScopeResolver** | Unchanged — **Multi-Tenant Expansion gate** |
| **RC-6** | Unchanged — **CLOSED** |
| **RC-10** | Unchanged — **BLOCKED** pending PD-1 / PD-2 |

---

# 8 — WHAT IS NOW UNBLOCKED

| Item | Unblocked by |
| --- | --- |
| **PD-5** — channel stock status | E-3. Now a plain product choice with no unknown risk |
| **Phase 3 Step 2** (repoint the grid) and **Step 8** (close human write path) | E-3, once PD-5 is signed |
| **Phase 3 Step 5** prerequisite | E-5. Every lifecycle entry point is now surveyed |
| **Phase 3 Step 1** | Nothing blocks it — ready to implement |
| **Pilot tenant-isolation posture** | D-8 removes the last *reachable* instance of the fail-open class |

---

# 9 — WHAT REMAINS BLOCKED

| Item | Blocked by |
| --- | --- |
| **Step 3** — products stats/list reconciliation | **GD-1** (tenant-2 gate under Pilot) |
| **Steps 4–6** — RC-10 transition track | **PD-1** and **PD-2**. Must ship as one release |
| **Step 7** — remove V2 translation layers | **PD-2** |
| **Steps 2 and 8** | **PD-5** |
| **D-9 ScopeResolver** | Multi-Tenant Expansion gate — unreachable today (zero production `scopedTo()` call sites) |
| **Tenant #2 onboarding** | GD-1, GD-2, GD-4, RC-1, RC-2 — per OD-2 |

---

# 10 — EXACT NEXT STEP

**Implement Phase 3 Step 1** — derive `availability_state` in `InventorySummaryService`, additive and
wired to nothing. It is the only remaining item that needs no decision, and it is the prerequisite for
Step 2 the moment PD-5 is signed.

**In parallel, put three decisions in front of their owners:**

| Decision | Now needs |
| --- | --- |
| **PD-5** | A choice — E-3 removed the risk |
| **PD-1** | Ratify existing enforcement + answer one question: warehouse at *Ready for Dispatch* or at *Dispatch*? |
| **PD-2** | Decide `completed`, `review`, `preparing`, and the `confirmed`/`processing` merge |

**Phase 3 is not complete.** This task executed only the currently unblocked portion. The blocked
sequence is unchanged: **PD-1 / PD-2 → Steps 4–6 as one release → remaining Phase 3 work.**

---

**No RC-10 work. No vocabulary, guard, transition or UI-control change. OD-2 not reopened.
`ScopeResolver` untouched. No new permission, no RBAC seeding, no destructive migration, no
production-data mutation, no `--no-verify`, no deployment.**
