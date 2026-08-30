# TASK-ENGINEERING-WORKTREE-CERTIFICATION-AND-COMMIT-AUDIT-001 — Engineering Report

**Date:** 2026-08-17 · **Type:** READ-ONLY AUDIT · **Worktree:** `C:\ecos-develop`

> ## 1. Executive Summary
>
> **Nothing was staged, committed, restored, reset, stashed, deployed, migrated or copied.
> The only write performed by this task is this report.**
>
> The tree holds **438 dirty paths** produced by **many concurrent sessions**, described by
> **113 uncommitted engineering reports**. It is emphatically **not** one commit.
>
> **One commit group is verified safe to make immediately** — Logistics two-segment
> permissions (2 files, CERTIFIED, self-contained, task ID cited in its own diff).
>
> Everything else falls into: certified-but-blocked (Orders), certified-verdict-but-file-set-
> unproven (3 candidates needing a focused follow-up), not-certified, or unattributed.
> **86 of 324 non-doc dirty files could not be attributed to any report** and are marked
> UNKNOWN rather than guessed at.

---

## 2–6. Tree snapshot (PART 1)

| Item | Value |
|---|---|
| **2. Current HEAD** | `ec43b4701054c3d3e18d4186073b57ceded19436` — *feat(reservation): ship the ADR-027 §16/§17 reservation chain as a self-contained unit* |
| **3. Branch** | `develop` |
| **4. Worktree / repo root** | `C:\ecos-develop` (one of **10** registered worktrees — see §21) |
| **5. Initial dirty count** (18:29:30Z) | **438** — staged 1 · modified 202 · untracked 240 |
| **6. Final dirty count** (18:32:53Z) | **438** — staged 1 · modified 202 · untracked 240 — **identical** |

Recent commits: `ec43b470`, `6149875b` (release: certify ECOS pilot go-live candidate),
`f0d7822a`, `d5f2b7e5`, `ba5e5914`.

Split: **324 non-doc files** (code/tests/migrations/config) + **114 doc paths**
(113 of them engineering reports).

## 7. Staged files (PART 2) — 1 file

| File | Status | Class | Verdict |
|---|---|---|---|
| `frontend/src/features/orders/components/order-reservation-cell.tsx` | `D` (deletion) | **A — this audit lineage's work** | Deleted during TASK-ORDERS-FINAL-INTEGRATION-AND-CERTIFICATION-CLOSURE-001 (PART 19 of that task) as proven-dead code holding a competing timestamp-derived reservation source. Last touched by commit `6cb3988f`. **LEFT STAGED, UNTOUCHED.** |

No file was unstaged, restaged, or "cleaned". The index was not modified.

## 8. Unstaged files — 202 · 9. Untracked files — 240

Domain distribution of all dirty paths:

