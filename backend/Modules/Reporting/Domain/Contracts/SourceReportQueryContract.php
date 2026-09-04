<?php

declare(strict_types=1);

namespace Modules\Reporting\Domain\Contracts;

use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;

/**
 * Optional discoverability interface for a source-owned report query (ADR-045 Decision 1;
 * ENTERPRISE-REPORTING-PLATFORM.md §6 Patterns A/B). A future Pattern-B query class added
 * inside an owning module (e.g. `Modules\Commerce\Orders\Application\Queries\
 * SalesOverviewQuery`) MAY implement this to be discoverable by Reporting's own composition
 * layer (Pattern C) through one shared shape, instead of Reporting knowing every source
 * query's own bespoke method signature.
 *
 * Foundation-only: no implementation exists yet, and this task builds none (§5/§6 — "no
 * duplicated operational write models... foundation-level query/read contracts only").
 * `execute()` is deliberately read-only (no `create`/`update`/`delete` counterpart) and
 * requires a {@see ReportQueryContext} — the tenant/company scope is part of the contract's
 * type signature, not an afterthought a future implementer could omit.
 */
interface SourceReportQueryContract
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function execute(ReportQueryContext $context, array $filters): array;
}
