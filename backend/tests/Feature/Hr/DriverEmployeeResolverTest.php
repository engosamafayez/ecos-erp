<?php

declare(strict_types=1);

namespace Tests\Feature\Hr;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Modules\Hr\Workforce\Domain\Services\DriverEmployeeResolver;
use Modules\Hr\Workforce\Domain\Services\EmployeeService;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Modules\Organization\Companies\Domain\Models\Company;
use Tests\TestCase;

/**
 * FIN-01 Slice 1 — Driver ↔ Employee identity resolver.
 *
 * The CTO decision under test: IAM User is the identity spine; a Driver
 * resolves to an Employee ONLY through a shared user_id AND a shared
 * company_id — never a name, phone or email guess, and never "the first"
 * row when more than one candidate matches.
 */
class DriverEmployeeResolverTest extends TestCase
{
    use DatabaseTransactions;

    private function makeDriver(string $code, ?int $userId, ?string $companyId, string $fullName = 'D', string $mobile = '0100', string $nationalId = 'N-0'): Driver
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

    public function test_exact_same_company_user_match_resolves(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $employee = app(EmployeeService::class)->create((string) $company->id, [
            'first_name' => 'Sam', 'last_name' => 'Driver', 'user_id' => $user->id,
        ]);
        $driver = $this->makeDriver('DRV-001', $user->id, (string) $company->id, 'Sam Driver', '0100000001', 'N-100001');

        $result = app(DriverEmployeeResolver::class)->resolve($driver);

        $this->assertSame(DriverEmployeeResolver::MATCHED, $result['status']);
        $this->assertSame((string) $employee->id, (string) $result['employee']->id);
    }

    public function test_driver_with_no_user_id_is_unmatched(): void
    {
        $company = Company::factory()->create();
        $driver = $this->makeDriver('DRV-002', null, (string) $company->id, 'No Login', '0100000002', 'N-100002');

        $result = app(DriverEmployeeResolver::class)->resolve($driver);

        $this->assertSame(DriverEmployeeResolver::UNMATCHED, $result['status']);
        $this->assertNull($result['employee']);
    }

    public function test_user_id_with_no_employee_anywhere_is_unmatched(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        $driver = $this->makeDriver('DRV-003', $user->id, (string) $company->id, 'Lone Wolf', '0100000003', 'N-100003');

        $result = app(DriverEmployeeResolver::class)->resolve($driver);

        $this->assertSame(DriverEmployeeResolver::UNMATCHED, $result['status']);
        $this->assertNull($result['employee']);
    }

    public function test_more_than_one_same_company_employee_is_ambiguous_never_the_first(): void
    {
        $company = Company::factory()->create();
        $user = User::factory()->create();
        app(EmployeeService::class)->create((string) $company->id, ['first_name' => 'A', 'last_name' => 'One', 'user_id' => $user->id]);
        app(EmployeeService::class)->create((string) $company->id, ['first_name' => 'B', 'last_name' => 'Two', 'user_id' => $user->id]);
        $driver = $this->makeDriver('DRV-004', $user->id, (string) $company->id, 'Ambiguous', '0100000004', 'N-100004');

        $result = app(DriverEmployeeResolver::class)->resolve($driver);

        $this->assertSame(DriverEmployeeResolver::AMBIGUOUS, $result['status']);
        $this->assertNull($result['employee'], 'Ambiguity must never be narrowed to an arbitrary first row.');
    }

    public function test_employee_in_a_different_company_fails_closed_as_cross_company(): void
    {
        $driverCompany = Company::factory()->create();
        $employeeCompany = Company::factory()->create();
        $user = User::factory()->create();
        app(EmployeeService::class)->create((string) $employeeCompany->id, [
            'first_name' => 'Other', 'last_name' => 'Company', 'user_id' => $user->id,
        ]);
        $driver = $this->makeDriver('DRV-005', $user->id, (string) $driverCompany->id, 'Cross Co', '0100000005', 'N-100005');

        $result = app(DriverEmployeeResolver::class)->resolve($driver);

        $this->assertSame(DriverEmployeeResolver::CROSS_COMPANY, $result['status']);
        $this->assertNull($result['employee'], 'A cross-company candidate must never be attributed.');
    }

