# TASK-PHASE3-GD2-STEP2-CLOSE-001 — Engineering Report

**Date:** 2026-08-09 · **Worktree:** `develop` @ `C:\ecos-develop` · Host PHP 8.4.22

| | |
| --- | --- |
| **GD-2 (narrow question)** | ✅ **RESOLVED** from existing behaviour |
| **Step 2 frontend** | ✅ **COMPLETE** — duplicate availability engine removed |
| **Write-path regression tests** | ⚠️ **WRITTEN, NOT EXECUTED GREEN** — §5 |
| **Guardian** | ✅ **PASS — `GUARDIAN_EXIT=0`**, TypeScript back to baseline **24** |

---

# 1 — GD-2 EVIDENCE

| # | Source | Finding |
| --- | --- | --- |
| 1 | **`ManufacturingAvailabilityService:13-14, 80`** | *"A material is considered available when: `available_qty > 0` **OR** `allow_negative_stock = true`"* — `$isAvailable = $available > 0.0 \|\| $material->allow_negative_stock;` |
| 2 | Same service | Exposes this under a **separate name**: `manufacturing_availability`, its own field on `ProductResource:160` |
| 3 | `ReserveOrderInventoryAction:157-162` | `allow_negative_stock` permits **reservation** past available |
| 4 | `InventoryMutationAdapter:26,52` | Permits `on_hand_qty` to **go negative** during consumption |
| 5 | `ComponentConsumptionPlan:14-15` | `will_go_negative` (accepted risk) vs `is_blocked` (hard blocker) |
| 6 | `WorkflowBlockingReason:36` | Blocking only when insufficient **and** `allow_negative_stock = false` |

## Answers to the seven questions

| # | Question | Answer |
| --- | --- | --- |
| 1 | Means "may be sold/prepared/reserved at zero/negative"? | **Yes** — at the point of action (3, 5, 6) |
| 2 | Or only "ledger may go negative"? | **Also yes** (4), but not limited to it |
| 3 | Does it change product **availability**? | **No** — it changes *permission to proceed*, not the measured quantity |
| 4 | Reservation eligibility? | **Yes** (3) |
| 5 | Order acceptance? | **Yes, indirectly** — via reservation |
| 6 | Preparation eligibility? | **Yes, indirectly** — `MoveToPreparationWorkflow` auto-reserves |
| 7 | Existing authoritative service distinguishing these? | **Yes — `ManufacturingAvailabilityService`**, and the distinction already has its own field |

---

# 2 — GD-2 RESOLUTION

**Resolved at engineering level from existing behaviour. No new business rule invented.**

> **`allow_negative_stock` is a permission to PROCEED despite unavailability, applied at the point of
> action (reserve / manufacture / consume). It does not change what the warehouse physically holds,
> and therefore must not change measured availability in a stock column.**

The platform already separates the two concepts and gives each its own field:

| Concept | Field | Rule |
| --- | --- | --- |
| *What do we physically have?* | `availability_state` | `available <= 0 → OutOfStock` — ignores `allow_negative_stock` |
| *May we proceed anyway?* | `manufacturing_availability` | `available > 0 OR allow_negative_stock` |

The frontend util **conflated** them. The backend never did.

**Scope note — this resolves only the display-semantics question.** The broader GD-2 governance items
(who may toggle `Allow Negative`, its default, Units editability, Categories ownership) are **untouched
owner decisions** and remain open under the tenant-2 gate.

---

# 3 — STEP 2 FRONTEND IMPLEMENTATION

| File | Change |
| --- | --- |
| `utils/material-stock-status.ts` | **Rule deleted.** Now presents the server's `availability_state`; documents the GD-2 resolution |
| `types/index.ts` | New `AvailabilityState` type; `availability_state` added to **`RawMaterial`**; `stock_status` relabelled in-code as the channel attribute |
| `pages/raw-materials-page.tsx:59` | CSV export consumes `availability_state` |
| `components/raw-material-table.tsx:280` | Grid consumes `availability_state` |
| `components/raw-material-detail-drawer.tsx:61,399,1003` | `stockStatusConfig` takes the canonical state; **two** call sites updated |

**No new label was needed** — the existing `inStock` / `outOfStock` keys already exist in EN and AR, so
zero keys were added or removed and no hardcoded string was introduced. `untracked` collapses to
`out_of_stock` for this binary column; the richer state remains on the API.

---

# 4 — DUPLICATE AVAILABILITY AUDIT

| Consumer | Classification |
| --- | --- |
| `AvailabilityState::fromAvailable()` | **Canonical** — the single rule |
| `InventorySummaryService` · `ProductResource` | **Canonical** — both delegate |
| `resolveMaterialStockStatus()` | **Canonical** — now a presenter, no rule |
| `ManufacturingAvailabilityService` | **Unrelated (by design)** — answers *may we proceed*, correctly uses `allow_negative_stock` |
| `products.stock_status` · importer · `ProductController::import()` | **Channel-specific** — retained |
| `DemandAnalysisService:143-148` | **Requires follow-up** — its own status ladder over `on_hand_qty`; demand-relative, out of Step 2 scope. Not refactored |

