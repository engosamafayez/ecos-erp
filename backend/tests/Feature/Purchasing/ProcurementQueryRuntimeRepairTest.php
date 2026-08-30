<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Modules\Core\DemandAnalysis\Application\Services\DemandAnalysisService;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\GoodsReceipts\Application\Actions\PostGoodsReceiptAction;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceiptLine;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrder;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrderLine;
use Modules\Purchasing\Suppliers\Application\Queries\GetProcurementHealthQuery;
use Modules\Purchasing\Suppliers\Application\Queries\GetSupplierPriceHistoryQuery;
use Modules\Purchasing\Suppliers\Domain\Exceptions\SupplierNotFoundException;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use Tests\TestCase;

/**
 * TASK-PROCUREMENT-MYSQL-COMPATIBILITY-AND-DEMAND-TIMELINE-REPAIR-001.
 *
 * Three procurement read paths could not execute on the MySQL 8.4 runtime:
 *
 *   P0 DemandAnalysisService::timeline() selected `grl.received_qty`, a column
 *      that exists on purchase_order_lines but never on goods_receipt_lines.
 *   P1 GetSupplierPriceHistoryQuery and GetProcurementHealthQuery cast with the
 *      PostgreSQL-only `::float` operator.
 *
 * Every case here executes the real query against the real database. A test
 * that only inspected the SQL string would not have caught either defect.
 */
final class ProcurementQueryRuntimeRepairTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    private Company $company;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /** @return array{0: PurchaseOrder, 1: PurchaseOrderLine} */
    private function approvedPo(Supplier $supplier, Product $product, float $orderedQty, float $unitPrice): array
    {
        $po = PurchaseOrder::factory()->approved()->create([
            'company_id' => $this->company->id,
            'supplier_id' => $supplier->id,
        ]);

        $line = PurchaseOrderLine::factory()->create([
            'purchase_order_id' => $po->id,
            'product_id' => $product->id,
            'quantity' => $orderedQty,
            'received_qty' => 0,
            'unit_price' => $unitPrice,
        ]);

        return [$po, $line];
    }

    private function postReceipt(
        PurchaseOrder $po,
        PurchaseOrderLine $poLine,
        float $netQty,
        float $unitPrice,
        int $daysAgo = 0,
    ): GoodsReceipt {
        $receipt = GoodsReceipt::factory()->create([
            'purchase_order_id' => $po->id,
            'company_id' => $po->company_id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => Carbon::today()->subDays($daysAgo)->toDateString(),
        ]);

        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'purchase_order_line_id' => $poLine->id,
            'product_id' => $poLine->product_id,
            'ordered_quantity' => (float) $poLine->quantity,
            'received_quantity' => $netQty,
            'gross_received_quantity' => $netQty,
            'net_received_quantity' => $netQty,
            'unit_price' => $unitPrice,
        ]);

        app(PostGoodsReceiptAction::class)->execute($receipt->id);

        return $receipt->refresh();
    }

    private function supplier(): Supplier
    {
        return Supplier::factory()->create(['company_id' => $this->company->id]);
    }

    /** A company-scoped actor holding no privileged role. */
    private function operatorFor(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);

        $role = Role::firstOrCreate(
            ['slug' => 'test-procurement-runtime-operator'],
            ['name' => 'Test Procurement Runtime Operator', 'is_system' => false],
        );
        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }

    /** @return array<int, array<string, mixed>> */
    private function timelineFor(string $productId): array
    {
        return app(DemandAnalysisService::class)->analyze($productId)->timeline;
    }

    // ── 1. timeline() executes against MySQL 8.4 ──────────────────────────────

    public function test_demand_analysis_executes_end_to_end(): void
    {
        $product = Product::factory()->create();
        $supplier = $this->supplier();
        [$po, $line] = $this->approvedPo($supplier, $product, 100.0, 10.0);
        $this->postReceipt($po, $line, 60.0, 10.0, daysAgo: 3);

        // Before the repair this threw: unknown column grl.received_qty.
        $dto = app(DemandAnalysisService::class)->analyze($product->id);

        self::assertSame($product->id, $dto->product_id);
        self::assertIsArray($dto->timeline);
        self::assertNotEmpty($dto->timeline);
    }

    public function test_procurement_panel_adapter_executes(): void
    {
        $product = Product::factory()->create();
        $supplier = $this->supplier();
        [$po, $line] = $this->approvedPo($supplier, $product, 100.0, 10.0);
        $this->postReceipt($po, $line, 60.0, 10.0, daysAgo: 3);

        // The shape the procurement panel endpoint actually serves.
        $panel = app(DemandAnalysisService::class)->analyze($product->id)->toProcurementPanel();

        self::assertIsArray($panel);
    }

    // ── 2. timeline uses the canonical received quantity ──────────────────────

    public function test_timeline_reports_the_net_received_quantity(): void
    {
        $product = Product::factory()->create();
        $supplier = $this->supplier();
        [$po, $line] = $this->approvedPo($supplier, $product, 100.0, 10.0);

        // Ordered 100, received 60 — the timeline must show what was received.
        $this->postReceipt($po, $line, 60.0, 10.0, daysAgo: 3);

        $event = collect($this->timelineFor($product->id))
            ->firstWhere('subtype', 'goods_receipt');

        self::assertNotNull($event, 'Expected a goods_receipt timeline event.');
        self::assertSame(60.0, $event['quantity'], 'received, never the ordered 100');
        self::assertSame(600.0, $event['value'], '60 x 10.00');
        self::assertSame($supplier->name, $event['supplier']);
    }

    public function test_timeline_prefers_net_over_the_legacy_received_column(): void
    {
        $product = Product::factory()->create();
        $supplier = $this->supplier();
        [$po, $line] = $this->approvedPo($supplier, $product, 200.0, 10.0);
        $receipt = $this->postReceipt($po, $line, 60.0, 10.0, daysAgo: 3);

        // Gross 90 delivered, 60 net accepted.
        GoodsReceiptLine::query()
            ->where('goods_receipt_id', $receipt->id)
            ->update(['received_quantity' => 90.0]);

        $event = collect($this->timelineFor($product->id))->firstWhere('subtype', 'goods_receipt');

        self::assertNotNull($event);
        self::assertSame(60.0, $event['quantity']);
    }

    // ── 3. legacy fallback net → received ─────────────────────────────────────

    public function test_timeline_falls_back_to_received_quantity_on_legacy_rows(): void
    {
        $product = Product::factory()->create();
        $supplier = $this->supplier();
        [$po, $line] = $this->approvedPo($supplier, $product, 200.0, 10.0);
        $receipt = $this->postReceipt($po, $line, 75.0, 10.0, daysAgo: 3);

        // Pre-net-quantity data: only received_quantity was ever populated.
        GoodsReceiptLine::query()
            ->where('goods_receipt_id', $receipt->id)
            ->update(['net_received_quantity' => null, 'gross_received_quantity' => null]);

        $event = collect($this->timelineFor($product->id))->firstWhere('subtype', 'goods_receipt');

        self::assertNotNull($event);
        self::assertSame(75.0, $event['quantity']);
    }

    // ── 4. Supplier Price History executes, values unchanged ──────────────────

    public function test_price_history_executes_and_computes_the_previous_price(): void
    {
        $product = Product::factory()->create();
        $supplier = $this->supplier();
        [$po, $line] = $this->approvedPo($supplier, $product, 500.0, 10.0);

        $this->postReceipt($po, $line, 30.0, 10.0, daysAgo: 30);
        $this->postReceipt($po, $line, 20.0, 12.0, daysAgo: 2);

        // Before the repair this threw on `::float`.
        $rows = app(GetSupplierPriceHistoryQuery::class)->execute($supplier->id)->values();

        self::assertCount(2, $rows);

        // Ordered most-recent first.
        $latest = $rows[0];
        self::assertSame(20.0, $latest['quantity']);
        self::assertSame(12.0, $latest['unit_cost']);
        self::assertSame(10.0, $latest['previous_price'], 'LAG over the prior receipt');
        self::assertSame(20.0, $latest['price_diff_pct'], '(12 - 10) / 10 x 100');

        $earliest = $rows[1];
        self::assertSame(30.0, $earliest['quantity'], 'the canonical received quantity');
        self::assertSame(10.0, $earliest['unit_cost']);
        self::assertNull($earliest['previous_price'], 'nothing precedes the first purchase');
        self::assertNull($earliest['price_diff_pct']);
    }

    public function test_price_history_returns_floats_not_driver_strings(): void
    {
        $product = Product::factory()->create();
        $supplier = $this->supplier();
        [$po, $line] = $this->approvedPo($supplier, $product, 500.0, 10.0);
        $this->postReceipt($po, $line, 30.0, 10.0, daysAgo: 5);

        $row = app(GetSupplierPriceHistoryQuery::class)->execute($supplier->id)->first();

        // The `::float` cast used to do this in SQL; PHP does it now. Consumers
        // that relied on a float must keep getting one.
        self::assertNotNull($row);
        self::assertIsFloat($row['quantity']);
        self::assertIsFloat($row['unit_cost']);
    }

    // ── 5. Procurement Health executes, formulas unchanged ────────────────────

    public function test_procurement_health_fill_rate_is_unchanged(): void
    {
        $product = Product::factory()->create();
        $supplier = $this->supplier();
        [$po, $line] = $this->approvedPo($supplier, $product, 100.0, 10.0);

        $this->postReceipt($po, $line, 80.0, 10.0, daysAgo: 5);

        // Before the repair this threw on `::float`.
        $health = app(GetProcurementHealthQuery::class)->execute($supplier->id);

        self::assertSame(80.0, $health['components']['fill_rate'], '80 received / 100 ordered');
        self::assertIsFloat($health['score']);
        self::assertGreaterThanOrEqual(0.0, $health['score']);
        self::assertLessThanOrEqual(100.0, $health['score']);
    }

    public function test_procurement_health_price_stability_runs_stddev_samp(): void
    {
        $product = Product::factory()->create();
        $supplier = $this->supplier();
        [$po, $line] = $this->approvedPo($supplier, $product, 500.0, 10.0);

        // Two prices for one product, so the HAVING COUNT(*) > 1 branch is taken
        // and STDDEV_SAMP actually executes rather than falling back to 75.0.
        $this->postReceipt($po, $line, 40.0, 10.0, daysAgo: 30);
        $this->postReceipt($po, $line, 40.0, 12.0, daysAgo: 5);

        $health = app(GetProcurementHealthQuery::class)->execute($supplier->id);

        // stddev_samp(10, 12) = sqrt(2); avg = 11; cv = sqrt(2)/11
        // score = 100 - (cv * 200) = 74.287… → 74.3
        self::assertSame(74.3, $health['components']['price_stability']);
    }

    // ── 6. No PostgreSQL-only cast remains ────────────────────────────────────

    /**
     * A source guard, not a behaviour test. It is cheap and it fails loudly if a
     * future edit reintroduces the operator that caused this outage.
     */
    public function test_the_repaired_queries_contain_no_postgres_only_cast(): void
    {
        $files = [
            base_path('Modules/Purchasing/Suppliers/Application/Queries/GetSupplierPriceHistoryQuery.php'),
            base_path('Modules/Purchasing/Suppliers/Application/Queries/GetProcurementHealthQuery.php'),
            base_path('Modules/Core/DemandAnalysis/Application/Services/DemandAnalysisService.php'),
        ];

        foreach ($files as $file) {
            $source = file_get_contents($file);

            self::assertIsString($source, "Could not read {$file}");
            self::assertStringNotContainsString('::float', $source, basename($file).' still casts with the PostgreSQL-only operator.');
        }
    }

    public function test_goods_receipt_lines_has_no_received_qty_column(): void
    {
        // The premise of the P0 fix. If this ever becomes false the repair should
        // be revisited rather than silently left in place.
        self::assertFalse(
            Schema::hasColumn('goods_receipt_lines', 'received_qty'),
            'goods_receipt_lines gained a received_qty column — recheck DemandAnalysisService::timeline().',
        );
        self::assertTrue(
            Schema::hasColumn('goods_receipt_lines', 'net_received_quantity'),
        );
    }

    // ── 7. Tenant isolation is intact ─────────────────────────────────────────

    public function test_price_history_rejects_a_foreign_supplier(): void
    {
        $foreignSupplier = Supplier::factory()->create([
            'company_id' => Company::factory()->create()->id,
        ]);

        $this->actingAsUnprivileged($this->operatorFor($this->company));

        $this->expectException(SupplierNotFoundException::class);

        app(GetSupplierPriceHistoryQuery::class)->execute($foreignSupplier->id);
    }

    public function test_procurement_health_rejects_a_foreign_supplier(): void
    {
        $foreignSupplier = Supplier::factory()->create([
            'company_id' => Company::factory()->create()->id,
        ]);

        $this->actingAsUnprivileged($this->operatorFor($this->company));

        $this->expectException(SupplierNotFoundException::class);

        app(GetProcurementHealthQuery::class)->execute($foreignSupplier->id);
    }

    public function test_own_company_supplier_remains_readable_over_http(): void
    {
        $product = Product::factory()->create();
        $supplier = $this->supplier();
        [$po, $line] = $this->approvedPo($supplier, $product, 500.0, 10.0);
        $this->postReceipt($po, $line, 30.0, 10.0, daysAgo: 5);

        $this->actingAsUnprivileged($this->operatorFor($this->company));

        $history = $this->getJson("/api/suppliers/{$supplier->id}/price-history")
            ->assertOk()
            ->json('data');
        self::assertCount(1, $history);

        $this->getJson("/api/suppliers/{$supplier->id}/health")->assertOk();
    }
}
