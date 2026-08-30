# TASK-PROC-PURCHASING-PHASE2-PART1-RECEIVING-BROWSER-ACCEPTANCE-001 — FINAL REPORT

**Date:** 2026-08-21
**Scope:** Browser Acceptance for Purchase Material receiving (PM-00002)
**Verdict:** **BACKEND COMPLETE / BROWSER NOT VERIFIED** — see §13 and §15
**Commit:** none

---

## 1. Pre-state

Captured before any action. Nothing in this task modified any of it.

| Table | Count |
|---|---|
| `goods_receipts` | **0** |
| `goods_receipt_lines` | **0** |
| `purchase_orders` | **0** |
| `purchase_order_lines` | **0** |
| `inventory_items` | 5 |
| `stock_ledger_entries` | 22 |
| `inventory_receipt_layers` | 2 |

`companies.goods_inward_mode` = `goods_receipt` (GoodsInwardAuthority active, unchanged).

Note: there are **zero Purchase Orders in the entire system**, which makes the §10 "PO untouched"
assertion trivially and completely verifiable.

## 2. PM-00002 state

| Field | Value |
|---|---|
| Purchase Material ID | `01a01831-25e2-7229-b50d-dd4c75af6a1c` |
| Number / status | `PM-00002` / `approved` |
| Company | `019f4e1c-2d1e-719d-873c-75779ab67251` |
| Warehouse | `019f4e1c-2e1b-7269-bfbb-8a414cb07cab` (Main Warehouse) |
| Line ID | `01a01831-25f8-71ab-b187-cb214264c6d2` |
| Product | `PKG-JAR-250` — Glass Jar 250ml |
| `requested_qty` | `100.0000` |
| `agreed_qty` | `NULL` |
| `agreed_price` | `NULL` |
| GR lines anchored to this line | **0** |

## 3. Supplier state — PASS

`purchase_material_lines.supplier_id` = `01a020ee-f7ec-7081-90d8-c9d0dfa15f55`
→ **398830 — OSAMA FAYEZ AHEMD**, same company as the Purchase Material.

RD-1 is satisfied: supplier identity is present at LINE level, persisted, and survived reload
(established and re-verified in TASK-PROC-PURCHASING-SUPPLIER-SELECTION-FIX-001).

## 4. Browser walkthrough — **BLOCKER**

Steps 1–3 were performed and passed in the preceding task (PM-00002 opened, Supplier tab rendered
`398830 – OSAMA FAYEZ AHEMD`, and the binding survived a full page reload).

**Steps 4–10 could not be performed. There is no UI path from a Purchase Material to a Goods
Receipt.** This is a missing-surface blocker, not a data problem and not an environment problem.

### Evidence

**The backend accepts a Purchase-Material anchor** — `StoreGoodsReceiptRequest`:

```php
'purchase_order_id'                => ['nullable', 'required_without:lines.0.purchase_material_line_id', 'uuid', 'exists:purchase_orders,id'],
'lines.*.purchase_order_line_id'   => ['nullable', 'required_without:lines.*.purchase_material_line_id', 'uuid', 'exists:purchase_order_lines,id'],
'lines.*.purchase_material_line_id'=> [ ... 'required_without:lines.*.purchase_order_line_id', ... ],
```

**The frontend cannot send one** — `goods-receipt-form-schema.ts` still hard-requires the PO:

```ts
purchase_order_id:      z.string().min(1, 'Purchase order is required.'),   // REQUIRED
purchase_order_line_id: z.string().min(1, 'PO line is required.'),          // REQUIRED
```

Two exhaustive sweeps confirm the gap:

| Sweep | Result |
|---|---|
| `purchase_material` \| `purchaseMaterial` in `features/goods-receipts/**` | **0 matches** |
| `purchase_material_line_id` \| `purchaseMaterialLineId` in **all** of `frontend/src` | **0 matches** |
| receiving entry point in `features/purchase-materials/**` | **none** — only status labels/KPIs named "receiving" |

