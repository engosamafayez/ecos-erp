<?php

declare(strict_types=1);

namespace Modules\Finance\Reporting\Application\Queries;

use App\Models\User;
use DateTimeImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\Finance\Payables\Domain\Services\SupplierLedgerService;
use Modules\Finance\Receivables\Domain\Services\CustomerLedgerService;
use Modules\IAM\Domain\Contracts\AuthorizationGatewayInterface;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportPeriod;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-FIN-04 · Customer / Supplier Statement (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metrics: MET-FIN-02, MET-FIN-03. The architecture's own canonical Financial-category entry
 * for "the same item as RPT-CUST-03/RPT-PROC-03" (ReportCatalogue.php docblock) — this
 * handler is genuinely the same underlying capability those two reports expose, reached from
 * the Financial category tab instead of Customers/Procurement. A `party_type` filter picks
 * customer vs. supplier; the two ledger services are never blended into one schema (opposite
 * debit/credit sign conventions, confirmed by direct inspection — see
 * `SupplierStatementQuery`'s own docblock).
 *
 * Co-gated the same way as its two siblings (Decision 5: `reports.finance.view` is additive
 * to, never a substitute for, the underlying `finance.*.view` permission) — `finance.ar.view`
 * for the customer side, `finance.ap.view` for the supplier side.
 */
final class PartyStatementReportQuery implements ReportHandlerInterface
{
    public function __construct(
        private readonly CustomerLedgerService $customerLedger,
        private readonly SupplierLedgerService $supplierLedger,
        private readonly AuthorizationGatewayInterface $authorization,
    ) {}

    public function reportId(): string
    {
        return 'RPT-FIN-04';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'party_type' => ['required', Rule::in(['customer', 'supplier'])],
            'party_id' => ['required', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ])->validate();

        return [
            'party_type' => $validated['party_type'],
            'party_id' => $validated['party_id'],
            'date_from' => $validated['date_from'] ?? Carbon::now()->startOfYear()->toDateString(),
            'date_to' => $validated['date_to'] ?? Carbon::now()->toDateString(),
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $from = Carbon::parse($filters['date_from']);
        $to = Carbon::parse($filters['date_to']);
        $partyId = $filters['party_id'];

        if ($filters['party_type'] === 'customer') {
            $this->assertAccess($context, 'finance.ar.view');
            $balance = $this->customerLedger->balance($context->companyId, $partyId);
            $statement = $this->customerLedger->statement($context->companyId, $partyId, $from, $to);

            return new ReportResult(
                reportId: $this->reportId(),
                kpis: ['MET-FIN-02' => $balance],
                rows: $statement['movements'],
                totals: ['party_type' => 'customer', 'party_id' => $partyId, 'balance' => $balance, 'opening_balance' => $statement['opening_balance'], 'closing_balance' => $statement['closing_balance']],
                period: new ReportPeriod($filters['date_from'], $filters['date_to']),
                appliedFilters: $filters,
                generatedAt: new DateTimeImmutable,
            );
        }

        $this->assertAccess($context, 'finance.ap.view');
        $balance = $this->supplierLedger->outstandingPayable($context->companyId, $partyId);
        $statement = $this->supplierLedger->statement($context->companyId, $partyId, $from, $to);

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: ['MET-FIN-03' => $balance],
            rows: $statement['movements'],
            totals: ['party_type' => 'supplier', 'party_id' => $partyId, 'balance' => $balance, 'opening_balance' => $statement['opening_balance'], 'closing_balance' => $statement['closing_balance']],
            period: new ReportPeriod($filters['date_from'], $filters['date_to']),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }

    private function assertAccess(ReportQueryContext $context, string $permission): void
    {
        $user = $context->userId !== null ? User::query()->find($context->userId) : null;

        if ($user === null || $this->authorization->decision($user, $permission)->isDenied()) {
            throw new AuthorizationException("Permission denied: {$permission}");
        }
    }
}
