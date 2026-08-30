<?php

declare(strict_types=1);

namespace Tests\Feature\Purchasing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\InventoryItems\Domain\Enums\LedgerMovementType;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\InventoryItems\Domain\Models\StockLedgerEntry;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\GoodsReceipts\Application\Actions\CreateGoodsReceiptAction;
use Modules\Purchasing\GoodsReceipts\Application\Actions\PostGoodsReceiptAction;
use Modules\Purchasing\GoodsReceipts\Application\DTO\GoodsReceiptDTO;
use Modules\Purchasing\GoodsReceipts\Domain\Enums\GoodsReceiptStatus;
use Modules\Purchasing\GoodsReceipts\Domain\Exceptions\PurchaseMaterialReceivingException;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterial;
use Modules\Purchasing\PurchaseMaterials\Domain\Models\PurchaseMaterialLine;
use Modules\Purchasing\PurchaseMaterials\Domain\Services\PurchaseMaterialReceivingService;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;
use Tests\TestCase;
use Throwable;

/**
 * TASK-PROC-PURCHASING-PHASE2-PART1 — Purchase Material receiving foundation.
 *
 * Proves the new operational path end to end:
 *   Purchase Material → Purchase Material Line → Goods Receipt Line → Inventory
 *
 * and proves it does so WITHOUT a purchase order, without a second inventory engine, and
 * without weakening any guard the legacy path already had.
 */
final class PurchaseMaterialReceivingFoundationTest extends TestCase
{
    use RefreshDatabase;

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

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function purchaseLine(
        ?float $requestedQty = 100.0,
        ?float $agreedQty = null,
        ?string $supplierId = 'default',
        ?Company $company = null,
        ?Warehouse $warehouse = null,
    ): PurchaseMaterialLine {
        $company ??= $this->company;
        $warehouse ??= $this->warehouse;

        $pm = PurchaseMaterial::query()->create([
            'request_number' => 'PM-'.substr(md5(uniqid('', true)), 0, 8),
            'company_id' => $company->id,
            'warehouse_id' => $warehouse->id,
            'record_type' => 'purchase',
            'status' => 'approved',
            'priority' => 'normal',
        ]);

        return PurchaseMaterialLine::query()->create([
            'purchase_material_id' => $pm->id,
            'product_id' => Product::factory()->create()->id,
            'requested_qty' => $requestedQty,
            'agreed_qty' => $agreedQty,
            'agreed_price' => 25.0,
            'supplier_id' => $supplierId === 'default' ? $this->supplier->id : $supplierId,
        ]);
    }

    /** @param list<array{line: PurchaseMaterialLine, qty: float}> $lines */
    private function receive(array $lines, ?Warehouse $warehouse = null): GoodsReceipt
    {
        $warehouse ??= $this->warehouse;

        $dto = GoodsReceiptDTO::fromArray([
            'purchase_order_id' => null,
            'warehouse_id' => $warehouse->id,
            'receipt_date' => now()->toDateString(),
            'lines' => array_map(fn (array $l): array => [
                'purchase_material_line_id' => $l['line']->id,
                'product_id' => $l['line']->product_id,
                'ordered_quantity' => (float) $l['line']->requested_qty,
                'gross_received_quantity' => $l['qty'],
                'net_received_quantity' => $l['qty'],
            ], $lines),
        ]);

        /** @var GoodsReceipt $receipt */
        $receipt = app(CreateGoodsReceiptAction::class)->execute($dto)->data();

        return $receipt;
    }

    private function service(): PurchaseMaterialReceivingService
    {
        return app(PurchaseMaterialReceivingService::class);
    }

    // ── 1. A Purchase line can anchor a Goods Receipt ────────────────────────

    public function test_a_purchase_material_line_can_anchor_a_goods_receipt(): void
    {
        $line = $this->purchaseLine();

        $receipt = $this->receive([['line' => $line, 'qty' => 40.0]]);

        self::assertNull($receipt->purchase_order_id, 'A Purchase receipt must not invent a purchase order.');
        self::assertSame((string) $this->company->id, (string) $receipt->company_id);
        self::assertSame(GoodsReceiptStatus::Draft, $receipt->status);
        self::assertSame((string) $line->id, (string) $receipt->lines->first()->purchase_material_line_id);
        self::assertNull($receipt->lines->first()->purchase_order_line_id);
    }

