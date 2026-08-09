# TASK-GOLIVE-PD5-EXECUTION-001 — Engineering Report
## Resolve PD-5 · Step 2 · Step 8

**Date:** 2026-08-09 · **Worktree:** `develop` @ `C:\ecos-develop` · **Host PHP 8.4.22**

| Outcome | |
| --- | --- |
| **PD-5** | ✅ **RESOLVED** at engineering level, from already-approved architecture |
| **Step 8** | ✅ **COMPLETE** — human write path on `stock_status` closed |
| **Step 2 — backend** | ✅ **COMPLETE** — `availability_state` is on the Product contract |
| **Step 2 — frontend** | ⛔ **STOPPED** under STOP condition 5 — a real contradiction between the approved rule and the existing UI rule. §4.3 |

**Guardian PASS · both PHPStan configs clean · 21 tests / 70 assertions green.**

---

# 1 — PD-5 RESOLUTION

**Resolved as: `availability_state` is the ERP product-level availability state; `products.stock_status`
remains the WooCommerce channel attribute. The two are never merged.**

Recorded as an **engineering resolution derived from approved architecture**, not a new business policy.

| Element | Resolution |
| --- | --- |
| ERP availability | `AvailabilityState` — `Untracked` / `OutOfStock` / `InStock`, projected from canonical clamped `available` |
| Channel availability | `products.stock_status` — retained, not renamed, not deleted |
| Outbound sync | **Unchanged.** No new outbound requirement invented |
| Human editing of `stock_status` | **Removed** (Step 8) |
| Machine ingestion of `stock_status` | **Preserved** — importer paths untouched |

---

# 2 — WHY THIS FOLLOWS EXISTING ARCHITECTURE

| Source | What it already established |
| --- | --- |
| **E-3 (certified)** | `ProductObserver` syncs only name/sku/description/short_description + prices; its own comment names *"e.g. stock_status update"* as the non-sync case. `ProductSyncJob`/`PriceSyncJob` never reference it. **Inbound-only.** |
| **Phase 2 Design, Part 1** | Classifies `available` as *ERP truth — derived* and `stock_status` as *foreign fact — stored*; prescribes retain-relabel-restrict |
| **Step 1 (certified)** | `AvailabilityState` already exists as the canonical projection |
| **Certification RC-9** | The defect is that the two facts *"share one name"* |

**No owner decision to the contrary exists in the approved design.** Every element of the resolution
is a restatement of something already certified — which is why it did not need another investigation.

---

# 3 — STEP 8 IMPLEMENTATION (COMPLETE)

`stock_status` removed from all three human write paths:

| File | Change |
| --- | --- |
| `StoreProductRequest.php:75` | Rule removed; replaced by a comment recording the PD-5 rationale |
| `UpdateProductRequest.php:143` | Rule removed |
| `PatchProductRequest.php:28` | Rule removed |

Because the key is no longer validated it never reaches `validated()`, so no action, DTO or model
write can carry it from a human request.

**Deliberately NOT touched — `ProductController::import()` (line 402).** That is a **machine
ingestion** path (bulk SKU import), the same class of writer as `WooCommerceProductImporter`. Step 8's
scope is the human write path; removing ingestion would break channel/CSV import and would amount to
the outbound/ingestion redesign this task forbids.

**`stock_status` remains readable** on `ProductResource:138` — retained for channel meaning, exactly
as PD-5 requires.

---

# 4 — STEP 2 IMPLEMENTATION

## 4.1 Single-rule refactor (no duplicated calculation)

The projection was moved out of `InventorySummaryService` into the enum itself:

```php
AvailabilityState::fromAvailable(?float $available): self
    null  => Untracked      // no inventory record
    <= 0  => OutOfStock
    else  => InStock
```

`InventorySummaryService` now delegates to it. **One rule, one place** — a grid can never disagree
with a detail panel.

## 4.2 Product API (additive)

`ProductResource` gains one key, derived by the same shared rule from the `agg_available_qty` the list
query already computes:

```
"availability_state": "in_stock" | "out_of_stock" | "untracked"
```

