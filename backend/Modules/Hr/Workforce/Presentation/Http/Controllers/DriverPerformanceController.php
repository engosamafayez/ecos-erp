<?php

declare(strict_types=1);

namespace Modules\Hr\Workforce\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Modules\Hr\Workforce\Domain\Services\DriverEmployeeResolver;
use Modules\Hr\Workforce\Domain\Services\DriverPerformanceReadModel;
use Modules\Hr\Workforce\Domain\Services\ManagerScopeService;
use Modules\Hr\Workforce\Presentation\Http\Controllers\Concerns\ResolvesHrContext;
use Modules\Logistics\Drivers\Domain\Models\Driver;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * FIN-01 Slice 4 — read-only Driver Performance presentation.
 *
 * Driver is reference-only: this controller reads `logistics_drivers` for
 * lookup and presentation only, never writes to it or to any Logistics
 * table. Manager scoping reuses ManagerScopeService exactly as the Employee
 * performance endpoints do — no second scoping mechanism (044B §10).
 */
class DriverPerformanceController extends Controller
{
    use ResolvesHrContext;

    public function __construct(
        private readonly DriverPerformanceReadModel $performance,
        private readonly DriverEmployeeResolver $identity,
        private readonly ManagerScopeService $managerScope,
    ) {}

    /**
     * Explicit, canonical company ownership for the lookup itself — never
     * the ambient Driver tenant scope, so a cross-company id guess 404s
     * deterministically regardless of the acting user's own ambient state
     * (044B §9/§12).
     */
    private function driver(Request $request, string $id): Driver
    {
        return Driver::withoutGlobalScopes()
            ->where('company_id', $this->companyId($request))
            ->where('id', $id)
            ->firstOrFail();
    }

    /** @return array{0: string, 1: string} */
    private function period(Request $request): array
    {
        $to = $request->string('to', Carbon::now()->toDateString())->toString();
        $from = $request->string('from', Carbon::now()->subDays(29)->toDateString())->toString();

        return [$from, $to];
    }

    /**
     * The same two-step visibility gate 044B §10 specifies: an IAM/system
     * bypass (HR/Admin company-level authority) always sees it; a scoped
     * manager sees it only when the driver resolves to an Employee AND that
     * Employee is inside their authorized visible set. A direct-id guess at
     * an out-of-scope or cross-company driver 404s exactly like an
     * out-of-scope employee id already does elsewhere in this module — never
     * a 403, so a blocked request reveals nothing about whether the row
     * exists at all (044B §12).
     */
    private function assertVisible(Request $request, Driver $driver): void
    {
        $visibleIds = $this->managerScope->visibleEmployeeIdsOrNull($request->user(), $this->actingEmployee($request));

        if ($visibleIds === null) {
            return;
        }

        $resolution = $this->identity->resolve($driver);
        $employee = $resolution['employee'];

        if ($employee === null || ! in_array((string) $employee->id, $visibleIds, true)) {
            throw new NotFoundHttpException;
        }
    }

    public function roster(Request $request): JsonResponse
    {
        $visibleIds = $this->managerScope->visibleEmployeeIdsOrNull($request->user(), $this->actingEmployee($request));

        return response()->json(['data' => $this->performance->roster($this->companyId($request), $visibleIds)]);
    }

    public function show(Request $request, string $driverId): JsonResponse
    {
        $driver = $this->driver($request, $driverId);
        $this->assertVisible($request, $driver);

        [$from, $to] = $this->period($request);

        return response()->json([
            'data' => $this->performance->forDriver($driver, $this->companyId($request), $from, $to),
        ]);
    }
}
