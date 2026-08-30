<?php

declare(strict_types=1);

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Application\Actions\ReleaseOrderInventoryAction;
use Modules\Commerce\Orders\Application\Actions\ReserveOrderInventoryAction;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Enums\ReservationStatus;
use Modules\Commerce\Orders\Domain\Exceptions\OrderAlreadyReleasedException;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;
use Throwable;

/**
 * TASK-ECOS-OPERATIONS-COMPLETION-BATCH-001 — Workstream A (A-2 / A-6 / A-7).
 *
 * A-2: "Reservation Warehouse = Reservation.warehouse_id. Release must occur against the
 * same warehouse recorded by the Reservation. Do NOT rely only on
 * Order.assigned_warehouse_id because an Order may have been reassigned."
 *
 * The reassignment is real and canonical: `WarehouseAssignmentEngine::override()` rewrites
 * `assigned_warehouse_id` with no guard requiring the order to be un-reserved. Everything
 * downstream then releases against the NEW warehouse, where this order never reserved
 * anything — so the release either throws or under-releases, and the units held in the
 * ORIGINAL warehouse are stranded (A-7) with no path back.
 *
 * These tests assert the contract, not the current behaviour.
 */
final class ReservationWarehouseAuthorityTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private Warehouse $origin;

    private Warehouse $destination;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->origin = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->destination = Warehouse::factory()->create(['company_id' => $this->company->id]);
    }

    private function product(): Product
    {
        return Product::factory()->finishedGood()->create(['company_id' => $this->company->id]);
    }

    private function stock(Product $product, Warehouse $warehouse, float $onHand): InventoryItem
    {
        return InventoryItem::query()->create([
            'warehouse_id' => $warehouse->id,
            'product_id' => $product->id,
            'company_id' => $this->company->id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => 0.0,
        ]);
    }

    private function order(Product $product, Warehouse $warehouse, float $qty = 5.0): Order
    {
        $order = Order::create([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'assigned_warehouse_id' => $warehouse->id,
            'order_number' => 'RWA-'.Str::random(8),
            'order_date' => now()->toDateString(),
            'status' => OrderStatus::InProgress->value,
            'subtotal' => 0,
            'total' => 0,
        ]);

        $order->lines()->create([
            'product_id' => $product->id,
            'quantity' => $qty,
            'unit_price' => 10.0,
            'line_total' => 10.0 * $qty,
        ]);

        return $order->refresh();
    }

    private function reservedQty(Product $product, Warehouse $warehouse): float
    {
        return (float) InventoryItem::query()
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->value('reserved_qty');
    }

    /** Canonical reassignment — the same column write `WarehouseAssignmentEngine::override()` performs. */
    private function reassign(Order $order, Warehouse $to): void
    {
        $order->forceFill(['assigned_warehouse_id' => $to->id])->saveQuietly();
        $order->refresh();
    }

    // ── Baseline: without reassignment the existing behaviour is correct ──────

    public function test_release_without_reassignment_returns_the_units(): void
    {
        $product = $this->product();
        $this->stock($product, $this->origin, 50.0);

        $order = $this->order($product, $this->origin);
        app(ReserveOrderInventoryAction::class)->execute($order);
        $order->refresh();

        self::assertSame(ReservationStatus::Reserved, $order->reservation_status);
        self::assertSame(5.0, $this->reservedQty($product, $this->origin));

        app(ReleaseOrderInventoryAction::class)->execute($order);

        self::assertSame(0.0, $this->reservedQty($product, $this->origin));
    }

    // ── A-2 — the reassignment case ──────────────────────────────────────────

    /**
     * A-2 / A-7. The units were reserved in `origin`. After reassignment the release must
     * still return them THERE — the warehouse the reservation itself recorded — otherwise
     * they are stranded for ever.
     */
    public function test_release_after_reassignment_frees_the_reserving_warehouse(): void
    {
        $product = $this->product();
        $this->stock($product, $this->origin, 50.0);
        $this->stock($product, $this->destination, 50.0);

        $order = $this->order($product, $this->origin);
        app(ReserveOrderInventoryAction::class)->execute($order);
        $order->refresh();

        self::assertSame(5.0, $this->reservedQty($product, $this->origin), 'precondition: held in origin');

        $this->reassign($order, $this->destination);

        app(ReleaseOrderInventoryAction::class)->execute($order);

        self::assertSame(
            0.0,
            $this->reservedQty($product, $this->origin),
            'A-7: the reserving warehouse must be freed, not stranded',
        );
    }

    /**
     * A-6. The destination never held a reservation for this order, so releasing against it
     * must not drive its `reserved_qty` below zero — nor silently "release" units it never held.
     */
    public function test_release_after_reassignment_does_not_disturb_the_destination(): void
    {
        $product = $this->product();
        $this->stock($product, $this->origin, 50.0);
        $this->stock($product, $this->destination, 50.0);

        $order = $this->order($product, $this->origin);
        app(ReserveOrderInventoryAction::class)->execute($order);
        $order->refresh();

        $this->reassign($order, $this->destination);

        app(ReleaseOrderInventoryAction::class)->execute($order);

        self::assertSame(
            0.0,
            $this->reservedQty($product, $this->destination),
            'A-6: a warehouse that never reserved must never go negative or be mutated',
        );
        self::assertGreaterThanOrEqual(
            0.0,
            $this->reservedQty($product, $this->destination),
            'A-6: reserved_qty must never be negative',
        );
    }

    /**
     * The release must not fail merely because the destination has no inventory row for the
     * product — a completely ordinary situation after a cross-warehouse reassignment.
     */
    public function test_release_succeeds_when_destination_has_no_inventory_row(): void
    {
        $product = $this->product();
        $this->stock($product, $this->origin, 50.0);
        // destination deliberately has NO inventory_items row for this product

        $order = $this->order($product, $this->origin);
        app(ReserveOrderInventoryAction::class)->execute($order);
        $order->refresh();

        $this->reassign($order, $this->destination);

        app(ReleaseOrderInventoryAction::class)->execute($order);

        $order->refresh();
        self::assertSame(ReservationStatus::Released, $order->reservation_status);
        self::assertSame(0.0, $this->reservedQty($product, $this->origin));
    }

    // ── A-4 — idempotency is preserved ───────────────────────────────────────

    /** Releasing twice must not release the same reservation twice. */
    public function test_double_release_is_rejected(): void
    {
        $product = $this->product();
        $this->stock($product, $this->origin, 50.0);

        $order = $this->order($product, $this->origin);
        app(ReserveOrderInventoryAction::class)->execute($order);
        $order->refresh();

        app(ReleaseOrderInventoryAction::class)->execute($order);
        $order->refresh();

        $this->expectException(OrderAlreadyReleasedException::class);
        app(ReleaseOrderInventoryAction::class)->execute($order);
    }

    /** And the quantity must not drift when the second attempt is rejected. */
    public function test_double_release_does_not_double_decrement(): void
    {
        $product = $this->product();
        $this->stock($product, $this->origin, 50.0);

        $order = $this->order($product, $this->origin);
        app(ReserveOrderInventoryAction::class)->execute($order);
        $order->refresh();

        app(ReleaseOrderInventoryAction::class)->execute($order);
        $order->refresh();

        try {
            app(ReleaseOrderInventoryAction::class)->execute($order);
        } catch (Throwable) {
            // expected — asserted in the test above
        }

        self::assertSame(0.0, $this->reservedQty($product, $this->origin), 'no double decrement');
    }
}
