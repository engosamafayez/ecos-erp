<?php

declare(strict_types=1);

namespace Modules\Hr\Workforce\Domain\Services;

use Modules\Logistics\Distribution\Domain\Services\DriverReportsReadService;
use Modules\Logistics\Drivers\Domain\Models\Driver;

/**
 * FIN-01 Slice 4 — Driver Performance Presentation, Pattern C (ADR-045, per
 * Reporting's ExecutiveOverviewQuery precedent: 044B §5/§8).
 *
 * ┌─ COMPOSE, NEVER RECOMPUTE ───────────────────────────────────────────────┐
 * │ Every figure below is read straight off Logistics's own driver-facing      │
 * │ read service and copied verbatim. Nothing here re-derives a delivery,      │
 * │ settlement or custody number, and this class owns no table of its own.     │
 * │                                                                            │
 * │ DriverDaySettlementReadService (the operator custody/settlement board)     │
 * │ was deliberately NOT composed here: its grain is one open custody/         │
 * │ assignment-day, drill-down by assignment id — forcing it into a           │
 * │ driver+period shape would mean writing new cross-day aggregation logic,    │
 * │ which is exactly the recomputation Pattern C forbids. DriverReportsRead    │
 * │ Service already IS a driver+period read model (built for the Driver App's  │
 * │ own Wallet + Reports pages), so it is the correctly-fitting authority for  │
 * │ this presentation, not a nominally-named but shape-mismatched one.         │
 * └────────────────────────────────────────────────────────────────────────────┘
 *
 * Driver is reference-only (044B Slice 1/4): this class queries it for
 * presentation, never writes to `logistics_drivers` or any Distribution table.
 */
final class DriverPerformanceReadModel
{
    public function __construct(
        private readonly DriverReportsReadService $logistics,
        private readonly DriverEmployeeResolver $identity,
    ) {}

    /**
     * The company's driver roster with identity-resolution state only — no
     * Logistics performance figures here, mirroring PerformanceController::
     * myTeam()'s own lightweight-roster-then-drill-down shape (see forDriver
     * for the actual facts). One Driver query + one bulk identity-resolution
     * query, regardless of roster size — never one query per driver.
     *
     * $visibleEmployeeIds === null means unrestricted (an IAM/system bypass —
     * 044B §10 "preserve existing broader IAM authority"). Otherwise only
     * MATCHED drivers whose resolved employee is in the set are returned: an
     * UNMATCHED/AMBIGUOUS/CROSS_COMPANY driver has no Employee to check that
     * membership against, so a scoped manager never sees one — nothing here
     * is guessed into scope (044B §8/§10).
     *
     * @param  array<int, string>|null  $visibleEmployeeIds
     * @return array<int, array<string, mixed>>
     */
    public function roster(string $companyId, ?array $visibleEmployeeIds): array
    {
        $drivers = Driver::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->orderBy('full_name')
            ->get();

        $resolutions = $this->identity->resolveMany($drivers);

        $rows = [];
        foreach ($drivers as $driver) {
            $resolution = $resolutions[$driver->id];
            $identity = $this->identityPayload($resolution);

            if ($visibleEmployeeIds !== null) {
                if ($resolution['status'] !== DriverEmployeeResolver::MATCHED) {
                    continue;
                }
                if (! in_array($identity['employee_id'], $visibleEmployeeIds, true)) {
                    continue;
                }
            }

            $rows[] = [
                'driver_id' => (string) $driver->id,
                'driver_name' => $driver->full_name,
                'identity' => $identity,
            ];
        }

        return $rows;
    }

    /**
     * One driver's performance for a period — canonical facts only, copied
     * verbatim from Logistics's own driver-facing read service. Employee
     * attribution is included only when the identity resolves MATCHED
     * (044B §8): an unattributed driver's facts still render (Driver-scoped
     * presentation), they simply carry no employee_id.
     *
     * @return array<string, mixed>
     */
    public function forDriver(Driver $driver, string $companyId, string $from, string $to): array
    {
        $resolution = $this->identity->resolve($driver);

        // Page size 1 — only the `summary` histogram is used here; the
        // paginated order rows belong to the Driver App's own detail list,
        // not this presentation, so no second query for rows nobody reads.
        $orders = $this->logistics->ordersPerformance($driver, $companyId, $from, $to, 1, 1)['summary'];
        $wallet = $this->logistics->wallet($driver, $companyId, $from, $to);
        $shortages = $this->logistics->shortages($driver, $companyId, $from, $to);

        return [
            'driver' => [
                'id' => (string) $driver->id,
                'name' => $driver->full_name,
            ],
            'identity' => $this->identityPayload($resolution),
            'period' => ['from' => $from, 'to' => $to],
            // "assigned/received delivery workload", "delivered outcomes",
            // "delivery success performance", "failure/outcome counts" (044B §7).
            'delivery' => [
                'received' => $orders['received'],
                'delivered' => $orders['delivered'],
                'partial' => $orders['partial'],
                'failed' => $orders['failed'],
                'returned' => $orders['returned'],
                'skipped' => $orders['skipped'],
                'pending' => $orders['pending'],
                'delivery_rate' => $orders['delivery_rate'],
            ],
            // "collections-related operational facts" + the settlement
            // discrepancy/is_balanced §7 names explicitly.
            'settlement' => [
                'status' => $wallet['settlement_status'],
                'cash_expected' => $wallet['cash']['expected'],
                'cash_submitted' => $wallet['cash']['submitted'],
                'difference' => $wallet['cash']['difference'],
                'is_balanced' => $wallet['cash']['is_balanced'],
            ],
            // DriverReportsReadService::shortages() already flags monetary
            // value as unavailable — preserved verbatim, never papered over
            // with a fabricated zero (044B §7's explicit instruction).
            'shortages' => [
                'count' => count($shortages['items']),
                'value_available' => $shortages['value_available'],
            ],
        ];
    }

    /** @return array{status: string, employee_id: ?string, employee_name: ?string} */
    private function identityPayload(array $resolution): array
    {
        $employee = $resolution['employee'] ?? null;

        return [
            'status' => $resolution['status'],
            'employee_id' => $employee !== null ? (string) $employee->id : null,
            'employee_name' => $employee?->fullName(),
        ];
    }
}
