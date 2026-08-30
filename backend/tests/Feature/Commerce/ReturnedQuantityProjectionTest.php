<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Operations\Fulfillment\Domain\Events\OrderReturnedEvent;
use Modules\Operations\Fulfillment\Domain\Models\CustomerReturn;
use Modules\Operations\Fulfillment\Domain\Models\CustomerReturnLine;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-OPERATIONAL-FULFILLMENT-RETURNS-RECONCILIATION-001 (§16) — the single canonical
 * writer of order_lines.returned_qty.
 *
 * ProjectReturnedQuantityFromCustomerReturn listens on OrderReturnedEvent and re-derives
 * returned_qty := Σ customer_return_lines.quantity_returned per order line (absolute set),
 * mirroring the certified delivered_qty projection. One authority; idempotent; writes only
 * returned_qty (never Order.status / delivered_qty / ledger / custody).
 */
class ReturnedQuantityProjectionTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();
    }

    public function test_projects_returned_qty_from_customer_return_lines_per_line(): void
    {
        $order = $this->order();
        $l1 = $this->line($order, 10.0);
        $l2 = $this->line($order, 5.0);

        $return = $this->customerReturn($order);
        $this->returnLine($return, $l1, 3.0, 'sellable');
        $this->returnLine($return, $l2, 2.0, 'damaged'); // condition governs restock, not "was returned"

        $this->fireReturned($order);

        self::assertSame(3.0, (float) $l1->fresh()->returned_qty);
        self::assertSame(2.0, (float) $l2->fresh()->returned_qty, 'damaged units still count as returned');
    }

    public function test_sums_across_multiple_returns_and_is_idempotent(): void
    {
        $order = $this->order();
        $l1 = $this->line($order, 10.0);

        $r1 = $this->customerReturn($order);
        $this->returnLine($r1, $l1, 2.0, 'sellable');
        $r2 = $this->customerReturn($order);
        $this->returnLine($r2, $l1, 1.0, 'sellable');

        $this->fireReturned($order);
        self::assertSame(3.0, (float) $l1->fresh()->returned_qty, 'Σ across both returns = 2 + 1');

        // Re-firing the projection is a no-op — absolute set from the canonical source.
        $this->fireReturned($order);
        self::assertSame(3.0, (float) $l1->fresh()->returned_qty, 'idempotent');
    }

    public function test_writes_only_returned_qty_never_status_or_delivered(): void
    {
        $order = $this->order();
        $line = $this->line($order, 10.0);
        // Pre-existing delivered_qty from the (separate) delivered projection authority.
        $line->update(['delivered_qty' => 6.0]);
        $statusBefore = $order->fresh()->status;

        $return = $this->customerReturn($order);
        $this->returnLine($return, $line, 4.0, 'sellable');

        $this->fireReturned($order);

        $line->refresh();
        self::assertSame(4.0, (float) $line->returned_qty);
        self::assertSame(6.0, (float) $line->delivered_qty, 'delivered_qty is untouched by the return projection');
        self::assertSame($statusBefore, $order->fresh()->status, 'Order.status is never written by the projection');
    }

    // ── Fixtures ──

    private function order(): Order
    {
        return Order::create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'RQ-'.Str::random(6),
            'order_date' => now()->toDateString(),
            'status' => OrderStatus::OutForDelivery->value,
            'subtotal' => 0,
            'total' => 0,
        ]);
    }

    private function line(Order $order, float $quantity): OrderLine
    {
        $product = Product::factory()->create(['company_id' => $this->company->id]);

        return $order->lines()->create([
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => 10.0,
            'line_total' => $quantity * 10.0,
        ]);
    }

    private function customerReturn(Order $order): CustomerReturn
    {
        return CustomerReturn::create([
            'company_id' => $this->company->id,
            'order_id' => $order->id,
            'return_number' => 'CR-'.Str::random(6),
            'status' => 'pending_inspection',
            'return_reason' => 'customer_changed_mind',
            'recorded_by' => (string) Str::uuid(),
        ]);
    }

    private function returnLine(CustomerReturn $return, OrderLine $line, float $qty, string $condition): CustomerReturnLine
    {
        return CustomerReturnLine::create([
            'customer_return_id' => $return->id,
            'order_line_id' => $line->id,
            'product_id' => $line->product_id,
            'sku_snapshot' => 'SKU-'.substr(md5($line->id), 0, 6),
            'name_snapshot' => 'Test Product',
            'quantity_returned' => $qty,
            'condition' => $condition,
        ]);
    }

    private function fireReturned(Order $order): void
    {
        event(new OrderReturnedEvent(
            orderId: (string) $order->id,
            orderNumber: (string) $order->order_number,
            companyId: (string) $order->company_id,
            returnId: (string) Str::uuid(),
            returnReason: 'customer_changed_mind',
            returnedAt: now()->toIso8601String(),
            actorId: (string) Str::uuid(),
        ));
    }
}
