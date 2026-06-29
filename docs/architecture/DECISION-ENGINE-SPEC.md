# ECOS ERP — Decision Engine Specification

**Document:** DECISION-ENGINE-SPEC  
**Version:** 1.0  
**Task:** TASK-MFG-SPEC-001  
**Status:** Draft — Awaiting Approval  
**Date:** 2026-06-29  
**Scope:** Decision Engine architecture, event catalog, decision rules, logging, and failure handling

---

## Overview

The Decision Engine is the central nervous system of ECOS ERP's automated operations. Every business event that may trigger manufacturing, disassembly, or procurement flows through it. No module calls Manufacturing or Procurement directly.

**Core Principle:** A decision is logged before its action is executed. If the action fails, the decision log still exists with an accurate outcome. This guarantees full auditability even in failure scenarios.

---

## 1. Architecture

### 1.1 Position in the System

```
┌────────────────────────────────────────────────────────────┐
│                     ECOS ERP                               │
│                                                            │
│  ┌──────────────┐    Events    ┌─────────────────────┐    │
│  │  Orders BC   │─────────────▶│   Decision Engine   │    │
│  └──────────────┘              │                     │    │
│                                │  - Receives events  │    │
│  ┌──────────────┐   Events     │  - Evaluates rules  │    │
│  │  Returns BC  │─────────────▶│  - Logs decisions   │    │
│  └──────────────┘              │  - Dispatches to:   │    │
│                                │    ManufacturingSvc │    │
│  ┌──────────────┐   Events     │    DisassemblySvc   │    │
│  │  Recipe BC   │─────────────▶│    ProcurementQueue │    │
│  └──────────────┘              │    CostEngine       │    │
│                                └─────────────────────┘    │
└────────────────────────────────────────────────────────────┘
```

### 1.2 Design Principles

1. **Single Entry Point** — No business module calls Manufacturing or Procurement directly. All routing flows through the Decision Engine.
2. **Log Before Execute** — A DecisionLog entry is written before any action is taken. Status is updated after execution.
3. **Stateless Rules** — Decision rules are pure functions: given an event and current state, they return a decision. No side effects in the rule evaluation phase.
4. **No Blocking** — Decision failures do not block the originating module (e.g., a manufacturing failure does not stop an order from continuing its lifecycle).
5. **Idempotent Logging** — Each event+source combination is logged once. Retries create new log entries with a reference to the original.

---

## 2. Event Catalog

Every event the Decision Engine handles is defined here. Events are immutable value objects.

### 2.1 ORDER_PREPARING

**Emitted by:** Orders BC  
**When:** Order status transitions to `preparing`  
**Payload:**

```
OrderPreparingEvent {
  order_id:    UUID
  items: [
    {
      product_id:   UUID
      quantity:     decimal
      unit_id:      UUID
      order_line_id: UUID
    }
  ]
  occurred_at:  timestamp
  actor_id:     UUID | 'system'
}
```

**Decision scope:** One decision per line item. Each product in the order is evaluated independently.

---

### 2.2 ORDER_RETURNED

**Emitted by:** Returns BC (when goods are physically received back)  
**When:** A return receipt is confirmed  
**Payload:**

```
OrderReturnedEvent {
  return_id:   UUID
  order_id:    UUID
  items: [
    {
      product_id:   UUID
      quantity:     decimal
      unit_id:      UUID
    }
  ]
  occurred_at:  timestamp
  actor_id:     UUID | 'system'
}
```

**Decision scope:** One decision per returned line item.

---

### 2.3 GOODS_RECEIPT_POSTED

**Emitted by:** Purchasing BC  
**When:** A GoodsReceipt is posted (status → posted)  
**Payload:**

```
GoodsReceiptPostedEvent {
  goods_receipt_id: UUID
  lines: [
    {
      product_id:      UUID
      quantity:        decimal
      landed_unit_cost: Money
    }
  ]
  occurred_at: timestamp
  actor_id:    UUID
}
```

