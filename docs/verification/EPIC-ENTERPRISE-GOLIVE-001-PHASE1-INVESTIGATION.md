# EPIC-ENTERPRISE-GOLIVE-001 — Phase 1
## Engineering Investigation: RC-9 and RC-10

**Date:** 2026-08-08
**Type:** Investigation only. No code modified, no patch generated, no fix proposed.
**Source of truth:** [ECOS ERP Enterprise Certification](ECOS-ERP-ENTERPRISE-CERTIFICATION-FINAL.md)

---

# Executive finding

Both root causes have the **same shape** and **different mechanisms**:

> **A canonical source of truth exists and is correct. A second value that answers the same
> business question was computed somewhere else, and the two were never connected.**

| | RC-9 | RC-10 |
| --- | --- | --- |
| Canonical source | `InventorySummaryService` | `InventoryItem` reservations |
| Divergent value | `products.stock_status` column | `OrderResource::resolveAllowedTransitions()` |
| Where it lives | Persistence (a stored column) | Presentation (an HTTP Resource) |
| Why it diverged | It is an **inbound WooCommerce attribute**, not an ERP calculation | It is a **pure function of the status enum** and reads nothing else |
| Connection between them | **None** | **None** |

Neither is a bug in the canonical engine. **In both cases the engine is correct and was simply not
consulted.**

---

# PART A — RC-9: Inventory state not derived from the inventory source

## A1. The layer trace

### Layer 1 — Inventory Ledger (source of record)

**Source of truth:** `stock_ledger_entries`, surfaced at `/app/stock-ledger` as *"Complete audit
trail of all inventory movements."*

Movement events are first-class domain events:
`InventoryStockReceived` · `InventoryStockAdjusted` · `InventoryStockReserved` ·
`InventoryStockReleased` · `InventoryStockShipped`
(`Modules/Inventory/DomainEvents/Events/`)

Write actions: `AdjustmentInAction` · `AdjustmentOutAction` · `DirectIssueStockAction`
(`Modules/Inventory/InventoryItems/Application/Actions/`)

**Observed state (Campaign 5):** `No movements found` — the ledger is empty and has never recorded
a movement.

### Layer 2 — Inventory Quantities

**Source of truth:** `InventoryItem` (`inventory_items`), aggregated by
**`Modules/Inventory/InventoryItems/Domain/Services/InventorySummaryService.php`**.

The service documents its own authority explicitly:

```
InventorySummaryService — the single source of truth for inventory quantities.

EPIC-DATA-CONSOLIDATION-001, Phase B. Replaces the ~10 ad-hoc availability/value
calculations scattered across repositories, controllers, dashboards, demand and
manufacturing services.

OFFICIAL ENTERPRISE RULE — availability is computed CLAMP-PER-WAREHOUSE, THEN SUM:
    available = Σ over warehouses of max(on_hand − reserved, 0)

No screen may calculate availability or value itself.
```

- Reads `InventoryItem` filtered by `product_id` and optionally `company_id`
- Returns an `InventorySummary` DTO
- Delegates valuation to `EnterpriseCostEngine` on a FIFO basis

**This layer is correct.** It is the reason Inventory Dashboard reported `0 units on hand`,
`EGP 0.00` and `Available Units 0` — accurate for an empty ledger.

### Layer 3 — Inventory **Status** ← **the divergence**

**Source of truth: none. It is a stored column.**

```
Migration: 2026_06_23_111000_add_enrichment_fields_to_products_table.php
    $table->string('stock_status')->nullable()->after('is_active');
```

Write paths — all three accept it as **user input**:

| Request | Rule |
| --- | --- |
| `StoreProductRequest.php:75` | `['nullable', Rule::in(['instock','outofstock','onbackorder'])]` |
| `UpdateProductRequest.php:143` | `['nullable', Rule::in(['instock','outofstock','onbackorder'])]` |
| `PatchProductRequest.php:28` | `['sometimes','string','in:instock,outofstock,onbackorder']` |

Read path — `EloquentProductRepository.php:119-121`:

