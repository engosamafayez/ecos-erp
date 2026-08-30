# TASK-ORDER-FULFILLMENT-STATE-CONTRACT-001 — Engineering Report

**Status:** AUDIT COMPLETE — nothing implemented. No production code, migration, test, status, order, or reservation was modified.
**Date:** 2026-08-12 · **Environment:** `C:\ecos-develop`, DB `ecos_dev` · **Branch:** `develop`

> ## ⛔ PART 16 GATE — CERTIFIED BASELINE PARITY = **BROKEN**
>
> | | MD5 |
> |---|---|
> | Certified host (expected) | `ce69612a5910ad7eb84c354895b45140` |
> | **Container `ecos-dev-app`** | **`4c2903b8fc751d05755b6fb8cdfa3546`** |
> | `git show HEAD:…` | `4c2903b8fc751d05755b6fb8cdfa3546` — **identical to the container** |
>
> The container runs the **pre-repair HEAD** `MaterialDemandCalculator`. Per the stop rule, **no container demand or preparation output is used as certification evidence anywhere in this report.** Every runtime claim below rests on files with verified host↔container parity, and each such claim states which.
>
> This is a `docker cp` gap, not a code defect — the source volume is not hot-mounted in this stack.

---

## 1. Executive Summary

The audit set out to establish one authoritative Order → Preparation state contract. It found that **no single authoritative contract currently exists**: the chain is governed by four separate authorities that disagree with each other, and **three integration seams are severed**, two of which were not visible in the previous diagnostic.

**The headline finding is not the locale bug. It is that the warehouse-assignment → preparation seam is disconnected at the event layer.**

`BranchAssignmentEngine` — the live engine — dispatches `BranchAssigned`. **That event has zero listeners.** The listener that attaches an order to a preparation session, `WarehouseAssignedListener`, subscribes to `WarehouseAssigned`, which is dispatched **only** by the legacy `WarehouseAssignmentEngine` that `BranchAssignmentEngine` replaced. The engine swap moved the dispatch and left the subscription behind.

Five further findings compound it:

1. **ORD-00002 has a different root cause than ORD-00001.** It has `governorate = NULL` → `markUnresolved()` → source `unassigned`. **The locale repair will not fix it.** This corrects the previous report's statement that one root cause covered both orders.
2. **The live Wave Engine config filters on `"confirmed"`** — a status that does not exist in the V3 `OrderStatus` enum. The Wave Engine can never attach any order in this environment.
3. **`StockAddedListener` queries a non-existent column** (`on_hand_quantity`; the real column is `on_hand_qty`), inside a `try/catch` that only logs. Preparation's stock-arrival recovery fails silently.
4. **The Preparation Entry Gate has no reservation or material prerequisite** — it checks status and warehouse only. Availability is *not* a preparation gate.
5. **`awaiting_stock` is not an eligible preparation status**, so any order routed there is structurally excluded from preparation until something changes its status — and nothing does.

**Net effect:** an order that fails warehouse assignment is unrecoverable by any automatic path, and — because of finding 1 — even a correct locale fix leaves half the current order population stuck.

**Recommendation: do not repair the locale defect in isolation.** It is one of six independent defects on a single chain, and fixing it alone changes ORD-00001's failure reason without releasing it, while leaving ORD-00002 untouched. The sequence in §19 orders the work; §18 lists the seven decisions that must be approved first.

---

## 2. Current ORD-00001 State

Read-only, `ecos_dev`, 2026-08-12.

| Field | ORD-00001 |
|---|---|
| `id` | `019fd976-2cda-731b-b878-0f72c6f97b38` |
| `company_id` | `019f4e1c-2d1e-719d-873c-75779ab67251` |
| `status` | `awaiting_stock` (from `new`) |
| `reservation_status` | `awaiting_stock` |
| `reservation_failure_reason` | **"Warehouse Not Assigned"** |
| `assigned_warehouse_id` / `assigned_branch_id` | **NULL** / **NULL** |
| `warehouse_assignment_source` | `no_branch_coverage` |
| `warehouse_assignment_failure_reason` | "No Branch Covers Destination" |
| `governorate` / `city` | `القاهرة` / `مدينة نصر` |
| `area` / `delivery_zone` / GPS | NULL / NULL / NULL |
| `inventory_reserved_at`, `confirmed_at`, `preparation_completed_at` | NULL |
| Line | FG-000001 عسل الصال كيلو · `finished_good` · qty **2.0000** · reserved 0 |

Last re-initiation: **2026-08-12 03:37:39**, reproducing `awaiting_stock` / `no_warehouse_assigned` identically. The state is live, not stale.

---

## 3. Order State Machine

`OrderStatus` (`Modules/Commerce/Orders/Domain/Enums/OrderStatus.php`) defines **exactly 11 states**. The task brief listed `preparing`, `prepared`, `confirmed`, and `refused` — **none of these exist as order statuses.** Per the "do not invent states" instruction, they are recorded here as non-existent:

- **`preparing` / `prepared`** — deliberately removed in V3. `MoveToPreparationWorkflow.php:20-23`: *"Previously moved order to Preparing status. In V3, Preparing is an invisible engine state — orders stay In Progress while being prepared."*
- **`confirmed`** — subsumed by `in_progress`. `PreparationSessionPolicy.php:86`: *"'in_progress' — subsumes the former confirm/confirmed"*. **It survives as a stale data value — see §10.**
- **`refused`** — no such status. Nearest equivalents are `cancelled` and `returned`.

### The 11 real states

| Group | States |
|---|---|
| Primary flow | `new` → `in_progress` → `ready_for_dispatch` → `out_for_delivery` → `delivered` |
| Exception | `awaiting_payment`, `awaiting_stock`, `scheduled`, `on_hold` |
| Terminal | `delivered`, `cancelled`, `returned` |

`isTerminal()` = {delivered, cancelled, returned}. `isLocked()` = everything except {new, scheduled, awaiting_payment} — structural data is immutable from `in_progress` onward.

### Transition table (source-derived; 23 workflows in `Operations/Fulfillment/Application/Workflows/`)