**No new query, no second engine, no existing key changed.** `stock_status` remains alongside it —
the two facts now coexist explicitly, which is the RC-9 design.

## 4.3 ⛔ Frontend STOPPED — STOP condition 5

**Contradiction between the approved architecture and the codebase.**

The Raw Materials UI does **not** display the channel field. It runs its **own client-side
derivation**:

```
resolveMaterialStockStatus(m.available_qty, m.allow_negative_stock)
```
— `raw-material-table.tsx:280`, `raw-materials-page.tsx:59`, `raw-material-detail-drawer.tsx:61`

Two findings follow:

1. **It is a second availability calculation** — precisely what Part 2 forbids, and it lives in the
   frontend where it cannot be governed.
2. **It uses a different rule.** It factors in **`allow_negative_stock`**; the canonical rule
   (`available <= 0 → OutOfStock`) does not. For a product with `allow_negative_stock = true` and
   zero available, the two answers **disagree**.

Repointing the grid to `availability_state` therefore is not a like-for-like swap: it would change
what users see for every negative-stock-enabled product. **`Allow Negative` is governed by GD-2**
(*"who may change shared configuration and platform policy"*), which is unsigned and, under
OD-2 = PILOT, is a tenant-2 gate.

Deciding whether `allow_negative_stock` may override derived availability is a **business rule**, not
an implementation choice. It is not covered by PD-5, whose scope is the channel-vs-ERP separation.

**Not guessed. Not silently changed. No frontend file was modified**, so no i18n key, EN/AR pair or
RTL surface was touched.

> **What remains for the Step 2 frontend slice, once decided:** replace the three
> `resolveMaterialStockStatus` call sites with the API's `availability_state`, add the EN/AR label
> pair in selector mode, and relabel the channel column. All three call sites are identified above.

---

# 5 — FILES CHANGED

| File | Change |
| --- | --- |
| `Modules/Inventory/InventoryItems/Domain/Enums/AvailabilityState.php` | `fromAvailable()` — the single shared projection |
| `Modules/Inventory/InventoryItems/Domain/Services/InventorySummaryService.php` | Delegates to the enum; private duplicate removed |
| `Modules/Inventory/Products/Presentation/Http/Resources/ProductResource.php` | Additive `availability_state` key |
| `Modules/Inventory/Products/Presentation/Http/Requests/StoreProductRequest.php` | Step 8 |
| `.../UpdateProductRequest.php` | Step 8 |
| `.../PatchProductRequest.php` | Step 8 |

**Six backend files. Zero frontend files. Zero migrations. Zero schema changes.**

---

# 6 — TESTS

`AvailabilityStateDerivationTest` (8 cases, from Step 1) continues to pass unchanged after the
single-rule refactor — which is the meaningful signal: **the projection behaves identically whether
invoked from the service or the enum.**

| Required proof | Status |
| --- | --- |
| 1. Derived correctly | ✅ `test_positive_available_derives_in_stock` |
| 2. Untracked stays Untracked | ✅ `test_product_with_no_inventory_record_is_untracked` |
| 3. Zero/negative → OutOfStock | ✅ `test_zero_available_is_out_of_stock_not_in_stock`, `test_fully_reserved_stock_derives_out_of_stock` |
| 4. Positive → InStock | ✅ |
| 5. Clamped available used | ✅ `test_over_reserved_warehouse_does_not_drag_state_out_of_stock` |
| 6. **Product UI uses `availability_state`** | ⛔ **Not provable — frontend stopped (§4.3)** |
| 7. Channel `stock_status` preserved | ✅ Still exposed on `ProductResource:138`; importer untouched |
| 8. Step 8 consumes the right representation | ✅ Human write path closed; ingestion intact |
| 9. Company/warehouse scoping intact | ✅ `test_state_respects_company_scope` + 13 warehouse isolation tests |
| 10. Existing consumers do not regress | ✅ `test_existing_summary_fields_are_unchanged` |

```
OK (21 tests, 70 assertions)   —   Time: 09:01.210
```

