# Implementation Progress — Manufacturing & Procurement

Architecture frozen: 2026-06-29 (ARCHITECTURE-FREEZE.md)
Implementation began: 2026-06-29

---

## PKG-01 — Product Foundation

**Status:** ✅ Completed
**Date:** 2026-06-29
**Task:** TASK-MFG-IMP-001
**Architect Decision:** All 4 fields from MFG-M001 (partial)

---

### Migration Summary

| Migration File | Table | Operation | Columns Added |
|----------------|-------|-----------|---------------|
| `2026_06_29_000001_add_manufacturing_fields_to_products_table.php` | `products` | ALTER | `cost_source`, `can_manufacture`, `can_disassemble`, `allow_negative_stock` |

**Column Specifications:**

| Column | Type | Default | Nullable | Notes |
|--------|------|---------|----------|-------|
| `cost_source` | VARCHAR(20) | `'purchase'` | No | CostSource enum: purchase \| recipe \| hybrid |
| `can_manufacture` | BOOLEAN | `false` | No | Has a recipe; may be produced |
| `can_disassemble` | BOOLEAN | `false` | No | May be disassembled back into components |
| `allow_negative_stock` | BOOLEAN | `false` | No | Evaluated on raw materials at consumption time (RC-2) |

**Rollback:** `dropColumn(['cost_source', 'can_manufacture', 'can_disassemble', 'allow_negative_stock'])`

---

### Files Created

| File | Purpose |
|------|---------|
| `backend/Modules/Inventory/Products/Infrastructure/Database/Migrations/2026_06_29_000001_add_manufacturing_fields_to_products_table.php` | Adds 4 manufacturing-capability columns to `products` |
| `backend/Modules/Inventory/Products/Domain/Enums/CostSource.php` | Backed enum: `Purchase`, `Recipe`, `Hybrid` with `label()` and `isManufacturingRelevant()` |
| `backend/tests/Feature/Inventory/ProductManufacturingFieldsTest.php` | 26 tests covering migration, defaults, enum casting, capability flags, factory states, backward compatibility |

---

### Files Modified

| File | Change |
|------|--------|
| `backend/Modules/Inventory/Products/Domain/Models/Product.php` | Added `cost_source`, `can_manufacture`, `can_disassemble`, `allow_negative_stock` to `$fillable` and `casts()`. Updated `@property` docblock. Imported `CostSource` and `ProductStockStatus` explicitly. |
| `backend/Modules/Inventory/Products/Application/DTO/ProductDTO.php` | Added `cost_source` (required, default `'purchase'`), `can_manufacture`, `can_disassemble`, `allow_negative_stock` as constructor-promoted properties. Updated `fromArray()`. |
| `backend/Modules/Inventory/Products/Presentation/Http/Requests/StoreProductRequest.php` | Added `cost_source` (required, `Rule::enum(CostSource::class)`), `can_manufacture`, `can_disassemble`, `allow_negative_stock` (all boolean). |
| `backend/Modules/Inventory/Products/Presentation/Http/Requests/UpdateProductRequest.php` | Same validation additions as StoreProductRequest. |
| `backend/Modules/Inventory/Products/Presentation/Http/Resources/ProductResource.php` | Added `cost_source`, `can_manufacture`, `can_disassemble`, `allow_negative_stock` to API response. |
| `backend/Modules/Inventory/Products/Infrastructure/Database/Factories/ProductFactory.php` | Added new fields to `definition()`. Added factory states: `manufacturable()`, `hybrid()`, `allowsNegativeStock()`. |

---

### Architecture Alignment

| Architecture Decision | Implementation |
|----------------------|----------------|
| RC-2: `allow_negative_stock` on raw materials only | Column added to `products` — no enforcement here; Decision Engine evaluates at consumption time |
| RC-3: FIFO cost in consumptions | Deferred — no consumptions in PKG-01 |
| RC-5: Hybrid cost as strategy | `cost_source = 'hybrid'` flag on Product; cost history logic deferred to PKG-10 |
| MFG-M001: Product manufacturing fields | ✅ `can_manufacture`, `can_disassemble`, `allow_negative_stock`, `cost_source` added |

---

### Risk Assessment

| Risk | Severity | Mitigation |
|------|----------|-----------|
| Existing products default `cost_source = 'purchase'` | Low | Correct default — all pre-manufacturing products are purchase-costed |
| `cost_source` has no DB-level CHECK constraint | Low | `Rule::enum(CostSource::class)` enforces valid values at API boundary; enum cast prevents invalid values via model |
| New required `cost_source` field in API | Medium | Existing API clients must now supply `cost_source`. Default `'purchase'` in DTO means factory/test code without explicit value continues to work. |

---

### Compatibility Notes

- **Existing tests:** All existing Product factory usages continue to work. New columns have `NOT NULL DEFAULT` values — no existing row creation breaks.
- **API:** `cost_source` is now required in `StoreProductRequest` and `UpdateProductRequest`. Clients not supplying it will receive a 422 validation error. Default `'purchase'` applies when creating via factory (tests).
- **ProductDTO:** `cost_source` has a default of `'purchase'` in the constructor. `fromArray()` falls back to `'purchase'` if key is absent, preserving backward compatibility in programmatic usage.
- **ProductResource:** New fields are always present in responses (not `whenLoaded`). No breaking change for clients that ignore extra fields.
- **product_type:** Remains unchanged. Still accepts `finished_good` | `raw_material`. No business logic tied to it in PKG-01.

---

---

## PKG-02A — Recipe Foundation

**Status:** ✅ Completed
**Date:** 2026-06-29
**Task:** TASK-MFG-IMP-002

---

### Migration Summary

| Migration File | Table | Operation | Details |
|----------------|-------|-----------|---------|
| `2026_06_29_000002_add_bom_version_number_to_bills_of_materials.php` | `bills_of_materials` | ALTER | Adds `bom_version_number UNSIGNED INT DEFAULT 1`, index on (product_id, bom_version_number). Backfills existing rows to 1. |

---

### BOM Layer Refactoring (Phase 1)

| Change | Details |
|--------|---------|
| `waste_percentage` removed from business layer | Removed from `BillOfMaterialLine.$fillable` + `casts()`, `BomLineDTO`, `BomResource`, `StoreBomRequest`, `UpdateBomRequest`, `CreateBomAction`, `UpdateBomAction`, `BomSeeder` |
| `waste_percentage` column preserved in DB | Backward-compatible: existing data unaffected, column defaults to 0 |
| `bom_version_number` added to BOM layer | `BillOfMaterial.$fillable` + `casts()`, `BomResource`, `CreateBomAction` passes it via repository, `EloquentBomRepository.nextVersionNumber()` |
| `BomRepositoryInterface` extended | Added `nextVersionNumber(string $productId): int` |

---

### Files Created (7)

| File | Purpose |
|------|---------|
| `2026_06_29_000002_add_bom_version_number_to_bills_of_materials.php` | Adds versioning integer column |
| `Recipe.php` | Recipe aggregate — domain language over `bills_of_materials` table |
| `RecipeLine.php` | Component line — domain language over `bill_of_material_lines` table (no waste_percentage) |
| `RecipeRepositoryInterface.php` | Contract: findById, findActiveByProduct, findAllByProduct, create, activate, nextVersionNumber, nextBomNumber |
| `EloquentRecipeRepository.php` | Eloquent implementation; manages active-version deactivation on create/activate |
| `RecipeNotFoundException.php` | Domain exception |
| `tests/Feature/Manufacturing/RecipeFoundationTest.php` | 22 tests |

---

### Files Modified (12)

| File | Change |
|------|--------|
| `BillOfMaterial.php` | Added `bom_version_number` to fillable + casts |
| `BillOfMaterialLine.php` | Removed `waste_percentage` from fillable + casts |
| `BomLineDTO.php` | Removed `waste_percentage` property |
| `BomResource.php` | Removed `waste_percentage` from line output; added `bom_version_number` to header |
| `StoreBomRequest.php` | Removed `waste_percentage` rule; added `Rule::notIn([$product_id])` on component |
| `UpdateBomRequest.php` | Same as StoreBomRequest |
| `CreateBomAction.php` | Removed `waste_percentage` from line map; added `bom_version_number` via `nextVersionNumber()` |
| `UpdateBomAction.php` | Removed `waste_percentage` from line map |
| `BomRepositoryInterface.php` | Added `nextVersionNumber(string $productId): int` |
| `EloquentBomRepository.php` | Added `nextVersionNumber()` implementation |
| `BomServiceProvider.php` | Registered `RecipeRepositoryInterface → EloquentRecipeRepository` |
| `BomSeeder.php` | Removed `waste_percentage`; added `bom_version_number: 1` |
| `Product.php` | Added `recipes()` HasMany, `activeRecipe()` HasOne with `ofMany`, `hasRecipe()` method; imported Recipe |

---

### Architecture Alignment

| Architecture Decision | Implementation |
|----------------------|----------------|
| BOM = persistence layer, Recipe = domain language | Recipe + RecipeLine model same tables as BOM. No parallel schema. |
| Copy-on-write versioning | `bom_version_number` integer added. Sequential numbering via `nextVersionNumber()`. Trigger logic (when in-use BOM is modified) deferred to PKG-02B. |
| No percentage quantities | `waste_percentage` removed from all new Recipe operations. DB column stays for backward compat. |
| Unit comes from Product | No `unit_id` on `RecipeLine` — unit derived from `component.unit` relationship. |
| One active version per product | `EloquentRecipeRepository.create()` and `activate()` call `deactivateOthers()`. |
| Component ≠ output product | `Rule::notIn([$product_id])` on `lines.*.raw_material_id` in both BOM requests. |

---

### Risk Assessment

| Risk | Severity | Mitigation |
|------|----------|-----------|
| Recipe and BillOfMaterial share the same table | Low | Intentional DDD design. Both models are read/write compatible. No ORM conflicts. |
| `waste_percentage` removed from BOM API response | Medium | Any frontend currently reading `lines[].waste_percentage` will get `undefined`. All values were 0 anyway since it was never enforced. |
| `bom_version_number` required for new BOMs | Low | `EloquentBomRepository.create()` sets it via `nextVersionNumber()`. Default in DB is 1 — old rows safe. |

---

### Compatibility Notes

- **BOM CRUD API**: existing `/api/boms` endpoints remain fully functional. BOM models untouched except for `bom_version_number` addition.
- **`waste_percentage` submissions**: silently ignored. Callers sending it will not receive a 422 — the field is not validated, just dropped.
- **BOM rows created before this migration**: all set to `bom_version_number = 1` by the backfill.

---

## PKG-02B — Recipe Resolver

**Status:** ✅ Completed
**Date:** 2026-06-29
**Task:** TASK-MFG-IMP-002B

---

### Purpose

A **read-only domain service** that locates the active Recipe for a product, validates its state, expands every component line (with unit from Product), and returns an immutable `RecipeSnapshot`. All recipe execution must go through this resolver.

**Callers:** ManufacturingEngine, DecisionEngine, CostEngine, SimulationEngine, AIEngine.

**This service MUST NOT:** consume inventory, calculate cost, execute manufacturing, create transactions, update the database, or trigger the Decision Engine.