| From | Trigger (workflow · `name()`) | Condition | To | Reservation effect | Warehouse effect | Preparation effect | Re-evaluation trigger |
|---|---|---|---|---|---|---|---|
| `new` | **auto** on create — `CreateManualOrderAction:188` → `ProcessOrderWorkflow` · `initiate_order` | `assigned_warehouse_id === null` | **`awaiting_stock`** | set `awaiting_stock`, reason "Warehouse Not Assigned"; **reservation never attempted** | none — never re-assigned | excluded (§10) | **NONE** |
| `new` | same | warehouse present, all lines satisfiable | `in_progress` | `reserved` | unchanged | eligible | — |
| `new` | same | warehouse present, some lines satisfiable | `in_progress` | `partial_reserved` | unchanged | eligible, but dispatch needs approval | `ApprovePartialReservationWorkflow` |
| `new` | same | warehouse present, no line satisfiable | `awaiting_stock` | `awaiting_stock`, reason "Insufficient Inventory" | unchanged | excluded | `RetryReservationOnStockAvailableListener` (works only if warehouse ≠ NULL) |
| `new`/`awaiting_payment`/`awaiting_stock`/`scheduled`/`on_hold`/`cancelled`/`in_progress` | `ProcessOrderWorkflow` guard allow-list (`:44-53`) | status in allow-list | as above | idempotent skip if already Reserved/PartialReserved | — | — | manual only |
| `scheduled` | `orders:activate-scheduled` (daily 00:05) | `requested_delivery_date <= today` | `in_progress` (via ProcessOrder) | attempts reservation | — | — | scheduled |
| `in_progress` | `MoveToPreparationWorkflow` · `ready_for_dispatch` | reservation not terminal; partial needs approval | **`ready_for_dispatch`** | auto-reserves on the fly if missing | **not guarded — see §6** | wave/session work happens *during* `in_progress` | — |
| `in_progress` | `MoveToPreparationWorkflow` | on-the-fly reservation returns `AwaitingStock` | `awaiting_stock` | `awaiting_stock` | — | detached by observer | none |
| `ready_for_dispatch` | `DispatchOrderWorkflow` · `dispatch_order` | — | `out_for_delivery` | → `transferred` | — | — | — |
| `out_for_delivery` | `CompleteDeliveryWorkflow` · `complete_delivery` | — | `delivered` | → `consumed` | — | — | — |
| any active | `MarkAwaitingStockWorkflow` · `mark_awaiting_stock` | manual | `awaiting_stock` | `awaiting_stock` | — | detached | none |
| `awaiting_stock`/`on_hold` | `ResumeOrderWorkflow` · `resume_order` / `RevertToConfirmedWorkflow` | manual | `in_progress` | re-attempt | — | re-eligible | **manual only** |
| any | `CancelOrderWorkflow` · `cancel_order` | — | `cancelled` | → `released` (**terminal**) | — | detached | — |
| any | `ReturnToPendingWorkflow` · `return_to_new` | manual | `new` | — | — | — | — |

**Both writers of `awaiting_stock`-on-null-warehouse** are `ProcessOrderWorkflow.php:97-119` and the identical `ConfirmOrderWorkflow.php:89-111`.

---

## 4. Warehouse Assignment Contract

### 4.1 What geographic field is authoritative?

`BranchAssignmentEngine::assign()` (`:57-58`) reads exactly two order fields:

```php
$governorate = (string) ($order->governorate ?? '');
$zone        = (string) ($order->area ?? $order->delivery_zone ?? '');
```

**`governorate` is the sole authoritative input.** `city` is **never read** — ORD-00001's `مدينة نصر` plays no part in assignment. `area` / `delivery_zone` are the optional zone refinement; both are NULL on ORD-00001, so only governorate-wide coverage could match.

### 4.2 How is governorate stored?

**As free text, in Arabic**, on `orders.governorate` (`varchar(255)`). It is *not* an ID and carries no FK. The order also has an unused `logistics_city_id` (`bigint`, NULL here) — a normalised reference that the assignment path does not consult.

The coverage side is fully normalised: `branch_coverage_areas` keys on `master_governorate_id` / `master_zone_id` (UUID FKs). **The mismatch is therefore structural — free Arabic text on one side, normalised English-named master rows on the other**, bridged only by a name string comparison.

### 4.3 What should `CoverageResolutionService` compare?

Today (`:38`) it compares against `name` only:

```php
MasterGovernorate::whereRaw('LOWER(name) = LOWER(?)', [trim($governorate)])
```

`master_governorates` carries **both** `name` (English) and `name_ar` (Arabic), plus a `code` (`CAI`). All 27 rows store English in `name`. The Cairo row is `name='Cairo'`, `name_ar='القاهرة'`, `code='CAI'`, active.

The same single-column assumption exists at `:48` for `MasterZone`.

**What it *should* compare is a business decision (§18 Q1)** — `name_ar` as an additional match, or a proper normalised reference (`logistics_city_id` / a governorate FK on the order) that removes string matching entirely. The audit does not choose.

### 4.4 Does a canonical governorate resolver already exist?

**No.** `CoverageResolutionService` is the only resolver from free text → master governorate. Three other governorate representations exist without a shared normaliser:

| Table / field | Shape |
|---|---|
| `orders.governorate` | free text (Arabic) |
| `master_governorates` | `name` (En) + `name_ar` (Ar) + `code` |
| `logistics_governorates` | separate table |
| `warehouse_assignment_policies.governorate` | free text again (legacy engine) |
| `orders.logistics_city_id` | normalised FK — **unused by assignment** |

**Cities/zones are not normalised on the order.** `orders.city` is free text and unread; `preparation_wave_orders` stores both `governorate_snapshot` **and** `master_governorate_id`, i.e. the preparation layer expects a resolved ID that the order layer never produces.

### 4.5 When is warehouse assignment supposed to happen?

**Once, at creation, and never again.** `CreateManualOrderAction:183` calls `assign()`, then `:188` auto-triggers `ProcessOrderWorkflow`. `BranchAssignmentEngine` has exactly one production caller (its own docblock says *"Caller: CreateManualOrderAction (and any other order ingestion path)"* — no other path exists).

There is **no** re-assignment on entering `in_progress`, no retry workflow, no scheduled sweep. The only re-assignment surface is the manual `WarehouseAssignmentController` — which uses the **legacy** engine (§9.1).

### 4.6 Assignment outcomes

| Outcome | `warehouse_assignment_source` | `failure_reason` | Sets status? |
|---|---|---|---|
| Coverage matched | `branch_coverage` | NULL | no |
| Manual override | `manual_override` | NULL | no |
| **No governorate on order** | **`unassigned`** (`markUnresolved`) | **NULL** | no |
| **Coverage returned nothing** | **`no_branch_coverage`** (`markNoCoverage`) | "No Branch Covers Destination" | no |
| Branch has no active warehouse | `no_branch_coverage` | "Assigned branch has no active warehouse" | no |

**ORD-00001 = `no_branch_coverage`. ORD-00002 = `unassigned`. Two different branches of this table (§13).**

---

## 5. No-Coverage Contract

### The contradiction, verbatim

**`BranchAssignmentEngine.php:221-224`** (code comment on `markNoCoverage`) and **`:27-31`** (class docblock):

> *Coverage resolution returned no matching branch. This is an Operations triage signal — NOT an Inventory problem. **order.status is intentionally left unchanged.***

**`BRANCH-ASSIGNMENT-ENGINE.md:62`** and certified scenario **C** (*"order status unchanged (NOT `awaiting_stock`)"* — **PASS**).

