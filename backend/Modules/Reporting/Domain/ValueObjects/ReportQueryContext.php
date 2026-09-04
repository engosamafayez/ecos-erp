<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\ValueObjects;

/**
 * Carries the tenant/company scope and requesting-user identity that every future report
 * query must honour (ENTERPRISE-REPORTING-PLATFORM.md §9 Data-Scope Security: "Report
 * access != company-wide data access... every report query is scoped transitively because
 * it calls a source service that already carries the source model's own tenant scope").
 *
 * A foundation-level contract, not a runtime implementation: no report handler exists yet
 * (Task 2 is catalogue + dictionary + contracts only). This value object exists so
 * {@see \Modules\Reporting\Domain\Contracts\SourceReportQueryContract} can require a scope
 * at the type level — a future Task 3+ handler cannot forget to accept one, because the
 * contract will not compile without it.
 */
final class ReportQueryContext
{
    public function __construct(
        public readonly string $companyId,
        public readonly ?string $userId = null,
    ) {}
}