```php
$stockStatus = trim((string) ($filters['stock_status'] ?? ''));
if ($stockStatus !== '') {
    $query->where('products.stock_status', $stockStatus);
}
```

**The value is stored, filtered on, and never computed.**

### The decisive detail

The permitted values are **`instock` · `outofstock` · `onbackorder`** — verbatim **WooCommerce**
`stock_status` vocabulary. Not `in_stock`, not an ERP enum. The migration that introduced it is
named **`add_enrichment_fields_to_products_table`** and adds it beside `short_description` and
`long_description` — e-commerce catalogue attributes.

**`stock_status` is an inbound channel-sync attribute describing what the storefront advertises.
It is not, and was never, an ERP inventory fact.** It is displayed in the ERP grid in a column
headed `Stock Status`, immediately beside `On Hand` and `Available`, which *are* ERP facts.

### Layers 4–7 — Reservation, Order Workflow, Preparation, Dispatch

Reservation state is modelled separately: `ReservationStatus` enum
(`Modules/Commerce/Orders/Domain/Enums/`), `UpdateReservationStatusAction`,
`RetryReservationOnStockAvailableListener`. Order-side reservation reads `Reserved At` and
`Assigned Warehouse` — which reported truthfully (`Not Reserved`, `—`).

**No layer downstream of Layer 2 consults `products.stock_status`, and `products.stock_status`
consults nothing.** It is a leaf, written by sync and read by the grid.

## A2. The four questions

| Question | Answer |
| --- | --- |
| **What is the source of truth?** | Quantities: `InventoryItem` via `InventorySummaryService`. Status: **none — it is stored input.** |
| **Where is it calculated?** | Quantities: `InventorySummaryService::summarize()`. Status: **nowhere.** |
| **Where is it duplicated?** | It is not duplicated — it is a **different fact** wearing the same name. `stock_status` = storefront availability; `available` = ERP availability. |
| **Where does it diverge?** | At the presentation boundary, where a channel attribute and an ERP calculation are rendered as adjacent columns in one table with no distinction. |

## A3. Why RC-9 happened

1. `stock_status` was introduced as a **WooCommerce catalogue enrichment field** (June 2026), correctly modelled as a stored, syncable string.
2. `InventorySummaryService` was introduced later (`EPIC-DATA-CONSOLIDATION-001, Phase B`) to consolidate ~10 scattered availability calculations, and correctly claimed authority over quantities.
3. **The consolidation covered `available` and `value`. It did not cover `status`** — because `status` was not one of the ~10 calculations; it was never a calculation at all.
4. The Raw Materials and Products grids render both, adjacent, unlabelled as to origin.

**RC-9 is not a defect in either component. It is an unowned seam** — a channel attribute and an
ERP metric that answer the same user-facing question, with no rule stating which wins.

The same seam explains the two aggregate-level symptoms:
- **`All Materials 0` above a table of 2** — `/api/products/stats` and `/api/products` are separate endpoints applying different filters (Campaign 2 network evidence).
- **CRM `Total customers 1` vs Orders' 2** — the same shape at a different boundary (Campaign 8).

---

# PART B — RC-10: Orders permit transitions that should be blocked

## B1. The layer trace

### Where the transition list is produced

**`Modules/Commerce/Orders/Presentation/Http/Resources/OrderResource.php`
→ `private function resolveAllowedTransitions(): array` (line 320)**

This is an **HTTP Resource — the serialization layer.** Its declared contract:

```
V2 allowed workflow transitions (TASK-ORDER-WORKFLOW-V2-001).

CONTRACT:
  - target_status  : business state — frontend uses this as the Select value
  - label          : display string
  - requires_reason: UX prompts for reason before confirming
  - action         : opaque workflow key — for audit/transparency only;
                     frontend must NOT route on this
```

Implementation shape:

```php
return match ($this->status) {
    OrderStatus::NewOrder => [
        $t('in_progress',      'Start Processing', false, 'initiate_order'),
        $t('awaiting_payment', 'Awaiting Payment', false, 'set_early_status'),
        $t('scheduled',        'Schedule',         false, 'set_early_status'),
        $t('on_hold',          'Put On Hold',      true,  'put_on_hold'),
        $t('cancelled',        'Cancel',           true,  'cancel_order'),
    ],
    ...
};
```

