<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Logistics\Distribution\Domain\Models\DistributionWindowOrder;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;
use Modules\Logistics\Distribution\Domain\Services\DistributionCollectionService;
use Modules\Logistics\Distribution\Domain\Services\DistributionWindowService;
use Modules\Logistics\Distribution\Domain\Services\ManualAssignmentService;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-SHIPPING-DISTRIBUTION-CORE-002 — TEST 12, the Warehouse boundary.
 *
 * The assertion is deliberately NOT "distribution_window_orders.warehouse_id equals
 * the assigned warehouse". No such column exists, and adding one purely to satisfy
 * a test would hand Warehouse selection to Shipping — the exact inversion PART 17
 * forbids.
 *
 * What is proven instead is the real contract: Distribution RETAINS the Warehouse
 * chosen upstream by Governorate + Zone + Brand Coverage, and never alters it, at
 * any point in its lifecycle — collection, zone/slot assignment, or individual
 * reassignment between slots.
 */
class DistributionWarehouseBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_12_distribution_retains_the_assigned_warehouse_and_never_changes_it(): void
    {
        config()->set('distribution.window.opens_at', '08:00');
        config()->set('distribution.window.closes_at', '14:00');

        $company = Company::factory()->create();
        $customer = Customer::factory()->create();
        $warehouse = Warehouse::factory()->create(['company_id' => $company->id, 'is_active' => true]);
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $company->id, 'is_active' => true]);

        $gov = DB::table('logistics_governorates')->insertGetId([
            'country_id' => 1,
            'name_ar' => 'محافظة',
            'name_en' => 'Governorate',
            'default_shipping_price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $zone = (int) DB::table('distribution_zones')->insertGetId([
            'code' => 'WB-'.substr(uniqid(), -5),
            'name_ar' => 'Zone', 'name_en' => 'Zone',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $city = (int) DB::table('logistics_cities')->insertGetId([
            'governorate_id' => $gov,
            'name_ar' => 'City', 'name_en' => 'City',
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('logistics_cities')->where('id', $city)->update(['distribution_zone_id' => $zone]);

        // The Order carries a warehouse decided upstream. Distribution is handed it.
        $order = Order::query()->create([
            'company_id' => $company->id,
            'customer_id' => $customer->id,
            'order_number' => 'ORD-WB-'.uniqid(),
            'order_date' => now()->toDateString(),
            'logistics_city_id' => $city,
            'assigned_warehouse_id' => $warehouse->id,
            'status' => 'in_progress',
            'subtotal' => 100, 'total' => 100,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);

        self::assertSame($warehouse->id, $order->fresh()->assigned_warehouse_id);

        $at = CarbonImmutable::parse(CarbonImmutable::now()->toDateString().' 10:00:00');
        $windowService = app(DistributionWindowService::class);
        $window = $windowService->windowFor($company->id, $at->toDateString(), $at);

        // ── Collection ───────────────────────────────────────────────────────────
        app(DistributionCollectionService::class)->collectForCompany($company->id, $at);

        $assignment = DistributionWindowOrder::query()->where('order_id', $order->id)->first();
        self::assertNotNull($assignment, 'Order must be collected into the Window.');
        self::assertSame($zone, $assignment->distribution_zone_id);
        self::assertSame(
            $warehouse->id,
            $order->fresh()->assigned_warehouse_id,
            'Collection must not alter the Order Warehouse.',
        );

        // ── Zone → Slot assignment ───────────────────────────────────────────────
        $manual = app(ManualAssignmentService::class);
        // The Group must be owned by the Order's OWN warehouse, or it would have no
        // claim on that Order at all.
        $slotA = $this->makeSlot($window->id, $company->id, 'A', $warehouse->id);
        $manual->assignZoneToSlot($window, $zone, $slotA);

        self::assertSame(
            $warehouse->id,
            $order->fresh()->assigned_warehouse_id,
            'Slot planning must not alter the Order Warehouse.',
        );

        // ── Individual reassignment between Slots ────────────────────────────────
        $slotB = $this->makeSlot($window->id, $company->id, 'B', $warehouse->id);
        $manual->changeOrderSlot($assignment->fresh(), $slotB, null, 'boundary probe');

        $order = $order->fresh();
        self::assertSame($slotB->id, $assignment->fresh()->virtual_slot_id);
        self::assertSame(
            $warehouse->id,
            $order->assigned_warehouse_id,
            'Individual reassignment must not alter the Order Warehouse.',
        );
        self::assertNotSame($otherWarehouse->id, $order->assigned_warehouse_id);

        // ── And Distribution owns no warehouse of its own ────────────────────────
        $columns = collect(DB::select('SHOW COLUMNS FROM distribution_window_orders'))
            ->pluck('Field')
            ->all();

        self::assertNotContains(
            'warehouse_id',
            $columns,
            'Distribution must not own a Warehouse column — Warehouse selection belongs upstream.',
        );
    }

    private function makeSlot(string $windowId, string $companyId, string $code, ?string $warehouseId = null): VirtualCapacitySlot
    {
        return VirtualCapacitySlot::query()->create([
            'company_id' => $companyId,
            'distribution_window_id' => $windowId,
            // A Distribution Group is owned by exactly one warehouse (Part 5B);
            // the column is NOT NULL, so a fixture must name the owner.
            'warehouse_id' => $warehouseId ?? $this->slotWarehouseId($companyId),
            'code' => $code.'-'.substr(uniqid(), -5),
            'name' => 'Slot '.$code,
            'capacity_orders' => 100,
        ]);
    }

    /**
     * A warehouse to own a fixture Group.
     *
     * Part 5B: `distribution_virtual_slots.warehouse_id` is NOT NULL, because a
     * Distribution Group is the planning container for exactly ONE warehouse.
     * Memoised per company so repeated fixtures reuse the same warehouse.
     *
     * @var array<string, string>
     */
    private array $slotWarehouses = [];

    private function slotWarehouseId(string $companyId): string
    {
        return $this->slotWarehouses[$companyId] ??= Warehouse::factory()
            ->create(['company_id' => $companyId])->id;
    }
}
