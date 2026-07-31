<?php

declare(strict_types=1);

namespace Modules\Hr\Infrastructure\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\Hr\Attendance\Domain\Services\AttendanceRegistrationService;
use Modules\Hr\Attendance\Domain\Services\AttendanceSummaryProvider;
use Modules\Hr\Attendance\Domain\Services\HolidayService;
use Modules\Hr\Attendance\Domain\Services\LeaveRequestService;
use Modules\Hr\Attendance\Domain\Services\WorkforceAvailabilityService;
use Modules\Hr\Attendance\Domain\Services\WorkScheduleService;
use Modules\Hr\Workforce\Domain\Contracts\ProvidesAttendanceSummary;
use Modules\Hr\Workforce\Domain\Policies\EmployeePolicy;
use Modules\Hr\Workforce\Domain\Services\DepartmentService;
use Modules\Hr\Workforce\Domain\Services\Employee360Service;
use Modules\Hr\Workforce\Domain\Services\EmployeeDocumentService;
use Modules\Hr\Workforce\Domain\Services\EmployeeService;
use Modules\Hr\Workforce\Domain\Services\EmploymentContractService;
use Modules\Hr\Workforce\Domain\Services\OrganizationChartService;
use Modules\Hr\Workforce\Domain\Services\ReportingLineService;
use Modules\Hr\Workforce\Domain\Services\WorkforceStructureService;

/**
 * HR & Workforce OS — EPIC H1 + H2.
 *
 * ┌─ EMPLOYEE OWNS IDENTITY · ATTENDANCE OWNS EVENTS ───────────────────────┐
 * │ H1 is the workforce master: organisation structure, the employee record,   │
 * │ contracts and reporting lines. H2 is the operational attendance layer:     │
 * │ calendars, shifts, the daily register and leave.                          │
 * │                                                                           │
 * │ The two meet at exactly one seam. H1 declares the ProvidesAttendanceSummary │
 * │ port for Employee 360 and H2 implements it, bound below — so the workforce │
 * │ domain never names the attendance domain, and attendance calculations stay │
 * │ where they belong. Payroll does not calculate attendance; Finance does not │
 * │ own employees; every other module references an employee by id.           │
 * └─────────────────────────────────────────────────────────────────────────┘
 */
final class HrServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // H1 — Workforce foundation.
        $this->app->singleton(DepartmentService::class);
        $this->app->singleton(WorkforceStructureService::class);
        $this->app->singleton(EmployeeService::class);
        $this->app->singleton(EmploymentContractService::class);
        $this->app->singleton(ReportingLineService::class);
        $this->app->singleton(OrganizationChartService::class);
        $this->app->singleton(EmployeeDocumentService::class);
        $this->app->singleton(Employee360Service::class);
        $this->app->singleton(EmployeePolicy::class);

        // H2 — Attendance and availability.
        $this->app->singleton(HolidayService::class);
        $this->app->singleton(WorkScheduleService::class);
        $this->app->singleton(AttendanceRegistrationService::class);
        $this->app->singleton(LeaveRequestService::class);
        $this->app->singleton(WorkforceAvailabilityService::class);

        // The one seam between them: H1 asks, H2 answers.
        $this->app->bind(ProvidesAttendanceSummary::class, AttendanceSummaryProvider::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../../Workforce/Infrastructure/Database/Migrations');
        $this->loadMigrationsFrom(__DIR__.'/../../Attendance/Infrastructure/Database/Migrations');
    }
}
