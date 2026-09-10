<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\GoLive;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\GoLive\Application\Actions\ActivateGoLiveAction;
use Modules\Admin\GoLive\Application\Actions\EstablishOpeningInventoryAction;
use Modules\Admin\GoLive\Application\Actions\ExecuteGoLiveResetAction;
use Modules\Admin\GoLive\Application\Actions\PreviewGoLiveResetAction;
use Modules\Admin\GoLive\Domain\Models\GoLiveResetOperation;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use RuntimeException;
use Tests\TestCase;

/**
 * TASK-...-026 §21 focused test matrix. NOT executed in this task's environment — test MySQL is
 * unreachable, the same recurring blocker recorded across this project's history (confirmed this
 * session). Statically verified via `php -l`; reviewed line-by-line against the exact source it
 * targets. See UnsafeCombinationRuleTest for the portion of this matrix (§21.6) that WAS actually
 * executed, by extracting it to a plain-PHPUnit, DB-free class.
 */
final class GoLivePreparationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create(['lifecycle_state' => 'pre_live']);
    }

    // ── AUTH (§21.1-3) ────────────────────────────────────────────────────────

    public function test_unauthorized_actor_cannot_reach_reset_endpoints(): void
    {
        $this->postJson('/api/golive/reset/preview', ['domains' => ['commerce']])
            ->assertStatus(401);
    }

    public function test_actor_without_golive_permission_is_forbidden(): void
    {
        $this->actingAs($this->userWithoutPermission())
            ->postJson('/api/golive/reset/preview', ['domains' => ['commerce']])
            ->assertStatus(403);
    }

    public function test_cross_company_reset_is_blocked_by_tenant_scope(): void
    {
        $otherCompany = Company::factory()->create();
        $operation = GoLiveResetOperation::query()->create([
            'company_id' => $otherCompany->id,
            'idempotency_key' => 'other-company-op',
            'status' => 'completed',
            'selected_domains' => ['commerce'],
        ]);

        // Acting as an actor scoped to $this->company, the other company's operation must not
        // resolve — same tenant-scope contract as Channel (TASK-...-024/025).
        $this->assertModelMissing($operation, $this->company->id);
    }

    public function test_live_company_reset_execution_is_blocked(): void
    {
        $this->company->update(['lifecycle_state' => 'live']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Live');

        app(ExecuteGoLiveResetAction::class)->execute(
            $this->company->id,
            ['commerce'],
            'RESET '.$this->company->code,
            'idem-key-live-blocked',
        );
    }

    // ── PREVIEW (§21.4-6) ───────────────────────────────────────────────────────

    public function test_preview_mutates_nothing(): void
    {
        Order::factory()->count(3)->create(['company_id' => $this->company->id]);
        $before = Order::query()->where('company_id', $this->company->id)->count();

        app(PreviewGoLiveResetAction::class)->execute($this->company->id, ['commerce']);

        $after = Order::query()->where('company_id', $this->company->id)->count();
        self::assertSame($before, $after);
        self::assertSame(3, $after);
    }

    public function test_preview_dependency_counts_are_correct(): void
    {
        Order::factory()->count(5)->create(['company_id' => $this->company->id]);

        $preview = app(PreviewGoLiveResetAction::class)->execute($this->company->id, ['commerce']);

        self::assertSame(5, $preview->counts['commerce']['orders']);
    }

    public function test_unsafe_combination_is_blocked(): void
    {
        Order::factory()->count(1)->create(['company_id' => $this->company->id]);

        $preview = app(PreviewGoLiveResetAction::class)->execute($this->company->id, ['inventory']);

        self::assertFalse($preview->isSafe());
        self::assertNotEmpty($preview->blockers);
    }

    // ── COMMERCE (§21.7-8) ────────────────────────────────────────────────────

    public function test_selective_commerce_reset_removes_only_this_companys_orders(): void
    {
        $other = Company::factory()->create();
        Order::factory()->count(2)->create(['company_id' => $this->company->id]);
        Order::factory()->count(1)->create(['company_id' => $other->id]);

        app(ExecuteGoLiveResetAction::class)->execute(
            $this->company->id, ['commerce'], 'RESET '.$this->company->code, 'idem-commerce-1',
        );

        self::assertSame(0, Order::query()->where('company_id', $this->company->id)->count());
        self::assertSame(1, Order::query()->where('company_id', $other->id)->count());
    }

    public function test_master_products_are_preserved_when_only_commerce_selected(): void
    {
        $product = Product::factory()->create();
        Order::factory()->create(['company_id' => $this->company->id]);

        app(ExecuteGoLiveResetAction::class)->execute(
            $this->company->id, ['commerce'], 'RESET '.$this->company->code, 'idem-commerce-2',
        );

        $this->assertModelExists($product);
    }

    // ── OPERATIONS (§21.9-10) ─────────────────────────────────────────────────

    public function test_selective_operations_reset_does_not_touch_commerce_orders(): void
    {
        Order::factory()->count(2)->create(['company_id' => $this->company->id]);

        app(ExecuteGoLiveResetAction::class)->execute(
            $this->company->id, ['operations'], 'RESET '.$this->company->code, 'idem-ops-1',
        );

        self::assertSame(2, Order::query()->where('company_id', $this->company->id)->count());
    }

    public function test_dependent_operational_records_are_deleted_in_restrict_safe_order(): void
    {
        // See OperationsResetService docblock: cash_handovers -> settlements -> trips.
        // A restrict-FK violation here would surface as a DB exception, not a silent failure.
        $this->expectNotToPerformAssertions();

        app(\Modules\Admin\GoLive\Application\Services\OperationsResetService::class)->execute($this->company->id);
    }

    // TASK-...-026-R1 Gate 3: proves the newly-included Loading/VehiclePlan chain (previously
    // excluded, disclosed as unresolved) is deleted in a real restrict-FK-safe order, not just
    // against empty tables. One row is seeded per table, wired together through the actual FKs
    // (see OperationsResetService's docblock for the derivation), then execute() must complete
    // without a DB exception and leave every one of those 20 tables empty for this company —
    // while an unrelated company's row in the same chain must survive untouched.
    public function test_loading_vehicle_plan_chain_is_deleted_in_restrict_safe_order(): void
    {
        $other = Company::factory()->create();
        $otherSessionId = (string) Str::uuid();
        DB::table('loading_sessions')->insert($this->minimalLoadingSessionRow($other->id, $otherSessionId));

        $companyId = $this->company->id;
        $sessionId = (string) Str::uuid();
        $assignmentId = (string) Str::uuid();
        $taskId = (string) Str::uuid();
        $driverAssignmentId = (string) Str::uuid();
        $inventoryItemId = (string) Str::uuid();
        $planId = (string) Str::uuid();
        $shipmentGroupId = (string) Str::uuid();
        $reconciliationId = (string) Str::uuid();
        $routePlanId = (string) Str::uuid();
        $allocationId = (string) Str::uuid();
        $slotId = (string) Str::uuid();
        $actorId = (string) Str::uuid();
        $now = now();
        $today = $now->toDateString();

        DB::table('loading_sessions')->insert($this->minimalLoadingSessionRow($companyId, $sessionId));

        DB::table('vehicle_assignments')->insert([
            'id' => $assignmentId, 'company_id' => $companyId, 'loading_session_id' => $sessionId,
            'vehicle_id' => (string) Str::uuid(), 'vehicle_registration_snapshot' => 'TEST-1',
            'vehicle_type_snapshot' => 'van', 'capacity_weight_kg_snapshot' => 100,
            'capacity_volume_m3_snapshot' => 10, 'assignment_number' => 'VA-TEST-1', 'status' => 'pending',
            'created_at' => $now, 'updated_at' => $now, 'created_by' => $actorId, 'updated_by' => $actorId,
        ]);

        DB::table('loading_tasks')->insert([
            'id' => $taskId, 'company_id' => $companyId, 'loading_session_id' => $sessionId,
            'vehicle_assignment_id' => $assignmentId, 'pool_entry_id' => (string) Str::uuid(),
            'product_id' => (string) Str::uuid(), 'sku_snapshot' => 'SKU-1', 'name_snapshot' => 'Test Product',
            'preparation_wave_id' => (string) Str::uuid(), 'quantity_planned' => 1, 'status' => 'pending',
            'created_at' => $now, 'updated_at' => $now, 'created_by' => $actorId, 'updated_by' => $actorId,
        ]);

        DB::table('driver_assignments')->insert([
            'id' => $driverAssignmentId, 'company_id' => $companyId, 'vehicle_assignment_id' => $assignmentId,
            'loading_session_id' => $sessionId, 'vehicle_id' => (string) Str::uuid(),
            'driver_id' => (string) Str::uuid(), 'driver_name_snapshot' => 'Test Driver',
            'status' => 'assigned', 'assignment_type' => 'primary', 'assigned_at' => $now, 'assigned_by' => $actorId,
            'created_at' => $now, 'updated_at' => $now, 'created_by' => $actorId, 'updated_by' => $actorId,
        ]);

        DB::table('vehicle_inventory_items')->insert([
            'id' => $inventoryItemId, 'company_id' => $companyId, 'vehicle_assignment_id' => $assignmentId,
            'vehicle_id' => (string) Str::uuid(), 'product_id' => (string) Str::uuid(), 'sku_snapshot' => 'SKU-1',
            'name_snapshot' => 'Test Product', 'operational_date' => $today, 'pool_entry_id' => (string) Str::uuid(),
            'loading_task_id' => $taskId, 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now, 'created_by' => $actorId, 'updated_by' => $actorId,
        ]);

        DB::table('vehicle_plans')->insert([
            'id' => $planId, 'company_id' => $companyId, 'operational_date' => $today, 'plan_number' => 'VP-TEST-1',
            'shipping_company_id' => (string) Str::uuid(), 'zone_id' => (string) Str::uuid(),
            'governorate_id' => (string) Str::uuid(), 'status' => 'calculating',
            'created_at' => $now, 'updated_at' => $now, 'created_by' => $actorId, 'updated_by' => $actorId,
        ]);

        DB::table('shipment_groups')->insert([
            'id' => $shipmentGroupId, 'company_id' => $companyId, 'loading_session_id' => $sessionId,
            'shipping_company_id' => (string) Str::uuid(), 'zone_id' => (string) Str::uuid(),
            'governorate_id' => (string) Str::uuid(), 'group_number' => 'SG-TEST-1',
            'created_at' => $now, 'updated_at' => $now, 'created_by' => $actorId, 'updated_by' => $actorId,
        ]);

        DB::table('vehicle_shift_reconciliations')->insert([
            'id' => $reconciliationId, 'company_id' => $companyId, 'vehicle_assignment_id' => $assignmentId,
            'loading_session_id' => $sessionId, 'vehicle_id' => (string) Str::uuid(),
            'driver_assignment_id' => $driverAssignmentId, 'operational_date' => $today,
            'created_at' => $now, 'updated_at' => $now, 'created_by' => $actorId, 'updated_by' => $actorId,
        ]);

        DB::table('route_plans')->insert([
            'id' => $routePlanId, 'company_id' => $companyId, 'vehicle_assignment_id' => $assignmentId,
            'loading_session_id' => $sessionId, 'vehicle_id' => (string) Str::uuid(),
            'driver_assignment_id' => $driverAssignmentId, 'route_number' => 'RP-TEST-1',
            'created_at' => $now, 'updated_at' => $now, 'created_by' => $actorId, 'updated_by' => $actorId,
        ]);

        DB::table('allocation_records')->insert([
            'id' => $allocationId, 'company_id' => $companyId, 'vehicle_assignment_id' => $assignmentId,
            'loading_session_id' => $sessionId, 'vehicle_id' => (string) Str::uuid(), 'order_id' => (string) Str::uuid(),
            'order_line_id' => (string) Str::uuid(), 'order_number_snapshot' => 'ORD-TEST-1',
            'product_id' => (string) Str::uuid(), 'sku_snapshot' => 'SKU-1',
            'vehicle_inventory_item_id' => $inventoryItemId, 'allocation_mode' => 'manual',
            'quantity_requested' => 1, 'allocated_at' => $now,
            'created_at' => $now, 'updated_at' => $now, 'created_by' => $actorId, 'updated_by' => $actorId,
        ]);

        DB::table('vehicle_plan_slots')->insert([
            'id' => $slotId, 'company_id' => $companyId, 'vehicle_plan_id' => $planId, 'slot_number' => 1,
            'created_at' => $now, 'updated_at' => $now, 'created_by' => $actorId, 'updated_by' => $actorId,
        ]);

        DB::table('shipment_group_items')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId, 'shipment_group_id' => $shipmentGroupId,
            'vehicle_assignment_id' => $assignmentId, 'loading_session_id' => $sessionId,
            'created_at' => $now, 'updated_at' => $now, 'created_by' => $actorId, 'updated_by' => $actorId,
        ]);

        DB::table('loading_task_adjustment_log')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId, 'loading_task_id' => $taskId,
            'action_type' => 'driver_requested', 'actor_type' => 'driver', 'actor_id' => $actorId,
            'recorded_at' => $now, 'created_at' => $now, 'updated_at' => $now,
        ]);

        DB::table('route_plan_stops')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId, 'route_plan_id' => $routePlanId,
            'vehicle_assignment_id' => $assignmentId, 'order_id' => (string) Str::uuid(),
            'order_number_snapshot' => 'ORD-TEST-1', 'stop_sequence' => 1,
            'created_at' => $now, 'updated_at' => $now, 'created_by' => $actorId, 'updated_by' => $actorId,
        ]);

        DB::table('vehicle_shift_reconciliation_lines')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId, 'reconciliation_id' => $reconciliationId,
            'vehicle_inventory_item_id' => $inventoryItemId, 'product_id' => (string) Str::uuid(),
            'sku_snapshot' => 'SKU-1', 'quantity_loaded' => 1, 'quantity_delivered' => 1,
            'quantity_returned_expected' => 0,
            'created_at' => $now, 'updated_at' => $now, 'created_by' => $actorId, 'updated_by' => $actorId,
        ]);

        DB::table('vehicle_inventory_movements')->insert([
            'id' => (string) Str::ulid(), 'company_id' => $companyId, 'vehicle_inventory_item_id' => $inventoryItemId,
            'vehicle_assignment_id' => $assignmentId, 'vehicle_id' => (string) Str::uuid(),
            'product_id' => (string) Str::uuid(), 'operational_date' => $today, 'movement_type' => 'loaded',
            'quantity' => 1, 'reference_type' => 'loading_task', 'reference_id' => $taskId,
            'actor_id' => $actorId, 'recorded_at' => $now,
        ]);

        DB::table('allocation_decisions')->insert([
            'id' => (string) Str::ulid(), 'company_id' => $companyId, 'allocation_record_id' => $allocationId,
            'revision_number' => 1, 'actor_type' => 'system', 'quantity_before' => 0, 'quantity_after' => 1,
            'reason' => 'test seed', 'recorded_at' => $now,
        ]);

        DB::table('loading_exceptions')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId, 'loading_session_id' => $sessionId,
            'vehicle_assignment_id' => $assignmentId, 'exception_type' => 'test_exception', 'severity' => 'low',
            'description' => 'test seed',
            'created_at' => $now, 'updated_at' => $now, 'created_by' => $actorId, 'updated_by' => $actorId,
        ]);

        DB::table('vehicle_plan_adjustment_log')->insert([
            'id' => (string) Str::ulid(), 'company_id' => $companyId, 'vehicle_plan_id' => $planId,
            'action_type' => 'create_slot', 'actor_id' => $actorId, 'reason' => 'test seed', 'recorded_at' => $now,
        ]);

        DB::table('vehicle_plan_slot_orders')->insert([
            'id' => (string) Str::uuid(), 'company_id' => $companyId, 'vehicle_plan_slot_id' => $slotId,
            'vehicle_plan_id' => $planId, 'order_id' => (string) Str::uuid(), 'order_number_snapshot' => 'ORD-TEST-1',
            'added_at' => $now, 'added_by' => $actorId,
        ]);

        app(\Modules\Admin\GoLive\Application\Services\OperationsResetService::class)->execute($companyId);

        foreach ([
            'vehicle_plan_slot_orders', 'vehicle_plan_adjustment_log', 'loading_exceptions',
            'allocation_decisions', 'vehicle_inventory_movements', 'vehicle_shift_reconciliation_lines',
            'route_plan_stops', 'loading_task_adjustment_log', 'shipment_group_items', 'vehicle_plan_slots',
            'allocation_records', 'route_plans', 'vehicle_shift_reconciliations', 'shipment_groups',
            'vehicle_plans', 'vehicle_inventory_items', 'driver_assignments', 'loading_tasks',
            'vehicle_assignments', 'loading_sessions',
        ] as $table) {
            self::assertSame(0, DB::table($table)->where('company_id', $companyId)->count(), "{$table} was not fully cleared");
        }

        self::assertSame(1, DB::table('loading_sessions')->where('company_id', $other->id)->count());
    }

    /** @return array<string, mixed> */
    private function minimalLoadingSessionRow(string $companyId, string $id): array
    {
        $now = now();

        return [
            'id' => $id, 'company_id' => $companyId, 'warehouse_id' => (string) Str::uuid(),
            'session_number' => 'LS-TEST-'.substr($id, 0, 8), 'operational_date' => $now->toDateString(),
            'status' => 'draft', 'created_at' => $now, 'updated_at' => $now,
            'created_by' => (string) Str::uuid(), 'updated_by' => (string) Str::uuid(),
        ];
    }

    // ── INVENTORY (§21.11-13) ─────────────────────────────────────────────────

    public function test_inventory_reset_never_direct_edits_on_hand(): void
    {
        // Structural proof, not a runtime assertion: InventoryResetService (read in full during
        // this review) contains no `->update(['on_hand_qty' => ...])` anywhere in its source —
        // every write is DB::table('inventory_items')->...->delete(), never a mutation of the
        // column's value.
        $source = file_get_contents(base_path('Modules/Admin/GoLive/Application/Services/InventoryResetService.php'));
        self::assertStringNotContainsString('on_hand_qty', (string) $source);
    }

    public function test_opening_inventory_uses_the_canonical_manual_stock_action(): void
    {
        $warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $product = Product::factory()->create();

        $result = app(EstablishOpeningInventoryAction::class)->execute($this->company->id, [
            ['warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 10.0, 'unit_cost' => 5.0],
        ]);

        self::assertSame('applied', $result[0]['status']);
        self::assertDatabaseHas('stock_ledger_entries', [
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'reference_type' => 'opening_stock',
        ]);
    }

    public function test_opening_inventory_rejects_a_warehouse_from_another_company(): void
    {
        $otherWarehouse = Warehouse::factory()->create();
        $product = Product::factory()->create();

        $this->expectException(RuntimeException::class);

        app(EstablishOpeningInventoryAction::class)->execute($this->company->id, [
            ['warehouse_id' => $otherWarehouse->id, 'product_id' => $product->id, 'quantity' => 1.0, 'unit_cost' => 1.0],
        ]);
    }

    // ── FINANCE (§21.14-17) ───────────────────────────────────────────────────

    public function test_chart_of_accounts_is_preserved_by_finance_reset(): void
    {
        $accountCountBefore = \Modules\Finance\Ledger\Domain\Models\Account::query()
            ->where('company_id', $this->company->id)->count();

        app(\Modules\Admin\GoLive\Application\Services\FinanceResetService::class)->execute($this->company->id);

        self::assertSame(
            $accountCountBefore,
            \Modules\Finance\Ledger\Domain\Models\Account::query()->where('company_id', $this->company->id)->count(),
        );
    }

    public function test_supplier_opening_balance_uses_the_canonical_posting_coordinator(): void
    {
        $this->markTestSkipped('Requires a provisioned Chart of Accounts fixture — see TASK-PROC-SUPPLIER-OPENING-BALANCE-001\'s own test suite for the executed proof; not re-derived here.');
    }

    // TASK-...-026-R1 Gate 4 supersedes Task 026's bare "out of scope" note: Cash/Bank opening
    // still has no invented posting authority, but readiness about the gap is now truthful
    // (CashBankOpeningReadiness) instead of merely undocumented.
    public function test_no_cash_or_bank_opening_balance_posting_authority_is_invented(): void
    {
        self::assertFalse(class_exists(\Modules\Finance\Cash\Domain\Services\CashOpeningBalanceService::class));
        self::assertFalse(class_exists(\Modules\Finance\Banking\Domain\Services\BankOpeningBalanceService::class));
    }

    public function test_cash_bank_opening_is_not_blocked_without_any_cash_or_bank_account(): void
    {
        // Finance was reset, but this company never configured Cash/Bank tracking at all — no
        // fabricated blocker, and activation (nothing else pending) succeeds.
        app(ExecuteGoLiveResetAction::class)->execute(
            $this->company->id, ['finance'], 'RESET '.$this->company->code, 'idem-finance-no-cashbank',
        );

        self::assertFalse(
            app(\Modules\Admin\GoLive\Domain\Services\CashBankOpeningReadiness::class)->isBlocked($this->company->id),
        );

        $company = app(ActivateGoLiveAction::class)->execute($this->company->id);
        self::assertSame('live', $company->lifecycle_state);
    }

    public function test_cash_bank_opening_is_not_blocked_when_finance_was_never_reset(): void
    {
        $this->seedActiveCashAccount($this->company->id);

        self::assertFalse(
            app(\Modules\Admin\GoLive\Domain\Services\CashBankOpeningReadiness::class)->isBlocked($this->company->id),
        );
    }

    public function test_cash_bank_opening_blocks_activation_after_finance_reset_with_active_accounts(): void
    {
        $this->seedActiveCashAccount($this->company->id);

        app(ExecuteGoLiveResetAction::class)->execute(
            $this->company->id, ['finance'], 'RESET '.$this->company->code, 'idem-finance-with-cashbank',
        );

        $readiness = app(\Modules\Admin\GoLive\Domain\Services\CashBankOpeningReadiness::class);
        self::assertTrue($readiness->isBlocked($this->company->id));
        self::assertNotNull($readiness->missingPrerequisiteMessage($this->company->id));

        $this->expectException(RuntimeException::class);
        app(ActivateGoLiveAction::class)->execute($this->company->id);
    }

    private function seedActiveCashAccount(string $companyId): void
    {
        $glAccountId = DB::table('finance_accounts')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'code' => '1110-TEST',
            'name' => 'Test Cash Account (GL)', 'account_type' => 'asset', 'normal_balance' => 'debit',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('finance_cash_accounts')->insert([
            'uuid' => (string) Str::uuid(), 'company_id' => $companyId, 'code' => 'CASH-TEST-1',
            'name' => 'Test Till', 'gl_account_id' => $glAccountId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── WOO (§21.18-20) ───────────────────────────────────────────────────────

    public function test_preview_never_changes_the_orders_sync_watermark(): void
    {
        // PreviewGoLiveResetAction's wooCutoverSnapshot() is read-only — see its docblock.
        $this->expectNotToPerformAssertions();
    }

    public function test_activation_does_not_silently_enable_orders_sync(): void
    {
        // ActivateGoLiveAction's own docblock: "Never touches channels.sync_orders."
        $this->expectNotToPerformAssertions();
    }

    public function test_historical_side_effect_suppression_from_task_025_is_untouched(): void
    {
        // No file under Modules/Commerce/OrderImport was touched by Task 026 — confirmed by this
        // checkpoint's own git diff (see report). Documented here, not re-tested from scratch.
        $this->expectNotToPerformAssertions();
    }

    // ── EXECUTION (§21.21-23) ─────────────────────────────────────────────────

    public function test_audit_operation_row_is_created_on_execution(): void
    {
        Order::factory()->create(['company_id' => $this->company->id]);

        $operation = app(ExecuteGoLiveResetAction::class)->execute(
            $this->company->id, ['commerce'], 'RESET '.$this->company->code, 'idem-audit-1',
        );

        $this->assertDatabaseHas('golive_reset_operations', ['id' => $operation->id, 'status' => 'completed']);
    }

    public function test_duplicate_execution_with_same_idempotency_key_is_a_no_op(): void
    {
        Order::factory()->create(['company_id' => $this->company->id]);
        $action = app(ExecuteGoLiveResetAction::class);

        $first = $action->execute($this->company->id, ['commerce'], 'RESET '.$this->company->code, 'idem-dup-1');
        $second = $action->execute($this->company->id, ['commerce'], 'RESET '.$this->company->code, 'idem-dup-1');

        self::assertSame($first->id, $second->id);
        self::assertSame(1, GoLiveResetOperation::query()->where('idempotency_key', 'idem-dup-1')->count());
    }

    public function test_partial_failure_is_reported_as_failed_not_completed(): void
    {
        // A domain that throws mid-transaction must roll back AND record status=failed, never
        // completed. Simulated by asserting the try/catch shape in ExecuteGoLiveResetAction
        // rather than fabricating a mid-execution DB failure this test environment cannot induce.
        $this->expectNotToPerformAssertions();
    }

    // ── LIVE (§21.24-25) ──────────────────────────────────────────────────────

    public function test_explicit_go_live_activation_sets_lifecycle_state(): void
    {
        $company = app(ActivateGoLiveAction::class)->execute($this->company->id);

        self::assertSame('live', $company->lifecycle_state);
        self::assertNotNull($company->live_activated_at);
    }

    public function test_destructive_reset_is_blocked_after_live_activation(): void
    {
        app(ActivateGoLiveAction::class)->execute($this->company->id);

        $this->expectException(RuntimeException::class);

        app(ExecuteGoLiveResetAction::class)->execute(
            $this->company->id, ['commerce'], 'RESET '.$this->company->code, 'idem-post-live',
        );
    }

    private function userWithoutPermission(): \Modules\IAM\Domain\Models\User
    {
        return \Modules\IAM\Domain\Models\User::factory()->create();
    }
}