**Decision scope:** Triggers Cost Engine update and Procurement Queue recalculation per product.

---

### 2.4 RECIPE_UPDATED

**Emitted by:** Recipe BC  
**When:** A recipe is saved (version incremented)  
**Payload:**

```
RecipeUpdatedEvent {
  recipe_id:    UUID
  product_id:   UUID
  old_version:  integer
  new_version:  integer
  occurred_at:  timestamp
  actor_id:     UUID
}
```

**Decision scope:** Triggers cost recalculation for the product and its parents.

---

### 2.5 PROCUREMENT_SCHEDULER_TRIGGERED

**Emitted by:** Scheduler (cron) or manual trigger  
**When:** A scheduled procurement run time is reached  
**Payload:**

```
ProcurementSchedulerTriggeredEvent {
  schedule_id:  UUID
  company_id:   UUID
  run_time:     timestamp
  trigger_type: 'scheduled' | 'manual'
  actor_id:     UUID | 'system'
}
```

**Decision scope:** Triggers a full procurement queue recalculation and purchase request generation.

---

### 2.6 MANUFACTURING_FAILED (Internal)

**Emitted by:** Decision Engine itself  
**When:** Manufacturing was decided but execution failed  
**Payload:**

```
ManufacturingFailedEvent {
  order_id:    UUID
  product_id:  UUID
  quantity:    decimal
  reason:      string
  decision_log_id: UUID
  occurred_at: timestamp
}
```

**Decision scope:** Triggers procurement queue update for the shortfall.

---

## 3. Decision Rules

A Decision Rule is a pure function:

```
evaluate(event, product_state) → Decision
```

Rules are evaluated in **priority order**. The first matching rule wins.

### 3.1 Rules for ORDER_PREPARING

Evaluated once per line item.

| Priority | Rule ID | Condition | Decision | Action |
|----------|---------|-----------|----------|--------|
| 1 | `MFG-001` | `product.can_manufacture = false` | `SKIP_NOT_MANUFACTURABLE` | Log only. No action. |
| 2 | `MFG-002` | `product.can_manufacture = true` AND `recipe = null` | `FAIL_NO_RECIPE` | Log, add product to attention queue |
| 3 | `MFG-003` | `recipe.is_active = false` | `FAIL_RECIPE_INACTIVE` | Log, add product to attention queue |
| 4 | `MFG-004` | Recipe valid AND all materials sufficient | `MANUFACTURE` | Execute ManufacturingService |
| 5 | `MFG-005` | Recipe valid AND shortfall exists AND `product.allow_negative_stock = true` | `MANUFACTURE_WITH_SHORTAGE` | Execute with negative raw material stock, add shortfall to procurement queue |
| 6 | `MFG-006` | Recipe valid AND shortfall exists AND `product.allow_negative_stock = false` | `FAIL_STOCK_SHORTAGE` | Log failure, add shortfall to procurement queue, do NOT manufacture |

**Shortfall Detection (used in MFG-005 and MFG-006):**

```
For each RecipeItem in recipe:
  required_qty     = item.quantity × order_line.quantity
  available_qty    = InventoryEngine.availableStock(item.product_id, warehouse_id)
  shortfall        = max(0, required_qty - available_qty)
  
  if shortfall > 0:
    ShortfallDetected(product_id = item.product_id, quantity = shortfall)
```

---

### 3.2 Rules for ORDER_RETURNED

Evaluated once per returned line item.

| Priority | Rule ID | Condition | Decision | Action |
|----------|---------|-----------|----------|--------|
| 1 | `DIS-001` | `product.can_disassemble = false` | `SKIP_DISASSEMBLY_DISABLED` | Receive product to finished goods inventory as-is |
| 2 | `DIS-002` | `product.can_disassemble = true` AND `recipe = null` | `SKIP_NO_RECIPE` | Receive product to finished goods inventory as-is |
| 3 | `DIS-003` | `product.can_disassemble = true` AND `recipe.is_active = false` | `SKIP_RECIPE_INACTIVE` | Receive product to finished goods inventory as-is |
| 4 | `DIS-004` | All conditions met | `DISASSEMBLE` | Execute DisassemblyService |

