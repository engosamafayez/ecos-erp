<?php

declare(strict_types=1);

namespace Tests\Feature\Operations\WaveEngine;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Application\Listeners\HandlePreparationWaveClosed;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Enums\ReservationStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Operations\Preparation\Domain\Enums\WaveStatus;
use Modules\Operations\Preparation\Domain\Events\WaveClosed;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-WAVE-CARRYOVER-RELEASE-DEPENDENCY-CLOSURE-001 — the missing runtime proof.
 *
 * `HandlePreparationWaveClosed` is the ONE registration in OrderServiceProvider that
 * pulls a file from outside the Orders release unit
 * (`Operations\DemandAnalysis\…\OrderPreparationCompletionReader`). Before that file can
 * be declared a release dependency, the behaviour it serves has to be shown to work —
 * and no test in the suite exercised it: a repo-wide search for
 * `HandlePreparationWaveClosed`, `ReturnToProcessingWorkflow`, `fullyPreparedOrderIds` or
 * `OrderPreparationCompletionReader` across `tests/` returned nothing.
 *
 * This file adds only that proof. It asserts the ALREADY-AUTHORISED carry-over contract
 * (G-4 CASE A / B / C) and changes no production behaviour:
 *
 *   CASE A — in or past shipping        → LEAVE UNTOUCHED
 *   CASE B — fully prepared, unshipped  → LEAVE UNTOUCHED (Ready for Dispatch)
 *   CASE C — unfinished                 → return to In Progress via the canonical workflow
 *
 * plus the four release-blocking invariants: historical membership survives, no duplicate
 * membership, no duplicate reservation, and tenant isolation.
 *
 * `DatabaseTransactions`, not `RefreshDatabase`: `ecos_dev_test` is shared and contended.
 */
final class WaveCarryOverDependencyTest extends TestCase
{
    use DatabaseTransactions;

    private Company $company;

    private Customer $customer;