**`ProcessOrderWorkflow.php:29`** (class docblock) and `:97-98`:

> *`- No warehouse → routed to AwaitingStock.`*

### Which is authoritative?

**Neither can be shown authoritative from source.** Both are explicit, both are documented, both are certified, and they were authored by different tasks:

| | Branch engine | Process workflow |
|---|---|---|
| Authored by | TASK-BRANCH-ASSIGNMENT-ENGINE-001 | TASK-ORDERS-LIFECYCLE-ARCH-002 (V3) |
| Certified | scenario C **PASS** | V3 lifecycle |
| Scope claim | assignment is an *Operations* concern | reservation is an *Inventory* concern |

**Neither is wrong in isolation — and that is precisely the defect.** The branch engine truthfully leaves status alone; the workflow that runs immediately afterwards overwrites it. The branch engine's test still passes because it exercises the engine **without** the workflow that follows it in production. **This is an integrated-workflow failure with two green component certifications either side of it (§17).**

**→ Business decision required (§18 Q2).** The audit will not pick a winner; both contracts are owner-approved.

### Is `awaiting_stock` overloaded?

**Yes — proven, with three distinct causes collapsing into one status:**

| Cause | Writer | `reservation_failure_reason` | Recoverable automatically? |
|---|---|---|---|
| No warehouse assigned | `ProcessOrderWorkflow:98` / `ConfirmOrderWorkflow:90` | **"Warehouse Not Assigned"** | **No** (§11) |
| Insufficient stock | `ReserveOrderInventoryAction` shortage path | "Insufficient Inventory" | Yes, if warehouse ≠ NULL |
| Manual hold for stock | `MarkAwaitingStockWorkflow` | operator-supplied | No |

The **discriminator already exists and is populated** — `reservation_failure_reason`. It is simply not part of the status semantics, not surfaced in the UI, and not used by any retry logic.

**Is this a state-model problem? Yes.** One status carries three causes with different recovery paths, and the recovery mechanism (§9) keys on the status while the cause lives in a column it never reads. Whether the fix is a new status, a surfaced reason, or a triage queue is **§18 Q3** — the audit does not rename anything.

---

## 6. Reservation Contract

**Entry point:** `ReserveOrderInventoryAction::execute(Order $order): ReservationStatus`. Parity **verified** (`670ba67a…` host = container).

| Question | Answer (source) |
|---|---|
| **When does reservation occur?** | Three places: `ProcessOrderWorkflow:123`, `ConfirmOrderWorkflow:114`, and `MoveToPreparationWorkflow:78` (on-the-fly guard) |
| **Warehouse prerequisite?** | **Hard.** `:87-89` `if ($order->assigned_warehouse_id === null) throw new OrderWarehouseNotAssignedException` |
| **Eligible order statuses** | Governed by the calling workflow's guard, not the action. `ProcessOrderWorkflow:44-53` allows new, awaiting_payment, awaiting_stock, scheduled, on_hold, cancelled, in_progress |
| **If warehouse assignment failed?** | `ProcessOrderWorkflow`/`ConfirmOrderWorkflow` pre-empt at `:97`/`:89` and never call the action. **`MoveToPreparationWorkflow` does NOT pre-empt** — it would hit the throw at `:87`. See defect below |
| **If reservation fails?** | It does **not** throw for shortage (`:34-35`). Returns `AwaitingStock`; the *workflow* writes the status |
| **Does failure change order status?** | Yes — but by the workflow, never by the action |
| **Retryable?** | Yes in principle; in practice only via manual re-initiation, or the stock listener when a warehouse exists |
| **Idempotent?** | Yes — `:73-85` skips when status ∈ {Reserved, Transferred, Consumed, **Released**} |
| **Released on status change?** | Cancel → `released`. **`Released` is terminal** — `ReservationStatus::canTransitionTo()` returns `false` from `Released`, and it is also a skip-state, so a released order can never be re-reserved by this action |

### Reservation decision ladder (per line)

| # | Gate | Line | Outcome |
|---|---|---|---|
| 1 | `$available >= $requested` | `:125` | reserve physically → `full` |
| 2 | `$product?->can_manufacture && manufacturingIsExecutable($product)` | `:159` | lock available, commit rest → `manufacturing_committed` |
| 3 | `$product?->allow_negative_stock` | `:187` | lock available, commit rest → `negative_stock_committed` |
| 4 | else | shortage path | `Insufficient Inventory` → workflow writes `awaiting_stock` |

`manufacturingIsExecutable()` (`:66-69`) delegates entirely to `ManufacturingAvailabilityService` — *"the material-level `allow_negative_stock` rule is read from it, never recomputed here"* (ADR-027).

> **Defect — inconsistent null-warehouse handling.** `ProcessOrderWorkflow` and `ConfirmOrderWorkflow` guard `assigned_warehouse_id === null` before reserving. **`MoveToPreparationWorkflow:77-78` does not.** An `in_progress` order with a NULL warehouse reaching that workflow throws `OrderWarehouseNotAssignedException` instead of routing to `awaiting_stock`. Not currently reachable in `ecos_dev` (no order is `in_progress`), so **unproven at runtime** — reported as a source-level inconsistency, classification **C**.

---

## 7. Availability Contract

**Four distinct availability concepts exist. They are not interchangeable, and only two are ever compared.**

| # | Concept | Owner | Rule | Subject | Scope |
|---|---|---|---|---|---|
| 1 | `products.stock_status` | WooCommerce importer | mirrored inbound, never published outbound (E-3) | product | channel |
| 2 | `availability_state` | `AvailabilityState::fromAvailable()` | `null → untracked`, `<=0 → out_of_stock`, else `in_stock` | product | all warehouses |
| 3 | **manufacturing availability** | `ManufacturingAvailabilityService::evaluate()` | `available > 0 \|\| allow_negative_stock`, per component | **recipe components** | **company** |
| 4 | **reservable availability** | `ReserveOrderInventoryAction` → `InventoryItem::availableQty()` | on-hand − reserved at one warehouse | **the finished good** | **warehouse** |

The canonical clamp rule is flag-gated. **Runtime: `config('inventory_ledger.canonical_summary') = false`** → legacy `GREATEST(SUM(on_hand) - SUM(reserved), 0)` (sum-then-clamp) is live, not the canonical clamp-per-warehouse-then-sum.

### Which is reached for ORD-00001?

**None of them.** `ProcessOrderWorkflow:97` returns before `:123`. No availability calculation of any kind executes for this order.

### The Products Workspace "In Stock" badge

Concept **3** — *manufacturability of the recipe's components*, company-scoped. It is **correct** and it is **not** evidence of fulfilment readiness. Concept **2** for the same product is `untracked` (`inventory_items` is empty across the whole database — 0 rows). Concept **4**, the only one that governs fulfilment, is never evaluated.

**Product Workspace vs Order fulfilment vs Preparation material availability:**