**No second Raw Materials availability calculation remains that can disagree with the canonical rule.**

---

# 5 — WRITE-PATH REGRESSION TESTS — ⚠️ NOT CERTIFIED

`tests/Feature/Inventory/ProductStockStatusWritePathTest.php` — 7 cases asserting `stock_status` is
absent from all three human `rules()` sets, that other fields survive, and that `import()` and the
WooCommerce importer still carry the field.

**They were not executed green.** The one run of `tests/Feature/Inventory` reported
**`Tests: 166, Assertions: 385, Failures: 3`** — all three in **`InventoryCountSessionTest`** (FIFO
quantities `7 vs 10`, `8 vs 10`, and a null ledger entry), none in the new file. I could not confirm
the new tests pass in isolation, and **the parent-commit control for those 3 failures was not
completed** (§7).

**This is recorded as not certified rather than assumed passing.**

---

# 6 — VALIDATION MATRIX

| Gate | Result |
| --- | --- |
| **Guardian pre-push** | ✅ **PASS — `GUARDIAN_EXIT=0`** (all 8 validators) |
| PHP Syntax · Pint · PHPStan (via Guardian) | ✅ PASS |
| **TypeScript** | ✅ PASS — **baseline 24, restored** after a regression I introduced (§7) |
| ESLint | ✅ PASS |
| Vite production build | ✅ PASS |
| i18n missing keys | ✅ **0** — no key added or removed |
| EN/AR parity | ✅ Held — reused existing keys |
| RTL-unsafe additions | ✅ **0** — no new class names |
| Targeted PHPUnit (new write-path tests) | ⚠️ **Not certified** — §5 |
| `--no-verify` / container PHP / suppressions | ✅ None used |

---

# 7 — TWO ERRORS I MADE, AND THEIR RESOLUTION

**Recorded because both had real blast radius.**

**(a) I reverted more than intended.** To run the control for §5 I ran `git checkout -- backend/`,
which reverted **every uncommitted backend change** — RC-6, D-8, Step 1, Step 2 backend and Step 8 —
not just this task's. I had taken a full `git diff HEAD` patch (26,130 bytes) seconds earlier, so I
restored with `git apply --include='backend/*'` and verified: 11 backend files present,
`TenantOwnershipResolver` referenced 3× each in Warehouse/Order/Supplier, `availability_state` present
in `ProductResource` and `InventorySummary`, `stock_status` absent from `PatchProductRequest`.
**Nothing was lost, and the control was abandoned rather than retried.**

**(b) I introduced a TypeScript regression: 24 → 28.** I added `availability_state` to
**`RawMaterialPayload`** instead of **`RawMaterial`**, and missed a second `stockStatusConfig` call
site at `raw-material-detail-drawer.tsx:1003`. Guardian caught both. Fixed at source — **no baseline
was normalized and no suppression was added.** TypeScript is back to **24**.

---

# 8 — DECISION REGISTER UPDATE

- **GD-2 (display semantics) = RESOLVED** — engineering resolution; evidence in §1
- **GD-2 (governance items)** = still **OWNER DECISION REQUIRED**, tenant-2 gate
- **Step 2 = COMPLETE** (backend + frontend; Guardian PASS)
- **Step 8 = COMPLETE**; write-path tests **written, not certified**
- Step 3 = **BLOCKED** (GD-1) · Steps 4–7 = **BLOCKED** (PD-1 + PD-2) · RC-10 = **BLOCKED**

---

# 9 — CURRENT PHASE 3 STATUS

| Step | Status |
| --- | --- |
| 1 — derive `availability_state` | ✅ COMPLETE |
| 2 — repoint availability presentation | ✅ **COMPLETE** |
| 8 — close human write path | ✅ COMPLETE *(tests not certified)* |
| 3 — products stats/list | ⛔ BLOCKED — GD-1 |
| 4–6 — RC-10 transition track | ⛔ BLOCKED — PD-1 + PD-2, one release |
| 7 — remove V2 translation layers | ⛔ BLOCKED — PD-2 |

**3 of 8 steps complete. Phase 3 is NOT complete.**

---

# 10 — EXACT REMAINING BLOCKERS

| # | Item | Owner |
| --- | --- | --- |
| 1 | **PD-1** — transition preconditions | Business Ops + Sales |
| 2 | **PD-2** — lifecycle vocabulary | Product + Business Ops |
| 3 | **GD-1** — cross-company product population | Exec + Product + Arch |
| 4 | *(engineering)* Certify the write-path tests + control the 3 `InventoryCountSessionTest` failures at the parent commit | — |

---

**No Step 3. No RC-10 work. PD-5, Step 1, Step 8, RC-6, D-8, E-3, E-5 and OD-2 not reopened. No
destructive migration, no data mutation, no `--no-verify`, no suppression, no deployment.**