---

### Files Created (5)

| File | Purpose |
|------|---------|
| `Modules/Manufacturing/BillsOfMaterials/Domain/ValueObjects/RecipeComponent.php` | Immutable value object: one resolved component (unit + allow_negative_stock from Product) |
| `Modules/Manufacturing/BillsOfMaterials/Domain/ValueObjects/RecipeSnapshot.php` | Immutable snapshot: full recipe at resolution time; includes `bom_version_number` for RC-10 |
| `Modules/Manufacturing/BillsOfMaterials/Domain/Exceptions/RecipeResolverException.php` | Typed exception with 6 reason codes and named constructors |
| `Modules/Manufacturing/BillsOfMaterials/Domain/Services/RecipeResolver.php` | 5-step read-only resolver; uses `RecipeRepositoryInterface` |
| `tests/Feature/Manufacturing/RecipeResolverTest.php` | 22 tests covering all paths |

---

### Resolver Steps

1. `findActiveByProduct($productId)` → null → `noActiveRecipe`
2. Validate `$recipe->product` — null or trashed → `productUnavailable`
3. Fresh query: `$recipe->components()->with(['component.unit'])->get()` — empty → `noComponents`
4. For each line: null/trashed → `componentNotFound`; `!is_active` → `componentInactive`; no unit → `componentMissingUnit`; build `RecipeComponent`
5. Return `new RecipeSnapshot(...)` with `resolved_at = now()->toIso8601String()`

---

### Exception Reason Codes

| Constant | Trigger | Context field |
|----------|---------|---------------|
| `NO_ACTIVE_RECIPE` | No recipe with `is_active = true` | `$productId` |
| `NO_COMPONENTS` | Active recipe has zero lines | `$recipeId` |
| `COMPONENT_NOT_FOUND` | Line's `raw_material_id` is soft-deleted | `$componentId` |
| `COMPONENT_INACTIVE` | Component product `is_active = false` | `$sku` |
| `COMPONENT_MISSING_UNIT` | Component product has no unit relation | `$sku` |
| `PRODUCT_UNAVAILABLE` | Output product is soft-deleted | `$productId` |

---

### Architecture Alignment

| Architecture Decision | Implementation |
|----------------------|----------------|
| RC-2: `allow_negative_stock` forwarded to Decision Engine | `RecipeComponent.allow_negative_stock` copied from `Product.allow_negative_stock` |
| RC-10: Unique constraint on `(order_line_id, bom_id, bom_version_number)` | `RecipeSnapshot.bom_version_number` captures version at resolution time |
| Unit comes from Product | `RecipeComponent.unit_*` set from `$component->unit` — never from the line |
| Read-only domain service | No DB writes anywhere in RecipeResolver; verified by test |
| Immutability | Both `RecipeSnapshot` and `RecipeComponent` are `final readonly class` |

---

### Test Coverage (22 tests)

| Group | Tests |
|-------|-------|
| Happy path (snapshot fields, version, bom_number, resolved_at) | 3 |
| Components (count, type, data, unit source, allow_negative_stock) | 6 |
| Active version selection (picks is_active=true over older versions) | 1 |
| Exception: no active recipe (no recipe at all, only inactive) | 2 |
| Exception: no components | 1 |
| Exception: component deleted | 1 |
| Exception: component inactive | 1 |
| Exception: output product deleted | 1 |
| Immutability (snapshot readonly, component readonly) | 2 |
| toArray() serialization (snapshot keys, component keys) | 2 |
| Per-component quantity accuracy | 1 |
| Read-only (no DB writes) | 1 |

---

## PKG-03A — Decision Kernel

**Status:** ✅ Completed
**Date:** 2026-06-29
**Task:** TASK-MFG-IMP-003

---

### Architecture

The Decision Kernel is a pure domain engine. It has zero infrastructure dependencies.

```
Trigger
    ↓
DecisionKernel.evaluate(trigger, context, ruleProvider)
    ↓
RuleEvaluationPipeline.run(rules, context)
    ↓
Select highest-priority matching rule
    ↓
DecisionResult  ← immutable, returned to caller
```

The kernel **never** executes business operations. It only decides. Execution belongs to the calling Engine.

**Caller isolation:** The kernel does not know who called it. All callers (Orders, GR, Procurement, Manufacturing, AI, CLI, API) receive the same `DecisionResult` contract.

---

### Decision Kernel Architecture

| Concern | Design Decision |
|---------|----------------|
| Independence | No imports from Orders, Inventory, Manufacturing, Procurement, Queue, Laravel |
| Extensibility (Phase 7) | `DecisionRuleInterface` + `RuleProviderInterface` — callers supply any provider |
| Priority resolution (Phase 5) | Highest `priority` wins; PHP 8.0+ stable sort gives first-registered on tie |
| Snapshot support (Phase 6) | `DecisionResult.snapshot_id` + `snapshot_hash` nullable fields — architecture only |
| No matching rule | `NoMatchingRuleException` — callers decide the default (defer, log, escalate) |
| Immutability | All value objects are `final readonly class` |
| No side effects | `RuleEvaluationPipeline.run()` is a pure function; `DecisionKernel.evaluate()` is stateless |

---

### Files Created (12)

#### Domain/Contracts
| File | Purpose |
|------|---------|
| `DecisionRuleInterface.php` | Contract for any rule (in-memory, DB, AI, dynamic) |
| `RuleProviderInterface.php` | Contract for supplying rules; callers pass domain-specific providers |

#### Domain/Enums
| File | Purpose |
|------|---------|
| `DecisionType.php` | `Approve` \| `Reject` \| `Defer` \| `Partial` \| `Escalate` — with `isPositive()`, `isTerminal()` |

#### Domain/Exceptions
| File | Purpose |
|------|---------|
| `NoMatchingRuleException.php` | Thrown when no rule matches; carries `contextType()` |

#### Domain/ValueObjects
| File | Purpose |
|------|---------|
| `DecisionReason.php` | `code` + `message` + `context` array |
| `DecisionTrigger.php` | `trigger_type`, `trigger_id`, `trigger_version` (RC-6), `triggered_at`, `actor_id` |
| `DecisionContext.php` | Generic immutable data bag; `with()` builder; `get()` / `has()` / `all()` |
| `DecisionRule.php` | Concrete in-memory rule; holds `Closure(DecisionContext): bool` |
| `DecisionEvaluation.php` | Single rule evaluation outcome (used internally + in DecisionResult) |
| `DecisionResult.php` | Final output: decision + reason + matched_rule + context + trigger + snapshot slots |

#### Domain/Services
| File | Purpose |
|------|---------|
| `RuleEvaluationPipeline.php` | Evaluates rules, collects matches, stable-sorts by priority descending |
| `InMemoryRuleProvider.php` | Current `RuleProviderInterface` implementation — variadic constructor |
| `DecisionKernel.php` | The kernel — 5-line `evaluate()` — delegates to pipeline, wraps winner |

#### Infrastructure/Providers
| File | Purpose |
|------|---------|
| `DecisionKernelServiceProvider.php` | Registers `RuleEvaluationPipeline` + `DecisionKernel` as singletons |

#### Tests
| File | Purpose |
|------|---------|
| `tests/Unit/Manufacturing/DecisionKernelTest.php` | 28 pure unit tests; no database, no Laravel boot |

---

### Files Modified (1)

| File | Change |
|------|--------|
| `bootstrap/providers.php` | Added `DecisionKernelServiceProvider` after `BomServiceProvider` |

---

### Test Coverage (28 tests)

| Group | Tests |
|-------|-------|
| Happy path (Approve, Reject, Defer decisions) | 3 |
| Exception: no matching rule (no match, empty list) | 2 |
| Priority: highest wins, tie → first registered | 2 |
| Result fields (trigger, context, matched_rule, decided_at) | 4 |
| Result immutability | 1 |
| Snapshot fields default to null (Phase 6) | 1 |
| Result helpers (isApproved, isRejected) | 2 |
| toArray() serialization | 1 |
| DecisionContext (with, get default, has, all) | 4 |
| DecisionTrigger fields | 1 |
| DecisionReason fields + toArray | 1 |
| DecisionRule matches true/false | 2 |
| DecisionType cases | 1 |
| InMemoryRuleProvider returns all rules | 1 |
| NoMatchingRuleException contextType | 1 |
| DecisionEvaluation fields + toArray | 1 |

---

### Architecture Alignment

| Architecture Decision | Implementation |
|----------------------|----------------|
| RC-6: `trigger_version` for idempotency | `DecisionTrigger.trigger_version` — future `decision_key` = hash(type + id + version) |
| Caller isolation | Kernel takes `RuleProviderInterface` at call time — no knowledge of caller |
| SOLID | Single responsibility per class; OCP via interface extension; DI via constructor |

---

### Future Extension Points

| Extension | How |
|-----------|-----|
| Database rules | Implement `RuleProviderInterface` with Eloquent; no kernel changes |
| AI-generated rules | `AiRuleProvider` calls LLM API; implements same interface |
| Dynamic rules | Build `DecisionRule` objects at runtime from config/user input |
| Snapshot persistence | Decision Log engine reads `DecisionResult`, persists with `snapshot_id` + `snapshot_hash` |
| Async evaluation | Wrap `DecisionKernel.evaluate()` in a Job; result returned via callback/event |
| Audit trail | Caller logs `DecisionResult.toArray()` to `decision_logs` table (RC-6) after kernel returns |

---

### Risk Assessment

| Risk | Severity | Mitigation |
|------|----------|-----------|
| No matching rule in production | Medium | Callers must always register at least one catch-all rule or handle `NoMatchingRuleException` |
| Priority tie non-determinism | Low | PHP 8.0+ stable sort + documented tie-breaking strategy (first-registered wins) |
| Context data typing | Low | `DecisionContext.get()` returns `mixed` — callers own type assertions |

---

## PKG-03B — Decision Orchestrator

**Status:** ✅ Completed
**Date:** 2026-06-29
**Task:** TASK-MFG-IMP-003B

---

### Architecture

```
Caller
    ↓
DecisionOrchestrator.orchestrate(trigger, builder, parameters, metadata)
    ├── ContextBuilderInterface.build()        → DecisionContext
    ├── RecipeResolverInterface.resolve()      → RecipeSnapshot (if requiresRecipe)
    ├── enrichWithRecipe()                     → enriched DecisionContext
    ├── RuleProviderRegistryInterface.for()    → RuleProviderInterface
    └── DecisionKernel.evaluate()             → DecisionResult
    ↓
OrchestratorResult  ← DecisionResult + RecipeSnapshot? + merged metadata
```

The Orchestrator **never** executes business operations. It coordinates domain engines and returns an immutable result to the caller.

---

### Design Decisions

| Concern | Design Decision |
|---------|----------------|
| Recipe access | Only via `RecipeResolverInterface` — never direct model reads |
| Builder extensibility | `ContextBuilderInterface` — new context types = new builder class; no orchestrator changes |
| Rule provider selection | `RuleProviderRegistryInterface` — context type → `RuleProviderInterface` mapping |
| Recipe requirement | Builder declares `requiresRecipe(): bool`; orchestrator asks the builder |
| Context enrichment | After resolution, `recipe_id`, `bom_version_number`, `component_count`, `recipe_resolved` injected into context |
| Caller isolation | Orchestrator does not know its caller; all callers receive `OrchestratorResult` |
| Error propagation | `RecipeResolverException` and `NoMatchingRuleException` propagate unchanged; only config errors become `OrchestratorException` |

