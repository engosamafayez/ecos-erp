<?php

declare(strict_types=1);

namespace Modules\Finance\Payables\Application\Queries;

use App\Models\User;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Modules\Finance\Payables\Domain\Services\SupplierLedgerService;
use Modules\IAM\Domain\Contracts\AuthorizationGatewayInterface;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportPeriod;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-PROC-03 · Supplier Statement (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metric: MET-FIN-03 (Outstanding AP). Read strategy: A — thin proxy into
 * `SupplierLedgerService::statement()` (movements) + `::outstandingPayable()` (the current
 * balance KPI, current as of call time — the service takes no date parameter for it, unlike
 * the period-bound statement). Catalogue's own words: "the single highest-leverage
 * 'expose, don't build' item in the whole catalogue" — 100% already-implemented backend,
 * never previously surfaced to any frontend.
 *
 * Permission co-gating (catalogue note): `reports.procurement.view` **and**
 * `finance.ap.view` — same pattern and same rationale as
 * `CustomerOutstandingArQuery`/RPT-CUST-03: this report's category permission alone would
 * let any Procurement-report viewer see Finance-owned payable balances, so the second gate
 * is enforced inside this handler via the same shared `AuthorizationGatewayInterface`.
 *
 * `SupplierLedgerService::statement()` uses the OPPOSITE debit/credit sign convention from
 * `CustomerLedgerService::statement()` (confirmed by direct source inspection) — returned
 * here exactly as the service provides it, never renormalized to match the customer side.
 */
final class SupplierStatementQuery implements ReportHandlerInterface
{
    public function __construct(
        private readonly SupplierLedgerService $supplierLedger,
        private readonly AuthorizationGatewayInterface $authorization,
    ) {}

    public function reportId(): string
    {
        return 'RPT-PROC-03';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'supplier_id' => ['required', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ])->validate();

        return [
            'supplier_id' => $validated['supplier_id'],
            'date_from' => $validated['date_from'] ?? Carbon::now()->startOfYear()->toDateString(),
            'date_to' => $validated['date_to'] ?? Carbon::now()->toDateString(),
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $this->assertFinanceApAccess($context);

        $supplierId = $filters['supplier_id'];
        $outstanding = $this->supplierLedger->outstandingPayable($context->companyId, $supplierId);
        $statement = $this->supplierLedger->statement(
            $context->companyId,
            $supplierId,
            Carbon::parse($filters['date_from']),
            Carbon::parse($filters['date_to']),
        );

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: ['MET-FIN-03' => $outstanding],
            rows: $statement['movements'],
            totals: [
                'supplier_id' => $supplierId,
                'outstanding_payable' => $outstanding,
                'opening_balance' => $statement['opening_balance'],
                'closing_balance' => $statement['closing_balance'],
            ],
            period: new ReportPeriod($filters['date_from'], $filters['date_to']),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }

    private function assertFinanceApAccess(ReportQueryContext $context): void
    {
        $user = $context->userId !== null ? User::query()->find($context->userId) : null;

        if ($user === null || $this->authorization->decision($user, 'finance.ap.view')->isDenied()) {
            throw new AuthorizationException('Permission denied: finance.ap.view');
        }
    }
}