- **Product Workspace** (3) asks *can this be produced?* → **yes**
- **Order fulfilment** (4) asks *can this be reserved from the assigned warehouse?* → **never asked**
- **Preparation material availability** — `MaterialDemandCalculator`, wave-scoped. **Not reached** (`preparation_wave_orders` and `wave_manufacturing_demand` are both empty) and **parity-broken** (§16), so excluded from evidence entirely.

They read the same table and the same company. **The divergence is subject and axis, not data source.**

---

## 8. Manufacturing Contract

FG-000001 `عسل الصال كيلو` — `019faef5-af41-7321-9f6b-546045947ace`:

| Attribute | Value |
|---|---|
| `product_type` | `finished_good` |
| **`can_manufacture`** | **0** |
| **`allow_negative_stock`** | **0** |
| Recipe | **BOM-00001, `is_active = 1`**, v1.0, yield 1.0000 |
| `missing_material_count` (cost summary) | 0 |
| Components | RM-000001 عسل الصال (1.0 /FG, 2 % waste, `allow_neg = 1`) · RM-000002 بطرمان كيلو (1.0 /FG, 0 % waste, `allow_neg = 1`) |
| RM availability | both 0 on-hand, 0 reserved, **0 inventory rows** |
| Manufacturing triggered by order? | **No** — `ReserveOrderInventoryAction:159` only *commits* against manufacturing eligibility; it creates no job |
| Can Preparation consume RMs directly? | Not for this order — preparation is never entered (§10) |
| Should FG exist as inventory? | **Unresolved from source — §18 Q5** |

### The contradiction inside the product

`can_manufacture = 0` while an **active recipe exists**. `Product.php:51` documents the flag as *"Has a recipe and may be produced."* The flag says no; the recipe says yes.

Consequence at `ReserveOrderInventoryAction:159`:

```php
if ($product?->can_manufacture && $this->manufacturingIsExecutable($product))
```

`manufacturingIsExecutable()` would return **`true`** (`ManufacturingAvailabilityService` returns `instock` — both components pass on `allow_negative_stock`). **The gate is closed solely by `can_manufacture = 0`.**

### Can the intended contract be resolved from source?

**No.** The sources are mutually consistent in *mechanism* and silent on *intent*:

- ADR-027 §16.3 names `ManufacturingAvailabilityService` the sole authority on material availability — satisfied.
- F4 / Option B (`ReserveOrderInventoryAction:37-46`) governs *when* the recipe matters — satisfied.
- Nothing states whether a finished good with an active recipe **must** carry `can_manufacture = 1`, nor whether the flag means "has a recipe" (contradicted by the data) or "production is authorised" (a meaning the platform never documents).

**→ STOP. Business decision §18 Q5.** Per the brief, `can_manufacture` and `allow_negative_stock` were not changed and no verdict is offered.

---

## 9. Negative Stock Contract

`Product.php:53` — *"When true, reservation commits immediately even with zero or negative stock; inventory goes negative at shipment."*

| Question | Answer | Source |
|---|---|---|
| Applies to finished goods? | **Yes** | `ReserveOrderInventoryAction:187` — commits full ordered qty, `negative_stock_committed` |
| Applies to raw materials? | **Yes** | `ManufacturingAvailabilityService:95` — component counts as available |
| Manufacturing on credit? | **Yes** — a recipe whose components are all credit-eligible evaluates `instock` at 0 stock | `:95`, `:118` |
| Preparation without physical material? | **Not gated at all** — the Entry Gate never inspects materials (§10) | `PreparationReleaseEngine:36-50` |
| Reservation and Preparation consistent? | **They do not overlap.** Reservation applies it to the FG; Preparation never applies it | — |

**Consistency verdict: consistent where both apply, but the platform has two independent applications of one flag — component-level (manufacturing) and product-level (reservation) — with no shared evaluator.** ORD-00001 sits exactly on the seam: components credit-eligible, product not.

**Not modified.** Whether FG-000001 *should* be credit-eligible is **§18 Q6**.

---

## 10. Preparation Entry Contract

**The Entry Gate is `PreparationReleaseEngine`** — *"The ONLY authority that decides whether an order may enter (or remain in) a Preparation Session. All attachment decisions must pass through here."* Parity not required (not a container-runtime claim).

```php
// PreparationReleaseEngine::ineligibilityReason() :36-50
if (! in_array($order->status->value, $eligibleStatuses, true))  return 'status_ineligible:'.$order->status->value;
if ($order->assigned_warehouse_id === null)                      return 'no_warehouse_assigned';
return null;
```

| Prerequisite | Enforced? |
|---|---|
| Eligible status | **Yes** — policy-driven |
| Warehouse assigned | **Yes** — hard |
| **Reservation** | **NO** |
| **Material availability** | **NO** |
| **Negative-stock exception** | **N/A** — nothing to except |

**Preparation eligibility is not an availability question at all.** A fully unreserved, zero-stock order in an eligible status with a warehouse **would be admitted**.

### Eligible statuses — two sources, one of them broken

| Source | Value | Valid V3? |
|---|---|---|
| `PreparationSessionPolicy::defaultEligibleStatuses()` | `['new', 'in_progress']` | **Yes** |
| `preparation_session_policies` table | **empty** → default applies | — |
| **`wave_engine_configurations.eligible_order_statuses`** (live row, warehouse `019f4e1c-2e1b-…`, active) | **`["confirmed"]`** | **NO — `confirmed` is not an `OrderStatus`** |

`WaveMembershipService:39` runs `->whereIn('status', $config->eligible_order_statuses)`. With `["confirmed"]` — a status the V3 enum removed — **the Wave Engine matches zero orders, permanently.** `wave:run-scheduler` runs every minute and can never attach anything. This is stale configuration data left behind by the V3 status rename.

### Answers

- **Can an order enter Preparation with no warehouse?** **No** — `'no_warehouse_assigned'`.
- **Can an order enter Preparation after being `awaiting_stock`?** **No** — `awaiting_stock` ∉ `['new','in_progress']` → `'status_ineligible:awaiting_stock'`. It must first be returned to an eligible status, which only a manual workflow does.
- **Is status automatically re-evaluated?** Only **destructively**: `OrderPreparationObserver` watches `status` / `assigned_warehouse_id` and **detaches** on ineligibility or warehouse change. There is **no constructive counterpart** — nothing re-attaches on becoming eligible except `WarehouseAssignedListener`, which is severed (§11).

Note `MoveToPreparationWorkflow` is misnamed: `name()` returns `ready_for_dispatch` and it transitions `in_progress → ready_for_dispatch`. It is the **exit** from preparation, not the entry.

---

## 11. Re-evaluation Matrix

Every mechanism searched: listeners, observers, subscribers, scheduled commands, queue jobs, workflows, engines.

