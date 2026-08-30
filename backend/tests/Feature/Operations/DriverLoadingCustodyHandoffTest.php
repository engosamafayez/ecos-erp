<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\User;
use BackedEnum;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\IAM\Domain\Models\Permission;
use Modules\IAM\Domain\Models\Role;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\VirtualCapacitySlot;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Logistics\Vehicles\Domain\Models\Vehicle;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\Loading\Application\Actions\LoadProductAction;
use Modules\Operations\Loading\Domain\Models\LoadingTask;
use Modules\Operations\Loading\Domain\Models\VehicleAssignment;
use Modules\Operations\Loading\Domain\Models\VehicleInventoryItem;
use Modules\Operations\Loading\Domain\Services\VehicleInventoryService;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;

/**
 * DRIVER LOADING → VEHICLE CUSTODY HANDOFF.
 *
 * ┌─ WHAT THIS SUITE PINS ───────────────────────────────────────────────────┐
 * │ The moment stock stops being the warehouse's problem and becomes the      │
 * │ DRIVER'S. Everything here is driven over HTTP through the real driver     │
 * │ endpoints, because the contract is only true if it survives the identity, │
 * │ ownership and permission guards that sit in front of the action:          │
 * │                                                                          │
 * │   GET  /api/driver/loading                    — the driver's own manifest │
 * │   POST /api/driver/loading/products/{product} — record the ACTUAL load    │
 * │   POST /api/driver/loading/complete           — hand custody over         │
 * │                                                                          │
 * │ The four facts under test:                                               │
 * │   1. A driver reaches THEIR OWN shipment and no one else's.               │
 * │   2. REQUIRED is the canonical live Group aggregation, never a copy.      │
 * │   3. ACTUAL is what reaches vehicle custody — never the required figure.  │
 * │   4. Recording a load is IDEMPOTENT: a retry cannot double the custody.   │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * CORRECTION CYCLE (TASK-DRIVER-02). The seven cases the owner requires are
 * carried by the tests named below, in this order:
 *
 *   (a) 0 → 18   test_the_actual_loaded_quantity_is_persisted_and_is_what_custody_receives
 *   (b) 18 → 18  test_reposting_the_same_quantity_does_not_double_custody
 *   (c) 18 → 12  test_a_downward_correction_reduces_custody_and_records_a_positive_adjustment
 *   (d) 12 → 18  test_an_upward_correction_moves_custody_by_the_delta
 *   (e) floor    test_a_correction_can_never_drive_custody_negative
 *   (f) atomic   test_a_refused_correction_leaves_task_custody_and_ledger_untouched
 *   (g) ceiling  test_an_over_load_is_refused_and_writes_nothing
 *   (h) earmark  test_a_correction_below_a_live_allocation_is_refused
 *   (i) earmark  test_a_correction_down_to_the_allocated_quantity_succeeds_and_keeps_the_earmark_whole
 *
 * THE LEDGER'S CONVENTION, pinned by (c): `vehicle_inventory_movements.quantity`
 * is always a positive MAGNITUDE (`CHECK (quantity > 0)`) and `movement_type`
 * carries the direction. A downward correction is therefore an `adjusted` row of
 * +6, never a `loaded` row of −6, and never a `loaded` row mislabelling a
 * reduction. (c) asserts BOTH halves of that: the adjusted row exists with a
 * positive quantity, and no row anywhere in the ledger is negative.
 *
 * NO PRODUCTION CODE IS TOUCHED. Every fixture is built through the canonical
 * production path (collect → Group → zone → assign-vehicle), so a Group, a Trip
 * and a driver↔vehicle pairing here are the same rows the operator workflow
 * produces. Nothing is hand-stitched except where a pre-existing custody row is
 * the thing being tested.
 *
 * RefreshDatabase, not DatabaseTransactions: the shared test schema is in a
 * broken incremental state and migrate:fresh is what makes a run truthful.
 */
final class DriverLoadingCustodyHandoffTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/logistics/distribution';

    private const DRIVER = '/api/driver/loading';

    /** The driver runtime permission the whole `/api/driver/*` group is gated on. */
    private const DRIVER_RUNTIME = 'loading.driver.operate';

    private Company $company;

    private Customer $customer;

    private Warehouse $warehouse;

    private int $governorate;

    private int $zoneA;

    private int $zoneB;

    private int $zoneC;

    private Product $honey;

    private Product $nuts;

    private Product $dates;

    /** @var array{user: User, driver: Driver, group: VirtualCapacitySlot, trip: Trip} */
    private array $shipmentA;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('distribution.window.opens_at', '00:00');
        config()->set('distribution.window.closes_at', '23:59');

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);

        $this->governorate = (int) DB::table('logistics_governorates')->insertGetId([
            'country_id' => 1,
            'name_ar' => 'القاهرة', 'name_en' => 'Cairo',
            'default_shipping_price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Every zone and city exists BEFORE the first collect: city binding is a
        // sweep run inside `POST /windows/collect`, and geography that appears
        // after a sweep is simply never asked about again for the orders it missed.
        $this->zoneA = $this->zone('Maadi');
        $this->zoneB = $this->zone('Nasr City');
        $this->zoneC = $this->zone('Obour');
        $this->city($this->governorate, 'Maadi', 'المعادي', $this->zoneA);
        $this->city($this->governorate, 'Nasr City', 'مدينة نصر', $this->zoneB);
        $this->city($this->governorate, 'Obour City', 'مدينة العبور', $this->zoneC);

        $this->honey = Product::factory()->create();
        $this->nuts = Product::factory()->create();
        $this->dates = Product::factory()->create();

        // Driver A's shipment: 2 orders x 10 of Honey = REQUIRED 20.
        $this->shipmentA = $this->shipment(
            $this->company,
            $this->warehouse,
            $this->zoneA,
            'Maadi',
            $this->honey,
            'DG-CUSTODY-A',
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // A. Ownership — the manifest is the driver's own, and nobody else's
    // ═════════════════════════════════════════════════════════════════════════

    /** 1 — the driver reads their own shipment, in the shape the mobile client consumes. */
    public function test_a_driver_reads_only_their_own_loading_manifest(): void
    {
        $data = $this->manifest($this->shipmentA);

        self::assertNotNull($data['shipment'], 'a driver with an assigned Trip has a shipment');
        self::assertNotSame([], $data['items'], 'and their own product rows');

        $row = $this->row($data, $this->honey->id);

        self::assertNotNull($row, "the driver's own product must appear on their manifest");
        self::assertArrayHasKey('quantity_required', $row);
        self::assertArrayHasKey('quantity_loaded', $row);
        // Cast at the boundary: JSON has no float type, so 20.0 arrives as 20.
        self::assertSame(20.0, (float) $row['quantity_required']);
        self::assertSame(0.0, (float) $row['quantity_loaded'], 'nothing has been loaded yet');
    }

    /** 2 — same company, a different driver. Assignment scope, not merely tenancy. */
    public function test_a_driver_cannot_reach_another_drivers_loading_in_the_same_company(): void
    {
        $b = $this->shipment(
            $this->company,
            $this->warehouse,
            $this->zoneB,
            'Nasr City',
            $this->nuts,
            'DG-CUSTODY-B',
        );

        $ids = array_column($this->manifest($b)['items'], 'product_id');

        self::assertContains($this->nuts->id, $ids, "driver B sees their own shipment's product");
        self::assertNotContains($this->honey->id, $ids, "driver A's product is not on driver B's manifest");

        // And B cannot reach it by naming the product id directly — the id is
        // checked against B's OWN Group, never against the products table.
        $refused = $this->load($b, $this->honey->id, 5.0);
        $refused->assertStatus(422);
        self::assertStringContainsString(
            'not part of your shipment',
            (string) $refused->json('message'),
        );

        self::assertSame(
            0,
            LoadingTask::query()->where('product_id', $this->honey->id)->count(),
            'a refused load writes no task anywhere',
        );
    }

    /** 3 — cross-company. Another tenant's shipment is neither visible nor loadable. */
    public function test_a_driver_cannot_see_or_load_another_companys_shipment(): void
    {
        $other = Company::factory()->create();
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $other->id]);

        $foreign = $this->shipment(
            $other,
            $otherWarehouse,
            $this->zoneC,
            'Obour City',
            $this->dates,
            'DG-CUSTODY-C',
        );

        $ids = array_column($this->manifest($foreign)['items'], 'product_id');

        self::assertContains($this->dates->id, $ids, "the foreign driver sees their own company's shipment");
        self::assertNotContains($this->honey->id, $ids, "and never company 1's");

        $this->load($foreign, $this->honey->id, 5.0)->assertStatus(422);

        self::assertSame(
            0,
            LoadingTask::query()->where('product_id', $this->honey->id)->count(),
            'no loading task was created for the foreign product',
        );
        self::assertSame(
            0,
            VehicleInventoryItem::query()->where('product_id', $this->honey->id)->count(),
            'and nothing reached any vehicle',
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // B. Required vs Actual — two different numbers, and neither replaces the other
    // ═════════════════════════════════════════════════════════════════════════

    /** 4 — REQUIRED is the Group's live order aggregation, not a stored or loaded figure. */
    public function test_required_quantity_is_the_canonical_group_aggregation(): void
    {
        $seeded = (float) DB::table('order_lines')->where('product_id', $this->honey->id)->sum('quantity');

        self::assertSame(20.0, $seeded, 'fixture: 2 orders x 10');

        $row = $this->row($this->manifest($this->shipmentA), $this->honey->id);
        self::assertSame($seeded, (float) $row['quantity_required']);

        // Loading short does NOT rewrite Required — the two facts stay separate.
        $this->load($this->shipmentA, $this->honey->id, 18.0)->assertOk();

        $after = $this->row($this->manifest($this->shipmentA), $this->honey->id);
        self::assertSame($seeded, (float) $after['quantity_required'], 'Required is unmoved by a short load');
        self::assertSame(18.0, (float) $after['quantity_loaded']);
    }

    /**
     * 5 — CASE (a), 0 → 18. The FIRST load.
     *
     * ACTUAL is what custody receives; the required 20 never reaches the vehicle.
     * The ledger entry for a first load is a single `loaded` row of +18 — the
     * baseline every correction case below is measured against.
     */
    public function test_the_actual_loaded_quantity_is_persisted_and_is_what_custody_receives(): void
    {
        $this->load($this->shipmentA, $this->honey->id, 18.0)->assertOk();

        $task = $this->task($this->honey->id);

        self::assertSame(20.0, (float) $task->quantity_planned);
        self::assertSame(18.0, (float) $task->quantity_loaded);
        self::assertSame(2.0, (float) $task->quantity_short);

        $item = $this->custody($this->honey->id);

        self::assertSame(18.0, (float) $item->quantity_loaded, 'the actual 18, never the required 20');
        self::assertSame(18.0, (float) $item->quantity_on_hand);
        self::assertSame(18.0, (float) $item->quantity_unallocated);
        self::assertSame('active', $this->statusValue($item->status));

        self::assertSame(
            [['loaded', 18.0]],
            $this->ledger($item),
            'one movement: a positive `loaded` row for the whole first load',
        );
    }

    /** 6 — a short load leaves a REMAINDER on the manifest, not a loss on the vehicle. */
    public function test_partial_loading_leaves_a_remainder_and_no_liability_row(): void
    {
        $this->load($this->shipmentA, $this->honey->id, 15.0)->assertOk();

        $row = $this->row($this->manifest($this->shipmentA), $this->honey->id);

        self::assertSame(20.0, (float) $row['quantity_required']);
        self::assertSame(15.0, (float) $row['quantity_loaded']);
        self::assertSame(5.0, (float) $row['quantity_remaining'], 'the 5 that stayed behind is a remainder');

        $item = $this->custody($this->honey->id);

        // Custody holds exactly what was loaded. The unloaded 5 is not a return,
        // not a delivery and not an allocation — it simply never left the warehouse.
        self::assertSame(15.0, (float) $item->quantity_on_hand);
        self::assertSame(0.0, (float) $item->quantity_returned);
        self::assertSame(0.0, (float) $item->quantity_delivered);
        self::assertSame(0.0, (float) $item->quantity_allocated);

        $movements = DB::table('vehicle_inventory_movements')->where('vehicle_inventory_item_id', $item->id);

        self::assertSame(1, (int) $movements->clone()->count(), 'one movement, and only one');
        self::assertSame(1, (int) $movements->clone()->where('movement_type', 'loaded')->count());
    }

    // ═════════════════════════════════════════════════════════════════════════
    // C. Idempotency — the fact that keeps custody honest under a retry
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * 7 — CASE (b), 18 → 18. THE CRITICAL ONE.
     *
     * A driver on a flaky connection taps "confirm" twice. Re-sending the SAME
     * quantity is a no-op by construction, because the write is an absolute SET
     * and the inventory moves by the DELTA — which here is zero, so neither
     * `recordLoad()` nor `recordLoadCorrection()` is reached and no second
     * movement row is appended.
     */
    public function test_reposting_the_same_quantity_does_not_double_custody(): void
    {
        $this->load($this->shipmentA, $this->honey->id, 18.0)->assertOk();
        $this->load($this->shipmentA, $this->honey->id, 18.0)->assertOk();

        self::assertSame(
            1,
            LoadingTask::query()->where('product_id', $this->honey->id)->count(),
            'one task per (assignment, product) — never a second row for a retry',
        );

        $item = $this->custody($this->honey->id);

        self::assertSame(18.0, (float) $item->quantity_loaded, 'not 36');
        self::assertSame(18.0, (float) $item->quantity_on_hand, 'not 36');

        self::assertSame(
            1,
            (int) DB::table('vehicle_inventory_movements')
                ->where('vehicle_inventory_item_id', $item->id)
                ->count(),
            'the second post produced a zero delta, so it appended no movement',
        );
    }

    /**
     * 8 — CASE (c), 18 → 12. THE P1 DEFECT, NOW FIXED AND PINNED FIXED.
     *
     * ┌─ WHAT USED TO HAPPEN, AND WHY IT NO LONGER DOES ─────────────────────────┐
     * │ `LoadProductAction` is an idempotent absolute SET that moves custody by   │
     * │ the DELTA, so 18 → 12 produces −6. That signed delta was handed straight  │
     * │ to `recordLoad()`, which appended it to `vehicle_inventory_movements` —   │
     * │ a table carrying `chk_vehicle_inventory_movements_quantity                │
     * │ CHECK (quantity > 0)`. The insert was rejected, the whole transaction     │
     * │ rolled back, and the driver got a 422 with the raw SQL in it. A driver    │
     * │ who typed 18 for a load of 12 could never correct it.                     │
     * │                                                                          │
     * │ The fix does NOT touch the constraint. It honours the ledger's own        │
     * │ convention — positive MAGNITUDE in `quantity`, direction in               │
     * │ `movement_type` — which is already how `allocated`, `unallocated`,        │
     * │ `delivered` and `returned` all record reductions. A downward correction   │
     * │ is now one `adjusted` row of +6.                                          │
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * The constraint itself is re-asserted from information_schema, so this test
     * fails loudly if a future change ever "fixes" the defect by removing the rule
     * instead of respecting it.
     */
    public function test_a_downward_correction_reduces_custody_and_records_a_positive_adjustment(): void
    {
        $this->load($this->shipmentA, $this->honey->id, 18.0)->assertOk();

        // WAREHOUSE STOCK IS NOT CREDITED by an un-load. Loading never deducted it
        // in this architecture, so the baseline is taken here and compared after.
        $warehouseLedgerBefore = (int) DB::table('stock_ledger_entries')
            ->where('product_id', $this->honey->id)->count();

        $this->load($this->shipmentA, $this->honey->id, 12.0)->assertOk();

        // The stated intent of the absolute SET: the task now says 12, not 18.
        $task = $this->task($this->honey->id);

        self::assertSame(12.0, (float) $task->quantity_loaded, 'the correction is reflected, not ignored');
        self::assertSame(20.0, (float) $task->quantity_planned, 'Required is untouched by a correction');
        self::assertSame(8.0, (float) $task->quantity_short, '20 required − 12 actually loaded');

        // Custody was reduced by exactly the 6 that were never on the vehicle.
        $item = $this->custody($this->honey->id);

        self::assertSame(12.0, (float) $item->quantity_loaded);
        self::assertSame(12.0, (float) $item->quantity_on_hand);
        self::assertSame(12.0, (float) $item->quantity_unallocated);
        self::assertSame(0.0, (float) $item->quantity_delivered);
        self::assertSame(0.0, (float) $item->quantity_returned);
        self::assertSame('active', $this->statusValue($item->status), 'still 12 on board, so not depleted');

        // THE LEDGER. Two rows: the original load, then a POSITIVE `adjusted` 6.
        // The direction lives in the type, never in the sign — asserted as a pair
        // so a `loaded` row of 6 (which would read as a second load) cannot pass.
        self::assertSame(
            [['loaded', 18.0], ['adjusted', 6.0]],
            $this->ledger($item),
            'a downward correction is an `adjusted` movement of +6, never a `loaded` one',
        );

        $adjustment = DB::table('vehicle_inventory_movements')
            ->where('vehicle_inventory_item_id', $item->id)
            ->where('movement_type', 'adjusted')
            ->first();

        self::assertNotNull($adjustment, 'the correction is auditable as its own movement row');
        self::assertSame(6.0, (float) $adjustment->quantity);
        self::assertGreaterThan(0, (float) $adjustment->quantity, 'the magnitude is POSITIVE 6, not −6');
        self::assertSame('loading_task', $adjustment->reference_type);
        self::assertSame((string) $task->id, (string) $adjustment->reference_id, 'traceable to the task it corrects');

        // NO row anywhere in the ledger is negative — the invariant the CHECK
        // constraint exists to protect, asserted over the whole table.
        self::assertSame(
            0,
            (int) DB::table('vehicle_inventory_movements')->where('quantity', '<', 0)->count(),
            'no movement row carries a negative quantity',
        );

        // The rule is still enforced by the SCHEMA, not merely respected by the code.
        self::assertNotNull(
            DB::selectOne(
                "SELECT CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND CONSTRAINT_NAME = 'chk_vehicle_inventory_movements_quantity'",
            ),
            'the fix respects the movement ledger constraint rather than removing it',
        );

        // Un-loading invents no inward warehouse movement for the 6 that stayed behind.
        self::assertSame(
            $warehouseLedgerBefore,
            (int) DB::table('stock_ledger_entries')->where('product_id', $this->honey->id)->count(),
            'un-loading fabricates no warehouse stock movement',
        );
    }

    /**
     * 8b — CASE (d), 12 → 18. The same absolute-set mechanism, upwards.
     *
     * Correcting 12 → 18 moves custody by +6 rather than to 6, which is what proves
     * the write is a SET with a delta-driven inventory move and not an increment.
     * Both rows are `loaded`: an upward correction is more load, not an adjustment.
     */
    public function test_an_upward_correction_moves_custody_by_the_delta(): void
    {
        $this->load($this->shipmentA, $this->honey->id, 12.0)->assertOk();
        $this->load($this->shipmentA, $this->honey->id, 18.0)->assertOk();

        $task = $this->task($this->honey->id);

        self::assertSame(18.0, (float) $task->quantity_loaded, 'set to 18, not 12 + 18');
        self::assertSame(2.0, (float) $task->quantity_short);

        $item = $this->custody($this->honey->id);

        self::assertSame(18.0, (float) $item->quantity_loaded, 'custody moved by +6, not to 6 and not to 30');
        self::assertSame(18.0, (float) $item->quantity_on_hand);

        self::assertSame(
            [['loaded', 12.0], ['loaded', 6.0]],
            $this->ledger($item),
            'two movements: the original load and the +6 delta — never a restatement of the total',
        );
    }

    /**
     * 8c — CASE (e), the FLOOR. A correction can never drive custody negative.
     *
     * TWO SURFACES, BOTH ASSERTED, BECAUSE THEY FAIL DIFFERENTLY:
     *
     *   1. OVER HTTP the request itself cannot express a negative custody. The
     *      endpoint takes an ABSOLUTE quantity validated `min:0`, so the deepest
     *      correction a driver can post is 18 → 0. That is asserted here as the
     *      real floor: custody lands on exactly 0 (not below), the item goes
     *      `depleted`, and the whole 18 is retired by one positive `adjusted` row.
     *   2. THE GUARD ITSELF is therefore not reachable through the endpoint, so it
     *      is exercised DIRECTLY against `VehicleInventoryService::recordLoadCorrection()`
     *      with a magnitude larger than the custody holds. Stated plainly: case (e)
     *      uses the SERVICE for the guard and HTTP for the floor.
     */
    public function test_a_correction_can_never_drive_custody_negative(): void
    {
        $this->load($this->shipmentA, $this->honey->id, 18.0)->assertOk();

        // ── 1. The floor that IS reachable over HTTP: 18 → 0.
        $this->load($this->shipmentA, $this->honey->id, 0.0)->assertOk();

        $item = $this->custody($this->honey->id);

        self::assertSame(0.0, (float) $this->task($this->honey->id)->quantity_loaded);
        self::assertSame(0.0, (float) $item->quantity_loaded, 'exactly zero, never below');
        self::assertSame(0.0, (float) $item->quantity_on_hand);
        self::assertSame(0.0, (float) $item->quantity_unallocated);
        self::assertSame('depleted', $this->statusValue($item->status), 'nothing left on board');

        self::assertSame(
            [['loaded', 18.0], ['adjusted', 18.0]],
            $this->ledger($item),
            'the whole load is retired by one positive adjustment',
        );

        // ── 2. The guard, reached directly because HTTP cannot express it.
        $assignment = $this->assignment();
        $task = $this->task($this->honey->id);

        // Put 5 back on the vehicle so there is something to over-correct against.
        $this->load($this->shipmentA, $this->honey->id, 5.0)->assertOk();

        self::assertSame(5.0, (float) $this->custody($this->honey->id)->quantity_loaded);

        $before = $this->custody($this->honey->id);
        $movementsBefore = $this->ledger($before);

        try {
            app(VehicleInventoryService::class)->recordLoadCorrection(
                assignment: $assignment,
                task: $task->refresh(),
                quantityRemoved: 25.0, // more than the 5 in custody
                actorId: (string) $this->shipmentA['user']->id,
            );
            self::fail('a correction larger than the loaded quantity must be refused');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('below zero', $e->getMessage());
        }

        $after = $this->custody($this->honey->id);

        self::assertSame(5.0, (float) $after->quantity_loaded, 'the refused correction moved nothing');
        self::assertSame(5.0, (float) $after->quantity_on_hand);
        self::assertSame($movementsBefore, $this->ledger($after), 'and appended no movement row');
        self::assertSame(
            0,
            (int) DB::table('vehicle_inventory_items')->where('quantity_loaded', '<', 0)->count(),
            'no custody row is ever negative',
        );
    }

    /**
     * 8d — CASE (f), ATOMICITY of a refused correction.
     *
     * ┌─ WHAT THIS DOES AND DOES NOT PROVE — SAID PLAINLY ───────────────────────┐
     * │ This is NOT a real parallel-connection race. A genuine concurrent test    │
     * │ needs two live DB connections interleaved at a chosen instruction, which  │
     * │ this harness (single connection, RefreshDatabase transaction semantics)   │
     * │ cannot reproduce reliably; a flaky "concurrency" test would be worse than │
     * │ none. What is asserted instead is the property that makes concurrency     │
     * │ safe, and it is asserted for real, not simulated:                          │
     * │                                                                          │
     * │   (i)  SERIALIZATION — the row that decides the correction is taken under │
     * │        `lockForUpdate()` in BOTH layers: `LoadProductAction` locks the     │
     * │        `loading_tasks` row, `recordLoadCorrection()` locks the            │
     * │        `vehicle_inventory_items` row. Asserted against the shipped source │
     * │        so the lock cannot be quietly dropped.                             │
     * │   (ii) ALL-OR-NOTHING — a correction that fails its guard leaves the task │
     * │        quantity, the custody row AND the ledger exactly as they were. The │
     * │        task is updated BEFORE the inventory call, so if the rollback were │
     * │        not whole, the task would read 12 while custody still read 18.     │
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * The refusal is produced through the real endpoint by the second guard: 15 of
     * the 18 have already been delivered off the vehicle, so correcting the load to
     * 12 would retract product that is already accounted for elsewhere.
     */
    public function test_a_refused_correction_leaves_task_custody_and_ledger_untouched(): void
    {
        $this->load($this->shipmentA, $this->honey->id, 18.0)->assertOk();

        $item = $this->custody($this->honey->id);

        // (i) SERIALIZATION, asserted against the shipped source of both layers.
        self::assertStringContainsString(
            'lockForUpdate()',
            $this->sourceOf(LoadProductAction::class),
            'the loading_tasks row is taken under a row lock before the delta is computed',
        );
        self::assertStringContainsString(
            'lockForUpdate()',
            $this->sourceOf(VehicleInventoryService::class),
            'the custody row is taken under a row lock before it is corrected',
        );

        // 15 of the 18 have already left the vehicle as deliveries. Written directly
        // because the delivery endpoints are a different suite's contract; this is a
        // fixture for the guard, not a claim about how deliveries are recorded.
        DB::table('vehicle_inventory_items')->where('id', $item->id)->update([
            'quantity_delivered' => 15,
            'quantity_on_hand' => 3,
        ]);

        $refused = $this->load($this->shipmentA, $this->honey->id, 12.0);

        $refused->assertStatus(422);
        self::assertStringContainsString(
            'already been delivered or returned',
            (string) $refused->json('message'),
            'a business refusal in plain language — no SQL, no schema names',
        );
        self::assertStringNotContainsString('SQLSTATE', (string) $refused->json('message'));

        // (ii) ALL-OR-NOTHING. The task update happens first inside the same
        // transaction, so a partial commit would show 12 here.
        self::assertSame(
            18.0,
            (float) $this->task($this->honey->id)->quantity_loaded,
            'the task rolled back with the failed correction',
        );

        $after = $this->custody($this->honey->id);

        self::assertSame(18.0, (float) $after->quantity_loaded, 'custody is exactly as it was');
        self::assertSame(15.0, (float) $after->quantity_delivered);
        self::assertSame(3.0, (float) $after->quantity_on_hand);
        self::assertSame(18.0, (float) $after->quantity_unallocated);

        self::assertSame(
            [['loaded', 18.0]],
            $this->ledger($after),
            'no orphan movement row survives the refusal',
        );

        self::assertSame(
            18.0,
            (float) $this->assignment()->loading_weight_kg,
            'and the assignment weight was not moved by a correction that never happened',
        );
    }

    /**
     * 8e — CASE (h), THE ALLOCATION FLOOR. A correction may not cut below a LIVE earmark.
     *
     * ┌─ THE HOLE THE FIRST FIX LEFT, NOW CLOSED ────────────────────────────────┐
     * │ `recordLoadCorrection()` originally guarded two things: custody must not   │
     * │ go negative, and product already DELIVERED or RETURNED must not be         │
     * │ retracted. It said nothing about product still physically on board that is │
     * │ already ALLOCATED to orders.                                              │
     * │                                                                          │
     * │ So: load 18 → the allocation engine earmarks all 18 → correct to 12 and    │
     * │ the item read `loaded 12, allocated 18`. Deliveries ceiling against the    │
     * │ AllocationRecord (`RecordProductDeliveryAction`), NEVER against this item, │
     * │ so the driver could still deliver the full 18 out of a custody of 12 — and │
     * │ the 6-unit excess was absorbed by the `max(0, …)` clamps in                │
     * │ `recordDelivery()` and `VehicleShiftReconciliationService`. The variance    │
     * │ was not reported; it was ERASED. That is the worst possible failure mode   │
     * │ for a custody ledger: silent, and shaped exactly like a clean shift.       │
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * SURFACE USED: HTTP — the real driver endpoint. Unlike the below-zero floor in
     * case (e), this guard IS expressible through the endpoint: 18 → 12 is a
     * perfectly ordinary absolute-set correction, and it is the allocation state of
     * the row (not the posted number) that makes it illegal. So the refusal is
     * proved where a driver would actually meet it, as a 422 in plain language.
     */
    public function test_a_correction_below_a_live_allocation_is_refused(): void
    {
        $this->load($this->shipmentA, $this->honey->id, 18.0)->assertOk();

        $item = $this->custody($this->honey->id);

        // All 18 are earmarked to orders on this vehicle. Written directly with
        // DB::table()->update() — the same way case (f) plants its delivered/returned
        // fixture — because AutoAllocationService is a different suite's contract.
        // This is a fixture FOR the guard, not a claim about how allocation happens.
        DB::table('vehicle_inventory_items')->where('id', $item->id)->update([
            'quantity_allocated' => 18,
            'quantity_unallocated' => 0,
        ]);

        $refused = $this->load($this->shipmentA, $this->honey->id, 12.0);

        $refused->assertStatus(422);

        $message = (string) $refused->json('message');

        self::assertStringContainsString(
            'still allocated to orders on this vehicle',
            $message,
            'the refusal names the ALLOCATION as the reason — not "below zero", not "delivered"',
        );
        self::assertStringContainsString(
            'Release the allocation first',
            $message,
            'and tells the driver which lever actually unblocks it',
        );
        self::assertStringNotContainsString('SQLSTATE', $message, 'a business refusal, never raw SQL');

        // The task rolled back with the refused correction: it still states 18.
        self::assertSame(
            18.0,
            (float) $this->task($this->honey->id)->quantity_loaded,
            'the task is not left claiming 12 while custody holds 18',
        );

        // CUSTODY IS EXACTLY AS IT WAS — including the earmark, which a loading
        // correction has no authority to shrink.
        $after = $this->custody($this->honey->id);

        self::assertSame(18.0, (float) $after->quantity_loaded, 'custody untouched');
        self::assertSame(18.0, (float) $after->quantity_allocated, 'the earmark untouched');
        self::assertSame(0.0, (float) $after->quantity_unallocated);
        self::assertSame(18.0, (float) $after->quantity_on_hand);
        self::assertSame(0.0, (float) $after->quantity_delivered);
        self::assertSame('active', $this->statusValue($after->status));

        // The state the guard exists to prevent: allocated must never exceed loaded.
        self::assertLessThanOrEqual(
            (float) $after->quantity_loaded,
            (float) $after->quantity_allocated,
            'allocated > loaded is the corruption this guard refuses to create',
        );

        // NO new movement row: the original load, and nothing else.
        self::assertSame(
            [['loaded', 18.0]],
            $this->ledger($after),
            'a refused correction appends no `adjusted` row',
        );

        self::assertSame(
            18.0,
            (float) $this->assignment()->loading_weight_kg,
            'and the assignment weight did not move either',
        );
    }

    /**
     * 8f — CASE (i), the same guard's PERMISSIVE side. Down to the earmark is fine.
     *
     * The guard is a floor at the allocation, not a ban on correcting an allocated
     * item. With 10 of 18 earmarked, correcting to 12 leaves the whole earmark
     * covered and 2 units still free, so it must SUCCEED.
     *
     * WHAT THIS PINS THAT (h) CANNOT. `quantity_unallocated` is written as
     * `max(0, unallocated − magnitude)`. Asserting the exact 2 here — rather than
     * merely "not negative" — is what proves the clamp absorbed nothing: 8 − 6 = 2
     * is also exactly `corrected − allocated`, so the two independent ways of
     * arriving at the free pool agree. If the guard were ever loosened, this figure
     * is the first thing that would start lying.
     *
     * `quantity_on_hand` stays 12, NOT 2: allocation is an earmark INSIDE on-hand,
     * never a deduction from it, and the correction must not conflate the two.
     */
    public function test_a_correction_down_to_the_allocated_quantity_succeeds_and_keeps_the_earmark_whole(): void
    {
        $this->load($this->shipmentA, $this->honey->id, 18.0)->assertOk();

        $item = $this->custody($this->honey->id);

        // 10 of the 18 are earmarked to orders, 8 are still free. Written directly
        // with DB::table()->update(), as in case (f) and (h) above.
        DB::table('vehicle_inventory_items')->where('id', $item->id)->update([
            'quantity_allocated' => 10,
            'quantity_unallocated' => 8,
        ]);

        $this->load($this->shipmentA, $this->honey->id, 12.0)->assertOk();

        $task = $this->task($this->honey->id);

        self::assertSame(12.0, (float) $task->quantity_loaded, 'the correction was accepted, not refused');
        self::assertSame(8.0, (float) $task->quantity_short, '20 required − 12 actually loaded');

        $after = $this->custody($this->honey->id);

        self::assertSame(12.0, (float) $after->quantity_loaded, 'custody moved down by exactly 6');
        self::assertSame(10.0, (float) $after->quantity_allocated, 'the earmark is untouched by a loading correction');
        self::assertSame(
            2.0,
            (float) $after->quantity_unallocated,
            '12 corrected − 10 allocated = 2 free: the max(0, …) clamp absorbed nothing',
        );
        self::assertSame(
            12.0,
            (float) $after->quantity_on_hand,
            'allocation is an earmark INSIDE on-hand, never a deduction from it',
        );
        self::assertSame(0.0, (float) $after->quantity_delivered);
        self::assertSame(0.0, (float) $after->quantity_returned);
        self::assertSame('active', $this->statusValue($after->status), '12 still on board');

        // (iii) THE POST-CONDITION INVARIANT, stated as itself rather than left to be
        // inferred from the three figures above: the earmarked and the free pool
        // together account for the whole custody, with nothing lost or invented.
        self::assertSame(
            (float) $after->quantity_loaded,
            (float) $after->quantity_allocated + (float) $after->quantity_unallocated,
            'allocated + unallocated == loaded',
        );

        // Exactly one new movement, and it is a POSITIVE `adjusted` magnitude of 6.
        self::assertSame(
            [['loaded', 18.0], ['adjusted', 6.0]],
            $this->ledger($after),
            'one adjusted row of +6 — an allocated item corrects the same way any other does',
        );
        self::assertSame(
            1,
            (int) DB::table('vehicle_inventory_movements')
                ->where('vehicle_inventory_item_id', $after->id)
                ->where('movement_type', 'adjusted')
                ->count(),
            'exactly one adjustment, not one per pool touched',
        );

        self::assertSame(
            12.0,
            (float) $this->assignment()->loading_weight_kg,
            'the weight column was DECREMENTED by 6, not incremented by −6 (CHECK >= 0)',
        );
    }

    /** 9 — stock already on the vehicle is ADDED TO, never overwritten by a new load. */
    public function test_existing_vehicle_stock_accumulates_rather_than_being_overwritten(): void
    {
        // A zero load opens the assignment and creates the task row without moving
        // anything (the action only touches inventory when the quantity is > 0), so
        // the pre-existing custody row below has the FK target the schema requires
        // and a clean 0 baseline to accumulate onto.
        $this->load($this->shipmentA, $this->honey->id, 0.0)->assertOk();

        $task = $this->task($this->honey->id);

        self::assertSame(
            0,
            VehicleInventoryItem::query()->where('product_id', $this->honey->id)->count(),
            'a zero load moves no stock',
        );

        // 10 units are already on this vehicle for this product.
        VehicleInventoryItem::query()->create([
            'company_id' => $task->company_id,
            'vehicle_assignment_id' => $task->vehicle_assignment_id,
            'vehicle_id' => (string) VehicleAssignment::query()
                ->whereKey($task->vehicle_assignment_id)->value('vehicle_id'),
            'product_id' => $this->honey->id,
            'sku_snapshot' => (string) $task->sku_snapshot,
            'name_snapshot' => (string) $task->name_snapshot,
            'operational_date' => now()->toDateString(),
            'loading_task_id' => $task->id,
            'quantity_loaded' => 10,
            'quantity_on_hand' => 10,
            'quantity_unallocated' => 10,
            'status' => 'active',
            'created_by' => (string) $this->shipmentA['user']->id,
            'updated_by' => (string) $this->shipmentA['user']->id,
        ]);

        $this->load($this->shipmentA, $this->honey->id, 15.0)->assertOk();

        $item = $this->custody($this->honey->id);

        self::assertSame(25.0, (float) $item->quantity_loaded, '10 + 15, not 15');
        self::assertSame(25.0, (float) $item->quantity_on_hand, '10 + 15, not 15');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // D. Handover — explicit, and safe to repeat
    // ═════════════════════════════════════════════════════════════════════════

    /** 10 — completion is an explicit act, and repeating it changes nothing. */
    public function test_completing_the_loading_is_explicit_and_idempotent(): void
    {
        $this->load($this->shipmentA, $this->honey->id, 18.0)->assertOk();

        $this->complete($this->shipmentA)->assertOk();

        $assignment = $this->assignment();

        self::assertSame('loading_complete', $this->statusValue($assignment->status));
        self::assertNotNull($assignment->loading_completed_at);

        $stamp = $assignment->loading_completed_at->toDateTimeString();
        $loaded = (float) $this->custody($this->honey->id)->quantity_loaded;

        self::assertSame(18.0, $loaded);

        // The driver taps "done" a second time.
        $this->complete($this->shipmentA)->assertOk();

        $again = $this->assignment();

        self::assertSame('loading_complete', $this->statusValue($again->status), 'the status did not move on');
        self::assertSame($stamp, $again->loading_completed_at->toDateTimeString(), 'and was not re-stamped');

        self::assertSame(
            1,
            VehicleInventoryItem::query()->where('product_id', $this->honey->id)->count(),
        );
        self::assertSame(
            $loaded,
            (float) $this->custody($this->honey->id)->quantity_loaded,
            'completion never touches vehicle inventory, so it cannot duplicate it',
        );
    }

    // ═════════════════════════════════════════════════════════════════════════
    // E. The completion → orders bridge (TASK-DRIVER-LOADING-COMPLETION-ORDERS-BRIDGE-001)
    //
    // Completing loading is the seam where a loaded shipment becomes deliverable
    // work. These pin the defect the driver hit as "No stops match the filter":
    // completion must materialise delivery stops from the trip's orders AND advance
    // the trip through the EXISTING lifecycle, so the driver's own Orders page and
    // dashboard read real rows. Everything runs over the real driver endpoints.
    // ═════════════════════════════════════════════════════════════════════════

    /** C + D — completion finalizes the group into trip_orders and materialises stops. */
    public function test_completing_the_loading_generates_stops_and_advances_the_trip(): void
    {
        $trip = $this->shipmentA['trip'];

        // BEFORE: this is an assign-vehicle-first shipment — the Group's two orders live
        // on the Group, the Trip is a bare vehicle commitment with NO trip_orders and NO
        // stops. This is the exact state that rendered "No stops match the filter".
        self::assertSame(
            0,
            DB::table('distribution_trip_orders')->where('trip_id', $trip->id)->count(),
            'before: the bare trip has no trip_orders',
        );
        self::assertSame(
            0,
            DB::table('distribution_delivery_stops')->where('trip_id', $trip->id)->count(),
            'before: no delivery stops',
        );

        $this->load($this->shipmentA, $this->honey->id, 18.0)->assertOk();
        $this->complete($this->shipmentA)->assertOk();

        // AFTER: completion finalized the Group through GroupFinalizationService — the two
        // Group orders are now trip_orders (canonical service, no direct insert, no
        // invented split), and DeliveryService::generateStops produced one stop per order.
        self::assertSame(
            2,
            DB::table('distribution_trip_orders')->where('trip_id', $trip->id)->count(),
            'the Group\'s two orders became trip_orders via the canonical finalization',
        );
        self::assertSame(
            2,
            DB::table('distribution_delivery_stops')->where('trip_id', $trip->id)->count(),
            'one delivery stop per trip order',
        );

        // The trip advanced through the EXISTING lifecycle to loading_completed.
        self::assertSame(
            'loading_completed',
            DB::table('distribution_trips')->where('id', $trip->id)->value('status'),
        );

        // The stops are the SAME orders the Group carries — no invented or duplicated
        // orders. Every stop's order_id is one of the Group's trip_orders.
        $tripOrderIds = DB::table('distribution_trip_orders')->where('trip_id', $trip->id)->pluck('order_id')->all();
        $stopOrderIds = DB::table('distribution_delivery_stops')->where('trip_id', $trip->id)->pluck('order_id')->all();
        sort($tripOrderIds);
        sort($stopOrderIds);
        self::assertSame($tripOrderIds, $stopOrderIds, 'stops map exactly to the trip orders — no invention, no duplicates');
    }

    /** E — completion is idempotent: a repeat never duplicates trip_orders or stops. */
    public function test_completing_the_loading_twice_does_not_duplicate_orders_or_stops(): void
    {
        $trip = $this->shipmentA['trip'];

        $this->load($this->shipmentA, $this->honey->id, 18.0)->assertOk();
        $this->complete($this->shipmentA)->assertOk();

        self::assertSame(2, DB::table('distribution_trip_orders')->where('trip_id', $trip->id)->count());
        self::assertSame(2, DB::table('distribution_delivery_stops')->where('trip_id', $trip->id)->count());

        // The driver taps "done" a second time.
        $this->complete($this->shipmentA)->assertOk();

        self::assertSame(
            2,
            DB::table('distribution_trip_orders')->where('trip_id', $trip->id)->count(),
            'finalization is idempotent — no duplicate trip_orders',
        );
        self::assertSame(
            2,
            DB::table('distribution_delivery_stops')->where('trip_id', $trip->id)->count(),
            'and generateStops does not duplicate stops',
        );
        self::assertSame(
            'loading_completed',
            DB::table('distribution_trips')->where('id', $trip->id)->value('status'),
        );
    }

    /** G — after completion the driver's OWN Orders/Stops endpoint returns the stops. */
    public function test_the_driver_orders_endpoint_sees_the_generated_stops(): void
    {
        $trip = $this->shipmentA['trip'];

        // Before completion the page is empty — the exact "No stops match the filter" state.
        $this->actingAs($this->shipmentA['user'])
            ->getJson('/api/driver/trips/'.$trip->uuid.'/stops')
            ->assertOk()
            ->assertJsonCount(0);

        $this->load($this->shipmentA, $this->honey->id, 18.0)->assertOk();
        $this->complete($this->shipmentA)->assertOk();

        // Now the driver's own read model returns the two stops.
        $this->actingAs($this->shipmentA['user'])
            ->getJson('/api/driver/trips/'.$trip->uuid.'/stops')
            ->assertOk()
            ->assertJsonCount(2);
    }

    /** H — the driver's trips/dashboard read reflects the generated stops as its order count. */
    public function test_the_driver_dashboard_count_reflects_the_generated_stops(): void
    {
        $trip = $this->shipmentA['trip'];

        $this->load($this->shipmentA, $this->honey->id, 18.0)->assertOk();
        $this->complete($this->shipmentA)->assertOk();

        $trips = $this->actingAs($this->shipmentA['user'])
            ->getJson('/api/driver/trips')
            ->assertOk()
            ->json();

        $row = collect($trips)->firstWhere('id', $trip->uuid);
        self::assertNotNull($row, "the driver's own trip is listed");
        self::assertSame(2, (int) $row['stops_count'], 'stops_count reflects the generated stops');
    }

    /**
     * 11 — CASE (g), the CEILING. Unchanged by the correction fix.
     *
     * Over-loading has no approved contract. It is refused, and writes nothing —
     * neither a task nor a custody row nor a movement. Asserted after the fix so
     * the new correction branch cannot have opened a way past the ceiling.
     */
    public function test_an_over_load_is_refused_and_writes_nothing(): void
    {
        $this->load($this->shipmentA, $this->honey->id, 25.0)->assertStatus(422);

        self::assertSame(
            0,
            LoadingTask::query()->where('product_id', $this->honey->id)->count(),
            'no task row survives a refused over-load',
        );
        self::assertSame(
            0,
            VehicleInventoryItem::query()->where('product_id', $this->honey->id)->count(),
            'and nothing reached the vehicle',
        );
        self::assertSame(
            0,
            (int) DB::table('vehicle_inventory_movements')->where('product_id', $this->honey->id)->count(),
            'and the ledger is untouched',
        );
    }

    /** 11b — the ceiling still holds when the over-load arrives as a CORRECTION. */
    public function test_an_over_load_correction_is_refused_and_leaves_the_previous_load_intact(): void
    {
        $this->load($this->shipmentA, $this->honey->id, 18.0)->assertOk();

        $this->load($this->shipmentA, $this->honey->id, 25.0)->assertStatus(422);

        self::assertSame(18.0, (float) $this->task($this->honey->id)->quantity_loaded);

        $item = $this->custody($this->honey->id);

        self::assertSame(18.0, (float) $item->quantity_loaded);
        self::assertSame([['loaded', 18.0]], $this->ledger($item), 'the refused correction wrote nothing');
    }

    // ═════════════════════════════════════════════════════════════════════════
    // E. Authorization — a real role, so the 403 is real
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * 12 — the runtime permission is enforced.
     *
     * `TestCase::actingAs()` grants a role-less user the is_system role, which the
     * permission middleware passes unconditionally — so a subject built that way
     * would prove only that the route resolves. Both users here wear a REAL,
     * non-system role, and both ARE drivers holding a real shipment: the only
     * difference between the 403 and the 200 is the grant itself.
     */
    public function test_the_driver_runtime_permission_is_enforced(): void
    {
        $b = $this->shipment(
            $this->company,
            $this->warehouse,
            $this->zoneB,
            'Nasr City',
            $this->nuts,
            'DG-CUSTODY-PERM',
        );

        $this->wearRole($this->shipmentA['user'], 'test-custody-no-runtime', ['logistics.shipping.view']);
        $this->wearRole($b['user'], 'test-custody-runtime', [self::DRIVER_RUNTIME]);

        $this->actingAsUnprivileged($this->shipmentA['user'])
            ->getJson(self::DRIVER)
            ->assertForbidden();

        // The control: the identical request, from an equally real driver who holds
        // the grant, is allowed. So the 403 above is the permission, not the fixture.
        $this->actingAsUnprivileged($b['user'])
            ->getJson(self::DRIVER)
            ->assertOk();
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Driver-endpoint helpers
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * @param  array{user: User, driver: Driver, group: VirtualCapacitySlot, trip: Trip}  $shipment
     * @return array<string, mixed>
     */
    private function manifest(array $shipment): array
    {
        /** @var array<string, mixed> $data */
        $data = $this->actingAs($shipment['user'])
            ->getJson(self::DRIVER)
            ->assertOk()
            ->json('data');

        return $data;
    }

    /** @param  array{user: User, driver: Driver, group: VirtualCapacitySlot, trip: Trip}  $shipment */
    private function load(array $shipment, string $productId, float $quantity): TestResponse
    {
        return $this->actingAs($shipment['user'])
            ->postJson(self::DRIVER.'/products/'.$productId, ['quantity_loaded' => $quantity]);
    }

    /** @param  array{user: User, driver: Driver, group: VirtualCapacitySlot, trip: Trip}  $shipment */
    private function complete(array $shipment): TestResponse
    {
        return $this->actingAs($shipment['user'])->postJson(self::DRIVER.'/complete');
    }

    /**
     * One manifest row, by product.
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>|null
     */
    private function row(array $manifest, string $productId): ?array
    {
        foreach ((array) ($manifest['items'] ?? []) as $item) {
            if ((string) $item['product_id'] === $productId) {
                return $item;
            }
        }

        return null;
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Persistence readers — driver A's shipment unless stated otherwise
    // ═════════════════════════════════════════════════════════════════════════

    private function assignment(): VehicleAssignment
    {
        return VehicleAssignment::query()
            ->where('trip_id', $this->shipmentA['trip']->id)
            ->firstOrFail();
    }

    private function task(string $productId): LoadingTask
    {
        return LoadingTask::query()
            ->where('vehicle_assignment_id', $this->assignment()->id)
            ->where('product_id', $productId)
            ->firstOrFail();
    }

    private function custody(string $productId): VehicleInventoryItem
    {
        return VehicleInventoryItem::query()
            ->where('vehicle_assignment_id', $this->assignment()->id)
            ->where('product_id', $productId)
            ->firstOrFail();
    }

    private function statusValue(mixed $status): string
    {
        return $status instanceof BackedEnum ? (string) $status->value : (string) $status;
    }

    /**
     * The custody movement ledger for one item, in recorded order, as
     * `[[type, quantity], …]`.
     *
     * TYPE AND QUANTITY TOGETHER, deliberately. Asserting the quantities alone
     * would let a reduction recorded as a `loaded` row pass — which is exactly the
     * shortcut the fix was forbidden to take.
     *
     * @return list<array{0: string, 1: float}>
     */
    private function ledger(VehicleInventoryItem $item): array
    {
        return DB::table('vehicle_inventory_movements')
            ->where('vehicle_inventory_item_id', $item->id)
            ->orderBy('recorded_at')->orderBy('id')
            ->get(['movement_type', 'quantity'])
            ->map(static fn ($m): array => [(string) $m->movement_type, (float) $m->quantity])
            ->all();
    }

    /**
     * The shipped source of a class, for the structural assertions in case (f).
     *
     * Read through reflection rather than a hard-coded path so it resolves
     * identically in the container and on a developer machine.
     *
     * @param  class-string  $class
     */
    private function sourceOf(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();

        self::assertIsString($file, "the source of {$class} must be readable");

        return (string) file_get_contents($file);
    }

    // ═════════════════════════════════════════════════════════════════════════
    // Fixtures — every step through the canonical production path
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * One driver's own shipment: a Group holding real orders, its Trip, and the
     * canonical driver↔vehicle pairing that makes the Trip reachable by that driver.
     *
     * @return array{user: User, driver: Driver, group: VirtualCapacitySlot, trip: Trip}
     */
    private function shipment(
        Company $company,
        Warehouse $warehouse,
        int $zoneId,
        string $city,
        Product $product,
        string $code,
        int $orders = 2,
        float $quantity = 10.0,
    ): array {
        for ($i = 0; $i < $orders; $i++) {
            $this->line($this->order($company, $warehouse, $city), $product, $quantity);
        }

        $this->collect($company);

        $windowId = $this->windowId($company, $warehouse);

        $groupId = (string) $this->actingAs($this->operator($company))
            ->postJson(self::BASE."/windows/{$windowId}/slots", [
                'warehouse_id' => $warehouse->id,
                'code' => $code,
            ])
            ->assertSuccessful()
            ->json('data.id');

        $this->actingAs($this->operator($company))
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$groupId}/zones", ['zone_id' => $zoneId])
            ->assertSuccessful();

        $user = User::factory()->create(['company_id' => $company->id]);
        $driver = $this->driver($company, $user);
        $vehicle = $this->vehicle($company);

        $this->actingAs($this->operator($company))
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$groupId}/assign-vehicle", [
                'vehicle_id' => $vehicle->uuid,
                'driver_id' => $driver->uuid,
            ])
            ->assertOk();

        /** @var VirtualCapacitySlot $group */
        $group = VirtualCapacitySlot::query()->findOrFail($groupId);

        /** @var Trip $trip */
        $trip = Trip::query()->where('virtual_slot_id', $groupId)->firstOrFail();

        return ['user' => $user, 'driver' => $driver, 'group' => $group, 'trip' => $trip];
    }

    private function operator(Company $company): User
    {
        return User::factory()->create(['company_id' => $company->id]);
    }

    /**
     * `POST /windows/collect` binds Order city text to canonical geography and then
     * collects. It is asserted to have BOUND everything it examined: an unbound
     * Order carries no zone, joins no Group, and would silently produce an empty
     * manifest several steps later — a fixture fault that reads exactly like a
     * product defect.
     */
    private function collect(Company $company): void
    {
        $this->actingAs($this->operator($company))
            ->postJson(self::BASE.'/windows/collect')
            ->assertOk()
            ->assertJsonPath('data.cities_unresolved', 0);
    }

    private function windowId(Company $company, Warehouse $warehouse): string
    {
        return (string) $this->actingAs($this->operator($company))
            ->getJson(self::BASE.'/windows/current?warehouse_id='.$warehouse->id)
            ->assertOk()
            ->json('data.window.id');
    }

    /**
     * `company_id` is deliberately absent from Driver::$fillable — ownership is
     * stamped in booted() from the acting operator — so it is set on the model
     * rather than mass-assigned. `mobile` and `national_id` are globally unique
     * and NOT NULL; omitting either fails with "doesn't have a default value".
     */
    private function driver(Company $company, User $user): Driver
    {
        $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 8));

        $driver = new Driver([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'driver_code' => 'DRV-'.$suffix,
            'full_name' => 'Driver '.$suffix,
            'mobile' => '01'.random_int(100000000, 999999999),
            'national_id' => (string) random_int(10000000000000, 99999999999999),
            'status' => Driver::STATUS_ACTIVE,
        ]);
        $driver->company_id = $company->id;
        $driver->save();

        return $driver->refresh();
    }

    private function vehicle(Company $company): Vehicle
    {
        $suffix = strtoupper(substr(md5(uniqid('', true)), 0, 8));

        return Vehicle::create([
            'company_id' => $company->id,
            'vehicle_code' => 'VEH-'.$suffix,
            'plate_number' => 'PL-'.$suffix,
            'type' => 'van',
            // ECOS capacity contract: capacity is an ORDER COUNT and nothing else.
            'capacity_orders' => 40,
            'status' => 'available',
        ]);
    }

    private function zone(string $name): int
    {
        return (int) DB::table('distribution_zones')->insertGetId([
            'code' => 'CUST-'.strtoupper(substr(md5(uniqid('', true)), 0, 6)),
            'name_ar' => $name, 'name_en' => $name,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function city(int $governorate, string $en, string $ar, int $zoneId): void
    {
        DB::table('logistics_cities')->insert([
            'governorate_id' => $governorate,
            'name_ar' => $ar, 'name_en' => $en,
            'distribution_zone_id' => $zoneId,
            'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function order(Company $company, Warehouse $warehouse, string $city): Order
    {
        return Order::query()->create([
            'company_id' => $company->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-CUST-'.uniqid(),
            'order_date' => now()->toDateString(),
            'assigned_warehouse_id' => $warehouse->id,
            'city' => $city,
            'governorate' => 'Cairo',
            'status' => 'in_progress',
            'subtotal' => 100, 'total' => 100,
            'deposit_amount' => 0,
            'shipping_total' => 0, 'discount_total' => 0, 'tax_total' => 0,
        ]);
    }

    private function line(Order $order, Product $product, float $quantity): void
    {
        OrderLine::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'unit_price' => 10,
            'line_total' => $quantity * 10,
        ]);
    }

    /**
     * Dress a user in a REAL, non-system role holding exactly `$names`.
     *
     * The same shape DriverRbacTenancySecurityTest uses: a role row plus real
     * Permission rows on the pivot, so the middleware answers from the same
     * objects production reads rather than from a test-only shortcut.
     *
     * @param  list<string>  $names
     */
    private function wearRole(User $user, string $slug, array $names): void
    {
        $role = Role::firstOrCreate(['slug' => $slug], ['name' => $slug, 'is_system' => false]);

        $pivot = [];
        foreach ($names as $name) {
            $permission = Permission::firstOrCreate(
                ['name' => $name],
                ['module' => Str::before($name, '.'), 'action' => Str::afterLast($name, '.')],
            );
            $pivot[$permission->id] = ['effect' => 'allow', 'data_scope' => 'all'];
        }
        $role->permissions()->sync($pivot);

        $user->roles()->sync([$role->id]);
        $user->unsetRelation('roles');
    }
}