    // ── 2. Supplier comes from the Purchase line (RD-1) ─────────────────────

    public function test_supplier_identity_comes_from_the_purchase_material_line(): void
    {
        $line = $this->purchaseLine();
        $receipt = $this->receive([['line' => $line, 'qty' => 10.0]]);

        app(PostGoodsReceiptAction::class)->execute($receipt->id);

        // The FIFO layer is where supplier attribution lands — returns and supplier cost
        // analytics both read it.
        $layerSupplier = DB::table('inventory_receipt_layers')
            ->where('goods_receipt_line_id', $receipt->lines->first()->id)
            ->value('supplier_id');

        self::assertSame((string) $this->supplier->id, (string) $layerSupplier);
    }

    public function test_a_purchase_line_without_a_supplier_cannot_be_received(): void
    {
        $line = $this->purchaseLine(supplierId: null);

        $this->expectException(PurchaseMaterialReceivingException::class);
        $this->receive([['line' => $line, 'qty' => 10.0]]);
    }

    public function test_one_receipt_cannot_mix_two_suppliers(): void
    {
        $a = $this->purchaseLine();
        $other = Supplier::factory()->create(['company_id' => $this->company->id]);
        $b = $this->purchaseLine(supplierId: $other->id);

        $this->expectException(PurchaseMaterialReceivingException::class);
        $this->receive([['line' => $a, 'qty' => 5.0], ['line' => $b, 'qty' => 5.0]]);
    }

    // ── 3./4. Required quantity (RD-2) ──────────────────────────────────────

    public function test_required_is_agreed_qty_when_present(): void
    {
        $line = $this->purchaseLine(requestedQty: 100.0, agreedQty: 80.0);

        self::assertSame(80.0, $this->service()->requiredQty($line));
    }

    public function test_required_falls_back_to_requested_qty_when_agreed_is_null(): void
    {
        $line = $this->purchaseLine(requestedQty: 100.0, agreedQty: null);

        self::assertSame(100.0, $this->service()->requiredQty($line));
    }

    // ── 5./6. Received gross accumulates; remaining derives (RD-3) ──────────

    public function test_received_gross_accumulates_and_remaining_derives(): void
    {
        $line = $this->purchaseLine(requestedQty: 100.0);

        self::assertSame(0.0, $this->service()->receivedGross((string) $line->id));
        self::assertSame(100.0, $this->service()->remaining($line));

        $first = $this->receive([['line' => $line, 'qty' => 40.0]]);
        app(PostGoodsReceiptAction::class)->execute($first->id);

        self::assertSame(40.0, $this->service()->receivedGross((string) $line->id));
        self::assertSame(60.0, $this->service()->remaining($line->fresh()));

        $second = $this->receive([['line' => $line, 'qty' => 30.0]]);
        app(PostGoodsReceiptAction::class)->execute($second->id);

        self::assertSame(70.0, $this->service()->receivedGross((string) $line->id));
        self::assertSame(30.0, $this->service()->remaining($line->fresh()));
    }

    public function test_a_draft_receipt_does_not_count_as_received(): void
    {
        $line = $this->purchaseLine(requestedQty: 100.0);
        $this->receive([['line' => $line, 'qty' => 40.0]]); // created, never posted

        self::assertSame(0.0, $this->service()->receivedGross((string) $line->id));
    }

    // ── 7. Over-receipt is refused ──────────────────────────────────────────

    public function test_over_receipt_against_the_purchase_line_is_refused(): void
    {
        $line = $this->purchaseLine(requestedQty: 100.0);

        $first = $this->receive([['line' => $line, 'qty' => 70.0]]);
        app(PostGoodsReceiptAction::class)->execute($first->id);

        $second = $this->receive([['line' => $line, 'qty' => 40.0]]); // 70 + 40 > 100
        $this->expectException(PurchaseMaterialReceivingException::class);
        app(PostGoodsReceiptAction::class)->execute($second->id);
    }

    public function test_the_ceiling_uses_agreed_qty_when_present(): void
    {
        // Required is 50 (agreed), NOT 100 (requested) — receiving 60 must fail.
        $line = $this->purchaseLine(requestedQty: 100.0, agreedQty: 50.0);
        $receipt = $this->receive([['line' => $line, 'qty' => 60.0]]);

        $this->expectException(PurchaseMaterialReceivingException::class);
        app(PostGoodsReceiptAction::class)->execute($receipt->id);
    }

