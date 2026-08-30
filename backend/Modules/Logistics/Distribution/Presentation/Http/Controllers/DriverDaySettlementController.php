<?php

declare(strict_types=1);

namespace Modules\Logistics\Distribution\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Logistics\Distribution\Domain\Services\DriverDaySettlementReadService;

/**
 * READ-ONLY per-driver / per-day settlement board and drill-down
 * (TASK-OPERATIONS-DRIVER-DAY-SETTLEMENT-UI-001, extended by
 * TASK-OPERATIONS-DRIVER-CLOSING-PAGE-ENHANCEMENT-001).
 *
 * A rollup over the canonical per-trip settlement engine and the canonical vehicle
 * custody / shift-reconciliation engines — no writes, no new status machine. Money is
 * derived by {@see DriverDaySettlementReadService}, which sums
 * SettlementService::financialSummary() across each driver's trips; goods/damage/shortage
 * are derived from the reconciliation authority.
 *
 * `scope` selects the operational tab: `day` (a single date — default, back-compat),
 * `active` (open custody, not date-bounded) or `history` (closed settlements, date-ranged,
 * paginated, sorted). All read-only.
 *
 * Both endpoints are gated on `logistics.distribution.view` at the route, and fail closed
 * on tenancy here: a caller with no company scope is refused with 403 (copied from
 * SettlementController).
 */
class DriverDaySettlementController extends Controller
{
    public function __construct(
        private readonly DriverDaySettlementReadService $service,
    ) {}

    /**
     * GET /api/logistics/distribution/driver-settlement
     *   ?scope=day&date=Y-m-d
     *   ?scope=active
     *   ?scope=history&from=Y-m-d&to=Y-m-d&page=&per_page=&sort=&dir=
     * plus &search=&shipping_company_id=&status=&stage=&has_damage=&has_shortage=&needs_review=
     */
    public function index(Request $request): JsonResponse
    {
        return match ($request->query('scope', 'day')) {
            'active' => $this->active($request),
            'history' => $this->history($request),
            default => $this->day($request),
        };
    }

    /** GET .../driver-settlement/{assignmentId}?date=Y-m-d */
    public function show(Request $request, int $assignmentId): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
        ]);

        return response()->json(
            $this->service->driverDay($this->companyId(), $validated['date'], $assignmentId),
        );
    }

    // ── Scope handlers ──────────────────────────────────────────────────────────

    private function day(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['nullable', 'in:day'],
            'date' => ['required', 'date_format:Y-m-d'],
        ] + $this->filterRules());

        return response()->json(
            $this->service->daySummary($this->companyId(), $validated['date'], $this->filters($request)),
        );
    }

    private function active(Request $request): JsonResponse
    {
        $request->validate(['scope' => ['required', 'in:active']] + $this->filterRules());

        return response()->json(
            $this->service->activeBoard($this->companyId(), $this->filters($request)),
        );
    }

    private function history(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'scope' => ['required', 'in:history'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'sort' => ['nullable', 'in:driver,date,difference,delivery_pct'],
            'dir' => ['nullable', 'in:asc,desc'],
        ] + $this->filterRules());

        return response()->json(
            $this->service->historyBoard(
                $this->companyId(),
                $validated['from'],
                $validated['to'],
                (int) ($validated['page'] ?? 1),
                (int) ($validated['per_page'] ?? 25),
                $validated['sort'] ?? 'date',
                $validated['dir'] ?? 'desc',
                $this->filters($request),
            ),
        );
    }

    // ── Shared filter validation / extraction ───────────────────────────────────

    /** @return array<string, array<int, string>> */
    private function filterRules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'shipping_company_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'in:needs_review,under_review,disputed,settled'],
            'stage' => ['nullable', 'in:open_custody,in_operation,ready_for_return,warehouse_counting,needs_review,ready_for_closing,closed'],
            'has_damage' => ['nullable', 'boolean'],
            'has_shortage' => ['nullable', 'boolean'],
            'needs_review' => ['nullable', 'boolean'],
            'sort' => ['nullable', 'in:driver,date,difference,delivery_pct'],
            'dir' => ['nullable', 'in:asc,desc'],
        ];
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return [
            'search' => $request->query('search'),
            'shipping_company_id' => $request->query('shipping_company_id'),
            'status' => $request->query('status'),
            'stage' => $request->query('stage'),
            'has_damage' => $request->boolean('has_damage'),
            'has_shortage' => $request->boolean('has_shortage'),
            'needs_review' => $request->boolean('needs_review'),
            'sort' => $request->query('sort'),
            'dir' => $request->query('dir'),
        ];
    }

    /**
     * The acting company, or a hard failure.
     *
     * Copied from SettlementController::companyId(): never returns null. The
     * `->when($companyId, …)` idiom used elsewhere in Logistics silently DROPS the
     * filter when the company is null and returns every tenant's rows, so it is not
     * used for the tenant scope.
     */
    private function companyId(): string
    {
        $companyId = request()->user()?->company_id;

        if ($companyId === null || $companyId === '') {
            abort(403, 'No company scope for the acting user.');
        }

        return (string) $companyId;
    }
}