---

### 3.3 Rules for GOODS_RECEIPT_POSTED

Evaluated once per receipt line.

| Priority | Rule ID | Condition | Decision | Action |
|----------|---------|-----------|----------|--------|
| 1 | `COST-001` | `product.cost_source = manual` | `NO_COST_UPDATE` | Log. Do not change cost. |
| 2 | `COST-002` | `product.cost_source = purchase_invoice` | `UPDATE_COST_FROM_INVOICE` | CostEngine.updateFromGoodsReceipt() |
| 3 | `COST-003` | `product.cost_source = recipe` | `NO_COST_UPDATE` | Log. Cost comes from recipe only. |
| 4 | `COST-004` | `product.cost_source = hybrid` | `UPDATE_PURCHASE_COST` | CostEngine.updateHybridPurchaseCost() |
| *(always)* | `PROC-001` | Goods received → net requirement decreases | `RECALCULATE_QUEUE` | ProcurementQueueService.recalculate(product_id) |

---

### 3.4 Rules for RECIPE_UPDATED

| Priority | Rule ID | Condition | Decision | Action |
|----------|---------|-----------|----------|--------|
| 1 | `COST-005` | `product.cost_source = recipe` | `RECALCULATE_RECIPE_COST` | CostEngine.recalculateFromRecipe(product_id) |
| 2 | `COST-006` | `product.cost_source = hybrid` | `RECALCULATE_HYBRID_RECIPE_COST` | CostEngine.recalculateHybridRecipeCost() |
| 3 | `COST-007` | Parent products reference this product as component | `CASCADE_COST_RECALCULATION` | CostEngine.cascadeRecipeCost(product_id) |
| *(always)* | `LOG-001` | Recipe version changed | `LOG_VERSION_CHANGE` | Log DecisionLog with old/new version |

---

### 3.5 Rules for PROCUREMENT_SCHEDULER_TRIGGERED

| Priority | Rule ID | Condition | Decision | Action |
|----------|---------|-----------|----------|--------|
| 1 | `PROC-010` | Scheduler already running for this company | `SKIP_CONCURRENT_RUN` | Log, abort new run |
| 2 | `PROC-011` | Net requirement = 0 for all products | `SCHEDULER_RUN_NO_ACTION` | Log run, create SchedulerRun record, no PRs |
| 3 | `PROC-012` | Net requirement > 0 for at least one product | `GENERATE_PURCHASE_REQUESTS` | Execute ProcurementSchedulerService |

---

## 4. DecisionLog

### 4.1 Schema

Every decision is recorded immutably. No updates. No deletes.

| Field | Type | Description |
|-------|------|-------------|
| `id` | UUID | Unique decision identifier |
| `event_type` | Enum | See Event Catalog — `ORDER_PREPARING`, `ORDER_RETURNED`, etc. |
| `rule_id` | String | Rule that fired — e.g. `MFG-004`, `DIS-001` |
| `trigger_source_type` | String | `order` / `return` / `goods_receipt` / `recipe` / `scheduler` / `system` |
| `trigger_source_id` | UUID | The ID of the triggering document/entity |
| `subject_type` | String | What the decision is about: `product`, `order_line`, `scheduler_run` |
| `subject_id` | UUID | The product_id, order_line_id, etc. |
| `decision` | String | The decision code — e.g. `MANUFACTURE`, `FAIL_NO_RECIPE` |
| `reason` | Text | Human-readable explanation |
| `outcome` | Enum | `executed` / `skipped` / `failed` / `queued` / `pending` |
| `execution_id` | UUID\|null | ID of the ManufacturingTransaction, DisassemblyTransaction, etc. if executed |
| `metadata` | JSON | Context-specific data (shortfall qty, component costs, etc.) |
| `actor_id` | UUID\|null | `null` = system-initiated. User UUID if user-triggered. |
| `decided_at` | Timestamp | When the decision was logged (BEFORE action execution) |
| `executed_at` | Timestamp\|null | When the action completed (after execution) |
| `error_message` | Text\|null | Set if outcome = `failed` |
| `retry_of` | UUID\|null | If this is a retry, references the original DecisionLog ID |