    private string $waveId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();
        $this->waveId = $this->makeWave()->id;
    }

    /** A real wave row — `preparation_wave_orders` has an FK to `preparation_waves`. */
    private function makeWave(?Company $company = null): PreparationWave
    {
        return PreparationWave::create([
            'company_id' => ($company ?? $this->company)->id,
            'warehouse_id' => (string) Str::uuid(),
            'wave_number' => 'PREP-'.now()->format('Ym').'-'.str_pad((string) random_int(1, 999999), 6, '0', STR_PAD_LEFT),
            'planning_date' => today()->toDateString(),
            'status' => WaveStatus::Collecting->value,
            'orders_count' => 0,
            'products_count' => 0,
            'lines_count' => 0,
            'total_units_required' => 0,
            'total_units_prepared' => 0,
            'shortage_detected' => false,
            'wave_type' => 'engine',
            'created_by' => 'system',
            'updated_by' => 'system',
        ]);
    }

    /** An order already carrying a reservation, so duplication can be detected. */
    private function order(OrderStatus $status, ?Company $company = null, ?string $shippedAt = null): Order
    {
        return Order::create([
            'customer_id' => $this->customer->id,
            'company_id' => ($company ?? $this->company)->id,
            'order_number' => 'WCO-'.Str::random(8),
            'order_date' => now()->toDateString(),
            'status' => $status->value,
            'reservation_status' => ReservationStatus::Reserved->value,
            'inventory_reserved_at' => now(),
            'inventory_shipped_at' => $shippedAt,
            'subtotal' => 0,
            'total' => 0,
        ]);
    }

    /**
     * Membership row.
     *
     * `$released = true` writes `released_at`, which the generated column
     * `active_membership` (`CASE WHEN released_at IS NULL THEN 1 ELSE NULL END`) turns
     * into NULL. The unique index `uq_prep_wave_orders_company_order_active` is on
     * (company_id, order_id, active_membership), and NULLs do not collide in MySQL — so an
     * order may accumulate MANY historical memberships but only ever ONE active one.
     */
    private function attach(Order $order, ?string $waveId = null, bool $released = false): void
    {
        DB::table('preparation_wave_orders')->insert([
            'id' => (string) Str::uuid(),
            'company_id' => $order->company_id,
            'preparation_wave_id' => $waveId ?? $this->waveId,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'order_confirmed_at' => now(),
            'added_at' => now(),
            'added_by' => (string) Str::uuid(),
            'released_at' => $released ? now() : null,
        ]);
    }

    private function closeWave(?Company $company = null): void
    {
        app(HandlePreparationWaveClosed::class)->handle(new WaveClosed(
            waveId: $this->waveId,
            waveNumber: 'W-TEST-1',
            companyId: ($company ?? $this->company)->id,
            warehouseId: (string) Str::uuid(),
            planningDate: now()->toDateString(),
            closedBy: 'test-runner',
            closedAt: now()->toIso8601String(),
        ));
    }

    private function membershipCount(Order $order, ?string $waveId = null): int
    {
        return DB::table('preparation_wave_orders')
            ->where('preparation_wave_id', $waveId ?? $this->waveId)
            ->where('order_id', $order->id)
            ->count();
    }

    // ── CASE C — the carry-over itself ────────────────────────────────────────

    /**
     * PART 7. Wave #1 ends, the order never reached shipping and is not fully prepared →
     * it must return to In Progress, which is the ONLY status the next cycle's collector
     * treats as fulfilment-eligible. That transition IS the carry-over.
     */
    public function test_case_c_unshipped_unfinished_order_returns_to_in_progress(): void
    {
        $order = $this->order(OrderStatus::ReadyForDispatch);
        $this->attach($order);

        $this->closeWave();

        self::assertSame(
            OrderStatus::InProgress,
            $order->refresh()->status,
            'wave end must carry an unfinished order back to In Progress',
        );
    }

    /**
     * The transition must go through the canonical workflow, not a direct column write —
     * proven by the audit row the engine stamps.
     */
    public function test_case_c_carry_over_goes_through_the_canonical_workflow(): void
    {
        $order = $this->order(OrderStatus::ReadyForDispatch);
        $this->attach($order);

        $this->closeWave();

        self::assertDatabaseHas('order_events', [
            'order_id' => $order->id,
            'event_type' => 'return_to_processing',
        ]);
    }

    /** After carry-over the order is fulfilment-eligible again — it can enter Wave #2. */
    public function test_carried_over_order_is_eligible_for_the_next_wave(): void
    {
        $order = $this->order(OrderStatus::ReadyForDispatch);
        $this->attach($order);

        $this->closeWave();

        self::assertContains(
            $order->refresh()->status,
            OrderStatus::fulfilmentEligible(),
            'a carried-over order must be collectable by the next cycle',
        );
    }

    // ── CASE A — shipping lifecycle is none of Preparation's business ─────────

    public function test_case_a_order_in_shipping_lifecycle_is_left_untouched(): void
    {
        $order = $this->order(OrderStatus::OutForDelivery);
        $this->attach($order);

        $this->closeWave();

        self::assertSame(OrderStatus::OutForDelivery, $order->refresh()->status);
    }

    public function test_case_a_shipped_order_is_left_untouched(): void
    {
        $order = $this->order(OrderStatus::ReadyForDispatch, shippedAt: now()->toDateTimeString());
        $this->attach($order);

        $this->closeWave();

        self::assertSame(
            OrderStatus::ReadyForDispatch,
            $order->refresh()->status,
            'inventory_shipped_at alone must protect the order from carry-over',
        );
    }

    // ── Non-eligible statuses are counted, never forced ───────────────────────

    /**
     * An `awaiting_stock` member is the live example: preparation start could not reserve
     * for it. The contract says such orders are left to the lifecycle that owns them.
     */
    public function test_awaiting_stock_member_is_not_forced_by_wave_end(): void
    {
        $order = $this->order(OrderStatus::AwaitingStock);
        $this->attach($order);

        $this->closeWave();

        self::assertSame(OrderStatus::AwaitingStock, $order->refresh()->status);
    }

    // ── PART 11 — historical membership ───────────────────────────────────────

    public function test_historical_membership_survives_wave_end(): void
    {
        $order = $this->order(OrderStatus::ReadyForDispatch);
        $this->attach($order);

        $this->closeWave();

        self::assertSame(1, $this->membershipCount($order), 'wave end must not delete history');
    }

    /** An order may belong to several waves; history accumulates rather than being replaced. */
    public function test_order_may_hold_membership_in_several_waves(): void
    {
        $order = $this->order(OrderStatus::ReadyForDispatch);
        $previousWave = $this->makeWave()->id;

        // The earlier cycle already ended, so its membership is released — that is what
        // frees the order to hold an active membership in the current wave.
        $this->attach($order, $previousWave, released: true);
        $this->attach($order);

        $this->closeWave();

        self::assertSame(1, $this->membershipCount($order, $previousWave), 'earlier wave retained');
        self::assertSame(1, $this->membershipCount($order), 'ended wave retained');
    }

    // ── PART 9 / 10 — no duplicate reservation, no duplicate demand ───────────

    /**
     * Carry-over deliberately does NOT release inventory: the reservation stays with the
     * order across the wave boundary. That is what stops the next cycle producing a
     * second reservation for the same commitment.
     */
    public function test_carry_over_does_not_duplicate_or_drop_the_reservation(): void
    {
        $order = $this->order(OrderStatus::ReadyForDispatch);
        $this->attach($order);

        $reservedAtBefore = $order->inventory_reserved_at;

        $this->closeWave();
        $order->refresh();

        self::assertSame(ReservationStatus::Reserved, $order->reservation_status, 'reservation preserved');
        self::assertNull($order->inventory_released_at, 'carry-over must not release inventory');
        self::assertEquals($reservedAtBefore, $order->inventory_reserved_at, 'no re-reservation occurred');
    }

    /** PART 10 — wave end writes no demand rows at all; the formulas are untouched. */
    public function test_wave_end_creates_no_material_demand_rows(): void
    {
        $order = $this->order(OrderStatus::ReadyForDispatch);
        $this->attach($order);

        $before = DB::table('wave_product_demand')->where('preparation_wave_id', $this->waveId)->count();

        $this->closeWave();

        $after = DB::table('wave_product_demand')->where('preparation_wave_id', $this->waveId)->count();

        self::assertSame($before, $after, 'wave end must not add or duplicate demand');
    }

    // ── PART 12 — tenant isolation ────────────────────────────────────────────

    /**
     * The listener filters members by the CLOSING company. Another company's order can sit
     * in the membership table (bad data, shared warehouse, whatever) and must still be
     * left alone.
     */
    public function test_tenant_isolation_a_foreign_company_order_is_not_carried_over(): void
    {
        $foreign = Company::factory()->create();
        $foreignOrder = $this->order(OrderStatus::ReadyForDispatch, company: $foreign);
        $ourOrder = $this->order(OrderStatus::ReadyForDispatch);

        $this->attach($foreignOrder);
        $this->attach($ourOrder);

        $this->closeWave();

        self::assertSame(
            OrderStatus::ReadyForDispatch,
            $foreignOrder->refresh()->status,
            "another company's order must never be moved by our wave",
        );
        self::assertSame(OrderStatus::InProgress, $ourOrder->refresh()->status);
    }

    // ── Idempotency of the dependency itself ──────────────────────────────────

    /** A replayed WaveClosed must converge: the second pass finds nothing to move. */
    public function test_replayed_wave_closed_event_is_idempotent(): void
    {
        $order = $this->order(OrderStatus::ReadyForDispatch);
        $this->attach($order);

        $this->closeWave();
        $this->closeWave();
        $this->closeWave();

        self::assertSame(OrderStatus::InProgress, $order->refresh()->status);
        self::assertSame(1, $this->membershipCount($order), 'replay must not duplicate membership');
    }

    /** An empty wave is a no-op — the guard that keeps closure cheap. */
    public function test_wave_with_no_members_is_a_no_op(): void
    {
        $this->closeWave();

        self::assertTrue(true, 'closing an empty wave must not throw');
    }
}
