# TASK-PHASE3-STEP1-AVAILABILITY-STATE-001 — Engineering Report
## Phase 3 Step 1 — Derive `availability_state`

**Date:** 2026-08-08 · **Worktree:** `develop` @ `C:\ecos-develop` · **Host PHP 8.4.22**

# ✅ STEP 1 COMPLETE

No stop condition was hit. The derivation was established from existing authoritative code, not
invented. **Phase 3 is not complete** — Step 1 is one of eight steps.

---

# 1 — EXISTING AVAILABILITY SOURCES

## 1.1 Authoritative source

**`InventorySummaryService`** — self-declared *"the single source of truth for inventory quantities"*
(EPIC-DATA-CONSOLIDATION-001, Phase B). Its stated enterprise rule:

```
available = Σ over warehouses of max(on_hand − reserved, 0)      [clamp-per-warehouse, THEN sum]
```

explicitly *"NOT `GREATEST(Σon_hand − Σreserved, 0)`"* — the legacy sum-then-clamp shape. Source
fields: `inventory_items.on_hand_qty`, `inventory_items.reserved_qty`, via
`InventoryItem::availableQty()`.

## 1.2 Existing scope

`summarize(string $productId, ?string $companyId = null)` filters `company_id` when supplied;
**`null` aggregates across all companies** — documented as the superuser/global view. Warehouse
breakdown is already returned per item. **Unchanged by this task.**

## 1.3 Was availability state already represented elsewhere? — Yes, in three places

| # | Location | What it is | Reused? |
| --- | --- | --- | --- |
| 1 | `ProductStockStatus` enum + `products.stock_status` | **WooCommerce channel vocabulary** (`instock`/`outofstock`/`onbackorder`), written by the inbound importer, never published outbound (E-3) | ❌ **Deliberately not reused** — reusing it would re-merge the two facts RC-9 requires separating |
| 2 | `EloquentProductRepository:87-88` — SQL `CASE … THEN 'outofstock' ELSE 'instock'` | `manufacturing_availability` — whether a product's **BOM components** can be sourced | ❌ Different question (component sourcing, not own stock) |
| 3 | **`DemandAnalysisService::buildDemandLine():143-148`** + `InventoryStatus` enum | Maps a quantity to a state | ✅ **Rule source — see §2** |

**This is why no new business rule was needed.**

---

# 2 — DERIVED-STATE RULE

## 2.1 The existing rule, quoted

`Modules/Operations/DemandAnalysis/Application/Services/DemandAnalysisService.php:143-148`:

```php
$inventoryStatus = match (true) {
    $availableQty === null    => InventoryStatus::Unknown,      // no inventory record
    $availableQty <= 0.0      => InventoryStatus::OutOfStock,
    $availableQty < $orderedQty => InventoryStatus::Shortage,
    default                   => InventoryStatus::Ready,
};
```

`InventoryStatus::Unknown` is documented as *"Product has no inventory record — not yet tracked in
the warehouse."*

## 2.2 What was adopted, and what was deliberately not

| Branch | Adopted? | Reason |
| --- | --- | --- |
| no record → `Unknown` | ✅ → `Untracked` | Demand-independent |
| `<= 0.0` → `OutOfStock` | ✅ → `OutOfStock` | Demand-independent. **The zero threshold is the existing one** — no new threshold invented |
| `< orderedQty` → `Shortage` | ❌ | **Requires an ordered quantity.** Meaningless for a product-level state; mirroring it would have invented a rule |
| otherwise → `Ready` | ✅ → `InStock` | Demand-independent |

## 2.3 The implemented rule

```php
match (true) {
    $untracked        => AvailabilityState::Untracked,   // no inventory_items row
    $available <= 0.0 => AvailabilityState::OutOfStock,
    default           => AvailabilityState::InStock,
};
```

**One difference from the source, and it is deliberate:** `DemandAnalysisService` reads
`on_hand_qty` as its quantity (line 141). This derivation consumes the **canonical clamped
`available`** produced two lines above it — as the approved Phase 2 design requires
(*"a derived enum over `available`"*). No threshold other than zero exists anywhere in the change.

---

# 3 — IMPLEMENTATION

**Three files. Additive only. No migration, no schema change, no field removed or renamed.**