### 4.2 Append-Only Guarantee

The DecisionLog table must have NO update or delete permissions for application-level database users. Only INSERT is allowed. This is enforced at the database level.

### 4.3 Example Entries

**Successful Manufacturing:**
```json
{
  "event_type": "ORDER_PREPARING",
  "rule_id": "MFG-004",
  "trigger_source_type": "order",
  "trigger_source_id": "order-uuid-1042",
  "subject_type": "order_line",
  "subject_id": "line-uuid-abc",
  "decision": "MANUFACTURE",
  "reason": "Recipe found, all raw materials available",
  "outcome": "executed",
  "execution_id": "mfg-txn-uuid-xyz",
  "metadata": {
    "product_id": "product-uuid-honey",
    "quantity": 5,
    "recipe_version": 3,
    "manufacturing_cost": 24.50
  }
}
```

**Manufacturing Blocked — No Recipe:**
```json
{
  "event_type": "ORDER_PREPARING",
  "rule_id": "MFG-002",
  "trigger_source_type": "order",
  "trigger_source_id": "order-uuid-1043",
  "subject_type": "product",
  "subject_id": "product-uuid-widget",
  "decision": "FAIL_NO_RECIPE",
  "reason": "Product is configured for manufacturing (can_manufacture=true) but no active recipe found",
  "outcome": "failed",
  "execution_id": null,
  "metadata": {
    "product_id": "product-uuid-widget",
    "quantity": 2
  }
}
```

**Disassembly Skipped — Disabled:**
```json
{
  "event_type": "ORDER_RETURNED",
  "rule_id": "DIS-001",
  "trigger_source_type": "return",
  "trigger_source_id": "return-uuid-abc",
  "subject_type": "product",
  "subject_id": "product-uuid-raw-item",
  "decision": "SKIP_DISASSEMBLY_DISABLED",
  "reason": "Product has can_disassemble=false. Returned to finished goods inventory.",
  "outcome": "skipped",
  "metadata": { "quantity": 1 }
}
```

---

## 5. Decision Priority

When multiple rules could apply, the **lowest priority number** wins. Rules are evaluated top-to-bottom in the priority order defined in Section 3.

**Priority tiebreaker:** If two rules share the same priority (should not occur by design), the more restrictive rule takes precedence (the one that blocks action over the one that allows it).

---

## 6. Decision Retry

### 6.1 When Retry Is Allowed

| Decision | Retryable? | Reason |
|----------|-----------|--------|
| `MANUFACTURE` (failed mid-execution) | Yes — manual trigger | Execution failure may be transient |
| `FAIL_NO_RECIPE` | No — until recipe is created | Nothing to retry |
| `FAIL_STOCK_SHORTAGE` | Yes — after procurement | After goods received, can re-trigger |
| `DISASSEMBLE` (failed mid-execution) | Yes — manual trigger | |
| `FAIL_RECIPE_INACTIVE` | No — until recipe is reactivated | |

### 6.2 Retry Mechanics

A retry creates a **new DecisionLog entry** with `retry_of = original_decision_log_id`. The original log is never modified.

```
retryDecision(original_decision_log_id):
  original = DecisionLog.find(original_decision_log_id)
  
  if original.outcome not in ['failed', 'pending']:
    return ERROR: "Only failed or pending decisions can be retried"
  
  // Re-evaluate the rule with current state
  new_decision = evaluateRule(original.rule_id, current_state)
  
  new_log = DecisionLog.create({
    ...new_decision,
    retry_of: original.id
  })
  
  if new_decision.action != null:
    execute(new_decision.action, new_log)
```

