# ECOS ERP — AI Data Architecture: Manufacturing & Procurement

**Document:** AI-DATA-ARCHITECTURE  
**Version:** 1.0  
**Task:** TASK-MFG-DB-001  
**Status:** Draft — Awaiting Approval  
**Date:** 2026-06-29  
**Scope:** Phase G — AI Entry Points, Analytical Datasets, Training Data Design

**IMPORTANT:** This document describes the AI readiness of the data model. No migrations, models, or code are created here.

---

## Purpose

This document identifies every place in the manufacturing and procurement database design where AI can improve system behavior — either as real-time prediction or as offline model training input. It catalogs the analytical datasets the new tables produce and how those datasets are structured for AI consumption.

The system described here is **demand-driven**: every manufacturing and procurement action starts from a customer order. This makes the data model unusually clean for AI — every event has a clear cause (an order), a clear actor (the decision engine), and a clear outcome (inventory change or cost change). The decision chain is fully logged.

---

## AI Entry Points

### Entry Point 1 — Decision Engine Suggestions

**Where:** `decision_logs` table  
**Signal:** The engine already classifies every incoming event (GOODS_RECEIPT, ORDER_PLACED, etc.) against a 22-row decision matrix and logs its result.

**Current (rule-based):** Decisions are deterministic — the same inputs always produce the same output. No uncertainty, no ranking.

**AI opportunity:** Replace or augment threshold comparisons with probability scores:
- "Should we manufacture now or wait for a cheaper procurement cycle?" — score both options from historical cost data
- "Will this stock be consumed fast enough to justify manufacturing today?" — score from consumption velocity data
- "Which supplier should the purchase request be routed to?" — score from `supplier_performance_analytics` (see below)

**Training signal:** The `decision_logs` table provides labeled outcome data. Each log row records: input event → decision taken → resolution outcome (did the manufacturing succeed? did inventory satisfy the order?). This is a natural multi-class classification dataset.

**Key columns for training:**
```
decision_logs:
  triggering_event        -- input class
  event_payload           -- JSONB features (order_qty, stock_on_hand, etc.)
  decision_type           -- output class
  resolution_status       -- was this the right decision? (from downstream outcome)
  resolution_notes        -- human override description
```

---

### Entry Point 2 — Procurement Scheduler

**Where:** `scheduler_runs` table + `procurement_queue_entries`  
**Signal:** Every scheduler run generates a snapshot of net requirements (procurement_queue) and emits purchase_requests.

**Current (rule-based):** Net Requirement = Demand − (Stock + In-Transit + In-Production). Safety stock threshold is a manually configured constant per product.

**AI opportunity:**
- **Dynamic safety stock** — replace the static threshold with a predicted safety stock level based on lead time variability, demand volatility, and seasonal patterns
- **Lead time prediction** — predict expected delivery time for a supplier+product pair from historical GR timing vs PO date
- **Demand spike detection** — flag orders that look anomalous before they flow into the queue, to avoid over-manufacturing

**Training signal:** `scheduler_runs` + `procurement_queue_entries` + `purchase_requests` + actual GR dates. The gap between `estimated_delivery_date` (set on PR creation) and actual GR `received_at` is a direct lead time error signal.

---

### Entry Point 3 — Recipe Cost Estimation

**Where:** `bills_of_materials` + `bill_of_material_lines` + `product_cost_histories`  
**Signal:** Every time a product's cost is recalculated by the Cost Engine, a `product_cost_histories` row is written with the new cost, the cost source, and the previous cost.

**Current (rule-based):** Cost is calculated exactly from BOM lines × current input costs. No forecasting.

**AI opportunity:**
- **Cost trend forecasting** — predict where a product's manufacturing cost will be in 30/60/90 days based on raw material cost trends
- **Recipe optimization suggestions** — detect when a substitute input material would produce the same output at lower cost (requires product similarity model)
- **Variance alerts** — flag when actual manufacturing cost deviates significantly from the BOM-predicted cost (signals wastage or measurement error)

