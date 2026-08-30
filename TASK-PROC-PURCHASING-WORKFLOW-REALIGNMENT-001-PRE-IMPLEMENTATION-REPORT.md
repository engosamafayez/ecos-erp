# TASK-PROC-PURCHASING-WORKFLOW-REALIGNMENT-001 — Section 24 Pre-Implementation Report

Prepared from the 8 domain audits. Verdict up front: **the "one operational Purchase linked to its Supplier Invoice and Goods Receipts" model does NOT exist end-to-end in the backend today.** The columns, relations, and posting engines all exist and are sound, but the *links between them are never written by any production path*. Most of the requested UX realignment is achievable frontend-only or with tiny backend touches on existing contracts; two items (completion-on-receipt, receiving against the operational Purchase) are genuine CONTRACT_GAPs that must STOP+REPORT for a product/architecture decision before any code.

---

## 1. CURRENT ARCHITECTURE MAP (as of HEAD)

### The three disconnected spines

There are **three procurement aggregates that never meet at runtime**:

| Aggregate | Table / Model | What it is today | Lifecycle reach |
|---|---|---|---|
| **Purchase** (`record_type='purchase'`) | `purchase_materials` / `PurchaseMaterial` | The visible "Purchase" — actually a requisition/approval doc. Same table/model/routes/enum as `material_request`; `record_type` is only a filter+badge. Header has `warehouse_id` but **no `supplier_id`** (supplier is per-line). | Reachable states: Draft → UnderReview → WaitingSupplierSelection → **Approved (terminal in practice)**. |
| **Purchase Order** (legacy) | `purchase_orders` / `PurchaseOrder` | The entity receiving actually anchors on. | Approved / PartiallyReceived / Received. |
| **Goods Receipt** | `goods_receipts` / `GoodsReceipt` | Physical receipt; posts inventory. **Belongs to `PurchaseOrder` (`purchase_order_id` REQUIRED), NOT to PurchaseMaterial.** | Draft → Posted. |
| **Supplier Invoice** | `supplier_invoices` / `SupplierInvoice` | Standalone AP invoice; the *intended* hub. | Draft → Validated → Posted / Failed. |

### The real relation columns (direction VERIFIED, corrects the task hypothesis)

- `SupplierInvoice.auto_purchase_id` → `PurchaseMaterial` via `autoPurchase()` belongsTo (`SupplierInvoice.php:89-92`)
- `SupplierInvoice.auto_receipt_id` → `GoodsReceipt` via `autoReceipt()` belongsTo (`SupplierInvoice.php:94-97`)
- `SupplierInvoiceLine.goods_receipt_line_id` → `GoodsReceiptLine` (`SupplierInvoiceLine.php:72-75`) — the V-5 per-line anchor, the ONLY *genuine* invoice↔receipt link mechanism
- `GoodsReceipt.purchase_order_id` → `PurchaseOrder` (`GoodsReceipt.php:137-141`)
- `PurchaseMaterial` declares only `company/warehouse/channel/lines` — **no invoice() or receipts() relation** (`PurchaseMaterial.php:142-164`)

**So the SupplierInvoice is the designed hub** (it points at both the Purchase and the Receipt). The Purchase and the Receipt point at neither each other nor the invoice.

### The decisive finding — the hub is INERT

`auto_purchase_id`, `auto_receipt_id`, and `lines.*.goods_receipt_line_id` are **absent from `StoreSupplierInvoiceRequest`** and **never written by any production path**. `SupplierInvoiceController::store` merges `safe()->except('lines')`; only test fixtures ever set them. `GoodsInwardAuthority.php:17` states verbatim that *no production code path ever wrote* `auto_receipt_id`.

### Inventory posting (two "Post" actions, deliberately separate)