---

### Files Created (11)

#### BillsOfMaterials/Domain/Contracts (new)
| File | Purpose |
|------|---------|
| `RecipeResolverInterface.php` | Abstraction over RecipeResolver; enables mocking + future CachedRecipeResolver, SimulatedRecipeResolver |

#### DecisionOrchestrator/Domain/Contracts
| File | Purpose |
|------|---------|
| `ContextBuilderInterface.php` | `contextType()`, `requiresRecipe()`, `build(array $params): DecisionContext` |
| `RuleProviderRegistryInterface.php` | `for(type)`, `register(type, provider)`, `has(type)` |

#### DecisionOrchestrator/Domain/Exceptions
| File | Purpose |
|------|---------|
| `OrchestratorException.php` | `MISSING_PRODUCT_ID` — builder requires recipe but `product_id` absent |
| `NoProviderForContextException.php` | No `RuleProviderInterface` registered for context type |

#### DecisionOrchestrator/Domain/ValueObjects
| File | Purpose |
|------|---------|
| `OrchestratorResult.php` | `final readonly`: `decision`, `recipe_snapshot?`, `metadata`; `hasRecipe()`, `toArray()` |

#### DecisionOrchestrator/Domain/Builders
| File | Purpose |
|------|---------|
| `ManufacturingContextBuilder.php` | `requiresRecipe=true`; keys: `product_id`, `ordered_qty`, `available_qty`, `shortage_qty` |
| `GoodsReceiptContextBuilder.php` | `requiresRecipe=false`; keys: `gr_id`, `po_id`, `received_qty`, `ordered_qty`, `variance_pct` (auto-computed) |

#### DecisionOrchestrator/Domain/Services
| File | Purpose |
|------|---------|
| `InMemoryRuleProviderRegistry.php` | Mutable registry; fluent `register()` returns `static` |
| `DecisionOrchestrator.php` | 5-step orchestration: build → resolve → enrich → select rules → evaluate |

#### Infrastructure/Providers
| File | Purpose |
|------|---------|
| `DecisionOrchestratorServiceProvider.php` | Singletons: `RuleProviderRegistryInterface`, `DecisionOrchestrator` |

#### Tests
| File | Purpose |
|------|---------|
| `tests/Unit/Manufacturing/DecisionOrchestratorTest.php` | 25 pure unit tests; PHPUnit mock for `RecipeResolverInterface`; real kernel |

---

### Files Modified (3)

| File | Change |
|------|--------|
| `BillsOfMaterials/Domain/Services/RecipeResolver.php` | Added `implements RecipeResolverInterface` |
| `BillsOfMaterials/Infrastructure/Providers/BomServiceProvider.php` | Bound `RecipeResolverInterface → RecipeResolver` |
| `bootstrap/providers.php` | Registered `DecisionOrchestratorServiceProvider` |

---

### Test Coverage (25 tests)

| Group | Tests |
|-------|-------|
| Basic orchestration (GR context, no recipe) | 1 |
| Resolver NOT called when not required | 1 |
| Resolver IS called when required | 1 |
| Recipe snapshot in result | 1 |
| Context enriched with recipe metadata | 1 |
| Rule provider selected by context type | 1 |
| DecisionResult in OrchestratorResult | 1 |
| Caller metadata propagated | 1 |
| context_type always in metadata | 1 |
| Recipe fields in metadata when resolved | 1 |
| RecipeResolverException propagates | 1 |
| NoMatchingRuleException propagates | 1 |
| NoProviderForContextException thrown + contextType() | 1 |
| OrchestratorException when product_id missing | 1 |
| OrchestratorResult immutability | 1 |
| toArray() structure | 1 |
| ManufacturingContextBuilder context type + keys | 2 |
| ManufacturingContextBuilder requiresRecipe | 1 |
| GoodsReceiptContextBuilder context type | 1 |
| GoodsReceiptContextBuilder does not require recipe | 1 |
| GoodsReceiptContextBuilder variance_pct + is_partial + over_received | 1 |
| InMemoryRuleProviderRegistry register + retrieve | 1 |
| InMemoryRuleProviderRegistry throws for unknown | 1 |
| InMemoryRuleProviderRegistry has() returns false | 1 |

---

### Architecture Alignment

| Architecture Decision | Implementation |
|----------------------|----------------|
| Caller isolation | Orchestrator receives `ContextBuilderInterface` — does not know if caller is Orders, GR, or AI |
| RC-10 snapshot readiness | `OrchestratorResult.recipe_snapshot.bom_version_number` available for unique constraint |
| RecipeResolver — never bypass | Orchestrator always calls `RecipeResolverInterface`; never imports Recipe models |
| SOLID OCP | New context type = new builder class; no orchestrator modifications |

---

### Future Extension Points

| Extension | How |
|-----------|-----|
| SchedulerContextBuilder | Implement `ContextBuilderInterface`; register in registry |
| AiContextBuilder | Same pattern |
| CachedRecipeResolver | Implement `RecipeResolverInterface`; swap binding in service provider |
| DB-backed RuleProviderRegistry | Implement `RuleProviderRegistryInterface` with Eloquent |
| Async orchestration | Wrap `orchestrate()` in a Job; kernel + resolver calls remain unchanged |

---

## PKG-04A — Inventory Availability Engine

**Status:** ✅ Completed
**Date:** 2026-06-29
**Task:** TASK-MFG-IMP-004A

---

### Architecture

```
Caller
    ↓
InventoryAvailabilityEngine.analyse(productId, warehouseId, requiredQty)
    ├── InventoryReadInterface.availableQty()   → available FG
    ├── RC-1: qty_to_manufacture = max(0, required − available_fg)
    │   └── 0 → Sufficient (return early, no recipe needed)
    ├── RecipeResolverInterface.resolve()        → RecipeSnapshot
    │   └── RecipeResolverException → NoRecipe  (valid state, not error)
    ├── Per component: availableQty(), scale by qty_to_manufacture
    │   required = component.quantity × qty_to_manufacture  (RC-1 scaling)
    │   is_satisfied = (missing == 0) || allow_negative_stock  (RC-2)
    └── classifyEligibility()
            All satisfied              → CanManufacture
            Unsatisfied + all negative → Partial       (RC-2)
            Any hard blocker           → CannotManufacture
    ↓
AvailabilityResult  ← immutable; no inventory written
```

**READ-ONLY GUARANTEE:** The engine never writes to inventory, never creates manufacturing transactions, and never reserves stock. Every call is side-effect-free.

---

### Design Decisions

| Concern | Design Decision |
|---------|----------------|
| RC-1 (Partial Manufacturing) | `qty_to_manufacture = max(0, required − available_fg)` — only the shortage is manufactured |
| RC-2 (Negative Stock) | Evaluated per raw material; `is_satisfied = true` when `missing == 0 \|\| allow_negative_stock` |
| No recipe = valid state | `RecipeResolverException` caught inside engine → `NoRecipe` eligibility; not propagated |
| Inventory read contract | `InventoryReadInterface` — thin single-method contract returning `float`; 0.0 when no record exists |
| Infrastructure adapter | `EloquentInventoryReader` wraps `InventoryItemRepositoryInterface`; engine imports zero Eloquent |
| `Sufficient` early exit | When FG covers full need, recipe resolution is skipped entirely (no unnecessary DB query) |
| Empty components fallback | If recipe resolver returns a recipe with zero components (should not happen after RecipeResolver validates), engine returns `CanManufacture` — nothing missing |

---

### Files Created (8)

#### Domain/Contracts
| File | Purpose |
|------|---------|
| `AvailabilityEngine/Domain/Contracts/InventoryReadInterface.php` | Thin read contract: `availableQty(warehouseId, productId): float` |

#### Domain/Enums
| File | Purpose |
|------|---------|
| `AvailabilityEngine/Domain/Enums/ManufacturingEligibility.php` | 5-state enum: `Sufficient`, `CanManufacture`, `Partial`, `CannotManufacture`, `NoRecipe` with `allowsManufacturing()` and `label()` |

#### Domain/ValueObjects
| File | Purpose |
|------|---------|
| `AvailabilityEngine/Domain/ValueObjects/RawMaterialAvailability.php` | Per-component analysis: required/available/missing/allow_negative_stock/is_satisfied |
| `AvailabilityEngine/Domain/ValueObjects/AvailabilityResult.php` | Full engine output: all fields + `isSufficient()`, `hasRecipe()`, `missingMaterials()`, `toArray()` |

#### Domain/Services
| File | Purpose |
|------|---------|
| `AvailabilityEngine/Domain/Services/InventoryAvailabilityEngine.php` | Core engine: `analyse()` + 4 private helpers |

#### Infrastructure/Readers
| File | Purpose |
|------|---------|
| `AvailabilityEngine/Infrastructure/Readers/EloquentInventoryReader.php` | Adapts `InventoryItemRepositoryInterface` → `InventoryReadInterface`; returns 0.0 for missing records |

#### Infrastructure/Providers
| File | Purpose |
|------|---------|
| `AvailabilityEngine/Infrastructure/Providers/AvailabilityEngineServiceProvider.php` | Binds `InventoryReadInterface` + registers `InventoryAvailabilityEngine` as singleton |

#### Tests
| File | Purpose |
|------|---------|
| `tests/Feature/Manufacturing/InventoryAvailabilityEngineTest.php` | 14 feature tests with `RefreshDatabase` |

---

### Files Modified (1)

| File | Change |
|------|--------|
| `bootstrap/providers.php` | Registered `AvailabilityEngineServiceProvider` after `DecisionOrchestratorServiceProvider` |

---

### Test Coverage (14 tests)

| Group | Tests |
|-------|-------|
| Sufficient stock (FG covers, exactly equal) | 2 |
| RC-1: only shortage manufactured | 1 |
| Can Manufacture (all materials covered, no FG) | 2 |
| Partial — RC-2 negative stock components | 2 |
| Cannot Manufacture (hard blocker, one blocker among many) | 2 |
| No Recipe (no BOM, inactive BOM) | 2 |
| Missing inventory record = 0 stock | 1 |
| Read-only guarantee (no inventory written) | 1 |
| Result structure (fields, snapshot, raw_material, toArray, missingMaterials) | 4 + 1 overlap in above |

---

### Architecture Alignment

| Architecture Decision | Implementation |
|----------------------|----------------|
| RC-1 Partial Manufacturing | `qty_to_manufacture = max(0, required − available_fg)` — precise |
| RC-2 Negative Stock on raw materials only | `is_satisfied = missing == 0 \|\| allow_negative_stock`; FG never goes negative (finished goods path returns Sufficient or triggers manufacturing) |
| Analysis only — no side effects | Engine is read-only; `InventoryReadInterface` has no write methods |
| `InventoryReadInterface` separates concerns | Engine does not import Eloquent or Inventory module models |
| `RecipeResolverInterface` used (not RecipeResolver) | Engine is testable without DB; uses the same abstraction from PKG-03B |