**Training signal:** `product_cost_histories` joined with `manufacturing_transactions` (actual cost) and `goods_receipts` (input material cost at GR time).

---

### Entry Point 4 — Supplier Selection

**Where:** `purchase_requests` + `purchase_orders` + `goods_receipts`  
**Signal:** The full supplier lifecycle is captured — PR created → PO issued to supplier → GR received. Every GR records received quantity vs ordered quantity, and received_at vs promised delivery date.

**Current:** Supplier is selected manually when converting a PR to a PO.

**AI opportunity:**
- **Supplier ranking** — score suppliers on: on-time delivery rate, quantity accuracy, price stability, lead time consistency
- **Supplier recommendation** — when converting a PR to a PO, suggest the best supplier for that product based on recent performance

**Training signal:** See Supplier Performance Analytics section below.

---

### Entry Point 5 — Inventory Consumption Patterns

**Where:** `inventory_layer_consumptions` (extended) + `order_lines` + `products`  
**Signal:** Every FIFO consumption records: which product, which warehouse, consumed quantity, and (post-migration) whether it was consumed for a sale or for manufacturing.

**Current:** No forecasting. Inventory is reactive.

**AI opportunity:**
- **Demand forecasting per product** — predict next 7/14/30 day sales volume per product per warehouse
- **Reorder point optimization** — combine demand forecast with lead time prediction to recommend when to trigger procurement
- **Dead stock detection** — flag products with consumption_velocity < threshold for a configurable period

**Training signal:** `inventory_layer_consumptions` WHERE consumption_type = 'sales' joined with `order_lines` for date and channel context.

---

### Entry Point 6 — Disassembly Recovery Rate

**Where:** `disassembly_recoveries` + `disassembly_transactions` + `bills_of_materials`  
**Signal:** Every disassembly records expected recovery (from BOM) vs actual recovery (from `disassembly_recoveries.recovered_quantity`).

**Current:** Recovery rate is assumed to equal the BOM quantity. No actuals tracked.

**AI opportunity:**
- **Recovery rate prediction** — predict actual recovery yield for a product based on historical disassembly outcomes
- **Variance alerting** — flag disassemblies where actual recovery diverges significantly from BOM expectation

**Training signal:** `disassembly_recoveries` (recovered_quantity) vs expected from `bill_of_material_lines` (quantity × unit conversion).

---

## Analytical Datasets

Each section below describes an analytical view — the source tables, join logic, grain, and intended AI/analytics use.

---

### DS-001 · Decision History Analytics

**Purpose:** Train the Decision Engine classifier and audit decision quality over time.

**Grain:** One row per decision log entry

**Source tables:**
```
decision_logs               -- core fact
products                    -- product features (can_manufacture, can_disassemble, cost_source)
orders                      -- order context (value, customer, channel)
procurement_queue_entries   -- net requirement at decision time
```

**Key analytical fields:**
```
decision_logs.id
decision_logs.company_id
decision_logs.triggering_event
decision_logs.decision_type
decision_logs.event_payload              -- JSONB: stock_on_hand, order_qty, cost, etc.
decision_logs.resolution_status          -- 'auto' | 'human_override' | 'failed' | 'pending'
decision_logs.created_at
-- derived:
event_payload->>'stock_on_hand'          -- stock at decision time
event_payload->>'order_quantity'         -- demand quantity
event_payload->>'current_cost'           -- cost at decision time
p.can_manufacture                        -- product capability
p.cost_source                            -- cost policy
```

**AI use:**
- Multi-class classification: (event + features) → decision_type
- Anomaly detection: which decisions were overridden by humans? what were their feature values?
- Decision latency analysis: time-to-resolution by event type

**Refresh cadence:** Append-only. No refresh needed — query directly.

---

### DS-002 · Demand History

**Purpose:** Train demand forecasting models and reorder point optimization.

**Grain:** One row per order line (consumed from inventory)

