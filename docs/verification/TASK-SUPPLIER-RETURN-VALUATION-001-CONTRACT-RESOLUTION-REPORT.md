# TASK-SUPPLIER-RETURN-VALUATION-001 — Contract Resolution Report

**Date:** 2026-08-15 · **Branch:** `develop`
**Outcome:** **STOP BEFORE IMPLEMENTATION — 3 STOP CONDITIONS (PART 17)**
**Production changes made: NONE.** No code, migration, schema, API, UI or data was modified.

---

## 1. Why this stopped

The task authorised implementation *if the business contracts were already defined*. The
focused audit found that **two of the three defects have explicit canonical answers, and the
third does not** — and the undefined one cannot be safely separated from the others.

| Defect | Contract status | Consequence |
|---|---|---|
| **D-6** — non-atomic approval | **DEFINED** — canonical pattern exists | implementable |
| **D-5 / G-6** — FIFO non-consumption | **PARTLY DEFINED** — the engine exists, but its *return* semantics do not | **STOP #1** |
| **D-7** — no returned-vs-received ceiling | **UNDEFINED** — three optional identities, no rule, no code | **STOP #2** |
| — | Supplier return financial/payable effect | **UNDEFINED** | **STOP #4** |

PART 17 is unambiguous: *"If STOP occurs: make NO production changes."* So D-6 was **not**
implemented either, even though its pattern is clear — see §7 for why separating it would be
unsafe rather than merely disallowed.

---

## 2. What already exists and is correct — reuse, do not rebuild

The audit's premise held: there is no need to invent anything.

| Capability | Canonical owner | Verdict |
|---|---|---|
| FIFO consumption engine | `InventoryLayerConsumptionService::consume()` | **Exists and is correct** — oldest-first (`created_at`, `id`), `lockForUpdate`, company-scoped (BUG-08), throws `InsufficientStockException`, writes immutable audit rows, returns weighted cost. Explicitly documented as "MUST be called inside an existing DB::transaction()" |
| Quantity + ledger mutation | `AdjustmentOutAction` | Already used by the return path |
| Atomic approve-and-consume pattern | `ApproveWarehouseLiabilityAction` | **The exact analogue**: one `DB::transaction` wrapping adjustment → `consume()` → status + FIFO cost snapshot (`cost_method = 'FIFO'`) |
| Idempotency marker | `supplier_returns.inventory_restocked` / `inventory_restocked_at` | **Already exists and is already written** by `ReverseSupplierReturnInventoryAction` |

**PART 1 answer: (D)** — a canonical mechanism exists and should be reused. **PART 5 answer:**
an idempotency marker already exists and needs no new mechanism.

So D-5 and D-6 are, mechanically, small changes. The blockers are business rules, not
engineering.

---

## 3. STOP #1 — no canonical FIFO **return** valuation rule

`InventoryLayerConsumptionService::consume()` filters layers by:

```php
->where('product_id',   $productId)
->where('warehouse_id', $warehouseId)
->where('company_id',   $companyId)      // no supplier term
```

**`inventory_receipt_layers.supplier_id` exists** (nullable, indexed, populated by
`CreateReceiptLayersAction`), but **no code anywhere consumes layers supplier-scoped** —
verified across the whole of `Modules/Inventory/ReceiptLayers`; the only supplier filter is a
read-side one in `InventoryLayerController`.

**The conflict:** for a shipment, consuming the oldest layer regardless of supplier is
correct FIFO. For a **supplier return**, it means returning goods to Supplier A can consume
**Supplier B's** cost layers — the returned units get valued at another supplier's price, and
B's layers are depleted by A's return.

This is an accounting decision, not an engineering one, and **no existing contract answers
it**. PART 1 forbids guessing.

### Options