The Receiving Center (`/purchasing/receiving`, `receiving-center-page.tsx`) lists Goods Receipts and
exposes the approved **"Confirm Receipt"** action (`receiving-center.json` → `"post": "Confirm Receipt"`;
terminology preserved, not renamed). But receipts can only be *created* through
`create-goods-receipt-page.tsx`, which sources its anchor from `use-approved-po-options.ts` — a
Purchase **Order** picker. With zero Purchase Orders in the system, that form cannot be completed
at all, and even with one it could not anchor to PM-00002.

**Conclusion:** Phase 2 Part 1 delivered the receiving **foundation** (schema, service, validation,
posting path) exactly as its name states. The **UI surface that would let an operator receive a
Purchase Material was never built.** Reaching steps 4–10 requires new frontend work, which this
task explicitly forbids ("Do not invent a new receiving flow", "Purchase Material UI redesign" is in
the FINAL STOP list). I therefore stopped rather than build it.

## 5. Receiving result — **BLOCKER**

No receipt was created. No `Confirm Receipt` was executed. Nothing was posted.

I deliberately did **not** create the receipt by calling the API directly. Three reasons:

1. Part 3 requires the **UI**; an API call would not be the workflow under acceptance.
2. Posting is **irreversible** — a posted receipt can never be un-posted (per the
   `PurchaseMaterialReceivingService` contract), and it would permanently write inventory, stock
   ledger and FIFO layer rows against real data.
3. The brief states: *"Do not solve a blocker by modifying production data manually."*

Proving a flow no operator can actually reach, by permanently mutating real inventory, would
manufacture a green result rather than earn one.

## 6. Required / Received / Remaining — PASS

Computed read-only through the certified `PurchaseMaterialReceivingService` against real PM-00002:

```
REQUIRED  : 100    COALESCE(agreed_qty = NULL, requested_qty = 100)      ← RD-2
RECEIVED  : 0      posted PM-anchored GR lines, gross of returns          ← RD-3
REMAINING : 100    Required − Received                                    ← RD-4
```

Matches the certified definitions exactly. The legacy `purchase_order_lines.received_qty` counter
is **not** consulted (and the PO tables are empty regardless). No new stored counter was introduced.

## 7. Inventory posting — **NOT VERIFIED** (blocked by §4)

Cannot be exercised without a receipt. `ReceiveStockAction`, `CreateReceiptLayersAction`,
GoodsInwardAuthority and the one-posting guarantee remain covered by the automated suite (§11) but
were **not** confirmed through a live UI receipt.

## 8. FIFO attribution — **NOT VERIFIED** (blocked by §4)

`inventory_receipt_layers.supplier_id` resolving from `purchase_material_lines.supplier_id` is
covered by the foundation tests, but no live layer was produced to inspect.

## 9. Duplicate protection — **NOT VERIFIED** (blocked by §4)

No posted receipt exists, so re-post rejection could not be exercised in the browser.

## 10. Side effects — PASS (zero)

Because no receipt was created, the before/after comparison is exact and empty:

| Table | Before | After | Δ |
|---|---|---|---|
| `purchase_materials` | unchanged | unchanged | **0** |
| `purchase_material_lines` | unchanged | unchanged | **0** |
| `goods_receipts` | 0 | 0 | **0** |
| `goods_receipt_lines` | 0 | 0 | **0** |
| `inventory_items` | 5 | 5 | **0** |
| `stock_ledger_entries` | 22 | 22 | **0** |
| `inventory_receipt_layers` | 2 | 2 | **0** |
| `purchase_orders` | 0 | 0 | **0** |
| `purchase_order_lines` | 0 | 0 | **0** |

No order, preparation, or distribution data was touched. No business data was fabricated.

## 11. Focused tests — PASS

Run via the serialized gate (`GATE_WAIT=2400 scripts/test-gate.sh`), no full Purchasing regression.

