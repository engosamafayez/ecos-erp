<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\GoodsReceipts\Application\Actions\PostGoodsReceiptAction;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceiptLine;
use Modules\Purchasing\PurchaseOrders\Domain\Enums\PurchaseOrderStatus;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrder;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrderLine;
use Modules\Purchasing\Suppliers\Application\Queries\GetSupplierProductDemandQuery;
use Modules\Purchasing\Suppliers\Domain\Exceptions\SupplierNotFoundException;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use Tests\TestCase;

/**
 * Part 12 — Supplier Product Demand / purchase rate.
 *
 * Every case builds real purchase_orders / goods_receipts / goods_receipt_lines
 * rows and posts them through PostGoodsReceiptAction; nothing is mocked.
 *
 * `$grantsBaselineAuthorization = false` so the tenant cases can construct a
 * genuinely unprivileged, company-scoped actor. The aggregation cases run with
 * no actor at all, which is the console/queue path TenantOwnershipResolver
 * leaves unscoped — the same convention SupplierAnalyticsTest uses.
 */
final class SupplierProductDemandTest extends TestCase
{
    use RefreshDatabase;

    protected bool $grantsBaselineAuthorization = false;

    private Company $company;

    private Warehouse $warehouse;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->supplier = Supplier::factory()->create(['company_id' => $this->company->id]);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /** @return array{0: PurchaseOrder, 1: PurchaseOrderLine} */
    private function approvedPo(
        Supplier $supplier,
        Product $product,
        float $orderedQty,
        float $unitPrice = 10.0,
        ?Company $company = null,
    ): array {
        $po = PurchaseOrder::factory()->approved()->create([
            'company_id' => ($company ?? $this->company)->id,
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

    /**
     * Build a receipt and post it through the production action.
     *
     * $netQty is written to net_received_quantity — the canonical quantity the
     * whole procurement stack reads.
     */
    private function postReceipt(
        PurchaseOrder $po,
        PurchaseOrderLine $poLine,
        float $netQty,
        int $daysAgo = 0,
        ?float $legacyReceivedQty = null,
        ?float $unitPrice = null,
        ?Warehouse $warehouse = null,
    ): GoodsReceipt {
        $receipt = GoodsReceipt::factory()->create([
            'purchase_order_id' => $po->id,
            'company_id' => $po->company_id,
            'warehouse_id' => ($warehouse ?? $this->warehouse)->id,
            'receipt_date' => Carbon::today()->subDays($daysAgo)->toDateString(),
        ]);

        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'purchase_order_line_id' => $poLine->id,
            'product_id' => $poLine->product_id,
            'ordered_quantity' => (float) $poLine->quantity,
            'received_quantity' => $legacyReceivedQty ?? $netQty,
            'gross_received_quantity' => $netQty,
            'net_received_quantity' => $netQty,
            'unit_price' => $unitPrice ?? (float) $poLine->unit_price,
        ]);

        app(PostGoodsReceiptAction::class)->execute($receipt->id);

        return $receipt->refresh();
    }

    /** @return array<string, mixed> */
    private function rowFor(Supplier $supplier, Product $product): array
    {
        $row = app(GetSupplierProductDemandQuery::class)
            ->execute($supplier->id)
            ->firstWhere('product_id', $product->id);

        self::assertIsArray($row, 'Expected a demand row for the product.');

        return $row;
    }

    /**
     * The 90-day fixture the business example describes:
     * 120 in the last 30 days, 350 in the last 90, plus one purchase outside
     * every window.
     *
     * @return array{0: Product, 1: PurchaseOrder, 2: PurchaseOrderLine}
     */
    private function honeyFixture(): array
    {
        $honey = Product::factory()->create(['name' => 'Honey']);
        [$po, $line] = $this->approvedPo($this->supplier, $honey, 1000.0);

        $this->postReceipt($po, $line, 50.0, daysAgo: 3);
        $this->postReceipt($po, $line, 70.0, daysAgo: 20);
        $this->postReceipt($po, $line, 230.0, daysAgo: 60);
        $this->postReceipt($po, $line, 40.0, daysAgo: 200);

        return [$honey, $po, $line];
    }

    // ── 1. Product quantity is aggregated correctly ───────────────────────────

    public function test_total_quantity_is_aggregated_per_product(): void
    {
        [$honey] = $this->honeyFixture();

        $row = $this->rowFor($this->supplier, $honey);

        self::assertTrue($row['has_purchase_history']);
        self::assertSame(390.0, $row['total_quantity'], '50 + 70 + 230 + 40');
        self::assertSame(4, $row['purchase_line_count']);
        self::assertSame(50.0, $row['last_purchase_quantity']);
        self::assertSame(
            Carbon::today()->subDays(3)->toDateString(),
            substr((string) $row['last_purchase_date'], 0, 10),
        );
        self::assertSame(
            Carbon::today()->subDays(200)->toDateString(),
            substr((string) $row['first_purchase_date'], 0, 10),
        );
    }

    public function test_each_product_is_aggregated_separately(): void
    {
        $honey = Product::factory()->create(['name' => 'Honey']);
        $olive = Product::factory()->create(['name' => 'Olive Oil']);

        [$poH, $lineH] = $this->approvedPo($this->supplier, $honey, 500.0);
        [$poO, $lineO] = $this->approvedPo($this->supplier, $olive, 500.0);

        $this->postReceipt($poH, $lineH, 120.0, daysAgo: 5);
        $this->postReceipt($poO, $lineO, 35.0, daysAgo: 5);

        self::assertSame(120.0, $this->rowFor($this->supplier, $honey)['total_quantity']);
        self::assertSame(35.0, $this->rowFor($this->supplier, $olive)['total_quantity']);
    }

    // ── 2. Last 7 days ────────────────────────────────────────────────────────

    public function test_last_7_days_quantity(): void
    {
        [$honey] = $this->honeyFixture();

        self::assertSame(50.0, $this->rowFor($this->supplier, $honey)['quantity_7d']);
    }

    public function test_7_day_window_boundary_is_inclusive(): void
    {
        $product = Product::factory()->create();
        [$po, $line] = $this->approvedPo($this->supplier, $product, 500.0);

        $this->postReceipt($po, $line, 11.0, daysAgo: 7);  // on the boundary — counts
        $this->postReceipt($po, $line, 22.0, daysAgo: 8);  // one day past it — does not

        $row = $this->rowFor($this->supplier, $product);

        self::assertSame(11.0, $row['quantity_7d']);
        self::assertSame(33.0, $row['quantity_30d']);
    }

    // ── 3. Last 30 days ───────────────────────────────────────────────────────

    public function test_last_30_days_quantity(): void
    {
        [$honey] = $this->honeyFixture();

        self::assertSame(120.0, $this->rowFor($this->supplier, $honey)['quantity_30d'], '50 + 70');
    }

    // ── 4. Last 90 days ───────────────────────────────────────────────────────

    public function test_last_90_days_quantity_excludes_older_purchases(): void
    {
        [$honey] = $this->honeyFixture();

        $row = $this->rowFor($this->supplier, $honey);

        self::assertSame(350.0, $row['quantity_90d'], '50 + 70 + 230; the 200-day-old 40 is outside');
        self::assertSame(390.0, $row['total_quantity'], 'lifetime total still counts it');
    }

    // ── 5. Average weekly ─────────────────────────────────────────────────────

    public function test_average_weekly_quantity_divides_by_the_window_length(): void
    {
        [$honey] = $this->honeyFixture();

        $row = $this->rowFor($this->supplier, $honey);

        // 350 over a 90-day window → 350 / (90 / 7) weeks.
        self::assertSame(round(350.0 / 90 * 7, 4), $row['average_weekly_quantity']);
        self::assertSame(round(90 / 7, 6), $row['average_weekly_denominator_weeks']);
        self::assertSame(90, $row['average_basis_days']);
    }

    public function test_averages_are_not_divided_by_the_number_of_transactions(): void
    {
        $product = Product::factory()->create();
        [$po, $line] = $this->approvedPo($this->supplier, $product, 500.0);

        // One delivery of 90 vs three of 30 must produce the same purchase rate.
        $this->postReceipt($po, $line, 90.0, daysAgo: 10);

        $single = $this->rowFor($this->supplier, $product);

        $other = Product::factory()->create();
        [$poB, $lineB] = $this->approvedPo($this->supplier, $other, 500.0);
        $this->postReceipt($poB, $lineB, 30.0, daysAgo: 10);
        $this->postReceipt($poB, $lineB, 30.0, daysAgo: 11);
        $this->postReceipt($poB, $lineB, 30.0, daysAgo: 12);

        $split = $this->rowFor($this->supplier, $other);

        self::assertSame($single['average_monthly_quantity'], $split['average_monthly_quantity']);
        self::assertSame($single['average_weekly_quantity'], $split['average_weekly_quantity']);
        self::assertSame(1, $single['purchase_line_count']);
        self::assertSame(3, $split['purchase_line_count']);
    }

    // ── 6. Average monthly ────────────────────────────────────────────────────

    public function test_average_monthly_quantity_divides_by_the_window_length(): void
    {
        [$honey] = $this->honeyFixture();

        $row = $this->rowFor($this->supplier, $honey);

        // 350 over a 90-day window → 350 / 3 months ≈ 116.6667.
        self::assertSame(round(350.0 / 3, 4), $row['average_monthly_quantity']);
        self::assertSame(round(90 / 30, 6), $row['average_monthly_denominator_months']);
        self::assertSame(round(350.0 / 90, 4), $row['average_daily_quantity']);
    }

    // ── 7. Cancelled / invalid purchases are excluded ─────────────────────────

    public function test_draft_receipts_do_not_count(): void
    {
        $product = Product::factory()->create();
        [$po, $line] = $this->approvedPo($this->supplier, $product, 500.0);

        $this->postReceipt($po, $line, 60.0, daysAgo: 2);

        // A second receipt left in draft — the goods have not been accepted yet.
        $draft = GoodsReceipt::factory()->create([
            'purchase_order_id' => $po->id,
            'company_id' => $po->company_id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => Carbon::today()->toDateString(),
        ]);
        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $draft->id,
            'purchase_order_line_id' => $line->id,
            'product_id' => $product->id,
            'ordered_quantity' => 500.0,
            'received_quantity' => 999.0,
            'net_received_quantity' => 999.0,
            'unit_price' => 10.0,
        ]);