### 11.1 The severed seam — `BranchAssigned` has no listeners

| Fact | Evidence |
|---|---|
| `BranchAssignmentEngine` dispatches `BranchAssigned` | `:104` (assign), `:139` (override) |
| **Listeners for `BranchAssigned`** | **ZERO** — repo-wide grep returns only the event class, the two dispatches, the docblock, and PHPStan cache artifacts |
| `WarehouseAssignedListener` subscribes to | **`WarehouseAssigned`** — `PreparationServiceProvider:116` |
| `WarehouseAssigned` is dispatched by | **only** `WarehouseAssignmentEngine:58` and `:96` — the **legacy** engine |
| Live order-creation path uses | `BranchAssignmentEngine` — `CreateManualOrderAction:42, :183` |

**`BranchAssignmentEngine` replaced `WarehouseAssignmentEngine`, but the auto-attach subscription was never migrated.** Successful coverage-driven assignment therefore never reaches Preparation. The legacy engine survives only behind `WarehouseAssignmentController` (manual HTTP).

### 11.2 Scenario matrix

| Scenario | Path | Verdict |
|---|---|---|
| **A. Warehouse unavailable at creation → becomes available later** | Nothing re-runs `BranchAssignmentEngine`. Manual `POST` → `WarehouseAssignmentController::assignWarehouse` → legacy engine → `WarehouseAssigned` → `WarehouseAssignedListener` → gate check. **But** the order is still `awaiting_stock`, which fails `PreparationReleaseEngine`, so the attach is skipped; and nothing re-runs `ProcessOrderWorkflow`, so the status never clears. | **NO PATH (automatic). Manual assignment alone is insufficient — it assigns a warehouse and the order remains stuck.** Full recovery needs manual assignment **plus** manual re-initiation. |
| **B. Stock unavailable → becomes available later** | `ReceiveStockAction:111` → `InventoryStockReceived` → two listeners. (i) `RetryReservationOnStockAvailableListener:38` filters `->where('assigned_warehouse_id', $event->warehouseId)` — **`NULL = uuid` is never true**, so NULL-warehouse orders are invisible. (ii) `StockAddedListener` sums **`on_hand_quantity`**, a column that does not exist (real: `on_hand_qty`), inside `try/catch(Throwable)` that only logs → **silent failure**. | **Automatic *only* for orders that already have a warehouse. NO PATH for ORD-00001/2. Preparation-side resolution is dead at runtime.** |
| **C. Manufacturing completes → FG available** | `ManufacturingJobCompletedListener` updates `preparation_production_requirements.status = ready`. **It does not touch orders, reservations, or order status.** No order-facing listener for manufacturing completion exists. | **NO PATH to order re-evaluation.** |
| **D. Reservation fails → becomes possible later** | Same as B — `RetryReservationOnStockAvailableListener` is the only mechanism, gated on a non-NULL warehouse. If reservation reached `released`, it is terminal in both `canTransitionTo()` and the action's skip-list. | **Automatic only with a warehouse; NO PATH if released.** |

### 11.3 Scheduled commands (`routes/console.php`) — none re-evaluate assignment

| Command | Cadence | Relevance |
|---|---|---|
| `orders:activate-scheduled` | daily 00:05 | Scheduled → InProgress by date only |
| `preparation:create-daily-sessions` | daily 06:00 | creates sessions; does not attach |
| `preparation:freeze-sessions` | every minute | freezes |
| `wave:run-scheduler` | every minute | runs Wave Engine — **inert**, config filters `"confirmed"` (§10) |
| `marketing:provider:health-check` ×3 | — | unrelated |
| `inventory:canonical-diff` | manual, read-only | unrelated |

**No scheduled job re-attempts warehouse assignment or reservation.**

---

## 12. Runtime Trace

### 12.1 CURRENT ACTUAL PATH — ORD-00001

```
order created (dashboard, 2026-08-07 02:43:48)
  ↓ CreateManualOrderAction:183 → BranchAssignmentEngine::assign()
  ↓   governorate = 'القاهرة'  (city 'مدينة نصر' NOT read; zone = '' → null)
  ↓ CoverageResolutionService:38  LOWER(name) = LOWER('القاهرة')  → NO MATCH
  ↓   (name_ar = 'القاهرة' exists on the Cairo row and is never consulted)
  ↓ :43 return collect()   → candidates empty
  ↓ markNoCoverage()  → source=no_branch_coverage, reason='No Branch Covers Destination'
  ↓                     assigned_warehouse_id stays NULL, STATUS UNCHANGED  ← engine contract honoured
  ↓ CreateManualOrderAction:188 → ProcessOrderWorkflow (status is still 'new')
  ↓ ProcessOrderWorkflow:97  assigned_warehouse_id === null  → TRUE
  ↓ :98  status = awaiting_stock          ← engine contract overwritten
  ↓ :104 reservation_status = awaiting_stock, reason 'Warehouse Not Assigned'
  ↓ :115 return  ─── EXIT ───
       ReserveOrderInventoryAction  : NEVER CALLED
       availability                 : NEVER EVALUATED
       BOM explosion                : NEVER EXECUTED
       MaterialDemandCalculator     : NEVER REACHED (and parity-broken anyway)
       PreparationReleaseEngine     : NEVER CONSULTED
       BranchAssigned event          : dispatched?  NO — markNoCoverage() does not dispatch
```

Re-initiated 2026-08-12 03:37:39 → identical path, identical outcome.

Runtime confirmation (parity-verified files, real container, real order):

```
resolve('القاهرة','مدينة نصر') → 0 coverage areas
resolve('Cairo', null)         → 1 → Cairo HQ → Main Warehouse (order's own company)
```

### 12.2 EXPECTED PATH AFTER EACH POTENTIAL REPAIR

| Repair applied | ORD-00001 outcome | ORD-00002 outcome |
|---|---|---|
| **R1** — coverage matches `name_ar` | warehouse = Main Warehouse → reservation runs → gate 1 fails (0 < 2), gate 2 fails (`can_manufacture=0`), gate 3 fails (`allow_neg=0`) → **`awaiting_stock`, reason changes to "Insufficient Inventory"**. Still stuck; now genuinely a stock verdict | **UNCHANGED** — `governorate` is NULL, so `markUnresolved()` fires before any lookup |
| **R1 + stock received for FG-000001 in Main Warehouse** | `RetryReservationOnStockAvailableListener` now matches (warehouse ≠ NULL) → re-reserves → `in_progress` → eligible for preparation **by the session policy**; still invisible to the Wave Engine (`"confirmed"`) | unchanged |
| **R1 + R2** (`BranchAssigned` → auto-attach wired) | on assignment the order would be gate-checked; at that instant status is still `new` (assignment precedes ProcessOrderWorkflow) → **eligible** → attached to today's session, if one exists | unchanged |
| **R1 + R4** (`wave_engine_configurations` corrected to V3 statuses) | Wave Engine can attach for the first time | unchanged |
| **R3 only** (no-coverage contract decision) | if "status unchanged" wins, ORD-00001 stays `new` and appears in Operations triage instead of masquerading as a stock problem | **same benefit** — ORD-00002 also stays `new` |
| **Nothing** | permanently stuck | permanently stuck |

