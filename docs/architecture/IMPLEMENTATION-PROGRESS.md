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

## PKG-06 through PKG-11 — Pending

See [ARCHITECTURE-FREEZE.md](ARCHITECTURE-FREEZE.md) §7 for implementation package order and dependencies.
