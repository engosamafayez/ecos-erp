<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Presentation\Http\Controllers;

use App\Core\Company\TenantOwnershipResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Modules\Logistics\Distribution\Domain\Services\DriverReportsReadService;
use Modules\Logistics\Drivers\Domain\Models\Driver;

/**
 * Driver App Wallet + Reports — TASK-DRIVER-APP-PHASE-6-WALLET-REPORTS-CLOSURE-001.
 *
 * READ-ONLY, self-scoped to the authenticated driver. Like DriverRuntimeController it owns no
 * business logic: it resolves identity + tenancy + the date window, then delegates every figure
 * to the canonical read service (DriverReportsReadService), which derives money from
 * SettlementService and the payment-collection ledger. No write, no Order.status, no Finance
 * entry. Gated by `loading.driver.operate` (the driver runtime permission) on the route group.
 *
 * SECURITY (§21): a driver can only ever see their OWN data. Trips are resolved from
 * `Driver::user_id = Auth::id()` scoped by company + `driver_vehicle_assignment.driver_id`; no
 * route parameter can widen that. The driver approves nothing here.
 */
final class DriverReportsController extends Controller
{
    public function __construct(
        private readonly DriverReportsReadService $reports,
        private readonly TenantOwnershipResolver $tenant,
    ) {}

    /** GET /api/driver/wallet — operational wallet + closing indicators for the window. */
    public function wallet(Request $request): JsonResponse
    {
        [$driver, $companyId] = $this->context();
        [$from, $to] = $this->window($request);

        return response()->json(['data' => $this->reports->wallet($driver, $companyId, $from, $to)]);
    }

    /** GET /api/driver/reports/orders — orders performance histogram + paginated rows. */
    public function orders(Request $request): JsonResponse
    {
        [$driver, $companyId] = $this->context();
        [$from, $to] = $this->window($request);
        $page = max(1, (int) $request->query('page', '1'));
        $perPage = (int) $request->query('per_page', '20');

        return response()->json($this->reports->ordersPerformance($driver, $companyId, $from, $to, $page, $perPage));
    }

    /** GET /api/driver/reports/goods-movement — per-product movement for the window. */
    public function goodsMovement(Request $request): JsonResponse
    {
        [$driver, $companyId] = $this->context();
        [$from, $to] = $this->window($request);

        return response()->json(['data' => $this->reports->goodsMovement($driver, $companyId, $from, $to)]);
    }

    /** GET /api/driver/reports/shortages — reconciliation-variance shortages for the window. */
    public function shortages(Request $request): JsonResponse
    {
        [$driver, $companyId] = $this->context();
        [$from, $to] = $this->window($request);

        return response()->json(['data' => $this->reports->shortages($driver, $companyId, $from, $to)]);
    }

    /**
     * GET /api/driver/reports/advances — no canonical driver-operational advances authority
     * exists; this returns an explicit unavailable payload rather than fabricating data (§5).
     */
    public function advances(Request $request): JsonResponse
    {
        $this->context(); // still fail-closed to a real driver

        return response()->json(['data' => [
            'available' => false,
            'reason' => 'no_canonical_authority',
            'items' => [],
        ]]);
    }

    /** GET /api/driver/statement?month=YYYY-MM — permanent monthly statement (read model). */
    public function statement(Request $request): JsonResponse
    {
        [$driver, $companyId] = $this->context();
        $month = (string) $request->query('month', '');

        return response()->json(['data' => $this->reports->monthlyStatement($driver, $companyId, $month)]);
    }

    // ── Identity + window ────────────────────────────────────────────────────────

    /** @return array{0: Driver, 1: string} */
    private function context(): array
    {
        $driver = Driver::query()->where('user_id', Auth::id())->first();
        abort_if($driver === null, 403, 'The authenticated user is not a driver.');

        return [$driver, $this->tenant->companyId()];
    }

    /** @return array{0: string, 1: string} [from, to] resolved server-side (§4). */
    private function window(Request $request): array
    {
        $resolved = $this->reports->resolvePeriod(
            $request->query('period') !== null ? (string) $request->query('period') : null,
            $request->query('from') !== null ? (string) $request->query('from') : null,
            $request->query('to') !== null ? (string) $request->query('to') : null,
        );

        return [$resolved['from'], $resolved['to']];
    }
}