| # | Rule | Consequence |
|---|---|---|
| **1a** | Reuse `consume()` as-is (product + warehouse + company) | Zero new code. True to platform-wide FIFO. Accepts cross-supplier layer consumption |
| **1b** | Supplier-scoped consumption (add `supplier_id` to the layer query) | Returns consume only that supplier's layers. Needs a **new consumption path or a parameter on the canonical engine** — touches certified inbound-adjacent code |
| **1c** | Receipt-scoped consumption (via `goods_receipt_line_id` → that receipt's layer) | Most precise; matches the `original_unit_cost` snapshot already on the return line. Depends entirely on STOP #2 being resolved |

**Recommendation: 1c if STOP #2 resolves to the Goods Receipt Line, otherwise 1a.**
Evidence for 1c: `supplier_return_lines` already carries `goods_receipt_line_id`,
`original_received_qty` **and** `original_unit_cost` — three columns that only make sense if
the return was intended to be anchored to a specific receipt line. Evidence for 1a: it is the
only option requiring no change to a canonical engine.

**1b is not recommended** — it would add a second consumption behaviour to the engine that
the certified inbound path shares, for the narrowest gain.

---

## 4. STOP #2 — no canonical identity for returnable quantity

PART 4 asked which dimension bounds the return. **Three candidates exist. All are optional.
None is enforced. No ceiling logic exists anywhere in the codebase.**

| Candidate | Where | Nullable? | Populated? |
|---|---|---|---|
| `supplier_returns.supplier_id` | header | **NOT NULL** | yes |
| `supplier_returns.purchase_order_id` | header | nullable | optional |
| `supplier_returns.goods_receipt_id` | header | nullable | optional |
| `supplier_return_lines.goods_receipt_line_id` | line | **nullable** | **validation says `nullable`; the controller never sets it** |
| `supplier_return_lines.original_received_qty` | line | nullable | **client-supplied** — not derived, so not authoritative |

Verified by exhaustive grep: **zero** occurrences of returnable / available-to-return /
previously-returned logic in `backend/Modules`.

**The conflict is exactly the one PART 4 anticipated:** multiple identities coexist, and no
canonical rule selects between them. The only mandatory dimension is `supplier_id`, which
alone is too coarse to bound a return (it cannot say *which* delivery the goods came from).

`original_received_qty` cannot rescue this: it is supplied by the client in the create
request, so using it as the ceiling would let the caller declare its own limit.

### Options

| # | Identity | Ceiling formula | Cost |
|---|---|---|---|
| **2a** | **Goods Receipt Line** | `GRL.effectiveReceivedQty() − Σ approved returns against that GRL` | Requires making `goods_receipt_line_id` **mandatory** → breaking change for any client omitting it; needs a backfill decision for existing rows |
| **2b** | Supplier + Product + Warehouse | `Σ received − Σ approved returned`, across all receipts | No schema change; works today. Coarse — cannot distinguish deliveries or costs |
| **2c** | Purchase Order Line | — | **Not viable**: return lines carry no PO-line FK at all |

**Recommendation: 2a.** The schema was clearly designed for it — `goods_receipt_line_id`,
`original_received_qty` and `original_unit_cost` on the same row are only coherent under a
receipt-line anchor. It also makes STOP #1 resolvable as 1c, giving one consistent story for
both quantity and valuation.

**But 2a is a breaking change** to the create contract, and that is the owner's call, not
mine.

---

## 5. STOP #4 — supplier return financial/payable effect undefined

PART 3 requires the **supplier return financial/payable effect** to be inside the atomic
operation. It cannot be, because it does not exist.

- `credit_method`, `credit_amount`, `debit_note_number`, `credit_received_date` are stored on
  `supplier_returns` and written by a controller endpoint (`SupplierReturnController:206-207`).
- **Nothing acts on them.** No payable, no AP posting, no financial integration.
- The prior audit already recorded **G-8**: no Accounts Payable module was found.

So the atomic operation PART 3 describes has an undefined member. Implementing atomicity
around the *other* four mutations would produce something that looks complete but silently
omits the financial leg — which is worse than the current honest gap.

**Decision required:** does a Supplier Return have a financial effect in V1, and if so what
owns it? If the answer is "none in V1", say so explicitly and the atomic operation shrinks to
four members and becomes implementable immediately.

---

## 6. What is NOT blocked

**D-6 (atomicity) is fully specified** and needs no decision of its own:

`SupplierReturnController::approve()` currently updates status to `Approved` and *then* calls
`reverseInventory->execute()` as a separate operation. If the reversal throws, the return is
left Approved with no stock movement. The fix is to move the status transition inside the same
`DB::transaction` as the mutations — precisely what `ApproveWarehouseLiabilityAction` does.

It is held back only by PART 17's "make NO production changes" and by §7.

---

## 7. Why the resolvable parts were not implemented anyway

Beyond the explicit instruction, implementing D-5 without D-7 would be actively unsafe:

FIFO consumption throws `InsufficientStockException` when open layers cannot cover the
quantity. That would *look* like an over-return guard, but it is the wrong guard in three ways:

1. It bounds by **stock on hand**, not by **what was received from this supplier** — a return
   of 200 against a receipt of 100 succeeds whenever 200 units happen to be in the warehouse.
2. It ignores **previously approved returns** entirely.
3. It reports "insufficient stock" for what is actually an invalid business request.

Shipping that would let us mark D-7 "mitigated" while leaving the real defect open — the kind
of false green this programme has repeatedly been bitten by.

---

## 8. Certification matrix (PART 16)

| | Gate | Result | Evidence |
|---|---|---|---|
| A | FIFO return valuation | **NOT RUN** | blocked by STOP #1 |
| B | FIFO layer consumption | **NOT RUN** | blocked by STOP #1 |
| C | Partial return | **NOT RUN** | blocked by STOP #2 |
| D | Full return | **NOT RUN** | blocked by STOP #2 |
| E | Over-return rejection | **NOT RUN** | blocked by STOP #2 — this is the gate that cannot exist without a ceiling rule |
| F | Multiple receipts / layers | **NOT RUN** | blocked by STOP #1 |
| G | Atomic approval | **NOT RUN** | specified (§6) but withheld under PART 17 |
| H | Rollback on failure | **NOT RUN** | depends on G |
| I | Idempotent retry | **NOT RUN** | marker exists (`inventory_restocked`); untested |
| J | Duplicate approval | **NOT RUN** | current `canTransitionTo()` guard unverified |
| K | Tenant isolation | **NOT RUN** | `consume()` is company-scoped by construction; unproven for returns |
| L | Quantity / unit correctness | **NOT RUN** | UoM snapshots exist on the line; unverified |
| M | Certified inbound regression | **NOT RUN** | nothing changed, so nothing to regress |
| N | Static quality | **NOT RUN** | no code changed |

**No gate is marked PASS. Nothing is CERTIFIED.**

---

## 9. Decisions required from the owner

| # | Decision | Recommendation | Blocks |
|---|---|---|---|
| **SR-1** | Should a supplier return consume **that supplier's** FIFO layers, or the oldest layers platform-wide? | **Receipt-scoped (1c)** if SR-2 = Goods Receipt Line; otherwise **as-is (1a)** | A, B, F |
| **SR-2** | What identity bounds returnable quantity — Goods Receipt Line, or Supplier+Product+Warehouse? | **Goods Receipt Line (2a)** — the schema was designed for it. **Note: making `goods_receipt_line_id` mandatory is a breaking change** and needs a backfill ruling for existing rows | C, D, E |
| **SR-3** | Does a Supplier Return have a financial/payable effect in V1? If yes, what owns it? | If AP does not exist, rule **"no financial effect in V1"** — that alone unblocks G and H immediately | G, H |

**SR-3 is the cheapest unblock.** A one-line ruling that returns carry no V1 payable effect
makes the atomicity fix (D-6) immediately implementable and certifiable on its own.

---

## 10. Compliance

Read-only audit. No production code, migration, schema, API, UI, test or data was modified.
The certified `TASK-PROCUREMENT-INBOUND-OWNERSHIP-CLOSURE-001` architecture was not touched
and was not reopened. No second inventory mutation path was created. No coordinating layer
was added. Scope fence respected in full.

**Awaiting SR-1, SR-2 and SR-3 before any implementation.**