**No repair in isolation releases either order.**

---

## 13. All-Order Impact

**Verified count: 2 orders in `ecos_dev`, both non-deleted, both `awaiting_stock`, both NULL warehouse.** Both belong to company `019f4e1c-2d1e-719d-873c-75779ab67251`.

| | ORD-00001 | ORD-00002 |
|---|---|---|
| created | 2026-08-07 02:43:48 | 2026-08-07 02:45:46 |
| `status` / `reservation_status` | `awaiting_stock` / `awaiting_stock` | `awaiting_stock` / `awaiting_stock` |
| `reservation_failure_reason` | Warehouse Not Assigned | Warehouse Not Assigned |
| `governorate` | `القاهرة` | **NULL** |
| `city` | `مدينة نصر` | NULL |
| **`warehouse_assignment_source`** | **`no_branch_coverage`** | **`unassigned`** |
| `warehouse_assignment_failure_reason` | "No Branch Covers Destination" | **NULL** |
| Upstream cause | **locale mismatch** — `CoverageResolutionService:38` | **missing address data** — `BranchAssignmentEngine:60-64` `markUnresolved()` |
| Fixed by the locale repair? | **Yes (partially — §12.2)** | **NO** |

> **Correction to the prior report.** TASK-ORDER-AWAITING-STOCK-DIAGNOSTIC-001 stated the same root cause applied to both orders. That is accurate only for the **shared downstream path** (`ProcessOrderWorkflow:97`). The **upstream causes differ**: ORD-00001 takes `markNoCoverage()`, ORD-00002 takes `markUnresolved()` because its `governorate` is NULL and the engine returns at `:60-64` before coverage resolution is ever attempted. Any repair plan scoped to the locale defect alone leaves 50 % of the current order population untouched.

Two distinct upstream causes converge on one status through one shared guard — the clearest evidence that `awaiting_stock` is overloaded (§5).

---

## 14. State / Decision Diagram

Real ECOS states, real guards, real file:line. `╳` marks a severed or inert seam.

```
                          ORDER CREATED  (status = new)
                                  │
                                  ▼
                 CreateManualOrderAction:183 → BranchAssignmentEngine::assign()
                                  │
                    ┌─────────────┴──────────────┐
        governorate == ''                   governorate present
                    │                            │
                    ▼                            ▼
          markUnresolved()            CoverageResolutionService::resolve()
      source = 'unassigned'            LOWER(name) = LOWER(governorate)   ← :38  name_ar NEVER read
      failure_reason = NULL                       │
      STATUS UNCHANGED                ┌───────────┴────────────┐
            ◀── ORD-00002 ──▶      no match                  match
                    │                  │                       │
                    │                  ▼                       ▼
                    │        markNoCoverage()        filter: branch.company_id == order.company
                    │   source='no_branch_coverage'  branch active?  →  BranchWarehouseResolver
                    │   STATUS UNCHANGED                        │
                    │        ◀── ORD-00001 ──▶        assigned_branch_id + assigned_warehouse_id
                    │                  │              source = 'branch_coverage'
                    │                  │                        │
                    │                  │                        ▼
                    │                  │              BranchAssigned::dispatch()  ╳ ZERO LISTENERS
                    │                  │              (WarehouseAssignedListener waits on
                    │                  │               WarehouseAssigned — legacy engine only)
                    └──────────┬───────┘                        │
                               ▼                                ▼
              ══════════ CreateManualOrderAction:188 → ProcessOrderWorkflow ══════════
                               │
                    ProcessOrderWorkflow:97   assigned_warehouse_id === null ?
                               │
            ┌──────── YES ─────┴───── NO ────────┐
            ▼                                    ▼
   :98  status = awaiting_stock        :123 ReserveOrderInventoryAction::execute()
   :104 reason 'Warehouse Not Assigned'          │
   :115 RETURN — reservation, availability,      │  per line:
        BOM, MaterialDemand ALL SKIPPED          │
            │                          ┌─────────┴──────────────────────────────┐
            │                     :125 available >= requested ?  ── YES ──▶ reserved (full)
            │                          │ NO
            │                     :159 can_manufacture && manufacturingIsExecutable ?
            │                          │            ── YES ──▶ manufacturing_committed
            │                          │ NO  ← FG-000001 (can_manufacture = 0)
            │                     :187 allow_negative_stock ?
            │                          │            ── YES ──▶ negative_stock_committed
            │                          │ NO  ← FG-000001 (allow_negative_stock = 0)
            │                          ▼
            │                    shortage path → 'Insufficient Inventory'
            │                          │
            ▼                          ▼
      ┌────────────────────────────────────────┐        all lines reserved / partial
      │  status = awaiting_stock               │                    │
      │  (THREE causes, ONE status — §5)       │                    ▼
      └────────────────────────────────────────┘          status = in_progress
            │                                                       │
            │  RetryReservationOnStockAvailableListener:38           ▼
            │  WHERE assigned_warehouse_id = <uuid>        PreparationReleaseEngine  ← THE ENTRY GATE
            │  ╳ NULL never matches → invisible forever    :41 status ∈ ['new','in_progress'] ?
            │                                              :45 assigned_warehouse_id != null ?
            │  StockAddedListener  ╳ queries 'on_hand_quantity'      (NO reservation prerequisite,
            │                        (real column: on_hand_qty)       NO material prerequisite)
            │                        silent try/catch                          │
            │                                                    ┌─────────────┴────────────┐
            │                                                 ELIGIBLE                 INELIGIBLE
            │                                                    │                          │
            │                                                    ▼                          ▼
            │                                        DailyPreparationSessionManager   detach / skip
            │                                            ::attachOrder()          ('status_ineligible'
            │                                                    │                'no_warehouse_assigned')
            │                                                    ▼
            │                                        WaveMembershipService:39
            │                                        whereIn('status', config)
            │                                        ╳ live config = ["confirmed"]
            │                                          — not a V3 status → 0 rows, always
            │                                                    │
            │                                                    ▼
            │                                          PreparationWave / Wave Item
            │                                          MaterialDemandCalculator
            │                                          ⛔ PARITY BROKEN (§16)
            │                                                    │
            │                                                    ▼
            │                                    MoveToPreparationWorkflow (`ready_for_dispatch`)
            │                                    :39 guard status == in_progress
            │                                    :77 auto-reserve if missing  ╳ no null-warehouse guard
            │                                                    │
            ▼                                                    ▼
     ⟨ NO AUTOMATIC EXIT ⟩                              status = ready_for_dispatch
     manual only: ResumeOrderWorkflow /                          │
     RevertToConfirmedWorkflow / re-initiate                     ▼
                                                        out_for_delivery → delivered
```

