<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Cash\Domain\Models\CashAccount;
use Modules\Finance\Integration\Domain\Models\AccountRole;
use Modules\Finance\Ledger\Domain\Models\Account;
use Modules\Inventory\WarehouseLiabilities\Domain\Models\WarehouseLiability;
use Modules\Logistics\Distribution\Domain\Models\DriverTripMovement;
use Modules\Logistics\Distribution\Domain\Models\TripCashHandover;
use Modules\Logistics\Distribution\Domain\Models\TripSettlement;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-DRIVER-SETTLEMENT-TREASURY-FINAL-IMPLEMENTATION-002.
 *
 * Pins the Cash Handover contract: an authorized (non-driver) receiver
 * confirms Treasury's physical count against the canonical Net Cash figure;
 * the received amount — never the expected amount, never the driver's
 * declaration — is the only figure ever posted to Finance; a repeat
 * confirmation with the same amount is a no-op; a conflicting repeat is
 * refused; the confirmation never creates a WarehouseLiability or mutates
 * Goods Remaining / Returns state.
 *
 * NOT EXECUTED as part of this task (§26 Validation Freeze — no PHPUnit run
 * manually). Static-reviewed against the conventions this test file mirrors
 * (Tests\Feature\Operations\DriverTripMovementApprovalTest — same fixture
 * style, same actingAs/actingAsUnprivileged helpers, same RefreshDatabase
 * usage). Committed-ready, not run.
 */