| Domain | Count | Domain | Count |
|---|---|---|---|
| docs | 113 | Purchasing/* | 18 |
| backend/tests | 49 | Commerce/Orders | 18 |
| Operations/Preparation | 25 | features/orders (FE) | 14 |
| Logistics/Distribution | 20 | features/operations (FE) | 12 |
| i18n (FE) | 20 | CostManagement | 12 |
| Sales+Crm/Customers | 14 | Manufacturing/* | 14 |
| Operations/DemandAnalysis | 11 | Operations/Fulfillment | 7 |
| Inventory/* | 12 | Finance | 5 |

## 10. Task inventory (PART 3)

**113 uncommitted reports** in `docs/verification/`. Report locations actually used by ECOS:
`docs/verification/` (primary), plus `docs/adr/`, `docs/engineering/`, `docs/engineering-notes/`,
`docs/reports/`, `docs/releases/` and ~20 further domain folders.

Verdicts were read from each report's own final-verdict line. **Prose mentions of the word
"certified" were rejected as evidence** — only an explicit verdict statement counts.

## 11. GROUP A — CERTIFIED (verdict verified verbatim)

| Task | Verdict evidence |
|---|---|
| **TASK-LOGISTICS-PERMISSIONS-ENVIRONMENT-PARITY-REPAIR-001** | `# FINAL VERDICT: **CERTIFIED**` (line 3) |
| **TASK-PROCUREMENT-GOODS-INWARD-CONFIGURATION-UI-001** | `**FINAL VERDICT: CERTIFIED**` (lines 5 and 337) |
| **TASK-PROCUREMENT-INBOUND-SECURITY-AND-IDEMPOTENCY-REPAIR-001** | `**VERDICT: CERTIFIED** — OK (137 tests, 394 assertions)` under the T-6 gate |
| **TASK-INV-RAW-MATERIAL-POLICY-TOGGLE-REPAIR-001** | `> # INVENTORY POLICY TOGGLE = CERTIFIED` (line 348) |
| **TASK-ORDERS-FINAL-INTEGRATION-AND-CERTIFICATION-CLOSURE-001** | `> ## VERDICT: **CERTIFIED**` — all 20 gates incl. browser smoke |
| TASK-SUPPLIER-RETURN-VALUATION-001 · TASK-WAREHOUSE-COVERAGE-BRAND-ASSIGNMENT-001 · TASK-SHIPPING-DISTRIBUTION-API-COMPLETION-002 · TASK-SHIPPING-DISTRIBUTION-WORKSPACE-API-READ-MODEL-001 · TASK-PREPARATION-MATERIAL-DEMAND-CALCULATION-REPAIR-002 · TASK-IAM-PASSWORD-RESET-DOMAIN-OPERATION-001 · TASK-PRICING-REVIEW-SNOOZE-ASSIGN-HTTP-CONTRACT-REPAIR-001 · TASK-ORDER-CREATE-STATUS-INVALID-FIX-001 · TASK-GOLIVE-RECIPE-GATE-TENANT-REPAIR-001 | verdict line present; **file sets not established in this pass** — see §24 |

## 12. GROUP B — ENGINEERING COMPLETE / NOT RELEASE-CLOSED

TASK-PROCUREMENT-INBOUND-FINAL-REMAINING-REPAIRS-001 · TASK-PROCUREMENT-INBOUND-RECEIPT-CONCURRENCY-REPAIR-001 ·
TASK-PROCUREMENT-PURCHASE-MATERIAL-MYSQL-CAST-REPAIR-001 (all *"IMPLEMENTATION COMPLETE — FINAL CERTIFICATION DEFERRED"*) ·
TASK-ORDERS-AVAILABILITY-LIFECYCLE-COMPLETION-001 · TASK-ORDERS-LIFECYCLE-AVAILABILITY-RESERVATION-CLOSURE-001 ·
TASK-ORDERS-MATERIALS-STATUS-AND-SCHEDULE-POSITION-FIX-001 · TASK-DISASSEMBLY-RECIPE-COST-SNAPSHOT-AND-VALUATION-001 ·
TASK-SALES-CUSTOMERS-POST-360-HARDENING-001 · TASK-SALES-CUSTOMERS-WORKSPACE-360-ENHANCEMENTS-001 ·
TASK-CUSTOMER-360-ENHANCEMENTS-001 · TASK-CUSTOMERS-CUSTOMER-360-DATA-LINKAGE-001 ·
TASK-PREPARATION-DEMAND-PREPARED-EXPORT-PRINT · TASK-PREPARATION-WAVE-WORKSPACE-FRONTEND-COMPLETION.

**None of these may enter a certified commit batch (PART 16).**

## 13. GROUP C — NOT CERTIFIED (explicit)

TASK-CUSTOMER-DOMAIN-FINAL-CLOSURE-001 · TASK-CUSTOMER-360-FRONTEND-VISIBILITY-DIAGNOSTIC-001 ·
TASK-IAM-TENANT-AUTHORIZATION-BOUNDARY-IMPLEMENTATION-001 · TASK-IAM-PRECONDITION-TEST-ENV-UNBLOCK-002 ·
TASK-INV-NEGATIVE-STOCK-SEMANTICS-AND-RESERVATION-001 (*business decision required*) ·
TASK-INVENTORY-NEGATIVE-STOCK-CURRENT-STATE-DIAGNOSTIC-002 · TASK-LOGISTICS-SHIPPING-FULL-STACK-AUDIT-001 ·
TASK-PROCUREMENT-DOMAIN-CLOSURE-AUDIT-001 (*4 STOP conditions*) · TASK-PROCUREMENT-FINANCE-INTEGRATION-CLOSURE-001 (*2 STOP*) ·
TASK-PROCUREMENT-FINANCE-VAT-AND-PRICE-VARIANCE-CLOSURE-001 · TASK-PROCUREMENT-INBOUND-OWNERSHIP-CLOSURE-001 ·
TASK-PROCUREMENT-RECEIVING-APPROVAL-INVOICE-AMENDMENT-IMPLEMENTATION-001 ·
TASK-PROCUREMENT-RECEIVING-INSPECTION-INVOICE-AMENDMENT-001 ·
TASK-PREPARATION-WAVE-ORDERS-WORKSPACE-REFINEMENT-001 / -002 · TASK-PREPARATION-WAVE-CROSS-DAY-TRANSITION-001 ·
TASK-PRICING-REVIEW-COST-MANAGEMENT-CERTIFICATION-CLOSURE-001 / FINAL-CERTIFICATION-001 ·
TASK-PRICE-REVIEW-ACTION-REGRESSION-DIAGNOSTIC-001 · TASK-ORDERS-ADR027-RESERVATION-CHAIN-CLOSURE-001 ·
TASK-ORDERS-AVAILABILITY-LIFECYCLE-REPAIR-001 · TASK-ORDERS-INVENTORY-EXECUTION-LIFECYCLE-REPAIR-001 (*E2E pending*).

## 14. GROUP D — BLOCKED

| Task | Blocker |
|---|---|
| **TASK-ORDERS-RELEASE-INTEGRATION-AND-PRODUCTION-CLOSURE-001** | `OrderServiceProvider.php` irreducibly mixed with the Wave carry-over chain — §22 |
| TASK-ENV-DUAL-STACK-DEV-ISOLATION-001 | `[BLOCKED]` |
| TASK-IAM-HTTP-SURFACE-001-CONTRACT-AUDIT · TASK-IAM-PRECONDITION-TEST-ENV-UNBLOCK-001 | `BLOCKED` |
| TASK-ORDER-INVENTORY-FULFILLMENT-CONTRACT-RECOVERY-001 | `RECOVERY BLOCKED — BUSINESS DECISION` |
| TASK-GOLIVE-PREPARATION-CERTIFICATION-BOUNDARY-REBASE-001 | `Blocked` |
| TASK-PROCUREMENT-FINANCE-INTEGRATION-AUTHORITY-DECISION-001 | authority decision outstanding |
| TASK-SHIPPING-DISTRIBUTION-WORKSPACE-API-COMPLETION-001 / -UI-001 | `STOPPED — STOP CONDITION` |

## 15. GROUP E — ALREADY COMMITTED (do not re-propose)

| Work | Commit | Note |
|---|---|---|
| ADR-027 §16/§17 reservation chain — `ReserveOrderInventoryAction`, `ReconcileOrderMaterialReservationsAction`, `ReserveStockAction`, `InventoryItem::availableQty()`, `ManufacturingAvailabilityService` (§16.4 tenant scoping) | **`ec43b470`** | Verified **clean in the tree** — these 5 files are *not* dirty and need no commit |
| ECOS pilot go-live candidate | `6149875b` | |
| Guardian quality-gate ratchet | `d5f2b7e5`, `f0d7822a` | |
| Platform Foundation EPIC-1 | `6cb3988f` | origin of the now-deleted `order-reservation-cell.tsx` |

## 16. GROUP F — UNKNOWN / UNATTRIBUTED — **86 files**

Of 324 non-doc dirty files, **238 are named by at least one report** and **86 by none**.
Per PART 4 these are **not** promoted to any commit candidate. Attribution by "a report
mentions this filename" is itself weak evidence and was **not** treated as proof of ownership
(see the false positive in §17).

## 17. File-level attribution (PART 5) — method and confidence

Evidence hierarchy actually applied:

1. **PROOF** — the file's own diff cites a task ID, *and* the task's report carries an explicit
   CERTIFIED verdict. (Only `config/permissions.php` reached this bar.)
2. **STRONG** — file named in a certified report's file list *and* dirty *and* not claimed by
   another uncommitted task.
3. **WEAK — rejected** — a report merely mentions the path.

**Demonstrated false positive:** the certified Logistics permissions report's text yielded
`frontend/src/features/orders/components/order-reservation-cell.tsx`, a file that has nothing to
do with Logistics permissions. This is exactly the trap PART 5 warns against, and it is why
mention-based attribution was not used to build any commit group.

| File | Task | Certification | Dependency | Proposed Group | Safe to Commit |
|---|---|---|---|---|---|
| `backend/config/permissions.php` | LOGISTICS-PERMISSIONS-ENVIRONMENT-PARITY-REPAIR-001 | **CERTIFIED** | RbacSeeder (committed) | **G1** | **YES** |
| `backend/Modules/IAM/.../2026_12_24_000000_restore_logistics_two_segment_permissions.php` | idem | **CERTIFIED** | none | **G1** | **YES** |
| `frontend/.../order-reservation-cell.tsx` *(staged D)* | ORDERS-FINAL-CERTIFICATION-CLOSURE-001 | **CERTIFIED** | none | G2 (Orders) | **NO — blocked with G2** |
| `Modules/Commerce/Orders/Infrastructure/Providers/OrderServiceProvider.php` | Orders + Wave carry-over | CERTIFIED **+ uncertified other task** | HandlePreparationWaveClosed → OrderPreparationCompletionReader | **MIXED** | **NO** |
| `Modules/Commerce/Orders/Domain/Enums/OrderStatus.php` | ADR-042 cascade | CERTIFIED (Gate T) | 16-file cascade | G2 | NO — blocked by G2 |
| `Modules/Commerce/Orders/Presentation/Http/Requests/StoreManualOrderRequest.php` | ADR-042 / Orders | CERTIFIED (Gate T) | OrderStatus | G2 | NO — blocked by G2 |
| `Modules/Operations/Fulfillment/Application/Workflows/ProcessOrderWorkflow.php` | Orders availability | CERTIFIED (Gate T) | OrderStatus | G2 | NO — blocked by G2 |
| `Modules/Commerce/Orders/Application/Listeners/RetryReservationOnStockAvailableListener.php` | Orders recovery | CERTIFIED (Gate T) | OrderServiceProvider | G2 | NO — blocked by G2 |
| `Modules/Operations/DemandAnalysis/.../DemandAnalysisService.php` | ADR-042 cascade | CERTIFIED (Gate T) | OrderStatus | G2 | NO — blocked by G2 |
| `backend/routes/api.php` | Distribution + Wave + Supplier | **not certified** | — | none | **NO** |
| `backend/config/distribution.php` *(new)* | Distribution | not certified | — | none | **NO** |
| `Modules/Purchasing/SupplierInvoices/*` (4 files) | invoice/receipt anchor | **NOT CERTIFIED** + **actively being written** | — | none | **NO — DO NOT TOUCH** |
| 86 further non-doc files | — | **UNKNOWN** | — | none | **NO** |

Full per-file attribution for all 324 non-doc paths was **not** completed to PROOF standard in
this single pass; §24 states what a follow-up needs.

## 18. Migration inventory (PART 10) — none applied, none modified

| Migration | Task | Cert | Dev | Target `ecos_erp` | Safe? |
|---|---|---|---|---|---|
| `IAM/.../2026_12_24_000000_restore_logistics_two_segment_permissions.php` *(new)* | Logistics permissions | **CERTIFIED** | — | pending | **YES — G1** |
| `Orders/.../2026_08_13_100000_supersede_order_lifecycle_v3_canonical.php` *(new)* | ADR-042 | CERTIFIED (Gate T) | **applied** | **pending** | with G2 only |
| `Preparation/.../2026_08_13_100000_add_postponed_at_to_preparation_wave_orders.php` *(new)* | Wave | not certified | — | pending | NO |
| `Preparation/.../2026_08_15_100002_add_membership_release_to_preparation_wave_orders.php` *(new)* | Wave | not certified | — | pending | NO |
| `Distribution/.../2026_08_11_100003_create_distribution_window_orders_table.php` *(new)* | Distribution | not certified | — | pending | NO |
| `BillsOfMaterials/.../2026_08_14_100000_create_recipe_cost_snapshots.php` *(new)* | Recipe snapshots | Group B | — | pending | NO |
| `SupplierInvoices/.../2026_08_17_120000_add_goods_receipt_line_anchor_to_supplier_invoice_lines.php` *(new)* | invoice anchor | NOT CERTIFIED · **drifting** | — | pending | **NO** |

## 19. Frontend inventory (PART 11) — not rebuilt, not deployed, not modified

| Area | Files | Task | Browser-verified? | Safe? |
|---|---|---|---|---|
| `features/orders` — `order-inventory-execution-cell`, `order-detail-drawer`, `order-detail-page`, `i18n en/ar orders.json`, deletion of `order-reservation-cell` | 6 | Orders certification | **YES — Gate T browser smoke** | with G2 only |
| `features/orders` — `manual-order-form`, `order-form-schema`, `order-header-fields`, `order-inventory-status-card`, `order-list-toolbar`, `order-status-badge`, `product-browser`, `use-order-labels`, `use-orders`, `types/order.ts` | 10 | mixed Orders-adjacent tasks | partially | **NO — unproven attribution** |
| `features/operations` (12), `features/raw-materials` (8), `features/admin` (6), `features/products` (5), `features/suppliers` (4), CRM/customers (6) | 41 | various | mostly no | **NO** |
| `i18n` (20 locale files) | 20 | many tasks share these files | — | **MIXED — high risk** |

## 20. Dependency graph (PART 9)

```
COMMITTED FOUNDATION (ec43b470)
  ReserveStockAction · InventoryItem::availableQty() · ManufacturingAvailabilityService(§16.4)
  ReserveOrderInventoryAction · ReconcileOrderMaterialReservationsAction
        ▲  (clean — no Inventory file needs committing)
        │
G2 ORDERS (certified, BLOCKED)
  OrderStatus ──► 16-file ADR-042 cascade (incl. Operations/DemandAnalysis, Synchronization)
  ProcessOrderWorkflow · CreateManualOrderAction · StoreManualOrderRequest · OrderResource
  RetryReservationOnStockAvailableListener · ExecuteReservationOnWarehouseAssigned
  OrderServiceProvider ──► HandlePreparationWaveClosed ──► OrderPreparationCompletionReader
                      └─► ReprocessLegacyReservationsCommand      ▲ CROSS-CHANGESET DEPENDENCY
  migration supersede_order_lifecycle_v3_canonical
  frontend (6 files, browser-verified)

G1 LOGISTICS PERMISSIONS (certified, INDEPENDENT)
  config/permissions.php + IAM migration      ← no dependency on G2 or anything dirty
```

**CROSS-CHANGESET DEPENDENCY (exact files):**
`OrderServiceProvider.php` → `HandlePreparationWaveClosed.php` (untracked) →
`OrderPreparationCompletionReader.php` (untracked, `Modules/Operations/DemandAnalysis`).
`ReturnToProcessingWorkflow.php` is tracked and clean, so it is **not** a blocker.

## 21. Concurrent drift (PART 12)

| Measure | Snapshot 1 (18:29:30Z) | Snapshot 2 (18:32:53Z) | Drift |
|---|---|---|---|
| dirty / staged / modified / untracked | 438 / 1 / 202 / 240 | 438 / 1 / 202 / 240 | **none** |
| HEAD | `ec43b470` | `ec43b470` | none |

Counts were stable **during** the audit window, but the tree is demonstrably **live**:
`Modules/Purchasing/SupplierInvoices/*` (4 files incl. a new migration) were written by another
session shortly before snapshot 1. **Marked CONCURRENTLY DRIFTING — do not attribute, do not stage.**

The repository also has **10 registered worktrees**, including `C:\Projects\ECOS-ERP`
(`platform-foundation`), `C:\ecos-bt` (`main`) and 7 agent worktrees. Other agents are working
outside this worktree.

## 22. Mixed files (PART 17) — manual split required

| File | Contains | Action |
|---|---|---|
| `Modules/Commerce/Orders/Infrastructure/Providers/OrderServiceProvider.php` | **Certified** Orders recovery subscriptions **+** uncertified Wave carry-over registration + `ReprocessLegacyReservationsCommand` | **MIXED FILE — MANUAL SPLIT REQUIRED.** Not modified, not split, not checked out |
| `frontend/src/i18n/locales/{en,ar}/*.json` | many tasks add keys to shared locale files | **MIXED — verify per key before any commit** |
| `backend/routes/api.php` | Distribution (14) + Wave (8) + Supplier (1) + Preparation (1) routes; **zero Orders routes** | MIXED across ≥3 uncertified tasks |

## 23. Safe commit groups (PART 13)

### COMMIT GROUP 1 — Logistics two-segment permissions ✅ **SAFE NOW**

| | |
|---|---|
| **Task** | TASK-LOGISTICS-PERMISSIONS-ENVIRONMENT-PARITY-REPAIR-001 |
| **Status** | **CERTIFIED** (`# FINAL VERDICT: **CERTIFIED**`) |
| **Files** | `backend/config/permissions.php` *(M)*<br>`backend/Modules/IAM/Infrastructure/Database/Migrations/2026_12_24_000000_restore_logistics_two_segment_permissions.php` *(??)* |
| **Migration** | the one above — additive, grants only |
| **Dependencies** | RbacSeeder — **already committed**; no dirty dependency |
| **Evidence** | The diff **cites the task ID in its own code comments** (twice). Every added line falls inside the two Logistics permission blocks — verified: **zero** added lines outside them. No other uncommitted task claims `permissions.php` |
| **Safe?** | **YES** |

### COMMIT GROUP 2 — Orders certified lifecycle 🔴 **CERTIFIED — COMMIT CANDIDATE, BLOCKED** (PART 15)

| | |
|---|---|
| **Task** | ORDERS-AVAILABILITY-LIFECYCLE-COMPLETION-001 + LIFECYCLE-AVAILABILITY-RESERVATION-CLOSURE-001 + FINAL-INTEGRATION-AND-CERTIFICATION-CLOSURE-001 |
| **Status** | **CERTIFIED** — all 20 gates incl. real browser smoke |
| **Files** | ~40: 9 Orders/Fulfillment production + the 16-file ADR-042 cascade + `StoreManualOrderRequest` + `WooCommerceOrderStatusTranslator` + 1 migration + 6 frontend + 3 test files |
| **Blocker** | `OrderServiceProvider.php` is a MIXED FILE (§22) |
| **Safe?** | **NO — until the mixed file is resolved** |

## 24. Unsafe commit groups

| Group | Why not |
|---|---|
| Procurement inbound security & idempotency · Goods inward configuration UI · Inventory raw-material policy toggle | **CERTIFIED verdicts confirmed**, but their exact file sets were **not** established to PROOF standard here. `TASK-PROCUREMENT-GOODS-INWARD-CONFIGURATION-UI-001` yielded **no** parseable file list. A focused per-task follow-up is required before any of these becomes a commit group |
| All Group B/C/D tasks | PART 16 — not certified, regardless of how complete |
| The 86 unattributed files | PART 4 — Group F may not be promoted |
| Purchasing/SupplierInvoices | actively drifting |

## 25. Exact files per commit group

**G1 (2 files)** — listed verbatim in §23.
**G2 (~40 files)** — enumerated in the manifest of
`TASK-ORDERS-RELEASE-INTEGRATION-AND-PRODUCTION-CLOSURE-001-ENGINEERING-REPORT.md` §3; not
repeated here to avoid two divergent copies of one list.

## 26. Required dependencies per group

| Group | Production | DB | Shared | Frontend |
|---|---|---|---|---|
| **G1** | none dirty | 1 additive migration | RbacSeeder (committed) | none |
| **G2** | 16-file ADR-042 cascade + wave chain (blocker) | 1 migration (pending in target) | `ec43b470` foundation (committed) | 6 files (browser-verified) |

## 27. Known bugs found — NOT fixed (PART 19)

| # | Bug | File | Evidence | Certification impact | Proposed task |
|---|---|---|---|---|---|
| 1 | Manufacturing evaluation is **unreachable** | `Modules/Operations/OrderLifecycle/Application/Handlers/ManufacturingLifecycleHandler.php:47-51` | whitelists `pending/processing/preparing`; none survives ADR-042. `PrepareOrderAction` sets `ready_for_dispatch` before evaluation | **None on Orders** — off the certified chain (legacy `POST /orders/{order}/prepare`, zero frontend callers) | `TASK-MANUFACTURING-LIFECYCLE-STATUS-VOCABULARY-REPAIR-001` |
| 2 | Snapshot consistency does not reject a mismatched subtotal | `CreateOrderSnapshotService` + `IntegrityEngine` (both committed, clean) | `OrderFinancialSnapshotTest::…mismatched_subtotal` fails | none | `TASK-ORDERS-SNAPSHOT-CONSISTENCY-REPAIR-001` |
| 3 | Unpaid orders hold reservations with **no timeout** | `CreateManualOrderAction` + `OrderStatus::decidesAvailabilityAtCreation()` | contract-mandated; only cancellation releases | none — intended | owner decision |
| 4 | `CreateOrderAction` (`POST /orders` + POS) takes **no** availability decision | `Modules/Commerce/Orders/Application/Actions/CreateOrderAction.php` | POS already issues stock via `DirectIssueStockAction`; adding reservation would double-commit | none | needs a business decision first |
| 5 | Test-runner fatal | `tests/Feature/Purchasing/GoodsReceiptConcurrencyTest.php` | `Access level to ::post() must be public` — aborts any run loading it | blocks suite runs | belongs to the drifting Purchasing session |

**No bug was fixed. No code was touched.**

## 28. Unresolved work (PART 8)

Orders: release/integration closure (blocked). Procurement: invoice/receipt deterministic anchor
(uncertified, drifting), invoice amendment workflow (NOT CERTIFIED ×2), GRNI/PPV + VAT/price-
variance closure (NOT CERTIFIED), domain closure audit (4 STOP conditions). Logistics: full-stack
shipping audit (NOT CERTIFIED); distribution workspace API/UI (STOPPED). Preparation/Wave: cross-day
transition -001 (blocked), workspace refinement -001/-002 (NOT CERTIFIED). IAM: tenant authorization
boundary (NOT CERTIFIED), HTTP surface (BLOCKED). Inventory: negative-stock semantics (business
decision required). Customers/CRM: domain final closure (NOT CERTIFIED).

## 29. Recommended commit order (PART 22)

Derived from the actual graph, not a template:

1. **G1 — Logistics permissions.** Zero dependencies on anything dirty; independent of G2. **Commit now.**
2. **Resolve the G2 mixed file** — the Wave task's owner commits `HandlePreparationWaveClosed` +
   `OrderPreparationCompletionReader` + `ReprocessLegacyReservationsCommand`, *or* the owner
   authorises shipping them inside G2.
3. **G2 — Orders certified lifecycle**, after re-running its pre-commit gate.
4. **Per-task follow-up audits** for the three certified Procurement/Inventory candidates (§24),
   each producing a proven file set before its own commit.
5. Everything else stays uncommitted pending its own certification.

## 30. Final audit verdict — PART 23 answered directly

| # | Question | Answer |
|---|---|---|
| 1 | How many dirty files exist? | **438** (324 non-doc + 114 doc) |
| 2 | How many are certified? | **2 proven safe** (G1) + **~40 certified-but-blocked** (G2) |
| 3 | How many are not certified? | The large majority — Groups B/C/D across ≥10 domains |
| 4 | How many are unknown? | **86** non-doc files attributable to no report |
| 5 | How many are safe commit candidates **now**? | **2** — G1 only |
| 6 | Which belong to other sessions? | Purchasing/SupplierInvoices (drifting), Preparation/Wave (25), Distribution (20), CRM/Customers (14), DemandAnalysis, CostManagement, Finance, and the 86 unattributed |
| 7 | Which commits can be made immediately? | **G1 only** |
| 8 | Which require another task first? | G2 (mixed-file resolution); the 3 Procurement/Inventory candidates (file-set proof) |
| 9 | Which are blocked by dependencies? | G2 — `OrderServiceProvider` → wave chain |
| 10 | Which must NOT be touched? | The staged deletion (leave staged); `Purchasing/SupplierInvoices/*`; `routes/api.php`; `config/distribution.php`; shared i18n locale files; all 86 unattributed files |

> ### AUDIT COMPLETE — **1 SAFE COMMIT GROUP IDENTIFIED (2 files)**
>
> The single highest-value finding is that **one certified change is cleanly separable today**,
> and that the Orders release — the largest certified body of work in the tree — remains gated
> on exactly one mixed file, not on engineering.
>
> A second finding worth recording: this audit **corrected an earlier misattribution of its own
> lineage.** `TASK-ORDERS-RELEASE-INTEGRATION-AND-PRODUCTION-CLOSURE-001` classified
> `backend/config/permissions.php` as "Distribution/other-session configuration". It is in fact
> the certified Logistics permissions repair — proven by the task ID in its own diff.

**Nothing was staged, unstaged, committed, reset, restored, stashed, checked out, deployed,
migrated, copied into a container, rebuilt, or deleted. No production code, test, migration or
configuration file was modified. The only write was this report.**
