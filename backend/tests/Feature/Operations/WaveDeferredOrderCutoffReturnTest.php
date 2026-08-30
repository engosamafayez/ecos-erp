<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Operations\Preparation\Application\Services\WaveEngine\WaveLifecycleService;
use Modules\Operations\Preparation\Application\Services\WaveEngine\WaveMembershipService;
use Modules\Operations\Preparation\Domain\Enums\WaveStatus;
use Modules\Operations\Preparation\Domain\Models\PreparationWave;
use Modules\Operations\Preparation\Domain\Models\PreparationWaveOrder;
use Modules\Operations\Preparation\Domain\Models\WaveEngineConfiguration;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Sales\Customers\Domain\Models\Customer;
use Tests\TestCase;

/**
 * TASK-OPERATIONS-PREPARATION-DEFERRED-ORDER-CUTOFF-RETURN-001.
 *
 * THE DEFECT. Three call sites required `WaveStatus::Collecting` before a POSTPONED order
 * could be returned to preparation. The scheduler moves a wave `Collecting → Preparing` the
 * moment `intake_closes_at` passes, so an order that had joined the wave BEFORE cutoff and was
 * then parked for a shortage became unreturnable for the rest of the day — even with the wave
 * still open and preparation still running. Work the operator had deliberately deferred until
 * stock arrived was stranded until the next cycle.
 *
 * THE DISTINCTION THESE TESTS PIN. Two different rules were being served by one predicate:
 *
 *   intake_closes_at (CUTOFF) -> Collecting becomes Preparing. STOPS NEW ADMISSIONS ONLY.
 *   closeWave()      (CLOSE)  -> terminal status + `released_at`. ENDS THE WAVE.
 *
 * Membership is the retained `preparation_wave_orders` row; screen presence is `postponed_at`;
 * membership ends only at `released_at`. Postponing never touched membership, which is exactly
 * why returning must not be judged by an admission rule.
 */
final class WaveDeferredOrderCutoffReturnTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::factory()->create();
        $this->customer = Customer::factory()->create();
    }

    // ─────────────────────────────────────────────────────────────────────────────
    // Fixtures — mirroring WavePostponeOrderTest so both suites describe one system
    // ─────────────────────────────────────────────────────────────────────────────

    private function actor(?string $companyId = null): User
    {
        return $this->grantSystemRole(
            User::factory()->create(['company_id' => $companyId ?? $this->company->id]),
        );
    }

    private function warehouseFor(string $companyId): string
    {
        return (string) Warehouse::factory()->create(['company_id' => $companyId])->id;
    }

    /** A wave that is still collecting — i.e. BEFORE its intake cutoff. */
    private function wave(string $companyId, string $warehouseId, WaveStatus $status = WaveStatus::Collecting): PreparationWave
    {
        return PreparationWave::create([
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
            'wave_number' => 'PREP-TEST-'.substr((string) fake()->uuid(), 0, 8),
            'planning_date' => now()->toDateString(),
            'intake_closes_at' => now()->addHour(),
            'ends_at' => now()->addHours(6),
            'status' => $status->value,
            'wave_type' => 'engine',
            'created_by' => 'test',
            'updated_by' => 'test',
        ]);
    }

    private function member(PreparationWave $wave, Order $order): PreparationWaveOrder
    {
        $wave->increment('orders_count');

        return PreparationWaveOrder::create([
            'company_id' => $wave->company_id,
            'preparation_wave_id' => $wave->id,
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'order_confirmed_at' => now(),
            'added_at' => now(),
            'added_by' => 'test',
        ]);
    }

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

    private function config(string $companyId, string $warehouseId): WaveEngineConfiguration
    {
        return WaveEngineConfiguration::create([
            'company_id' => $companyId,
            'warehouse_id' => $warehouseId,
            'collection_start_time' => '00:00:00',
            'preparation_start_time' => '23:59:00',
            'wave_end_time' => '23:59:59',
            'auto_create' => true,
            'auto_assign_orders' => true,
            'auto_move_to_preparing' => false,
            'eligible_order_statuses' => ['new', 'in_progress'],
            'timezone' => 'UTC',
            'is_active' => true,
            'created_by' => 1,
            'updated_by' => 1,
        ]);
    }

    /** The cutoff, expressed exactly as the scheduler expresses it. */
    private function passCutoff(PreparationWave $wave): PreparationWave
    {
        $wave->update([
            'status' => WaveStatus::Preparing->value,
            'intake_closes_at' => now()->subMinute(),
        ]);

        return $wave->refresh();
    }

    private function membership(PreparationWave $wave, Order $order): ?PreparationWaveOrder
    {
        return PreparationWaveOrder::where('preparation_wave_id', $wave->id)
            ->where('order_id', $order->id)
            ->first();
    }

    private function service(): WaveMembershipService
    {
        return app(WaveMembershipService::class);
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // 1–6 · The repaired path: join before cutoff → defer → cutoff → return
    // ═════════════════════════════════════════════════════════════════════════════

    /** 1 + 2 + 4 — joining, deferring, and membership surviving the deferral. */
    public function test_1_a_deferred_order_remains_a_member_of_the_same_wave(): void
    {
        $wh = $this->warehouseFor((string) $this->company->id);
        $wave = $this->wave((string) $this->company->id, $wh);
        $order = $this->orderFor((string) $this->company->id, $wh);
        $this->member($wave, $order);

        self::assertTrue($this->service()->postponeOrder($wave, $order->id, 'test'));

        $row = $this->membership($wave, $order);
        self::assertNotNull($row, 'Deferring must NOT delete the membership row.');
        self::assertNotNull($row->postponed_at, 'Deferred = postponed_at set (screen presence).');
        self::assertNull($row->released_at, 'Membership ends only at release — not at deferral.');
        self::assertSame((string) $wave->id, (string) $row->preparation_wave_id);
    }

    /** 3 + 5 + 8 — THE REPAIR. Cutoff passes; the existing member can still return. */
    public function test_2_a_deferred_member_can_return_after_cutoff_while_the_wave_is_open(): void
    {
        $wh = $this->warehouseFor((string) $this->company->id);
        $wave = $this->wave((string) $this->company->id, $wh);
        $order = $this->orderFor((string) $this->company->id, $wh);
        $this->member($wave, $order);
        $this->service()->postponeOrder($wave, $order->id, 'test');

        $wave = $this->passCutoff($wave);
        self::assertSame(WaveStatus::Preparing, $wave->status, 'Premise: the wave is past cutoff.');
        self::assertFalse($wave->status->isTerminal(), 'Premise: the wave has NOT closed.');

        self::assertTrue(
            $this->service()->returnPostponedOrder($wave, $order->id, 'test'),
            'Cutoff stops NEW admissions; it must not lock a member that joined before it.',
        );

        self::assertNull(
            $this->membership($wave, $order)->postponed_at,
            'Returning clears postponed_at — the order is back on the preparation screen.',
        );
    }

    /** 6 — the return is an UPDATE, never an INSERT. */
    public function test_3_returning_creates_no_duplicate_membership(): void
    {
        $wh = $this->warehouseFor((string) $this->company->id);
        $wave = $this->wave((string) $this->company->id, $wh);
        $order = $this->orderFor((string) $this->company->id, $wh);
        $original = $this->member($wave, $order);
        $this->service()->postponeOrder($wave, $order->id, 'test');
        $wave = $this->passCutoff($wave);

        $this->service()->returnPostponedOrder($wave, $order->id, 'test');

        $rows = PreparationWaveOrder::where('order_id', $order->id)->get();
        self::assertCount(1, $rows, 'Exactly one membership row may exist.');
        self::assertSame((string) $original->id, (string) $rows->first()->id, 'The SAME row — no delete+insert.');
        self::assertSame((string) $wave->id, (string) $rows->first()->preparation_wave_id, 'Still the same wave.');
        self::assertSame(1, (int) $rows->first()->active_membership, 'Still exactly one ACTIVE membership.');
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // 7 · New admissions after cutoff stay refused — eligibility is NOT widened
    // ═════════════════════════════════════════════════════════════════════════════

    public function test_4_a_new_order_still_cannot_join_the_wave_after_cutoff(): void
    {
        $wh = $this->warehouseFor((string) $this->company->id);
        $wave = $this->passCutoff($this->wave((string) $this->company->id, $wh));
        $newcomer = $this->orderFor((string) $this->company->id, $wh);

        self::assertNull(
            $this->service()->attachOrder($wave, $newcomer, 'test'),
            'An order that never joined before cutoff must not be admitted now.',
        );
        self::assertSame(0, PreparationWaveOrder::where('order_id', $newcomer->id)->count());
    }

    public function test_5_the_collector_still_admits_nobody_after_cutoff(): void
    {
        $wh = $this->warehouseFor((string) $this->company->id);
        $wave = $this->passCutoff($this->wave((string) $this->company->id, $wh));
        $this->orderFor((string) $this->company->id, $wh);

        self::assertSame(
            0,
            $this->service()->attachEligibleOrders($wave, $this->config((string) $this->company->id, $wh), 'scheduler'),
            'attachEligibleOrders keeps its own Collecting-only guard.',
        );
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // 9 + 10 · Wave CLOSE is a different rule, and carry-over owns what follows
    // ═════════════════════════════════════════════════════════════════════════════

    public function test_6_return_is_refused_once_the_wave_has_closed(): void
    {
        $wh = $this->warehouseFor((string) $this->company->id);
        $wave = $this->wave((string) $this->company->id, $wh);
        $order = $this->orderFor((string) $this->company->id, $wh);
        $this->member($wave, $order);
        $this->service()->postponeOrder($wave, $order->id, 'test');

        $wave = app(WaveLifecycleService::class)->closeWave($wave, 'test', 'test-close');
        self::assertTrue($wave->status->isTerminal(), 'Premise: the wave has ended.');

        self::assertFalse(
            $this->service()->returnPostponedOrder($wave, $order->id, 'test'),
            'A closed wave is over for everyone — carry-over owns the order now.',
        );
    }

    /** 10 — the EXISTING carry-over, not a new mechanism. */
    public function test_7_a_closed_wave_releases_membership_so_the_next_wave_collects_the_order(): void
    {
        $companyId = (string) $this->company->id;
        $wh = $this->warehouseFor($companyId);
        $wave = $this->wave($companyId, $wh);
        $order = $this->orderFor($companyId, $wh);
        $this->member($wave, $order);
        $this->service()->postponeOrder($wave, $order->id, 'test');

        app(WaveLifecycleService::class)->closeWave($wave, 'test', 'rotation');

        self::assertNotNull(
            $this->membership($wave, $order)->released_at,
            'closeWave stamps released_at — that IS the carry-over handoff.',
        );

        // The next cycle's collector picks it up with no special handling.
        $next = $this->wave($companyId, $wh);
        $attached = $this->service()->attachEligibleOrders($next, $this->config($companyId, $wh), 'scheduler');

        self::assertSame(1, $attached, 'The released order is collected by the next wave.');
        self::assertNotNull($this->membership($next, $order), 'It is now a member of the NEW wave.');
        self::assertSame(
            2,
            PreparationWaveOrder::where('order_id', $order->id)->count(),
            'One historical row + one new row — history is retained, never rewritten.',
        );
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // 11–13 · Scope and idempotency
    // ═════════════════════════════════════════════════════════════════════════════

    /** 11 — tenant scope, through the real HTTP surface. */
    public function test_8_a_foreign_tenant_cannot_return_another_companys_order(): void
    {
        $wh = $this->warehouseFor((string) $this->company->id);
        $wave = $this->wave((string) $this->company->id, $wh);
        $order = $this->orderFor((string) $this->company->id, $wh);
        $this->member($wave, $order);
        $this->service()->postponeOrder($wave, $order->id, 'test');
        $this->passCutoff($wave);

        $intruder = $this->actor((string) Company::factory()->create()->id);

        $this->actingAs($intruder)
            ->postJson("/api/preparation/waves/{$wave->id}/orders/{$order->id}/return-to-preparation")
            ->assertNotFound();

        self::assertNotNull(
            $this->membership($wave, $order)->postponed_at,
            'The order must still be deferred — a cross-tenant call changes nothing.',
        );
    }

    /** 12 — an order can only be returned through the wave that actually holds it. */
    public function test_9_an_order_cannot_be_returned_through_another_warehouses_wave(): void
    {
        $companyId = (string) $this->company->id;
        $whA = $this->warehouseFor($companyId);
        $whB = $this->warehouseFor($companyId);

        $waveA = $this->wave($companyId, $whA);
        $waveB = $this->wave($companyId, $whB);
        $order = $this->orderFor($companyId, $whA);
        $this->member($waveA, $order);
        $this->service()->postponeOrder($waveA, $order->id, 'test');
        $this->passCutoff($waveB);

        self::assertFalse(
            $this->service()->returnPostponedOrder($waveB, $order->id, 'test'),
            'Wave B does not hold this order, so it cannot return it.',
        );
        self::assertNotNull($this->membership($waveA, $order)->postponed_at, 'Wave A is unaffected.');
        self::assertNull($this->membership($waveB, $order), 'No membership was invented in wave B.');
    }

    /** 13 — returning an already-active order is a no-op, not a second transition. */
    public function test_10_an_already_active_order_cannot_be_returned_twice(): void
    {
        $wh = $this->warehouseFor((string) $this->company->id);
        $wave = $this->wave((string) $this->company->id, $wh);
        $order = $this->orderFor((string) $this->company->id, $wh);
        $this->member($wave, $order);
        $this->service()->postponeOrder($wave, $order->id, 'test');
        $wave = $this->passCutoff($wave);

        self::assertTrue($this->service()->returnPostponedOrder($wave, $order->id, 'test'));
        $countAfterFirst = (int) $wave->refresh()->orders_count;

        self::assertFalse(
            $this->service()->returnPostponedOrder($wave, $order->id, 'test'),
            'A non-deferred order matches nothing and reports false.',
        );
        self::assertSame(
            $countAfterFirst,
            (int) $wave->refresh()->orders_count,
            'orders_count must not be incremented twice.',
        );
        self::assertCount(1, PreparationWaveOrder::where('order_id', $order->id)->get());
    }

    // ═════════════════════════════════════════════════════════════════════════════
    // The screen and the write path must agree
    // ═════════════════════════════════════════════════════════════════════════════

    /**
     * The deferred list drives the Return button. Before the repair it forced
     * `can_return = false` after cutoff while the write path would equally have refused —
     * consistent, but consistently wrong. Both now key on "has the wave ended".
     */
    public function test_11_the_deferred_list_still_offers_return_after_cutoff(): void
    {
        $wh = $this->warehouseFor((string) $this->company->id);
        $wave = $this->wave((string) $this->company->id, $wh);
        $order = $this->orderFor((string) $this->company->id, $wh);
        $this->member($wave, $order);
        $this->service()->postponeOrder($wave, $order->id, 'test');
        $this->passCutoff($wave);

        $response = $this->actingAs($this->actor())
            ->getJson("/api/preparation/waves/{$wave->id}/deficit-decisions");

        $response->assertOk();

        $postponed = collect($response->json('data.postponed_orders') ?? [])
            ->firstWhere('order_id', (string) $order->id);

        self::assertNotNull($postponed, 'The deferred order must still be listed after cutoff.');
        self::assertTrue(
            (bool) $postponed['can_return'],
            'The Return button must remain available: cutoff is not a membership lock.',
        );
    }

    /** And the HTTP write path agrees with it. */
    public function test_12_the_return_endpoint_accepts_a_deferred_member_after_cutoff(): void
    {
        $wh = $this->warehouseFor((string) $this->company->id);
        $wave = $this->wave((string) $this->company->id, $wh);
        $order = $this->orderFor((string) $this->company->id, $wh);
        $this->member($wave, $order);
        $this->service()->postponeOrder($wave, $order->id, 'test');
        $this->passCutoff($wave);

        $this->actingAs($this->actor())
            ->postJson("/api/preparation/waves/{$wave->id}/orders/{$order->id}/return-to-preparation")
            ->assertOk();

        self::assertNull($this->membership($wave, $order)->postponed_at);
        self::assertCount(1, PreparationWaveOrder::where('order_id', $order->id)->get());
    }

    /** The order itself is never touched — returning is a membership decision. */
    public function test_13_returning_does_not_mutate_the_order(): void
    {
        $wh = $this->warehouseFor((string) $this->company->id);
        $wave = $this->wave((string) $this->company->id, $wh);
        $order = $this->orderFor((string) $this->company->id, $wh);
        $this->member($wave, $order);
        $this->service()->postponeOrder($wave, $order->id, 'test');
        $wave = $this->passCutoff($wave);

        $before = $order->fresh();
        $this->service()->returnPostponedOrder($wave, $order->id, 'test');
        $after = $order->fresh();

        self::assertSame($before->status->value, $after->status->value, 'No order status transition.');
        self::assertSame(
            (string) $before->assigned_warehouse_id,
            (string) $after->assigned_warehouse_id,
            'No warehouse reassignment.',
        );
        self::assertSame(
            (string) $before->reservation_status?->value,
            (string) $after->reservation_status?->value,
            'No reservation state change.',
        );
    }
}