---

### Eligibility Classification Matrix

| Scenario | Eligibility | `can_manufacture` | `needs_manufacturing` |
|----------|-------------|-------------------|-----------------------|
| FG stock covers full need | `Sufficient` | true | false |
| No FG, all raw materials available | `CanManufacture` | true | true |
| No FG, some short + all have `allow_negative_stock` | `Partial` | true | true |
| No FG, any short without `allow_negative_stock` | `CannotManufacture` | false | true |
| No FG, no active recipe | `NoRecipe` | false | true |

---

### Future Extension Points

| Extension | How |
|-----------|-----|
| Cached inventory reads | Implement `CachedInventoryReader implements InventoryReadInterface`; swap binding |
| CQRS read model | `ProjectionInventoryReader` reads from denormalized read table; no engine changes |
| Multi-warehouse analysis | Extend `analyse()` signature or add `analyseAcrossWarehouses()` method |
| Reservation preview | Add optional `include_reserved: bool` parameter to `InventoryReadInterface.availableQty()` |

---

## PKG-04B — Manufacturing Planner

**Status:** ✅ Completed
**Date:** 2026-06-29
**Task:** TASK-MFG-IMP-004B

---

### Architecture

```
Caller
    ↓
ManufacturingPlanner.plan(AvailabilityResult, DecisionResult, metadata)
    ├── assertInvariant()  — guard: CanManufacture|Partial must have recipe_snapshot
    ├── buildComponents()  — RawMaterialAvailability → ComponentConsumptionPlan[]
    ├── buildNegativeStockDecisions()  — filter will_go_negative → NegativeStockDecision[]
    ├── hashSnapshot()     — SHA-256 of RecipeSnapshot.toArray() JSON
    ├── can_proceed  = eligibility.allowsManufacturing() && decision.isPositive()
    └── should_manufacture = can_proceed && availability.needs_manufacturing
    ↓
ManufacturingPlan  ← immutable; no inventory written; no jobs dispatched
```

**PLAN ONLY GUARANTEE:** The planner never consumes inventory, reserves stock,
calculates costs, dispatches jobs, or writes any records.
The Manufacturing Engine (PKG-05) receives and executes the plan.

---

### Design Decisions

| Concern | Design Decision |
|---------|----------------|
| can_proceed vs should_manufacture | `can_proceed` = eligibility + decision positive. `should_manufacture` = can_proceed + needs_manufacturing. Sufficient: can_proceed=true, should_manufacture=false. |
| Negative stock (RC-2) | `will_go_negative` per component; `NegativeStockDecision` records projected balance for operator audit trail |
| Snapshot hash | SHA-256 of `RecipeSnapshot.toArray()` JSON — Manufacturing Engine verifies before executing |
| Invariant protection | `PlannerException` only for programming errors (CanManufacture/Partial + null snapshot); all valid states return a plan |
| Pure domain — no UUID package | UUID v4 generated via `random_bytes(16)` + bit manipulation; no framework dependency |
| Component data source | Built from `AvailabilityResult.raw_materials` — quantities already RC-1 scaled by the engine |

---

### Decision Outcome → Plan Flags Matrix

| Eligibility | Decision | can_proceed | should_manufacture |
|-------------|----------|-------------|---------------------|
| Sufficient | Approve | true | false |
| CanManufacture | Approve | true | true |
| Partial | Partial | true | true |
| CannotManufacture | Reject | false | false |
| NoRecipe | Reject | false | false |
| CanManufacture | Defer | false | false |
| CanManufacture | Escalate | false | false |

---

### Files Created (7)

#### Domain/Exceptions
| File | Purpose |
|------|---------|
| `ManufacturingPlanner/Domain/Exceptions/PlannerException.php` | Single reason code: `RECIPE_SNAPSHOT_MISSING` — invariant violation only |

#### Domain/ValueObjects
| File | Purpose |
|------|---------|
| `ManufacturingPlanner/Domain/ValueObjects/ComponentConsumptionPlan.php` | Per-component: `qty_to_consume`, `available_qty`, `missing_qty`, `will_go_negative`, `is_blocked` |
| `ManufacturingPlanner/Domain/ValueObjects/NegativeStockDecision.php` | RC-2 record: `projected_balance` (always negative) for operator audit |
| `ManufacturingPlanner/Domain/ValueObjects/ManufacturingPlan.php` | Full immutable plan: all fields + `hasNegativeStockRisk()`, `blockedComponents()`, `toArray()` |

#### Domain/Services
| File | Purpose |
|------|---------|
| `ManufacturingPlanner/Domain/Services/ManufacturingPlanner.php` | Core service: `plan()` + 4 private helpers; pure PHP UUID v4; no framework deps |

#### Infrastructure/Providers
| File | Purpose |
|------|---------|
| `ManufacturingPlanner/Infrastructure/Providers/ManufacturingPlannerServiceProvider.php` | Registers `ManufacturingPlanner` as singleton (no constructor deps) |

#### Tests
| File | Purpose |
|------|---------|
| `tests/Unit/Manufacturing/ManufacturingPlannerTest.php` | 22 pure unit tests; no DB, no Laravel boot; all fixtures constructed inline |

---

### Files Modified (1)

| File | Change |
|------|--------|
| `bootstrap/providers.php` | Registered `ManufacturingPlannerServiceProvider` |

---

### Test Coverage (22 tests)

| Group | Tests |
|-------|-------|
| Full manufacture (Approve) | 2 |
| RC-1 partial manufacture | 1 |
| Sufficient stock (no manufacture) | 1 |
| Deferred + Escalated decisions | 2 |
| No recipe plan | 1 |
| Blocked by availability + helpers | 2 |
| Negative stock (RC-2) decisions | 3 |
| Snapshot hash (SHA-256, stability, divergence, null for no recipe) | 5 |
| Plan identity (UUID v4, uniqueness, ISO 8601 planned_at) | 3 |
| Metadata integrity (decision, trigger, caller, warehouse) | 4 |
| toArray() structure | 1 |
| Invariant violations (PlannerException) | 3 |

---

### Architecture Alignment

| Architecture Decision | Implementation |
|----------------------|----------------|
| RC-1 Partial Manufacturing | `qty_to_manufacture` from AvailabilityResult; `should_manufacture = can_proceed && needs_manufacturing` |
| RC-2 Negative Stock | `will_go_negative` per component; `NegativeStockDecision.projected_balance` for audit |
| Recipe snapshot integrity | `recipe_snapshot_hash` = SHA-256 of snapshot JSON; verified by PKG-05 before execution |
| Separation of concerns | Planner plans; Engine executes; Planner never writes |
| Zero infrastructure imports | No Eloquent, no Laravel Facades, no Queue, no Events |

---

## PKG-05 — Manufacturing Executor

**Status:** ✅ Completed
**Date:** 2026-06-29
**Task:** TASK-MFG-IMP-005

---

### Architecture

```
Caller
    ↓
ManufacturingExecutor.execute(ManufacturingPlan, companyId)
    ├── Guard: should_manufacture == false  → ExecutionException(PLAN_NOT_APPROVED)
    ├── Guard: recipe_snapshot_hash == null → ExecutionException(SNAPSHOT_MISSING)
    ├── Guard: verifySnapshotIntegrity()    → ExecutionException(SNAPSHOT_MISMATCH)
    ├── Idempotency: findByPlanId()         → if found → buildIdempotentResult()
    └── DB::transaction()
            ├── consumeComponent() × N      → decrement on_hand_qty + ProductionConsumption ledger entry
            ├── produceFinishedGoods()      → increment on_hand_qty + ProductionOutput ledger entry
            └── ManufacturingTransaction.save()
    ↓
ManufacturingExecutionResult  ← success=true, was_idempotent, qty_produced, consumed_components[], ledger_entry_ids[], duration_ms
```

**EXECUTION CONTRACT:**
- Allowed: consume raw materials, create ledger entries, create manufacturing transaction, produce FG inventory
- Forbidden: resolve recipes, check availability, build plans, evaluate rules, make decisions, select recipe versions, calculate manufacturing quantity

---

### Transaction Boundaries

All DB writes happen inside a single `DB::transaction()`. If any step throws, Postgres rolls back:
- Raw material `on_hand_qty` decrements
- Finished goods `on_hand_qty` increment
- All `StockLedgerEntry` inserts
- `ManufacturingTransaction` insert

The pre-execution guards (snapshot integrity, plan approval) run **before** the transaction opens — no rollback needed for those.

---

### Idempotency Strategy

| Layer | Mechanism |
|-------|-----------|
| Pre-check | `findByPlanId()` before entering transaction |
| DB constraint | `UNIQUE(plan_id)` on `manufacturing_transactions` |
| Race condition | Concurrent calls with same plan_id: first to commit wins; second hits UNIQUE and rolls back |
| Return value | `was_idempotent = true` + same `transaction_id` from original execution |

---

### Snapshot Integrity Check

Before any DB write, the executor re-hashes the plan's embedded `RecipeSnapshot`:

```
computed = SHA-256(json_encode(plan.recipe_snapshot.toArray()))
stored   = plan.recipe_snapshot_hash   ← set by ManufacturingPlanner at planning time

computed != stored → ExecutionException(SNAPSHOT_MISMATCH)
```

This ensures a tampered or stale plan cannot drive an execution.

---

### Ledger Entry Convention

All entries from one execution share:
- `reference_type = 'manufacturing_plan'`
- `reference_id   = plan.plan_id`

Component consumptions: `LedgerMovementType::ProductionConsumption`
Finished goods output: `LedgerMovementType::ProductionOutput`

---

### RC-2 Negative Stock Execution

When a component has `allow_negative_stock = true` and insufficient stock:
- `on_hand_qty` decrements below zero — no exception thrown
- `ComponentConsumptionRecord.went_negative = true` flagged in result
- Ledger entry records `on_hand_before` and `on_hand_after` (negative)

---

### Files Created (9)

#### Domain/Enums
| File | Purpose |
|------|---------|
| `ManufacturingExecution/Domain/Enums/TransactionStatus.php` | `Completed`, `Failed`, `RolledBack` — backed string enum |

#### Domain/Exceptions
| File | Purpose |
|------|---------|
| `ManufacturingExecution/Domain/Exceptions/ExecutionException.php` | 3 reason codes: `PLAN_NOT_APPROVED`, `SNAPSHOT_MISSING`, `SNAPSHOT_MISMATCH`; named constructors; `planId()` accessor |

#### Domain/Contracts
| File | Purpose |
|------|---------|
| `ManufacturingExecution/Domain/Contracts/ManufacturingTransactionRepositoryInterface.php` | `findByPlanId()`, `save()` |

#### Domain/Models
| File | Purpose |
|------|---------|
| `ManufacturingExecution/Domain/Models/ManufacturingTransaction.php` | Eloquent model: `HasUuids`; casts `status → TransactionStatus`, `metadata → array` |