- **GR Post** (`POST /goods-receipts/{id}/post` → `PostGoodsReceiptAction`) = **inventory only**: writes `stock_ledger_entries` via `ReceiveStockAction`, FIFO layers via `CreateReceiptLayersAction`, increments `PurchaseOrderLine.received_qty`, advances PO status. No AP.
- **Invoice Post** (`POST /supplier-invoices/{id}/post` → `PostSupplierInvoiceService`) = **financial posting** (GRNI/PPV/VAT via `AccountsPayableService`), plus inventory **only under Mode 3**.
- `GoodsInwardAuthority` (`companies.goods_inward_mode`, default `goods_receipt`) picks which single document posts stock per company — the certified mechanism that prevents double-posting *without* relying on the never-populated `auto_receipt_id`.

### Supplier 360

10-tab drawer already surfaces PurchaseOrders (as "Purchase history"), GoodsReceipts, Financial, Timeline. Financial figures for the **list** come from legacy GR scalars; the **360 drawer** uses the ledger-derived `financial-summary` endpoint → the two show *different outstanding numbers for the same supplier*. No Supplier Invoices tab; no operational-Purchase (PurchaseMaterial) tab.

### Plain answer to the task's premise

**No.** The backend does not have a working "one Purchase → Invoice → Receipt(s) → Inventory" chain. It has the *scaffolding* (columns + Eloquent relations + a dormant UI block at `supplier-invoices-page.tsx:212-234`) but no write path links the documents, and the operational Purchase (`PurchaseMaterial`) is architecturally an orphan — receiving is bound to `PurchaseOrder`, and `PurchaseMaterial`'s Purchasing/Receiving/Completed states are dead.

---

## 2. CURRENT WORKFLOW vs TARGET (Section 20)

| Step | Today | Target (Section 20) |
|---|---|---|
| **Create purchase** | "New Purchase" → "Select Purchase Source" dialog offers 3 choices (From Material Request / Direct Purchase / Reorder). All 3 open the SAME wizard, differing only by a cosmetic `source_type` string. No supplier, no unit price, no invoice fields — it's the Material Request wizard reused. | ONE operational Purchase (Direct), priced, that you can invoice and receive against. |
| **"From Material Request" / "Reorder"** | Labels promise conversion / reorder-point logic that **does not exist** — both open a blank wizard. | Removed from purchase entry points. |
| **Invoice** | "New Invoice" builds a **standalone** invoice from scratch (supplier + warehouse + freehand lines), linked to nothing. On a default-mode tenant it can be created & validated but **can never post** (missing receipt anchor → rollback → Failed). | Invoice raised *against* the Purchase, linked to receiving. |
| **Receiving** | "New Receipt" forces building a receipt from scratch against an **approved PurchaseOrder**; picker excludes `partially_received` POs; line grid shows Ordered/Gross/Net/Variance, not Ordered/Invoiced/Received/Remaining. Receiving Center is a *registry of existing receipts*, not a to-receive worklist. | Receive against the Purchase; see remaining; queue of what needs receiving. |
| **"Post" buttons** | Two ambiguous Posts, both labeled "Post to Inventory" in places. GR Post = confirm receipt (inventory); Invoice Post = AP/payable (+Mode-3 inventory). | GR → "Confirm Receipt"; Invoice → "Post Invoice". |
| **Completion** | Purchase never leaves "Approved". Purchasing/Receiving/Completed states have **zero writers**. `purchased_value`/`approved_value` stay 0 forever. DemandAnalysis counts approved purchases as "incoming" forever. | Purchase completes when received = ordered. |
| **Supplier 360** | Shows PO/GR/Financial; no Invoices tab; "Purchase history" is legacy POs. | Operational record kept in Supplier 360. |

---

## 3. GAP LIST (classified)

### A. Source-choice removal
- Remove "From Material Request" and "Reorder" from `SOURCE_OPTIONS` — **FRONTEND_ONLY**. Backend enum keeps accepting the legacy values for existing rows (`StorePurchaseMaterialRequest.php:65`), no backend edit.
- Their i18n descriptions promise non-existent behavior — **FRONTEND_ONLY** (delete strings).
- If only "Direct" remains, drop `SourceSelectorDialog`, open wizard directly (`pendingSourceType` already defaults to `'direct'`) — **FRONTEND_ONLY**.

