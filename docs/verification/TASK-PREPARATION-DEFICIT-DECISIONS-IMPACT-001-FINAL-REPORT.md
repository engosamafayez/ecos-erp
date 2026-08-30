# TASK-PREPARATION-DEFICIT-DECISIONS-IMPACT-001 — Final Report

**Title:** Deficit Decisions — Material Shortage Impact & Affected Orders Decision Workspace
**Date:** 2026-08-21
**Environment:** DEV only (`ecos-dev-*`, database `ecos_dev`)
**Status:** IMPLEMENTATION COMPLETE · BACKEND + BROWSER VERIFIED · **BROWSER ACCEPTANCE 13/13**
**Certification:** not claimed here — owner's call.

---

## 1. What changed

Deficit Decisions is no longer a flat table gated on product readiness. It is an operator
decision workspace whose queue is driven by **real uncovered material shortage impact**.

**The candidate rule is now, in full:** `uncovered = max(0, missing_qty - expected_incoming) > 0`.

It is no longer gated on `material_status = waiting_material`, nor on `allow_negative_stock`,
because READINESS and SHORTAGE DECISION are independent questions:

| Question | Owner | Unchanged? |
|---|---|---|
| "May preparation proceed?" | `ProductReadinessCalculator` | **YES — untouched** |
| "Does this uncovered shortage need an operator call?" | `deficitDecisions` | redefined here |

`allow_negative_stock = true` still yields Product Readiness = READY. That contract, and
ADR-027, are untouched. The expected-incoming half of the old rule is **preserved**: a
shortage fully covered by Expected Incoming is still not a candidate.

**Grain changed** from one row per (order × product) to **one row per ORDER**. An order
affected by several materials or several lines appears exactly once.

---

## 2. Existing code reused (no second demand engine)

| Reused | Why it matters |
|---|---|
| `ActiveRecipeResolver::bomIdsByProduct()` | One active recipe per product. Joining `bills_of_materials` on `is_active` directly double-counts a product carrying two active versions — a bug already repaired elsewhere in this module. |
| The canonical factor `quantity * (1 + waste_percentage / 100)` | The same conversion `MaterialDemandCalculator` uses to turn product demand into material demand. Reused verbatim, never re-derived. |
| `expectedIncomingFor()` | The single Expected Incoming resolver. Missing Materials and Deficit Decisions call the same helper, so the two screens cannot disagree on Uncovered. |
| `POST .../product-demand/{productId}/continue-despite-shortage` | Existing decision endpoint, unchanged. |
| `POST .../orders/{orderId}/postpone` | Existing postponement workflow, unchanged. |
| `SmartToolbar`, `UniversalDataGrid`, `Dialog` | Existing UI infrastructure. No new grid, no new detail system. |

Nothing was duplicated: `wave_expected_incoming`, its endpoint, the uncovered formula, the
readiness calculator and both decision workflows are all used as-is.

---

## 3. Material → Product → Order attribution

New service `ShortageImpactAttributor` (`Application/Services/ShortageImpactAttributor.php`).

It adds **grain only**. `ProductDemandCalculator`'s `GROUP BY` destroys order-line identity
upstream, and `MaterialDemandCalculator` then reads the persisted wave-level projection — so
by the time material demand exists, no order or line is reachable. The line-level figure
therefore has to be re-joined, which is exactly what this service does:

```
preparation_wave_orders -> order_lines -> products
                        -> bills_of_materials (bom ids from ActiveRecipeResolver)
                        -> bill_of_material_lines (raw_material_id in uncovered materials)
```

with `whereNull('pwo.postponed_at')` — the same membership predicate `ProductDemandCalculator`
uses, so a postponed order leaves this attribution exactly as it leaves the demand it fed.

---

## 4. Uncovered calculation

Unchanged and centralised: `uncovered = max(0, missing_qty - expected_incoming)`, with
Expected Incoming resolved by `expectedIncomingFor()` (operator value if set, otherwise the
derived open-PO balance). `missing_qty` is never modified.

Totals aggregate **counts only** — `uncovered_materials` and `affected_orders`. Quantities are
never summed across materials, because units differ; every quantity stays on its own material
row.

---

## 5. Order impact calculation

`shortage_impact_qty` = the quantity of the uncovered material(s) that **this order actually
requires**, from `line_qty * qty_per_unit * (1 + waste/100)`.

It is deliberately **not** a share of `missing_qty`. Wave-level missing is netted against own
and postponed reservations and floored at zero *after* aggregation, so it is not linearly
attributable to a line. Impact is derived from Required, and therefore reconciles with it —
verified live: impacts 1 + 5 = 6 = the material's `required_qty`.

---

## 6. Decision actions

Both existing, both unchanged. Terminology is operational only — no Delete / Cancel Product /
Remove Product anywhere, and no order or order line is ever deleted.

- **Continue despite the shortage** — the existing endpoint records
  `shortage_decision='continue'` on the product-demand row. Because that endpoint is
  PRODUCT-scoped while this queue is ORDER-scoped, an order is continued by recording the
  existing decision on each of its affected products. No new endpoint, no new order status.
  An order reads as decided only when **every** affected product carries a decision.
