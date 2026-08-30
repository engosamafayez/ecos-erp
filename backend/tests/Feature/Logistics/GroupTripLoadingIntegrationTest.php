<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Orders\Domain\Models\OrderLine;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Logistics\Vehicles\Domain\Models\Vehicle;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-OPERATIONS-GROUP-TRIP-LOADING-CONTRACT-ALIGNMENT-001.
 *
 * ┌─ THE ECOS CAPACITY CONTRACT ─────────────────────────────────────────────┐
 * │ Capacity is an ORDER COUNT and nothing else.                             │
 * │ Weight, Volume and Refrigeration are NOT ECOS business constraints.      │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * The chain under test:  Group → Trip → Vehicle/Driver → Loading.
 *
 * Loading stops at LOADED. It never issues stock, never touches FIFO or COGS,
 * and never moves an order to out_for_delivery — those belong to Dispatch,
 * which is a separate authorised workstream and is not exercised here.
 */
class GroupTripLoadingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/logistics/distribution';

    private Company $companyA;

    private Company $companyB;

    private Customer $customer;

    private Warehouse $warehouseA;

    private int $zoneMaadi;

    private Product $honey;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('distribution.window.opens_at', '00:00');
        config()->set('distribution.window.closes_at', '23:59');

        $this->companyA = Company::factory()->create();
        $this->companyB = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->warehouseA = Warehouse::factory()->create(['company_id' => $this->companyA->id]);

        $governorate = (int) DB::table('logistics_governorates')->insertGetId([
            'country_id' => 1,
            'name_ar' => 'القاهرة', 'name_en' => 'Cairo',
            'default_shipping_price' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->zoneMaadi = $this->zone('Maadi');
        $this->city($governorate, 'Maadi', 'المعادي', $this->zoneMaadi);
        $this->honey = Product::factory()->create();
    }

    // ── The contract: weight / volume / refrigeration are not constraints ─────

    public function test_a_vehicle_assignment_no_longer_requires_weight_volume_or_refrigeration(): void
    {
        // The schema itself must stop demanding them, or every caller is forced
        // to invent a number.
        foreach (['capacity_weight_kg_snapshot', 'capacity_volume_m3_snapshot'] as $column) {
            $this->assertTrue(
                $this->isNullable('vehicle_assignments', $column),
                "{$column} must be nullable — weight and volume are not ECOS business constraints.",
            );
        }
    }

    public function test_opening_loading_stores_null_capacity_rather_than_zero(): void
    {
        $ctx = $this->openLoading($this->readyGroup('DG-L1', 2, capacity: 10));

        $row = DB::table('vehicle_assignments')->where('id', $ctx['loading']['assignment_id'])->first();

        // 0 would be a real measurement meaning "carries nothing". NULL is the
        // truth: not measured. Writing 0 to satisfy a column would be exactly the
        // contract circumvention this task forbids.
        $this->assertNull($row->capacity_weight_kg_snapshot);
        $this->assertNull($row->capacity_volume_m3_snapshot);
    }

    // ── Group → Trip → Vehicle/Driver → Loading ───────────────────────────────

    public function test_loading_resolves_the_canonical_group_trip_vehicle_driver_and_warehouse(): void
    {
        $group = $this->readyGroup('DG-L2', 3, capacity: 10);
        $ctx = $this->openLoading($group);

        $this->assertSame($group['id'], $ctx['group']['id']);
        $this->assertSame('DG-L2', $ctx['group']['code']);
        $this->assertSame($this->warehouseA->id, $ctx['group']['warehouse_id']);

        $this->assertNotNull($ctx['trip']['id']);
        $this->assertNotNull($ctx['vehicle']['id'], 'Vehicle must resolve from the canonical pairing.');
        $this->assertNotNull($ctx['driver']['id'], 'Driver must resolve from the canonical pairing.');

        // The Loading vehicle assignment points at the Trip — that link is how
        // Loading reaches the Group, rather than storing a group id of its own.
        $tripRow = DB::table('distribution_trips')->where('uuid', $ctx['trip']['id'])->first();
        $this->assertDatabaseHas('vehicle_assignments', [
            'id' => $ctx['loading']['assignment_id'],
            // The link stores the internal bigint; the API publishes the uuid.
            'trip_id' => $tripRow->id,
        ]);

        // The session is a WAREHOUSE + DATE container, per the existing contract.
        $this->assertDatabaseHas('loading_sessions', [
            'id' => $ctx['loading']['session_id'],
            'warehouse_id' => $this->warehouseA->id,
        ]);
    }

    // ── Quantity sources ──────────────────────────────────────────────────────

    public function test_required_prepared_and_remaining_come_from_the_canonical_group_contract(): void
    {
        $group = $this->readyGroup('DG-L3', 2, capacity: 10);
        $ctx = $this->openLoading($group);

        $this->assertNotEmpty($ctx['products'], 'Loading must see the Group products.');

        $row = $ctx['products'][0];
        foreach (['required' => 'total_quantity', 'prepared' => 'prepared_qty', 'remaining' => 'remaining_qty'] as $key) {
            $this->assertArrayHasKey($key, $row);
        }

        // Remaining is DERIVED, never stored.
        $this->assertSame(
            max(0.0, (float) $row['total_quantity'] - (float) $row['prepared_qty']),
            (float) $row['remaining_qty'],
        );

        // Prepared has not been recorded, so it is 0 — and Loaded is a separate
        // concept that does not exist yet. They are never interchangeable.
        $this->assertSame(0.0, (float) $row['prepared_qty']);
    }

    // ── Idempotency ───────────────────────────────────────────────────────────

    public function test_opening_loading_twice_reuses_the_same_session_and_assignment(): void
    {
        $group = $this->readyGroup('DG-L4', 2, capacity: 10);

        $first = $this->openLoading($group);
        $second = $this->openLoading($group);

        $this->assertSame($first['loading']['session_id'], $second['loading']['session_id']);
        $this->assertSame($first['loading']['assignment_id'], $second['loading']['assignment_id']);

        $this->assertSame(1, (int) DB::table('loading_sessions')->count());
        $this->assertSame(1, (int) DB::table('vehicle_assignments')->count());
    }

    public function test_the_schema_forbids_two_loading_tasks_for_the_same_product_on_one_vehicle(): void
    {
        // The unique index is what makes the absolute-set write in
        // LoadProductAction safe rather than merely well-behaved.
        $indexes = collect(Schema::getIndexes('loading_tasks'));

        $unique = $indexes->first(fn (array $i): bool => $i['name'] === 'loading_tasks_assignment_product_unique');

        $this->assertNotNull($unique, 'A unique index must prevent duplicate loading tasks.');
        $this->assertTrue($unique['unique']);
        $this->assertEqualsCanonicalizing(['vehicle_assignment_id', 'product_id'], $unique['columns']);
    }

    // ── Tenant / warehouse isolation ──────────────────────────────────────────

    public function test_another_companys_operator_cannot_open_loading_for_this_group(): void
    {
        $group = $this->readyGroup('DG-L5', 2, capacity: 10);
        $trip = DB::table('distribution_trips')->where('virtual_slot_id', $group['id'])->first();

        // The window id is resolved BEFORE switching actor.
        //
        // `windowId()` itself calls actingAs(companyA), and actingAs mutates the
        // shared authenticated user. Interpolating it inside the argument below
        // would therefore re-authenticate as company A after the companyB
        // actingAs had run, and the request would be sent as company A — the
        // test would pass a cross-tenant request off as a rejection.
        $windowId = $this->windowId();

        $this->actingAs($this->userFor($this->companyB))
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$group['id']}/trips/{$trip->uuid}/loading")
            ->assertStatus(404);

        $this->assertSame(0, (int) DB::table('loading_sessions')->count());
    }

    public function test_a_trip_belonging_to_another_group_is_rejected(): void
    {
        $groupA = $this->readyGroup('DG-L6', 2, capacity: 10);
        $foreignTrip = DB::table('distribution_trips')->where('virtual_slot_id', $groupA['id'])->first();

        $groupB = $this->group($this->warehouseA, 'DG-L7');

        // groupB does not own that trip, so it cannot open Loading with it.
        $this->actingAs($this->userFor($this->companyA))
            ->postJson(self::BASE."/windows/{$this->windowId()}/slots/{$groupB['id']}/trips/{$foreignTrip->uuid}/loading")
            ->assertStatus(404);
    }

    // ── Boundaries: inventory, ledger, FIFO, COGS, order status, dispatch ─────

    public function test_opening_loading_mutates_no_inventory_ledger_fifo_or_order_status(): void
    {
        $group = $this->readyGroup('DG-L8', 2, capacity: 10);

        $before = $this->boundaryCounts();
        $orderStatusesBefore = $this->orderStatuses();

        $this->openLoading($group);

        $this->assertSame($before, $this->boundaryCounts(), 'Loading must not touch inventory, the ledger, FIFO layers or reservations.');
        $this->assertSame($orderStatusesBefore, $this->orderStatuses(), 'Loading must not change any order status.');

        $this->assertSame(
            0,
            (int) DB::table('orders')->where('status', 'out_for_delivery')->count(),
            'out_for_delivery belongs to Dispatch, never to Loading.',
        );
    }

    // ── Capacity remains ORDER COUNT only ─────────────────────────────────────

    public function test_a_group_exceeding_the_vehicle_order_count_is_still_rejected(): void
    {
        // Capacity enforcement is unchanged by this task: still order count,
        // still server-side, and still refusing before any pairing is written.
        //
        // ORDERS BEFORE THE FIRST COLLECT — the fixture order this class already uses
        // everywhere else, via readyGroup().
        //
        // Since the fail-closed window contract, `GET /windows/current` resolves an
        // existing window and never creates one, and the collector opens a window
        // INSIDE its candidate loop — so a sweep with nothing to collect creates
        // nothing. Asking for a Group before any order exists therefore yields an empty
        // window id and a 404. Nothing asserted below changed.
        for ($i = 0; $i < 3; $i++) {
            $this->line($this->order($this->warehouseA, 'Maadi'), $this->honey->id, 1);
        }
        $this->collect();

        $group = $this->group($this->warehouseA, 'DG-L9');
        $this->addZone($group['id'], $this->zoneMaadi);

        $small = $this->vehicleFor($this->companyA, capacity: 2);
        $driver = $this->driverFor($this->companyA);

        $this->actingAs($this->userFor($this->companyA))
            ->postJson(self::BASE."/windows/{$this->windowId()}/slots/{$group['id']}/assign-vehicle", [
                'vehicle_id' => $small->uuid,
                'driver_id' => $driver->uuid,
            ])
            ->assertStatus(422);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    // ── TASK-1-C — TRIP READINESS + LOADING INTEGRITY ────────────────────────

    /**
     * §14.4 — a Group whose Trips do not carry all of its accepted Orders cannot load.
     *
     * This is the INVERSE of the membership guard: that one catches a Trip Order that
     * left the Group, this one catches an accepted Group Order that never reached a Trip.
     * Neither is visible from the other side, so Loading checks both.
     */
    public function test_loading_is_refused_when_a_trip_does_not_carry_every_accepted_order(): void
    {
        [$group, $trip] = $this->finalizedGroup('DG-C1', 3);

        // One accepted order silently absent from the manifest.
        $dropped = DB::table('distribution_trip_orders')->where('trip_id', $trip->id)->first();
        DB::table('distribution_trip_orders')->where('id', $dropped->id)->delete();

        $before = $this->boundaryCounts();

        $this->actingAs($this->userFor($this->companyA))
            ->postJson(self::BASE."/windows/{$this->windowId()}/slots/{$group['id']}/trips/{$trip->uuid}/loading")
            ->assertStatus(422);

        self::assertSame($before, $this->boundaryCounts(), 'nothing was created or repaired');
    }

    /**
     * §14.5 — the same Order cannot be manifested twice, and the DATABASE is what stops it.
     *
     * This replaced an application-level test. The original tried to create a duplicate
     * manifest row and could not: `distribution_trip_orders_order_unique` is a unique
     * index on `order_id` alone, so an Order belongs to at most one Trip anywhere. The
     * scenario §5 asks about is structurally impossible, so the honest test is that the
     * constraint refuses it — not that a guard catches a state that cannot exist.
     */
    public function test_the_manifest_forbids_the_same_order_twice(): void
    {
        [, $trip] = $this->finalizedGroup('DG-C2', 2);

        $row = (array) DB::table('distribution_trip_orders')->where('trip_id', $trip->id)->first();
        unset($row['id']);

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('distribution_trip_orders')->insert($row);
    }

    /** §14.12/13 — a refused open creates no session and repairs nothing. */
    public function test_a_refused_open_creates_no_loading_session_and_repairs_nothing(): void
    {
        [$group, $trip] = $this->finalizedGroup('DG-C3', 2);

        // Break membership the way the live ORD-00007 case is broken: the Order stays in
        // the Trip while leaving the Group.
        $manifest = DB::table('distribution_trip_orders')->where('trip_id', $trip->id)->first();
        DB::table('distribution_window_orders')
            ->where('order_id', $manifest->order_id)
            ->update(['virtual_slot_id' => null]);

        $sessionsBefore = DB::table('loading_sessions')->count();
        $manifestBefore = DB::table('distribution_trip_orders')->where('trip_id', $trip->id)->count();

        $this->actingAs($this->userFor($this->companyA))
            ->postJson(self::BASE."/windows/{$this->windowId()}/slots/{$group['id']}/trips/{$trip->uuid}/loading")
            ->assertStatus(422);

        self::assertSame($sessionsBefore, DB::table('loading_sessions')->count(), 'no session');
        self::assertSame(
            $manifestBefore,
            DB::table('distribution_trip_orders')->where('trip_id', $trip->id)->count(),
            'the offending order was NOT removed for us',
        );
    }

    // ── The readiness READ ───────────────────────────────────────────────────

    /** §14.1/11 — a valid Trip reports ready, and Loading opens. */
    public function test_a_valid_trip_reports_ready_and_opens(): void
    {
        $group = $this->readyGroup('DG-C4', 2, capacity: 10);

        $readiness = $this->readiness($group['id']);

        self::assertTrue($readiness['ready'], 'ready');
        self::assertNull($readiness['reason']);
        foreach ($readiness['checks'] as $check) {
            self::assertTrue($check['ok'], $check['key'].' should pass');
        }

        $this->openLoading($group);
    }

    /**
     * §14.2 — no vehicle/driver: readiness names it and Loading refuses.
     *
     * The readiness read and the write path must agree, which is why the panel runs the
     * very guards `open()` runs rather than describing them a second time.
     */
    public function test_a_group_without_a_vehicle_reports_blocked_and_refuses_to_open(): void
    {
        // A finalized Group with NO vehicle assignment.
        for ($i = 0; $i < 2; $i++) {
            $this->line($this->order($this->warehouseA, 'Maadi'), $this->honey->id, 1);
        }
        $this->collect();

        $group = $this->group($this->warehouseA, 'DG-C5');
        $this->addZone($group['id'], $this->zoneMaadi);

        $this->actingAs($this->userFor($this->companyA))
            ->postJson(self::BASE."/windows/{$this->windowId()}/slots/{$group['id']}/finalize")
            ->assertSuccessful();

        $readiness = $this->readiness($group['id']);

        self::assertFalse($readiness['ready']);
        self::assertNotNull($readiness['reason']);

        $byKey = [];
        foreach ($readiness['checks'] as $check) {
            $byKey[$check['key']] = $check['ok'];
        }

        self::assertFalse($byKey['vehicle_assigned'] ?? true);
        self::assertFalse($byKey['driver_assigned'] ?? true);
        self::assertTrue($byKey['manifest_membership'] ?? false, 'membership itself is fine');
    }

    /** §14.3/10 — the ORD-00007 shape: readiness flags membership, Loading refuses. */
    public function test_a_trip_order_outside_the_group_reports_blocked(): void
    {
        [$group, $trip] = $this->finalizedGroup('DG-C6', 2);

        $manifest = DB::table('distribution_trip_orders')->where('trip_id', $trip->id)->first();
        DB::table('distribution_window_orders')
            ->where('order_id', $manifest->order_id)
            ->update(['virtual_slot_id' => null]);

        $readiness = $this->readiness($group['id']);

        $byKey = [];
        foreach ($readiness['checks'] as $check) {
            $byKey[$check['key']] = $check['ok'];
        }

        self::assertFalse($readiness['ready']);
        self::assertFalse($byKey['manifest_membership'] ?? true);
        self::assertNotNull($readiness['reason']);
    }

    /** The readiness read exposes no class names or column names to the operator. */
    public function test_readiness_keys_expose_no_internals(): void
    {
        $group = $this->readyGroup('DG-C7', 2, capacity: 10);

        foreach ($this->readiness($group['id'])['checks'] as $check) {
            self::assertMatchesRegularExpression(
                '/^[a-z][a-z_]*$/',
                $check['key'],
                'keys are stable i18n identifiers, not internals',
            );
            self::assertStringNotContainsString('\\', $check['key']);
        }
    }

    /** §14.6/7/15 — another company can neither read readiness nor open Loading. */
    public function test_readiness_and_loading_are_company_scoped(): void
    {
        $group = $this->readyGroup('DG-C8', 2, capacity: 10);
        $trip = DB::table('distribution_trips')->where('virtual_slot_id', $group['id'])->first();

        // RESOLVED FIRST, DELIBERATELY. `windowId()` authenticates as companyA to read the
        // window; interpolating it inside the URL would re-authenticate AFTER
        // actingAs($foreign) and the request would run as companyA — the tenancy
        // assertion would then be testing nothing.
        $windowId = $this->windowId();

        $foreign = $this->userFor(Company::factory()->create());

        $this->actingAs($foreign)
            ->getJson(self::BASE."/windows/{$windowId}/slots/{$group['id']}/trips")
            ->assertStatus(404);

        $this->actingAs($foreign)
            ->postJson(self::BASE."/windows/{$windowId}/slots/{$group['id']}/trips/{$trip->uuid}/loading")
            ->assertStatus(404);
    }

    /** The readiness payload for a Group's trips, from the existing trips endpoint. */
    private function readiness(string $groupId): array
    {
        $rows = $this->actingAs($this->userFor($this->companyA))
            ->getJson(self::BASE."/windows/{$this->windowId()}/slots/{$groupId}/trips")
            ->assertOk()
            ->json('readiness');

        self::assertNotEmpty($rows, 'the group has at least one trip to report on');

        return $rows[0];
    }

    /**
     * A Group built in the APPROVED order: Finalize, then Vehicle + Driver.
     *
     * ┌─ WHY THIS CANNOT WRAP readyGroup() ──────────────────────────────────────┐
     * │ `readyGroup()` assigns the vehicle first, and that creates a Trip. Finalize │
     * │ is idempotent — "already finalized -> return what exists" — so a later      │
     * │ finalize() sees that Trip and returns it WITHOUT building a manifest.      │
     * │ Every manifest assertion then operates on zero rows.                       │
     * │                                                                          │
     * │ The task's own flow is Group -> Capacity Decision -> Finalize -> Trip ->   │
     * │ Vehicle + Driver -> Loading. Built in that order, Finalize manifests.      │
     * └──────────────────────────────────────────────────────────────────────────┘
     *
     * @return array{0: array<string, mixed>, 1: object} [group, trip]
     */
    private function finalizedGroup(string $code, int $orders, int $capacity = 10): array
    {
        for ($i = 0; $i < $orders; $i++) {
            $this->line($this->order($this->warehouseA, 'Maadi'), $this->honey->id, 2);
        }

        $this->collect();
        $group = $this->group($this->warehouseA, $code);
        $this->addZone($group['id'], $this->zoneMaadi);

        // FINALIZE FIRST — this is what builds the manifest.
        $this->actingAs($this->userFor($this->companyA))
            ->postJson(self::BASE."/windows/{$this->windowId()}/slots/{$group['id']}/finalize")
            ->assertSuccessful();

        // Then the vehicle and driver Loading requires.
        $vehicle = $this->vehicleFor($this->companyA, $capacity);
        $driver = $this->driverFor($this->companyA);

        $this->actingAs($this->userFor($this->companyA))
            ->postJson(self::BASE."/windows/{$this->windowId()}/slots/{$group['id']}/assign-vehicle", [
                'vehicle_id' => $vehicle->uuid,
                'driver_id' => $driver->uuid,
            ])
            ->assertOk();

        $trip = DB::table('distribution_trips')
            ->where('virtual_slot_id', $group['id'])
            ->whereNotNull('finalized_at')
            ->first();

        self::assertNotNull($trip, 'fixture: a finalized trip exists');
        self::assertGreaterThan(
            0,
            DB::table('distribution_trip_orders')->where('trip_id', $trip->id)->count(),
            'fixture: finalize built the manifest',
        );

        return [$group, $trip];
    }

    private function openLoading(array $group): array
    {
        // Addressed by UUID — the public Trip identifier the API publishes.
        // The internal bigint is deliberately never exposed.
        $trip = DB::table('distribution_trips')->where('virtual_slot_id', $group['id'])->first();

        return $this->actingAs($this->userFor($this->companyA))
            ->postJson(self::BASE."/windows/{$this->windowId()}/slots/{$group['id']}/trips/{$trip->uuid}/loading")
            ->assertOk()
            ->json('data');
    }

    /** A Group with orders, a vehicle and a driver already assigned. */
    private function readyGroup(string $code, int $orders, int $capacity): array
    {
        for ($i = 0; $i < $orders; $i++) {
            $this->line($this->order($this->warehouseA, 'Maadi'), $this->honey->id, 2);
        }

        $this->collect();
        $group = $this->group($this->warehouseA, $code);
        $this->addZone($group['id'], $this->zoneMaadi);

        $vehicle = $this->vehicleFor($this->companyA, $capacity);
        $driver = $this->driverFor($this->companyA);

        $this->actingAs($this->userFor($this->companyA))
            ->postJson(self::BASE."/windows/{$this->windowId()}/slots/{$group['id']}/assign-vehicle", [
                'vehicle_id' => $vehicle->uuid,
                'driver_id' => $driver->uuid,
            ])
            ->assertOk();

        return $group;
    }

    /** @return array<string,int> */
    private function boundaryCounts(): array
    {
        return [
            'inventory_items' => (int) DB::table('inventory_items')->count(),
            'stock_ledger_entries' => (int) DB::table('stock_ledger_entries')->count(),
            'inventory_receipt_layers' => (int) DB::table('inventory_receipt_layers')->count(),
        ];
    }

    /** @return array<string,string> */
    private function orderStatuses(): array
    {
        return DB::table('orders')->orderBy('id')->pluck('status', 'id')->all();
    }

    private function isNullable(string $table, string $column): bool
    {
        $row = DB::selectOne(
            'SELECT IS_NULLABLE AS n FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column],
        );

        return $row !== null && $row->n === 'YES';
    }

    private function vehicleFor(Company $company, int $capacity): Vehicle
    {
        $vehicle = Vehicle::withoutEvents(fn () => Vehicle::create([
            'plate_number' => 'V-'.mt_rand(1000, 9999),
            'capacity_orders' => $capacity,
            'status' => 'available',
        ]));

        $vehicle->forceFill(['uuid' => (string) Str::uuid(), 'company_id' => $company->id])->saveQuietly();

        return $vehicle->refresh();
    }

    private function driverFor(Company $company): Driver
    {
        $driver = Driver::withoutEvents(fn () => Driver::create([
            'driver_code' => 'DRV-'.mt_rand(10000, 99999),
            'full_name' => 'Driver '.substr($company->id, 0, 4),
            'mobile' => '01'.mt_rand(100000000, 999999999),
            'national_id' => (string) mt_rand(10000000000000, 99999999999999),
            'status' => Driver::STATUS_ACTIVE,
        ]));

        $driver->forceFill(['uuid' => (string) Str::uuid(), 'company_id' => $company->id])->saveQuietly();

        return $driver->refresh();
    }

    private function userFor(Company $company): User
    {
        return User::factory()->create(['company_id' => $company->id]);
    }

    private function collect(): void
    {
        $this->actingAs($this->userFor($this->companyA))
            ->postJson(self::BASE.'/windows/collect')->assertOk();
    }

    private function windowId(): string
    {
        return (string) $this->actingAs($this->userFor($this->companyA))
            ->getJson(self::BASE.'/windows/current')->assertOk()->json('data.window.id');
    }

    private function group(Warehouse $warehouse, string $code): array
    {
        return $this->actingAs($this->userFor($this->companyA))
            ->postJson(self::BASE."/windows/{$this->windowId()}/slots", [
                'warehouse_id' => $warehouse->id,
                'code' => $code,
            ])->assertStatus(201)->json('data');
    }

    private function addZone(string $groupId, int $zoneId): void
    {
        $this->actingAs($this->userFor($this->companyA))
            ->postJson(self::BASE."/windows/{$this->windowId()}/slots/{$groupId}/zones", ['zone_id' => $zoneId])
            ->assertOk();
    }

    private function zone(string $name): int
    {
        return (int) DB::table('distribution_zones')->insertGetId([
            'code' => strtoupper(substr($name, 0, 6)).mt_rand(10, 99),
            'name_en' => $name, 'name_ar' => $name,
            'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function city(int $governorate, string $en, string $ar, int $zoneId): void
    {
        DB::table('logistics_cities')->insert([
            'governorate_id' => $governorate,
            'name_en' => $en, 'name_ar' => $ar,
            'distribution_zone_id' => $zoneId,
            'is_active' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function order(Warehouse $warehouse, string $city): Order
    {
        return Order::query()->create([
            'company_id' => $this->companyA->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-GL-'.uniqid(),
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

    private function line(Order $order, string $productId, float $qty): void
    {
        OrderLine::query()->create([
            'order_id' => $order->id,
            'product_id' => $productId,
            'quantity' => $qty,
            'unit_price' => 10,
            'line_total' => $qty * 10,
        ]);
    }
}