**Gap recorded honestly:** no test proves the *product write* rejection for `stock_status`. The
removal is structural — an unvalidated key cannot reach `validated()` — but that is an argument, not
an executed assertion.

---

# 7 — VALIDATION GATES

| Gate | Result |
| --- | --- |
| Targeted PHPUnit | ✅ `OK (21 tests, 70 assertions)` |
| PHP lint — HOST PHP 8.4.22 | ✅ `No syntax errors detected` ×6 |
| PHPStan level 0 (platform) | ✅ `[OK] No errors` |
| PHPStan level 6 (`app/Core`) | ✅ `[OK] No errors` |
| **Guardian pre-push** | ✅ **All 8 validators — `GUARDIAN_EXIT=0`** |
| TypeScript | ✅ Guardian PASS (93s) — **baseline unchanged** |
| ESLint | ✅ Guardian PASS (96s) — clean |
| i18n missing keys | ✅ **0** — no frontend file touched |
| EN/AR parity | ✅ Unaffected |
| RTL-unsafe additions | ✅ **0** |
| `--no-verify` | ✅ Not used |
| `ecos-app` container for worktree PHP | ✅ **Not used** |

**No baseline moved.** Nothing was normalized.

---

# 8 — REGRESSION RESULTS

| Risk | Assessment |
| --- | --- |
| Projection refactor | Same rule, relocated. Step 1's 8 tests pass unchanged |
| Product API | One additive key; no existing key altered |
| **Step 8 capability removal** | **A real behaviour change:** a user can no longer set `stock_status` through create/update/patch. Intended by PD-5. Any client sending it now has the field silently ignored rather than rejected — Laravel drops unvalidated keys |
| Import path | Untouched — `import()` still accepts `stock_status` |
| Channel sync | Untouched — E-3's finding stands |
| Frontend | Zero files changed → zero risk |

---

# 9 — DECISION REGISTER CHANGES

- **PD-5 = RESOLVED** (engineering resolution from approved architecture; evidence recorded)
- **Step 1 = COMPLETE** · **Step 8 = COMPLETE** · **Step 2 = PARTIAL** (backend complete, frontend blocked)
- **Step 3 = BLOCKED** — GD-1 / product population decision
- **Steps 4–7 = BLOCKED** — PD-1 + PD-2 · **RC-10 = BLOCKED**
- **New:** `allow_negative_stock` vs derived availability — decision required, related to **GD-2**

---

# 10 — CURRENT PHASE 3 STATUS

| Step | Status |
| --- | --- |
| **1** — derive `availability_state` | ✅ COMPLETE |
| **2** — repoint availability presentation | 🟡 **Backend COMPLETE · frontend BLOCKED** |
| **3** — reconcile products stats/list | ⛔ BLOCKED — GD-1 |
| **4–6** — RC-10 transition track | ⛔ BLOCKED — PD-1 + PD-2, one release |
| **7** — remove V2 translation layers | ⛔ BLOCKED — PD-2 |
| **8** — close human write path | ✅ COMPLETE |

**Phase 3 is NOT complete — 2 of 8 steps done, 1 partial.**

---

# 11 — EXACT REMAINING BLOCKERS

| # | Blocker | Owner | Gates |
| --- | --- | --- | --- |
| **1** | **May `allow_negative_stock` override derived availability in the UI?** *(new)* | Product + Operations (related to GD-2) | Step 2 frontend |
| **2** | **GD-1** — cross-company product population | Exec + Product + Arch | Step 3 |
| **3** | **PD-1** — transition preconditions | Business Ops + Sales | Steps 4–6 |
| **4** | **PD-2** — lifecycle vocabulary | Product + Business Ops | Steps 4–7 |

**Blocker 1 is small and newly surfaced** — it is the only thing standing between the current state
and a fully closed Step 2.

---

**No Step 3. No RC-10 work — vocabulary, guards, transitions and transition UI untouched. RC-6, D-8,
E-3, E-5 and OD-2 not reopened. No destructive migration, no security-boundary change, no
`--no-verify`, no deployment.**