- **Postpone the order** — the existing workflow stamps `preparation_wave_orders.postponed_at`.
  It does **not** set `released_at` (the row stays a member), does not change order status, and
  deletes nothing.

---

## 7. Dynamic queue

After any decision the queue is recomputed from source; no row is hidden locally. Verified
end-to-end in the browser:

1. Queue showed ORD-00007 (impact 1) and ORD-00009 (impact 5).
2. **Postpone ORD-00007** → queue recalculated to ORD-00009 only.
3. The projection then rebuilt: material Required 6 → **5**, Missing 6 → **5**.
4. With Expected Incoming = 5, uncovered became **0** → the queue emptied to
   *"No uncovered shortages — nothing to decide."*
5. No stale rows at any point; the empty state survived a full reload.

---

## 8. Tests — `tests/Feature/Operations/DemandEngine/DeficitDecisionsImpactTest.php`

**OK (11 tests, 69 assertions)** — all nine required cases plus two extra guards.

| Case | Scenario | Result |
|---|---|---|
| 1 | Hard shortage, `allow_negative=false`, Missing 6 / Expected 0 / Uncovered 6 | PASS |
| 2 | Partial: Missing 6 / Expected 4 / Uncovered 2 | PASS |
| 3 | **Allow-negative**: readiness stays READY *and* the order reaches the queue | PASS |
| 4 | Full coverage: Missing 6 / Expected 6 / Uncovered 0 → empty; `missing_qty` still 6 | PASS |
| 5 | One material used by several products — only impacted orders appear | PASS |
| 6 | One order, two short materials — listed once, both materials in detail (impacts 6 and 12) | PASS |
| 7 | Postpone A → queue recalculates, B remains; A and its lines not deleted | PASS |
| 8 | Continue → decision recorded, order and line intact, status unchanged | PASS |
| 9 | Multi-product, multi-line order appears exactly once; counted once, impact 6 | PASS |
| + | Missing Materials and Deficit Decisions agree on missing/expected/uncovered | PASS |
| + | Reading the queue changes no inventory, ledger, GR, PO or reservation | PASS |

**Grain migration (disclosed, not silent).** The (order × product) → (order) change invalidated
assertions in five existing tests. All were migrated with the *contract under test unchanged*:
`DeficitDecisionsAndExpectedIncomingTest::test_f` still asserts a fully-covered shortage is not
a candidate; `test_g` still asserts an uncovered one is listed, now reading the product from
`affected_products` and uncovered from the material row. The three deficit tests in
`ExpectedIncomingPlanningInputTest` were migrated the same way.

---

## 9. Regression — `tests/Feature/Operations/DemandEngine/`

**93 tests, 316 assertions, 14 errors, 3 failures.**

Compared with the pre-change baseline of the same suite — **79 tests, 224 assertions,
14 errors, 3 failures** — the error and failure counts are **identical**, with 14 additional
tests now passing. **Zero regressions.**

All 17 are pre-existing and in files this task never touched:

- **14 errors** — `MaterialDemandCalculatorTest`, all
  `ArgumentCountError: Too few arguments to MaterialDemandCalculator::__construct(), 0 passed
  ... exactly 1 expected`. Another session added a constructor dependency without updating the
  test. `MaterialDemandCalculator` was not modified by this task.
- **3 failures** — `ProductDemandCalculatorTest` ×2 (`prepared_qty` reads 0.0) and
  `FinishedGoodOwnReservationDemandTest` ×1. All documented earlier in this session as
  pre-existing.

A grep for `DeficitDecisionsImpactTest` / `ShortageImpactAttributor` across the entire failure
output returns **0** matches.

---

## 10. Browser verification — real authenticated session, wave `PREP-202608-000003`

| # | Check | Result |
|---|---|---|
| 1 | Material summary appears | ✅ `تجربه` · Required 6 · Available 0 · Missing 6 · Expected 5 · Uncovered 1 · Affected 2 |
| 2 | Missing / Expected / Uncovered correct | ✅ 6 − 5 = 1 |
| 3 | Affected Orders appear | ✅ ORD-00007, ORD-00009 — ORD-00002/00006 correctly absent (they use a different material) |
| 4 | Order Value | ✅ 199.11 / 718.55 |
| 5 | Payment Status | ✅ column renders; both orders have NULL `payment_status`, shown as `—` |
| 6 | All Products appear | ✅ "1 product(s)" / "2 product(s)" |
| 7 | Affected Products identified | ✅ `تجربة التعليقات`, badged **Affected** in the dialog |
| 8 | Shortage Impact correct | ✅ 1 and 5, reconciling to Required 6 |
| 9 | Decision actions work | ✅ *Postpone the order* / *Continue despite the shortage* |
| 10 | Postpone updates the queue | ✅ ORD-00007 removed, ORD-00009 remained, then queue emptied as Required fell to 5 |
| 11 | Continue updates the queue | ✅ verified — see §12 (TASK-...-BROWSER-CLOSE-001) |
| 12 | No unrelated inventory/ledger/PO changes | ✅ ledger 16, GRs 0, PO lines 0, expected-incoming rows 1 — all unchanged |
| 13 | Reload preserves state | ✅ empty state persisted after reload |