| File | Change |
| --- | --- |
| `Modules/Inventory/InventoryItems/Domain/Enums/AvailabilityState.php` **(new)** | Backed string enum: `Untracked` / `OutOfStock` / `InStock`. Docblock records the rule's provenance and states explicitly that it is distinct from `products.stock_status` |
| `.../Domain/DTO/InventorySummary.php` | New readonly property `availabilityState`, **defaulted to `Untracked`** so every existing construction site stays valid. `toArray()` gains one key: `availability_state` |
| `.../Domain/Services/InventorySummaryService.php` | `deriveAvailabilityState()` — a private projection; `$available` rounded once and reused so the state and the reported figure cannot diverge |

**No second availability engine.** The derivation lives inside the canonical service and consumes its
own output. Nothing recomputes availability.

## 3.1 API contract

`GET /api/inventory-layers/{product}/summary` (`InventoryLayerController:120`) returns
`$summary->toArray()`, which now additionally contains:

```
"availability_state": "in_stock" | "out_of_stock" | "untracked"
```

**Purely additive** — every pre-existing key (`product_id`, `on_hand_qty`, `reserved_qty`,
`available_qty`, `inventory_value`, `warehouses`, `total_on_hand`, `total_reserved`,
`total_available`) is unchanged in name, type and value. Asserted by
`test_existing_summary_fields_are_unchanged`.

## 3.2 UI

**None.** Step 1 is explicitly *"wire it to nothing"*. No frontend file was modified, so no i18n key,
no EN/AR pair and no RTL surface was introduced. The enum carries **no `label()`**, deliberately —
adding an English-only display string would create exactly the hardcoded-string problem the UI rules
forbid. Labels belong with the Step 2 UI work, through the existing selector-mode i18n architecture.

---

# 4 — CONSUMER AUDIT

Searched the backend for `new InventorySummary(`, `InventorySummaryService` and `->summarize(`.

| Consumer | Classification | Reason |
| --- | --- | --- |
| `InventorySummaryService:79` — the only `new InventorySummary(...)` site | **UPDATED** | Passes the derived state. The only producer, as the DTO docblock requires |
| `InventoryLayerController:120` — `summarize($productId)->toArray()` | **SAFE** | Response gains one key; no existing key altered |
| `routes/console.php:70-102` — canonical-vs-legacy comparison command | **SAFE** | Reads `available`/value for comparison; new property ignored |
| `EnterpriseCostEngine:142` | **NOT AFFECTED** | Comment only — explicitly defers availability to this service |
| `EloquentProductRepository:26,87-89` | **NOT AFFECTED** | Computes `manufacturing_availability` (BOM component sourcing) — a different question |
| `products.stock_status` / `ProductStockStatus` (Products, StoreProduct/UpdateProduct/PatchProduct requests, `ProductResource`, importer) | **REQUIRES FOLLOW-UP** | The channel attribute RC-9 is about. **Untouched here by design** — repointing the grid is **Step 2**, gated on **PD-5** |
| Orders · Preparation · Procurement | **NOT AFFECTED** | No reference to `InventorySummary`, `InventorySummaryService` or `availability_state` |

**No second interpretation of availability was created.** The one place that could produce a rival
answer — `products.stock_status` — is untouched and explicitly documented in the enum as a different
fact.

---

# 5 — TESTS

`backend/tests/Feature/Inventory/AvailabilityStateDerivationTest.php` — **8 cases**.

| Required coverage | Test |
| --- | --- |
| 1. Available state | `test_positive_available_derives_in_stock` — on_hand 10, reserved 4 → available 6, `InStock` |
| 2. Unavailable state | `test_fully_reserved_stock_derives_out_of_stock` — 5/5 → available 0, `OutOfStock` |
| 3. Boundary — zero | `test_zero_available_is_out_of_stock_not_in_stock` |
| 3. Boundary — clamp-per-warehouse | `test_over_reserved_warehouse_does_not_drag_state_out_of_stock` — WH1 (2 on-hand, 10 reserved) clamps to 0, WH2 contributes 6 → `InStock`. **Under legacy sum-then-clamp this would read 0 and be wrong** |
| 4. Company / warehouse scope | `test_state_respects_company_scope` — foreign company → `Untracked`; `null` → global view, `InStock`. Warehouse coverage via the clamp case |
| 5. Existing behaviour unchanged | `test_existing_summary_fields_are_unchanged` — all nine pre-existing keys asserted present and correct |
| 6. Missing / null source data | `test_product_with_no_inventory_record_is_untracked` — distinguishes *no record* from *tracked but empty* |
| Additive construction | `test_dto_default_keeps_construction_additive` |

**No state was invented that the domain does not support** — in particular there is no `Shortage`,
`LowStock` or threshold-based case.