        self::assertSame(60.0, $this->rowFor($this->supplier, $product)['total_quantity']);
    }

    public function test_soft_deleted_receipts_do_not_count(): void
    {
        $product = Product::factory()->create();
        [$po, $line] = $this->approvedPo($this->supplier, $product, 500.0);

        $this->postReceipt($po, $line, 60.0, daysAgo: 2);
        $voided = $this->postReceipt($po, $line, 25.0, daysAgo: 1);

        $voided->delete();

        self::assertSame(60.0, $this->rowFor($this->supplier, $product)['total_quantity']);
    }

    public function test_receipts_against_a_cancelled_purchase_order_do_not_count(): void
    {
        $product = Product::factory()->create();

        [$goodPo, $goodLine] = $this->approvedPo($this->supplier, $product, 500.0);
        $this->postReceipt($goodPo, $goodLine, 60.0, daysAgo: 2);

        // A posted receipt whose PO was subsequently cancelled. Built directly:
        // PostGoodsReceiptAction refuses to post against a cancelled PO, so this
        // asserts the query's own exclusion rather than the posting guard's.
        [$cancelledPo, $cancelledLine] = $this->approvedPo($this->supplier, $product, 500.0);
        $receipt = GoodsReceipt::factory()->posted()->create([
            'purchase_order_id' => $cancelledPo->id,
            'company_id' => $cancelledPo->company_id,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => Carbon::today()->toDateString(),
        ]);
        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'purchase_order_line_id' => $cancelledLine->id,
            'product_id' => $product->id,
            'ordered_quantity' => 500.0,
            'received_quantity' => 400.0,
            'net_received_quantity' => 400.0,
            'unit_price' => 10.0,
        ]);
        $cancelledPo->update(['status' => PurchaseOrderStatus::Cancelled->value]);

        self::assertSame(60.0, $this->rowFor($this->supplier, $product)['total_quantity']);
    }

    // ── 8. Tenant isolation ───────────────────────────────────────────────────

    public function test_another_companys_purchase_never_contributes(): void
    {
        $product = Product::factory()->create();

        [$ownPo, $ownLine] = $this->approvedPo($this->supplier, $product, 500.0);
        $this->postReceipt($ownPo, $ownLine, 100.0, daysAgo: 5);

        // A purchase of the same supplier/product owned by a different company.
        $foreign = Company::factory()->create();
        $foreignWarehouse = Warehouse::factory()->create(['company_id' => $foreign->id]);
        [$foreignPo, $foreignLine] = $this->approvedPo($this->supplier, $product, 500.0, company: $foreign);
        $this->postReceipt($foreignPo, $foreignLine, 500.0, daysAgo: 5, warehouse: $foreignWarehouse);

        $this->actingAsUnprivileged($this->operatorFor($this->company));

        self::assertSame(
            100.0,
            $this->rowFor($this->supplier, $product)['total_quantity'],
            "Company B's 500 must not leak into company A's purchase rate.",
        );
    }

    public function test_a_foreign_supplier_is_not_resolvable(): void
    {
        $foreign = Company::factory()->create();
        $foreignSupplier = Supplier::factory()->create(['company_id' => $foreign->id]);

        $this->actingAsUnprivileged($this->operatorFor($this->company));

        $this->expectException(SupplierNotFoundException::class);

        app(GetSupplierProductDemandQuery::class)->execute($foreignSupplier->id);
    }

    public function test_endpoint_is_scoped_to_the_callers_company(): void
    {
        $product = Product::factory()->create(['name' => 'Honey']);

        [$ownPo, $ownLine] = $this->approvedPo($this->supplier, $product, 500.0);
        $this->postReceipt($ownPo, $ownLine, 100.0, daysAgo: 5);

        $foreign = Company::factory()->create();
        $foreignWarehouse = Warehouse::factory()->create(['company_id' => $foreign->id]);
        [$foreignPo, $foreignLine] = $this->approvedPo($this->supplier, $product, 500.0, company: $foreign);
        $this->postReceipt($foreignPo, $foreignLine, 500.0, daysAgo: 5, warehouse: $foreignWarehouse);

        $this->actingAsUnprivileged($this->operatorFor($this->company));

        $rows = $this->getJson("/api/suppliers/{$this->supplier->id}/product-demand")
            ->assertOk()
            ->json('data');

        // JSON collapses 100.0 to 100, so compare as numbers rather than by type.
        self::assertCount(1, $rows);
        self::assertSame(100.0, (float) $rows[0]['total_quantity']);
        self::assertSame(100.0, (float) $rows[0]['last_purchase_quantity']);
    }

    // ── 9. Multiple suppliers for the same product stay separate ──────────────

    public function test_two_suppliers_of_the_same_product_are_not_merged(): void
    {
        $honey = Product::factory()->create(['name' => 'Honey']);
        $other = Supplier::factory()->create(['company_id' => $this->company->id]);

        [$poA, $lineA] = $this->approvedPo($this->supplier, $honey, 500.0);
        [$poB, $lineB] = $this->approvedPo($other, $honey, 500.0);

        $this->postReceipt($poA, $lineA, 120.0, daysAgo: 4);
        $this->postReceipt($poB, $lineB, 45.0, daysAgo: 4);

        self::assertSame(120.0, $this->rowFor($this->supplier, $honey)['total_quantity']);
        self::assertSame(45.0, $this->rowFor($other, $honey)['total_quantity']);
    }

    // ── 10. No purchase history ───────────────────────────────────────────────

    public function test_product_with_no_receipts_reports_no_history_rather_than_zero(): void
    {
        $product = Product::factory()->create(['name' => 'Never Received']);
        $this->approvedPo($this->supplier, $product, 500.0);

        $row = $this->rowFor($this->supplier, $product);

        self::assertFalse($row['has_purchase_history']);
        self::assertNull($row['total_quantity']);
        self::assertNull($row['quantity_7d']);
        self::assertNull($row['quantity_30d']);
        self::assertNull($row['quantity_90d']);
        self::assertNull($row['average_weekly_quantity']);
        self::assertNull($row['average_monthly_quantity']);
        self::assertNull($row['last_purchase_date']);
        self::assertNull($row['last_purchase_quantity']);
    }

    public function test_a_window_with_no_purchases_reports_a_real_zero(): void
    {
        $product = Product::factory()->create();
        [$po, $line] = $this->approvedPo($this->supplier, $product, 500.0);

        $this->postReceipt($po, $line, 80.0, daysAgo: 45);

        $row = $this->rowFor($this->supplier, $product);

        self::assertTrue($row['has_purchase_history']);
        self::assertSame(0.0, $row['quantity_7d'], 'nothing in the last 7 days is a calculated zero');
        self::assertSame(0.0, $row['quantity_30d']);
        self::assertSame(80.0, $row['quantity_90d']);
    }

    public function test_a_supplier_with_no_purchases_at_all_returns_no_rows(): void
    {
        $rows = app(GetSupplierProductDemandQuery::class)->execute($this->supplier->id);

        self::assertTrue($rows->isEmpty());
    }

    // ── 11. Ordered vs received follows the canonical contract ────────────────

    public function test_partial_receipt_counts_received_not_ordered(): void
    {
        $product = Product::factory()->create();
        [$po, $line] = $this->approvedPo($this->supplier, $product, 100.0);

        $this->postReceipt($po, $line, 60.0, daysAgo: 1);

        $row = $this->rowFor($this->supplier, $product);

        self::assertSame(60.0, $row['total_quantity'], 'ordered 100, received 60 → demand is 60');
        self::assertSame(60.0, $row['quantity_7d']);
        self::assertSame(
            60.0,
            (float) $line->refresh()->received_qty,
            'and it matches what the posting action wrote to the PO line',
        );
    }

    public function test_net_received_quantity_wins_over_the_legacy_column(): void
    {
        $product = Product::factory()->create();
        [$po, $line] = $this->approvedPo($this->supplier, $product, 500.0);

        // Gross 90 delivered, 60 net accepted — net is what reaches stock.
        $this->postReceipt($po, $line, 60.0, daysAgo: 1, legacyReceivedQty: 90.0);

        self::assertSame(60.0, $this->rowFor($this->supplier, $product)['total_quantity']);
    }

    public function test_legacy_rows_without_a_net_quantity_fall_back_to_received_quantity(): void
    {
        $product = Product::factory()->create();
        [$po, $line] = $this->approvedPo($this->supplier, $product, 500.0);
        $receipt = $this->postReceipt($po, $line, 75.0, daysAgo: 1);

        // Pre-net-quantity data: only received_quantity was ever populated.
        GoodsReceiptLine::query()
            ->where('goods_receipt_id', $receipt->id)
            ->update(['net_received_quantity' => null, 'gross_received_quantity' => null]);

        self::assertSame(75.0, $this->rowFor($this->supplier, $product)['total_quantity']);
    }

    // ── Supplier price + trend ────────────────────────────────────────────────

    public function test_supplier_price_is_the_latest_paid_price_and_trend_compares_it(): void
    {
        $product = Product::factory()->create();
        [$po, $line] = $this->approvedPo($this->supplier, $product, 500.0, unitPrice: 10.0);

        $this->postReceipt($po, $line, 30.0, daysAgo: 30, unitPrice: 10.0);
        $this->postReceipt($po, $line, 30.0, daysAgo: 2, unitPrice: 12.0);

        $row = $this->rowFor($this->supplier, $product);

        self::assertSame(12.0, $row['supplier_price']);
        self::assertSame('rising', $row['price_trend']);
        self::assertSame(20.0, $row['price_change_percent']);
    }

    public function test_price_trend_is_null_on_a_first_purchase(): void
    {
        $product = Product::factory()->create();
        [$po, $line] = $this->approvedPo($this->supplier, $product, 500.0, unitPrice: 10.0);

        $this->postReceipt($po, $line, 30.0, daysAgo: 2);

        $row = $this->rowFor($this->supplier, $product);

        self::assertSame(10.0, $row['supplier_price']);
        self::assertNull($row['price_trend']);
        self::assertNull($row['price_change_percent']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** A company-scoped actor holding no privileged role. */
    private function operatorFor(Company $company): User
    {
        $user = User::factory()->create(['company_id' => $company->id]);

        $role = Role::firstOrCreate(
            ['slug' => 'test-supplier-demand-operator'],
            ['name' => 'Test Supplier Demand Operator', 'is_system' => false],
        );
        $user->roles()->attach($role->id);
        $user->unsetRelation('roles');

        return $user;
    }
}