---

## 15. Root Causes

Six independent defects on one chain. Each is separately sufficient to block fulfilment.

| # | Root cause | Location | Proven by |
|---|---|---|---|
| **RC-1** | Coverage resolution matches English `name` only; orders store Arabic | `CoverageResolutionService.php:38` (and `:48` for zones) | Runtime: `resolve('القاهرة')`→0, `resolve('Cairo')`→1 |
| **RC-2** | `BranchAssigned` has **zero listeners**; auto-attach subscribes to `WarehouseAssigned` from the replaced engine | dispatch `BranchAssignmentEngine:104,:139` vs `PreparationServiceProvider:116` | Repo-wide grep |
| **RC-3** | Retry listener excludes NULL-warehouse orders; nothing re-runs assignment | `RetryReservationOnStockAvailableListener.php:38` | SQL semantics + single-caller grep |
| **RC-4** | Live wave config uses `"confirmed"`, absent from the V3 enum → Wave Engine inert | `wave_engine_configurations.eligible_order_statuses` vs `OrderStatus` | DB row + enum |
| **RC-5** | `StockAddedListener` reads non-existent column `on_hand_quantity`; failure swallowed | `StockAddedListener.php` (~`:44`) vs `SHOW COLUMNS` | Schema |
| **RC-6** | Two certified contracts contradict on no-coverage status | `BranchAssignmentEngine:221-224` + `BRANCH-ASSIGNMENT-ENGINE.md:62` vs `ProcessOrderWorkflow:29,:97` | Both docs |

**Secondary conditions (NOT classified as defects — business decisions):** `can_manufacture = 0` beside an active BOM; `allow_negative_stock = 0` on the FG while both components are `1`; `inventory_items` empty database-wide; ORD-00002's missing `governorate`.

---

## 16. Repair Classification

| # | Item | Class | Rationale |
|---|---|---|---|
| RC-1 | Coverage `name_ar` matching | **A — one-file defect** | Single predicate, `CoverageResolutionService:38`(+`:48`). *If* the decision is normalised references instead (§18 Q1), it escalates to **E**. |
| RC-2 | `BranchAssigned` → auto-attach seam | **E — integration repair** | Event/listener wiring across Preparation + Orders; needs Q4 answered |
| RC-3 | Re-evaluation for NULL-warehouse orders | **D — state-machine repair** | Requires a re-assignment trigger that does not exist; depends on Q4/Q7 |
| RC-4 | Wave config `"confirmed"` | **F — business decision, then data fix** | Which V3 statuses are wave-eligible is a policy question (Q8); the write itself is trivial |
| RC-5 | `StockAddedListener` column name | **A — one-file defect** | One identifier; but silent-catch masking is **C** if error handling is revisited |
| RC-6 | No-coverage status contract | **F — business decision required** | Two owner-approved contracts; cannot be resolved from source (§5) |
| — | `MoveToPreparationWorkflow` null-warehouse guard | **C — workflow repair** | Source inconsistency; unproven at runtime |
| — | `ReservationStatus::Released` terminal + skip-state | **G — already correct** | Deliberate; documented; no action |
| — | `ManufacturingAvailabilityService` credit rule | **G — already correct** | Behaves exactly as ADR-027 specifies |
| — | `AvailabilityState` / `ProductResource` split | **G — already correct** | Correctly distinguishes channel mirror from ERP answer |
| — | `PreparationReleaseEngine` sole-gate design | **G — already correct** | Single authority, policy-driven, no hardcoded statuses |
| — | `can_manufacture` / `allow_negative_stock` on FG-000001 | **F — business decision** | Q5, Q6 |
| — | ORD-00002 missing `governorate` | **F — business decision** | Q7 — data repair vs. validation vs. triage |
| — | `MaterialDemandCalculator` container parity | **not a repair — deployment action** | `docker cp` |

**Nothing in this table was implemented.**

---

## 17. Certification Impact

The audit distinguishes **component correctness** (does the unit honour its own contract?) from **integrated workflow correctness** (does the chain deliver the business outcome?).

| Certification | Component | Integrated | Verdict |
|---|---|---|---|
| **Orders** | Holds — `OrderStatus`, guards, and workflows behave as specified | **BREACHED** — `ProcessOrderWorkflow` overwrites a status the Branch engine contractually preserves (RC-6) | **Do not revoke.** Component certification stands; the integrated Orders↔Assignment seam is uncertified |
| **Reservation** | **Holds** — `ReserveOrderInventoryAction` correct at every gate; F4/Option B ladder honoured; idempotency correct | **Not exercised** for either live order | **No impact.** No evidence against it |
| **Preparation** | **Holds** — `PreparationReleaseEngine` is a clean sole authority | **BREACHED** — inbound seam dead (RC-2), wave config inert (RC-4), stock listener broken (RC-5) | **Do not revoke** component cert; integrated preparation entry is **unproven end-to-end** |
| **MaterialDemandCalculator** | **CANNOT BE ASSESSED IN THIS ENVIRONMENT** | — | **Parity broken (§16).** Certification is neither confirmed nor revoked; the certified code is not running. Re-establish parity before any claim |
| **Preparation Entry Gate** | **Holds** — correct, single-authority, policy-driven | Never reached by either live order | **No impact** |
| **Future Shipping** | — | **AT RISK** | Shipping depends on `ready_for_dispatch`, reachable only via `MoveToPreparationWorkflow` from `in_progress`. No order in this environment has ever reached `in_progress`. **The path into Shipping is entirely unexercised** — do not certify Shipping against this environment until at least one order completes new → in_progress → ready_for_dispatch |
| **Branch Assignment Engine** (scenario C) | **Holds in isolation** | **Misleading** — passes because the test exercises the engine without the workflow that immediately follows in production | **Do not revoke.** Flag the test as component-scope-only; it cannot detect RC-6 |
| **F4 / Option B** | **Holds** — parity verified (`670ba67a…`, `14701fd3…`) | — | **No impact** |
| **Tenant isolation / IAM** | **Holds** — company scoping correct at every layer inspected | — | **No impact** |

**Summary: no component certification is revoked. Every failure found lives in the seams between certified components — which is exactly the class of defect component certification cannot detect.**

---

## 18. Open Business Decisions

Only questions unresolvable from source, ADR, certified contract, or runtime evidence.