**`match ($this->status)` — a pure function of one input: the status enum.**

It receives no reservation state, no warehouse assignment, no inventory summary, and references
none. There is no `if` on any precondition anywhere in the method.

### Where the frontend renders it

`frontend/src/features/orders/components/order-detail-drawer.tsx` (~line 1420):

```tsx
{transitions.length > 0 ? (
  <SectionTitle>{t($ => $.drawer.workflow.availableActions)}</SectionTitle>
  {transitions.map((tr, idx) => {
    const variant = tr.target_status === 'cancelled' ? 'destructive'
                  : idx === 0                        ? 'default'
                  : 'outline';
```

**The frontend adds no logic.** It renders the server list verbatim. `Mark Ready` appeared as the
highlighted primary action solely because **`idx === 0`** — it is first in the `InProgress` match
arm. Its visual prominence is an artefact of array order, not a business judgement.

### What the reservation layer actually knew

At the same moment, the order's own Inventory tab correctly reported:

```
RESERVATION STATUS   Reserved At: Not Reserved     Shipped At: —
FULFILLMENT          Assigned Warehouse: —         Line Items: 1
```

**The data required to block the transition was present, on the same screen, in the same API
response.** It was simply never an input to `resolveAllowedTransitions()`.

### Downstream: Preparation and Dispatch

`PrepareOrderAction`, `HandlePreparationWaveStarted`, `HandlePreparationWavePreparationStarted`
consume order state. `ReservationStatus`, `UpdateReservationStatusAction` and
`RetryReservationOnStockAvailableListener` maintain reservation state independently — the retry
listener proving reservation is genuinely event-driven and functional.

**No search of `Modules/Commerce` found a domain-layer state machine, a policy class, or any
`canTransition` / `allowedTransitions` guard.** The only transition authority in the codebase is
the Resource method above.

## B2. The four questions

| Question | Answer |
| --- | --- |
| **What is the source of truth?** | For *reservation*: `InventoryItem` reservations via `ReservationStatus` — correct. For *what may happen next*: `OrderResource::resolveAllowedTransitions()`, in the presentation layer. |
| **Where is it calculated?** | `OrderResource.php:320`, from `$this->status` alone. |
| **Where is it duplicated?** | It is not duplicated — it is **misplaced**. Business rules live in a serializer. |
| **Where does it diverge?** | The moment an order's *legal* next states depend on anything other than its current status — i.e. reservation, warehouse, stock or payment. |

## B3. Why RC-10 happened

1. `TASK-ORDER-WORKFLOW-V2-001` correctly built a **status-transition graph** — which states may follow which. That graph is right, and richer than most ERPs ship.
2. It was placed in `OrderResource` because its purpose was **to tell the UI what buttons to draw**. As a *presentation* concern that is a reasonable location.
3. **No layer was ever given the second responsibility: whether a legal transition is currently *permissible*.** The graph answers *"can In Progress become Ready for Dispatch?"* — yes. Nobody owns *"can **this** order become Ready for Dispatch **right now**?"*
4. Because the authority sits in a serializer, it structurally **cannot** consult reservation state — Resources receive a model, not domain services.

**RC-10 is not a missing check. It is a missing layer.** The distinction matters for Phase 2: adding
an `if` to `OrderResource` would place a business rule permanently in the presentation tier.

---

# PART C — Cross-cutting observations

## C1. Both failures are seams, not components

Every component examined is individually well-built:

| Component | Assessment |
| --- | --- |
| `InventorySummaryService` | Correct, documented, explicitly authoritative; clamp-per-warehouse rule stated and justified |
| Inventory domain events | Complete lifecycle: received / adjusted / reserved / released / shipped |
| `ReservationStatus` + retry listener | Genuinely event-driven; the retry-on-stock-available listener is sophisticated |
| `OrderStatus` enum | 11 states with `isTerminal()`, `isLocked()`, `isPreActivation()` — real semantics |
| Transition graph | Comprehensive; V3 lifecycle documented in-code |
| `products.stock_status` | Correct **as a WooCommerce sync field** |