### B. Material-Request hiding
- Hide the Material Requests nav leaf — **FRONTEND_ONLY** (`module-navigation.ts:258`), but MUST also repoint two Procurement-Hub deep-links (`procurement-hub-page.tsx:206` work-queue card, `:413` quick action). Keep the route/page/`record_type` — the Purchases source selector may still reference `material_request` and the MR page is a separate legacy workflow.

### C. Receiving queue + partial-receipt display
- **Receiving work-queue endpoint** ("POs/purchases needing receiving") — **MISSING / BACKEND_NEEDED**. No endpoint returns outstanding receiving work; hub badge is hardcoded `—`.
- New-Receipt PO picker excludes `partially_received` (open) POs though backend `canReceive()` allows them — **BUG / FRONTEND_ONLY** (`use-approved-po-options.ts:13`).
- Create-GR grid shows full ordered qty, not remaining — **FRONTEND_ONLY** (`remaining_qty` already exposed by `PurchaseOrderLineResource.php:31`).
- "Invoiced" + "Remaining (cumulative)" columns on the GR grid — **BACKEND_NEEDED** (needs linked invoice + server-side cumulative rollup).

### D. Post relabeling (Section 9)
- GR Post "Post to Inventory" → "Confirm Receipt" — **FRONTEND_ONLY** (`receiving-center.json:91,101`).
- Supplier-Invoice Post (3 inconsistent call sites) → standardize "Post Invoice" — **FRONTEND_ONLY** (`supplier-invoices.json:7`, `detail.postToInventory`, `page.actions.postShort`).
- Drop "Mode 3" jargon from Supplier Invoices subtitle/editor — **FRONTEND_ONLY**.