### 6.3 No Automatic Retry

The Decision Engine does **not** automatically retry failed decisions. All retries are:
- Manual (user-initiated from the Operations dashboard) — Phase 2 UI
- Triggered by a system event that changes the conditions (e.g., goods received → retry manufacturing)

---

## 7. Decision Failure Handling

### 7.1 Failure Types

| Type | Description | Response |
|------|-------------|----------|
| Rule evaluation failure | Exception thrown during rule evaluation | Log `SYSTEM_ERROR`, notify admin. Do NOT take action. |
| Action execution failure | Exception during manufacturing/disassembly | Log outcome = `failed`, set `error_message`. |
| Partial execution failure | e.g., consumed some materials but failed to produce output | Roll back all inventory movements. Log as failed. |
| Unknown event type | Event type not in catalog | Log `UNKNOWN_EVENT_TYPE`, discard event. |

### 7.2 Non-Blocking Failure

A decision failure **never blocks the originating module**. Specifically:
- Manufacturing failure does NOT prevent an order from progressing to the next status.
- Disassembly failure does NOT prevent a return from being recorded.
- A failed decision is visible in the DecisionLog and the Operations dashboard.

### 7.3 Failure Cascade Prevention

If a manufacturing execution fails midway (e.g., materials consumed but product not produced):
1. Roll back all inventory movements via database transaction.
2. Log DecisionLog with outcome = `failed`.
3. Do NOT add to procurement queue automatically (the shortfall was not confirmed).
4. Alert operations team.

---

## 8. Decision Sequence — ORDER_PREPARING

```
WooCommerce                Orders BC              Decision Engine            Recipe Engine
    │                          │                        │                        │
    │  Order #1042 → Preparing │                        │                        │
    │─────────────────────────▶│                        │                        │
    │                          │                        │                        │
    │                          │  ORDER_PREPARING event │                        │
    │                          │  {order_id, items[]}   │                        │
    │                          │───────────────────────▶│                        │
    │                          │                        │                        │
    │                          │          For each line item:                    │
    │                          │                        │  resolveRecipe()       │
    │                          │                        │───────────────────────▶│
    │                          │                        │                        │
    │                          │                        │◀─── Recipe or null ────│
    │                          │                        │                        │
    │                          │                        │  evaluateRule()        │
    │                          │                        │  → Decision: MANUFACTURE│
    │                          │                        │                        │
    │                          │                        │  log(MANUFACTURE, pending)
    │                          │                        │                        │
    │                          │                        │  ManufacturingService.execute()
    │                          │                        │         │              │
    │                          │                        │         │ consumeRaw() │
    │                          │                        │         │──────────────┤
    │                          │                        │         │ addFinished()│
    │                          │                        │         │──────────────┤
    │                          │                        │         │ updateCost() │
    │                          │                        │         │──────────────┤
    │                          │                        │         │              │
    │                          │                        │  update log(executed)  │
    │                          │                        │                        │
```

---

## 9. Complete Decision Matrix

