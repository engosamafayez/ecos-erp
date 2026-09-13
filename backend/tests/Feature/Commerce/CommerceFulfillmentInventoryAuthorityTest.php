<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Commerce\Fulfillments\Application\Actions\FulfillFulfillmentAction;
use Modules\Commerce\Fulfillments\Domain\Enums\FulfillmentStatus;
use Modules\Commerce\Fulfillments\Domain\Exceptions\FulfillmentNotFulfillableException;
use Modules\Commerce\Fulfillments\Domain\Models\Fulfillment;
use Modules\Commerce\Fulfillments\Domain\Models\FulfillmentLine;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Purchasing\GoodsReceipts\Domain\Models\StockBalance;
use Tests\TestCase;

/**
 * TASK-...-CONSOLIDATED-REMEDIATION-001 (fifth-track fulfillment-inventory-authority closure) —
 * the live Commerce Fulfillment `fulfill` path must not maintain a competing physical-stock truth.
 * Physical warehouse stock is owned solely by InventoryItem + the canonical Inventory actions
 * (ShipOrderInventoryAction → ShipStockAction, with FIFO/COGS and the Order.inventory_shipped_at
 * double-issue guard); FulfillFulfillmentAction is now commercial-only and issues no physical stock.
 *
 * Per the CTO's Track-2 execution model these are written as source-level regression coverage but
 * their EXECUTION is deferred to the consolidated test pass — no MySQL/PHPUnit cycle was launched
 * for this ticket.
 */
final class CommerceFulfillmentInventoryAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private Product $product;

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->product = Product::factory()->create([
            'company_id' => $this->company->id,
            'product_type' => Product::TYPE_RAW_MATERIAL,
        ]);
        $this->order = Order::factory()->create(['company_id' => $this->company->id]);
    }

    private function inventoryItem(float $onHand = 100.0, float $reserved = 0.0): InventoryItem
    {
        return InventoryItem::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $this->product->id,
            'company_id' => $this->company->id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => $reserved,
        ]);
    }

    private function pendingFulfillment(float $quantity = 10.0): Fulfillment
    {
        $fulfillment = Fulfillment::query()->create([
            'fulfillment_number' => 'FUL-TEST-'.substr($this->order->id, 0, 8),
            'order_id' => $this->order->id,
            'warehouse_id' => $this->warehouse->id,
            'fulfillment_date' => now()->toDateString(),
            'status' => FulfillmentStatus::Pending->value,
        ]);

        FulfillmentLine::query()->create([
            'fulfillment_id' => $fulfillment->id,
            'product_id' => $this->product->id,
            'quantity' => $quantity,
        ]);

        return $fulfillment->fresh();
    }

    public function test_fulfilling_does_not_mutate_inventoryitem_physical_stock(): void
    {
        $item = $this->inventoryItem(onHand: 100.0);
        $fulfillment = $this->pendingFulfillment(quantity: 10.0);

        app(FulfillFulfillmentAction::class)->execute($fulfillment->id);

        // InventoryItem is the sole physical authority — the commercial fulfill path leaves it
        // exactly as the canonical dispatch flow left it.
        $this->assertSame(100.0, (float) $item->fresh()->on_hand_qty);
        $this->assertSame(0.0, (float) $item->fresh()->reserved_qty);
    }

    public function test_fulfilling_does_not_write_a_competing_stockbalance_row(): void
    {
        $this->inventoryItem();
        $fulfillment = $this->pendingFulfillment();

        app(FulfillFulfillmentAction::class)->execute($fulfillment->id);

        // The retired competing physical ledger is never written by this path any more.
        $this->assertSame(0, StockBalance::query()
            ->where('warehouse_id', $this->warehouse->id)
            ->where('product_id', $this->product->id)
            ->count());
    }

    public function test_fulfilling_marks_the_record_fulfilled_commercial_only(): void
    {
        $fulfillment = $this->pendingFulfillment();

        $result = app(FulfillFulfillmentAction::class)->execute($fulfillment->id);

        $this->assertTrue($result->isSuccess());
        $this->assertSame(FulfillmentStatus::Fulfilled, $fulfillment->fresh()->status);
    }

    public function test_repeated_fulfill_is_blocked_and_causes_no_physical_effect(): void
    {
        $item = $this->inventoryItem(onHand: 100.0);
        $fulfillment = $this->pendingFulfillment();

        app(FulfillFulfillmentAction::class)->execute($fulfillment->id);

        // The Pending-status guard blocks any re-fulfilment — there is no path to a second issue.
        $this->expectException(FulfillmentNotFulfillableException::class);

        try {
            app(FulfillFulfillmentAction::class)->execute($fulfillment->id);
        } finally {
            $this->assertSame(100.0, (float) $item->fresh()->on_hand_qty);
        }
    }

    public function test_canonical_cogs_is_untouched_by_the_commercial_fulfillment(): void
    {
        $this->inventoryItem();
        $fulfillment = $this->pendingFulfillment();

        app(FulfillFulfillmentAction::class)->execute($fulfillment->id);

        // COGS/margin remain owned by the canonical dispatch path (ShipOrderInventoryAction);
        // the commercial fulfill never stamps them.
        $this->assertNull($this->order->fresh()->actual_cogs_amount);
    }
}
