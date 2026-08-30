# TASK-ORDER-INVENTORY-FULFILLMENT-CONTRACT-RECOVERY-001 — Engineering Report

**Date:** 2026-08-13 · **Branch:** `develop` · **DIAGNOSTIC ONLY — no production code was modified**

> # VERDICT: **RECOVERY BLOCKED — BUSINESS DECISION REQUIRED**
>
> The contract is recovered and the "every order goes straight to In Progress" symptom is **fully explained by two independent causes**, both proven from source. Neither is a bug in the ordinary sense — each is doing exactly what its author intended. What is missing is a ruling on **what an operator's Entry Status selection is supposed to mean**.
>
> Alongside that, one genuine engineering defect was found that is *not* a business question: **the brand order policy still stores pre-V3 status vocabulary, and the write path and read path disagree about how to handle it** — one silently migrates, the other silently discards.

---

## 1. Executive Summary

Three findings dominate.

**Finding 1 — the reported symptom has TWO independent causes.** Either alone reproduces it.

**Finding 2 — the V3 status rename was never applied to configuration.** `orders.status` rows were migrated in July; `config_brand_policies` JSON was not. Every consumer since has coped with the mismatch differently, and two of them cope in *opposite* directions.

**Finding 3 — no evidence of Inventory / Reservation / Preparation contract corruption.** The ADR-027 chain is intact. The drift is concentrated in the **Order status vocabulary and its configuration**, not in availability or reservation semantics.

### Runtime confirmation (read-only, `ecos_dev`)

Two orders created by the user today, after the status-validation fix:

```
ORD-00003  status=in_progress  previous_status=new  reservation_status=reserved  source=branch_coverage  22:18
ORD-00004  status=in_progress  previous_status=new  reservation_status=reserved  source=branch_coverage  22:19
```

Created as `new`, advanced to `in_progress` within the same request. The symptom is real and reproduced from live data.

## 2. Source-of-Truth Hierarchy

| Authority | Source | Governs |
|---|---|---|
| **1** | **ADR-027** (v1.2) — *"This matrix is the law"* (§9) | Reservation, warehouse assignment, negative stock, recipe gate, awaiting_stock |
| **1** | **ADR-005** — ERP owns lifecycle; channel statuses are never first-class ERP states | Status vocabulary ownership |
| **2** | **Migration `2026_07_22_100000_simplify_order_lifecycle_v3`** | The V3 rename itself — executed, irreversible in practice |
| **2** | `OrderStatus` enum (11 cases) | The canonical status vocabulary |
| **3** | Certified task reports (F4/Option B, Preparation Entry Gate, Branch Assignment) | Component behaviour |
| **4** | Service/class docblocks | Descriptive |
| **5** | **Configuration data** (`config_brand_policies`, `wave_engine_configurations`) | **Never authoritative — and currently stale (§4)** |

**Standing gap:** ADR-005 §5 promises a dedicated order-FSM ADR. **It does not exist.** No ADR-level authority defines order status transitions — which is precisely why the two causes below could both be "intended" and still contradict each other.

## 3. Order Lifecycle Recovery

Canonical enum, 11 cases (`OrderStatus.php:17-31`):

```
new · in_progress · ready_for_dispatch · out_for_delivery · delivered
awaiting_payment · awaiting_stock · scheduled · on_hold · cancelled · returned
```

Header states the intended flow (`:10`): `New → In Progress → Ready for Dispatch → Out for Delivery → Delivered`.

**A model-level guard protects the column.** `Order.php:146-153` blocks direct `status` writes outside `FulfillmentEngine`. One writer (`VerifyPaymentAction`) violates it but is **unreachable** due to a separate enum-vs-string comparison defect — logged in §18, not fixed.

## 4. Order Creation Contract

```
POST /orders/manual  (routes/api.php:536)
  → OrderController::storeManual                        (:121)
  → CreateManualOrderAction::execute                    (:123)
  → status resolved                                     (:127)
  → row inserted                                        (:177)
  → IF status === new  → ProcessOrderWorkflow           (:188-196)
```

### CAUSE 1 — the auto-initiate gate

`CreateManualOrderAction.php:188`:

```php
if ($order->status === OrderStatus::NewOrder) {
    $this->fulfillmentEngine->run($this->initiateWorkflow, $order->fresh(), [], $actorId);
}
```

`ProcessOrderWorkflow.php:169` then writes unconditionally:

```php
$order->update(['status' => OrderStatus::InProgress]);
```

**A user-chosen `new` therefore never survives its own creation request.** Only three escapes exist: no warehouse assigned (early return, status untouched — the repair from TASK-ORDER-PREPARATION-FLOW-REPAIR-001), reservation shortfall (`awaiting_stock`), or the workflow throwing and being swallowed at `:197-202`.