| # | Decision | Why it cannot be answered from source | Blocks |
|---|---|---|---|
| **Q1** | **What should coverage compare?** `name_ar` as an additional match, or a normalised reference (an FK on the order — `logistics_city_id` is present and unused)? | Both are viable; the schema supports either. Choosing string matching accepts a permanent locale-collision surface; choosing normalisation is a schema/ingestion change. Nothing states the intended direction | RC-1, class A vs E |
| **Q2** | **Which no-coverage contract is authoritative** — `BranchAssignmentEngine` ("status unchanged, Operations triage") or `ProcessOrderWorkflow` ("route to AwaitingStock")? | Both explicit, both certified, authored by different tasks (§5) | RC-6, and the meaning of every no-coverage order |
| **Q3** | **May `awaiting_stock` represent warehouse-assignment failure, or is it exclusively inventory/material?** If exclusive, a distinct state or surfaced reason is required | Three causes already collapse into it; `reservation_failure_reason` discriminates but no rule says whether that is sufficient | State model; UI labelling |
| **Q4** | **What re-triggers warehouse assignment** — a re-assignment action, a coverage-change listener, a scheduled sweep of NULL-warehouse orders, or operator-only? | No mechanism exists; nothing states which was intended | RC-2, RC-3 |
| **Q5** | **Is `can_manufacture = 0` correct for FG-000001**, which has an active BOM-00001? Does the flag mean "has a recipe" (contradicted by data) or "production authorised" (undocumented)? | Sources define the mechanism, never the intent (§8) | Whether the order can ever be fulfilled by production |
| **Q6** | **Is `allow_negative_stock = 0` correct for FG-000001**, while both its components are `1`? | The rule is consistently implemented; its *application* to this product is a commercial choice | Whether the order can be fulfilled on credit |
| **Q7** | **How are the 2 stuck orders released**, and what happens to ORD-00002's NULL `governorate` — data repair, ingestion validation, or Operations triage? | Requires mutating live order data; explicitly out of scope | Both live orders |
| **Q8** | **Which V3 statuses are wave-eligible?** The live config says `"confirmed"`, which no longer exists; the session policy default says `['new','in_progress']` | Two eligibility sources disagree and only one is valid; the correct wave policy is not stated anywhere | RC-4 |

---

## 19. Recommended Implementation Sequence

Ordered so that each step is verifiable before the next, and no step depends on an unapproved decision. **Nothing below has been started.**

**Phase 0 — Unblock evidence (no business decision needed)**
1. `docker cp` the certified `MaterialDemandCalculator` into `ecos-dev-app`; re-verify `ce69612a…` on both sides. Until then no demand/preparation runtime claim is admissible.

**Phase 1 — Decisions (blocking; no code)**
2. Obtain Q2 (no-coverage contract) and Q3 (status semantics). **These two determine the shape of everything after.**
3. Obtain Q1 (comparison basis), Q4 (re-trigger), Q8 (wave statuses).
4. Obtain Q5 / Q6 (product flags) and Q7 (stuck-order disposition).

**Phase 2 — Independent defects (no decision needed; safe in any order)**
5. **RC-5** — `on_hand_quantity` → `on_hand_qty` in `StockAddedListener` (class A). Independent of every decision.
6. **RC-2** — reconnect the assignment → preparation seam per Q4 (class E).

**Phase 3 — The locale defect, once Q1 is answered**
7. **RC-1** — implement the approved comparison basis (class A or E). **Must not ship alone** — on its own it only changes ORD-00001's failure reason and leaves ORD-00002 untouched (§12.2, §13).

**Phase 4 — State model, once Q2/Q3 are answered**
8. **RC-6** — retire the losing contract across `ProcessOrderWorkflow` **and** `ConfirmOrderWorkflow` (identical guards), plus the Operations triage surface (class D/F).
9. **RC-3** — re-evaluation path per Q4 (class D). **After** RC-6, so retry does not loop against an unchanged assignment.
10. Align `MoveToPreparationWorkflow`'s null-warehouse handling with the ruling (class C).

**Phase 5 — Configuration and data**
11. **RC-4** — correct `wave_engine_configurations.eligible_order_statuses` per Q8.
12. Per Q5/Q6/Q7 — product flags and stuck-order disposition, as approved.

---

## 20. Verification Plan

Each item names its evidence and its parity precondition.

| # | Check | Method | Pass criterion | Parity required |
|---|---|---|---|---|
| V1 | Container parity restored | `md5sum` host vs container | both `ce69612a…` | — |
| V2 | Coverage resolves Arabic | `CoverageResolutionService::resolve('القاهرة', null)` in container | ≥1 area → Cairo HQ → Main Warehouse | resolver |
| V3 | Coverage still resolves English | `resolve('Cairo', null)` | unchanged, 1 area | resolver |
| V4 | No cross-language collision | resolve each of the 27 `name` and `name_ar` values | each yields exactly one governorate | resolver |
| V5 | Assignment on a **new** order | create a test order, Cairo, Arabic — **new row, never mutate ORD-00001/2** | `assigned_warehouse_id` set, source `branch_coverage` | engine |
| V6 | Preparation auto-attach fires | after V5, assert session attachment | order attached, or documented gate reason | listener + gate |
| V7 | No-coverage honours the ruling | order with an uncovered governorate | status matches the Q2 ruling exactly | workflow |
| V8 | ORD-00002 class | order with NULL governorate | source `unassigned`; behaviour matches the Q7 ruling | engine |
| V9 | Reservation ladder intact | order for a product with stock | `reserved`; F4/Option B gates unchanged | reservation |
| V10 | Retry on stock arrival | receive stock for a warehouse-assigned awaiting_stock order | order re-processed; `StockAddedListener` logs **no** error | listeners |
| V11 | Wave attaches | after RC-4, run `wave:run-scheduler` | eligible orders attach | wave engine |
| V12 | Demand contract | `on_hand 15 / reserved 8 / required 10` | `available 7`, `missing 3` | **V1 mandatory** |
| V13 | Entry Gate unchanged | `PreparationReleaseEngine` unit behaviour | still status + warehouse only; no new prerequisite | gate |
| V14 | Certified components unchanged | `md5sum` + targeted suites for MaterialDemandCalculator, Reservation, Entry Gate, F4, IAM | byte-identical or deliberately re-certified | all |
| V15 | End-to-end | one order: new → in_progress → ready_for_dispatch | full traverse — **never yet achieved in this environment** | all |

**V15 is the real acceptance test.** No order in `ecos_dev` has ever reached `in_progress`, so every downstream contract — preparation, wave, dispatch, shipping — remains unexercised regardless of which repairs land.

---

## Compliance Statement

No production code, migration, test expectation, schema, status, order row, or reservation row was modified. All database access was `SELECT` / `SHOW`. The single `tinker` invocation called `CoverageResolutionService::resolve()`, which performs read-only queries. **`MaterialDemandCalculator`, reservation services, the Preparation Entry Gate, F4/Option B, tenant isolation, IAM, and preparation eligibility logic were read only.** Certified-baseline parity was checked before any runtime claim and is reported as **BROKEN** for `MaterialDemandCalculator`; no container demand output was used as certification evidence.
