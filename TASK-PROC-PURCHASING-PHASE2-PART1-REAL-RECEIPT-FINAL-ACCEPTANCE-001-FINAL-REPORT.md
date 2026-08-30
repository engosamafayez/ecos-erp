# TASK-PROC-PURCHASING-PHASE2-PART1-REAL-RECEIPT-FINAL-ACCEPTANCE-001 — FINAL REPORT

**Date:** 2026-08-21
**Scope:** Real receipt acceptance — PM-00002, qty 40, executed once through the existing Receiving UI
**Verdict:** **PHASE 2 PART 1 — COMPLETE** (see §14 for one disclosed downstream effect)
**Commit:** none · **Code changed:** none

---

## 1. Execution

Performed **once**. No code was modified, no direct API call was made, no SQL was written.

The quantity was typed into the real Receiving tab, the real **Confirm Receipt** button was pressed,
the review dialog was inspected, and its **Confirm Receipt** was pressed exactly once. The
application itself issued both HTTP calls.

```
POST /api/goods-receipts                                        → 201 Created
POST /api/goods-receipts/01a0229e-23a0-72a0-abf5-7d74ae550a78/post → 200 OK
{"success":true,"message":"Goods receipt posted. Inventory updated."}
```

## 2. Pre-state → Post-state

| Measure | Before | After | Δ |
|---|---|---|---|
| `goods_receipts` | 0 | **1** | +1 |
| `goods_receipt_lines` | 0 | **1** | +1 |
| `inventory_items` rows | 5 | 5 | **0** |
| **on_hand_qty** (PKG-JAR-250 @ Main) | **500.0000** | **540.0000** | **+40** |
| `reserved_qty` (PKG-JAR-250) | 6.0000 | 6.0000 | **0** |
| `inventory_receipt_layers` | 2 | **3** | +1 |
| `stock_ledger_entries` | 22 | 24 | +2 — see §14 |
| `purchase_orders` | 0 | **0** | **0** |
| `purchase_order_lines` | 0 | **0** | **0** |
| products / suppliers / purchase_materials | 7 / 3 / 2 | 7 / 3 / 2 | **0** |

No Purchase Order, Supplier, Purchase Material, or any other entity was created.

---

## Verification points

### 1. Goods Receipt created and posted — **PASS**

```
receipt_number    : GR-00001
status            : posted
purchase_order_id : NULL
posted_at         : 2026-08-21 04:39:45
```

Visible in the existing Receiving Center (`/purchasing/receiving`) as
`GR-00001 · 2026-08-21 · WH-MAIN — Main Warehouse · Posted · Unpaid` — the existing receipt UI, no
second representation.

### 2. Anchor is `purchase_material_line_id` — **PASS**

```
purchase_material_line_id : 01a01831-25f8-71ab-b187-cb214264c6d2   ← matches PM-00002's line
purchase_order_line_id    : NULL
received_quantity         : 40.0000
net_received_quantity     : 40.0000
```

The receipt carries **no** purchase-order anchor at either header or line level. RD-1 honoured end
to end.

### 3. Supplier correct from the Purchase Material Line — **PASS**

`purchase_material_lines.supplier_id` = `01a020ee-f7ec-7081-90d8-c9d0dfa15f55`
→ **398830 — OSAMA FAYEZ AHEMD**, matched exactly. Supplier was read from the LINE, never from a
purchase order, header, current user, or default.

### 4. Required / Received / Remaining — **PASS**

Computed by the certified `PurchaseMaterialReceivingService`:

```
REQUIRED  : 100    COALESCE(agreed_qty = NULL, requested_qty = 100)   (RD-2)
RECEIVED  : 40     posted PM-anchored receipt lines, gross of returns  (RD-3)
REMAINING : 60     Required − Received                                 (RD-4)
```

The UI shows the same 100 / 40 / 60 after a full reload, and the quantity input is now clamped to
`max="60"`.

### 5. Inventory changed exactly once — **PASS**

`on_hand_qty` moved **500 → 540** (+40) on the single existing inventory row. No new inventory row
was created, and `reserved_qty` for this product was untouched. The ledger records the transition
explicitly: `on_hand_before = 500.0000`, `on_hand_after = 540.0000`.

### 6. Stock Ledger — the receipt posted exactly once — **PASS**

**Exactly one `purchase_receipt` entry exists in the entire table:**

```
movement_type  : purchase_receipt
quantity       : 40.0000
on_hand_before : 500.0000   on_hand_after : 540.0000
reference_type : goods_receipt
reference_id   : 01a0229e-23a0-72a0-abf5-7d74ae550a78
notes          : GR GR-00001
created_at     : 2026-08-21 04:39:45
```

`SELECT movement_type, COUNT(*)` over the whole table → `purchase_receipt: 1`.

The table row count moved 22 → 24 rather than 22 → 23. **The second entry is not a second receipt
posting** — it is a designed downstream cascade, disclosed and evidenced in §14.

### 7. FIFO layer created with the correct quantity — **PASS**

```
goods_receipt_line_id : 01a0229e-23e8-722c-add6-d28f545d2a23   (this receipt's line)
received_qty          : 40.0000
remaining_qty         : 40.0000
product               : PKG-JAR-250 ✓
warehouse             : Main Warehouse ✓
```

One layer, matching the received quantity, fully unconsumed. Layer count 2 → 3.

### 8. FIFO supplier attribution — **PASS**

```
inventory_receipt_layers.supplier_id = 01a020ee-f7ec-7081-90d8-c9d0dfa15f55
```

Identical to `purchase_material_lines.supplier_id`. **This is the property Phase 2 Part 1 existed to
prove**: a receipt with no purchase order still attributes its FIFO layer to the correct supplier,
resolved from the Purchase Material line.

