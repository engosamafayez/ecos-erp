# TASK-PROC-PURCHASING-PHASE2-PART1-PURCHASE-MATERIAL-RECEIVING-UI-001 — FINAL REPORT

**Date:** 2026-08-21
**Scope:** Frontend only — the missing Purchase Material → Goods Receipt path
**Verdict:** **FRONTEND COMPLETE / BROWSER NOT VERIFIED** — see §14 and §17
**Commit:** none · **Business data changed:** none

---

## 1. Existing UI audit

Audited before writing anything. What already existed:

| Surface | State |
|---|---|
| Purchase workspace (`purchases-page.tsx`) | exists — row click opens the drawer |
| Purchase Material drawer (`purchase-material-drawer.tsx`) | exists — 7 tabs, `Sheet` based |
| Supplier selection tab | exists — the pattern this work mirrors |
| Receiving Center (`/purchasing/receiving`) | exists — lists receipts, exposes **Confirm Receipt** |
| Goods Receipt create form | exists — **PO-only**, `use-approved-po-options.ts` |
| React Query hooks (`use-goods-receipts.ts`) | `useCreateGoodsReceipt`, `usePostGoodsReceipt` — complete |
| API client (`goods-receipts-service.ts`) | `create()` → `POST /goods-receipts`, `post()` → `POST /goods-receipts/{id}/post` |
| Authorization | `usePermission().can()` (ADR-041) |
| i18n | `purchase-materials` namespace; `tabs.receipt` = "Receiving" **already existed** |

**The gap, precisely.** The backend has accepted a purchase-material anchor since Phase 2 Part 1:

```php
'purchase_order_id'                 => ['nullable', 'required_without:lines.0.purchase_material_line_id', ...],
'lines.*.purchase_order_line_id'    => ['nullable', 'required_without:lines.*.purchase_material_line_id', ...],
'lines.*.purchase_material_line_id' => ['nullable', 'required_without:lines.*.purchase_order_line_id', ...],
```

…while the frontend hard-required a PO (`purchase_order_id: z.string().min(1, 'Purchase order is required.')`)
and contained **zero** occurrences of `purchase_material_line_id` in all of `frontend/src`.

**No duplicate components were created.** No new page, route, service, or hook was added.

## 2. Components reused — PASS

Everything came off the shelf; no new visual system, no new primitives:

`ConfirmDialog` · `Button` · `toast` (`@/components/ds/use-toast`) · `getMediaUrl` ·
`usePermission` · `useSupplierOptions` · `useCreateGoodsReceipt` · `usePostGoodsReceipt` ·
the drawer's existing `Sheet` + tab-strip pattern · the `SupplierSelectionLineRow` card layout
(same border/spacing/typography idiom).

## 3. Entry point — PASS

A **Receiving** tab inside the existing Purchase Material drawer, sitting between *Supplier* and
*Financial*. It uses the pre-existing `tabs.receipt` key ("Receiving" / "الاستلام") — no new label.

The tab is rendered **only when receiving is applicable**:

```ts
const RECEIVING_STATUSES = ['approved', 'purchasing', 'receiving'];
...(material && RECEIVING_STATUSES.includes(material.status) ? [{ id: 'receiving', ... }] : [])
```

The action inside is labelled **Confirm Receipt** — the approved terminology. The words "Post",
"New Purchase Order", "Create PO" and "Receive PO" appear nowhere.

## 4. Receiving screen — PASS

New component: `features/purchase-materials/components/purchase-material-receiving-tab.tsx`.

Shows Purchase context (identifier and status via the drawer header it lives in, **Warehouse**,
**Receipt date**) and per line: product name, **SKU**, **Supplier**, **Required**, **Received**,
**Remaining**, **To receive**, and unit.

**No Purchase Order is requested or displayed** — verified in the live DOM: `mentionsPurchaseOrder:
false`, and zero `<select>` elements on the panel.

## 5. Quantity behaviour — PASS

The component **never computes** Required / Received / Remaining. They arrive already derived from
`PurchaseMaterialReceivingService` via `PurchaseMaterialLineResource` and are rendered verbatim:

```json
"required_qty": 100, "received_qty": 0, "remaining_qty": 100
```

