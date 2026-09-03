<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Logistics\Distribution\Domain\Exceptions\DistributionException;
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
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-CUSTOMERS-009-R1 (§3).
 *
 * Task-scoped runtime harness for exactly the two ManualAssignmentService::
 * assignLateOrder() regression tests added to DistributionCoreTest by Task 4
 * (§22) — copied VERBATIM from that class (test bodies, fixture helpers, and
 * imports it depends on), not reimplemented. The two tests were written and
 * statically verified in Task 4 but never executed, because DistributionCoreTest
 * has no minimal-schema override of its own and the R1 task explicitly forbids
 * running its full migration chain.
 *
 * This class exists ONLY to give those two tests a disposable, hand-traced
 * minimal MySQL schema to run against — the same technique CustomerBlockingTest/
 * BlockedOrderFulfillmentTest established in Task 4. It is not a replacement for
 * DistributionCoreTest and does not re-certify anything else in that suite.
 *
 * Production source under test is completely unchanged by this remediation —
 * see ManualAssignmentService::assignLateOrder() and PreparationEligibilityReader
 * ::isEligible(), both already shipped at checkpoint 65d10725.
 */
final class AssignLateOrderEligibilityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Base list is IDENTICAL to BlockedOrderFulfillmentTest::migrateFreshUsing()
     * (Task 4's proven, 32/32-green minimal schema) — reused wholesale rather than
     * re-derived, since PreparationEligibilityReader::isEligible() (called by
     * assignLateOrder()) reads preparation_wave_orders and Order::create() must
     * succeed against the exact same orders schema that suite already proved
     * sufficient. Added on top: the Distribution-module tables DistributionCoreTest's
     * own fixtures need (windows/slots/slot-zones/window-orders/warehouse-ownership)
     * and the Logistics/Geography tables the Zone→City chain resolves through
     * (governorates, cities, the distribution_zone_id column on cities, and
     * orders.logistics_city_id) — none of which BlockedOrderFulfillmentTest touched.
     */
    protected function migrateFreshUsing()
    {
        return array_merge([
            '--drop-views' => $this->shouldDropViews(),
            '--drop-types' => $this->shouldDropTypes(),
            '--seed' => $this->shouldSeed(),
        ], [
            '--path' => [
                'database/migrations/0001_01_01_000000_create_users_table.php',
                'database/migrations/2026_07_07_000002_add_company_id_to_users_table.php',
                'Modules/Organization/Companies/Infrastructure/Database/Migrations',
                'Modules/IAM/Infrastructure/Database/Migrations',
                'Modules/MasterData/Units/Infrastructure/Database/Migrations',
                'Modules/MasterData/Categories/Infrastructure/Database/Migrations/2026_06_23_100100_create_categories_table.php',
                'database/migrations/2026_07_02_300000_add_type_to_categories_table.php',
                'Modules/MasterData/Categories/Infrastructure/Database/Migrations/2026_07_04_100000_refactor_categories_type_to_scope.php',
                'Modules/Organization/Brands/Infrastructure/Database/Migrations/2026_07_05_140000_create_brands_table.php',
                'Modules/Organization/Branches/Infrastructure/Database/Migrations/2026_06_22_130000_create_branches_table.php',
                'Modules/MasterData/Warehouses/Infrastructure/Database/Migrations/2026_06_23_100200_create_warehouses_table.php',
                'Modules/MasterData/Warehouses/Infrastructure/Database/Migrations/2026_07_05_160000_remove_branch_id_from_warehouses_table.php',
                'Modules/Inventory/Products/Infrastructure/Database/Migrations/2026_06_23_110000_create_products_table.php',
                'Modules/Inventory/Products/Infrastructure/Database/Migrations/2026_06_23_111000_add_enrichment_fields_to_products_table.php',
                'Modules/Inventory/Products/Infrastructure/Database/Migrations/2026_06_25_230002_add_cost_intelligence_to_products_table.php',
                'Modules/Inventory/Products/Infrastructure/Database/Migrations/2026_06_25_250002_add_current_fifo_cost_to_products_table.php',
                'Modules/Inventory/Products/Infrastructure/Database/Migrations/2026_06_29_000001_add_manufacturing_fields_to_products_table.php',
                'Modules/Sales/Customers/Tests/Support/Migrations/2026_07_06_000001_add_company_id_to_products_table_test_only.php',
                'Modules/Sales/Customers/Tests/Support/Migrations/2026_07_06_100001_migrate_products_to_brand_ownership_test_only.php',
                'Modules/Inventory/InventoryItems/Infrastructure/Database/Migrations/2026_06_24_800000_create_inventory_items_table.php',
                'Modules/Inventory/InventoryItems/Infrastructure/Database/Migrations/2026_06_24_810000_create_stock_ledger_entries_table.php',
                'Modules/Inventory/InventoryItems/Infrastructure/Database/Migrations/2026_07_20_100000_fix_inventory_items_soft_delete_unique.php',
                'Modules/Commerce/Channels/Infrastructure/Database/Migrations/2026_06_23_170000_create_channels_table.php',
                'Modules/Commerce/Channels/Infrastructure/Database/Migrations/2026_06_23_600000_add_sync_customers_and_webhook_ids_to_channels.php',
                'Modules/Commerce/ProductMappings/Infrastructure/Database/Migrations/2026_06_23_180000_create_product_channel_mappings_table.php',
                'Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_07_05_100100_create_preparation_waves_table.php',
                'Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_07_05_100200_create_preparation_wave_orders_table.php',
                'Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_08_13_100000_add_postponed_at_to_preparation_wave_orders.php',
                'Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_08_15_100002_add_membership_release_to_preparation_wave_orders.php',
                'Modules/Operations/DemandAnalysis/Infrastructure/Database/Migrations/2026_07_16_200001_create_wave_material_demand_table.php',
                'database/migrations/2026_07_05_200100_create_feature_flags_table.php',
                'Modules/CostManagement/Infrastructure/Database/Migrations/2026_07_02_200004_create_pricing_reviews_table.php',
                'Modules/Manufacturing/BillsOfMaterials/Infrastructure/Database/Migrations/2026_06_23_220000_create_bills_of_materials_tables.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_15_000001_create_order_notes_table.php',
                'database/migrations/2026_07_16_000001_create_enterprise_events_table.php',
                'database/migrations/2026_07_16_000002_create_enterprise_event_processing_log_table.php',
                'database/migrations/2026_07_16_000003_create_enterprise_dead_letter_queue_table.php',
                'Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_07_06_210005_add_assignment_fields_to_orders_table.php',
                'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_07_16_000001_create_distribution_zones_table.php',
                'Modules/Logistics/ShippingCompanies/Infrastructure/Database/Migrations/2026_07_23_100000_create_logistics_shipping_companies_table.php',
                'Modules/Logistics/Drivers/Infrastructure/Database/Migrations/2026_07_24_100000_create_logistics_vehicles_table.php',
                'Modules/Logistics/Drivers/Infrastructure/Database/Migrations/2026_07_24_100001_create_logistics_drivers_table.php',
                'Modules/Logistics/Drivers/Infrastructure/Database/Migrations/2026_07_24_100003_create_logistics_driver_vehicle_assignments_table.php',
                'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_07_28_100000_create_distribution_trips_table.php',
                'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_07_28_100001_create_distribution_trip_orders_table.php',
                'Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_07_06_110000_create_preparation_sessions_table.php',
                'Modules/Operations/Preparation/Infrastructure/Database/Migrations/2026_07_06_210004_create_preparation_session_orders_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_06_23_200000_create_orders_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_06_23_200001_create_order_lines_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_14_100001_add_fulfillment_quantities_to_order_lines.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_06_23_201000_add_ecommerce_fields_to_orders_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_06_23_210000_add_billing_financials_fees_coupons_to_orders.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_06_25_220002_add_assigned_warehouse_to_orders_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_06_25_240000_add_inventory_lifecycle_to_orders_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_06_100003_add_manual_order_fields_to_orders_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_06_200000_add_location_to_orders_table.php',
                'database/migrations/2026_07_14_000001_add_enterprise_address_fields_to_orders.php',
                'database/migrations/2026_07_14_000002_add_customer_snapshot_fields_to_orders.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_06_300000_create_order_events_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_06_600000_add_delivery_fields_to_orders_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_10_000001_add_confirmed_at_to_orders_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_11_000001_add_customer_name_to_orders_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_14_100000_enhance_order_events_for_activity_timeline.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_14_100002_add_internal_notes_and_creator_to_orders.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_15_100000_extend_order_events_enterprise_audit.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_15_200000_add_actor_role_to_order_events.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_15_300000_add_actor_email_to_order_events.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_18_100000_add_reservation_status_to_orders_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_18_100001_create_order_reservation_audits_table.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_07_08_910002_add_preparation_completed_at_to_orders_table.php',
                'database/migrations/2026_07_13_000002_add_reschedule_fields_to_orders.php',
                'Modules/Commerce/Orders/Infrastructure/Database/Migrations/2026_09_15_100002_add_hold_reason_code_to_orders_table.php',
                'Modules/Sales/Customers/Infrastructure/Database/Migrations/2026_06_23_160000_create_customers_table.php',
                'Modules/Sales/Customers/Infrastructure/Database/Migrations/2026_07_06_100001_create_customer_addresses_table.php',
                'Modules/Sales/Customers/Infrastructure/Database/Migrations/2026_07_08_910001_add_company_id_to_customers_table.php',
                'Modules/Sales/Customers/Infrastructure/Database/Migrations/2026_07_22_200000_create_customer_brands_table.php',
                'Modules/Sales/Customers/Infrastructure/Database/Migrations/2026_09_15_100000_create_customer_blocks_table.php',
                'Modules/Sales/Customers/Infrastructure/Database/Migrations/2026_09_15_100001_create_order_block_overrides_table.php',
                // ── Additions for THIS harness — the Zone→City geography chain and
                // Distribution's Window/Slot/assignment tables, none of which the
                // Blocked Customer suites above ever touched. ──
                'Modules/Logistics/Geography/Infrastructure/Database/Migrations/2026_07_12_100000_create_logistics_governorates_table.php',
                'Modules/Logistics/Geography/Infrastructure/Database/Migrations/2026_07_12_100001_create_logistics_cities_table.php',
                'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_07_16_000002_add_distribution_zone_to_logistics_cities.php',
                'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_07_16_000004_add_logistics_city_id_to_orders.php',
                'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_08_11_100000_create_distribution_windows_table.php',
                'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_08_11_100001_create_distribution_virtual_slots_table.php',
                'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_08_11_100002_create_distribution_slot_zones_table.php',
                'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_08_11_100003_create_distribution_window_orders_table.php',
                'Modules/Logistics/Distribution/Infrastructure/Database/Migrations/2026_08_21_100000_add_warehouse_ownership_to_distribution_groups.php',
            ],
        ]);
    }

    private Company $companyA;

    private Customer $customer;

    private int $zoneA;

    private int $cityA;

    /** @var array<string, string> company_id => memoised warehouse id, mirrors DistributionCoreTest::$slotWarehouses */
    private array $slotWarehouses = [];

    /** 10:00 — inside the Window. */
    private CarbonImmutable $beforeCutoff;

    /** 15:00 — past the 14:00 cutoff. */
    private CarbonImmutable $afterCutoff;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('distribution.window.opens_at', '08:00');
        config()->set('distribution.window.closes_at', '14:00');

        $this->companyA = Company::factory()->create();
        $this->customer = Customer::factory()->create();

        $governorate = DB::table('logistics_governorates')->insertGetId([
            'country_id' => 1,
            'name_ar' => 'محافظة',
            'name_en' => 'Governorate',
            'default_shipping_price' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->zoneA = $this->makeZone('ZA', 'Zone A');
        $this->cityA = $this->makeCity($governorate, 'City A', $this->zoneA);

        $today = CarbonImmutable::now()->toDateString();
        $this->beforeCutoff = CarbonImmutable::parse($today.' 10:00:00');
        $this->afterCutoff = CarbonImmutable::parse($today.' 15:00:00');
    }

    // ── Fixtures — copied verbatim from DistributionCoreTest ───────────────────

    private function makeZone(string $code, string $name): int
    {
        return (int) DB::table('distribution_zones')->insertGetId([
            'code' => $code.'-'.substr(uniqid(), -5),
            'name_ar' => $name.' '.substr(uniqid(), -5),
            'name_en' => $name.' '.substr(uniqid(), -5),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeCity(int $governorateId, string $name, int $zoneId): int
    {
        $id = (int) DB::table('logistics_cities')->insertGetId([
            'governorate_id' => $governorateId,
            'name_ar' => $name.' '.substr(uniqid(), -5),
            'name_en' => $name.' '.substr(uniqid(), -5),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('logistics_cities')->where('id', $id)->update(['distribution_zone_id' => $zoneId]);

        return $id;
    }

    private function order(
        string $status = 'in_progress',
        ?Company $company = null,
        ?int $cityId = null,
    ): Order {
        return Order::query()->create([
            'company_id' => ($company ?? $this->companyA)->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $this->slotWarehouseId(($company ?? $this->companyA)->id),
            'logistics_city_id' => $cityId ?? $this->cityA,
            'status' => $status,
            'subtotal' => 100,
            'total' => 100,
            'shipping_total' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
        ]);
    }

    private function collect(?CarbonImmutable $at = null, ?Company $company = null): array
    {
        return app(DistributionCollectionService::class)
            ->collectForCompany(($company ?? $this->companyA)->id, $at ?? $this->beforeCutoff);
    }

    private function makeSlot(string $windowId, string $code, ?int $capacity, ?Company $company = null): VirtualCapacitySlot
    {
        return VirtualCapacitySlot::query()->create([
            'company_id' => ($company ?? $this->companyA)->id,
            'distribution_window_id' => $windowId,
            'warehouse_id' => $this->slotWarehouseId(($company ?? $this->companyA)->id),
            'code' => $code,
            'capacity_orders' => $capacity,
        ]);
    }

    private function assignment(Order $order): DistributionWindowOrder
    {
        return DistributionWindowOrder::query()->where('order_id', $order->id)->firstOrFail();
    }

    private function slotWarehouseId(string $companyId): string
    {
        return $this->slotWarehouses[$companyId] ??= Warehouse::factory()
            ->create(['company_id' => $companyId])->id;
    }

    // ── The two tests — copied verbatim from DistributionCoreTest (§22/§3) ─────

    /**
     * @see DistributionCoreTest::test_assign_late_order_rejects_an_order_that_is_not_status_eligible
     */
    public function test_assign_late_order_rejects_an_order_that_is_not_status_eligible(): void
    {
        $windows = app(DistributionWindowService::class);
        $window = $windows->windowFor($this->companyA->id, $this->beforeCutoff->toDateString(), $this->afterCutoff);
        $this->makeSlot($window->id, 'S1', 100);

        $order = $this->order(status: 'on_hold');

        $this->expectException(DistributionException::class);

        app(ManualAssignmentService::class)->assignLateOrder(
            $window, $order->id, null, null, $this->afterCutoff,
        );
    }

    /**
     * @see DistributionCoreTest::test_assign_late_order_still_moves_an_already_assigned_order_regardless_of_status
     */
    public function test_assign_late_order_still_moves_an_already_assigned_order_regardless_of_status(): void
    {
        $windows = app(DistributionWindowService::class);
        $window = $windows->windowFor($this->companyA->id, $this->beforeCutoff->toDateString(), $this->afterCutoff);
        $slot = $this->makeSlot($window->id, 'S1', 100);
        app(ManualAssignmentService::class)->assignZoneToSlot($window, $this->zoneA, $slot);

        $order = $this->order();
        $this->collect($this->afterCutoff);
        self::assertNotSame($window->id, $this->assignment($order)->distribution_window_id);

        DB::table('orders')->where('id', $order->id)->update(['status' => 'on_hold']);

        $moved = app(ManualAssignmentService::class)->assignLateOrder(
            $window, $order->id, null, null, $this->afterCutoff,
        );

        self::assertSame($window->id, $moved->distribution_window_id);
    }
}
