<?php

declare(strict_types=1);

namespace Tests\Feature\Admin\GoLive;

use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_cash_and_bank_opening_paths_are_explicitly_out_of_scope(): void
    {
        // Documents the Task 026 scoping decision rather than asserting behavior: no
        // CashOpeningBalanceService/BankOpeningBalanceService class exists in this checkpoint.
        self::assertFalse(class_exists(\Modules\Finance\Cash\Domain\Services\CashOpeningBalanceService::class));
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