**Nothing here is badly built. The failures are in the spaces between things that are.**

## C2. `InventorySummaryService` proves the pattern is understood

Its docblock — *"Replaces the ~10 ad-hoc availability calculations… No screen may calculate
availability or value itself"* — is a precise description of the RC-9 failure mode, written by
someone who had already diagnosed and fixed it **for quantities**.

**RC-9 is the same disease in an organ the consolidation did not reach.** Status was never in
scope because status was never a calculation.

## C3. Why the frontend is not implicated in either

- RC-9: the grid renders `stock_status` from the API; it performs no computation.
- RC-10: the drawer renders `transitions` from the API; `idx === 0` is styling only.

**Both root causes are entirely server-side.** The frontend faithfully displays what it is given —
which is why the contradictions are visible on screen: it hid nothing.

## C4. Confidence

| Claim | Confidence | Basis |
| --- | --- | --- |
| `stock_status` is a stored, user-writable column | **High** | Migration + 3 request validators + repository filter, all read directly |
| It originates from WooCommerce sync | **High** | Exact value vocabulary; `enrichment_fields` migration name; sits beside catalogue descriptions |
| `InventorySummaryService` is the canonical quantity source | **High** | Self-declared with documented rule; consistent with observed correct dashboard/ledger values |
| Transitions computed solely from status | **High** | `match ($this->status)` read directly; no other input in scope |
| No domain-layer transition guard exists | **Medium-High** | Repository-wide search of `Modules/Commerce` found none. **Cannot prove a negative** — a guard elsewhere (e.g. an Action) was not exhaustively excluded |
| The write path also lacks the guard | **UNVERIFIED** | This investigation traced the **read/offer** path. Whether `PatchOrderAction` or the transition endpoint enforces preconditions server-side was **not** examined. **Phase 2 must confirm this before assuming the write path is unguarded.** |

---

# PART D — Answers to the required questions

### Why did RC-9 happen?

**Because two different facts were given one name.** `products.stock_status` is a WooCommerce
catalogue attribute — what the storefront advertises — stored as user input and never computed.
`available` is an ERP quantity — computed by `InventorySummaryService` from `InventoryItem`.
The `EPIC-DATA-CONSOLIDATION-001` consolidation unified availability *calculations*, but
`stock_status` was never a calculation, so it was outside that scope. The two are rendered as
adjacent columns with nothing declaring which answers *"do we have stock?"*

### Why did RC-10 happen?

**Because the transition graph was built as a UI contract, not a domain guard.**
`resolveAllowedTransitions()` lives in an HTTP Resource and is a pure `match` on the status enum.
It correctly answers *"which states may follow this state?"* — a static-graph question. Nobody
owns the dynamic question *"is this transition permissible for this order right now?"*, and
because the authority sits in the serialization layer, it structurally cannot reach reservation or
inventory state to answer it.

---

# PART E — Open questions for Phase 2

Recorded as questions, not recommendations:

1. **Does the transition write path enforce preconditions?** This investigation traced the read
   path only. If `PatchOrderAction` already guards, RC-10's blast radius is presentational.
   **This is the first thing Phase 2 should establish.**
2. **Is `products.stock_status` consumed by outbound channel sync?** If the storefront reads it
   back, it cannot simply be repointed at `InventorySummaryService` without changing what
   WooCommerce publishes.
3. **Which other Resources compute business rules?** `OrderResource` is unlikely to be unique.
4. **Do `/api/products` and `/api/products/stats` share a filter path?** The `All Materials 0`
   symptom suggests not.
5. **Does any other screen read `stock_status`?** The repository filters on it — the Products
   quick-filter chips (`In Stock` / `Out of Stock`) likely depend on it, so its meaning is already
   load-bearing in the UI.

---

**No code was modified. No patch was generated. No fix or redesign is proposed. All findings are
traced to specific files and line numbers and were read directly from the repository.**
