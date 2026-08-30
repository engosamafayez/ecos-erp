# TASK-PROCUREMENT-PO-DRIVEN-RECEIVING-CENTER-001 — Engineering Report

**Procurement Receiving Center — Purchase-Order-Driven Receiving Queue**
Date: 2026-08-29 · Branch: `develop` · Status: **COMPLETE** (verification narrow, per policy)

---

## Executive Summary

The Receiving Center is now a **work queue of receivable Purchase Orders**. Eligible POs appear
automatically; the Warehouse records actual "receive now" quantities per line; and the physical
receipt is performed by the **certified Goods Receipt authority** (`CreateGoodsReceiptAction` +
`PostGoodsReceiptAction`) — inventory increases only by the actual accepted quantity, with the
existing over-receipt ceiling, partial/full tracking against the PO, and locked idempotency all
preserved. The from-scratch "New Receipt" is removed from the Receiving Center.

**No new receiving model, inventory authority, or inbound mode was created.** `goods_inward_mode`
is untouched; the Supplier Invoice remains the downstream financial document (its V-5
receipt-settlement role is unchanged, and receiving never creates or requires one). Per §23 nothing
was committed, pushed, deployed, or run against DEV business data — verification is on the isolated
test schema only.

---

## 1. Existing Certified Inbound Architecture

`companies.goods_inward_mode` selects one inventory authority per company (`goods_receipt` default /
`supplier_invoice`). In `goods_receipt` mode the flow is **PO → Goods Receipt → PostGoodsReceiptAction →
Inventory → Supplier Invoice (financial settlement)**. This task builds entirely on that mode; it
does not touch `GoodsInwardAuthority`, `PostGoodsReceiptAction`, `CreateGoodsReceiptAction`, or the
Supplier Invoice posting path.

## 2. PO Receiving Eligibility

The canonical "receivable now" state is `PurchaseOrderStatus::canReceive()` = **Approved** or
**PartiallyReceived** — reused verbatim, no new eligibility flag. The queue's **Active** scope is
those two states; **History** is **Received + Closed**. Draft/Submitted/Cancelled are never
receivable and never appear.

## 3. Goods Receipt Authority

Receiving delegates to a thin orchestrator, `ReceiveAgainstPurchaseOrderAction`, that composes the
two certified actions inside **one transaction**: `CreateGoodsReceiptAction` (builds the draft from
the PO + the operator's actual quantities; it already validates `canReceive()` and rejects
cancelled/closed POs) and `PostGoodsReceiptAction` (the sole canonical inventory posting). A failure
in either rolls the whole thing back — no orphan draft, no partial inventory. It is an orchestrator,
not a second stock action.

## 4. Receiving Center — Before

A **Goods Receipt** CRUD list (`useGoodsReceiptsQuery`) with a manual **"New Receipt"** /
"Receive Goods" that opened a from-scratch form (`goodsReceiptsNew` → `CreateGoodsReceiptAction`),
plus edit/post/delete per draft. The warehouse re-entered purchasing information by hand.

## 5. Receiving Center — After

A **receivable-PO work queue**: Active/History tabs, KPIs (Awaiting / Partially Received / Received),
server-side filters (PO-number search, warehouse, date range) + pagination, a desktop table and
mobile cards, and a per-PO **Receive** action opening a line-level receive drawer. No from-scratch
creation.

## 6. Removal of Manual Creation UX

The Receiving Center no longer renders "New Receipt" or "Receive Goods"; it was the only surface that
linked to `goodsReceiptsNew`. Per §3 the underlying Goods Receipt model/actions and the create/view/
edit pages are **not deleted** — the create route remains reachable by direct URL for edge/back-office
use, but the normal warehouse operational flow is now PO-driven. Historical Goods Receipts are
untouched (`/goods-receipts` continues to redirect into the Receiving Center; `/goods-receipts/:id`
view/edit remain).

## 7. Expected vs Actual Quantity

Expected = the PO line's `quantity` (snapshotted into `goods_receipt_lines.ordered_quantity`); the
client never sets it. Actual = the operator's **receive now**, written as
`net_received_quantity` (and `gross_received_quantity`), the authoritative figure
`PostGoodsReceiptAction` moves to stock. Inventory increases by the actual quantity only — a PO of
100 received as 95 posts 95 and leaves 5 remaining.