**The gate is on `new` only.** `awaiting_payment`, `scheduled` and `on_hold` survive untouched. So the behaviour is *asymmetric*: one Entry Status is auto-advanced, the others are honoured. From an operator's seat that is indistinguishable from a bug even though each half is deliberate.

### CAUSE 2 — the payment-method status preference (independent)

`CreateManualOrderAction.php:243`:

```php
private const PAYMENT_CLEAR_STATUS_PREFERENCE = ['in_progress', 'new'];
```

Applied at `:302-309` whenever the submitted status fails the membership test at `:297`:

```php
if ($method !== '') {
    $enabledSet = array_flip($enabled);
    foreach (self::PAYMENT_CLEAR_STATUS_PREFERENCE as $preferred) {
        if (isset($enabledSet[$preferred])) { return $preferred; }
    }
}
```

**Any order with a payment method whose submitted status is not in the policy-enabled set lands on `in_progress` regardless of the dropdown** — before Cause 1 even runs.

**Either cause alone reproduces the symptom.** Fixing one would leave the other in place. This is why the report treats them separately.

## 5. Processing Regression Trace — CAUSE 3: the configuration vocabulary split

This is the genuine engineering defect, and it explains why Cause 2 fires so readily.

**The brand order policy still stores pre-V3 statuses.** `BrandPolicy.php:154`:

```php
'manual' => ['pending', 'awaiting_payment', 'processing', 'confirmed'],
```

Three of four do not exist in the V3 enum. Migration `2026_07_17_000001_normalize_manual_order_entry_statuses.php:22-27` hard-writes the same legacy set into the database.

**The V3 rename never touched configuration.** `2026_07_22_100000_simplify_order_lifecycle_v3` rewrites only the `orders` table (`:30`), never the policy JSON.

Two consumers then diverge — and this is the crux:

| Path | Behaviour | Effect |
|---|---|---|
| **Write** — `CreateManualOrderAction:229-237` `LEGACY_STATUS_MAP` applied at `:285` | **Silently migrates**: `pending→new`, `processing→in_progress`, `confirmed→in_progress` | resolves to `['new','awaiting_payment','in_progress']` |
| **Read** — `BrandConfigurationController:132-139` `OrderStatus::from()` in try/catch | **Silently discards** anything invalid | `GET /policies/order` returns only `['awaiting_payment']` |

**The UI and the server therefore disagree about what the operator may choose**, from the same stored config.

Worse, the read path's own fallback is invalid: `:142-144` sets `$valid = ['pending']` — a value the enum cannot construct, so the fallback produces a dropdown option the API would reject with 422.

The frontend submits the raw policy string (`manual-order-form.tsx:1688-1697`), and validation is V3-only (`StoreManualOrderRequest:28,83`) — so a legacy value from the form is a **422**, not a silent fallback.

**The same class of staleness was already found and fixed once**, in `wave_engine_configurations.eligible_order_statuses = ["confirmed"]` (TASK-ORDER-PREPARATION-FLOW-REPAIR-001). This is the second instance. **Configuration was never migrated to V3 anywhere.**

## 6. Confirmed Recovery — answer: **C, merged**

`confirmed` and `processing` are **not** V3 statuses. Both were **merged into `in_progress`**, verbatim from `2026_07_22_100000_simplify_order_lifecycle_v3.php:14-17`:

```
*   processing    → in_progress
*   confirmed     → in_progress  (merged: was a separate confirmation step)
*   preparing     → in_progress  (invisible engine state; order remains In Progress)
```

Executed at `:30`. Corroborated independently by `WooCommerceOrderStatusTranslator.php:24-26` and `PreparationSessionPolicy:86`.

**Not re-added, as instructed.** But note the consequence: **there is no longer a distinct "confirmed" state**, so any workflow or UI that still presents "Confirm" as a discrete step is presenting a state the domain no longer has. `ORD-00002`'s history shows a `confirm_order` event on 2026-08-12 — the *workflow* still exists even though the *status* does not.

## 7–11. Inventory, Availability, Reservation, Recipe, Awaiting Stock

**No contract corruption found.** The ADR-027 chain is intact and matches the implementation as certified earlier in this programme:

| Rule | Source | Status |
|---|---|---|
| `available = on_hand_qty − reserved_qty` | ADR-027 §3 ("universal, non-negotiable") | **ALIGNED** |
| Material passes when `available > 0 OR allow_negative_stock` | §16.3 | **ALIGNED** |
| Recipe executable iff **every** material passes | §16.3 | **ALIGNED** |
| FG `allow_negative_stock` → Case 3 logical commit, OH goes negative at **shipment** | §3 Case 3, §6 | **ALIGNED** (reservation), **KNOWN GAP** at issuance — ADR-027 §15 **C1** records `DirectIssueStockAction` still throwing; unverified here |
| Recipe availability is **company**-scoped, fail-closed | §16.4/§16.5 | **ALIGNED** |
| `awaiting_stock` = FG shortage only | §3 Case 4 | **ALIGNED** since TASK-ORDER-PREPARATION-FLOW-REPAIR-001 |
| Warehouse missing → `reservation_status = pending`, lifecycle untouched | §2, §10 | **ALIGNED** since the same repair |
| `untracked` (no row) ≠ tracked-zero | `AvailabilityState::fromAvailable()` | **ALIGNED** |

**Depth limitation, stated honestly:** §6–§11 rest on ADR-027 plus prior in-programme verification rather than a fresh exhaustive re-trace. The four scenario cases (A–E) and the untracked/zero/negative representation matrix were **not** re-derived line-by-line in this pass. Given the drift found is concentrated entirely in status vocabulary, I judged a re-trace of the inventory chain lower value than reporting the status findings precisely — but that is a judgement, and it is the main thing I would deepen if you want this pass extended.

## 12. Preparation Seam

Repaired earlier in this programme and believed intact:

- `BranchAssignmentEngine` now emits the canonical `WarehouseAssigned` alongside `BranchAssigned` (previously `BranchAssigned` had **zero** listeners).
- `PreparationReleaseEngine` gates on **status + warehouse only** — no reservation or material prerequisite.
- `PreparationSessionPolicy::defaultEligibleStatuses()` = `['new','in_progress']`.
- `wave_engine_configurations.eligible_order_statuses` corrected from `["confirmed"]` to `['new','in_progress']`.

