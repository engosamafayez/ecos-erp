# Operations Planning Engine

**Status:** Approved (Domain Sprint 03)
**Layer:** Operations Planning

---

## 1. Core Principle

The Operations Planning Engine is the **operational brain** that transforms customer orders into executable warehouse operations.

It is NOT:
- Inventory management (separate layer)
- Manufacturing management (separate layer)
- Shipping management (separate layer)

It IS:
- The planning layer that bridges Commerce (Orders) and Execution (Warehouse)
- The system that converts individual orders into efficient batch operations
- The engine that calculates materials, production, and logistics requirements

### The Fundamental Shift

> Warehouse teams never work directly on individual orders.
> Warehouse teams work on **Fulfillment Batches**.

This is the most important operational decision in the system.

---

## 2. Operations Flow

```
Orders (from Commerce layer)
    ↓
Operations Planning
    ↓
Material Requirements Planning (MRP)
    ↓
Production Requirements Planning (PRP)
    ↓
Wave Picking
    ↓
Channel Distribution
    ↓
Vehicle Loading
    ↓
Shipping
```

---

## 3. Fulfillment Batch

The Fulfillment Batch is the **primary operational unit** in the warehouse.

### Definition

A Fulfillment Batch groups multiple orders into a single executable warehouse operation. The warehouse team works the batch as a unit — not order by order.

### Batch Fields

```
FulfillmentBatch
├── id
├── batch_number (e.g. BATCH-2025-001234)
├── warehouse_id → Warehouse
├── planning_date
├── status: BatchStatus
├── stats
│   ├── orders_count
│   ├── products_count
│   ├── lines_count
├── requirements
│   ├── required_products[] → { product_id, quantity_needed, quantity_available, shortage }
│   └── required_materials[] → { material_id, quantity_needed, quantity_available, shortage }
├── assignment
│   ├── areas[] → WarehouseArea
│   ├── vehicles[] → Vehicle
│   └── users[] → User
├── notes
├── created_by → User
├── created_at
├── approved_by → User
├── approved_at
└── ActivityEvents[]
```

### Batch Lifecycle

```
Draft
  ↓
Planning (MRP + PRP calculated)
  ↓
Waiting Materials (if shortage exists)
  ↓
Manufacturing (if production required)
  ↓
Ready For Picking
  ↓
Picking
  ↓
Distribution (channel dispatch profiles applied)
  ↓
Loading (vehicles assigned and loaded)
  ↓
Completed

Dead ends:
Cancelled
```

---

## 4. Batch Builder

### Step 1 — Select Orders

Operator selects orders to include in the batch using filters:

| Filter | Examples |
|--------|---------|
| Today's Orders | All orders created today |
| By Status | Confirmed orders ready for preparation |
| By Channel | WooCommerce orders only |
| By Area | Governorate / city filters |
| By Warehouse | Specific warehouse scope |

Manual selection is also supported (individual order checkboxes).

### Step 2 — Calculate Requirements

The system automatically calculates:

- **Products Required**: sum of all order line quantities, per product
- **Raw Materials Required**: Bill-of-Materials explosion for each product
- **Manufacturing Requirements**: products not in stock that must be produced
- **Purchase Requirements**: raw materials not in stock that must be purchased

### Step 3 — Assign Resources

- Warehouse (from default or manual selection)
- Areas within the warehouse
- Vehicles
- Responsible team / users

### Step 4 — Generate Batch

System creates the FulfillmentBatch record with status `Planning`.

### Step 5 — Review & Approve

Planning supervisor reviews:
- Requirements accuracy
- Material availability
- Manufacturing timeline
- Vehicle assignment

Approves → batch moves to `Waiting Materials` or `Ready For Picking`.

---

## 5. Material Requirements Planning (MRP)

The MRP engine calculates what raw materials must be procured.

### MRP Calculation

For each batch:
1. Collect all products and quantities
2. Explode Bill-of-Materials for each product
3. Sum total raw material requirements
4. Compare against current stock
5. Calculate shortage per material

### MRP Output

```
PurchaseRequirement
├── batch_id
├── material_id → RawMaterial
├── quantity_required
├── quantity_available (current stock)
├── quantity_to_purchase
└── expected_delivery_date
```

This output is sent to the Purchasing module as a **Purchase Requirements List**.

---

## 6. Production Requirements Planning (PRP)

The PRP engine calculates what finished products must be manufactured.

### PRP Calculation

For each batch:
1. Sum required finished product quantities
2. Compare against available finished goods stock
3. Calculate products to manufacture
4. Assign manufacturing priority (based on batch date)

### PRP Output

```
ManufacturingPlan
├── batch_id
├── product_id → Product
├── quantity_required
├── quantity_available
├── quantity_to_manufacture
└── priority: number
```

This output becomes a **Manufacturing Queue** sent to the Manufacturing module.

---

