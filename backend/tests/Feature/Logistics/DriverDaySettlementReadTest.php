<?php

declare(strict_types=1);

namespace Tests\Feature\Logistics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Logistics\Distribution\Domain\Enums\DeliveryStopStatus;
use Modules\Logistics\Distribution\Domain\Enums\PaymentType;
use Modules\Logistics\Distribution\Domain\Enums\SettlementStatus;
use Modules\Logistics\Distribution\Domain\Enums\TripStatus;
use Modules\Logistics\Distribution\Domain\Exceptions\DistributionException;
use Modules\Logistics\Distribution\Domain\Models\DeliveryStop;
use Modules\Logistics\Distribution\Domain\Models\PaymentCollection;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\TripOrder;
use Modules\Logistics\Distribution\Domain\Models\TripSettlement;
use Modules\Logistics\Distribution\Domain\Services\DeliveryService;
use Modules\Logistics\Distribution\Domain\Services\TripService;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Logistics\Drivers\Domain\Services\DriverVehicleAssignmentService;
use Modules\Logistics\Vehicles\Domain\Models\Vehicle;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-OPERATIONS-DRIVER-DAY-SETTLEMENT-UI-001 — read-only per-driver/per-day rollup.
 *
 * Seeds one company with one driver_vehicle_assignment and one trip on a date (two
 * stops + payments + a settlement), and asserts the day board and the drill-down. A
 * second company's trip on the same day proves tenant isolation.
 */
class DriverDaySettlementReadTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = '/api/logistics/distribution/driver-settlement';

    private const DAY = '2026-08-24';

    public function test_day_summary_rolls_up_the_driver_and_isolates_other_companies(): void
    {
        $companyA = Company::factory()->create();
        $userA = User::factory()->create(['company_id' => $companyA->id]);
        $seed = $this->seedDriverDay($companyA);

        // A second company's driver-day on the SAME date must never appear.
        $companyB = Company::factory()->create();
        $this->seedDriverDay($companyB);

        $response = $this->actingAs($userA)
            ->getJson(self::BASE.'?date='.self::DAY)
            ->assertOk();

        $response->assertJsonPath('date', self::DAY)
            ->assertJsonCount(1, 'drivers')
            // The 8 operational KPIs over the visible custodies (seedDriverDay: 2 orders — 1 delivered
            // 250.00, 1 failed 120.00; transfers 80.00; no canonical expense/net-cash authority → null).
            ->assertJsonPath('kpis.total_orders', 2)
            ->assertJsonPath('kpis.total_delivered', 1)
            ->assertJsonPath('kpis.total_failed', 1)
            ->assertJsonPath('kpis.delivery_rate', 50)
            ->assertJsonPath('kpis.total_sales', fn ($v): bool => (float) $v === 250.0)
            ->assertJsonPath('kpis.total_transfers_paid', fn ($v): bool => (float) $v === 80.0)
            ->assertJsonPath('kpis.total_expenses', null)
            ->assertJsonPath('kpis.net_cash', null)
            ->assertJsonPath('drivers.0.assignment_id', $seed['assignment_id'])
            ->assertJsonPath('drivers.0.operational_date', self::DAY)
            ->assertJsonPath('drivers.0.orders', 2)
            ->assertJsonPath('drivers.0.delivered', 1)
            ->assertJsonPath('drivers.0.failed', 1)
            ->assertJsonPath('drivers.0.delivery_pct', 50)
            ->assertJsonPath('drivers.0.returns', 0)
            ->assertJsonPath('drivers.0.settlement_status', 'under_review')
            ->assertJsonPath('drivers.0.closing_stage', 'warehouse_counting')
            ->assertJsonPath('drivers.0.damaged_qty', fn ($v): bool => (float) $v === 0.0)
            ->assertJsonPath('drivers.0.shortage_qty', fn ($v): bool => (float) $v === 0.0)
            ->assertJsonPath('drivers.0.reconciliation_status', null)
            ->assertJsonPath('drivers.0.cash_expected', fn ($v): bool => (float) $v === 100.0)
            ->assertJsonPath('drivers.0.transfers', fn ($v): bool => (float) $v === 80.0)
            ->assertJsonPath('drivers.0.difference', fn ($v): bool => (float) $v === 0.0);

        // The other company's driver is absent.
        $this->assertSame(
            [$seed['assignment_id']],
            array_column($response->json('drivers'), 'assignment_id'),
        );
    }

    public function test_driver_day_detail_returns_overview_financial_and_supporting_rows(): void
    {
        $companyA = Company::factory()->create();
        $userA = User::factory()->create(['company_id' => $companyA->id]);
        $seed = $this->seedDriverDay($companyA);

        $response = $this->actingAs($userA)
            ->getJson(self::BASE.'/'.$seed['assignment_id'].'?date='.self::DAY)
            ->assertOk();

        $response->assertJsonPath('date', self::DAY)
            ->assertJsonPath('settlement_status', 'under_review')
            ->assertJsonPath('closing_stage', 'warehouse_counting')
            // Overview
            ->assertJsonPath('overview.orders', 2)
            ->assertJsonPath('overview.delivered', 1)
            ->assertJsonPath('overview.partial', 0)
            ->assertJsonPath('overview.failed', 1)
            ->assertJsonPath('overview.returns', 0)
            ->assertJsonPath('overview.delivery_pct', 50)
            ->assertJsonPath('overview.trips', 1)
            // Financial — approved_transfers is the VERIFIED subset (50), not the
            // 80 of non-rejected transfers that feed the per-trip summary.
            ->assertJsonPath('financial.cash_expected', fn ($v): bool => (float) $v === 100.0)
            ->assertJsonPath('financial.approved_transfers', fn ($v): bool => (float) $v === 50.0)
            ->assertJsonPath('financial.actual_cash', fn ($v): bool => (float) $v === 100.0)
            ->assertJsonPath('financial.difference', fn ($v): bool => (float) $v === 0.0)
            ->assertJsonPath('financial.is_balanced', true)
            // Collections breakdown (§6): cash 100, bank 80 (50+30), card 0, already-paid 0.
            ->assertJsonPath('collections.cash', fn ($v): bool => (float) $v === 100.0)
            ->assertJsonPath('collections.bank_transfer', fn ($v): bool => (float) $v === 80.0)
            ->assertJsonPath('collections.card', fn ($v): bool => (float) $v === 0.0)
            ->assertJsonPath('collections.total_collected', fn ($v): bool => (float) $v === 180.0)
            ->assertJsonPath('collections.delivered_sales', fn ($v): bool => (float) $v === 250.0)
            // Expected Collection is NOT canonical → explicitly unavailable, never invented (§6 HARD RULE).
            ->assertJsonPath('collections.expected_collection', null)
            ->assertJsonPath('collections.expected_collection_available', false)
            // No shift reconciliation opened → custody reports an honest not-available state (§8).
            ->assertJsonPath('custody_summary.reconciliation_available', false)
            ->assertJsonPath('custody_summary.reconciliation_status', null)
            ->assertJsonPath('damage.available', false)
            ->assertJsonPath('shortage_review.available', false)
            ->assertJsonPath('closing_readiness.ready', false)
            // Supporting collections
            ->assertJsonCount(1, 'trips')
            ->assertJsonPath('trips.0.trip_number', $seed['trip_number'])
            ->assertJsonCount(2, 'orders')
            ->assertJsonCount(2, 'transfers');

        // Closing is blocked while a trip settlement is only Submitted (not Reconciled).
        $this->assertContains('settlement_not_reconciled', $response->json('closing_readiness.blockers'));

        // The verified transfer carries its active payment proof and a readable label.
        $verified = collect($response->json('transfers'))->firstWhere('collection_status', PaymentCollection::STATUS_VERIFIED);
        $this->assertNotNull($verified);
        $this->assertSame($seed['proof_id'], $verified['proof']['id']);
        $this->assertSame('verified', $verified['proof']['state']);
        $this->assertSame('Bank Transfer', $verified['payment_label']);

        // product_reconciliation, timeline and goods_remaining are always present as arrays.
        $this->assertIsArray($response->json('product_reconciliation'));
        $this->assertIsArray($response->json('timeline'));
        $this->assertIsArray($response->json('goods_remaining'));
    }

    public function test_detail_is_not_found_for_a_foreign_company(): void
    {
        $companyA = Company::factory()->create();
        $seed = $this->seedDriverDay($companyA);

        $companyB = Company::factory()->create();
        $userB = User::factory()->create(['company_id' => $companyB->id]);

        $this->actingAs($userB)
            ->getJson(self::BASE.'/'.$seed['assignment_id'].'?date='.self::DAY)
            ->assertNotFound();
    }

    public function test_endpoints_require_authentication(): void
    {
        $this->getJson(self::BASE.'?date='.self::DAY)->assertUnauthorized();
        $this->getJson(self::BASE.'/1?date='.self::DAY)->assertUnauthorized();
    }

    public function test_date_is_required_and_validated(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $this->actingAs($user)->getJson(self::BASE)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date']);

        $this->actingAs($user)->getJson(self::BASE.'?date=24-08-2026')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['date']);
    }

    public function test_active_scope_lists_open_custody_without_a_date_filter(): void
    {
        $companyA = Company::factory()->create();
        $userA = User::factory()->create(['company_id' => $companyA->id]);
        $seed = $this->seedDriverDay($companyA); // Submitted settlement, trip 'planning' → open/active

        // Another company's open custody must never appear.
        $companyB = Company::factory()->create();
        $this->seedDriverDay($companyB);

        $this->actingAs($userA)
            ->getJson(self::BASE.'?scope=active')
            ->assertOk()
            ->assertJsonPath('scope', 'active')
            ->assertJsonCount(1, 'drivers')
            ->assertJsonPath('kpis.total_orders', 2)
            ->assertJsonPath('kpis.total_delivered', 1)
            ->assertJsonPath('drivers.0.assignment_id', $seed['assignment_id'])
            ->assertJsonPath('drivers.0.closing_stage', 'warehouse_counting');
    }

    public function test_history_scope_filters_finalized_settlements_and_paginates(): void
    {
        $companyA = Company::factory()->create();
        $userA = User::factory()->create(['company_id' => $companyA->id]);
        $seed = $this->seedClosedDriverDay($companyA);

        // In range: the closed driver-day appears, keyed and paginated server-side.
        $this->actingAs($userA)
            ->getJson(self::BASE.'?scope=history&from='.self::DAY.'&to='.self::DAY)
            ->assertOk()
            ->assertJsonPath('scope', 'history')
            ->assertJsonCount(1, 'drivers')
            ->assertJsonPath('kpis.total_orders', 1)
            ->assertJsonPath('kpis.total_delivered', 1)
            ->assertJsonPath('drivers.0.assignment_id', $seed['assignment_id'])
            ->assertJsonPath('drivers.0.settlement_status', 'settled')
            ->assertJsonPath('drivers.0.closing_stage', 'closed')
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 25);

        // A range excluding the finalized date returns nothing.
        $this->actingAs($userA)
            ->getJson(self::BASE.'?scope=history&from=2026-01-01&to=2026-01-31')
            ->assertOk()
            ->assertJsonCount(0, 'drivers')
            ->assertJsonPath('meta.total', 0);

        // The Active board must NOT include a closed driver-day.
        $this->actingAs($userA)
            ->getJson(self::BASE.'?scope=active')
            ->assertOk()
            ->assertJsonCount(0, 'drivers');
    }

    public function test_history_requires_a_valid_range(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);

        $this->actingAs($user)->getJson(self::BASE.'?scope=history')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['from', 'to']);

        // `to` must not precede `from`.
        $this->actingAs($user)->getJson(self::BASE.'?scope=history&from=2026-08-24&to=2026-08-01')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['to']);
    }

    // ── Expected Collection: immutable handoff snapshot (TASK-...-SINGLE-ACTIVE-TRIP) ─────────────

    public function test_generate_stops_snapshots_the_collectible_amount_at_handoff(): void
    {
        $company = Company::factory()->create();
        $seed = $this->seedHandoffTrip($company, [
            ['total' => 1000.0, 'paid' => false, 'deposit' => 0.0],   // unpaid COD → collectible 1000
            ['total' => 1000.0, 'paid' => true, 'deposit' => 0.0],    // fully prepaid → 0
            ['total' => 1000.0, 'paid' => false, 'deposit' => 300.0], // deposit paid → 700
        ]);

        $stops = DeliveryStop::query()->where('trip_id', $seed['trip']->id)->orderBy('sequence')->get();

        $this->assertCount(3, $stops);
        $this->assertEqualsWithDelta(1000.0, (float) $stops[0]->expected_collection_at_handoff, 0.001);
        $this->assertEqualsWithDelta(0.0, (float) $stops[1]->expected_collection_at_handoff, 0.001);
        $this->assertEqualsWithDelta(700.0, (float) $stops[2]->expected_collection_at_handoff, 0.001);
    }

    public function test_expected_collection_snapshot_is_immutable_after_handoff(): void
    {
        $company = Company::factory()->create();
        $seed = $this->seedHandoffTrip($company, [['total' => 1000.0, 'paid' => false, 'deposit' => 0.0]]);

        // A later change to the order (payment/total) must NOT rewrite the original handoff figure.
        DB::table('orders')->where('id', $seed['order_ids'][0])->update(['total' => 5000.0, 'date_paid' => now()]);

        $stop = DeliveryStop::query()->where('trip_id', $seed['trip']->id)->first();
        $this->assertEqualsWithDelta(1000.0, (float) $stop->expected_collection_at_handoff, 0.001);
    }

    public function test_expected_collection_reads_the_sum_of_the_handoff_snapshots(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $seed = $this->seedHandoffTrip($company, [
            ['total' => 1000.0, 'paid' => false, 'deposit' => 0.0],
            ['total' => 1000.0, 'paid' => true, 'deposit' => 0.0],
            ['total' => 1000.0, 'paid' => false, 'deposit' => 300.0],
        ]);

        $this->actingAs($user)
            ->getJson(self::BASE.'/'.$seed['assignment_id'].'?date='.self::DAY)
            ->assertOk()
            ->assertJsonPath('collections.expected_collection_available', true)
            ->assertJsonPath('collections.expected_collection', fn ($v): bool => (float) $v === 1700.0);
    }

    public function test_expected_collection_is_unavailable_when_a_stop_predates_the_snapshot(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        // seedDriverDay builds its stops DIRECTLY (no generateStops) → no handoff snapshot → NULL.
        $seed = $this->seedDriverDay($company);

        $this->actingAs($user)
            ->getJson(self::BASE.'/'.$seed['assignment_id'].'?date='.self::DAY)
            ->assertOk()
            ->assertJsonPath('collections.expected_collection_available', false)
            ->assertJsonPath('collections.expected_collection', null);
    }

    // ── Single-active operational custody contract (TASK-...-SINGLE-ACTIVE-CUSTODY-CLOSURE-001) ──

    public function test_a_loading_shell_without_custody_is_not_active(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        // A driver assignment + a Loading trip shell, but NO real goods custody handoff.
        $this->seedCustodyTrip($company, TripStatus::Loading);

        $this->actingAs($user)->getJson(self::BASE.'?scope=active')->assertOk()
            ->assertJsonCount(0, 'drivers');
    }

    public function test_a_custody_eligible_trip_is_exactly_one_active_record(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $seed = $this->seedCustodyTrip($company, TripStatus::InProgress);

        $this->actingAs($user)->getJson(self::BASE.'?scope=active')->assertOk()
            ->assertJsonCount(1, 'drivers')
            ->assertJsonPath('drivers.0.trip_id', $seed['trip']->uuid)
            ->assertJsonPath('drivers.0.duplicate_open_custody', false);
    }

    public function test_first_custody_handoff_succeeds_when_no_other_is_open(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $seed = $this->seedCustodyTrip($company, TripStatus::Loading);

        // Loading → LoadingCompleted is the custody-start boundary; no other open custody → allowed.
        app(TripService::class)->changeStatus($seed['trip'], TripStatus::LoadingCompleted, 'test', 'tester');

        $this->assertSame(TripStatus::LoadingCompleted, $seed['trip']->refresh()->status);
        $this->actingAs($user)->getJson(self::BASE.'?scope=active')->assertOk()
            ->assertJsonCount(1, 'drivers');
    }

    public function test_second_simultaneous_custody_handoff_is_rejected_server_side(): void
    {
        $company = Company::factory()->create();
        $assignment = app(DriverVehicleAssignmentService::class)->assign($this->makeDriver(), $this->makeVehicle());

        // Custody A already open on this driver's pairing.
        Trip::create([
            'company_id' => $company->id,
            'trip_number' => 'TRP-A'.substr(md5(uniqid('', true)), 0, 4),
            'name' => 'Custody A',
            'driver_vehicle_assignment_id' => $assignment->id,
            'status' => TripStatus::InProgress->value,
            'trip_started_at' => self::DAY.' 09:00:00',
        ]);

        // Trip B on the SAME driver tries to take custody — must be rejected in the domain layer.
        $tripB = Trip::create([
            'company_id' => $company->id,
            'trip_number' => 'TRP-B'.substr(md5(uniqid('', true)), 0, 4),
            'name' => 'Custody B',
            'driver_vehicle_assignment_id' => $assignment->id,
            'status' => TripStatus::Loading->value,
        ]);

        $this->expectException(DistributionException::class);
        app(TripService::class)->changeStatus($tripB, TripStatus::LoadingCompleted, 'test', 'tester');
    }

    public function test_closing_removes_from_active_and_allows_a_new_custody(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $assignment = app(DriverVehicleAssignmentService::class)->assign($this->makeDriver(), $this->makeVehicle());

        $tripA = Trip::create([
            'company_id' => $company->id,
            'trip_number' => 'TRP-A'.substr(md5(uniqid('', true)), 0, 4),
            'name' => 'Custody A',
            'driver_vehicle_assignment_id' => $assignment->id,
            'status' => TripStatus::InProgress->value,
            'trip_started_at' => self::DAY.' 09:00:00',
        ]);
        // Canonical close (SettlementService sets Closed directly). Custody A is now terminal.
        $tripA->update(['status' => TripStatus::Closed->value]);

        $this->actingAs($user)->getJson(self::BASE.'?scope=active')->assertOk()
            ->assertJsonCount(0, 'drivers');

        // A NEW custody B is now allowed for the same driver.
        $tripB = Trip::create([
            'company_id' => $company->id,
            'trip_number' => 'TRP-B'.substr(md5(uniqid('', true)), 0, 4),
            'name' => 'Custody B',
            'driver_vehicle_assignment_id' => $assignment->id,
            'status' => TripStatus::Loading->value,
        ]);
        app(TripService::class)->changeStatus($tripB, TripStatus::LoadingCompleted, 'test', 'tester');
        $this->assertSame(TripStatus::LoadingCompleted, $tripB->refresh()->status);
    }

    public function test_multiple_legacy_open_custodies_are_surfaced_not_deduped(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        $assignment = app(DriverVehicleAssignmentService::class)->assign($this->makeDriver(), $this->makeVehicle());

        // Two custody-eligible trips for ONE driver — legacy corruption, created directly as
        // pre-invariant data would be (the write-side guard prevents new ones).
        foreach (['A', 'B'] as $tag) {
            Trip::create([
                'company_id' => $company->id,
                'trip_number' => 'TRP-'.$tag.substr(md5(uniqid('', true)), 0, 4),
                'name' => 'Legacy Custody '.$tag,
                'driver_vehicle_assignment_id' => $assignment->id,
                'status' => TripStatus::InProgress->value,
                'trip_started_at' => self::DAY.' 09:00:00',
            ]);
        }

        $this->actingAs($user)->getJson(self::BASE.'?scope=active')->assertOk()
            ->assertJsonCount(2, 'drivers')            // surfaced, NOT deduped
            ->assertJsonPath('drivers.0.duplicate_open_custody', true)
            ->assertJsonPath('drivers.1.duplicate_open_custody', true)
            ->assertJsonPath('drivers.0.closing_stage', 'needs_review')
            ->assertJsonPath('drivers.1.closing_stage', 'needs_review');
    }

    public function test_active_board_exposes_canonical_order_and_financial_values(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create(['company_id' => $company->id]);
        // seedDriverDay: delivered order 250.00, failed order 120.00; transfers 50+30 = 80.00.
        $this->seedDriverDay($company);

        $this->actingAs($user)->getJson(self::BASE.'?scope=active')->assertOk()
            ->assertJsonPath('drivers.0.orders_value', fn ($v): bool => (float) $v === 370.0)
            ->assertJsonPath('drivers.0.delivered_value', fn ($v): bool => (float) $v === 250.0)
            ->assertJsonPath('drivers.0.failed_value', fn ($v): bool => (float) $v === 120.0)
            ->assertJsonPath('drivers.0.total_sales', fn ($v): bool => (float) $v === 250.0)
            ->assertJsonPath('drivers.0.transfers_paid', fn ($v): bool => (float) $v === 80.0);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * An open operational custody: a driver/vehicle pairing + one trip in the given custody status.
     *
     * @return array{assignment: \Modules\Logistics\Drivers\Domain\Models\DriverVehicleAssignment, trip: Trip}
     */
    private function seedCustodyTrip(Company $company, TripStatus $status): array
    {
        $assignment = app(DriverVehicleAssignmentService::class)->assign(
            $this->makeDriver(),
            $this->makeVehicle(),
        );

        $trip = Trip::create([
            'company_id' => $company->id,
            'trip_number' => 'TRP-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Custody Run',
            'driver_vehicle_assignment_id' => $assignment->id,
            'status' => $status->value,
            'trip_started_at' => self::DAY.' 09:00:00',
        ]);

        return ['assignment' => $assignment, 'trip' => $trip];
    }

    /**
     * An ACTIVE handoff trip: assign a driver/vehicle, create the orders (with paid/deposit state),
     * link them as trip orders, then run generateStops so each stop carries its immutable
     * expected_collection_at_handoff snapshot.
     *
     * @param  list<array{total: float, paid: bool, deposit: float}>  $orders
     * @return array{assignment_id: int, trip: Trip, order_ids: list<string>}
     */
    private function seedHandoffTrip(Company $company, array $orders): array
    {
        $assignment = app(DriverVehicleAssignmentService::class)->assign(
            $this->makeDriver(),
            $this->makeVehicle(),
        );

        $trip = Trip::create([
            'company_id' => $company->id,
            'trip_number' => 'TRP-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Handoff Run',
            'driver_vehicle_assignment_id' => $assignment->id,
            'trip_started_at' => self::DAY.' 09:00:00',
        ]);

        $orderIds = [];
        foreach ($orders as $i => $spec) {
            $orderId = $this->makeOrder($company->id, 'Customer '.$i, $spec['total'], 'cod');
            DB::table('orders')->where('id', $orderId)->update([
                'date_paid' => $spec['paid'] ? now() : null,
                'deposit_amount' => $spec['deposit'],
            ]);
            TripOrder::create(['trip_id' => $trip->id, 'order_id' => $orderId]);
            $orderIds[] = $orderId;
        }

        app(DeliveryService::class)->generateStops($trip);

        return ['assignment_id' => (int) $assignment->id, 'trip' => $trip, 'order_ids' => $orderIds];
    }

    /**
     * One driver's day for a company: an active driver/vehicle pairing, one trip on
     * self::DAY with two stops (one delivered, one failed), a cash collection, two
     * bank transfers (one verified with a proof, one merely recorded), and a
     * submitted-and-balanced settlement.
     *
     * @return array{assignment_id: int, trip_number: string, proof_id: string}
     */
    private function seedDriverDay(Company $company): array
    {
        $assignment = app(DriverVehicleAssignmentService::class)->assign(
            $this->makeDriver(),
            $this->makeVehicle(),
        );

        $trip = Trip::create([
            'company_id' => $company->id,
            'trip_number' => 'TRP-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Settlement Day Run',
            'driver_vehicle_assignment_id' => $assignment->id,
            // Custody-eligible status: this driver-day is an OPEN operational custody (goods handed
            // over), so it belongs on the Active board under the single-active-custody contract.
            'status' => TripStatus::InProgress->value,
            'trip_started_at' => self::DAY.' 09:00:00',
        ]);

        $orderDelivered = $this->makeOrder($company->id, 'Ahmed Hassan', 250.00, 'cod');
        $orderFailed = $this->makeOrder($company->id, 'Sara Ali', 120.00, 'cod');

        $delivered = DeliveryStop::create([
            'trip_id' => $trip->id,
            'order_id' => $orderDelivered,
            'sequence' => 1,
            'status' => DeliveryStopStatus::Delivered->value,
        ]);
        DeliveryStop::create([
            'trip_id' => $trip->id,
            'order_id' => $orderFailed,
            'sequence' => 2,
            'status' => DeliveryStopStatus::Failed->value,
        ]);

        // Cash collected (feeds cash_expected = 100) + two transfers.
        PaymentCollection::create([
            'trip_id' => $trip->id,
            'stop_id' => $delivered->id,
            'payment_type' => PaymentType::Cash->value,
            'amount' => 100.00,
            'status' => PaymentCollection::STATUS_RECORDED,
        ]);
        PaymentCollection::create([
            'trip_id' => $trip->id,
            'stop_id' => $delivered->id,
            'payment_type' => PaymentType::BankTransfer->value,
            'amount' => 50.00,
            'status' => PaymentCollection::STATUS_VERIFIED,
        ]);
        PaymentCollection::create([
            'trip_id' => $trip->id,
            'stop_id' => $delivered->id,
            'payment_type' => PaymentType::BankTransfer->value,
            'amount' => 30.00,
            'status' => PaymentCollection::STATUS_RECORDED,
        ]);

        $proofId = $this->makeActivePaymentProof($company->id, $orderDelivered);

        // Submitted, balanced settlement (100 handed back against 100 expected).
        TripSettlement::create([
            'trip_id' => $trip->id,
            'cash_collected' => 100.00,
            'bank_transfers_pending' => 80.00,
            'already_paid' => 0,
            'total_collected' => 180.00,
            'cash_expected' => 100.00,
            'driver_cash_submitted' => 100.00,
            'discrepancy' => 0.00,
            'status' => SettlementStatus::Submitted->value,
            'submitted_at' => now(),
        ]);

        return [
            'assignment_id' => (int) $assignment->id,
            'trip_number' => $trip->trip_number,
            'proof_id' => $proofId,
        ];
    }

    /**
     * A permanently-closed driver-day: the trip is Closed and its settlement Finalized on
     * self::DAY, so it belongs to the History board and never to Active.
     *
     * @return array{assignment_id: int}
     */
    private function seedClosedDriverDay(Company $company): array
    {
        $assignment = app(DriverVehicleAssignmentService::class)->assign(
            $this->makeDriver(),
            $this->makeVehicle(),
        );

        $trip = Trip::create([
            'company_id' => $company->id,
            'trip_number' => 'TRP-'.substr(md5(uniqid('', true)), 0, 6),
            'name' => 'Closed Settlement Run',
            'driver_vehicle_assignment_id' => $assignment->id,
            'status' => TripStatus::Closed->value,
            'dispatched_at' => self::DAY.' 08:00:00',
            'trip_started_at' => self::DAY.' 09:00:00',
            'trip_finished_at' => self::DAY.' 17:00:00',
        ]);

        $order = $this->makeOrder($company->id, 'Mona Adel', 250.00, 'cod');
        DeliveryStop::create([
            'trip_id' => $trip->id,
            'order_id' => $order,
            'sequence' => 1,
            'status' => DeliveryStopStatus::Delivered->value,
        ]);
        PaymentCollection::create([
            'trip_id' => $trip->id,
            'stop_id' => DeliveryStop::where('trip_id', $trip->id)->value('id'),
            'payment_type' => PaymentType::Cash->value,
            'amount' => 100.00,
            'status' => PaymentCollection::STATUS_VERIFIED,
        ]);

        TripSettlement::create([
            'trip_id' => $trip->id,
            'cash_collected' => 100.00,
            'bank_transfers_pending' => 0,
            'already_paid' => 0,
            'total_collected' => 100.00,
            'cash_expected' => 100.00,
            'driver_cash_submitted' => 100.00,
            'discrepancy' => 0.00,
            'status' => SettlementStatus::Finalized->value,
            'submitted_at' => self::DAY.' 17:30:00',
            'reconciled_at' => self::DAY.' 17:45:00',
            'finalized_at' => self::DAY.' 18:00:00',
        ]);

        return ['assignment_id' => (int) $assignment->id];
    }

    private function makeDriver(): Driver
    {
        $suffix = substr(md5(uniqid('', true)), 0, 8);

        return Driver::create([
            'driver_code' => 'DRV-'.$suffix,
            'full_name' => 'Settlement Driver '.$suffix,
            'mobile' => '010'.substr($suffix, 0, 8),
            'national_id' => 'NID-'.$suffix,
            'license_issue_date' => '2024-01-01',
            'license_expiry_date' => '2031-01-01',
        ]);
    }

    private function makeVehicle(): Vehicle
    {
        $suffix = substr(md5(uniqid('', true)), 0, 8);

        return Vehicle::create([
            'vehicle_code' => 'VEH-'.$suffix,
            'plate_number' => 'PL-'.$suffix,
            'type' => 'van',
            'capacity_orders' => 60,
        ]);
    }

    private function makeOrder(string $companyId, string $customerName, float $total, string $paymentMethod): string
    {
        $customerId = (string) Str::uuid();
        DB::table('customers')->insert([
            'id' => $customerId,
            'code' => 'CUS-'.substr(md5($customerId), 0, 8),
            'name' => $customerName,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $orderId = (string) Str::uuid();
        DB::table('orders')->insert([
            'id' => $orderId,
            'company_id' => $companyId,
            'customer_id' => $customerId,
            'customer_name' => $customerName,
            'order_number' => 'ORD-'.substr(md5($orderId), 0, 8),
            'order_date' => self::DAY,
            'total' => $total,
            'payment_method' => $paymentMethod,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $orderId;
    }

    private function makeActivePaymentProof(string $companyId, string $orderId): string
    {
        $proofId = (string) Str::uuid();
        DB::table('payment_proofs')->insert([
            'id' => $proofId,
            'company_id' => $companyId,
            'order_id' => $orderId,
            'state' => 'verified',
            'storage_disk' => 'local',
            'storage_path' => 'proofs/'.$proofId.'.jpg',
            'uploaded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $proofId;
    }
}
