<?php

declare(strict_types=1);

namespace Modules\Finance\Receivables\Application\Queries;

use App\Models\User;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Validator;
use Modules\Finance\Receivables\Domain\Services\CustomerLedgerService;
use Modules\IAM\Domain\Contracts\AuthorizationGatewayInterface;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportPeriod;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-CUST-03 · Customer Outstanding AR (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metric: MET-FIN-02 (Outstanding AR). Read strategy: A — thin proxy into
 * `CustomerLedgerService::balance()`.
 *
 * Permission co-gating (catalogue note): `reports.customers.view` **and** `finance.ar.view`
 * — this report sits in the Customers category (so the generic execution-layer check per
 * report maps it to `reports.customers.view`, per §16's "exactly one approved category
 * permission"), but exposes genuinely Finance-owned balance data no other Customers report
 * touches. The second gate is enforced here, inside this handler, by re-using the exact
 * same `AuthorizationGatewayInterface::decision()` the execution service itself calls — not
 * a second engine, not a bypass, exactly ADR-045 Decision 5's "additional gate, layered on
 * top, never a substitute." `ReportQueryContext` carries only a `userId` string (not a
 * hydrated `User`), so the user is re-fetched once here rather than widening that shared
 * value object for every other handler's sake.
 */
final class CustomerOutstandingArQuery implements ReportHandlerInterface
{
    public function __construct(
        private readonly CustomerLedgerService $customerLedger,
        private readonly AuthorizationGatewayInterface $authorization,
    ) {}

    public function reportId(): string
    {
        return 'RPT-CUST-03';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'customer_id' => ['required', 'uuid'],
        ])->validate();

        return [
            'customer_id' => $validated['customer_id'],
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $this->assertFinanceArAccess($context);

        $balance = $this->customerLedger->balance($context->companyId, $filters['customer_id']);

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: ['MET-FIN-02' => $balance],
            rows: [],
            totals: ['customer_id' => $filters['customer_id'], 'outstanding_ar' => $balance],
            period: new ReportPeriod(null, null),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }

    private function assertFinanceArAccess(ReportQueryContext $context): void
    {
        $user = $context->userId !== null ? User::query()->find($context->userId) : null;

        if ($user === null || $this->authorization->decision($user, 'finance.ar.view')->isDenied()) {
            throw new AuthorizationException('Permission denied: finance.ar.view');
        }
    }
}