### E. Standalone-invoice prevention / linkage
- Make "New Invoice" attach to a Purchase (`auto_purchase_id`) — **CONTRACT_GAP / BACKEND_NEEDED** (request + store don't accept/write it).
- Expose+validate `lines.*.goods_receipt_line_id` so default-mode invoices are postable — **BACKEND_NEEDED** (small, reuses `InvoiceReceiptAnchorService`).
- Dead "Auto Documents" UI block that never renders (`supplier-invoices-page.tsx:212-234`) — **FRONTEND_ONLY** to remove, **BACKEND_NEEDED** to make real.

### F. Completion-on-receipt
- Purchasing / Receiving / Completed states are **dead (0 writers)**; completion tied to nothing — **CONTRACT_GAP / BACKEND_NEEDED**. Cannot be synthesized in React.
- `approved_value`/`purchased_value` never populated → inert KPIs — **BUG**, downstream of the same gap.
- DemandAnalysis over-counts approved as "incoming" forever — **BUG**, downstream symptom.

### G. Supplier edit persistence
- "Any edit doesn't persist" is **over-broad and FALSE** — all profile fields (incl. state/district/google_maps_url) persist and show. The prior DTO fix landed.
- The ONLY silent drop is **Opening Balance** (`opening_balance_amount/type`): `SupplierDTO` omits the properties → `BaseDTO::toArray()` never emits them → repo never writes them, but the request validates and the form sends them → 200, drawer closes, columns stay at defaults. **BUG mechanically, but resolution is CONTRACT_GAP** — naively adding to the DTO creates a *second* opening-balance store that double-counts against the certified ledger at `SupplierResource.php:64`. Correct fix is **FRONTEND_ONLY** (remove the inputs, route through the certified ledger endpoint).
- Supplier 360 drawer renders from the list-row snapshot prop, never a by-id fetch (`useSupplierQuery` has 0 call sites) → a held-open drawer looks stale — **FRONTEND_ONLY** UX polish.

### H. Performance realism (Section 15)
- All 6 scoring components substitute hard-coded midpoints (50/50/75/30/**100**/50) when the supplier has no transactions → a zero-history supplier renders a fabricated "57 / Watch" with colored bars; `trend` hard-coded `'stable'`; no "No data" state anywhere — **BUG**. Fix split: **BACKEND_NEEDED** (per-component null/insufficient signal + `has_history` flag) + **FRONTEND_ONLY** (empty state).
- `financial_standing` uses hand-entered GR scalars instead of the ledger SSOT, and defaults to a perfect 100 — **BUG / BACKEND_NEEDED** (repoint at `SupplierLedgerService`).

### I. List balances (Section 16)
- Total Purchased / Purchase Balance / Total Outstanding / Current Supplier Balance all derived from legacy `goods_receipts.invoice_total_amount` + `paid_amount`; Opening Balance from the `suppliers.opening_balance_amount` scalar — **NOT** the ledger. Contradicts `SupplierLedgerService` (the SSOT), diverges from Supplier 360's own numbers — **BUG / BACKEND_NEEDED** (wire the repo to `SupplierLedgerService`; **not** a contract gap — the ledger already has the data).
- Opening Balance collapses Payable + Advance into one signed scalar, breaking the approved separation — **BUG / BACKEND_NEEDED**.
- `purchase_balance == total_purchased_value == total_invoiced` (duplicate, mislabeled) — **BUG**.
- Frontend renders columns verbatim → cannot be fixed frontend-only.

### J. Sidebar / 360
- Nav hide/reorder — **FRONTEND_ONLY** (nav leaves carry no permission field; only module-level gate). Edit `module-navigation.ts:251-264`, NOT the stale `navigation.ts`.
- Add Supplier 360 "Invoices" tab — **FRONTEND_ONLY** (`SupplierInvoiceController::index` already supports `?supplier_id`).
- Supplier 360 GR tab not supplier-scoped (controller drops `supplier_id` the repo supports) — **BUG / one-line BACKEND**.
- Realign 360 "Purchase history" from PO to operational Purchase — depends on the linkage decision (blocked).

---

## 4. FILES / SERVICES / ROUTES AFFECTED

### Frontend
- `frontend/src/features/purchase-materials/pages/purchases-page.tsx` — `SOURCE_OPTIONS` (73-92), `SourceSelectorDialog` (62-121)
- `frontend/src/config/module-navigation.ts:251-264` — Purchasing nav (active source; NOT `navigation.ts`)
- `frontend/src/features/procurement-hub/pages/procurement-hub-page.tsx:206,413` — MR deep-links; `:235` Receiving badge
- `frontend/src/features/goods-receipts/hooks/use-approved-po-options.ts:13` — add `partially_received`
- `frontend/src/features/goods-receipts/components/goods-receipt-header-fields.tsx:60-74` — use `remaining_qty`
- `frontend/src/features/receiving-center/pages/receiving-center-page.tsx` — Post label
- `frontend/src/features/supplier-invoices/pages/supplier-invoices-page.tsx:115-128,212-234,361-371,476-483` — Post labels, dead Auto-Docs block
- `frontend/src/features/supplier-invoices/components/supplier-invoice-editor.tsx:156-158` — subtitle jargon
- `frontend/src/features/suppliers/components/supplier-form.tsx:66-93`, `supplier-wizard.tsx:240-264`, `supplier-form-schema.ts` — remove Opening Balance inputs from CRUD
- `frontend/src/features/suppliers/components/supplier-360-drawer.tsx` — add Invoices tab; optional live refresh
- i18n: `en|ar/receiving-center.json`, `en|ar/supplier-invoices.json`, `purchase-materials.json`

### Backend (existing contracts — touches, not new engines)
- `.../Suppliers/Infrastructure/Repositories/EloquentSupplierRepository.php:27-66` — swap GR-scalar aggregates for `SupplierLedgerService`
- `.../Suppliers/Presentation/Http/Resources/SupplierResource.php:61-77` — ledger-derived balances, de-dupe columns
- `.../Suppliers/Presentation/Http/Controllers/GoodsReceiptController.php:34-45` — forward `supplier_id` (repo already filters)
- `.../Suppliers/Application/Queries/GetProcurementHealthQuery.php` — null/insufficient signals + `has_history`; repoint `financial_standing` at `SupplierLedgerService`
- `.../SupplierInvoices/Presentation/Http/Requests/StoreSupplierInvoiceRequest.php` + `SupplierInvoiceController.php` (syncLines) + `SupplierInvoiceResource.php` — expose/validate/persist `lines.*.goods_receipt_line_id` (and, if approved, `auto_purchase_id`)
- `.../Suppliers/Application/Queries/GetSupplierTimelineQuery.php` — additively union `supplier_invoices`

### Reusable services (DO NOT duplicate)
- `SupplierLedgerService` (AP SSOT), `SupplierOpeningBalanceService` (certified opening-balance contract), `AccountsPayableService` (sole `SupplierLedgerEntry` writer), `InvoiceReceiptAnchorService` (V-5 anchor), `GoodsInwardAuthority`, `PostGoodsReceiptAction`, `PostSupplierInvoiceService`, `ReceiveStockAction`, `CreateReceiptLayersAction`.

### Routes (no new routes for frontend-only work)
`GET/POST /purchase-materials …`, `POST /goods-receipts/{id}/post`, `POST /supplier-invoices/{id}/post`, `GET /suppliers`, `GET /suppliers/{id}/financial-summary`, `POST /suppliers/{id}/opening-balance`, `GET /suppliers/{id}/health`.

---

## 5. FRONTEND-ONLY WORK (maximize this)

1. Remove "From Material Request" + "Reorder" source options and their i18n.
2. Optionally collapse `SourceSelectorDialog` to open the Direct wizard directly.
3. Hide Material Requests nav leaf + repoint the 2 hub deep-links.
4. Include `partially_received` POs in the receipt picker; render `remaining_qty` as the target column.
5. Relabel GR Post → "Confirm Receipt"; standardize the 3 Invoice Post sites → "Post Invoice"; drop "Mode 3" jargon.
6. Remove the dead "Auto Documents" block (or leave hidden until backend links exist).
7. Remove Opening Balance inputs from the supplier create wizard + edit form; route opening balance solely through `suppliersService.postOpeningBalance` (the certified endpoint the 360 already calls). **This fixes the "edit doesn't persist" complaint without touching the DTO or the ledger.**
8. Supplier 360: add an "Invoices" tab via existing `?supplier_id` endpoint; optionally refresh the open drawer from the mutation's returned fresh `SupplierResource`.
9. Performance: render a "No purchase history yet" empty state when backend `has_history=false`.

---

## 6. BACKEND CHANGES (small, on existing contracts only)

1. **List balances** → `EloquentSupplierRepository`/`SupplierResource` consume `SupplierLedgerService.outstandingPayable()` / `availableAdvance()` and the ledger's opening entries; de-alias `purchase_balance` vs `total_purchased_value`. *Contract-preserving wiring, not a new engine.*
2. **Supplier 360 GR scoping** → one-line: forward `supplier_id` in `GoodsReceiptController::index`.
3. **Performance realism** → `GetProcurementHealthQuery` returns per-component null/insufficient + overall `has_history`; stop defaulting to 50; repoint `financial_standing` at `SupplierLedgerService`; compute or drop `trend`.
4. **Postable default-mode invoice** → expose + validate `lines.*.goods_receipt_line_id`, persist in `syncLines`, surface in resource; reuse `InvoiceReceiptAnchorService` for the ceiling.
5. **Supplier 360 timeline** → additively union `supplier_invoices` events.

---

## 7. DB CHANGES

**Target: NONE, and it is achievable for everything except the two STOP+REPORT items.**

- All link columns already exist (`auto_purchase_id`, `auto_receipt_id`, `goods_receipt_line_id`), so linkage work needs **no migration** — only request/persist/expose wiring.
- List-balance, performance, GR-scoping, timeline, Post-relabel, source-removal, nav, Opening-Balance-input-removal all need **zero DB changes**.
- **Completion-on-receipt**: the enum states and `nextWorkflowState()` already exist — activating them needs **no migration**, only a new guarded transition action + listener (backend logic, not schema).
- If the product decision is to receive directly against `PurchaseMaterial`, *that* could require a new FK/relation — **avoidable** if the decision instead bridges Purchase→PurchaseOrder or keeps PO as the receiving anchor.

---

## 8. CONTRACT GAPS — STOP + REPORT candidates

These **cannot be done by reuse or in React** and require an explicit owner decision before code:

1. **Completion-on-receipt is not modeled.** Purchasing/Receiving/Completed have zero writers; `PostGoodsReceiptAction`/`PostSupplierInvoiceService` never reference the linked Purchase; completion is tied to neither receiving nor invoicing. *Minimum non-fabricated fix:* on GR/Invoice posting, resolve the Purchase via `SupplierInvoice.auto_purchase_id`, compare posted received qty vs line `requested_qty`/`agreed_qty`, advance via existing `nextWorkflowState()` in a new action mirroring `ApprovePurchaseMaterialAction`. No fake bill/journal.

2. **"The Purchase" is ambiguous: `PurchaseMaterial` vs `PurchaseOrder`.** Receiving is hard-bound to `PurchaseOrder`; `PurchaseMaterial` has no path to GR/Invoice. Deciding which is canonical (bridge PM→PO on approval, or treat PO as the Purchase) is a prerequisite architecture decision.

3. **No production path links Supplier Invoice to a Purchase or Receipt.** `auto_purchase_id`/`auto_receipt_id`/`goods_receipt_line_id` are absent from the create contract and never written. Choosing the linking trigger (at invoice create? at receipt post? a "raise invoice from Purchase" action?) is a business decision. **Note the certified collision:** migration `2026_08_15_120000` *deliberately rejected* exposing header `auto_receipt_id` in favor of per-company `goods_inward_mode` mutual-exclusivity — reopening header linking contradicts a certified contract. Prefer the **line-level** `goods_receipt_line_id` path.

4. **No receiving work-queue endpoint.** Nothing returns "POs/purchases needing receiving." In scope? If yes, a read endpoint over approved/partially_received POs with outstanding `remaining_qty` (reuse existing model). If a lighter approach is fine, expose a multi-status/"receivable" PO filter and build the queue in React.

5. **Two disconnected inbound engines both post stock** (GR Post and Mode-3 Invoice Post) and **invoice is captured twice** (inline on GR vs the SupplierInvoice entity). Reconciling to ONE authoritative inbound is a backend ownership decision — must respect the certified `goods_inward_mode` and Opening Balance contracts.

6. **Opening-Balance CRUD collision.** The mechanical silent drop is real, but the correct fix is to *not* wire CRUD to write `suppliers.opening_balance_*` — it double-counts against the certified `SupplierOpeningBalanceService` ledger SSOT. Route opening balance through the certified endpoint only; STOP+REPORT the two-store collision for a product decision before any DTO change.

---

## 9. EXACT IMPLEMENTATION PLAN (ordered, reuse-first)

### (a) Safe frontend alignment — ship first, no backend risk
1. Remove "From Material Request" + "Reorder" source options + i18n; optionally collapse the source dialog to Direct.
2. Hide Material Requests nav leaf; repoint the 2 Procurement-Hub deep-links.
3. Relabel Posts: GR → "Confirm Receipt"; 3 Invoice sites → "Post Invoice"; drop "Mode 3" jargon.
4. Receipt picker: include `partially_received`; show `remaining_qty` as target qty.
5. Remove Opening Balance inputs from supplier wizard + edit form; route via `postOpeningBalance` (fixes the "edit doesn't persist" report).
6. Remove the dead Auto-Documents block.
7. Add Supplier 360 "Invoices" tab (existing `?supplier_id` endpoint); optional open-drawer refresh from mutation result.

### (b) Small backend touches on existing contracts
8. `GoodsReceiptController::index` — forward `supplier_id` (one line).
9. `EloquentSupplierRepository`/`SupplierResource` — ledger-derived balances via `SupplierLedgerService`; de-dupe Purchase Balance vs Total Purchased; keep Opening Payable/Advance separate; add frontend split columns after.
10. `GetProcurementHealthQuery` — `has_history` + per-component null; repoint `financial_standing` at the ledger; fix `trend`. Then render the frontend empty state.
11. `GetSupplierTimelineQuery` — union `supplier_invoices`.
12. Expose+validate `lines.*.goods_receipt_line_id` so default-mode invoices post (reuse `InvoiceReceiptAnchorService`).

### (c) STOP-and-report before any code
13. Completion-on-receipt transition (which Purchase, which trigger).
14. Linking model for Purchase↔Invoice↔Receipt (respect `goods_inward_mode`; prefer line anchor).
15. Receiving-against-the-Purchase (which entity is canonical; avoid a migration if possible).
16. Opening-Balance two-store collision confirmation.
17. Receiving work-queue scope.

---

## 10. REGRESSION RISKS

1. **Opening Balance (highest).** Do NOT add `opening_balance_*` to `SupplierDTO` — it double-counts at `SupplierResource.php:64` against the certified `SupplierOpeningBalanceService` ledger. Do NOT modify `SupplierOpeningBalanceService`/`SupplierLedgerService`. The certified `POST /suppliers/{id}/opening-balance` + `financial-summary` contract must not regress. Frontend-only removal of the inputs is the safe path.
2. **List-balance rewire.** Switching to `SupplierLedgerService` changes displayed numbers for every supplier (by design — aligns list with 360). Verify against Finance AP tests and the `financial-summary` output; ensure advances are surfaced separately, not summed into outstanding.
3. **Finance / GR / Inventory posting tests.** Exposing `goods_receipt_line_id` must not alter `PostSupplierInvoiceService` / `PostGoodsReceiptAction` / `InboundPostingGuard` idempotency or the `goods_inward_mode` mutual-exclusivity. Run the inbound-ownership contract + concurrency suites. Note: 5 GoodsReceipt posting tests already fail at baseline (flagged `task_a7671f38`) — run a control at the parent commit before attributing failures.
4. **The already-shipped supplier-edit fix.** state/district/google_maps_url persistence landed via the DTO — do not revert or re-touch `SupplierDTO` location fields.
5. **Hiding routes with backend consumers.** Material Requests page/route/`record_type` must survive (Purchases source selector + separate legacy workflow reference them); only hide the nav leaf and repoint hub links. Nav leaves have no permission field, so hiding is cosmetic — API authorization is unchanged and correct.
6. **Performance defaults.** Removing the 50/75/100 midpoints changes visible scores; ensure `computeWeightedScore` doesn't re-default missing components to 50, or the "No data" signal is meaningless.
7. **Do not fabricate.** No completion state, no invoice↔purchase link, no bill/journal may be synthesized in the frontend or invented in the backend to paper over items 13-16. Those are STOP+REPORT.

**Bottom line:** ~70% of the requested realignment (all of Section-9 relabeling, source-choice cleanup, MR hiding, partial-receipt display, Opening-Balance input removal, 360 Invoices tab, and the list-balance/performance/GR-scoping wiring) is frontend-only or small touches on existing, certified services with **no DB changes**. The remaining ~30% (completion-on-receipt, invoice/receipt linkage, receiving-against-the-Purchase, receiving queue) are genuine contract gaps that must be decided by the owner before implementation — they cannot be honestly built by reuse.