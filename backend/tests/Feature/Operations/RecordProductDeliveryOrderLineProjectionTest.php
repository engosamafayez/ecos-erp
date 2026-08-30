<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\Loading\Application\Actions\LoadProductAction;
use Modules\Operations\Loading\Application\Actions\RecordProductDeliveryAction;
use Modules\Operations\Loading\Domain\Enums\AllocationRecordStatus;
use Modules\Operations\Loading\Domain\Enums\DriverAssignmentStatus;
use Modules\Operations\Loading\Domain\Enums\LoadingSessionStatus;
use Modules\Operations\Loading\Domain\Enums\SessionType;
use Modules\Operations\Loading\Domain\Enums\VehicleAssignmentStatus;
use Modules\Operations\Loading\Domain\Models\AllocationRecord;
use Modules\Operations\Loading\Domain\Models\DriverAssignment;
use Modules\Operations\Loading\Domain\Models\LoadingSession;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use Modules\Operations\Loading\Domain\Models\VehicleInventoryItem;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-DRIVER-04 (decision A) — the canonical delivered-quantity PROJECTION.
 *
 * ┌─ WHAT THIS PINS ─────────────────────────────────────────────────────────┐
 * │ `allocation_records.quantity_delivered` (ADR-015, sole writer             │
 * │ RecordProductDeliveryAction) is the single source of truth for how much   │
 * │ of a line was actually delivered. Commerce `order_lines.delivered_qty`    │
 * │ was an unpopulated projection column. This suite proves the projection    │
 * │ now runs: recording a delivery re-derives order_lines.delivered_qty as    │
 * │ Σ allocation_records.quantity_delivered for the line — deterministically  │
 * │ and idempotently — WITHOUT inventing a second delivered-quantity source,  │
 * │ WITHOUT touching Order.status/reservations/warehouse stock, and WHILE the │
 * │ existing vehicle-custody movement still happens.                          │
 * └───────────────────────────────────────────────────────────────────────────┘
 *
 * Every delivery flows through the REAL RecordProductDeliveryAction; nothing
 * writes quantity_delivered onto a row by hand. The projection is exercised via
 * the real event → listener wiring (ProductDeliveryRecorded →
 * ProjectDeliveredQuantityFromAllocation) — events are NOT faked here.
 */
class RecordProductDeliveryOrderLineProjectionTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private User $user;

    private Product $product;

    private Customer $customer;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->user = User::factory()->create(['company_id' => $this->company->id]);
        $this->product = Product::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
    }

    // ── Core projection ──────────────────────────────────────────────────────────

    public function test_recording_a_partial_delivery_projects_delivered_qty_onto_the_order_line(): void
    {
        $line = $this->orderLine(10.0);
        $shift = $this->makeShift();
        $record = $this->allocate($this->load($shift, 10.0), $line, 10.0);

        $this->deliver($record, 7.0);

        self::assertSame(
            7.0,
            $this->lineDeliveredQty($line),
            'a partial delivery of 7 projects delivered_qty = 7 onto the order line',
        );
    }

    public function test_a_full_delivery_projects_the_full_quantity(): void
    {
        $line = $this->orderLine(10.0);
        $shift = $this->makeShift();
        $record = $this->allocate($this->load($shift, 10.0), $line, 10.0);

        $this->deliver($record, 10.0);

        self::assertSame(10.0, $this->lineDeliveredQty($line));
    }

    public function test_the_projection_is_idempotent_on_replay(): void
    {
        $line = $this->orderLine(10.0);
        $shift = $this->makeShift();
        $record = $this->allocate($this->load($shift, 10.0), $line, 10.0);

        // A driver on a flaky connection confirms the same 7 twice. The canonical
        // writer is an absolute set, and the projection re-derives from it, so the
        // order line reads 7 — never 14.
        $this->deliver($record, 7.0);
        $this->deliver($record->refresh(), 7.0);

        self::assertSame(7.0, $this->lineDeliveredQty($line), 'replaying the same delivery is a no-op, not a double count');
    }

    public function test_delivered_qty_sums_across_multiple_allocations_for_one_line(): void
    {
        // A split shipment: one order line spread across TWO vehicles. Each vehicle
        // delivers its share; the order line must reflect the SUM.
        $line = $this->orderLine(10.0);

        $shiftA = $this->makeShift();
        $recordA = $this->allocate($this->load($shiftA, 4.0), $line, 4.0);

        $shiftB = $this->makeShift();
        $recordB = $this->allocate($this->load($shiftB, 3.0), $line, 3.0);

        $this->deliver($recordA, 4.0);
        self::assertSame(4.0, $this->lineDeliveredQty($line), 'first vehicle: 4 so far');

        $this->deliver($recordB, 3.0);
        self::assertSame(7.0, $this->lineDeliveredQty($line), 'second vehicle adds 3 → the line reads 4 + 3 = 7');
    }

    // ── Boundaries: custody moves, warehouse + order status do not ─────────────────

    public function test_delivery_moves_vehicle_custody_yet_the_projection_touches_only_the_order_line(): void
    {
        $line = $this->orderLine(10.0);
        $shift = $this->makeShift();
        $item = $this->load($shift, 10.0);
        $record = $this->allocate($item, $line, 10.0);

        $statusBefore = (string) DB::table('orders')->where('id', $line->order_id)->value('status');
        $warehouseLedgerBefore = (int) DB::table('stock_ledger_entries')
            ->where('product_id', $this->product->id)->count();

        $this->deliver($record, 7.0);

        // The order line is projected …
        self::assertSame(7.0, $this->lineDeliveredQty($line));

        // … vehicle custody moved through the EXISTING canonical mechanism …
        $freshItem = VehicleInventoryItem::query()->whereKey($item->id)->firstOrFail();
        self::assertSame(7.0, (float) $freshItem->quantity_delivered, 'vehicle custody recorded the delivery');
        self::assertSame(
            1,
            (int) DB::table('vehicle_inventory_movements')
                ->where('vehicle_inventory_item_id', $item->id)
                ->where('movement_type', 'delivered')
                ->count(),
            'the existing Delivered vehicle-custody movement was appended',
        );

        // … and NOTHING else moved: no Order.status change (that is FulfillmentEngine's),
        // and no warehouse stock ledger entry (delivery is warehouse-stock-neutral).
        self::assertSame(
            $statusBefore,
            (string) DB::table('orders')->where('id', $line->order_id)->value('status'),
            'the projection never touches Order.status',
        );
        self::assertSame(
            $warehouseLedgerBefore,
            (int) DB::table('stock_ledger_entries')->where('product_id', $this->product->id)->count(),
            'delivery creates no warehouse stock movement',
        );
    }

    // ── Fixtures — real writers only ──────────────────────────────────────────────

    private function orderLine(float $quantity): OrderLine
    {
        $order = Order::query()->create([
            'company_id' => $this->company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-'.strtoupper(substr(md5(uniqid('', true)), 0, 8)),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $this->warehouse->id,
            'city' => 'Cairo',
            'governorate' => 'Cairo',
            'status' => 'in_progress',
            'subtotal' => 100, 'total' => 100,
            'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);

        return OrderLine::query()->create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'unit_price' => 10,
            'line_total' => $quantity * 10,
        ]);
    }

    private function makeShift(): VehicleAssignment
    {
        $suffix = substr(md5(uniqid('', true)), 0, 8);

        $session = LoadingSession::create([
            'company_id' => $this->company->id,
            'warehouse_id' => (string) Str::uuid(),
            'session_number' => 'LS-'.$suffix,
            'operational_date' => '2026-08-26',
            'status' => LoadingSessionStatus::Loading->value,
            'session_type' => SessionType::Standard->value,
            'created_by' => (string) $this->user->id,
            'updated_by' => (string) $this->user->id,
        ]);

        $assignment = VehicleAssignment::create([
            'company_id' => $this->company->id,
            'loading_session_id' => $session->id,
            'vehicle_id' => (string) Str::uuid(),
            'vehicle_registration_snapshot' => 'REG-'.$suffix,
            'vehicle_type_snapshot' => 'van',
            'capacity_weight_kg_snapshot' => 1000,
            'capacity_volume_m3_snapshot' => 10,
            'assignment_number' => 'VA-'.$suffix,
            'status' => VehicleAssignmentStatus::Loading->value,
            'created_by' => (string) $this->user->id,
            'updated_by' => (string) $this->user->id,
        ]);

        DriverAssignment::create([
            'company_id' => $this->company->id,
            'vehicle_assignment_id' => $assignment->id,
            'loading_session_id' => $session->id,
            'vehicle_id' => $assignment->vehicle_id,
            'driver_id' => (string) Str::uuid(),
            'driver_name_snapshot' => 'Test Driver',
            'status' => DriverAssignmentStatus::Assigned->value,
            'assigned_by' => (string) $this->user->id,
            'created_by' => (string) $this->user->id,
            'updated_by' => (string) $this->user->id,
        ]);

        return $assignment->refresh();
    }

    private function load(VehicleAssignment $assignment, float $quantity): VehicleInventoryItem
    {
        app(LoadProductAction::class)->execute(
            assignment: $assignment,
            poolEntryId: (string) Str::uuid(),
            productId: $this->product->id,
            skuSnapshot: 'SKU-'.substr(md5($this->product->id), 0, 6),
            nameSnapshot: 'Test Product',
            preparationWaveId: (string) Str::uuid(),
            quantityPlanned: $quantity,
            quantityLoaded: $quantity,
            loadedBy: (string) $this->user->id,
        );

        return VehicleInventoryItem::where('vehicle_assignment_id', $assignment->id)
            ->where('product_id', $this->product->id)
            ->firstOrFail();
    }

    private function allocate(VehicleInventoryItem $item, OrderLine $line, float $quantity): AllocationRecord
    {
        return AllocationRecord::create([
            'company_id' => $item->company_id,
            'vehicle_assignment_id' => $item->vehicle_assignment_id,
            'loading_session_id' => VehicleAssignment::find($item->vehicle_assignment_id)?->loading_session_id,
            'vehicle_id' => $item->vehicle_id,
            'order_id' => $line->order_id,
            'order_line_id' => $line->id,
            'order_number_snapshot' => 'ORD-'.substr(md5(uniqid('', true)), 0, 6),
            'product_id' => $item->product_id,
            'sku_snapshot' => $item->sku_snapshot,
            'vehicle_inventory_item_id' => $item->id,
            'allocation_mode' => 'full_auto',
            'priority_rank' => 1,
            'quantity_requested' => $quantity,
            'quantity_allocated' => $quantity,
            'quantity_loaded' => 0.0,
            'quantity_delivered' => 0.0,
            'quantity_remaining' => $quantity,
            'is_partial' => false,
            'status' => AllocationRecordStatus::Allocated->value,
            'allocated_at' => now(),
            'allocated_by' => 'system',
            'created_by' => (string) $this->user->id,
            'updated_by' => (string) $this->user->id,
        ]);
    }

    private function deliver(AllocationRecord $record, float $quantity): void
    {
        app(RecordProductDeliveryAction::class)->execute(
            $record,
            $quantity,
            (string) $this->user->id,
            'driver',
        );
    }

    private function lineDeliveredQty(OrderLine $line): float
    {
        return (float) DB::table('order_lines')->where('id', $line->id)->value('delivered_qty');
    }
}