**Not re-verified at runtime in this pass** (the shared runner was occupied by another agent's suite; this diagnostic stayed read-only on `ecos_dev`).

## 13–14. Cross-Domain Matrix & Certification Provenance

**Every component certification issued in this programme remains valid.** Nothing found here contradicts one.

The failures found are **cross-domain seams and configuration**, which component certification structurally cannot detect:

| Certification | Still valid? | Why the new findings don't revoke it |
|---|---|---|
| F4 / Option B, Recipe Gate, Cross-Brand Reuse | ✅ | Inventory/recipe semantics unaffected |
| Preparation Entry Gate | ✅ | Gate logic unchanged |
| `MaterialDemandCalculator` | ✅ | Untouched; parity `ce69612a` |
| Warehouse Coverage + Brand Assignment | ✅ | Assignment path unaffected by status vocabulary |
| Order status validation fix (`StoreManualOrderRequest`) | ✅ | Confirmed working — ORD-00003/4 exist because of it |
| **Order creation *contract*** | ❌ **NEVER CERTIFIED** | No certification ever covered "what status does a created order end up in" |

**COMPONENT CERTIFIED / CROSS-DOMAIN CONTRACT NOT CERTIFIED** is the accurate characterisation.

## 15. Regression Provenance

The V3 rename (2026-07-22) migrated `orders` but not configuration. Every subsequent symptom in this family traces to that single omission:

1. `wave_engine_configurations = ["confirmed"]` → Wave Engine inert (found and fixed).
2. `StoreManualOrderRequest` hardcoded V2 whitelist → order creation blocked (found and fixed).
3. `config_brand_policies` legacy entry statuses → **still present**, with write/read paths diverging (this report).

Three instances, one root: **configuration was never migrated to V3.**

## 16. Runtime Evidence

Read-only on `ecos_dev` (556 tables, 4 orders). **No mutation.** No order, reservation, inventory row, session or wave was created. `SELECT DATABASE()` verified. `ecos_dev_test` deliberately untouched — another agent's phpunit process was observed in the runner.

## 17–18. Contract Conflicts

| # | Conflict | Sources | Severity |
|---|---|---|---|
| **C1** | **Entry Status is offered to the operator but overridden for `new`** | `CreateManualOrderAction:188` vs the UI's Entry Status control | **Blocking — business** |
| **C2** | **Payment method silently overrides the chosen status** | `CreateManualOrderAction:243,302-309` | **Blocking — business** |
| **C3** | **Write path migrates legacy config; read path discards it** | `:229-237/:285` vs `BrandConfigurationController:132-139` | **Engineering** |
| **C4** | **Read-path fallback `['pending']` is not a valid status** | `BrandConfigurationController:142-144` | **Engineering** |
| **C5** | **`confirm_order` workflow persists though `confirmed` status was merged away** | `2026_07_22_100000` vs the surviving workflow | **Needs ruling** |
| **C6** | `VerifyPaymentAction` writes status outside `FulfillmentEngine`, violating the model guard; currently unreachable via an enum-vs-string comparison defect | `Order.php:146-153` vs `VerifyPaymentAction` | **Engineering — latent** |
| **C7** | ADR-005 §5 promises an order-FSM ADR that does not exist | ADR-005 | **Governance** |

## 19. Required Decisions

### BLOCKING BUSINESS DECISIONS — I cannot resolve these from source

**D1. What does the operator's Entry Status selection mean?**
(a) *Pick-and-stay* — the order remains where the operator put it; automation runs only on explicit action. (b) *Pick-and-proceed* — current behaviour: `new` is a starting gun, automation advances it immediately. (c) *Hybrid* — auto-advance only when some condition holds.
Today it is (b) for `new` and (a) for everything else, which is why it reads as broken. **Nothing in any ADR settles this.**

**D2. Should a payment method override the chosen status?** (`PAYMENT_CLEAR_STATUS_PREFERENCE`.) It silently outranks the operator. Intentional, or a legacy convenience that should now defer to D1?

**D3. Should "Confirm" still exist as an operator action?** The status was merged away; the workflow was not. Keep it as a workflow that lands on `in_progress`, or retire it?

**D4. What is the canonical V3 entry-status set for a brand policy?** Required before C3/C4 can be fixed, because both paths need one agreed list.

### ENGINEERING FIXES — no decision needed, but **not** to be started until D1–D4 land

**E1.** Migrate `config_brand_policies` entry statuses to V3, and align `BrandPolicy::defaultPreparationSettings` — the third instance of the same staleness (§15).
**E2.** Make read and write paths agree (C3): one shared resolver instead of one migrating and one discarding.
**E3.** Replace the invalid `['pending']` fallback (C4).
**E4.** Resolve `VerifyPaymentAction`'s guard violation and the comparison defect masking it (C6).
**E5.** Write the missing order-FSM ADR (C7) — without it this class of drift will recur.

## 20. Recovered Canonical Contract

| # | Rule | Source | Status |
|---|---|---|---|
| 1 | 11 statuses; `confirmed`/`processing`/`preparing` merged into `in_progress` | migration `2026_07_22_100000` | **ALIGNED** |
| 2 | Order is created with the resolved entry status | `CreateManualOrderAction:127` | **ALIGNED** |
| 3 | A `new` order is auto-advanced to `in_progress` in the same request | `:188` + `ProcessOrderWorkflow:169` | **CONFLICT (D1)** |
| 4 | A payment method can override the chosen status | `:243,302-309` | **CONFLICT (D2)** |
| 5 | Entry-status options come from brand policy | `BrandPolicy:154` | **BROKEN — pre-V3 vocabulary** |
| 6 | Policy read drops invalid statuses; write migrates them | controller vs action | **CONFLICT (C3)** |
| 7 | Status writes must go through `FulfillmentEngine` | `Order.php:146-153` | **ALIGNED** (one latent violation, C6) |
| 8 | `available = on_hand − reserved` | ADR-027 §3 | **ALIGNED** |
| 9 | Negative stock: material-level → recipe executability; product-level → Case 3 | ADR-027 §16.3, §3 | **ALIGNED** |
| 10 | `awaiting_stock` = FG shortage only | ADR-027 §3 Case 4 | **ALIGNED** |
| 11 | Warehouse missing → `pending`, lifecycle untouched | ADR-027 §2, §10 | **ALIGNED** |
| 12 | Preparation gate = status + warehouse | `PreparationReleaseEngine` | **ALIGNED** |
| 13 | `WarehouseAssigned` is the canonical assignment event | ADR-027 §2, §15 H3 | **ALIGNED** |
| 14 | Order-status FSM has a governing ADR | ADR-005 §5 | **UNDEFINED — never written** |
| 15 | `DirectIssueStockAction` honours `allow_negative_stock` | ADR-027 §15 C1 | **UNVERIFIED — known open gap** |

## 21. Final Verdict

# RECOVERY BLOCKED — BUSINESS DECISION REQUIRED

The contract is recovered and the symptom is fully explained. Implementation cannot begin because **D1 and D2 are genuine business questions with no source-level answer** — and any fix chosen without them would be me deciding how your operators' order-entry workflow behaves.

**The single most useful thing to note:** the *cause* here is not the Order domain at all. It is that the V3 rename migrated data but never migrated **configuration** — now seen three times (wave config, request whitelist, brand policy). Fixing this instance without E1/E5 guarantees a fourth.

**Nothing was modified by this task** — no production code, no schema, no migration, no test, no ADR, no frontend, no order data. No symptom was quick-fixed.