| # | Event | Rule | Condition | Decision | Action | Outcome |
|---|-------|------|-----------|----------|--------|---------|
| 1 | ORDER_PREPARING | MFG-001 | `can_manufacture = false` | SKIP_NOT_MANUFACTURABLE | Log only | No manufacturing |
| 2 | ORDER_PREPARING | MFG-002 | `can_manufacture = true`, recipe = null | FAIL_NO_RECIPE | Log, attention queue | Manufacturing blocked |
| 3 | ORDER_PREPARING | MFG-003 | Recipe exists, `is_active = false` | FAIL_RECIPE_INACTIVE | Log, attention queue | Manufacturing blocked |
| 4 | ORDER_PREPARING | MFG-004 | Recipe valid, all materials sufficient | MANUFACTURE | Execute manufacturing | Success |
| 5 | ORDER_PREPARING | MFG-005 | Recipe valid, shortfall, `allow_negative = true` | MANUFACTURE_WITH_SHORTAGE | Execute + add to procurement queue | Success (negative stock) |
| 6 | ORDER_PREPARING | MFG-006 | Recipe valid, shortfall, `allow_negative = false` | FAIL_STOCK_SHORTAGE | Log, add to procurement queue | Manufacturing blocked |
| 7 | ORDER_RETURNED | DIS-001 | `can_disassemble = false` | SKIP_DISASSEMBLY_DISABLED | Receive to finished goods | No disassembly |
| 8 | ORDER_RETURNED | DIS-002 | `can_disassemble = true`, recipe = null | SKIP_NO_RECIPE | Receive to finished goods | No disassembly |
| 9 | ORDER_RETURNED | DIS-003 | `can_disassemble = true`, recipe inactive | SKIP_RECIPE_INACTIVE | Receive to finished goods | No disassembly |
| 10 | ORDER_RETURNED | DIS-004 | All conditions met | DISASSEMBLE | Execute disassembly | Raw materials recovered |
| 11 | GOODS_RECEIPT_POSTED | COST-001 | `cost_source = manual` | NO_COST_UPDATE | Log | No cost change |
| 12 | GOODS_RECEIPT_POSTED | COST-002 | `cost_source = purchase_invoice` | UPDATE_COST_FROM_INVOICE | Update current_cost | Cost history entry created |
| 13 | GOODS_RECEIPT_POSTED | COST-003 | `cost_source = recipe` | NO_COST_UPDATE | Log | No cost change |
| 14 | GOODS_RECEIPT_POSTED | COST-004 | `cost_source = hybrid` | UPDATE_PURCHASE_COST | Update hybrid purchase cost | Cost history entry created |
| 15 | GOODS_RECEIPT_POSTED | PROC-001 | Always | RECALCULATE_QUEUE | ProcurementQueue.recalculate() | Queue updated |
| 16 | RECIPE_UPDATED | COST-005 | `cost_source = recipe` | RECALCULATE_RECIPE_COST | Recalculate from recipe | Cost updated |
| 17 | RECIPE_UPDATED | COST-006 | `cost_source = hybrid` | RECALCULATE_HYBRID_COST | Recalculate hybrid recipe cost | Cost updated |
| 18 | RECIPE_UPDATED | COST-007 | Parent products use this as component | CASCADE_COST | Recalculate parent costs | Parent costs updated |
| 19 | RECIPE_UPDATED | LOG-001 | Always | LOG_VERSION_CHANGE | Log version increment | Audit trail |
| 20 | PROCUREMENT_SCHEDULER_TRIGGERED | PROC-010 | Scheduler already running | SKIP_CONCURRENT_RUN | Log, abort | No duplicate run |
| 21 | PROCUREMENT_SCHEDULER_TRIGGERED | PROC-011 | Net requirement = 0 | SCHEDULER_RUN_NO_ACTION | Log run record | No purchase requests |
| 22 | PROCUREMENT_SCHEDULER_TRIGGERED | PROC-012 | Net requirement > 0 | GENERATE_PURCHASE_REQUESTS | Run scheduler | Purchase requests created |
| 23 | MANUFACTURING_FAILED | AUTO | Shortfall confirmed | UPDATE_PROCUREMENT_QUEUE | Add shortfall to queue | Queue updated |

---

## 10. Decision Engine Constraints

| Constraint | Value |
|-----------|-------|
| Maximum concurrent decisions per order | 1 per line item (evaluated sequentially) |
| Decision log retention | Forever (append-only, never deleted) |
| Maximum retry attempts | 3 (then requires manual intervention) |
| Event processing guarantee | At-least-once (idempotency key on event_id) |
| Decision evaluation timeout | 5 seconds |
| Action execution timeout | 30 seconds (manufacturing) |
