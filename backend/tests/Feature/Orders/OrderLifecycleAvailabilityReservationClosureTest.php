<?php

declare(strict_types=1);

namespace Tests\Feature\Orders;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Modules\Admin\Configuration\Domain\Models\MasterGovernorate;
use Modules\Admin\Configuration\Domain\Models\MasterZone;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Enums\ReservationStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Inventory\InventoryItems\Domain\Models\InventoryItem;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\MasterData\Warehouses\Domain\Models\WarehouseBrandCoverage;
use Modules\Organization\Branches\Domain\Models\Branch;
use Modules\Organization\Branches\Domain\Models\BranchCoverageArea;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-ORDERS-LIFECYCLE-AVAILABILITY-RESERVATION-CLOSURE-001.
 *
 * Covers only what the CLOSURE contract adds over the already-certified
 * OrderAvailabilityLifecycleContractTest — it does not restate that file:
 *
 *   PART 1/2  the availability decision happens AT CREATION, for every order that
 *             enters a commercially active state — not only `in_progress`, and never
 *             deferred to a scheduler.
 *   PART 5/6  `pending` is not a business reservation state and must never be the
 *             resting outcome of an availability decision.
 *   PART 23-B an unpaid order still gets its availability decision; payment stays
 *             authoritative over the lifecycle status.
 *
 * `DatabaseTransactions`, not `RefreshDatabase`: `ecos_dev_test` is shared and
 * contended, and `migrate:fresh` is what makes concurrent agents destroy each other's
 * runs.
 */
