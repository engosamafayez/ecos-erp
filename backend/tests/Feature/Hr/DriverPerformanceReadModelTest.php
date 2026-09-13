<?php

declare(strict_types=1);

namespace Tests\Feature\Hr;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Modules\Hr\Workforce\Domain\Services\DriverEmployeeResolver;
use Modules\Hr\Workforce\Domain\Services\DriverPerformanceReadModel;
use Modules\Hr\Workforce\Domain\Services\EmployeeService;
use Modules\Hr\Workforce\Domain\Services\ManagerScopeService;
use Modules\Hr\Workforce\Domain\Services\ReportingLineService;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * FIN-01 Slice 4 — Driver Performance Presentation (Pattern C).
 *
 * Every fact asserted here is a straight pass-through from
 * DriverReportsReadService with no fixture trips, so a driver with no
 * operational history reads as honest zeros/empties — never fabricated.
 */
class DriverPerformanceReadModelTest extends TestCase
{
    use DatabaseTransactions;

    private function makeDriver(string $code, ?int $userId, string $companyId, string $fullName, string $mobile, string $nationalId): Driver
    {
        return Driver::forceCreate([
            'driver_code' => $code,
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $userId,
            'company_id' => $companyId,
            'full_name' => $fullName,
            'mobile' => $mobile,
            'national_id' => $nationalId,
            'status' => Driver::STATUS_ACTIVE,
        ]);
    }

    public function test_never_recomputes_logistics_business_facts(): void
    {
        $source = (string) file_get_contents(
            base_path('Modules/Hr/Workforce/Domain/Services/DriverPerformanceReadModel.php'),
        );

        foreach (['Trip::', 'DeliveryStop::', 'PaymentCollection::', 'VehicleInventoryItem::', 'VehicleShiftReconciliation', 'SettlementService'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                'Driver Performance must compose Logistics read services, never query or re-derive their tables directly.',
            );
        }

        $this->assertStringContainsString('DriverReportsReadService', $source);
    }

    public function test_matched_driver_performance_carries_employee_attribution_and_honest_zeros(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $employee = app(EmployeeService::class)->create((string) $company->id, [
            'first_name' => 'Matched', 'last_name' => 'One', 'user_id' => $user->id,
        ]);
        $driver = $this->makeDriver('DRV-P1', $user->id, (string) $company->id, 'Matched Driver', '0300000001', 'N-300001');

        $result = app(DriverPerformanceReadModel::class)->forDriver($driver, (string) $company->id, '2026-01-01', '2026-01-31');

        $this->assertSame(DriverEmployeeResolver::MATCHED, $result['identity']['status']);
        $this->assertSame((string) $employee->id, $result['identity']['employee_id']);
        // No trip fixture exists for this driver — the composed read must report
        // a real, honest zero/empty state, never a guessed figure.
        $this->assertSame(0, $result['delivery']['received']);
        $this->assertSame(0, $result['delivery']['delivered']);
        $this->assertSame(0, $result['delivery']['delivery_rate']);
        $this->assertSame(0, $result['shortages']['count']);
        $this->assertFalse($result['shortages']['value_available'], 'Shortage monetary value has no canonical authority and must stay reported as unavailable.');
    }

    public function test_unmatched_driver_performance_carries_no_employee_attribution(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $driver = $this->makeDriver('DRV-P2', $user->id, (string) $company->id, 'Unmatched Driver', '0300000002', 'N-300002');

        $result = app(DriverPerformanceReadModel::class)->forDriver($driver, (string) $company->id, '2026-01-01', '2026-01-31');

        $this->assertSame(DriverEmployeeResolver::UNMATCHED, $result['identity']['status']);
        $this->assertNull($result['identity']['employee_id']);
        // Still presentable as Driver-scoped facts — never dropped outright.
        $this->assertSame(0, $result['delivery']['received']);
    }

    public function test_roster_excludes_unmatched_and_out_of_scope_drivers_for_a_scoped_manager(): void
    {
        $company = Company::factory()->create();

        $managerUser = User::factory()->create();
        $manager = app(EmployeeService::class)->create((string) $company->id, ['first_name' => 'Mgr', 'last_name' => 'X', 'user_id' => $managerUser->id]);

        $reportUser = User::factory()->create();
        $report = app(EmployeeService::class)->create((string) $company->id, ['first_name' => 'Rep', 'last_name' => 'Y', 'user_id' => $reportUser->id]);
        app(ReportingLineService::class)->assignManager($report, $manager);

        $outsideUser = User::factory()->create();
        app(EmployeeService::class)->create((string) $company->id, ['first_name' => 'Out', 'last_name' => 'Z', 'user_id' => $outsideUser->id]);

        $reportDriver = $this->makeDriver('DRV-R1', $reportUser->id, (string) $company->id, 'Report Driver', '0300000010', 'N-300010');
        $outsideDriver = $this->makeDriver('DRV-R2', $outsideUser->id, (string) $company->id, 'Outside Driver', '0300000011', 'N-300011');
        $unmatchedDriver = $this->makeDriver('DRV-R3', null, (string) $company->id, 'Unmatched Driver', '0300000012', 'N-300012');

        $visibleIds = app(ManagerScopeService::class)->visibleEmployeeIds($manager);
        $rows = app(DriverPerformanceReadModel::class)->roster((string) $company->id, $visibleIds);

        $ids = array_column($rows, 'driver_id');
        $this->assertContains((string) $reportDriver->id, $ids, 'A driver resolving to a visible subtree employee must appear.');
        $this->assertNotContains((string) $outsideDriver->id, $ids, 'A driver resolving outside the manager\'s subtree must not appear.');
        $this->assertNotContains((string) $unmatchedDriver->id, $ids, 'An unmatched driver has no employee to check subtree membership against, so a scoped manager must never see it.');
    }

    public function test_roster_is_unrestricted_for_a_company_level_bypass(): void
    {
        $company = Company::factory()->create();
        $this->makeDriver('DRV-R4', null, (string) $company->id, 'Any Driver', '0300000020', 'N-300020');

        $rows = app(DriverPerformanceReadModel::class)->roster((string) $company->id, null);

        $this->assertCount(1, $rows, 'A null visible-set (IAM bypass) must see every company driver regardless of identity state.');
    }

    public function test_roster_never_crosses_company_boundaries(): void
    {
        $companyA = Company::factory()->create();
        $companyB = Company::factory()->create();
        $this->makeDriver('DRV-R5', null, (string) $companyA->id, 'Company A Driver', '0300000030', 'N-300030');
        $this->makeDriver('DRV-R6', null, (string) $companyB->id, 'Company B Driver', '0300000031', 'N-300031');

        $rows = app(DriverPerformanceReadModel::class)->roster((string) $companyA->id, null);

        $this->assertCount(1, $rows);
        $this->assertSame('Company A Driver', $rows[0]['driver_name']);
    }
}
