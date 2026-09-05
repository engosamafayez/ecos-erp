<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Application\Queries;

use DateTimeImmutable;
use Illuminate\Support\Facades\Validator;
use Modules\Purchasing\GoodsReceipts\Domain\Enums\GoodsReceiptStatus;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Reporting\Application\Support\ReportDateRange;
use Modules\Reporting\Domain\Contracts\ReportHandlerInterface;
use Modules\Reporting\Domain\ValueObjects\ReportQueryContext;
use Modules\Reporting\Domain\ValueObjects\ReportResult;

/**
 * RPT-PROC-01 · Purchasing Overview (ENTERPRISE-REPORTING-PLATFORM.md §18).
 * Metrics: MET-PROC-01 (Purchase Volume), MET-PROC-02 (Supplier Spend).
 *
 * TASK-ECOS-REPORTING-V1-SOURCE-REMEDIATION-007 §4 fix — two confirmed defects:
 *
 * (A) This handler used to call `GetSupplierSummaryStatsQuery::execute()` with zero
 * arguments, silently relying on that function's own ambient `TenantOwnershipResolver`
 * resolution instead of the `ReportQueryContext::$companyId` this handler already receives.
 * Now passed explicitly on every call — see `GetSupplierSummaryStatsQuery`'s own docblock
 * for why an explicit company id must take precedence over ambient resolution inside
 * Reporting specifically, even for an unrestricted/system-role actor.
 *
 * (B) Both metrics are ratified as period-bound by the Metric Dictionary ("...in the
 * period" — §17 MET-PROC-01/02) — `GetSupplierSummaryStatsQuery`'s own fields
 * (`open_pos_total`, a current open-PO count with no date bound; `total_outstanding`, an
 * all-time invoiced-minus-paid balance) are neither period-bound nor the same figure the
 * dictionary defines, and `validateFilters()` didn't even accept a date range. Fixed by
 * computing both metrics directly from posted Goods Receipts within the now-accepted
 * `date_from`/`date_to` window: MET-PROC-01 (Purchase Volume) as the count of Goods
 * Receipts posted in the period; MET-PROC-02 (Supplier Spend) as their summed invoiced
 * value (never `paid_amount` — the dictionary's own known dependency: "legacy, diverges
 * from the AP ledger"). `GetSupplierSummaryStatsQuery`'s broader, still-useful snapshot
 * figures remain available under `totals.snapshot`, but no longer stand in for the two
 * ratified metrics themselves.
 *
 * `GoodsReceipt` carries its own ambient tenant global scope, but Reporting explicitly
 * qualifies `company_id` here anyway rather than relying on it alone — the same principle
 * as `GetSupplierSummaryStatsQuery`'s explicit-context fix: a Reporting execution's company
 * scope must come from its own resolved `ReportQueryContext`, never from whatever an
 * unrestricted actor's ambient resolution happens to allow.
 */
final class PurchasingOverviewQuery implements ReportHandlerInterface
{
    public function __construct(
        private readonly GetSupplierSummaryStatsQuery $summaryStats,
    ) {}

    public function reportId(): string
    {
        return 'RPT-PROC-01';
    }

    public function validateFilters(array $rawFilters): array
    {
        $validated = Validator::make($rawFilters, [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ])->validate();

        return [
            'date_from' => $validated['date_from'] ?? now()->startOfMonth()->toDateString(),
            'date_to' => $validated['date_to'] ?? now()->toDateString(),
        ];
    }

    public function execute(ReportQueryContext $context, array $filters): ReportResult
    {
        $snapshot = $this->summaryStats->execute($context->companyId);

        $range = new ReportDateRange($filters['date_from'], $filters['date_to']);

        $periodBase = $range->applyToDateColumn(
            GoodsReceipt::query()
                ->where('company_id', $context->companyId)
                ->where('status', GoodsReceiptStatus::Posted->value)
                ->whereNull('deleted_at'),
            'receipt_date',
        );

        $purchaseVolume = (clone $periodBase)->count();
        $supplierSpend = (float) ((clone $periodBase)->sum('invoice_total_amount') ?? 0);

        return new ReportResult(
            reportId: $this->reportId(),
            kpis: [
                // MET-PROC-01 Purchase Volume — count of Goods Receipts posted in the period.
                'MET-PROC-01' => $purchaseVolume,
                // MET-PROC-02 Supplier Spend — invoiced value of those same receipts.
                'MET-PROC-02' => round($supplierSpend, 2),
            ],
            rows: [],
            totals: [
                'purchase_volume' => $purchaseVolume,
                'supplier_spend' => round($supplierSpend, 2),
                'snapshot' => $snapshot,
            ],
            period: $range->toPeriod(),
            appliedFilters: $filters,
            generatedAt: new DateTimeImmutable,
        );
    }
}