**Source tables:**
```
order_lines                     -- the demand event
orders                          -- date, channel, branch
inventory_layer_consumptions    -- actual FIFO consumption tied to this order line
products                        -- product features
```

**Key analytical fields:**
```
ol.product_id
ol.quantity                      -- ordered quantity
ol.unit_price                    -- sale price at time of order
o.created_at                     -- demand date
o.branch_id                      -- location of demand
o.channel                        -- WooCommerce / retail / wholesale
p.product_type                   -- raw / finished / service / merchandise
-- derived:
DATE_TRUNC('week', o.created_at) -- weekly demand bucket
DATE_TRUNC('month', o.created_at)-- monthly demand bucket
```

**AI use:**
- Time-series forecasting: demand by product + week/month
- Seasonality detection: holiday spikes, weekly patterns
- Demand elasticity: price sensitivity per product category

**Refresh cadence:** Continuous — new rows append on every order. Historical window: rolling 2 years minimum.

---

### DS-003 · Supplier Performance

**Purpose:** Score suppliers for selection recommendations and lead time prediction.

**Grain:** One row per goods receipt line (one supplier delivery for one product)

**Source tables:**
```
goods_receipt_lines     -- received quantity + cost
goods_receipts          -- received_at, supplier_id, purchase_order_id
purchase_orders         -- expected_delivery_date, po_date
purchase_order_lines    -- ordered quantity + agreed unit cost
products                -- product context
```

**Key analytical fields:**
```
gr.supplier_id
grl.product_id
grl.quantity_received
pol.quantity_ordered
grl.unit_cost                               -- actual GR unit cost
pol.unit_price                              -- PO agreed price
po.expected_delivery_date                   -- promised date
gr.received_at                              -- actual receipt date
-- derived:
grl.quantity_received / pol.quantity_ordered AS quantity_fill_rate
EXTRACT(DAYS FROM gr.received_at - po.expected_delivery_date) AS delivery_delay_days
grl.unit_cost / pol.unit_price              AS price_deviation_ratio
```

**AI use:**
- Supplier scoring: on-time rate, quantity accuracy, price stability
- Lead time prediction: predict expected delivery date for (supplier, product) pair
- Supplier ranking per product: who to route a PR to