## 7. Wave Picking

Wave Picking is the warehouse execution method for collecting products.

### Wave Picking Principle

The warehouse does NOT pick products order-by-order.

Instead:
1. Sum ALL products needed across ALL orders in the batch
2. Generate a consolidated pick list
3. Warehouse team picks all quantities at once

### Example

Instead of picking for 125 separate orders:

| Product | Total Required |
|---------|---------------|
| Honey 500g | 420 units |
| Coffee Blend | 180 units |
| Medjool Dates | 95 units |

One warehouse pick operation serves all 125 orders.

### Wave Pick List

```
WavePickList
├── batch_id
├── items[]
│   ├── product_id → Product
│   ├── sku
│   ├── location (warehouse zone / shelf)
│   ├── quantity_to_pick
│   └── quantity_picked (tracked during execution)
└── status: pending | in_progress | completed
```

---

## 8. Channel Distribution

After Wave Picking, products are distributed according to each channel's **Dispatch Profile**.

### Dispatch Profiles

| Profile | Process |
|---------|---------|
| `bulk_distribution` | Products loaded directly by quantity. No individual packing. Vehicle receives: "Honey: 120 units, Coffee: 45 units." |
| `pack_during_loading` | Products are packed into individual customer cartons during driver handover. Packing happens at the vehicle, not in the warehouse. |
| `pre_packed` | Orders are pre-packed in the warehouse before the vehicle arrives. Each package is labeled and ready. |

Each channel defines its own dispatch profile. New profiles can be added as business requirements evolve without changing the planning engine.

---

## 9. Vehicle Loading

Each vehicle receives an assignment for a specific batch.

```
VehicleAssignment
├── batch_id
├── vehicle_id → Vehicle
├── driver → User
├── areas[] (governorates / cities covered)
├── orders[] → Order[]
├── products[] → { product_id, quantity }
├── packed_items[] (for pack_during_loading profile)
├── loading_checklist[]
│   ├── item: string
│   ├── checked: boolean
│   └── checked_by → User
├── departure_time (planned)
├── actual_departure_time
└── status: pending | loading | loaded | dispatched
```

---

## 10. Operations Dashboard

Real-time operational view of today's operations.

### KPI Cards

| KPI | Description |
|-----|-------------|
| Today's Orders | Total orders for today |
| Fulfillment Batches | Active batches today |
| Products Required | Total SKU count across active batches |
| Raw Materials Required | Materials needed for today's production |
| Manufacturing Jobs | Open manufacturing orders |
| Vehicles Ready | Vehicles cleared for loading |
| Vehicles Loading | Vehicles currently being loaded |
| Dispatch Progress | % of today's batches dispatched |
| Completed Deliveries | Confirmed deliveries today |

---

## 11. Activity

Every operational action generates an Activity event:

| Event | Trigger |
|-------|---------|
| `batch_created` | Batch builder completes |
| `planning_approved` | Supervisor approves batch plan |
| `materials_calculated` | MRP run completes |
| `manufacturing_started` | Manufacturing job linked to batch |
| `picking_started` | Wave pick list activated |
| `picking_completed` | All products picked |
| `distribution_started` | Channel dispatch profiles applied |
| `vehicle_loaded` | Vehicle loading completed |
| `batch_completed` | All vehicles dispatched |

---

## 12. Design Principles

1. **Planning before Execution** — plan is always created before warehouse execution begins
2. **Batch before Order** — warehouse team sees batches, not individual orders
3. **Wave Picking before Packing** — collect all products first, then distribute
4. **Channel Dispatch Rules after Picking** — dispatch profiles are applied post-collection
5. **Warehouse operators execute batches** — not orders (customer service executes orders)
6. **Planning is centralized** — done once per batch by an authorized planner
7. **Execution is decentralized** — warehouse team, production team, drivers work independently
8. **Everything generates Activity** — every action creates an audit trail
9. **Everything is auditable** — all decisions can be reviewed and explained

---

## 13. Entity Relationships

```
FulfillmentBatch
├── → Warehouse
├── Orders[] → Order
├── RequiredProducts[] → Product
├── RequiredMaterials[] → RawMaterial
├── ManufacturingJobs[] → ManufacturingJob
├── WavePickList → WavePickList
├── VehicleAssignments[] → Vehicle
├── ChannelDistributions[] (by dispatch profile)
└── ActivityEvents[]
```

---

## 14. Future Suggestions

- **Route Optimization** — optimize vehicle routes across order delivery addresses
- **Dynamic Batching** — AI-suggested batch groupings based on area and vehicle capacity
- **Real-time Driver App** — mobile interface for drivers to confirm deliveries
- **Warehouse Navigation** — pick path optimization based on shelf locations
- **Batch Templates** — save batch configurations for recurring daily operations
- **Predictive MRP** — use historical patterns to pre-calculate next-day requirements