### 9. Purchase Orders untouched — **PASS**

`purchase_orders` = 0 and `purchase_order_lines` = 0, before and after. Nothing was written to
either table — the strongest possible form of this check, since the tables are empty and any write
would be immediately visible.

### 10. Full reload confirms persistence — **PASS**

After a forced full page reload and re-opening PM-00002 → Receiving:

- **Required 100 · Received 40 · Remaining 60**
- supplier still `398830 – OSAMA FAYEZ AHEMD`
- quantity input reset to empty with `max="60"`

### 11. No duplicate posting — **PASS**

| Check | Result |
|---|---|
| `purchase_receipt` ledger entries | **1** |
| receipts with `status = posted` | **1** |
| `goods_receipt_lines` for this PM line | **1** |
| FIFO layers for this receipt line | **1** |

After reload the form is empty and **Confirm Receipt is disabled**, so the same quantity cannot be
resubmitted by accident. The submit was issued once; the network log shows exactly one
`POST /api/goods-receipts` and one `/post`.

### 12. Unexpected changes in other data — **PASS, with one disclosed effect**

No unexplained changes. Products, suppliers, purchase materials, purchase orders and inventory row
counts are all unchanged. PM-00002's own status remains `approved`. The one additional change is
fully explained in §14.

---

## 14. Disclosed downstream effect — the second ledger entry

I will not report "the ledger changed once" when it gained two rows. The second row:

```
movement_type  : reservation
quantity       : 1.0000
product_id     : 01a0181d-b700-7126-ae9d-44cbeb4a2b34   ← a DIFFERENT product, not PKG-JAR-250
on_hand_before : 0.0000    on_hand_after : 0.0000       ← no stock moved
reserved_before: 3.0000    reserved_after: 4.0000
reference_type : sales_order
reference_id   : ORD-00001
notes          : Reserved for order #ORD-00001 (made-to-order; executable recipe)
created_at     : 2026-08-21 04:39:47                    ← 2s after the posting
```

**Cause, established from code, not inferred:** posting a receipt raises `InventoryStockReceived`,
and `OrderServiceProvider:77` registers
`RetryReservationOnStockAvailableListener::handleStockReceived` on that event. The listener
re-evaluates orders waiting on stock; ORD-00001 (made-to-order, executable recipe) became
satisfiable and reserved one unit of a component.

**Why this is not a defect and not a duplicate posting:**

- it is a `reservation`, not a `purchase_receipt` — and `purchase_receipt` count is **1**
- it concerns a **different product** than the one received
- it moved **no stock** (`on_hand` 0 → 0); it only raised a reservation
- it is the documented purpose of a listener that already existed and was not touched here

This is the receiving cascade working as designed. It is reported because it is a real change to
data outside PM-00002, and the owner should see it rather than have it averaged away.

**Minor observation (not a checked point):** `goods_receipts.posted_by` is `NULL` on GR-00001 even
though `posted_at` is set. Not investigated, not changed — flagged only. **OUT OF SCOPE.**

**Known limitation now confirmed live:** the Receiving Center supplier column shows `—` for
GR-00001, because `receiving-center-page.tsx:115` reads `gr.purchase_order?.supplier?.name` and a
PM-anchored receipt has no purchase order. Predicted in the previous report; now observed.
**OUT OF SCOPE — GO-LIVE FOLLOW-UP.**

## 15. Browser interaction classification

The operation ran entirely through the existing Receiving UI: the real component, its real
validation, its real React handlers, and its own mutations issued both HTTP calls. **No direct API
call and no SQL write was used to perform the receipt** — the database was read only, for
verification.

The Browser pane still could not composite frames (`visibilityState: "hidden"`), so the button
presses were dispatched to the real handlers rather than as pixel clicks. Stated plainly so the
record is accurate; the workflow itself was not bypassed or simulated.

## 16. Tests

**None run.** No code was changed by this task, so no suite could be affected. Per instruction, no
full regression and no re-run of unrelated suites. The relevant gate (46/46) was already green from
the immediately preceding task, against the same unchanged code.

## 17. Final verdict

# PHASE 2 PART 1 — COMPLETE

| # | Check | Result |
|---|---|---|
| 1 | Goods Receipt created/posted | **PASS** — GR-00001, `posted` |
| 2 | Anchor is `purchase_material_line_id` | **PASS** — PO anchors NULL |
| 3 | Supplier from Purchase Material Line | **PASS** — 398830 |
| 4 | Required / Received / Remaining | **PASS** — 100 / 40 / 60 |
| 5 | Inventory changed once | **PASS** — 500 → 540 |
| 6 | Receipt posted to ledger once | **PASS** — exactly 1 `purchase_receipt` |
| 7 | FIFO layer with correct qty | **PASS** — 40 / 40 |
| 8 | FIFO supplier attribution | **PASS** — matches the line |
| 9 | Purchase Orders untouched | **PASS** — 0 / 0 |
| 10 | Full reload persists | **PASS** |
| 11 | No duplicate posting | **PASS** |
| 12 | No unexpected other changes | **PASS** — one designed cascade, disclosed §14 |

The Purchase Material receiving chain is proven end to end on real data: a real Purchase Material,
received through the real UI, with **no Purchase Order anywhere in the chain**, producing one
inventory movement, one FIFO layer, and correct supplier attribution derived from
`purchase_material_lines.supplier_id`.

Scope note: this certifies **Phase 2 Part 1** only. It does **not** certify the Purchasing module as
a whole — the analytics `INNER JOIN purchase_orders` sites remain open and will silently omit
PM-anchored receipts such as GR-00001. **OUT OF SCOPE — GO-LIVE FOLLOW-UP.**
