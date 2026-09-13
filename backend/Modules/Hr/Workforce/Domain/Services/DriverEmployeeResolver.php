<?php

declare(strict_types=1);

namespace Modules\Hr\Workforce\Domain\Services;

use Illuminate\Support\Collection;
use Modules\Hr\Workforce\Domain\Models\Employee;
use Modules\Logistics\Drivers\Domain\Models\Driver;

/**
 * FIN-01 Slice 1 — the Driver ↔ Employee identity resolver.
 *
 * ┌─ THE CLOSED CTO DECISION ────────────────────────────────────────────────┐
 * │ IAM User is the identity spine. Driver and Employee stay separate          │
 * │ bounded-context entities — there is no driver.employee_id, no              │
 * │ employee.driver_id, no merged identity table. A Driver correlates to an    │
 * │ Employee only where BOTH already share the same user_id AND the same       │
 * │ company. Nothing here is fuzzy: no name, phone or email matching, ever.    │
 * └───────────────────────────────────────────────────────────────────────────┘
 *
 * Both models are reference-only (044B §8 Slice 1): this resolver queries
 * them by id/column, never adds a relation method to either, and never
 * touches Driver's own table.
 */
final class DriverEmployeeResolver
{
    public const MATCHED = 'matched';

    public const UNMATCHED = 'unmatched';

    public const AMBIGUOUS = 'ambiguous';

    public const CROSS_COMPANY = 'cross_company';

    /**
     * Resolve one Driver. Delegates to resolveMany() so the single- and
     * bulk-resolution paths can never disagree about the match rule.
     *
     * @return array{status: string, employee: Employee|null}
     */
    public function resolve(Driver $driver): array
    {
        return $this->resolveMany(collect([$driver]))[$driver->id];
    }

    /**
     * Bulk-resolve a set of Drivers in ONE bounded query regardless of how
     * many drivers are passed — never one Employee query per Driver.
     *
     * Outcomes, per the exact-match contract:
     *   MATCHED        — exactly one Employee shares this Driver's user_id
     *                     AND company_id.
     *   AMBIGUOUS       — more than one same-company Employee shares it (a
     *                     data-quality condition; never resolved to "the
     *                     first").
     *   CROSS_COMPANY   — the user_id matches an Employee, but only in a
     *                     DIFFERENT company than the Driver's own — fail
     *                     closed, reported distinctly from a plain absence.
     *   UNMATCHED       — no Driver.user_id at all, or no Employee anywhere
     *                     shares it.
     *
     * @param  Collection<int, Driver>  $drivers
     * @return array<int, array{status: string, employee: Employee|null}> keyed by Driver::id
     */
    public function resolveMany(Collection $drivers): array
    {
        $userIds = $drivers->pluck('user_id')->filter()->unique()->values()->all();

        $employeesByUser = $userIds === []
            ? collect()
            : Employee::query()->whereIn('user_id', $userIds)->get()->groupBy('user_id');

        $out = [];
        foreach ($drivers as $driver) {
            if ($driver->user_id === null) {
                $out[$driver->id] = ['status' => self::UNMATCHED, 'employee' => null];

                continue;
            }

            $allForUser = $employeesByUser->get($driver->user_id, collect());
            $sameCompany = $allForUser->where('company_id', $driver->company_id);

            $out[$driver->id] = match (true) {
                $sameCompany->count() === 1 => ['status' => self::MATCHED, 'employee' => $sameCompany->first()],
                $sameCompany->count() > 1 => ['status' => self::AMBIGUOUS, 'employee' => null],
                $allForUser->isNotEmpty() => ['status' => self::CROSS_COMPANY, 'employee' => null],
                default => ['status' => self::UNMATCHED, 'employee' => null],
            };
        }

        return $out;
    }

    /**
     * Read-only data-quality visibility (044B §5/§8 of this continuation):
     * classify every Driver in one company. Never creates an Employee, never
     * rewrites a Driver's user_id, never merges anything — this only reports.
     *
     * @return array{total: int, matched: int, unmatched: int, ambiguous: int, cross_company: int, cross_company_driver_ids: list<int>}
     */
    public function diagnoseCompany(string $companyId): array
    {
        // Explicit, canonical company ownership — never the ambient Driver
        // tenant scope, which is a no-op outside an authenticated HTTP
        // request (e.g. this console-invoked diagnostic) and must not be the
        // only thing standing between companies here regardless.
        $drivers = Driver::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->get(['id', 'user_id', 'company_id']);

        $results = $this->resolveMany($drivers);

        $counts = [self::MATCHED => 0, self::UNMATCHED => 0, self::AMBIGUOUS => 0, self::CROSS_COMPANY => 0];
        $crossCompanyDriverIds = [];

        foreach ($results as $driverId => $result) {
            $counts[$result['status']]++;
            if ($result['status'] === self::CROSS_COMPANY) {
                $crossCompanyDriverIds[] = $driverId;
            }
        }

        return [
            'total' => $drivers->count(),
            'matched' => $counts[self::MATCHED],
            'unmatched' => $counts[self::UNMATCHED],
            'ambiguous' => $counts[self::AMBIGUOUS],
            'cross_company' => $counts[self::CROSS_COMPANY],
            'cross_company_driver_ids' => $crossCompanyDriverIds,
        ];
    }
}