#### Domain/ValueObjects
| File | Purpose |
|------|---------|
| `ManufacturingExecution/Domain/ValueObjects/ComponentConsumptionRecord.php` | Execution record per component: `on_hand_before`, `on_hand_after`, `went_negative`, `ledger_entry_id` |
| `ManufacturingExecution/Domain/ValueObjects/ManufacturingExecutionResult.php` | Full execution output: `execution_id`, `transaction_id`, `success`, `was_idempotent`, `qty_produced`, `consumed_components[]`, `ledger_entry_ids[]`, `duration_ms` |

#### Application/Services
| File | Purpose |
|------|---------|
| `ManufacturingExecution/Application/Services/ManufacturingExecutor.php` | Core executor: 3 guards + idempotency + DB transaction; pure execution, no decisions |

#### Infrastructure
| File | Purpose |
|------|---------|
| `ManufacturingExecution/Infrastructure/Database/Migrations/2026_06_29_000003_create_manufacturing_transactions_table.php` | `manufacturing_transactions` table: `UNIQUE(plan_id)`, `UNIQUE(execution_id)`, nullable `order_line_id` (RC-10 anchor) |
| `ManufacturingExecution/Infrastructure/Persistence/EloquentManufacturingTransactionRepository.php` | Eloquent implementation |
| `ManufacturingExecution/Infrastructure/Providers/ManufacturingExecutionServiceProvider.php` | Binds `ManufacturingTransactionRepositoryInterface`; registers `ManufacturingExecutor` singleton |

#### Tests
| File | Purpose |
|------|---------|
| `tests/Feature/Manufacturing/ManufacturingExecutorTest.php` | 14 feature tests with `RefreshDatabase` |

---

### Files Modified (3)

| File | Change |
|------|--------|
| `ManufacturingPlanner/Domain/ValueObjects/ManufacturingPlan.php` | Added `?RecipeSnapshot $recipe_snapshot` field + `toArray()` update — executor needs it for integrity re-hash |
| `ManufacturingPlanner/Domain/Services/ManufacturingPlanner.php` | Populates `recipe_snapshot: $snapshot` in `new ManufacturingPlan(...)` |
| `bootstrap/providers.php` | Registered `ManufacturingExecutionServiceProvider` |

---

### Test Coverage (14 tests)

| Group | Tests |
|-------|-------|
| Successful execution: raw material decremented | 1 |
| Successful execution: FG incremented | 1 |
| Successful execution: ledger entries created (consumption + output) | 1 |
| Successful execution: ManufacturingTransaction record created | 1 |
| Successful execution: result structure (execution_id, success, qty, components) | 1 |
| RC-2 negative stock: component goes below zero, `went_negative=true` | 1 |
| Idempotency: duplicate returns `was_idempotent=true` + same `transaction_id` | 1 |
| Idempotency: duplicate does not double-consume inventory | 1 |
| Rollback: transaction repo failure → inventory unchanged, no ledger entries, no tx record | 1 |
| Guard: `should_manufacture=false` → `ExecutionException(PLAN_NOT_APPROVED)` | 1 |
| Guard: `should_manufacture=false` → no DB writes | 1 |
| Guard: snapshot hash mismatch → `ExecutionException(SNAPSHOT_MISMATCH)` | 1 |
| Guard: snapshot mismatch has correct `reason()` code | 1 |
| Guard: `recipe_snapshot_hash=null` → `ExecutionException(SNAPSHOT_MISSING)` | 1 |

---

### Architecture Alignment

| Architecture Decision | Implementation |
|----------------------|----------------|
| RC-2 Negative Stock at execution | `on_hand_qty` allowed to go below zero when `allow_negative_stock=true`; `went_negative` flagged |
| RC-6 Idempotency | Pre-check `findByPlanId()` + DB `UNIQUE(plan_id)` as second guard; concurrent race → first wins |
| RC-10 Unique constraint anchor | `order_line_id UUID NULLABLE` column present; UNIQUE constraint added when Order integration arrives |
| Transaction boundary | All inventory + ledger + transaction writes in single `DB::transaction()`; rollback on any exception |
| Executor vs Planner separation | Executor receives fully-decided plan; forbidden from resolving recipes or evaluating rules |
| Snapshot integrity | Executor re-hashes `plan.recipe_snapshot` and compares to `plan.recipe_snapshot_hash` before any writes |

---

### `manufacturing_transactions` Table

| Column | Type | Notes |
|--------|------|-------|
| `id` | UUID PK | `HasUuids` |
| `execution_id` | VARCHAR(36) UNIQUE | Correlation ID generated at call time |
| `plan_id` | VARCHAR(36) UNIQUE | Idempotency key from `ManufacturingPlan.plan_id` |
| `product_id` | UUID FK | Finished good produced |
| `warehouse_id` | UUID FK | Execution warehouse |
| `bom_id` | UUID NULLABLE | `recipe_id` from ManufacturingPlan |
| `bom_version_number` | UINT NULLABLE | RC-10: monotonically increasing version |
| `recipe_snapshot_hash` | VARCHAR(64) NULLABLE | SHA-256 audit trail |
| `qty_produced` | DECIMAL(15,4) | |
| `status` | VARCHAR(20) | Default `'completed'` |
| `executed_at` | TIMESTAMP | Auto-set to current |
| `duration_ms` | UINT NULLABLE | Wall-clock execution time |
| `order_line_id` | UUID NULLABLE | RC-10 future anchor for Order integration |
| `metadata` | JSON NULLABLE | |

---

### Failure Recovery

| Failure Point | Behaviour |
|--------------|-----------|
| Guard check fails (pre-transaction) | `ExecutionException` thrown; zero DB writes |
| Component inventory lock fails | `DB::transaction()` rolls back all writes; exception propagates |
| Ledger entry insert fails | Full rollback; inventory back to original state |
| Transaction record insert fails | Full rollback; all inventory mutations undone |
| Concurrent duplicate plan_id | Second call hits `UNIQUE(plan_id)` DB error → transaction rolls back; first call's result stands |

---

## PKG-05A — Execution Pipeline

**Status:** ✅ Completed
**Date:** 2026-06-29
**Task:** TASK-MFG-IMP-005A

---

### Architecture

```
ManufacturingPlan
    ↓
ExecutionPipeline.prepare(plan, alreadyExecuted, expirySeconds)
    ├── validateRequiredMetadata()      plan_id / product_id / warehouse_id non-empty
    ├── validatePlanExecutable()        should_manufacture == true
    ├── validateSnapshotPresent()       recipe_snapshot != null (when required)
    ├── validateSnapshotHashPresent()   recipe_snapshot_hash != null (when required)
    ├── validateSnapshotHash()          re-hash == stored hash (SHA-256)
    ├── validatePlanVersion()           bom_version_number >= 1
    ├── validateRecipeVersion()         plan version == snapshot version
    ├── validateDecisionKey()           recipe_id present (key can be derived)
    ├── validateIdempotency()           alreadyExecuted == false
    ├── validateExpiry()                age <= expirySeconds (default 24 h)
    └── validateComponentConsistency()  all components in snapshot; qty > 0
    ↓
ManufacturingExecutionContext  ← always returned, never throws for business failures
```

**CONTRACT — This service MUST NOT:**
- Consume inventory
- Produce finished goods
- Create ledger entries
- Write any database records
- Dispatch events
- Update costs
- Create manufacturing transactions

**Failure strategy:** ALL validators run regardless of earlier failures — callers see every issue in one result. `PipelineException` is only thrown for unrecoverable internal errors (unparseable timestamp).

---

### Validation Rules & Failure Codes

| Validator | Failure Code | Trigger |
|-----------|-------------|---------|
| `validateRequiredMetadata` | `MissingRequiredMetadata` | `plan_id`, `product_id`, or `warehouse_id` is empty |
| `validatePlanExecutable` | `PlanNotExecutable` | `should_manufacture == false` |
| `validateSnapshotPresent` | `SnapshotMissing` | `recipe_snapshot == null` but manufacturing required |
| `validateSnapshotHashPresent` | `SnapshotHashMissing` | `recipe_snapshot_hash == null` but manufacturing required |
| `validateSnapshotHash` | `SnapshotHashMismatch` | `hash(snapshot.toArray()) != plan.recipe_snapshot_hash` |
| `validatePlanVersion` | `PlanVersionMissing` | `bom_version_number == null` or `< 1` |
| `validateRecipeVersion` | `RecipeVersionMismatch` | `plan.bom_version_number != snapshot.bom_version_number` |
| `validateDecisionKey` | `DecisionKeyUnderivable` | `recipe_id == null` on an executable plan |
| `validateIdempotency` | `AlreadyExecuted` | Caller passed `$alreadyExecuted = true` |
| `validateExpiry` | `PlanExpired` | `now - planned_at > expirySeconds` |
| `validateComponentConsistency` | `ComponentInconsistency` | `qty_to_consume <= 0` or component not in snapshot |

---

### ManufacturingExecutionContext

| Field | Type | Purpose |
|-------|------|---------|
| `plan` | `ManufacturingPlan` | Original plan (read-only reference) |
| `recipe_snapshot` | `?RecipeSnapshot` | Forwarded from plan |
| `snapshot_hash` | `?string` | Forwarded from plan |
| `decision_key` | `string` | SHA-256 of `product_id|warehouse_id|recipe_id|bom_version|snapshot_hash` — deterministic |
| `execution_uuid` | `string` | UUID v4 generated fresh per `prepare()` call |
| `transaction_metadata` | `array` | Pre-built row data for ManufacturingTransaction |
| `validation_result` | `PipelineValidationResult` | All validation failures (or valid) |
| `correlation_id` | `string` | From `plan.metadata['correlation_id']` or fresh UUID |
| `execution_timestamp` | `string` | ISO 8601 timestamp of when `prepare()` was called |

---

### Idempotency Design

The pipeline never queries the database. Idempotency works as follows:

1. **Caller** queries DB: `findByPlanId(plan.plan_id)` → sets `$alreadyExecuted = found`
2. **Pipeline** validates: if `$alreadyExecuted` → `AlreadyExecuted` failure in result
3. **Executor (PKG-05B)** has UNIQUE(plan_id) as a second guard against race conditions

This keeps the pipeline pure domain with no infrastructure imports.

---

### Files Created (6)

#### Domain/Enums
| File | Purpose |
|------|---------|
| `ManufacturingExecution/Domain/Enums/ValidationFailureCode.php` | 11-case enum: every typed failure reason |

#### Domain/Exceptions
| File | Purpose |
|------|---------|
| `ManufacturingExecution/Domain/Exceptions/PipelineException.php` | `CLOCK_FAILURE` — thrown only when timestamp is unparseable |

#### Domain/ValueObjects
| File | Purpose |
|------|---------|
| `ManufacturingExecution/Domain/ValueObjects/ValidationFailure.php` | Typed failure: code + message + context array |
| `ManufacturingExecution/Domain/ValueObjects/PipelineValidationResult.php` | `valid()` / `invalid(failures[])` / `hasFailure(code)` |
| `ManufacturingExecution/Domain/ValueObjects/ManufacturingExecutionContext.php` | Full execution context: plan + ids + metadata + validation result |

