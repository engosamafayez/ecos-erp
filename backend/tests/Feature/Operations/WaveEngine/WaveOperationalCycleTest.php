<?php

declare(strict_types=1);

namespace Tests\Feature\Operations\WaveEngine;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Inventory\Products\Domain\Models\Product;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\Preparation\Application\Services\WaveEngine\CompanyTimezoneResolver;
use Modules\Operations\Preparation\Application\Services\WaveEngine\WaveScheduleResolver;
use Modules\Operations\Preparation\Domain\Enums\WaveStatus;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;
use Modules\Operations\Preparation\Domain\Models\PreparationWaveOrder;
use Modules\Operations\Preparation\Domain\Models\WaveEngineConfiguration;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-PREPARATION-WAVE-CROSS-DAY-TRANSITION-002 — the operational cycle.
 *
 * The cycle under test is the contract's own example, and it crosses midnight:
 *
 *   Day 1 18:00  start / intake opens
 *   Day 2 08:00  intake closes  (preparation continues)
 *   Day 2 15:00  end
 *   Day 2 18:00  next cycle starts
 *
 * Every instant is expressed in Africa/Cairo — the company timezone — because that is the
 * authority (G-2). The application timezone stays UTC throughout, so a test that passes
 * here could not pass by accidentally agreeing with the server clock.
 */
