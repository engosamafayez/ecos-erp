<?php

declare(strict_types=1);

namespace Tests\Feature\Commerce;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Modules\Commerce\Orders\Application\Actions\ReevaluateOrderFulfillmentAction;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Enums\ReservationStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderEvent;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\Inventory\InventoryItems\Application\Actions\ReceiveStockAction;
use Modules\Inventory\InventoryItems\Application\DTO\StockOperationDTO;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\Fulfillment\Application\FulfillmentEngine;
use Modules\Operations\Fulfillment\Application\Workflows\ConfirmOrderWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\ProcessOrderWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\ResumeOrderWorkflow;
use Modules\Operations\Fulfillment\Application\Workflows\RevertToConfirmedWorkflow;
use Modules\Operations\Fulfillment\Domain\Exceptions\WorkflowPreconditionException;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Application\Actions\BlockCustomerOrPhoneAction;
use Modules\Sales\Customers\Application\Actions\OverrideOrderBlockAction;
use Modules\Sales\Customers\Domain\Models\Customer;
use Modules\Sales\Customers\Domain\Models\CustomerBlock;
use Modules\Sales\Customers\Domain\Models\OrderBlockOverride;
use Tests\TestCase;

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-CUSTOMERS-009.
 *
 * The fulfillment-enforcement half of the contract (§13-§27): a blocked Customer's
 * Order enters/remains On Hold with no reservation and no automatic progression,
 * existing recoverable Orders are swept safely, terminal/physical-execution Orders
 * are left alone, and the one-order override is scoped exactly to the Order it
 * names. CustomerBlockingTest covers the block authority itself.
 *
 * Real DB throughout — no mocking of ProcessOrderWorkflow/MoveToReviewWorkflow/
 * ReevaluateOrderFulfillmentAction/FulfillmentEngine. Fixture shape follows
 * OrdersInventoryExecutionLifecycleTest's established pattern (direct Order::create
 * + FulfillmentEngine::run, real ReceiveStockAction for stock) for the workflow-
 * level cases, and the real /api/orders/manual endpoint (OrderPaymentFulfillment-
 * ReevaluationTest's pattern) for the creation-time cases.
 */
final class BlockedOrderFulfillmentTest extends TestCase
{
    use RefreshDatabase;

    /**
     * IDENTICAL to CustomerBlockingTest::migrateFreshUsing() — see that class's
     * docblock for why. Duplicated rather than shared via a trait/base class so
     * either suite can still be read (and, if ever needed, run) standalone.
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
                // Not used directly by this suite — required only because OrderPreparationObserver
                // (an Eloquent observer registered on Order::updated(), unconditionally) and a
                // Product-side listener query these tables as a side effect of the real
                // FulfillmentEngine/Product-factory writes this suite performs. Base shape only.
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
                // Same reason as the block above: side-effect tables touched by OrderResource's
                // relationship resolution (driver/trip) and the global enterprise-events sink —
                // not used by this suite's own assertions.
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
            ],
        ]);
    }

    private Company $company;

    private Warehouse $warehouse;

    private Brand $brand;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // Out of this suite's minimal schema and entirely orthogonal to blocked-
        // customer behaviour: ReserveStockAction/ReceiveStockAction dispatch a real
        // domain event that the platform's Enterprise Event Bus queues out to
        // several unrelated subscribers (Finance posting, event-sourcing
        // projections, etc.) via a real queued job. Faking the queue isolates this
        // suite from that infrastructure without touching production code — the
        // same isolation `Queue::fake()` is designed for.
        Queue::fake();

        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->brand = Brand::factory()->create(['company_id' => $this->company->id]);
        $this->customer = Customer::factory()->create(['company_id' => $this->company->id, 'phone' => '01012345678']);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function user(): User
    {
        return User::factory()->create(['company_id' => $this->company->id]);
    }

    private function product(): Product
    {
        return Product::factory()->finishedGood()->create(['brand_id' => $this->brand->id, 'allow_negative_stock' => false]);
    }

    private function stock(Product $product, float $onHand): InventoryItem
    {
        return InventoryItem::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product->id,
            'company_id' => $this->company->id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => 0,
        ]);
    }

    private function receive(Product $product, float $qty): void
    {
        app(ReceiveStockAction::class)->execute(new StockOperationDTO(
            warehouse_id: $this->warehouse->id,
            product_id: $product->id,
            company_id: $this->company->id,
            quantity: $qty,
            reference_type: 'test_receipt',
            unit_cost: 10.0,
        ));
    }

    /** @param  list<array{product: Product, qty: float}>  $lines */
    private function order(array $lines, OrderStatus $status = OrderStatus::InProgress, ?string $holdReasonCode = null): Order
    {
        $order = Order::query()->create([
            'company_id' => $this->company->id,
            'assigned_warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-'.uniqid(),
            'order_date' => now()->toDateString(),
            'status' => $status->value,
            'hold_reason_code' => $holdReasonCode,
            'subtotal' => 100,
            'total' => 100,
            'shipping_total' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
        ]);

        foreach ($lines as $line) {
            OrderLine::query()->create([
                'order_id' => $order->id,
                'product_id' => $line['product']->id,
                'quantity' => $line['qty'],
                'unit_price' => 100.0,
                'line_total' => 100.0 * $line['qty'],
            ]);
        }

        return $order->fresh();
    }

    private function process(Order $order, array $ctx = []): Order
    {
        app(FulfillmentEngine::class)->run(app(ProcessOrderWorkflow::class), $order->fresh(), $ctx, null);

        return $order->fresh();
    }

    private function blockCustomer(?string $reason = 'Repeated fraud'): CustomerBlock
    {
        $result = app(BlockCustomerOrPhoneAction::class)->execute(
            (string) $this->company->id, (string) $this->customer->id, null, $reason, null,
        );

        return $result->data();
    }

    // ── §13/§14 — New Order creation for a blocked Customer ──────────────────

    public function test_new_order_for_a_blocked_customer_lands_on_hold_with_blocked_customer_reason(): void
    {
        $this->blockCustomer();
        $product = $this->product();
        $this->stock($product, 10);

        $response = $this->actingAs($this->user())->postJson('/api/orders/manual', [
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'order_date' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ]);

        $response->assertSuccessful();
        $order = Order::query()->findOrFail($response->json('data.id'));

        $this->assertSame(OrderStatus::OnHold, $order->status, 'Approved behaviour is ON HOLD, never rejection (§13).');
        $this->assertSame('blocked_customer', $order->hold_reason_code);
    }

    public function test_new_blocked_order_does_not_reserve_inventory_even_with_stock_available(): void
    {
        $this->blockCustomer();
        $product = $this->product();
        $this->stock($product, 10);

        $response = $this->actingAs($this->user())->postJson('/api/orders/manual', [
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'order_date' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => 100]],
        ])->assertSuccessful();

        $order = Order::query()->findOrFail($response->json('data.id'));
        $this->assertNull($order->inventory_reserved_at);

        $item = InventoryItem::query()->where('product_id', $product->id)->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertSame(0.0, (float) $item->reserved_qty, 'Stock must never reserve a blocked Order (§13/§20).');
    }

    public function test_unblocked_customer_order_creation_is_unaffected(): void
    {
        $product = $this->product();
        $this->stock($product, 10);

        $response = $this->actingAs($this->user())->postJson('/api/orders/manual', [
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'order_date' => now()->toDateString(),
            'lines' => [['product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100]],
        ])->assertSuccessful();

        $order = Order::query()->findOrFail($response->json('data.id'));
        $this->assertSame(OrderStatus::InProgress, $order->status);
        $this->assertNull($order->hold_reason_code);
    }

    // ── §16-§20 — Existing Orders swept on block ──────────────────────────────

    public function test_existing_in_progress_order_moves_to_on_hold_and_releases_reservation_when_customer_blocked(): void
    {
        $product = $this->product();
        $this->receive($product, 10);
        $order = $this->process($this->order([['product' => $product, 'qty' => 2]]));
        $this->assertSame(ReservationStatus::Reserved, $order->reservation_status, 'Premise: order holds a real reservation.');

        $this->blockCustomer('Customer blocked after order placed');

        $order->refresh();
        $this->assertSame(OrderStatus::OnHold, $order->status, '§16/§17 — recoverable Orders move to On Hold.');
        $this->assertSame('blocked_customer', $order->hold_reason_code);
        $this->assertNotNull($order->inventory_released_at, '§17/§20 — the active reservation must be released through the canonical authority.');
        $this->assertSame(ReservationStatus::Released, $order->reservation_status);

        $item = InventoryItem::query()->where('product_id', $product->id)->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertSame(0.0, (float) $item->reserved_qty, 'Released reservation must be reflected on the inventory item itself.');
    }

    public function test_existing_confirmed_order_with_no_reservation_moves_to_on_hold_without_error(): void
    {
        $product = $this->product();
        $order = $this->order([['product' => $product, 'qty' => 1]], OrderStatus::Confirmed);

        $this->blockCustomer();

        $order->refresh();
        $this->assertSame(OrderStatus::OnHold, $order->status);
        $this->assertSame('blocked_customer', $order->hold_reason_code);
    }

    public function test_terminal_delivered_order_is_unchanged_when_customer_is_blocked(): void
    {
        $product = $this->product();
        $order = $this->order([['product' => $product, 'qty' => 1]], OrderStatus::Delivered);

        $this->blockCustomer();

        $order->refresh();
        $this->assertSame(OrderStatus::Delivered, $order->status, '§19 — terminal Orders remain unchanged.');
        $this->assertNull($order->hold_reason_code);
    }

    public function test_terminal_cancelled_order_is_unchanged_when_customer_is_blocked(): void
    {
        $product = $this->product();
        $order = $this->order([['product' => $product, 'qty' => 1]], OrderStatus::Cancelled);

        $this->blockCustomer();

        $order->refresh();
        $this->assertSame(OrderStatus::Cancelled, $order->status);
    }

    public function test_order_already_out_for_delivery_is_not_rolled_back_and_an_exception_is_recorded(): void
    {
        $product = $this->product();
        $order = $this->order([['product' => $product, 'qty' => 1]], OrderStatus::OutForDelivery);

        $this->blockCustomer('Fraud discovered after dispatch');

        $order->refresh();
        $this->assertSame(
            OrderStatus::OutForDelivery,
            $order->status,
            '§18 — an Order already beyond the safe hold boundary must preserve its operational state.',
        );

        $exceptionEvent = OrderEvent::query()
            ->where('order_id', $order->id)
            ->where('event_type', 'blocked_customer_exception_physical_execution')
            ->first();
        $this->assertNotNull($exceptionEvent, '§18 — the exception must be recorded/surfaced, not silently ignored.');
    }

    // ── §23/§24 — Automatic fulfillment cannot bypass the block ───────────────

    public function test_reevaluate_order_fulfillment_action_cannot_resume_a_blocked_order(): void
    {
        $product = $this->product();
        $this->receive($product, 10);
        $order = $this->process($this->order([['product' => $product, 'qty' => 1]]));
        $this->blockCustomer();
        $order->refresh();
        $this->assertSame(OrderStatus::OnHold, $order->status);

        // A normal reevaluation trigger (e.g. a payment-method change elsewhere calls
        // this same action) must be a safe no-op while the block still applies.
        app(ReevaluateOrderFulfillmentAction::class)->execute($order->fresh());

        $order->refresh();
        $this->assertSame(OrderStatus::OnHold, $order->status, 'A blocked Order must not automatically progress (§23).');
    }

    public function test_process_order_workflow_guard_rejects_resuming_a_blocked_order_directly(): void
    {
        $product = $this->product();
        $order = $this->order([['product' => $product, 'qty' => 1]], OrderStatus::OnHold, 'blocked_customer');
        $this->blockCustomer();

        $this->expectException(WorkflowPreconditionException::class);

        app(FulfillmentEngine::class)->run(app(ProcessOrderWorkflow::class), $order->fresh(), [], null);
    }

    public function test_confirm_order_workflow_guard_rejects_confirming_a_blocked_order_directly(): void
    {
        $product = $this->product();
        $order = $this->order([['product' => $product, 'qty' => 1]], OrderStatus::OnHold, 'blocked_customer');
        $this->blockCustomer();

        $this->expectException(WorkflowPreconditionException::class);

        app(FulfillmentEngine::class)->run(app(ConfirmOrderWorkflow::class), $order->fresh(), [], null);
    }

    // TASK-ECOS-BUSINESS-INTEGRATION-CONVEYOR-001 — closes the verification debt
    // FINAL-CLOSURE-011 (§3/§4) itself documented as accepted-but-unremediated: these
    // two routes (`resume`, `revert-to-confirmed`) gained the identical
    // BlockedCustomerPolicy guard ProcessOrderWorkflow/ConfirmOrderWorkflow already had
    // tested above, but never received their own direct coverage.

    public function test_resume_order_workflow_guard_rejects_resuming_a_blocked_order_directly(): void
    {
        $product = $this->product();
        $order = $this->order([['product' => $product, 'qty' => 1]], OrderStatus::OnHold, 'blocked_customer');
        $this->blockCustomer();

        $this->expectException(WorkflowPreconditionException::class);

        app(FulfillmentEngine::class)->run(app(ResumeOrderWorkflow::class), $order->fresh(), [], null);
    }

    public function test_revert_to_confirmed_workflow_guard_rejects_reverting_a_blocked_order_directly(): void
    {
        $product = $this->product();
        $order = $this->order([['product' => $product, 'qty' => 1]], OrderStatus::OnHold, 'blocked_customer');
        $this->blockCustomer();

        $this->expectException(WorkflowPreconditionException::class);

        app(FulfillmentEngine::class)->run(app(RevertToConfirmedWorkflow::class), $order->fresh(), [], null);
    }

    // ── §25-§27 — One-order override ──────────────────────────────────────────

    public function test_one_order_override_allows_only_that_order_to_resume(): void
    {
        $product = $this->product();
        $this->receive($product, 20);
        $orderA = $this->process($this->order([['product' => $product, 'qty' => 1]]));
        $orderB = $this->process($this->order([['product' => $product, 'qty' => 1]]));

        $this->blockCustomer();
        $orderA->refresh();
        $orderB->refresh();
        $this->assertSame(OrderStatus::OnHold, $orderA->status);
        $this->assertSame(OrderStatus::OnHold, $orderB->status);

        app(OverrideOrderBlockAction::class)->execute($orderA->fresh(), 'CTO-approved exception for order A', null);

        $orderA->refresh();
        $orderB->refresh();
        $this->assertSame(OrderStatus::InProgress, $orderA->status, 'Only the overridden Order may proceed (§25).');
        $this->assertSame(OrderStatus::OnHold, $orderB->status, '§26 — every OTHER Order remains blocked.');
    }

    public function test_override_does_not_unblock_the_customer_or_future_orders(): void
    {
        $product = $this->product();
        $this->receive($product, 20);
        $orderA = $this->process($this->order([['product' => $product, 'qty' => 1]]));
        $this->blockCustomer();
        $orderA->refresh();

        app(OverrideOrderBlockAction::class)->execute($orderA->fresh(), 'One-order exception', null);

        $this->assertTrue(
            CustomerBlock::query()->where('company_id', $this->company->id)->where('is_active', true)->exists(),
            '§26/§27 — the Customer/phone remains blocked; override is never a temporary unblock.',
        );

        // A brand-new Order for this Customer must still land On Hold.
        $newOrder = $this->order([['product' => $product, 'qty' => 1]]);
        $newOrder->refresh();
        // (created directly at InProgress by the fixture — prove the BLOCK still applies
        // by attempting to resume it exactly as test_process_order_workflow_guard does)
        DB::table('orders')->where('id', $newOrder->id)->update(['status' => 'on_hold', 'hold_reason_code' => 'blocked_customer']);
        $this->expectException(WorkflowPreconditionException::class);
        app(FulfillmentEngine::class)->run(app(ProcessOrderWorkflow::class), $newOrder->fresh(), [], null);
    }

    public function test_override_reason_is_required(): void
    {
        $order = $this->order([['product' => $this->product(), 'qty' => 1]], OrderStatus::OnHold, 'blocked_customer');
        $this->blockCustomer();

        $response = $this->actingAs($this->user())
            ->postJson("/api/orders/{$order->id}/block-override", ['reason' => '']);

        $response->assertStatus(422);
        $this->assertSame(0, OrderBlockOverride::query()->where('order_id', $order->id)->count());
    }

    // ── §39-C — Real concurrency: two concurrent overrides for the same Order ──

    public function test_two_concurrent_override_attempts_for_the_same_order_do_not_conflict(): void
    {
        $order = $this->order([['product' => $this->product(), 'qty' => 1]], OrderStatus::OnHold, 'blocked_customer');
        $this->blockCustomer();

        config(['database.connections.mysql_secondary' => config('database.connections.mysql')]);
        $connB = DB::connection('mysql_secondary');
        $connB->statement('SET SESSION innodb_lock_wait_timeout = 1');

        DB::beginTransaction();
        DB::table('order_block_overrides')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid7(),
            'order_id' => $order->id,
            'company_id' => $this->company->id,
            'granted_by' => null,
            'reason' => 'Connection A grant',
            'granted_at' => now(),
            'created_at' => now(),
        ]);

        $blocked = false;

        try {
            $connB->beginTransaction();
            $connB->table('order_block_overrides')->insert([
                'id' => (string) \Illuminate\Support\Str::uuid7(),
                'order_id' => $order->id,
                'company_id' => $this->company->id,
                'granted_by' => null,
                'reason' => 'Connection B grant',
                'granted_at' => now(),
                'created_at' => now(),
            ]);
            $connB->commit();
        } catch (\Illuminate\Database\QueryException $e) {
            $blocked = str_contains($e->getMessage(), 'Lock wait timeout exceeded');
            $connB->rollBack();
        }

        DB::commit();

        $this->assertTrue($blocked, 'A second concurrent override grant for the same Order must not create conflicting effective state.');
        $this->assertSame(1, OrderBlockOverride::query()->where('order_id', $order->id)->count());
    }
}