**Refresh cadence:** Append-only (GRs don't change after posting). Recalculate supplier scores weekly.

---

### DS-004 · Recipe Performance

**Purpose:** Detect BOM accuracy issues and optimize recipe quantities.

**Grain:** One row per manufacturing transaction

**Source tables:**
```
manufacturing_transactions      -- output quantity, status, cost
manufacturing_consumptions      -- actual input consumed per ingredient
bills_of_materials              -- the recipe used
bill_of_material_lines          -- expected input quantity per ingredient
decision_logs                   -- the decision that triggered this transaction
```

**Key analytical fields:**
```
mt.id
mt.product_id                            -- finished product
mt.quantity_produced
mt.actual_unit_cost                      -- actual cost per unit
mt.bom_id                                -- which BOM was used
mt.bom_version_number                    -- which version
mt.status                                -- completed | failed
mt.created_at
-- per ingredient (join to manufacturing_consumptions):
mc.input_product_id
mc.quantity_consumed                     -- actual
bml.quantity                             -- expected (from BOM line)
mc.quantity_consumed / bml.quantity AS yield_ratio   -- 1.0 = perfect
-- derived:
mt.actual_unit_cost / bom_expected_cost AS cost_variance
```

**AI use:**
- BOM accuracy scoring: which recipes produce the expected yield?
- Manufacturing variance detection: flag transactions where yield deviates from BOM
- Cost prediction: predict manufacturing cost given current input costs
- Failure pattern analysis: what conditions correlate with manufacturing failures?

**Refresh cadence:** Append-only. Query directly.

---

### DS-005 · Cost Trend Analysis

**Purpose:** Forecast future product costs and detect pricing anomalies.

**Grain:** One row per cost history entry per product

**Source tables:**
```
product_cost_histories      -- core fact
products                    -- product context
```

**Key analytical fields:**
```
pch.product_id
pch.cost_source             -- what drove this cost change?
pch.new_cost
pch.previous_cost
pch.created_at
-- derived:
pch.new_cost - pch.previous_cost AS cost_delta
(pch.new_cost - pch.previous_cost) / NULLIF(pch.previous_cost, 0) AS cost_change_pct
DATE_TRUNC('month', pch.created_at) AS cost_month
```

**AI use:**
- Cost forecasting: predict unit cost in 30/60/90 days
- Anomaly detection: sudden cost jumps (supplier price shock, recipe error)
- Cost correlation: which raw material cost changes drive finished product cost changes?
- Margin erosion alerts: when cost trends upward but selling price is fixed

**Refresh cadence:** Append-only. Build time-series index on (product_id, created_at).

---

### DS-006 · Inventory Consumption Velocity

**Purpose:** Track how fast each product moves through inventory and detect slow/dead stock.

**Grain:** Daily consumption total per product per warehouse

**Source tables:**
```
inventory_layer_consumptions    -- individual FIFO consumption events (extended)
inventory_receipt_layers        -- stock receipt events (to calculate days on hand)
products                        -- product context
```

**Key analytical fields:**
```
ilc.product_id
ilc.warehouse_id                         -- where consumed
ilc.consumption_type                     -- sales | manufacturing | disassembly
DATE(ilc.consumed_at) AS consumption_date
SUM(ilc.quantity_consumed) AS daily_consumed
-- derived (requires join to receipt layers):
AVG(daily_consumed) OVER (
  PARTITION BY ilc.product_id, ilc.warehouse_id
  ORDER BY consumption_date
  ROWS BETWEEN 29 PRECEDING AND CURRENT ROW
) AS rolling_30d_avg_daily_consumption
```

**AI use:**
- Days-on-hand prediction: current_stock / rolling_avg_daily_consumption
- Stockout risk scoring: probability of stockout before next procurement cycle
- Dead stock detection: products with rolling avg consumption near zero for 90+ days
- ABC classification improvement: current ABC is static; replace with velocity-weighted dynamic classification

**Refresh cadence:** Materialized view refreshed daily at end of operational day.

---

### DS-007 · Procurement Queue Evolution

**Purpose:** Track how the net requirement changes over time to understand demand-procurement lag.

**Grain:** One row per scheduler run per queue entry

**Source tables:**
```
scheduler_runs              -- the run that produced this snapshot
procurement_queue_entries   -- current queue state
purchase_requests           -- what was generated
goods_receipts              -- when was the demand actually fulfilled
```

**Key analytical fields:**
```
sr.id AS scheduler_run_id
sr.company_id
sr.triggered_at
pqe.product_id
pqe.net_requirement_quantity
pqe.on_hand_quantity
pqe.in_transit_quantity
pqe.demand_source_payload   -- JSONB: order IDs driving the demand
pr.id AS purchase_request_id
pr.requested_quantity
pr.status
-- derived (after GR):
gr.received_at AS fulfilled_at
EXTRACT(DAYS FROM gr.received_at - sr.triggered_at) AS demand_to_fulfillment_days
```

**AI use:**
- Procurement cycle optimization: how many days between demand appearing in queue and inventory arriving?
- Safety stock calibration: what minimum stock prevents stockouts given observed fulfillment times?
- Queue accuracy: does net_requirement_quantity predict actual order volume for the next N days?

**Refresh cadence:** Built from scheduler run events. Append-only core; derived metrics recalculated after GR posting.

---

### DS-008 · Disassembly Recovery Analysis

**Purpose:** Track actual recovery yield vs BOM expectations per product and per version.

**Grain:** One row per disassembly recovery (one row per recovered component per transaction)

**Source tables:**
```
disassembly_recoveries          -- actual recovered
disassembly_transactions        -- parent transaction
bills_of_materials              -- recipe used
bill_of_material_lines          -- expected recovery quantities
products                        -- input (finished) and output (component) context
```

**Key analytical fields:**
```
dt.product_id                           -- disassembled product
dt.quantity_disassembled
dt.bom_id
dt.bom_version_number
dr.output_product_id                    -- recovered component
dr.recovered_quantity                   -- actual
bml.quantity                            -- expected per BOM
bml.quantity * dt.quantity_disassembled AS expected_total
dr.recovered_quantity / (bml.quantity * dt.quantity_disassembled) AS recovery_rate
dt.created_at
```

**AI use:**
- Recovery rate modeling: predict expected recovery for a disassembly job before it runs
- Anomaly detection: flag transactions where recovery rate < threshold
- BOM accuracy: if recovery rates consistently differ from BOM quantities, the BOM is wrong
- Cost estimation improvement: adjust disassembly cost estimates based on observed recovery rates

**Refresh cadence:** Append-only. Query directly.

---

## Event Stream Schema

The database design is well-suited to event-driven AI pipelines. The following tables are natural event sources:

| Table | Event | Payload |
|-------|-------|---------|
| `decision_logs` | DecisionMade | triggering_event, decision_type, event_payload |
| `manufacturing_transactions` (status change) | ManufacturingCompleted / ManufacturingFailed | product_id, qty, actual_unit_cost |
| `disassembly_transactions` (status change) | DisassemblyCompleted | product_id, qty |
| `product_cost_histories` | CostUpdated | product_id, new_cost, previous_cost, cost_source |
| `scheduler_runs` (completed) | SchedulerRunCompleted | company_id, products_queued, requests_generated |
| `purchase_requests` (status change) | PurchaseRequestConverted | product_id, supplier_id, quantity |
| `procurement_queue_entries` (net_requirement change) | QueueEntryUpdated | product_id, new_requirement, demand_sources |

These events can be streamed via PostgreSQL LISTEN/NOTIFY to an AI inference service for real-time predictions.

---

## Data Retention & Training Windows

| Dataset | Minimum History | Maximum History | Notes |
|---------|----------------|----------------|-------|
| DS-001 Decision History | 1 year | Indefinite | Immutable — never deleted |
| DS-002 Demand History | 2 years | 5 years | Seasonal patterns require 2+ years |
| DS-003 Supplier Performance | 1 year | 3 years | Supplier relationships change |
| DS-004 Recipe Performance | 6 months | Indefinite | Per BOM version |
| DS-005 Cost Trends | 2 years | 5 years | Cycle detection needs 2+ years |
| DS-006 Consumption Velocity | 1 year | 3 years | Seasonal window |
| DS-007 Procurement Queue | 1 year | Indefinite | Immutable scheduler_runs |
| DS-008 Disassembly Recovery | 6 months | Indefinite | Builds slowly |

---

## AI Readiness Checklist

| Requirement | Status | Notes |
|-------------|--------|-------|
| All decisions logged with full payload | ✓ DESIGNED | decision_logs with JSONB event_payload |
| Immutable transaction history | ✓ DESIGNED | 4 immutable tables + DB-level REVOKE |
| Outcome tracking per decision | ✓ DESIGNED | resolution_status + resolution_notes in decision_logs |
| Supplier performance data | ✓ EXISTING | goods_receipts + purchase_orders already exist |
| Recipe vs actual cost comparison | ✓ DESIGNED | manufacturing_consumptions vs bill_of_material_lines |
| Demand time-series | ✓ EXISTING | order_lines + orders already exist |
| Recovery yield tracking | ✓ DESIGNED | disassembly_recoveries with recovered_quantity |
| Cost change history | ✓ DESIGNED | product_cost_histories (append-only) |
| Event payload stored as JSONB | ✓ DESIGNED | event_payload in decision_logs |
| Consumption type discrimination | ✓ DESIGNED | consumption_type in inventory_layer_consumptions |
| Human override tracking | ✓ DESIGNED | resolution_status = 'human_override' in decision_logs |
| Per-BOM version performance | ✓ DESIGNED | bom_version_number in manufacturing_transactions |

**Awaiting approval before any implementation begins.**