| Suite | Tests | Result |
|---|---|---|
| `PurchaseMaterialReceivingFoundationTest` | 15 | **OK (15 tests, 29 assertions)** |
| `GoodsReceiptTest` | 23 | **OK (23 tests, 53 assertions)** |
| `PurchaseMaterialSupplierSelectionTest` | 8 | **OK (8 tests)** — verified in the preceding task |

**Zero failures. Zero pre-existing failures.** 46 receiving-related tests green in total.

**On the brief's "17 receiving tests".** No suite in the receiving gate contains 17 tests today, so
the figure could not be matched to a file. Verified counts, on host and inside the testrunner
container, are 15 / 23 / 8 above. `GoodsReceiptTest.php` is **unmodified versus HEAD** (clean in
`git status`, last written 2026-08-05), so its 23 tests are long-standing and were not added by this
work — the "17" does not correspond to it. Rather than guess which run the number came from, **both**
receiving suites plus the supplier-selection suite were executed in full and all are green.

## 12. Static gates — N/A

**No files were changed by this task**, so no gate was applicable. (ESLint / tsc / Vite / PHPStan /
Pint / `php -l` all skipped for that reason, not skipped silently.)

## 13. Browser acceptance classification

**NOT BROWSER VERIFIED**

Two independent reasons, either of which is sufficient:

1. **No UI surface exists** for Purchase Material receiving (§4). Even a perfectly working browser
   could not complete steps 4–10.
2. **The Browser pane cannot composite frames** in this environment (`visibilityState: "hidden"`),
   so screenshots and coordinate/pixel interaction are unavailable. Per Part 8 this must be reported
   as *NOT BROWSER VERIFIED — UI handler/network path verified*, and it is not silently downgraded.

Reason 1 is the decisive one and is an engineering gap, not an environment artefact.

## 14. Analytics follow-up

**OUT OF SCOPE — GO-LIVE FOLLOW-UP.** Not touched.

The previously identified `INNER JOIN purchase_orders` analytics sites remain open. A sweep of
`backend/Modules` shows joins to `purchase_orders` across 8 analytics/query files (plus 2
migrations, irrelevant): `DemandAnalysisService`, `ExpectedIncomingQuery`,
`GetProcurementHealthQuery`, `GetSupplierAnalyticsQuery`, `GetSupplierPriceHistoryQuery`,
`GetSupplierProductDemandQuery`, `GetSupplierTimelineQuery`, `EloquentSupplierRepository`.

**Risk restated:** Purchase-Material-anchored receipts have no `purchase_order_id`, so an INNER JOIN
to `purchase_orders` **silently drops them**. Every one of these surfaces will under-report once PM
receiving goes live. Must be addressed before Purchasing is production-ready.

## 15. Final verdict

**BACKEND COMPLETE / BROWSER NOT VERIFIED**

| Criterion | Result |
|---|---|
| Real PM-00002 received successfully | **BLOCKER** — no UI path exists |
| Browser interaction genuinely verified | **NOT BROWSER VERIFIED** |
| Inventory posted exactly once | NOT VERIFIED (blocked) |
| FIFO attribution correct | NOT VERIFIED (blocked) |
| No PO rows touched | **PASS** (zero PO rows exist; zero changes) |
| Focused tests green | **PASS** — 46 tests green (15 + 23 + 8), zero failures |
| Static gates green | N/A — no files changed |
| Supplier present and persistent (RD-1) | **PASS** |
| Required / Received / Remaining | **PASS** |
| Side effects | **PASS** — zero |

Phase 2 Part 1 is **NOT** claimed COMPLETE, because its acceptance criterion (a real receipt through
the real UI) cannot be met today. Nothing here is claimed CERTIFIED for the Purchasing module.

### What is actually outstanding

One thing, precisely scoped: **the Purchase Material receiving UI does not exist.** The backend
foundation behind it is implemented and green. Closing Phase 2 Part 1 requires a decision on
building that surface — which is outside this task's mandate and needs approval.