    // ── 8./9. Inventory posts exactly once ──────────────────────────────────

    public function test_inventory_is_posted_once_with_no_duplicate_ledger_entry(): void
    {
        $line = $this->purchaseLine(requestedQty: 100.0);
        $receipt = $this->receive([['line' => $line, 'qty' => 40.0]]);

        app(PostGoodsReceiptAction::class)->execute($receipt->id);

        $onHand = InventoryItem::query()
            ->where('product_id', $line->product_id)
            ->where('warehouse_id', $this->warehouse->id)
            ->value('on_hand_qty');
        self::assertSame(40.0, (float) $onHand);

        $ledgerCount = StockLedgerEntry::query()
            ->where('reference_type', 'goods_receipt')
            ->where('reference_id', $receipt->id)
            ->where('movement_type', LedgerMovementType::PurchaseReceipt->value)
            ->count();
        self::assertSame(1, $ledgerCount, 'Exactly one inbound ledger entry per posted receipt.');

        // Re-posting the same receipt must be refused, leaving inventory untouched.
        try {
            app(PostGoodsReceiptAction::class)->execute($receipt->id);
            self::fail('Re-posting a posted receipt must be refused.');
        } catch (Throwable) {
            // expected
        }

        self::assertSame(40.0, (float) InventoryItem::query()
            ->where('product_id', $line->product_id)
            ->where('warehouse_id', $this->warehouse->id)
            ->value('on_hand_qty'));
        self::assertSame(1, StockLedgerEntry::query()
            ->where('reference_type', 'goods_receipt')
            ->where('reference_id', $receipt->id)
            ->count());
    }

    // ── 10./11. Tenant + warehouse scope ────────────────────────────────────

    public function test_the_receipt_is_owned_by_the_purchase_company(): void
    {
        $line = $this->purchaseLine();
        $receipt = $this->receive([['line' => $line, 'qty' => 5.0]]);

        self::assertSame((string) $this->company->id, (string) $receipt->company_id);
    }

    public function test_stock_lands_in_the_receiving_warehouse_only(): void
    {
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $line = $this->purchaseLine();

        $receipt = $this->receive([['line' => $line, 'qty' => 12.0]]);
        app(PostGoodsReceiptAction::class)->execute($receipt->id);

        self::assertSame(12.0, (float) InventoryItem::query()
            ->where('product_id', $line->product_id)->where('warehouse_id', $this->warehouse->id)->value('on_hand_qty'));
        self::assertNull(InventoryItem::query()
            ->where('product_id', $line->product_id)->where('warehouse_id', $otherWarehouse->id)->value('on_hand_qty'));
    }

    // ── 12./13. The legacy path is untouched ────────────────────────────────

    public function test_a_receipt_cannot_mix_purchase_order_and_purchase_material_anchors(): void
    {
        $line = $this->purchaseLine();

        $dto = GoodsReceiptDTO::fromArray([
            'purchase_order_id' => null,
            'warehouse_id' => $this->warehouse->id,
            'receipt_date' => now()->toDateString(),
            'lines' => [
                [
                    'purchase_material_line_id' => $line->id,
                    'purchase_order_line_id' => (string) \Illuminate\Support\Str::uuid(),
                    'product_id' => $line->product_id,
                    'ordered_quantity' => 10.0,
                    'gross_received_quantity' => 10.0,
                    'net_received_quantity' => 10.0,
                ],
            ],
        ]);

        $this->expectException(PurchaseMaterialReceivingException::class);
        app(CreateGoodsReceiptAction::class)->execute($dto);
    }

    public function test_purchase_receipts_do_not_touch_purchase_order_counters(): void
    {
        $line = $this->purchaseLine();
        $receipt = $this->receive([['line' => $line, 'qty' => 10.0]]);

        app(PostGoodsReceiptAction::class)->execute($receipt->id);

        // Nothing in the legacy aggregate may move for a Purchase-anchored receipt.
        self::assertSame(0, DB::table('purchase_orders')->count());
        self::assertSame(0, DB::table('purchase_order_lines')->count());
    }
}