```
OK (8 tests, 28 assertions)   —   Time: 08:33.586
```

---

# 6 — VALIDATION

| Gate | Result |
| --- | --- |
| **Targeted PHPUnit** | ✅ `OK (8 tests, 28 assertions)` |
| **Relevant existing tests** — tenant isolation (Warehouse / Order / Supplier) | ✅ `OK (22 tests, 62 assertions)` earlier this session; unaffected by this change |
| **PHP lint — HOST PHP 8.4.22** | ✅ `No syntax errors detected` ×4 |
| **PHPStan** `phpstan.neon.dist` (level 0, platform) | ✅ `[OK] No errors` |
| **PHPStan** `phpstan-core.neon.dist` (level 6) | ✅ `[OK] No errors` |
| **Guardian pre-push** | ✅ **All 8 validators passed — `GUARDIAN_EXIT=0`** |
| **TypeScript baseline** | ✅ Guardian TypeScript PASS (99s) — no regression |
| **ESLint** | ✅ Guardian ESLint PASS (99s) |
| **i18n audit** | ✅ **Not required** — no frontend file changed, zero keys added or removed |
| `--no-verify` | ✅ Not used |
| `ecos-app` container used for verification | ✅ **No** — host PHP against the develop worktree throughout |

---

# 7 — REGRESSION ANALYSIS

| Risk | Assessment |
| --- | --- |
| **DTO signature change** | New parameter is **last and defaulted**. The only production construction site is the service itself; `test_dto_default_keeps_construction_additive` pins the default |
| **API response shape** | One key added. No key removed, renamed or retyped — asserted explicitly |
| **Existing quantity semantics** | `available` is now rounded once and reused rather than rounded inline. Same value, same precision (4 dp); pinned by `test_existing_summary_fields_are_unchanged` |
| **Second source of truth** | None introduced. The projection consumes the canonical figure and cannot disagree with it |
| **Performance** | No additional query. `$items` was already materialised; the derivation is an in-memory `match` |
| **`products.stock_status`** | Untouched. Both facts now coexist explicitly, which is the RC-9 design |
| **Console / queue paths** | `routes/console.php` reads only pre-existing fields |
| **Broader suite** | Not re-run in full. Guardian, both PHPStan configs and the targeted suites all pass; the change surface is three files with one additive DTO field |

---

# 8 — DECISION REGISTER UPDATE

Recorded additively: **Step 1 = COMPLETE**. **Step 3 remains BLOCKED** pending the Product/Governance
decision on cross-company product browsing (**GD-1**).

**Unaltered, as required:** OD-2 · GD-1 · RC-6 · D-8 · E-3 · E-5 · RC-10.

---

# 9 — STEP 1 FINAL STATUS

# ✅ COMPLETE

| Criterion | Result |
| --- | --- |
| Derivation established from existing code, not invented | ✅ `DemandAnalysisService:143-148` |
| Additive only — no destructive migration, no rename | ✅ |
| Existing records remain valid | ✅ No schema change |
| Authoritative state remains the source of truth | ✅ `InventorySummaryService` unchanged as owner |
| No duplicated business logic / no second engine | ✅ Projection inside the canonical service |
| Type-safe | ✅ Backed enum; both PHPStan configs clean |
| API contract preserved and documented | ✅ §3.1 |
| Behaviour + static validation + Guardian | ✅ All three |

---

# 10 — EXACT REMAINING PHASE 3 BLOCKERS

| Step | Blocked by |
| --- | --- |
| **Step 2** — repoint the ERP grid to derived availability | **PD-5** (risk-free to decide since E-3) |
| **Step 3** — reconcile products stats/list | **GD-1** — `stats` is always scoped to the authenticated company, `list` only when the caller supplies a filter. Reconciling means deciding whether cross-company product browsing is intended |
| **Steps 4–6** — RC-10 transition track | **PD-1** and **PD-2**. Must ship as **one release** |
| **Step 7** — remove V2 translation layers | **PD-2** |
| **Step 8** — close the human write path on `stock_status` | **PD-5** |

**Three owner decisions gate all remaining Phase 3 work: PD-1, PD-2, PD-5.**

**Step 1 is the last Phase 3 item that required no decision. Every remaining step is decision-gated.**

---

**Phase 3 is NOT complete — 1 of 8 steps done. Step 3 not implemented. RC-10 untouched: no
vocabulary, guard, transition or UI-control change. No Product/Governance decision was made.**