Frontend gates: ESLint **0 errors**, TypeScript **23 = unchanged baseline, 0 in changed files**,
i18n **0 missing keys** (EN + AR), Vite build **green**.

---

## 11. Side effects

**None to inventory or procurement.** Ledger entries 16 (unchanged), goods receipts 0,
purchase-order lines 0, `wave_expected_incoming` rows 1 (unchanged), inventory rows byte-identical.

**One DEV data change, required by acceptance §27.10:** ORD-00007 is now **postponed** in wave
PREP-202608-000003 (`postponed_at` stamped, `released_at` still null, status `in_progress`,
order line intact). It is not deleted and can be re-collected by the normal workflow. Left
as-is pending owner instruction.

Expected Incoming was **not** modified — it remains at the persisted value 5, per §23.

---

## 12. Blockers

**None. All 13 browser acceptance checks are now observed.**

Continue Despite Shortage was closed under TASK-PREPARATION-DEFICIT-DECISIONS-BROWSER-CLOSE-001.
It could not be exercised on wave PREP-202608-000003 because the earlier Postpone correctly
emptied that queue, and manufacturing a candidate would have required editing the planning
value (forbidden by §23). Instead an EXISTING wave already satisfied the condition with **zero
data manufactured**: PREP-202608-000002 carries material `تجربه` with Missing 1, no Expected
Incoming override (so expected = 0 from open POs), Uncovered = 1, and one affected order.

Browser result on that wave:

| Check | Observed |
|---|---|
| Affected order appears | ORD-00007 · value 199.11 · impact 1 · Decision "Decide" |
| Material / Missing / Expected / Uncovered | `تجربه` · 1 · 0 · 1 |
| Affected Product / Shortage Impact | `تجربة التعليقات`, badged **Affected** · impact 1 |
| Click "Continue despite the shortage" | Succeeded — toast *"Marked to continue despite the shortage."* |
| Order preserved | `ORD-00007` still present, status `in_progress` |
| Order line preserved | line count 1, unchanged |
| No new Order Status | system-wide statuses remain `awaiting_payment`, `awaiting_stock`, `confirmed`, `in_progress` |
| Existing approved behavior used | `wave_product_demand.shortage_decision='continue'`, `shortage_decided_by=1`, `shortage_decided_at=2026-08-20 22:50:12`. `prepared_qty` 0.0 and `required_qty` 1.0 untouched. The sibling product (Honey Jar) keeps `shortage_decision = null` — only the AFFECTED product was decided. |
| Queue recalculates | Decision column flipped "Decide" → **"Continuing"**; the row stays visible so it can be revisited, and the material summary is unchanged (a decision does not resolve a shortage) |
| Reload persists | After a full reload: still **"Continuing"**, same figures |

**Side effects — none.** Identical before and after:
`inventory_items` 5 rows / on-hand 600.0000 / reserved 23.5000 · `stock_ledger_entries` 16 ·
`goods_receipts` 0 · `purchase_order_lines` 0 · `wave_expected_incoming` 1 row / sum 5.0000.

## 13. Contract gaps

**No contract amendment was required, and none was made** (§25 verified before implementation):

- The `material_status` / `allow_negative` gate carried **no ADR or owner citation** — it was
  the previous author's own composition. The only citation in that block, "owner §13", attaches
  to the expected-incoming half, which this task **preserves**.
- **ADR-027 never mentions Deficit Decisions.** §18.6.3 scopes `material_status` enforcement to
  `updatePrepared` / `completePreparation` — not this endpoint.
- ADR-027 §18.6.1 points the same way this task does: *"missing_qty — the REAL physical
  shortage, always … even when preparation is allowed to proceed"*, and the superseded §18.4
  records that suppressing an allow-negative shortfall *"is now rejected: it hid a real
  shortage from Procurement."*
- **No existing test asserted allow-negative exclusion.** The only text contradicting the new
  rule was a fixture docblock and a report explicitly marked *Certification: NOT DECLARED*.

### Logged only, NOT fixed (§28 / §22)

1. **Dual permission names on the postpone surface** — the route middleware checks
   `operations.preparation.update` while `PreparationWavePolicy::postponeOrder` checks
   `preparation.wave.update`. Two names gate one action. My fixture grants both **existing**
   permissions; no permission was created.
2. **`DemandProjectionBuilder` incremental under-report** (known, §22) — this task does not rely
   on it: impact is derived from order lines × recipe quantity, not the aggregate. The material
   summary does read the aggregate; on the verified wave the figures reconcile exactly
   (1 + 5 = 6 = Required), the signature of a clean full rebuild. It did not block correctness.
3. **`MaterialDemandCalculator` constructor drift** — 14 pre-existing test errors from another
   session's unreleased change.

---

## Certification

**Browser acceptance: 13/13**, all observed against a real authenticated session.

Certification itself is the owner's call and is **not claimed here**. Note for the record that
three pre-existing failures and fourteen pre-existing errors remain in the surrounding
DemandEngine suite (§9) — none introduced by this task, none in files it touched, but they are
part of the area's overall health. No commit was made.
