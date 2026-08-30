<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Logistics\Distribution\Domain\Enums\DriverTripMovementStatus;
use Modules\Logistics\Distribution\Domain\Events\TripSettled;
use Modules\Logistics\Distribution\Domain\Models\DriverTripMovement;
use Modules\Logistics\Distribution\Domain\Models\Trip;
use Modules\Logistics\Distribution\Domain\Models\TripSettlement;
use Modules\Logistics\Distribution\Domain\Services\DriverDaySettlementReadService;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-OPERATIONS-DRIVER-TRIP-MOVEMENT-APPROVAL-001 — Operations approval of driver trip movements
 * and their integration into Driver Closing cash.
 *
 * Pins the cash cycle: driver creates Pending; Operations approves/rejects (driver/unauthorized
 * cannot); only APPROVED cash-out counts as Expenses; an APPROVED advance is cash-in, never an
 * expense; Pending/Rejected touch nothing; Net Cash = cash collected + approved cash-in − approved
 * cash-out; pending movements block closing readiness; TripSettled settles approved movements.
 */
final class DriverTripMovementApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
    }

    // ── Driver creation + authority boundary ─────────────────────────────────────

    public function test_driver_creates_own_pending_movement(): void
    {
        $s = $this->scenario();

        $res = $this->actingAs($s['user'])->postJson('/api/driver/trip-expenses', [
            'category' => 'fuel',
            'amount' => 100,
            'note' => 'Petrol',
        ])->assertStatus(201);

        $res->assertJsonPath('data.category', 'fuel');
        $res->assertJsonPath('data.direction', 'cash_out');
        $res->assertJsonPath('data.status', 'pending');
        self::assertSame(1, DriverTripMovement::query()->where('trip_id', $s['trip_id'])->count());
    }

    public function test_unauthorized_user_cannot_approve(): void
    {
        $s = $this->scenario();
        $movement = $this->movement($s, 'fuel', 100);

        // An unprivileged user (no is_system role, no distribution permission) is refused.
        $stranger = User::factory()->create(['company_id' => $this->company->id]);
        $this->actingAsUnprivileged($stranger)
            ->patchJson("/api/logistics/distribution/driver-movements/{$movement->id}/approve")
            ->assertStatus(403);

        self::assertSame('pending', DriverTripMovement::query()->find($movement->id)->status->value);
    }

    public function test_cross_company_movement_is_not_found(): void
    {
        $s = $this->scenario();
        $movement = $this->movement($s, 'fuel', 100);

        // An operator scoped to a DIFFERENT company cannot reach this movement (tenancy).
        $otherCompany = Company::factory()->create();
        $otherOperator = User::factory()->create(['company_id' => $otherCompany->id]);
        $this->actingAs($otherOperator)
            ->patchJson("/api/logistics/distribution/driver-movements/{$movement->id}/approve")
            ->assertStatus(404);
    }

    // ── Operations approve / reject ──────────────────────────────────────────────

    public function test_operations_approves_pending_movement_and_records_audit(): void
    {
        $s = $this->scenario();
        $movement = $this->movement($s, 'fuel', 250);
        $operator = User::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($operator)
            ->patchJson("/api/logistics/distribution/driver-movements/{$movement->id}/approve", ['note' => 'ok'])
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');

        $fresh = DriverTripMovement::query()->find($movement->id);
        self::assertSame('approved', $fresh->status->value);
        self::assertNotNull($fresh->reviewed_by);
        self::assertNotNull($fresh->reviewed_at);
    }

    public function test_operations_rejects_with_reason_and_keeps_record(): void
    {
        $s = $this->scenario();
        $movement = $this->movement($s, 'other', 90);
        $operator = User::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($operator)
            ->patchJson("/api/logistics/distribution/driver-movements/{$movement->id}/reject", ['reason' => 'No receipt'])
            ->assertOk()
            ->assertJsonPath('data.status', 'rejected');

        $fresh = DriverTripMovement::query()->find($movement->id);
        self::assertSame('rejected', $fresh->status->value);
        self::assertSame('No receipt', $fresh->review_note);
    }

    public function test_reject_requires_a_reason(): void
    {
        $s = $this->scenario();
        $movement = $this->movement($s, 'other', 90);
        $operator = User::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($operator)
            ->patchJson("/api/logistics/distribution/driver-movements/{$movement->id}/reject", [])
            ->assertStatus(422);
    }

    public function test_duplicate_approval_is_safely_refused(): void
    {
        $s = $this->scenario();
        $movement = $this->movement($s, 'fuel', 100);
        $operator = User::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($operator)->patchJson("/api/logistics/distribution/driver-movements/{$movement->id}/approve")->assertOk();
        // A second decision on an already-approved movement is refused, not silently re-applied.
        $this->actingAs($operator)->patchJson("/api/logistics/distribution/driver-movements/{$movement->id}/approve")->assertStatus(422);
    }

    // ── Closing integration (read service) ───────────────────────────────────────

    public function test_only_approved_cashout_counts_as_expenses_and_advance_is_cash_in(): void
    {
        $s = $this->scenario();
        $this->movement($s, 'fuel', 400, DriverTripMovementStatus::Approved);
        $this->movement($s, 'road_toll', 150, DriverTripMovementStatus::Approved);
        $this->movement($s, 'other', 200, DriverTripMovementStatus::Approved);
        $this->movement($s, 'advance', 1000, DriverTripMovementStatus::Approved);
        // Noise that must NOT count:
        $this->movement($s, 'fuel', 999, DriverTripMovementStatus::Pending);
        $this->movement($s, 'fuel', 888, DriverTripMovementStatus::Rejected);

        $detail = $this->read()->driverDay($this->company->id, now()->toDateString(), $s['pairing_id']);

        self::assertSame(750.0, (float) $detail['financial']['expenses']);   // 400 + 150 + 200 (advance excluded)
        self::assertSame(1000.0, (float) $detail['financial']['cash_in']);   // advance only
        self::assertSame(750.0, (float) $detail['movements']['approved_expenses']);
        self::assertSame(1, (int) $detail['movements']['pending_count']);
    }

    public function test_net_cash_is_cash_collected_plus_approved_cash_in_minus_cash_out(): void
    {
        $s = $this->scenario();
        $this->addCashCollection($s['trip_id'], 6500);
        $this->movement($s, 'advance', 1000, DriverTripMovementStatus::Approved);
        $this->movement($s, 'fuel', 750, DriverTripMovementStatus::Approved);

        $detail = $this->read()->driverDay($this->company->id, now()->toDateString(), $s['pairing_id']);

        // 6500 physical cash + 1000 advance − 750 expenses = 6750. Electronic is excluded.
        self::assertSame(6500.0, (float) $detail['financial']['cash_collected']);
        self::assertSame(6750.0, (float) $detail['financial']['net_cash']);
    }

    public function test_pending_movements_block_closing_readiness(): void
    {
        $s = $this->scenario();
        $this->movement($s, 'fuel', 100, DriverTripMovementStatus::Pending);

        $detail = $this->read()->driverDay($this->company->id, now()->toDateString(), $s['pairing_id']);

        self::assertContains('pending_movements', $detail['closing_readiness']['blockers']);
        self::assertFalse($detail['closing_readiness']['ready']);
    }

    // ── Settlement boundary (§19) ────────────────────────────────────────────────

    public function test_trip_settled_settles_only_approved_movements(): void
    {
        $s = $this->scenario();
        $approved = $this->movement($s, 'fuel', 100, DriverTripMovementStatus::Approved);
        $pending = $this->movement($s, 'fuel', 50, DriverTripMovementStatus::Pending);

        $trip = Trip::query()->find($s['trip_id']);
        // The listener keys off the trip only; an unsaved settlement instance is enough to carry
        // the canonical event (dispatched synchronously in tests).
        $settlement = new TripSettlement(['trip_id' => $trip->id]);

        TripSettled::dispatch($trip, $settlement, 'test');

        self::assertSame('settled', DriverTripMovement::query()->find($approved->id)->status->value);
        // Pending is untouched by the settlement boundary.
        self::assertSame('pending', DriverTripMovement::query()->find($pending->id)->status->value);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────────

    private function read(): DriverDaySettlementReadService
    {
        return app(DriverDaySettlementReadService::class);
    }

    /**
     * A driver with one custody-eligible trip in the current day + a loading vehicle assignment.
     *
     * @return array{user: User, driver_id: int, trip_id: int, pairing_id: int, assignment_id: string}
     */
    private function scenario(): array
    {
        $user = User::factory()->create(['company_id' => $this->company->id]);

        $driverId = (int) DB::table('logistics_drivers')->insertGetId([
            'company_id' => $this->company->id, 'user_id' => $user->id,
            'driver_code' => 'DRV-'.substr(uniqid(), -6), 'full_name' => 'Driver '.substr(uniqid(), -4),
            'mobile' => '0100'.random_int(1000000, 9999999), 'national_id' => (string) random_int(10000000000000, 99999999999999),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $vehicleId = (int) DB::table('logistics_vehicles')->insertGetId([
            'company_id' => $this->company->id, 'plate_number' => 'PL-'.strtoupper(substr(uniqid(), -6)),
            'name' => 'V-'.substr(uniqid(), -4), 'capacity_orders' => 25, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $pairingId = (int) DB::table('logistics_driver_vehicle_assignments')->insertGetId([
            'driver_id' => $driverId, 'vehicle_id' => $vehicleId, 'assigned_at' => now(),
            'active_flag' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // in_progress is custody-eligible (past loading, not terminal) — the active-custody window.
        $tripId = (int) DB::table('distribution_trips')->insertGetId([
            'uuid' => (string) Str::uuid(), 'company_id' => $this->company->id, 'trip_number' => 'TRP-'.substr(uniqid(), -6),
            'name' => 'trip', 'status' => 'in_progress', 'driver_vehicle_assignment_id' => $pairingId,
            'trip_started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $sessionId = (string) Str::uuid();
        DB::table('loading_sessions')->insert([
            'id' => $sessionId, 'company_id' => $this->company->id, 'warehouse_id' => $this->warehouse->id,
            'session_number' => 'LS-'.substr(uniqid(), -6), 'operational_date' => now()->toDateString(), 'status' => 'loading',
            'created_by' => (string) Str::uuid(), 'updated_by' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $assignmentId = (string) Str::uuid();
        DB::table('vehicle_assignments')->insert([
            'id' => $assignmentId, 'company_id' => $this->company->id, 'loading_session_id' => $sessionId,
            'trip_id' => $tripId, 'vehicle_id' => (string) Str::uuid(), 'vehicle_registration_snapshot' => 'REG-'.substr(uniqid(), -6),
            'vehicle_type_snapshot' => 'van', 'assignment_number' => 'VA-'.substr(uniqid(), -6), 'status' => 'loading_complete',
            'created_by' => (string) Str::uuid(), 'updated_by' => (string) Str::uuid(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        return ['user' => $user, 'driver_id' => $driverId, 'trip_id' => $tripId, 'pairing_id' => $pairingId, 'assignment_id' => $assignmentId];
    }

    /**
     * @param  array{driver_id: int, trip_id: int}  $s
     */
    private function movement(array $s, string $category, float $amount, DriverTripMovementStatus $status = DriverTripMovementStatus::Pending): DriverTripMovement
    {
        return DriverTripMovement::create([
            'company_id' => $this->company->id,
            'driver_id' => $s['driver_id'],
            'trip_id' => $s['trip_id'],
            'category' => $category,
            'direction' => $category === 'advance' ? 'cash_in' : 'cash_out',
            'amount' => $amount,
            'occurred_at' => now(),
            'status' => $status->value,
            'created_by' => 'test',
            'updated_by' => 'test',
        ]);
    }

    private function addCashCollection(int $tripId, float $amount): void
    {
        DB::table('distribution_payment_collections')->insert([
            'trip_id' => $tripId, 'stop_id' => null, 'payment_type' => 'cash', 'amount' => $amount,
            'status' => 'verified', 'collected_by' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