final class OrderLifecycleAvailabilityReservationClosureTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private Warehouse $warehouse;

    private Customer $customer;

    private Brand $brand;

    /**
     * The full geography + brand-coverage chain BranchAssignmentEngine needs, so the
     * creation surface actually resolves a warehouse.
     *
     * Without it every order lands with no warehouse, which postpones reservation
     * execution for a reason that has nothing to do with availability (RC-10) — and a
     * test that cannot get a warehouse cannot say anything about availability at all.
     * `WarehouseBrandCoverage` is not optional here: NO ROWS = SERVES NO BRANDS.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();

        $this->warehouse = Warehouse::factory()->create([
            'company_id' => $this->company->id,
            'is_active' => true,
        ]);

        $this->brand = Brand::create([
            'company_id' => $this->company->id,
            'code' => 'BR'.substr(uniqid(), -8),
            'name' => 'Brand '.uniqid(),
            'slug' => 'brand-'.uniqid(),
            'is_active' => true,
        ]);

        $governorate = MasterGovernorate::create([
            'name' => 'Cairo',
            'name_ar' => 'القاهرة',
            'code' => 'C'.substr(uniqid(), -7),
            'is_active' => true,
        ]);

        $zone = MasterZone::create([
            'master_governorate_id' => $governorate->id,
            'name' => 'Nasr City',
            'code' => 'NC'.substr(uniqid(), -8),
            'is_active' => true,
        ]);

        $branch = Branch::create([
            'company_id' => $this->company->id,
            'code' => 'BR-'.uniqid(),
            'name' => 'Branch '.uniqid(),
            'default_warehouse_id' => $this->warehouse->id,
            'is_active' => true,
        ]);

        BranchCoverageArea::create([
            'branch_id' => $branch->id,
            'master_governorate_id' => $governorate->id,
            'master_zone_id' => $zone->id,
            'priority' => 100,
            'is_active' => true,
        ]);

        WarehouseBrandCoverage::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'brand_id' => $this->brand->id,
            'is_active' => true,
        ]);
    }

    /** A finished good owned by the brand this warehouse is configured to serve. */
    private function product(): Product
    {
        return Product::factory()->finishedGood()->create([
            'brand_id' => $this->brand->id,
            'company_id' => $this->company->id,
        ]);
    }

    private function stock(Product $product, float $onHand): InventoryItem
    {
        return InventoryItem::query()->create([
            'warehouse_id' => $this->warehouse->id,
            'product_id' => $product->id,
            'company_id' => $this->company->id,
            'on_hand_qty' => $onHand,
            'reserved_qty' => 0.0,
        ]);
    }

    /**
     * Create through the REAL creation surface — route → FormRequest → controller →
     * CreateManualOrderAction — because the behaviour under test IS the creation
     * trigger. Driving the workflow directly would bypass the very gate in question
     * and the suite would stay green while creation reserved nothing.
     */
    private function createViaHttp(Product $product, ?string $status, float $qty = 5.0): Order
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);

        $payload = [
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'order_date' => now()->toDateString(),
            'governorate' => 'Cairo',
            'area' => 'Nasr City',
            'lines' => [[
                'product_id' => $product->id,
                'quantity' => $qty,
                'unit_price' => 100,
            ]],
        ];

        if ($status !== null) {
            $payload['status'] = $status;
        }

        $response = $this->actingAs($user)->postJson('/api/orders/manual', $payload);
        $response->assertSuccessful();

        $order = Order::whereKey($response->json('data.id'))->first();
        self::assertNotNull($order, 'order was not persisted');

        // The availability decision is only MEANINGFUL once a warehouse exists; without
        // one, reservation execution is postponed for a reason that has nothing to do
        // with availability (RC-10). Assert the precondition so a warehouse-assignment
        // failure can never be misread as an availability defect.
        self::assertNotNull(
            $order->assigned_warehouse_id,
            'precondition: BranchAssignmentEngine resolved no warehouse, so this test cannot speak about availability',
        );

        return $order;
    }

    /** A directly-built order, for the cases that do not exercise the creation surface. */
    private function order(Product $product, OrderStatus $status, float $qty = 5.0): Order
    {
        $order = Order::create([
            'customer_id' => $this->customer->id,
            'company_id' => $this->company->id,
            'assigned_warehouse_id' => $this->warehouse->id,
            'order_number' => 'CLO-'.Str::random(8),
            'order_date' => now()->toDateString(),
            'status' => $status->value,
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

    // ── PART 23-A — available + the default paid entry path ───────────────────

    public function test_a_available_product_reserves_immediately_at_creation(): void
    {
        $product = $this->product();
        $this->stock($product, 100.0);

        $order = $this->createViaHttp($product, OrderStatus::InProgress->value);

        self::assertSame(OrderStatus::InProgress, $order->status);
        self::assertSame(ReservationStatus::Reserved, $order->reservation_status);
        self::assertNotSame(ReservationStatus::Pending, $order->reservation_status);
    }

    // ── PART 23-B — available + UNPAID (the headline gap) ─────────────────────

    /**
     * PART 1: "DO NOT wait for a scheduler to perform the first reservation attempt.
     * The availability decision must happen as part of the canonical order
     * creation/availability workflow." PART 23-B: an unpaid order's
     * "Reservation/availability logic executes... No Pending."
     *
     * The creation trigger trips only for `in_progress`, so an order created at
     * `awaiting_payment` received NO availability decision at all: it rested at
     * `reservation_status = NULL`, which the Orders UI renders as "Pending".
     */
    public function test_b_unpaid_order_still_gets_its_availability_decision_at_creation(): void
    {
        $product = $this->product();
        $this->stock($product, 100.0);

        $order = $this->createViaHttp($product, OrderStatus::AwaitingPayment->value);

        // Payment stays authoritative over the LIFECYCLE.
        self::assertSame(OrderStatus::AwaitingPayment, $order->status);

        // But availability was decided, and it is not Pending and not null.
        self::assertNotNull($order->reservation_status, 'no availability decision was taken at creation');
        self::assertNotSame(ReservationStatus::Pending, $order->reservation_status);
        self::assertSame(ReservationStatus::Reserved, $order->reservation_status);
    }

    /**
     * The other half: unpaid AND unavailable. Availability is recorded on the
     * reservation surface; the payment blocker keeps the lifecycle column.
     */
    public function test_b2_unpaid_and_unavailable_records_awaiting_stock_on_the_reservation_surface(): void
    {
        $product = $this->product();
        $this->stock($product, 0.0);

        $order = $this->createViaHttp($product, OrderStatus::AwaitingPayment->value);

        self::assertSame(OrderStatus::AwaitingPayment, $order->status);
        self::assertSame(ReservationStatus::AwaitingStock, $order->reservation_status);
        self::assertNotSame(ReservationStatus::Pending, $order->reservation_status);
    }

    // ── PART 23-C — unavailable → Awaiting Stock, never Pending ─────────────

    public function test_c_unavailable_product_goes_to_awaiting_stock_at_creation(): void
    {
        $product = $this->product();
        $this->stock($product, 0.0);

        $order = $this->createViaHttp($product, OrderStatus::InProgress->value);

        self::assertSame(OrderStatus::AwaitingStock, $order->status);
        self::assertSame(ReservationStatus::AwaitingStock, $order->reservation_status);
    }

    // ── PART 12 — Scheduled is the ONE state that defers, by its own rule ─────

    /**
     * Scheduled deliberately does NOT reserve at creation: PART 12 gives it its own
     * activation rule (D-1), after which "availability / reservation flow" runs. This
     * asserts the exclusion is deliberate rather than the same omission as B.
     */
    public function test_scheduled_order_defers_its_availability_decision_to_d1(): void
    {
        $product = $this->product();
        $this->stock($product, 100.0);

        $order = $this->order($product, OrderStatus::Scheduled);

        self::assertSame(OrderStatus::Scheduled, $order->status);
        self::assertNull($order->reservation_status, 'a future-dated order holds no inventory yet');
    }

    // ── PART 5 / 23-L — `pending` is never an availability outcome ────────────

    /**
     * PART 5/23-L. Every terminal outcome of an availability decision must be one of
     * the three valid business outcomes. `pending` may survive in the enum only as the
     * no-warehouse postponement marker required by RC-10 — never as the answer to
     * "is this product available".
     */
    public function test_l_an_availability_decision_never_rests_at_pending(): void
    {
        $valid = [
            ReservationStatus::Reserved,
            ReservationStatus::PartialReserved,
            ReservationStatus::AwaitingStock,
        ];

        $available = $this->product();
        $this->stock($available, 100.0);

        $short = $this->product();
        $this->stock($short, 0.0);

        foreach ([$available, $short] as $product) {
            foreach ([OrderStatus::InProgress, OrderStatus::AwaitingPayment] as $entry) {
                $order = $this->createViaHttp($product, $entry->value);

                self::assertContains(
                    $order->reservation_status,
                    $valid,
                    "order created at [{$entry->value}] rested at ["
                        .($order->reservation_status?->value ?? 'null').']',
                );
            }
        }
    }
}