#### Domain/Services
| File | Purpose |
|------|---------|
| `ManufacturingExecution/Domain/Services/ExecutionPipeline.php` | Core pipeline: 11 validators + 4 builders; no infrastructure deps |

#### Tests
| File | Purpose |
|------|---------|
| `tests/Unit/Manufacturing/ExecutionPipelineTest.php` | 30 pure unit tests; `PHPUnit\Framework\TestCase`; no DB |

---

### Files Modified (1)

| File | Change |
|------|--------|
| `ManufacturingExecution/Infrastructure/Providers/ManufacturingExecutionServiceProvider.php` | Added `ExecutionPipeline` singleton registration |

---

### Test Coverage (30 tests)

| Group | Tests |
|-------|-------|
| Valid plan (context structure, plan reference, execution UUID, UUID v4 format, uniqueness) | 4 |
| Valid plan (decision key determinism, length) | 2 |
| Valid plan (execution timestamp ISO 8601, transaction metadata keys) | 2 |
| Valid plan (toArray keys) | 1 |
| Invalid snapshot (SnapshotMissing failure, no throw) | 2 |
| Hash mismatch (SnapshotHashMismatch failure, context carries stored + computed) | 2 |
| Duplicate execution (AlreadyExecuted failure, carries plan_id, no failure when false) | 3 |
| Invalid recipe version (RecipeVersionMismatch failure, context carries both versions) | 2 |
| Invalid plan (PlanNotExecutable failure, carries eligibility) | 2 |
| Missing metadata (empty plan_id, empty product_id, empty warehouse_id, lists fields) | 4 |
| Correlation propagation (from metadata, generated when absent, different from exec UUID) | 3 |
| Multiple failures (all collected, not short-circuited) | 1 |
| Plan expiry (PlanExpired for stale plan, no failure for fresh plan) | 2 |
| Component consistency (unknown component, zero qty) | 2 |
| PipelineException (thrown, correct reason code) | 2 |
| Validation result helpers (toArray, valid factory) | 2 |

---

### Architecture Alignment

| Architecture Decision | Implementation |
|----------------------|----------------|
| No DB in pipeline | `alreadyExecuted: bool` parameter — caller does the DB lookup; pipeline stays pure |
| All failures visible | Validators never short-circuit; all failures collected before returning context |
| Business failures never thrown | `ValidationFailure` + `PipelineValidationResult` returned; only `PipelineException` thrown |
| Decision key determinism | `hash(product_id|warehouse_id|recipe_id|version|snapshot_hash)` — content-addressed |
| Correlation propagation | `plan.metadata['correlation_id']` flows through; fresh UUID generated when absent |
| Executor still checks validity | Context carries `isValid()` — PKG-05B must check before mutating anything |

---

## PKG-04C — Manufacturing Workflow

**Status:** ✅ Completed
**Date:** 2026-06-29
**Commit:** (this batch)
**Tests:** 20 feature tests

### Purpose

The Manufacturing Workflow is the **single entry point** for every manufacturing request in the system.
It coordinates all manufacturing engines in a fixed, sequential order and returns a typed result
describing where the workflow reached and why it stopped.

```
Caller
  └─▶ ManufacturingWorkflow.run(ManufacturingWorkflowRequest)
           │
           ├── Stage 1: DecisionOrchestrator.orchestrate()  ──▶ [blocked: DecisionRejected/Deferred/Escalated/NoMatchingRule/RecipeNotFound]
           │
           ├── Stage 2: InventoryAvailabilityEngine.analyse()  ──▶ [blocked: CannotManufacture/NoRecipe]
           │
           └── Stage 3: ManufacturingPlanner.plan()  ──▶ [blocked: ManufacturingNotNeeded]
                                                       └─▶ ManufacturingWorkflowResult (is_plan_ready = true)
```

### Contract — This Service MUST NOT

- Consume inventory
- Create ledger entries
- Write database records
- Dispatch jobs or events
- Update costs or perform procurement
- Call ExecutionPipeline or Executor (those are PKG-05A / PKG-05B)

### New Files

| File | Description |
|------|-------------|
| `Modules/Manufacturing/ManufacturingWorkflow/Domain/Enums/WorkflowStage.php` | `DecisionEvaluated`, `AvailabilityAnalysed`, `PlanProduced` |
| `Modules/Manufacturing/ManufacturingWorkflow/Domain/Enums/WorkflowBlockingReason.php` | 8 typed reasons with `fromDecisionType()` + `fromEligibility()` factory methods |
| `Modules/Manufacturing/ManufacturingWorkflow/Domain/ValueObjects/ManufacturingWorkflowRequest.php` | Immutable input: `product_id`, `warehouse_id`, `required_qty`, `trigger`, `metadata` |
| `Modules/Manufacturing/ManufacturingWorkflow/Domain/ValueObjects/ManufacturingWorkflowResult.php` | Immutable output carrying all engine results + `isPlanReady()` |
| `Modules/Manufacturing/ManufacturingWorkflow/Domain/Services/ManufacturingWorkflow.php` | Core coordinator service |
| `Modules/Manufacturing/ManufacturingWorkflow/Infrastructure/Providers/ManufacturingWorkflowServiceProvider.php` | Singleton wiring |

### Modified Files

| File | Change |
|------|--------|
| `bootstrap/providers.php` | Added `ManufacturingWorkflowServiceProvider` |

### Test Coverage

| Scenario | Test |
|----------|------|
| Successful plan ready | `test_successful_workflow_returns_plan_ready_result` |
| All engine outputs present | `test_successful_workflow_result_carries_all_engine_outputs` |
| Workflow ID is UUID v4 | `test_successful_workflow_result_carries_workflow_id` |
| Completed-at is ISO 8601 | `test_successful_workflow_result_has_completed_at_timestamp` |
| Decision reject → blocked | `test_decision_reject_returns_blocked_at_decision_stage` |
| Decision defer → deferred | `test_decision_defer_returns_deferred_blocking_reason` |
| Rejected: no availability/plan called | `test_decision_blocked_does_not_call_availability_or_planner` |
| Rejected: decision_result present | `test_decision_blocked_still_carries_decision_result` |
| No recipe → RecipeNotFound | `test_no_recipe_blocks_at_decision_stage_with_recipe_not_found` |
| Short components → CannotManufacture | `test_cannot_manufacture_returns_blocked_at_availability_stage` |
| Availability blocked: result present | `test_availability_blocked_carries_availability_result` |
| Availability blocked: no plan | `test_availability_blocked_does_not_produce_plan` |
| Sufficient FG → NotNeeded | `test_sufficient_fg_stock_returns_manufacturing_not_needed` |
| NotNeeded: plan present, should_manufacture=false | `test_manufacturing_not_needed_still_carries_plan` |
| Caller metadata propagated | `test_caller_metadata_propagated_to_result` |
| workflow_id injected into plan | `test_workflow_id_injected_into_plan_metadata` |
| Snapshot from orchestrator in result | `test_recipe_snapshot_from_orchestrator_in_result` |
| Plan snapshot matches result snapshot | `test_plan_carries_same_recipe_snapshot_as_workflow_result` |
| Snapshot present even when blocked by decision | `test_snapshot_carried_into_blocked_by_decision_result` |
| Plan carries correct product/warehouse | `test_plan_carries_correct_product_and_warehouse` |
| Availability result in success | `test_availability_result_carried_into_successful_result` |
| toArray() shape | `test_result_toArray_has_expected_keys` |

### Key Design Decisions

| Decision | Rationale |
|----------|-----------|
| Never throws for business outcomes | All blocking conditions return typed `ManufacturingWorkflowResult` |
| First-block-wins sequential execution | Prevents calling downstream engines unnecessarily |
| Snapshot resolved by orchestrator | Orchestrator resolves recipe → Workflow carries snapshot forward into all stages |
| Workflow ID propagated to plan metadata | Enables full trace from plan → workflow → trigger without extra DB columns |
| `isPlanReady()` encapsulates the check | Callers never need to inspect `should_manufacture` + `is_blocked` manually |

---

## PKG-05B — Manufacturing Executor

**Status:** ✅ Completed
**Date:** 2026-06-29
**Commit:** (this batch)
**Tests:** 25 feature tests

### Purpose

PKG-05B is the **first and only component** allowed to mutate the inventory database during manufacturing. It receives a fully-validated `ManufacturingExecutionContext` from PKG-05A and executes it in a single atomic transaction:

```
ManufacturingExecutionContext (from PKG-05A Pipeline)
  ↓
ManufacturingExecutor.execute(context, companyId)
  ├── Guard: context.isValid()
  ├── Idempotency check: findByPlanId()
  └── DB::transaction()
       ├── InventoryMutationInterface.consumeComponent() × N
       │     ├── InventoryItem: on_hand_qty -= qty        (SELECT FOR UPDATE)
       │     ├── StockLedgerEntry: ProductionConsumption  (immutable)
       │     └── InventoryLayerConsumption × M            (FIFO audit)
       ├── InventoryMutationInterface.produceFinishedGoods()
       │     ├── InventoryItem: on_hand_qty += qty        (SELECT FOR UPDATE)
       │     └── StockLedgerEntry: ProductionOutput       (immutable)
       └── ManufacturingTransaction INSERT               (source of truth)
↓
ManufacturingExecutionResult
```

### Architectural Refinements (CTO Review)

#### 1. InventoryMutationInterface
The executor never calls inventory repositories directly. A thin application contract:
- `consumeComponent()` → `ComponentConsumptionRecord`
- `produceFinishedGoods()` → `string` (ledger entry ID)

`InventoryMutationAdapter` implements the interface and internally reuses `InventoryItemRepositoryInterface` + `InventoryLayerConsumptionService`. Future modules (Disassembly, Transfers, Adjustments) implement the same interface.

#### 2. ManufacturingTransaction as Source of Truth
The transaction record now carries all execution identifiers:
- `execution_id` (= `context.execution_uuid`)
- `decision_key` (SHA-256 content-addressed, cross-plan idempotency)
- `correlation_id` (distributed trace ID across all services)
- `recipe_snapshot_hash` (integrity anchor)

Future AI, auditing, and replay systems read only this table.

#### 3. Lifecycle Hooks
`ManufacturingExecutorHooksInterface` defines five extension points:
- `onBeforeExecution` — before any DB writes
- `onAfterInventoryConsumption` — inside transaction, all components consumed
- `onAfterFinishedGoodsCreated` — inside transaction, FG produced
- `onAfterCommit` — after successful commit (safe for events/jobs)
- `onAfterRollback` — after any failure (logging, cleanup)

No hooks registered by default. Future integrations (Cost Engine, Procurement Queue, AI Analytics) inject implementations via the service provider.

### New Files

| File | Description |
|------|-------------|
| `Domain/Contracts/InventoryMutationInterface.php` | `consumeComponent()` + `produceFinishedGoods()` contract |
| `Domain/Contracts/ManufacturingExecutorHooksInterface.php` | 5 lifecycle extension points |
| `Infrastructure/Adapters/InventoryMutationAdapter.php` | Reuses `InventoryItemRepository` + `InventoryLayerConsumptionService` |
| `Infrastructure/Database/Migrations/2026_06_29_000004_add_execution_identifiers_to_manufacturing_transactions.php` | Adds `decision_key` + `correlation_id` columns |