    public function test_matching_name_or_phone_never_substitutes_for_a_user_id_link(): void
    {
        // Same company, same human-readable name AND phone number as the
        // Employee below — but no shared user_id. Resolution must not use
        // either as a fallback identity signal.
        $company = Company::factory()->create();
        app(EmployeeService::class)->create((string) $company->id, [
            'first_name' => 'Ahmed', 'last_name' => 'Hassan', 'phone' => '01099998888', 'user_id' => null,
        ]);
        $driver = $this->makeDriver('DRV-006', null, (string) $company->id, 'Ahmed Hassan', '01099998888', 'N-100006');

        $result = app(DriverEmployeeResolver::class)->resolve($driver);

        $this->assertSame(DriverEmployeeResolver::UNMATCHED, $result['status']);
        $this->assertNull($result['employee']);
    }

    public function test_resolve_many_is_one_bounded_query_regardless_of_batch_size(): void
    {
        $company = Company::factory()->create();
        $users = User::factory()->count(5)->create();
        foreach ($users as $i => $user) {
            app(EmployeeService::class)->create((string) $company->id, [
                'first_name' => "E{$i}", 'last_name' => 'X', 'user_id' => $user->id,
            ]);
        }
        $drivers = $users->map(
            fn (User $u, int $i) => $this->makeDriver("DRV-B{$i}", $u->id, (string) $company->id, "D{$i}", "010000{$i}", "N-B{$i}"),
        );

        DB::enableQueryLog();
        $results = app(DriverEmployeeResolver::class)->resolveMany($drivers);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(5, $results);
        foreach ($drivers as $driver) {
            $this->assertSame(DriverEmployeeResolver::MATCHED, $results[$driver->id]['status']);
        }
        $this->assertSame(1, $queries, 'Bulk resolution must issue exactly one Employee query, never one per driver.');
    }

    public function test_diagnose_company_classifies_every_state_without_writing_anything(): void
    {
        $company = Company::factory()->create();
        $otherCompany = Company::factory()->create();

        $matchedUser = User::factory()->create();
        app(EmployeeService::class)->create((string) $company->id, ['first_name' => 'M', 'last_name' => 'One', 'user_id' => $matchedUser->id]);
        $this->makeDriver('DRV-D1', $matchedUser->id, (string) $company->id, 'Matched', '0200000001', 'N-200001');

        $unmatchedUser = User::factory()->create();
        $this->makeDriver('DRV-D2', $unmatchedUser->id, (string) $company->id, 'Unmatched', '0200000002', 'N-200002');

        $ambiguousUser = User::factory()->create();
        app(EmployeeService::class)->create((string) $company->id, ['first_name' => 'Amb', 'last_name' => 'One', 'user_id' => $ambiguousUser->id]);
        app(EmployeeService::class)->create((string) $company->id, ['first_name' => 'Amb', 'last_name' => 'Two', 'user_id' => $ambiguousUser->id]);
        $this->makeDriver('DRV-D3', $ambiguousUser->id, (string) $company->id, 'Ambiguous', '0200000003', 'N-200003');

        $crossUser = User::factory()->create();
        app(EmployeeService::class)->create((string) $otherCompany->id, ['first_name' => 'Cross', 'last_name' => 'Co', 'user_id' => $crossUser->id]);
        $crossDriver = $this->makeDriver('DRV-D4', $crossUser->id, (string) $company->id, 'CrossCompany', '0200000004', 'N-200004');

        $report = app(DriverEmployeeResolver::class)->diagnoseCompany((string) $company->id);

        $this->assertSame(4, $report['total']);
        $this->assertSame(1, $report['matched']);
        $this->assertSame(1, $report['unmatched']);
        $this->assertSame(1, $report['ambiguous']);
        $this->assertSame(1, $report['cross_company']);
        $this->assertSame([$crossDriver->id], $report['cross_company_driver_ids']);

        // Visibility only — no Employee created, no Driver row rewritten.
        $this->assertDatabaseCount('hr_employees', 4);
        $this->assertDatabaseHas('logistics_drivers', ['id' => $crossDriver->id, 'user_id' => $crossUser->id]);
    }
}