Rules enforced, none invented:

- input `max={remaining}`, `min=0`, `step=0.0001`
- a value above Remaining marks the field invalid, shows *Exceeds remaining*, and **disables** Confirm
- zero/blank lines are excluded from the payload entirely
- submission is blocked when nothing is to be received
- no tolerance, no negative receiving, no over-receipt path
- **the backend remains the final authority** — the UI clamps, it does not decide

## 6. API integration — PASS

Existing certified endpoints only; no new service method, no direct DB access.

```
POST /api/goods-receipts            (useCreateGoodsReceipt)
POST /api/goods-receipts/{id}/post  (usePostGoodsReceipt)
```

Payload shape — note the absent PO fields:

```jsonc
{
  "warehouse_id": "<pm.warehouse_id>",
  "receipt_date": "<today>",
  "lines": [{
    "purchase_material_line_id": "<line.id>",   // the anchor
    "product_id": "<line.product_id>",
    "ordered_quantity": 100,                     // the server's required_qty
    "gross_received_quantity": 40,
    "net_received_quantity": 40
  }]
}
```

`unit_price` is included only when the line carries an `agreed_price`. **Confirm Receipt** is one
operator action: create, then post.

**Success state:** both hooks already invalidate the goods-receipt query key; the PM query is
refreshed so Required/Received/Remaining update; the resulting receipt appears in the existing
Receiving Center — no second receipt representation was introduced. The review dialog closes and
inputs reset, and Confirm is disabled while the mutation is pending, so a double submit is not
reachable.

## 7. Error handling — PASS

Backend messages are surfaced, not flattened into a generic failure:

```ts
const errors = error.response?.data?.errors;      // 422 field detail first —
const first  = errors ? Object.values(errors)[0]?.[0] : undefined;   // a bare `message` there
if (first) return first;                          // is only "The given data was invalid."
return error.response?.data?.message ?? fallback;
```

