<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\Preparation\Application\Services\WaveEngine\WaveMembershipService;
use Modules\Operations\Preparation\Domain\Events\DemandRefreshRequested;
use Modules\Operations\Preparation\Domain\Events\OrderRemovedFromWave;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;
use Modules\Operations\Preparation\Domain\Models\PreparationWaveOrder;
use Modules\Operations\Preparation\Domain\Models\WaveEngineConfiguration;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-PREPARATION-WAVE-ORDERS-WORKSPACE-REFINEMENT-002 — postponement.
 *
 * Postponing is NOT `detachOrder()`. That deletes the membership row, which makes
 * `attachEligibleOrders()`'s `whereNotExists` true again — and `wave:run-scheduler` runs
 * every minute, so the order would be re-attached to the same wave within 60 seconds.
 * Retaining the row with `postponed_at` set keeps the collector excluding it while
 * preserving the membership as history.
 *
 * The order itself is untouched: no `orders` write, no status transition, no deletion.
 */
final class WavePostponeOrderTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        // preparation_waves.company_id carries an FK to companies, so the wave needs a
        // real company row — a bare User factory's company_id does not satisfy it.
        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();
    }

    /** A system actor belonging to the fixture company. */
    private function actor(): User
    {
        return $this->grantSystemRole(User::factory()->create(['company_id' => $this->company->id]));
    }

    private function wave(string $companyId, string $warehouseId): PreparationWave
    {
        return PreparationWave::create([
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
            'wave_number' => 'PREP-TEST-'.substr((string) fake()->uuid(), 0, 8),
            'planning_date' => now()->toDateString(),
            'status' => 'collecting',
            'wave_type' => 'engine',
            'created_by' => 'test',
            'updated_by' => 'test',
        ]);
    }

    private function member(PreparationWave $wave, Order $order): PreparationWaveOrder
    {
        // attachOrder() increments this in production, and a CHECK constraint
        // (chk_preparation_waves_orders_count) forbids it going negative — so the fixture
        // must mirror the real counter rather than leaving it at 0.
        $wave->increment('orders_count');

        return PreparationWaveOrder::create([
            'company_id' => $wave->company_id,
            'preparation_wave_id' => $wave->id,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            // NOT NULL without a default in the live schema — set explicitly rather than
            // relying on a database default that does not exist.
            'order_confirmed_at' => now(),
            'added_at' => now(),
            'added_by' => 'test',
        ]);
    }

    /** Mirrors the established order fixture used by PreparationEntryGateTest. */
    private function orderFor(string $companyId, ?string $warehouseId = null): Order
    {
        return Order::query()->create([
            'company_id' => $companyId,
            'assigned_warehouse_id' => $warehouseId,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-'.uniqid(),
            'order_date' => now()->toDateString(),
            'status' => OrderStatus::InProgress->value,
            'subtotal' => 100,
            'total' => 100,
            'shipping_total' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
        ]);
    }

    // ── F. postponed_at is persisted, not deleted ─────────────────────────────

    public function test_postpone_persists_postponed_at_and_keeps_the_membership_row(): void
    {
        $user = $this->actor();
        $this->actingAsUnprivileged($user);

        $wave = $this->wave((string) $user->company_id, (string) fake()->uuid());
        $order = $this->orderFor((string) $user->company_id);
        $this->member($wave, $order);

        $done = app(WaveMembershipService::class)->postponeOrder($wave, $order->id, 'test');

        self::assertTrue($done);

        $row = PreparationWaveOrder::where('preparation_wave_id', $wave->id)
            ->where('order_id', $order->id)
            ->first();

        self::assertNotNull($row, 'The membership row must be RETAINED, never deleted.');
        self::assertNotNull($row->postponed_at, 'postponed_at must be stamped.');
        self::assertTrue($row->isPostponed());
    }

    // ── M. idempotency ────────────────────────────────────────────────────────

    public function test_postponing_twice_is_idempotent(): void
    {
        $user = $this->actor();
        $this->actingAsUnprivileged($user);

        $wave = $this->wave((string) $user->company_id, (string) fake()->uuid());
        $order = $this->orderFor((string) $user->company_id);
        $this->member($wave, $order);

        $service = app(WaveMembershipService::class);

        self::assertTrue($service->postponeOrder($wave, $order->id, 'test'), 'First call performs it.');
        $stampedAt = PreparationWaveOrder::where('order_id', $order->id)->first()->postponed_at;

        self::assertFalse($service->postponeOrder($wave, $order->id, 'test'), 'Second call is a no-op.');

        self::assertEquals(
            $stampedAt,
            PreparationWaveOrder::where('order_id', $order->id)->first()->postponed_at,
            'A repeat call must not re-stamp the timestamp.',
        );
        self::assertSame(
            1,
            PreparationWaveOrder::where('order_id', $order->id)->count(),
            'No duplicate postponement record may be created.',
        );
    }

    // ── G + H. the Order is untouched ─────────────────────────────────────────

    public function test_postpone_does_not_delete_or_mutate_the_order(): void
    {
        $user = $this->actor();
        $this->actingAsUnprivileged($user);

        $wave = $this->wave((string) $user->company_id, (string) fake()->uuid());
        $order = $this->orderFor((string) $user->company_id);
        $this->member($wave, $order);

        $before = $order->fresh()->getAttributes();

        app(WaveMembershipService::class)->postponeOrder($wave, $order->id, 'test');

        $after = $order->fresh();

        self::assertNotNull($after, 'The order row must still exist.');
        self::assertSame(
            OrderStatus::InProgress->value,
            $after->status instanceof OrderStatus ? $after->status->value : $after->status,
            'Postponement must NOT transition OrderStatus.',
        );
        self::assertSame($before, $after->getAttributes(), 'No order column may change.');
    }

    // ── L. the scheduler must not re-attach a postponed order ─────────────────

    public function test_collector_does_not_reattach_a_postponed_order(): void
    {
        $user = $this->actor();
        $this->actingAsUnprivileged($user);

        $wave = $this->wave((string) $user->company_id, (string) fake()->uuid());
        $order = $this->orderFor((string) $user->company_id);
        $this->member($wave, $order);

        app(WaveMembershipService::class)->postponeOrder($wave, $order->id, 'test');

        // The collector's exclusion predicate, verbatim from
        // WaveMembershipService::attachEligibleOrders(): an order is a candidate only when
        // NO membership row exists. Retaining the postponed row keeps this false forever.
        $isCandidate = Order::where('id', $order->id)
            ->whereNotExists(fn ($q) => $q
                ->select(DB::raw(1))
                ->from('preparation_wave_orders')
                ->whereColumn('preparation_wave_orders.order_id', 'orders.id'))
            ->exists();

        self::assertFalse(
            $isCandidate,
            'A postponed order must not become a collection candidate again — that is the whole point of retaining the row.',
        );
    }

    // ── I + J. it leaves the active list and the demand aggregation ───────────

    public function test_postponed_order_leaves_the_active_membership_set(): void
    {
        $user = $this->actor();
        $this->actingAsUnprivileged($user);

        $wave = $this->wave((string) $user->company_id, (string) fake()->uuid());
        $stays = $this->orderFor((string) $user->company_id);
        $goes = $this->orderFor((string) $user->company_id);
        $this->member($wave, $stays);
        $this->member($wave, $goes);

        self::assertSame(2, PreparationWaveOrder::where('preparation_wave_id', $wave->id)->active()->count());

        app(WaveMembershipService::class)->postponeOrder($wave, $goes->id, 'test');

        $active = PreparationWaveOrder::where('preparation_wave_id', $wave->id)->active()->get();

        self::assertSame([$stays->id], $active->pluck('order_id')->all());
        self::assertSame(
            2,
            PreparationWaveOrder::where('preparation_wave_id', $wave->id)->count(),
            'Both rows remain on the table — postponement is not a delete.',
        );
    }

    public function test_postponed_order_is_excluded_from_product_demand_aggregation(): void
    {
        $user = $this->actor();
        $this->actingAsUnprivileged($user);

        $wave = $this->wave((string) $user->company_id, (string) fake()->uuid());
        $order = $this->orderFor((string) $user->company_id);
        $this->member($wave, $order);

        // The demand aggregation predicate used by ProductDemandCalculator,
        // MissingMaterialCalculator and GenerateDemandAction.
        $countedBefore = DB::table('preparation_wave_orders')
            ->where('preparation_wave_id', $wave->id)
            ->whereNull('postponed_at')
            ->count();

        app(WaveMembershipService::class)->postponeOrder($wave, $order->id, 'test');

        $countedAfter = DB::table('preparation_wave_orders')
            ->where('preparation_wave_id', $wave->id)
            ->whereNull('postponed_at')
            ->count();

        self::assertSame(1, $countedBefore);
        self::assertSame(0, $countedAfter, 'A postponed order contributes no demand to the current cycle.');
    }

    // ── the wave counter follows, exactly once ────────────────────────────────

    public function test_orders_count_decrements_once_only(): void
    {
        $user = $this->actor();
        $this->actingAsUnprivileged($user);

        $wave = $this->wave((string) $user->company_id, (string) fake()->uuid());
        $order = $this->orderFor((string) $user->company_id);
        $this->member($wave, $order);

        $service = app(WaveMembershipService::class);
        $service->postponeOrder($wave, $order->id, 'test');
        $service->postponeOrder($wave, $order->id, 'test');

        self::assertSame(0, (int) $wave->fresh()->orders_count, 'The counter must not double-decrement.');
    }

    // ── postponing an order that is not a member changes nothing ──────────────

    public function test_postponing_a_non_member_is_a_no_op(): void
    {
        $user = $this->actor();
        $this->actingAsUnprivileged($user);

        $wave = $this->wave((string) $user->company_id, (string) fake()->uuid());
        $stranger = $this->orderFor((string) $user->company_id);

        self::assertFalse(app(WaveMembershipService::class)->postponeOrder($wave, $stranger->id, 'test'));
    }

    // ── Events: fired, and fired exactly once ─────────────────────────────────

    public function test_postpone_fires_the_removal_and_demand_refresh_events_exactly_once(): void
    {
        $user = $this->actor();
        $this->actingAsUnprivileged($user);

        $wave = $this->wave((string) $user->company_id, (string) fake()->uuid());
        $order = $this->orderFor((string) $user->company_id);
        $this->member($wave, $order);

        Event::fake([OrderRemovedFromWave::class, DemandRefreshRequested::class]);

        $service = app(WaveMembershipService::class);
        $service->postponeOrder($wave, $order->id, 'test');
        // Second call must be inert — this is what proves idempotency at the EVENT level,
        // not merely at the column level.
        $service->postponeOrder($wave, $order->id, 'test');

        Event::assertDispatchedTimes(OrderRemovedFromWave::class, 1);
        Event::assertDispatchedTimes(DemandRefreshRequested::class, 1);

        Event::assertDispatched(
            OrderRemovedFromWave::class,
            fn (OrderRemovedFromWave $e) => $e->orderId === $order->id
                && $e->waveId === $wave->id
                && $e->reason === 'postponed',
        );
    }

    // ── Reservation and Inventory are untouched ───────────────────────────────

    public function test_postpone_changes_no_reservation_or_inventory_state(): void
    {
        $user = $this->actor();
        $this->actingAsUnprivileged($user);

        $wave = $this->wave((string) $user->company_id, (string) fake()->uuid());
        $order = $this->orderFor((string) $user->company_id);
        $this->member($wave, $order);

        $reservationBefore = [
            'reservation_status' => $order->fresh()->reservation_status,
            'inventory_reserved_at' => $order->fresh()->inventory_reserved_at,
        ];
        $inventoryBefore = DB::table('inventory_items')
            ->orderBy('id')
            ->get(['id', 'on_hand_qty', 'reserved_qty'])
            ->toArray();
        $orderLinesBefore = DB::table('order_lines')
            ->where('order_id', $order->id)
            ->orderBy('id')
            ->get(['id', 'quantity', 'reserved_qty'])
            ->toArray();

        app(WaveMembershipService::class)->postponeOrder($wave, $order->id, 'test');

        self::assertSame($reservationBefore, [
            'reservation_status' => $order->fresh()->reservation_status,
            'inventory_reserved_at' => $order->fresh()->inventory_reserved_at,
        ], 'Postponement must not touch reservation state.');

        self::assertEquals(
            $inventoryBefore,
            DB::table('inventory_items')->orderBy('id')->get(['id', 'on_hand_qty', 'reserved_qty'])->toArray(),
            'Postponement must not touch inventory quantities.',
        );

        self::assertEquals(
            $orderLinesBefore,
            DB::table('order_lines')->where('order_id', $order->id)->orderBy('id')->get(['id', 'quantity', 'reserved_qty'])->toArray(),
            'Postponement must not touch order lines.',
        );
    }

    // ── The real collector, not just its predicate ────────────────────────────

    public function test_the_real_collector_does_not_reattach_a_postponed_order(): void
    {
        $user = $this->actor();
        $this->actingAsUnprivileged($user);

        $companyId = (string) $user->company_id;
        // orders.assigned_warehouse_id carries an FK, so the collector test needs a real
        // warehouse — the collector matches orders on exactly this column.
        $warehouseId = (string) Warehouse::factory()->create(['company_id' => $companyId])->id;

        $wave = $this->wave($companyId, $warehouseId);
        $order = $this->orderFor($companyId, $warehouseId);
        $this->member($wave, $order);

        $config = WaveEngineConfiguration::create([
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
            'collection_start_time' => '00:00:00',
            'preparation_start_time' => '23:59:00',
            'wave_end_time' => '23:59:59',
            'auto_create' => true,
            'auto_assign_orders' => true,
            'auto_move_to_preparing' => false,
            'eligible_order_statuses' => ['in_progress', 'confirmed'], // ADR-042 §7 fulfilment-eligible (was pre-V3 ['new','in_progress'])
            'timezone' => 'UTC',
            'is_active' => true,
            'created_by' => 1,
            'updated_by' => 1,
        ]);

        $service = app(WaveMembershipService::class);
        $service->postponeOrder($wave, $order->id, 'test');

        // This is the scheduler's actual collection step (RunWaveSchedulerCommand step 2).
        $attached = $service->attachEligibleOrders($wave, $config, 'scheduler');

        self::assertSame(0, $attached, 'The scheduler must not re-attach a postponed order.');
        self::assertSame(
            1,
            PreparationWaveOrder::where('order_id', $order->id)->count(),
            'No second membership row may be created.',
        );
        self::assertNotNull(
            PreparationWaveOrder::where('order_id', $order->id)->first()->postponed_at,
            'The row must remain postponed after a collector run.',
        );
    }

    // ── Non-postponed orders keep working normally ────────────────────────────

    public function test_orders_that_were_not_postponed_remain_active(): void
    {
        $user = $this->actor();
        $this->actingAsUnprivileged($user);

        $wave = $this->wave((string) $user->company_id, (string) fake()->uuid());
        $a = $this->orderFor((string) $user->company_id);
        $b = $this->orderFor((string) $user->company_id);
        $c = $this->orderFor((string) $user->company_id);
        foreach ([$a, $b, $c] as $o) {
            $this->member($wave, $o);
        }

        app(WaveMembershipService::class)->postponeOrder($wave, $b->id, 'test');

        $active = PreparationWaveOrder::where('preparation_wave_id', $wave->id)
            ->active()
            ->pluck('order_id')
            ->all();

        sort($active);
        $expected = [$a->id, $c->id];
        sort($expected);

        self::assertSame($expected, $active, 'Only the postponed order leaves; the others are untouched.');
    }

    // ── Loading / shipping allocation excludes it ─────────────────────────────

    public function test_postponed_order_is_excluded_from_loading_allocation(): void
    {
        $user = $this->actor();
        $this->actingAsUnprivileged($user);

        $companyId = (string) $user->company_id;
        $wave = $this->wave($companyId, (string) fake()->uuid());
        $keeps = $this->orderFor($companyId);
        $goes = $this->orderFor($companyId);
        $this->member($wave, $keeps);
        $this->member($wave, $goes);

        app(WaveMembershipService::class)->postponeOrder($wave, $goes->id, 'test');

        // The exact query AutoAllocationService runs to build its allocation candidates.
        $candidates = PreparationWaveOrder::whereIn('preparation_wave_id', [$wave->id])
            ->where('company_id', $companyId)
            ->active()
            ->pluck('order_id')
            ->all();

        self::assertSame([$keeps->id], $candidates, 'A postponed order must not reach loading/shipping allocation.');
    }

    // ── Missing Materials aggregation excludes it ─────────────────────────────

    public function test_postponed_order_is_excluded_from_missing_materials_join(): void
    {
        $user = $this->actor();
        $this->actingAsUnprivileged($user);

        $wave = $this->wave((string) $user->company_id, (string) fake()->uuid());
        $order = $this->orderFor((string) $user->company_id);
        $this->member($wave, $order);

        // MissingMaterialCalculator joins preparation_wave_orders with the same predicate.
        $joined = fn () => DB::table('preparation_wave_orders as pwo')
            ->where('pwo.preparation_wave_id', $wave->id)
            ->whereNull('pwo.postponed_at')
            ->count();

        self::assertSame(1, $joined());

        app(WaveMembershipService::class)->postponeOrder($wave, $order->id, 'test');

        self::assertSame(0, $joined(), 'A postponed order contributes nothing to Missing Materials.');
    }
}