### Modified Files

| File | Change |
|------|--------|
| `Application/Services/ManufacturingExecutor.php` | Refactored to accept `ManufacturingExecutionContext`; injects `InventoryMutationInterface`; adds hook calls |
| `Domain/Exceptions/ExecutionException.php` | Added `INVALID_CONTEXT` reason + `invalidContext()` factory |
| `Domain/Models/ManufacturingTransaction.php` | Added `decision_key`, `correlation_id` to `$fillable` |
| `Infrastructure/Providers/ManufacturingExecutionServiceProvider.php` | Binds `InventoryMutationInterface → InventoryMutationAdapter`; explicit executor singleton |
| `tests/Feature/Manufacturing/ManufacturingExecutorTest.php` | Rewritten with 25 tests using `ManufacturingExecutionContext` as input |

### Transaction Boundary

| Stage | Inside Transaction | Locks Held |
|-------|-------------------|------------|
| Context guard, idempotency check | ✗ | None |
| `consumeComponent()` × N | ✓ | InventoryItem (FOR UPDATE) + InventoryReceiptLayer (FOR UPDATE) |
| `produceFinishedGoods()` | ✓ | InventoryItem (FOR UPDATE) |
| `ManufacturingTransaction` INSERT | ✓ | UNIQUE(plan_id) constraint |
| onAfterCommit hook | ✗ | None |

Any exception inside the transaction triggers automatic rollback of all inventory, ledger, FIFO layer, and transaction changes.

### Idempotency Strategy

Three independent guards:

| Guard | Mechanism |
|-------|-----------|
| Context-level | `context.isValid()` — pipeline's `AlreadyExecuted` failure prevents execution |
| Application-level | `findByPlanId()` before `DB::transaction()` → returns idempotent result |
| Database-level | `UNIQUE(plan_id)` on `manufacturing_transactions` — catches concurrent races |

### FIFO Reuse Strategy

`InventoryLayerConsumptionService::consume()` is called inside the same transaction:
- Loads receipt layers with `lockForUpdate()` ordered by `created_at ASC`
- Decrements `InventoryReceiptLayer.remaining_qty` for each consumed slice
- Creates immutable `InventoryLayerConsumption` audit records per layer

**Negative-stock RC-2 handling:** When `allow_negative_stock = true` and on-hand is insufficient:
- `InventoryItem.on_hand_qty` decrements below zero (standard behavior)
- FIFO layers are consumed up to `min(qty_to_consume, available_in_layers)` (partial)
- `ComponentConsumptionRecord.went_negative = true` flags the event

### Test Coverage (25 tests)

| Group | Tests |
|-------|-------|
| Successful execution | Stock decrement, FG increment, ledger entries, transaction record, result structure, FG item creation, multi-component |
| Context identifier propagation | `execution_uuid → execution_id`, `decision_key` in TX, `correlation_id` in TX, `bom_version_number` (RC-10) |
| FIFO layer consumption | Audit records created, `remaining_qty` decremented, FIFO order (multi-layer) |
| Negative stock (RC-2) | Goes below zero, partial FIFO consume, no-layers silent skip |
| Idempotency | Duplicate returns idempotent result, no double-consume, idempotent UUID from context |
| Rollback | Inventory restored, FIFO layers restored |
| Invalid context guard | Throws `ExecutionException::INVALID_CONTEXT`, no DB writes |
| Lifecycle hooks | Correct order on success, rollback hook called + exception propagates, null hooks ok |

### Performance Notes

- All `InventoryItem` locks acquired in component array order → deterministic, deadlock-free
- FIFO sum query (`lockForUpdate`) runs once per component before `consume()` — one extra read per component is acceptable vs exception-control-flow
- `ManufacturingTransaction` INSERT is the last write inside the transaction, minimizing lock hold time on the unique constraint

---

## PKG-06A — Manufacturing Application Service

**Status:** ✅ Completed
**Date:** 2026-06-29
**Task:** TASK-MFG-IMP-006A
**Tests:** 18 feature tests

### Purpose

The Manufacturing Application Service is the **single public API** for the entire Manufacturing domain.
No module may call Workflow, Pipeline, Executor, Availability Engine, Planner, or Decision Orchestrator directly.
Every manufacturing entry point goes through this service.

```
Caller (Orders / POS / CLI / API Controller)
    ↓
ManufacturingApplicationService
    ├── manufactureProduct()    → Workflow → Pipeline → Executor → ManufactureProductResponse
    ├── simulateManufacturing() → Workflow only → SimulateManufacturingResponse   (no mutations)
    ├── validateManufacturing() → Workflow + Pipeline → ValidateManufacturingResponse (no mutations)
    └── disassembleProduct()    → DisassembleProductResponse (placeholder, not yet implemented)
```

### Contract — This Service MUST NOT

- Contain business rules (they live in the domain engines)
- Call Workflow / Pipeline / Executor / Availability Engine / Planner directly from other modules
- Integrate with Orders, POS, Scheduler, or API Controllers (deferred to PKG-06B+)
- Dispatch Laravel Events (reserved for PKG-06B)

### Files Created (9)

#### Application/DTOs/Requests (4)
| File | Purpose |
|------|---------|
| `ManufacturingService/Application/DTOs/Requests/ManufactureProductRequest.php` | Full execution request: product, warehouse, company, qty, actor, trigger |
| `ManufacturingService/Application/DTOs/Requests/SimulateManufacturingRequest.php` | Dry-run request: same as manufacture but no company_id (no execution) |
| `ManufacturingService/Application/DTOs/Requests/ValidateManufacturingRequest.php` | Pre-flight validation: workflow + pipeline, no execution |
| `ManufacturingService/Application/DTOs/Requests/DisassembleProductRequest.php` | Placeholder: product, warehouse, quantity, actor |

#### Application/DTOs/Responses (4)
| File | Purpose |
|------|---------|
| `ManufacturingService/Application/DTOs/Responses/ManufactureProductResponse.php` | `blocked()` + `fromExecution()` factories; carries all execution fields + workflow context |
| `ManufacturingService/Application/DTOs/Responses/SimulateManufacturingResponse.php` | `fromWorkflow()` factory; carries plan details, components, negative-stock risks |
| `ManufacturingService/Application/DTOs/Responses/ValidateManufacturingResponse.php` | `blocked()` + `fromPipeline()` factories; workflow validity + pipeline failures |
| `ManufacturingService/Application/DTOs/Responses/DisassembleProductResponse.php` | Placeholder: `implemented=false`, descriptive message |

#### Application/Services (1)
| File | Purpose |
|------|---------|
| `ManufacturingService/Application/Services/ManufacturingApplicationService.php` | Coordinator: `buildWorkflowRequest()` helper, `generateUuid()` for trigger_id; no business rules |

#### Infrastructure/Providers (1)
| File | Purpose |
|------|---------|
| `ManufacturingService/Infrastructure/Providers/ManufacturingServiceProvider.php` | Singleton: `ManufacturingApplicationService` wired with Workflow + Pipeline + Executor |

#### Tests (1)
| File | Purpose |
|------|---------|
| `tests/Feature/Manufacturing/ManufacturingApplicationServiceTest.php` | 18 feature tests; RefreshDatabase; singleton reset per test |

### Files Modified (1)

| File | Change |
|------|--------|
| `backend/bootstrap/providers.php` | Added `ManufacturingServiceProvider` after `ManufacturingWorkflowServiceProvider` |

### Test Coverage (18 tests)

| Group | Tests |
|-------|-------|
| `manufactureProduct` | Happy path (response shape, DB transaction, inventory decrement), decision rejected, manufacturing not needed, no recipe, DTO toArray |
| `simulateManufacturing` | Returns plan without executing, blocked when rejected, component details in response, DTO toArray |
| `validateManufacturing` | Valid report when plan ready, blocked when workflow blocked, no transaction created, DTO toArray |
| `disassembleProduct` | Placeholder response, no DB records, DTO toArray |

### Design Decisions

| Concern | Decision |
|---------|----------|
| Trigger ID generation | Application Service generates a fresh UUID v4 when `trigger_id` is null; callers may provide their own |
| Trigger version | Always `1` for manual triggers — callers that need replay semantics provide an explicit `trigger_id` |
| Blocking responses | All outcomes use the same typed Response DTO; callers check `is_blocked` / `was_executed` / `implemented` |
| Simulation guarantee | `simulateManufacturing()` never calls the Executor; safe to call repeatedly with zero side effects |
| Validation guarantee | `validateManufacturing()` never calls the Executor; returns two-layer validity (workflow + pipeline) |

---

## PKG-06B — Manufacturing Policy

**Status:** ✅ Completed
**Date:** 2026-06-29
**Task:** TASK-MFG-IMP-006B
**Tests:** 28 unit tests (pure domain, no DB)

### Purpose

The Manufacturing Policy is a **pure domain eligibility gate**. It answers one question:
"Is this order/product combination eligible to proceed to manufacturing?"

It performs no execution, no inventory operations, no planning, and no DB writes.
The caller decides whether to invoke `ManufacturingApplicationService` based on the result.

```
Caller
    ↓
ManufacturingPolicy.evaluate(request, order, product)
    ├── Rule 1: Order not cancelled              → OrderCancelled
    ├── Rule 2: Order status allows mfg          → OrderStatusNotAllowed
    ├── Rule 3: Product can manufacture          → ProductCannotManufacture
    ├── Rule 4: Recipe exists                    → RecipeNotFound
    ├── Rule 5: Product is inventory-managed     → ProductNotInventoryManaged
    ├── Rule 6: Manufacturing required (qty > 0) → ManufacturingNotRequired
    └── Rule 7: Not already manufactured         → AlreadyManufactured
    ↓
ManufacturingPolicyResult { eligible, reason, policy_code, metadata }
```

### CONTRACT — This Service MUST NOT

- Call ManufacturingApplicationService, Executor, or Planner
- Consume inventory or update any database record
- Dispatch jobs or events
- Invoke ManufacturingApplicationService itself — the caller decides

### Eligibility Matrix

| Rule | Evaluated Field | Ineligible Code | Notes |
|------|----------------|-----------------|-------|
| Order not cancelled | `order.is_cancelled` | `order_cancelled` | Checked first; supersedes all |
| Order status | `order.order_status` in `[pending, processing]` | `order_status_not_allowed` | `completed` and `cancelled` blocked |
| Product can manufacture | `product.can_manufacture` | `product_cannot_manufacture` | Product model flag |
| Recipe exists | `product.has_active_recipe` | `recipe_not_found` | Caller pre-checks BOM table |
| Inventory managed | `product.is_inventory_managed` | `product_not_inventory_managed` | Caller derives from product type |
| Manufacturing required | `request.required_qty > 0` | `manufacturing_not_required` | Gross qty check only; RC-1 is Workflow's job |
| Not already manufactured | `order.already_manufactured` | `already_manufactured` | Caller pre-checks ManufacturingTransaction |

### Design Decisions

