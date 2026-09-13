<?php

declare(strict_types=1);

namespace Tests\Feature\Finance;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\Fiscal\Domain\Services\FiscalCalendarService;
use Modules\Finance\Infrastructure\Database\Seeders\AccountRoleSeeder;
use Modules\Finance\Infrastructure\Database\Seeders\ChartOfAccountsSeeder;
use Modules\Finance\Integration\Domain\Services\AccountRoleResolver;
use Modules\Finance\Shared\Domain\Services\CompanyFinanceProvisioner;
use Modules\Logistics\Distribution\Domain\Models\TripSettlement;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * TASK-ECOS-V1.1-OPS-02-CLOSURE — Treasury Cash Handover could not post in any
 * company: `CashHandoverService` resolves the `driver_cash_clearing` account
 * role, but `AccountRoleSeeder` never provisioned it, so every confirmation
 * attempt threw `FinanceException::accountRoleNotMapped()` (422). This suite
 * proves the closure: (1) fresh-company provisioning maps the role; (2) an
 * existing company upgraded by re-running the (idempotent) seeder — the same
 * call the backfill migration makes — gets the role without any other mapping
 * moving; (3) an explicit, company-chosen mapping is never silently
 * overwritten; (4) a freshly provisioned company, with NO manual AccountRole
 * fixture of any kind, can complete the real HTTP cash-handover posting path
 * end to end.
 */
final class DriverCashClearingRoleProvisioningTest extends TestCase
{
    use DatabaseTransactions;

    private const ROLE = 'driver_cash_clearing';

    public function test_fresh_company_provisioning_maps_driver_cash_clearing_to_cash_in_transit(): void
    {
        $company = Company::factory()->create();

        app(CompanyFinanceProvisioner::class)->provision((string) $company->id);

        $accountId = app(AccountRoleResolver::class)->resolve((string) $company->id, self::ROLE);

        self::assertSame(
            '1130',
            DB::table('finance_accounts')->where('id', $accountId)->value('code'),
            'driver_cash_clearing must resolve to 1130 Cash in Transit — the same account cod_clearing already uses for the same "cash a driver holds, not yet banked" fact.',
        );
    }

    public function test_existing_company_upgrade_backfills_the_role_without_disturbing_other_mappings(): void
    {
        $company = Company::factory()->create();

        // Simulate a company provisioned BEFORE this role existed: seed everything,
        // then remove exactly the one row an "old" seeder run would not have created.
        (new ChartOfAccountsSeeder)->seedCompany((string) $company->id);
        (new AccountRoleSeeder)->seedCompany((string) $company->id);
        DB::table('finance_account_roles')
            ->where('company_id', $company->id)
            ->where('role', self::ROLE)
            ->delete();

        $before = DB::table('finance_account_roles')
            ->where('company_id', $company->id)
            ->orderBy('role')
            ->pluck('account_id', 'role');

        self::assertFalse($before->has(self::ROLE), 'fixture must start without the role, mirroring a pre-upgrade company.');

        // This is the exact call the backfill migration makes.
        (new AccountRoleSeeder)->run();

        $after = DB::table('finance_account_roles')
            ->where('company_id', $company->id)
            ->orderBy('role')
            ->pluck('account_id', 'role');

        self::assertTrue($after->has(self::ROLE), 'upgrade must backfill the missing role.');
        self::assertSame(
            '1130',
            DB::table('finance_accounts')->where('id', $after[self::ROLE])->value('code'),
        );

        // Every OTHER role's mapping must be byte-for-byte unchanged.
        foreach ($before as $role => $accountId) {
            self::assertSame(
                $accountId,
                $after[$role],
                "upgrade must not move the existing '{$role}' mapping.",
            );
        }
    }

    public function test_an_explicit_company_mapping_is_never_silently_overwritten(): void
    {
        $company = Company::factory()->create();

        (new ChartOfAccountsSeeder)->seedCompany((string) $company->id);
        (new AccountRoleSeeder)->seedCompany((string) $company->id);

        // The company explicitly re-points driver_cash_clearing away from the default —
        // exactly what the existing generic /finance/integration/account-roles endpoint
        // (AccountRoleController::store()) already lets an admin do; no new UI required.
        // 1110 "Cash on Hand" (the 'cash' role's own account) is a deliberately different,
        // valid postable account — not 1130 — so the test can prove it survives untouched.
        $customAccountId = (int) DB::table('finance_accounts')
            ->where('company_id', $company->id)
            ->where('code', '1110')
            ->value('id');

        self::assertNotSame(0, $customAccountId, 'fixture chart must contain 1110 Cash on Hand.');

        DB::table('finance_account_roles')
            ->where('company_id', $company->id)
            ->where('role', self::ROLE)
            ->update(['account_id' => $customAccountId]);

        // Re-run both the fresh-provisioning path and the upgrade/backfill path — neither
        // may re-point an explicit, already-present mapping.
        app(CompanyFinanceProvisioner::class)->provision((string) $company->id);
        (new AccountRoleSeeder)->run();

        $resolved = app(AccountRoleResolver::class)->resolve((string) $company->id, self::ROLE);

        self::assertSame(
            $customAccountId,
            $resolved,
            'an explicit company mapping must survive both fresh-provisioning and backfill re-runs.',
        );
    }