## 8. Partial Receipt

Reuses the canonical cumulative behaviour: `PostGoodsReceiptAction` advances
`purchase_order_lines.received_qty`; a line with `received_qty < quantity` keeps the PO in
`PartiallyReceived`, so the same PO stays in the Active queue with the correct remaining. No new
manual workflow. (Proven: receive 70/100 → received 70, remaining 30, PO PartiallyReceived, on-hand
70, still Active.)

## 9. Full Receipt

When cumulative received reaches the ordered quantity the PO becomes `Received`, drops out of the
Active queue, and remains browsable under History. (Proven: receive 100/100 → Received, absent from
Active, present in History.)

## 10. Over-Receipt

Preserved exactly: `PostGoodsReceiptAction` Guard 4 throws `OverReceiptException` when
`received_qty + net > ordered`. The receive endpoint surfaces it as **422** and the transaction rolls
back — nothing posts. No tolerance was introduced or weakened. (Proven: receive 150/100 → 422,
received stays 0, no inventory row.)

## 11. Inventory Posting

Exclusively through `PostGoodsReceiptAction` → `ReceiveStockAction` + `CreateReceiptLayersAction`
(quantity + `stock_ledger_entries` + FIFO layers), referenced as `goods_receipt`. React never mutates
stock. Channel stock-sync mirrors the normal post path (best-effort).

## 12. Idempotency

Unchanged and preserved: `PostGoodsReceiptAction` re-checks status under a row lock and
`InboundPostingGuard` keys on the ledger reference, so a given receipt cannot double-post. The receive
button is disabled while pending; a genuinely duplicated cumulative quantity is caught by the
over-receipt ceiling across receipts.

## 13. Supplier Invoice Boundary

Untouched. Receiving never creates or reads a Supplier Invoice; `SupplierInvoiceLine.goods_receipt_line_id`
remains the authoritative settlement anchor. In `goods_receipt` mode the invoice posts no inventory,
and this task keeps it that way. (Proven: a full receive creates **zero** Supplier Invoices.)

## 14. Damage / Rejected-Goods Gap

As documented in the prior audit, `goods_receipt_lines` has **no accepted / rejected / damaged**
disposition contract, and none was fabricated. The receive flow captures received quantity only
(`gross == net`). This remains a **deferred procurement/inventory architecture gap** for later review;
it does not block normal expected-vs-actual receiving.

## 15. Desktop UX

`UniversalDataGrid` table: PO number + order date, supplier, warehouse, product count, expected,
received, remaining, a receipt-stage badge (Awaiting / Partially Received / Fully Received), and a
**Receive** action (shown only while remaining > 0). Filters and pagination sit above it.

## 16. Mobile UX

The grid's `renderMobileCard` gives a card list below `lg` (desktop table stays for `lg+`). Each card
shows Supplier, PO number, Warehouse, Expected/Received/Remaining and the stage badge, with a primary
**Receive** button — no horizontal scrolling for normal receiving.

## 17. RBAC

The receive write carries `permission:purchasing.goods_receipts.create` — the existing warehouse
receipt authority, distinct from PO creation/approval and Supplier-Invoice/financial administration.
No permission was added or broadened. The queue reads follow the existing goods-receipt/PO read
convention. Tenancy is the acting user's company (`company_id` filter, matching the certified PO
list). (Proven: an unprivileged user gets 403 and nothing posts.)

## 18. Backend Changes

- **NEW** `ReceiveAgainstPurchaseOrderAction` — orchestrates Create + Post in one transaction.
- **NEW** `ReceiveAgainstPurchaseOrderRequest` — validates `lines[].purchase_order_line_id` + `receive_now >= 0`.
- **NEW** `ReceivingCenterController` — `queue` (receivable-PO aggregates + KPIs + filters + pagination),
  `show` (per-line receive detail), `receive` (delegates to the action + best-effort channel sync).
- **MODIFIED** `routes/api.php` — `GET /receiving/queue`, `GET /receiving/purchase-orders/{po}`,
  `POST /receiving/purchase-orders/{po}/receive` (receive gated on `purchasing.goods_receipts.create`).