Covered: no remaining quantity (tab shows *fully received*, no input); quantity exceeds remaining
(blocked client-side **and** by the server); missing supplier (blocked, RD-1); unauthorized (action
disabled + explicit message, server still enforces); tenant mismatch (server `Rule::exists` scoped
to the actor's company — surfaced verbatim); invalid line (server 422 surfaced); duplicate posting
(pending-state disable + server rejection surfaced).

## 8. i18n — PASS

19 new keys under `purchaseDrawer.receiving`, in **both** `en` and `ar`. No hardcoded UI strings
(ESLint's `ecos-i18n/no-hardcoded-ui-strings` passes). The existing `tabs.receipt` key was reused
rather than duplicated. Arabic follows the established Procurement terminology — `تأكيد المورد`
(Confirm Supplier) → **`تأكيد الاستلام`** (Confirm Receipt), `المطلوب` / `المستلم` / `المتبقي`.

A JSON round-trip while adding keys reformatted 7 pre-existing inline objects per file; that was
detected and **fully reverted**, so the diff is additions-only. The files were confirmed to carry
unrelated uncommitted work (the supplier-picker keys), so they were never restored from git.

## 9. Permission behaviour — PASS

Reuses the existing permissions; **none created**:

| Action | Permission |
|---|---|
| create receipt | `purchasing.goods_receipts.create` |
| post receipt | `purchasing.goods_receipts.update` |

Both are required for Confirm Receipt to enable. The check is UX-only — the route middleware
remains the authority.

## 10. Focused tests

**Frontend** — `purchase-material-receiving-tab.test.tsx`: **8/8 PASS**. Narrow by design; it
drives the real component with only hooks stubbed, and resolves `t()` selectors against the **real**
locale bundles so a missing key fails the test.

| Test | Pins |
|---|---|
| renders Required/Received/Remaining exactly as supplied | server values, never recomputed (fixture deliberately inconsistent with `requested_qty`) |
| fully-received state | no input when nothing remains |
| refuses quantity > remaining | warning + disabled Confirm |
| Confirm disabled until a quantity is entered | no empty submits |
| **payload anchors on the purchase-material line** | `purchase_material_line_id` set; `purchase_order_id` **and** `purchase_order_line_id` undefined |
| no receiving permission | blocked |
| line with no supplier (RD-1) | blocked, no mutation fired |
| renders from the Arabic bundle | catches a half-added translation |

**Backend** — no backend file was changed; the receiving gate was re-run as a regression guard and
is **green**:

```
tests/Feature/Purchasing/PurchaseMaterialReceivingFoundationTest.php   (15)
tests/Feature/Purchasing/GoodsReceiptTest.php                          (23)
tests/Feature/Purchasing/PurchaseMaterialSupplierSelectionTest.php     (8)

.............................................. 46 / 46 (100%)
OK (46 tests, 106 assertions)
```

**46/46 PASS**, zero failures, zero pre-existing failures. No full Purchasing regression was run.
No existing test was duplicated.

## 11. ESLint — PASS

Exit 0 on all five touched files. Three initial errors in the new test file (an unused parameter and
two hardcoded strings) were fixed at source — by dropping the unneeded fixture label and switching
the mock's confirm button to `data-testid` — **not** by disabling the rule or exempting tests.

## 12. TypeScript

**23 errors, all pre-existing, none in this task's files.**

Zero errors in the touched files and zero anywhere under `purchase-materials` or `goods-receipts`.

The 23 sit in 13 files this task never touched: `orders/manual-order-form` (6),
`admin/configuration-os-page` (4), `marketing/automation-workspace-page` (2),
`engineering/AIEngineeringWorkspacePage` (2), and one each in `stock-ledger/movement-type-badge`,
`marketing/connection-status-badge`, `marketing/automation-dashboard-page`,
`logistics/dispatch-conflicts-panel`, `hr/offers-workspace-page`, `hr/exit-management-page`,
`hr/compensation-explainability-page`, `business-accounts-page`, `admin/brand-configuration-page`.

Proof they are not caused here: **none of those files imports `goods-receipt` or
`purchase-material` types** (checked individually), they were last modified hours before this task
began, and every error is one of the repo's known pre-existing patterns — `TS7053` index-signature
on status/label maps, `TS2322` on `StatusVariant`, `TS7006` implicit `any`. Not fixed here, per
instruction.

**A measurement correction.** An earlier draft of this report cited "13 errors". That figure was an
artifact of piping the first `tsc` run through `tail -30`, which truncated the output before it was
counted — not a real baseline. The full, untruncated count is 23, and it was 23 before this task's
changes as well.

One genuine error *was* introduced in the new test file during this work
(`TS2322: Type 'false' is not assignable to type 'true'` — `vi.hoisted` inferred a literal `true`
return type) and was fixed at source by annotating the ref as `(): boolean`.

Run with `-p tsconfig.app.json`; a bare `tsc --noEmit` checks zero files in this repo.

## 13. Vite — PASS

`✓ built in 6.21s`, exit 0.

## 14. Browser acceptance — **NOT BROWSER VERIFIED — UI/API path verified**

Performed against the running app with real **PM-00002**. Nothing was created or fabricated.

| # | Step | Result |
|---|---|---|
| 1 | Purchase Material opens | **PASS** — `PM-00002 · Main Warehouse · ECOS Holding 20 · Approved` |
| 2 | Supplier visible | **PASS** — `398830 – OSAMA FAYEZ AHEMD` on the line |
| 3 | Supplier survives reload | **PASS** (re-verified; established in the preceding task) |
| 4 | Confirm Receipt action visible | **PASS** — new **Receiving** tab between Supplier and Financial |
| 5 | Receiving screen opens | **PASS** |
| 6 | Purchase Order NOT required | **PASS** — no PO text, no picker, zero `<select>` |
| 7 | Required / Received / Remaining displayed | **PASS** — **100 / 0 / 100** |
| 8 | Quantity input behaves | **PASS** — `150` → *Exceeds remaining* + Confirm disabled; `40` → valid, Confirm enabled |
| 9 | Review state correct | **PASS** — *Warehouse: Main Warehouse · Total lines: 1 · Glass Jar 250ml · 398830 – OSAMA FAYEZ AHEMD — 40 pcs* |
| 10 | Submit reaches the certified backend | **STOPPED DELIBERATELY** — see §15 |

**Why the classification is not "browser verified".** The Browser pane could not composite frames
(`document.visibilityState: "hidden"`), so screenshots and coordinate/pixel clicks were unavailable.
Interaction was driven through the **real React handlers on the real rendered DOM**, with results
read from the live DOM and the real network layer. That is a genuine UI/API path exercise, but it is
not a human clicking pixels, so per Part 16 it is reported as **NOT BROWSER VERIFIED** and not
silently upgraded.

**One defect was found and fixed during this walkthrough.** The supplier badge initially did not
render: the Purchase Material payload carries `supplier_id` but **not** the `supplier` relation
(not eager-loaded). Rather than change the backend, the label is resolved client-side from
`useSupplierOptions()` — the same list the Supplier tab already uses. Identity still comes from
`purchase_material_lines.supplier_id`, so RD-1 is untouched.

## 15. Business-data side effects — PASS (zero)

Stopped immediately before the irreversible action, exactly as Parts 13 and 17 require. The review
dialog was opened, inspected, and **cancelled**.

**Exact state at the stop point:** PM-00002, line `01a01831-25f8-71ab-b187-cb214264c6d2`,
Glass Jar 250ml, supplier `398830 — OSAMA FAYEZ AHEMD`, Required 100 / Received 0 / Remaining 100,
**40 pcs** staged in the review dialog, Confirm Receipt enabled and **not clicked**.

| Table | Before | After | Δ |
|---|---|---|---|
| `purchase_materials` (PM-00002 status) | `approved` | `approved` | **0** |
| `purchase_material_lines` (supplier, qty) | unchanged | unchanged | **0** |
| `goods_receipts` | 0 | 0 | **0** |
| `goods_receipt_lines` | 0 | 0 | **0** |
| `inventory_items` | 5 | 5 | **0** |
| `stock_ledger_entries` | 22 | 22 | **0** |
| `inventory_receipt_layers` | 2 | 2 | **0** |
| `purchase_orders` | 0 | 0 | **0** |
| `purchase_order_lines` | 0 | 0 | **0** |

Network confirms it independently: **zero `POST` to `/api/goods-receipts`** — only Vite module
`GET`s. No API call bypassed the UI; no database write was made by hand.

## 16. Remaining limitations

1. **The receipt has never actually been posted.** Every step up to the final submit is verified;
   the posting path itself (inventory, ledger, FIFO layer, supplier attribution, duplicate
   rejection) is covered only by the automated suite, not by a live receipt. This is deliberate.
2. **NOT BROWSER VERIFIED** in the strict sense — §14.
3. **Supplier relation not eager-loaded** on the Purchase Material endpoint; the label is resolved
   client-side. Adding `lines.supplier` to the eager-load would be tidier but is a backend change
   and therefore out of scope. **OUT OF SCOPE.**
4. **Receiving Center supplier column** (`receiving-center-page.tsx:115`) reads
   `gr.purchase_order?.supplier?.name`, so a PM-anchored receipt will show "—" there even though
   the supplier is known via the line. Not touched. **OUT OF SCOPE — GO-LIVE FOLLOW-UP.**
5. **Analytics `INNER JOIN purchase_orders` sites** remain open and will silently omit PM-anchored
   receipts. **OUT OF SCOPE — GO-LIVE FOLLOW-UP.**

## 17. Final verdict

**FRONTEND COMPLETE / BROWSER NOT VERIFIED**

| Criterion | Result |
|---|---|
| UI implemented | **PASS** |
| Reaches the certified backend without fabricated data | **PASS** (verified to the submit boundary) |
| Focused frontend tests green | **PASS** — 8/8 |
| Backend receiving gate green | **PASS** — 46/46, 106 assertions |
| ESLint | **PASS** |
| TypeScript | **PASS** — 0 new errors (13 pre-existing, unrelated) |
| Vite build | **PASS** |
| Genuine browser interaction | **NOT BROWSER VERIFIED** |
| Business data unchanged | **PASS** — zero |
| Backend / schema / permissions / contracts changed | **none** |

Phase 2 Part 1 is **NOT** claimed fully CERTIFIED: that requires the actual receiving browser
acceptance, which needs an explicit authorization to post a real receipt against PM-00002 —
an irreversible inventory, ledger and FIFO write. That remains a separate, owner-authorized action.