final class WaveOperationalCycleTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'Africa/Cairo';

    private const DAY1 = '2026-09-01';

    private const DAY2 = '2026-09-02';

    private Company $company;

    private Warehouse $warehouse;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create(['timezone' => self::TZ]);
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->customer = Customer::factory()->create();

        $this->config();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── fixtures ──────────────────────────────────────────────────────────────

    private function config(array $overrides = []): WaveEngineConfiguration
    {
        return WaveEngineConfiguration::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            // The cross-day window. Under the old CHECK constraint this row could not
            // even be inserted: 08:00 is not greater than 18:00.
            'collection_start_time' => '18:00:00',
            'preparation_start_time' => '08:00:00',
            'wave_end_time' => '15:00:00',
            'auto_create' => true,
            'auto_assign_orders' => true,
            'auto_move_to_preparing' => true,
            'eligible_order_statuses' => array_map(
                static fn (OrderStatus $s): string => $s->value,
                OrderStatus::fulfilmentEligible(),
            ),
            'timezone' => 'UTC', // deliberately WRONG: proves companies.timezone wins
            'is_active' => true,
            'created_by' => (string) Str::uuid(),
            'updated_by' => (string) Str::uuid(),
            ...$overrides,
        ]);
    }

    /** Freeze the clock at a company-local instant. */
    private function at(string $localDateTime): CarbonImmutable
    {
        $instant = CarbonImmutable::parse($localDateTime, self::TZ);
        Carbon::setTestNow($instant);

        return $instant;
    }

    private function tick(): void
    {
        Artisan::call('wave:run-scheduler');
    }

    private function order(
        OrderStatus $status = OrderStatus::InProgress,
        ?string $companyId = null,
        ?string $warehouseId = null,
    ): Order {
        return Order::query()->create([
            'company_id' => $companyId ?? $this->company->id,
            'assigned_warehouse_id' => $warehouseId ?? $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'order_number' => 'ORD-'.uniqid(),
            'order_date' => self::DAY1,
            'status' => $status->value,
            'subtotal' => 100,
            'total' => 100,
            'shipping_total' => 0,
            'discount_total' => 0,
            'tax_total' => 0,
        ]);
    }

    /** `order_lines.product_id` carries an FK, so a real product row is required. */
    private function product(): string
    {
        return (string) Product::factory()->create(['sku' => 'SKU-'.uniqid()])->id;
    }

    private function line(Order $order, string $productId, float $qty = 2.0): void
    {
        DB::table('order_lines')->insert([
            'id' => (string) Str::uuid(),
            'order_id' => $order->id,
            'product_id' => $productId,
            'quantity' => $qty,
            'unit_price' => 50,
            'line_total' => 50 * $qty,
        ]);
    }

    /**
     * The canonical G-1 completion fact, written where the operator writes it.
     *
     * updateOrInsert, not insert: attaching the order already made the demand engine
     * build this row, and (preparation_wave_id, product_id) is unique. The operator
     * likewise declares completion on a row that already exists.
     */
    private function declareProductPrepared(PreparationWave $wave, string $productId, float $required = 2.0): void
    {
        DB::table('wave_product_demand')->updateOrInsert(
            ['preparation_wave_id' => $wave->id, 'product_id' => $productId],
            [
                'id' => (string) Str::uuid(),
                'company_id' => $wave->company_id,
                'warehouse_id' => $wave->warehouse_id,
                'product_name' => 'Test product',
                'required_qty' => $required,
                'prepared_qty' => $required,
                'remaining_qty' => 0,
                'orders_count' => 1,
                'completion_pct' => 100,
                'preparation_completed_at' => now(),
                'last_calculated_at' => now(),
                'updated_at' => now(),
            ],
        );
    }

    private function currentWave(): ?PreparationWave
    {
        return PreparationWave::where('warehouse_id', $this->warehouse->id)
            ->orderByDesc('planning_date')
            ->first();
    }

    // ── TEST 1 / 2 — start boundary ───────────────────────────────────────────

    public function test_no_wave_is_opened_before_the_start_boundary(): void
    {
        $this->at(self::DAY1.' 17:59');
        $this->tick();

        $this->assertSame(0, PreparationWave::count(), 'A wave was opened before the cycle start.');
    }

    public function test_exactly_one_wave_opens_at_the_start_boundary_with_cross_day_bounds(): void
    {
        $this->at(self::DAY1.' 18:00');
        $this->tick();

        $this->assertSame(1, PreparationWave::count());

        $wave = $this->currentWave();
        $this->assertNotNull($wave);
        $this->assertSame(WaveStatus::Collecting, $wave->status);
        $this->assertSame(self::DAY1, $wave->planning_date->toDateString());

        // The boundaries land on the NEXT occurrence of each time, so the cycle crosses
        // midnight rather than collapsing onto the start date.
        $this->assertSame(
            self::DAY1.' 18:00',
            $wave->starts_at->setTimezone(self::TZ)->format('Y-m-d H:i'),
        );
        $this->assertSame(
            self::DAY2.' 08:00',
            $wave->intake_closes_at->setTimezone(self::TZ)->format('Y-m-d H:i'),
        );
        $this->assertSame(
            self::DAY2.' 15:00',
            $wave->ends_at->setTimezone(self::TZ)->format('Y-m-d H:i'),
        );
    }

    // ── TEST 22 / 28 — idempotency ────────────────────────────────────────────

    public function test_repeated_scheduler_runs_in_the_start_window_open_only_one_wave(): void
    {
        $this->at(self::DAY1.' 18:00');
        $this->tick();
        $this->tick();

        $this->at(self::DAY1.' 18:30');
        $this->tick();

        $this->assertSame(1, PreparationWave::count());
    }

    // ── TEST 3 / 4 — collection ───────────────────────────────────────────────

    public function test_eligible_orders_are_collected_into_the_open_wave(): void
    {
        $inProgress = $this->order(OrderStatus::InProgress);
        $confirmed = $this->order(OrderStatus::Confirmed);
        $ineligible = $this->order(OrderStatus::AwaitingStock);

        $this->at(self::DAY1.' 18:00');
        $this->tick();

        $wave = $this->currentWave();
        $members = PreparationWaveOrder::where('preparation_wave_id', $wave->id)
            ->pluck('order_id')->all();

        // Both fulfilment-eligible statuses collect. Read from the enum rather than
        // assumed literals — "Confirmed" is resolved through the canonical lifecycle.
        $this->assertContains($inProgress->id, $members);
        $this->assertContains($confirmed->id, $members);
        $this->assertNotContains($ineligible->id, $members);
    }

    public function test_collection_is_idempotent_within_the_same_wave(): void
    {
        $order = $this->order();

        $this->at(self::DAY1.' 18:00');
        $this->tick();
        $this->tick();
        $this->tick();

        $this->assertSame(
            1,
            PreparationWaveOrder::where('order_id', $order->id)->count(),
            'Repeated collection created duplicate membership.',
        );
    }

    // ── TEST 5 / 6 / 7 / 8 — intake cutoff and demand freeze ──────────────────

    public function test_orders_still_enter_just_before_the_cutoff_and_never_after_it(): void
    {
        $this->at(self::DAY1.' 18:00');
        $this->tick();
        $wave = $this->currentWave();

        // 07:59 — still open, and still the SAME wave that opened the evening before.
        $this->at(self::DAY2.' 07:59');
        $beforeCutoff = $this->order();
        $this->tick();

        $wave->refresh();
        $this->assertSame(WaveStatus::Collecting, $wave->status);
        $this->assertSame(1, PreparationWaveOrder::where('order_id', $beforeCutoff->id)->count());

        $requiredAtCutoff = PreparationWaveOrder::where('preparation_wave_id', $wave->id)->count();

        // 08:00 — intake closes. Preparation continues; the wave does NOT close.
        $this->at(self::DAY2.' 08:00');
        $this->tick();

        $wave->refresh();
        $this->assertSame(WaveStatus::Preparing, $wave->status, 'Intake cutoff did not close intake.');

        // 08:01 — a newly eligible order must wait for the next cycle.
        $this->at(self::DAY2.' 08:01');
        $afterCutoff = $this->order();
        $this->tick();

        $this->assertSame(
            0,
            PreparationWaveOrder::where('order_id', $afterCutoff->id)->count(),
            'An order entered the wave after intake closed.',
        );

        // Demand freeze: membership — and therefore Required — did not grow.
        $this->assertSame(
            $requiredAtCutoff,
            PreparationWaveOrder::where('preparation_wave_id', $wave->id)->count(),
            'Wave membership grew after the intake cutoff.',
        );
    }

    // ── TEST 9 / 10 — preparation continues, then end ─────────────────────────

    public function test_preparation_continues_between_cutoff_and_end_then_the_wave_ends(): void
    {
        $this->at(self::DAY1.' 18:00');
        $this->tick();
        $wave = $this->currentWave();

        $this->at(self::DAY2.' 08:00');
        $this->tick();

        foreach (['10:00', '14:59'] as $time) {
            $this->at(self::DAY2.' '.$time);
            $this->tick();

            $this->assertSame(
                WaveStatus::Preparing,
                $wave->refresh()->status,
                "Wave was not still preparing at {$time}.",
            );
        }

        $this->at(self::DAY2.' 15:00');
        $this->tick();

        $this->assertSame(WaveStatus::Closed, $wave->refresh()->status);
    }

    // ── TEST 11 / 12 / 13 — wave end order handling (G-4) ─────────────────────

    public function test_wave_end_returns_only_unshipped_and_unprepared_orders(): void
    {
        $productA = $this->product();
        $productB = $this->product();

        $unprepared = $this->order();
        $this->line($unprepared, $productA);

        $prepared = $this->order();
        $this->line($prepared, $productB);

        $shipped = $this->order();
        $this->line($shipped, $productB);

        $this->at(self::DAY1.' 18:00');
        $this->tick();
        $wave = $this->currentWave();

        // Preparation start is what puts an order in Ready for Dispatch; set it directly
        // so the assertion is about the wave-end decision and not about inventory.
        Order::whereIn('id', [$unprepared->id, $prepared->id])
            ->update(['status' => OrderStatus::ReadyForDispatch->value]);
        Order::where('id', $shipped->id)
            ->update(['status' => OrderStatus::OutForDelivery->value]);

        // Only productB is declared finished, so only $prepared is fully prepared (G-1).
        $this->declareProductPrepared($wave, $productB);

        $this->at(self::DAY2.' 15:00');
        $this->tick();

        $this->assertSame(WaveStatus::Closed, $wave->refresh()->status);

        // CASE C — carried over.
        $this->assertSame(
            OrderStatus::InProgress,
            $unprepared->refresh()->status,
            'An unfinished order was not returned to In Progress.',
        );

        // CASE B — finished work is not re-prepared.
        $this->assertSame(
            OrderStatus::ReadyForDispatch,
            $prepared->refresh()->status,
            'A fully prepared order was wrongly returned to Preparation.',
        );

        // CASE A — shipping lifecycle untouched.
        $this->assertSame(OrderStatus::OutForDelivery, $shipped->refresh()->status);
    }

    public function test_a_partially_prepared_order_is_not_treated_as_complete(): void
    {
        $productA = $this->product();
        $productB = $this->product();

        $order = $this->order();
        $this->line($order, $productA);
        $this->line($order, $productB);

        $this->at(self::DAY1.' 18:00');
        $this->tick();
        $wave = $this->currentWave();

        Order::where('id', $order->id)->update(['status' => OrderStatus::ReadyForDispatch->value]);

        // One product finished, one not — G-1 requires ALL of them.
        $this->declareProductPrepared($wave, $productA);

        $this->at(self::DAY2.' 15:00');
        $this->tick();

        $this->assertSame(OrderStatus::InProgress, $order->refresh()->status);
    }

    // ── TEST 14 / 15 / 17 / 18 — membership across cycles ─────────────────────

    public function test_carry_over_creates_a_second_membership_and_keeps_the_first(): void
    {
        $order = $this->order();
        $this->line($order, $this->product());

        $this->at(self::DAY1.' 18:00');
        $this->tick();
        $waveOne = $this->currentWave();

        Order::where('id', $order->id)->update(['status' => OrderStatus::ReadyForDispatch->value]);

        // End cycle 1 — nothing was declared prepared, so the order carries over.
        $this->at(self::DAY2.' 15:00');
        $this->tick();

        $this->assertSame(OrderStatus::InProgress, $order->refresh()->status);

        // Start cycle 2.
        $this->at(self::DAY2.' 18:00');
        $this->tick();

        $waveTwo = $this->currentWave();
        $this->assertNotSame($waveOne->id, $waveTwo->id, 'A second operational cycle did not open.');
        $this->assertSame(self::DAY2, $waveTwo->planning_date->toDateString());

        $memberships = PreparationWaveOrder::where('order_id', $order->id)
            ->orderBy('added_at')
            ->get();

        // TEST 17 — historical membership in BOTH waves.
        $this->assertCount(2, $memberships, 'Carry-over did not produce a second membership row.');
        $this->assertSame($waveOne->id, $memberships[0]->preparation_wave_id);
        $this->assertSame($waveTwo->id, $memberships[1]->preparation_wave_id);

        // TEST 14 — the first row survives, marked historical rather than deleted.
        $this->assertNotNull($memberships[0]->released_at, 'Wave #1 membership was not released.');
        $this->assertNull($memberships[1]->released_at);

        // TEST 18 — exactly one ACTIVE membership at any moment.
        $this->assertSame(
            1,
            PreparationWaveOrder::where('order_id', $order->id)->whereNull('released_at')->count(),
        );
    }

    public function test_a_fully_prepared_order_is_not_collected_into_the_next_cycle(): void
    {
        $product = $this->product();
        $order = $this->order();
        $this->line($order, $product);

        $this->at(self::DAY1.' 18:00');
        $this->tick();
        $waveOne = $this->currentWave();

        Order::where('id', $order->id)->update(['status' => OrderStatus::ReadyForDispatch->value]);
        $this->declareProductPrepared($waveOne, $product);

        $this->at(self::DAY2.' 15:00');
        $this->tick();

        $this->at(self::DAY2.' 18:00');
        $this->tick();

        $waveTwo = $this->currentWave();
        $this->assertNotSame($waveOne->id, $waveTwo->id);

        $this->assertSame(
            0,
            PreparationWaveOrder::where('preparation_wave_id', $waveTwo->id)
                ->where('order_id', $order->id)
                ->count(),
            'A fully prepared order was collected for preparation a second time.',
        );
    }

    public function test_an_order_cannot_hold_two_active_memberships(): void
    {
        $order = $this->order();

        $this->at(self::DAY1.' 18:00');
        $this->tick();
        $waveOne = $this->currentWave();

        $second = PreparationWave::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'wave_number' => 'PREP-TEST-SECOND',
            'planning_date' => self::DAY2,
            'status' => WaveStatus::Collecting->value,
            'wave_type' => 'engine',
            'created_by' => 'test',
            'updated_by' => 'test',
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        PreparationWaveOrder::create([
            'company_id' => $this->company->id,
            'preparation_wave_id' => $second->id,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'order_confirmed_at' => now(),
            'added_at' => now(),
            'added_by' => 'test',
        ]);

        $this->assertSame($waveOne->id, $waveOne->id); // guard against an empty-risk test
    }

    // ── TEST 19 / 20 — reservation and demand safety ──────────────────────────

    public function test_carry_over_does_not_disturb_the_order_reservation(): void
    {
        $order = $this->order();
        $this->line($order, $this->product());

        $reservedAt = CarbonImmutable::parse(self::DAY1.' 12:00', self::TZ);
        Order::where('id', $order->id)->update([
            'inventory_reserved_at' => $reservedAt,
            'reservation_status' => 'reserved',
        ]);

        $this->at(self::DAY1.' 18:00');
        $this->tick();

        Order::where('id', $order->id)->update(['status' => OrderStatus::ReadyForDispatch->value]);

        $before = DB::table('orders')->where('id', $order->id)
            ->first(['inventory_reserved_at', 'inventory_released_at', 'reservation_status']);
        $ledgerBefore = DB::table('stock_ledger_entries')->where('reference_id', $order->id)->count();

        $this->at(self::DAY2.' 15:00');
        $this->tick();
        $this->at(self::DAY2.' 18:00');
        $this->tick();

        $after = DB::table('orders')->where('id', $order->id)
            ->first(['inventory_reserved_at', 'inventory_released_at', 'reservation_status']);

        // The reservation crosses the wave boundary untouched: no release on the way out,
        // no second reservation on the way in.
        $this->assertEquals($before, $after, 'Carry-over mutated the order reservation.');
        $this->assertSame(
            $ledgerBefore,
            DB::table('stock_ledger_entries')->where('reference_id', $order->id)->count(),
            'Carry-over produced additional stock ledger entries.',
        );
    }

    public function test_demand_for_a_carried_over_order_is_scoped_to_one_wave_at_a_time(): void
    {
        $order = $this->order();
        $this->line($order, $this->product());

        $this->at(self::DAY1.' 18:00');
        $this->tick();
        $waveOne = $this->currentWave();

        Order::where('id', $order->id)->update(['status' => OrderStatus::ReadyForDispatch->value]);

        $this->at(self::DAY2.' 15:00');
        $this->tick();
        $this->at(self::DAY2.' 18:00');
        $this->tick();

        $waveTwo = $this->currentWave();

        // Each wave sees the order exactly once. `postponed_at IS NULL` is what every
        // demand calculator joins on, so one row per wave is one contribution per wave —
        // never two waves counting the same order at the same time.
        foreach ([$waveOne->id, $waveTwo->id] as $waveId) {
            $this->assertSame(
                1,
                PreparationWaveOrder::where('preparation_wave_id', $waveId)
                    ->where('order_id', $order->id)
                    ->whereNull('postponed_at')
                    ->count(),
            );
        }
    }

    // ── TEST 21 — stale wave closure ──────────────────────────────────────────

    public function test_a_stale_wave_from_a_previous_calendar_day_is_closed(): void
    {
        $stale = PreparationWave::create([
            'company_id' => $this->company->id,
            'warehouse_id' => $this->warehouse->id,
            'wave_number' => 'PREP-TEST-STALE',
            'planning_date' => '2026-08-20',
            'starts_at' => CarbonImmutable::parse('2026-08-20 18:00', self::TZ),
            'intake_closes_at' => CarbonImmutable::parse('2026-08-21 08:00', self::TZ),
            'ends_at' => CarbonImmutable::parse('2026-08-21 15:00', self::TZ),
            'status' => WaveStatus::Preparing->value,
            'wave_type' => 'engine',
            'created_by' => 'test',
            'updated_by' => 'test',
        ]);

        // Twelve days later, and a fresh cycle is due.
        $this->at(self::DAY1.' 18:00');
        $this->tick();

        $this->assertSame(
            WaveStatus::Closed,
            $stale->refresh()->status,
            'A wave whose ends_at had long passed was left operationally active.',
        );

        // Closing the stale one must not stop the current cycle opening in the same tick.
        $current = PreparationWave::where('planning_date', self::DAY1)->first();
        $this->assertNotNull($current);
        $this->assertSame(WaveStatus::Collecting, $current->status);
    }

    // ── TEST 23 — timezone ────────────────────────────────────────────────────

    public function test_boundaries_resolve_through_the_company_timezone_not_the_app_timezone(): void
    {
        $this->assertSame('UTC', config('app.timezone'), 'Fixture assumes a UTC application timezone.');

        $resolver = app(WaveScheduleResolver::class);
        $config = WaveEngineConfiguration::where('company_id', $this->company->id)->firstOrFail();

        // 00:30 Cairo on Day 2 — still 21:30 UTC on Day 1. Resolved against the UTC clock
        // this instant belongs to the previous calendar day; resolved against Cairo it is
        // squarely inside the cycle that opened at 18:00 Cairo on Day 1.
        $now = CarbonImmutable::parse(self::DAY2.' 00:30', self::TZ);
        $cycle = $resolver->resolveCycleAt($config, self::TZ, $now);

        $this->assertNotNull($cycle);
        $this->assertSame(self::DAY1, $cycle->planningDate);
        $this->assertTrue($cycle->isIntakeOpenAt($now));
        $this->assertFalse($cycle->hasEndedAt($now));

        // And the gap between cycles really is a gap.
        $inGap = CarbonImmutable::parse(self::DAY2.' 16:00', self::TZ);
        $this->assertNull(
            $resolver->resolveCycleAt($config, self::TZ, $inGap),
            'The quiet window between cycles resolved to a live cycle.',
        );
    }

    public function test_a_company_without_a_usable_timezone_is_skipped_rather_than_defaulted(): void
    {
        DB::table('companies')->where('id', $this->company->id)->update(['timezone' => null]);
        app(CompanyTimezoneResolver::class); // fresh instance per resolution below

        $this->at(self::DAY1.' 18:00');
        $this->tick();

        $this->assertSame(
            0,
            PreparationWave::count(),
            'The engine fell back to a default timezone instead of failing closed.',
        );
    }

    // ── TEST 24 — tenant isolation ────────────────────────────────────────────

    public function test_a_wave_never_collects_another_companys_orders(): void
    {
        $otherCompany = Company::factory()->create(['timezone' => self::TZ]);
        $otherWarehouse = Warehouse::factory()->create(['company_id' => $otherCompany->id]);

        $mine = $this->order();
        $theirs = $this->order(OrderStatus::InProgress, $otherCompany->id, $otherWarehouse->id);

        $this->at(self::DAY1.' 18:00');
        $this->tick();

        $wave = $this->currentWave();
        $members = PreparationWaveOrder::where('preparation_wave_id', $wave->id)->pluck('order_id')->all();

        $this->assertContains($mine->id, $members);
        $this->assertNotContains($theirs->id, $members);

        // The other company has no engine configuration, so no wave of its own was opened
        // and its order was not swept into this one.
        $this->assertSame(
            0,
            PreparationWave::where('company_id', $otherCompany->id)->count(),
        );
    }
}