    public function test_a_freshly_provisioned_company_can_complete_the_cash_handover_posting_path(): void
    {
        $company = Company::factory()->create();

        // The ONLY finance setup this test performs is the canonical provisioner —
        // no manual AccountRole row, unlike CashHandoverConfirmationTest's own
        // financeFixture(), which exists specifically to test posting behaviour in
        // isolation from provisioning. This test proves provisioning itself is enough.
        app(CompanyFinanceProvisioner::class)->provision((string) $company->id);
        $this->openPeriodForCompany((string) $company->id);

        $cashGlAccountId = (int) DB::table('finance_accounts')
            ->where('company_id', $company->id)->where('code', '1110')->value('id');

        $cashAccountUuid = (string) Str::uuid();
        DB::table('finance_cash_accounts')->insert([
            'uuid' => $cashAccountUuid,
            'company_id' => $company->id,
            'code' => 'TILL-'.substr(uniqid(), -6),
            'name' => 'Test Till',
            'gl_account_id' => $cashGlAccountId,
            'currency' => 'EGP',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::factory()->create(['company_id' => $company->id]);

        $driverId = (int) DB::table('logistics_drivers')->insertGetId([
            'company_id' => $company->id, 'user_id' => $user->id,
            'driver_code' => 'DRV-'.substr(uniqid(), -6), 'full_name' => 'Provisioning Test Driver',
            'mobile' => '0100'.random_int(1000000, 9999999), 'national_id' => (string) random_int(10000000000000, 99999999999999),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $vehicleId = (int) DB::table('logistics_vehicles')->insertGetId([
            'company_id' => $company->id, 'plate_number' => 'PL-'.strtoupper(substr(uniqid(), -6)),
            'name' => 'V-'.substr(uniqid(), -4), 'capacity_orders' => 25, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $pairingId = (int) DB::table('logistics_driver_vehicle_assignments')->insertGetId([
            'driver_id' => $driverId, 'vehicle_id' => $vehicleId, 'assigned_at' => now(),
            'active_flag' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $tripUuid = (string) Str::uuid();
        $tripId = (int) DB::table('distribution_trips')->insertGetId([
            'uuid' => $tripUuid, 'company_id' => $company->id, 'trip_number' => 'TRP-'.substr(uniqid(), -6),
            'name' => 'trip', 'status' => 'in_progress', 'driver_vehicle_assignment_id' => $pairingId,
            'trip_started_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        TripSettlement::create(['trip_id' => $tripId]);

        DB::table('distribution_payment_collections')->insert([
            'trip_id' => $tripId, 'stop_id' => null, 'payment_type' => 'cash', 'amount' => 1000,
            'status' => 'verified', 'collected_by' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        TripSettlement::where('trip_id', $tripId)->update(['cash_collected' => 1000, 'cash_expected' => 1000]);

        $operator = User::factory()->create(['company_id' => $company->id]);

        $response = $this->actingAs($operator)
            ->postJson("/api/logistics/distribution/trips/{$tripUuid}/cash-handover/confirm", [
                'received_cash' => 1000,
                'cash_account_id' => $cashAccountUuid,
            ]);

        $response->assertStatus(201);
        self::assertEquals(1000.0, (float) $response->json('data.received_cash'));
        self::assertEquals(0.0, (float) $response->json('data.difference'));

        self::assertNotNull(
            $response->json('data.cash_transaction_id'),
            'a real Finance cash transaction must have posted — the whole point of provisioning the role.',
        );
    }

    /** Same fixture pattern used across the Finance test suite (e.g. CommercialAccountingCodTest). */
    private function openPeriodForCompany(string $companyId): void
    {
        $start = Carbon::today()->subMonths(3)->startOfMonth();
        $year = app(FiscalCalendarService::class)->createYear(
            $companyId, 'FY-'.substr(md5(uniqid('', true)), 0, 6), $start, $start->copy()->addMonths(11)->endOfMonth(),
        );

        foreach ($year->periods as $period) {
            if ($period->status->value !== 'open') {
                app(FiscalCalendarService::class)->openPeriod($period);
            }
        }
    }
}