| Concern | Decision |
|---------|----------|
| Decoupled from Commerce module | `OrderContext` carries `order_status: string` — policy checks against `['pending', 'processing']`; no `OrderStatus` enum import |
| Decoupled from Inventory module | `ProductContext` carries pre-computed booleans — no Product model import |
| Short-circuit at first failure | Returns on first ineligible rule; callers see the most important reason |
| Caller pre-computes DB state | `already_manufactured` and `has_active_recipe` require DB reads; caller does them before building context |
| Pure PHP value objects | No framework dependency; singleton service registered but has no constructor args |

### Files Created (8)

#### Domain/Enums (1)
| File | Purpose |
|------|---------|
| `ManufacturingPolicy/Domain/Enums/PolicyCode.php` | 8-case enum: `Eligible` + 7 ineligible codes; `isEligible()`, `label()` |

#### Domain/ValueObjects (4)
| File | Purpose |
|------|---------|
| `ManufacturingPolicy/Domain/ValueObjects/ManufacturingPolicyRequest.php` | Intent: `product_id`, `required_qty`, `actor_id`, `metadata` |
| `ManufacturingPolicy/Domain/ValueObjects/OrderContext.php` | Order state: `order_id`, `order_line_id`, `order_status`, `is_cancelled`, `already_manufactured` |
| `ManufacturingPolicy/Domain/ValueObjects/ProductContext.php` | Product capability: `product_id`, `can_manufacture`, `has_active_recipe`, `is_inventory_managed` |
| `ManufacturingPolicy/Domain/ValueObjects/ManufacturingPolicyResult.php` | `eligible()` + `ineligible()` factories; `toArray()` |

#### Domain/Services (1)
| File | Purpose |
|------|---------|
| `ManufacturingPolicy/Domain/Services/ManufacturingPolicy.php` | Core evaluator: 7 rules in priority order; `MANUFACTURING_ALLOWED_STATUSES = ['pending', 'processing']` |

#### Infrastructure/Providers (1)
| File | Purpose |
|------|---------|
| `ManufacturingPolicy/Infrastructure/Providers/ManufacturingPolicyServiceProvider.php` | Singleton registration (no constructor args) |

#### Tests (1)
| File | Purpose |
|------|---------|
| `tests/Unit/Manufacturing/ManufacturingPolicyTest.php` | 28 pure unit tests; `PHPUnit\Framework\TestCase`; no DB |

### Files Modified (1)

| File | Change |
|------|--------|
| `backend/bootstrap/providers.php` | Added `ManufacturingPolicyServiceProvider` after `ManufacturingServiceProvider` |

### Test Coverage (28 tests)

| Group | Tests |
|-------|-------|
| Happy path | All rules pass (eligible), `pending` status, `processing` status |
| Rule 1 (Order cancelled) | Ineligible, supersedes all other failures |
| Rule 2 (Status not allowed) | `completed`, unknown status, fires after rule 1 |
| Rule 3 (Cannot manufacture) | Ineligible, fires after rule 2 |
| Rule 4 (No recipe) | Ineligible, fires after rule 3 |
| Rule 5 (Not inventory managed) | Ineligible, fires after rule 4 |
| Rule 6 (Qty = 0 or negative) | Zero qty, negative qty, fires after rule 5 |
| Rule 7 (Already manufactured) | Ineligible, fires last |
| Result structure | `eligible()` factory, `ineligible()` factory, `toArray()`, metadata keys |
| PolicyCode enum | Only `Eligible` passes `isEligible()`, all codes have non-empty labels |

### Future Rules

| Rule | PolicyCode | When to Add |
|------|-----------|-------------|
| Warehouse capacity check | `WarehouseCapacityExceeded` | When warehouse capacity planning is implemented |
| Operator approved required | `PendingApproval` | When approval workflows are added |
| Component reservation required | `ComponentsNotReserved` | When pre-reservation becomes mandatory |
| Manufacturing window check | `OutsideManufacturingWindow` | When Operational Day boundary (ADR-012) gates manufacturing |
| Machine availability | `MachineUnavailable` | When production scheduling is added (Operations module) |

---

## PKG-07A — Order Lifecycle Coordinator

**Status:** ✅ Completed
**Date:** 2026-06-29
**Task:** TASK-ORD-IMP-001
**Tests:** 20 feature tests

### Purpose

The Order Lifecycle Coordinator is the **single integration point** between the Orders domain and all
operational domains. Orders never call `ManufacturingApplicationService` directly. Orders notify the
coordinator, which evaluates policies and invokes application services on their behalf.

```
Orders module
    ↓
OrderLifecycleCoordinator.handle(OrderLifecycleRequest)
    ├── Step 1: shouldEvaluateManufacturing(status)?
    │     No → OrderLifecycleResult::statusIgnored  (policy never runs)
    │
    ├── Step 2: ManufacturingPolicy.evaluate(request, order, product)
    │     Ineligible → OrderLifecycleResult::policyRejected  (manufacturing never runs)
    │
    └── Step 3: ManufacturingApplicationService.manufactureProduct(...)
          is_blocked=true  → OrderLifecycleResult::manufacturingBlocked  (handled=false)
          is_blocked=false → OrderLifecycleResult::manufacturingTriggered (handled=true)
```

### CONTRACT — This Coordinator MUST NOT

- Execute manufacturing logic directly
- Read recipes, BOM tables, or inventory
- Build manufacturing plans
- Dispatch shipping or notifications
- Update accounting records
- Be called by any domain other than Orders (and future order-adjacent events)

### Integration Architecture

| Layer | Component | Responsibility |
|-------|-----------|----------------|
| Caller | Orders module / future triggers | Build `OrderLifecycleRequest` with pre-computed flags |
| Gate | `shouldEvaluateManufacturing(status)` | Quick status check before policy (avoids policy overhead for completed/cancelled) |
| Policy | `ManufacturingPolicy.evaluate()` | 7-rule eligibility check; pure domain; no DB writes |
| Execution | `ManufacturingApplicationService.manufactureProduct()` | Full Workflow → Pipeline → Executor chain |
| Result | `OrderLifecycleResult` | Typed outcome; callers check `handled` + `action` |

### Lifecycle Actions

| `LifecycleAction` | `handled` | Condition | Notes |
|-------------------|-----------|-----------|-------|
| `status_ignored` | false | `order_status` not in `[pending, processing]` | Policy never runs; manufacturing_result=null |
| `policy_rejected` | false | Policy returns ineligible | Specific PolicyCode in policy_result; manufacturing_result=null |
| `manufacturing_triggered` | true | Policy eligible + workflow executed | Transaction in DB; full manufacturing_result |
| `manufacturing_blocked` | false | Policy eligible + workflow blocked | No transaction; manufacturing_result carries blocking_reason |

### Decoupling Strategy

The coordinator bridges Commerce and Manufacturing without creating module-level imports:

| Concern | Decision |
|---------|----------|
| No `OrderStatus` enum import | `OrderLifecycleRequest.order_status: string`; coordinator checks against `['pending', 'processing']` |
| No `Product` model import | `product_can_manufacture`, `product_has_active_recipe`, `product_is_inventory_managed` are pre-computed booleans from caller |
| No `ManufacturingTransaction` query | `already_manufactured: bool` pre-computed by caller before building request |
| `trigger_type: 'order_lifecycle'` | Set by coordinator; unambiguous source for manufacturing transaction audit trail |
| `trigger_id: order_line_id` | Order line ID used as trigger_id; provides end-to-end traceability without extra columns |

### Extension Pattern

Future packages add operational domains by adding **private handler methods** without changing the public contract:

```php
public function handle(OrderLifecycleRequest $request): OrderLifecycleResult
{
    $result = $this->handleManufacturing($request);
    // PKG-07B: $result = $this->handleShipping($result, $request);
    // Future: $result = $this->handleCRM($result, $request);
    // Future: $result = $this->handleNotifications($result, $request);
    return $result;
}
```

### Files Created (5)

#### Domain/Enums (1)
| File | Purpose |
|------|---------|
| `Operations/OrderLifecycle/Domain/Enums/LifecycleAction.php` | 4-case enum: `StatusIgnored`, `PolicyRejected`, `ManufacturingTriggered`, `ManufacturingBlocked`; `isManufacturingComplete()`, `isNoOp()` |

#### Application/DTOs (2)
| File | Purpose |
|------|---------|
| `Operations/OrderLifecycle/Application/DTOs/OrderLifecycleRequest.php` | Immutable input: all order + product + company fields pre-computed by caller |
| `Operations/OrderLifecycle/Application/DTOs/OrderLifecycleResult.php` | Immutable output: 4 static factories (`statusIgnored`, `policyRejected`, `manufacturingTriggered`, `manufacturingBlocked`); `toArray()` |

#### Application/Services (1)
| File | Purpose |
|------|---------|
| `Operations/OrderLifecycle/Application/Services/OrderLifecycleCoordinator.php` | Core coordinator: `handle()` + `handleManufacturing()` + `shouldEvaluateManufacturing()`; extension-point comments |

#### Infrastructure/Providers (1)
| File | Purpose |
|------|---------|
| `Operations/OrderLifecycle/Infrastructure/Providers/OrderLifecycleServiceProvider.php` | Singleton `OrderLifecycleCoordinator` wired with `ManufacturingPolicy` + `ManufacturingApplicationService` |

### Files Modified (1)

| File | Change |
|------|--------|
| `backend/bootstrap/providers.php` | Added `OrderLifecycleServiceProvider` after `ManufacturingPolicyServiceProvider` |

### Test Coverage (20 feature tests)

| Group | Tests |
|-------|-------|
| Manufacturing triggered | `pending` order, `processing` order, transaction in DB |
| Policy rejected | `can_manufacture=false`, `has_active_recipe=false`, `is_inventory_managed=false`, `already_manufactured=true`, reason propagation |
| Manufacturing blocked | Workflow blocked (Reject rule), blocking reason in result |
| Status ignored | `completed`, `cancelled`, unknown status; policy and result both null |
| Context mapping | Policy metadata has `product_id`/`order_id`/`order_line_id`, manufacturing `source_metadata` carries order context |
| Result structure invariants | `handled=false` for all non-triggered outcomes, `toArray()` keys, null sub-results for ignored |

### Key Design Decisions

| Decision | Rationale |
|----------|-----------|
| `MANUFACTURING_TRIGGER_STATUSES` constant at coordinator level | Coordinator has its own status gate independent of the policy's `MANUFACTURING_ALLOWED_STATUSES` — coordinator short-circuits before policy even runs |
| `handled = true` only for `ManufacturingTriggered` | Callers need a single boolean to know if manufacturing completed; all other outcomes are no-ops from the caller's perspective |
| Manufacturing metadata nested under `source_metadata` | The executor's `buildTransactionMetadata()` wraps plan metadata in `source_metadata`; tests are written against actual execution behavior, not assumptions |
| Coordinator in `Modules/Operations/` not `Modules/Commerce/` | It coordinates across multiple operational domains (Manufacturing + future Shipping, CRM); belongs to Operations, not to Commerce/Orders |

---

## PKG-08 through PKG-11 — Pending

See [ARCHITECTURE-FREEZE.md](ARCHITECTURE-FREEZE.md) §7 for implementation package order and dependencies.