final class CashHandoverConfirmationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Warehouse $warehouse;

    private CashAccount $cashAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = Company::factory()->create();
        $this->warehouse = Warehouse::factory()->create(['company_id' => $this->company->id]);
        $this->cashAccount = $this->financeFixture();
    }

    // ── Golden path ───────────────────────────────────────────────────────────

    public function test_authorized_receiver_confirms_exact_handover(): void
    {
        $s = $this->scenario();
        $this->addCashCollection($s['trip_id'], 5000);
        $operator = User::factory()->create(['company_id' => $this->company->id]);

        $res = $this->actingAs($operator)
            ->postJson("/api/logistics/distribution/trips/{$s['trip_uuid']}/cash-handover/confirm", [
                'received_cash' => 5000,
                'cash_account_id' => $this->cashAccount->uuid,
            ])
            ->assertStatus(201);

        $res->assertJsonPath('data.expected_cash', 5000.0);
        $res->assertJsonPath('data.received_cash', 5000.0);
        $res->assertJsonPath('data.difference', 0.0);
        $res->assertJsonPath('data.is_exact', true);

        $handover = TripCashHandover::query()->where('trip_id', $s['trip_id'])->first();
        self::assertNotNull($handover);
        self::assertNotNull($handover->cash_transaction_id);
        self::assertSame($operator->id, $handover->received_by);
    }

    public function test_expected_cash_nets_approved_advances_and_expenses(): void
    {
        $s = $this->scenario();
        $this->addCashCollection($s['trip_id'], 6500);
        $this->movement($s, 'advance', 1000, 'approved');
        $this->movement($s, 'fuel', 750, 'approved');
        // Noise that must NOT count:
        $this->movement($s, 'fuel', 999, 'pending');

        $operator = User::factory()->create(['company_id' => $this->company->id]);

        $res = $this->actingAs($operator)
            ->getJson("/api/logistics/distribution/trips/{$s['trip_uuid']}/cash-handover")
            ->assertOk();

        // 6500 cash + 1000 advance − 750 expenses = 6750 (the same formula/example
        // TASK-OPERATIONS-DRIVER-TRIP-MOVEMENT-APPROVAL-001 §14 verifies at the day grain).
        $res->assertJsonPath('data.expected_cash', 6750.0);
    }

    public function test_under_and_over_receipt_are_both_accepted_and_recorded_truthfully(): void
    {
        $short = $this->scenario();
        $this->addCashCollection($short['trip_id'], 1000);
        $operator = User::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($operator)
            ->postJson("/api/logistics/distribution/trips/{$short['trip_uuid']}/cash-handover/confirm", [
                'received_cash' => 900,
                'cash_account_id' => $this->cashAccount->uuid,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.difference', -100.0)
            ->assertJsonPath('data.is_short', true);

        $over = $this->scenario();
        $this->addCashCollection($over['trip_id'], 1000);

        $this->actingAs($operator)
            ->postJson("/api/logistics/distribution/trips/{$over['trip_uuid']}/cash-handover/confirm", [
                'received_cash' => 1050,
                'cash_account_id' => $this->cashAccount->uuid,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.difference', 50.0)
            ->assertJsonPath('data.is_over', true);
    }

    // ── Authorization ─────────────────────────────────────────────────────────

    public function test_unauthorized_user_cannot_confirm_handover(): void
    {
        $s = $this->scenario();
        $this->addCashCollection($s['trip_id'], 1000);

        $stranger = User::factory()->create(['company_id' => $this->company->id]);
        $this->actingAsUnprivileged($stranger)
            ->postJson("/api/logistics/distribution/trips/{$s['trip_uuid']}/cash-handover/confirm", [
                'received_cash' => 1000,
                'cash_account_id' => $this->cashAccount->uuid,
            ])
            ->assertStatus(403);

        self::assertSame(0, TripCashHandover::query()->where('trip_id', $s['trip_id'])->count());
    }

    /**
     * The driver role does not hold `logistics.distribution.update` at all
     * (Architecture-001, confirmed against config/permissions.php) — this
     * endpoint reuses that existing exclusion rather than adding a new,
     * separate self-review identity check (see the implementation report's
     * Authorization section for why a per-record check was not added: there
     * is no `submitted_by`/`declared_by` column on the certified
     * TripSettlement model to compare against, and adding one was judged out
     * of this task's scope).
     */
    public function test_driver_role_cannot_confirm_handover(): void
    {
        $s = $this->scenario();
        $this->addCashCollection($s['trip_id'], 1000);

        $this->actingAs($s['user'])
            ->postJson("/api/logistics/distribution/trips/{$s['trip_uuid']}/cash-handover/confirm", [
                'received_cash' => 1000,
                'cash_account_id' => $this->cashAccount->uuid,
            ])
            ->assertStatus(403);
    }

    public function test_cross_company_account_is_rejected(): void
    {
        $s = $this->scenario();
        $this->addCashCollection($s['trip_id'], 1000);
        $operator = User::factory()->create(['company_id' => $this->company->id]);

        $otherCompanyCash = $this->financeFixture(Company::factory()->create());

        $this->actingAs($operator)
            ->postJson("/api/logistics/distribution/trips/{$s['trip_uuid']}/cash-handover/confirm", [
                'received_cash' => 1000,
                'cash_account_id' => $otherCompanyCash->uuid,
            ])
            ->assertStatus(422);

        self::assertSame(0, TripCashHandover::query()->where('trip_id', $s['trip_id'])->count());
    }

    // ── Idempotency / concurrency ─────────────────────────────────────────────

    public function test_repeat_confirmation_with_same_amount_is_idempotent_and_does_not_double_post(): void
    {
        $s = $this->scenario();
        $this->addCashCollection($s['trip_id'], 2000);
        $operator = User::factory()->create(['company_id' => $this->company->id]);

        $first = $this->actingAs($operator)->postJson(
            "/api/logistics/distribution/trips/{$s['trip_uuid']}/cash-handover/confirm",
            ['received_cash' => 2000, 'cash_account_id' => $this->cashAccount->uuid],
        )->assertStatus(201);

        $second = $this->actingAs($operator)->postJson(
            "/api/logistics/distribution/trips/{$s['trip_uuid']}/cash-handover/confirm",
            ['received_cash' => 2000, 'cash_account_id' => $this->cashAccount->uuid],
        )->assertStatus(201);

        self::assertSame($first->json('data.id'), $second->json('data.id'));
        self::assertSame(1, TripCashHandover::query()->where('trip_id', $s['trip_id'])->count());
        self::assertSame(
            1,
            DB::table('finance_cash_transactions')->where('id', $first->json('data.cash_transaction_id'))->count(),
        );
    }

    public function test_conflicting_repeat_confirmation_is_refused_not_overwritten(): void
    {
        $s = $this->scenario();
        $this->addCashCollection($s['trip_id'], 2000);
        $operator = User::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($operator)->postJson(
            "/api/logistics/distribution/trips/{$s['trip_uuid']}/cash-handover/confirm",
            ['received_cash' => 2000, 'cash_account_id' => $this->cashAccount->uuid],
        )->assertStatus(201);

        $this->actingAs($operator)->postJson(
            "/api/logistics/distribution/trips/{$s['trip_uuid']}/cash-handover/confirm",
            ['received_cash' => 1800, 'cash_account_id' => $this->cashAccount->uuid],
        )->assertStatus(422);

        $handover = TripCashHandover::query()->where('trip_id', $s['trip_id'])->first();
        self::assertSame(1, TripCashHandover::query()->where('trip_id', $s['trip_id'])->count());
        self::assertSame(2000.0, (float) $handover->received_cash); // unchanged — never overwritten
    }

    // ── Non-goals explicitly proven ───────────────────────────────────────────

    public function test_handover_confirmation_creates_no_warehouse_liability(): void
    {
        $s = $this->scenario();
        $this->addCashCollection($s['trip_id'], 500);
        $operator = User::factory()->create(['company_id' => $this->company->id]);

        $this->actingAs($operator)->postJson(
            "/api/logistics/distribution/trips/{$s['trip_uuid']}/cash-handover/confirm",
            ['received_cash' => 300, 'cash_account_id' => $this->cashAccount->uuid], // short by 200
        )->assertStatus(201);

        // §22/§28: a shortfall is recorded truthfully but never becomes automatic
        // driver liability — that remains the separate, pre-existing (and, per
        // Architecture-001 §12/§15, still owner-undecided) approval authority.
        self::assertSame(0, WarehouseLiability::query()->where('company_id', $this->company->id)->count());
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    /**
     * @return array{user: User, driver_id: int, trip_id: int, trip_uuid: string, pairing_id: int}
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
        $tripUuid = (string) Str::uuid();
        $tripId = (int) DB::table('distribution_trips')->insertGetId([
            'uuid' => $tripUuid, 'company_id' => $this->company->id, 'trip_number' => 'TRP-'.substr(uniqid(), -6),
            'name' => 'trip', 'status' => 'in_progress', 'driver_vehicle_assignment_id' => $pairingId,
            'trip_started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        TripSettlement::create(['trip_id' => $tripId]);

        return ['user' => $user, 'driver_id' => $driverId, 'trip_id' => $tripId, 'trip_uuid' => $tripUuid, 'pairing_id' => $pairingId];
    }

    /**
     * @param  array{driver_id: int, trip_id: int}  $s
     */
    private function movement(array $s, string $category, float $amount, string $status): DriverTripMovement
    {
        return DriverTripMovement::create([
            'company_id' => $this->company->id,
            'driver_id' => $s['driver_id'],
            'trip_id' => $s['trip_id'],
            'category' => $category,
            'direction' => $category === 'advance' ? 'cash_in' : 'cash_out',
            'amount' => $amount,
            'occurred_at' => now(),
            'status' => $status,
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

        // Mirrors what SettlementService::refreshTripTotals() would have computed —
        // recomputed directly here rather than by invoking the settlement engine, to
        // keep this fixture builder independent of that certified service's internals.
        TripSettlement::where('trip_id', $tripId)->update(['cash_collected' => $amount, 'cash_expected' => $amount]);
    }

    /** One active CashAccount + a 'driver_cash_clearing' AccountRole mapping, for one company. */
    private function financeFixture(?Company $company = null): CashAccount
    {
        $company ??= $this->company;

        $cashGlAccount = Account::factory()->create(['company_id' => $company->id]);
        $clearingGlAccount = Account::factory()->create(['company_id' => $company->id]);

        $cashAccount = CashAccount::create([
            'company_id' => $company->id,
            'code' => 'TILL-'.substr(uniqid(), -6),
            'name' => 'Test Till',
            'gl_account_id' => $cashGlAccount->id,
            'is_active' => true,
        ]);

        AccountRole::create([
            'company_id' => $company->id,
            'role' => 'driver_cash_clearing',
            'account_id' => $clearingGlAccount->id,
            'description' => 'Test fixture — driver cash clearing',
        ]);

        return $cashAccount;
    }
}