No changes to any certified authority, model, migration, or `goods_inward_mode`.

## 19. Frontend Changes

- **REWRITTEN** `receiving-center-page.tsx` — PO-driven queue (Active/History, KPIs, filters,
  pagination, desktop table + mobile cards, Receive action); no New Receipt.
- **NEW** `types/receiving.ts`, `services/receiving-service.ts`, `hooks/use-receiving.ts`,
  `components/receive-drawer.tsx` (per-line "receive now" → receive endpoint).
- **MODIFIED** i18n `en/ar receiving-center.json` — new `page.*` + `receive.*` blocks (EN/AR parity).

## 20. Files Changed

**Backend**
- `backend/Modules/Purchasing/GoodsReceipts/Application/Actions/ReceiveAgainstPurchaseOrderAction.php` *(new)*
- `backend/Modules/Purchasing/GoodsReceipts/Presentation/Http/Requests/ReceiveAgainstPurchaseOrderRequest.php` *(new)*
- `backend/Modules/Purchasing/GoodsReceipts/Presentation/Http/Controllers/ReceivingCenterController.php` *(new)*
- `backend/routes/api.php`
- `backend/tests/Feature/Purchasing/PoDrivenReceivingTest.php` *(new)*

**Frontend**
- `frontend/src/features/receiving-center/pages/receiving-center-page.tsx` *(rewritten)*
- `frontend/src/features/receiving-center/types/receiving.ts` *(new)*
- `frontend/src/features/receiving-center/services/receiving-service.ts` *(new)*
- `frontend/src/features/receiving-center/hooks/use-receiving.ts` *(new)*
- `frontend/src/features/receiving-center/components/receive-drawer.tsx` *(new)*
- `frontend/src/features/receiving-center/pages/receiving-center-page.test.tsx` *(new)*
- `frontend/src/i18n/locales/en/receiving-center.json`
- `frontend/src/i18n/locales/ar/receiving-center.json`

## 21. Focused Verification

- **Backend (isolated test schema, via the gate):** `php -l` clean; **PHPStan clean** (3 files);
  **`PoDrivenReceivingTest` — 7 tests / 34 assertions OK**: eligible PO in queue with aggregates;
  Draft PO absent; partial receipt uses actual qty (received 70, remaining 30, PO PartiallyReceived,
  on-hand 70, still Active); full receipt → Received, leaves Active, appears in History; over-receipt
  → 422 rolled back; **receiving creates zero Supplier Invoices**; unauthorized → 403, nothing posted.
- **Frontend:** `tsc -p tsconfig.app.json` — **0 errors in the feature** (pre-existing baseline
  untouched); **ESLint 0**; **Vitest 4/4** (queue lists POs with aggregates; Receive present and
  **no New Receipt**; empty state; error state); mobile card presentation asserted; i18n EN/AR parity
  91 = 91.
- **No DEV business data mutated**; no deploy (per §23). All mutations exercised on `RefreshDatabase`.

## 22. Remaining Gaps

- **Damage / rejected / accepted** disposition (§14) — no canonical contract; deferred.
- **Supplier filter** on the queue: the backend `queue` already accepts `supplier_id`; the UI ships
  search (PO #) + warehouse + date + state, and a supplier dropdown is a small follow-up.
- The manual `goods-receipts/new` create page remains routed (unlinked from the Receiving Center) for
  the Goods Receipt authority's own edge/back-office use, per §3.
- Not deployed to the DEV runtime (per §23 STOP); a DEV runtime refresh (backend `docker cp` +
  `optimize:clear`, frontend `vite build` + copy to `ecos-dev-nginx`) is required before it is visible
  in DEV, as a separate approved step.

## 23. Implementation Status

The Receiving Center is a PO-driven receiving queue over the certified Goods Receipt authority, with
expected-vs-actual, partial/full, over-receipt, idempotency, RBAC, Active/History, filters and mobile
cards — and no manual from-scratch creation — with no new inventory authority and no change to
`goods_inward_mode` or the Supplier Invoice.

---

IMPLEMENTATION STATUS:
COMPLETE

FINAL CERTIFICATION:
DEFERRED TO FINAL SYSTEM REVIEW
