<?php

declare(strict_types=1);

namespace Modules\Finance\Integration\Domain\Services;

use Illuminate\Support\Carbon;
use Modules\Finance\Allocation\Domain\Services\AllocationEngine;
use Modules\Finance\Integration\Application\Services\FinancialIntegrationService;
use Modules\Finance\Integration\Domain\Enums\BusinessEventType;
use Modules\Finance\Integration\Domain\ValueObjects\FinancialEvent;
use Modules\Finance\Ledger\Domain\Models\JournalEntry;
use Modules\Finance\Receivables\Domain\Models\CustomerInvoice;
use Modules\Finance\Receivables\Domain\Models\CustomerReceipt;
use Modules\Finance\Receivables\Domain\Services\AccountsReceivableService;

/**
 * Commercial Accounting — the F3-side integration for a commercially
 * delivered order (TASK-ECOS-FINANCE-COMMERCIAL-ACCOUNTING-006).
 *
 * ┌─ TWO POSTINGS, TWO EXISTING AUTHORITIES, ONE TRIGGER ───────────────────┐
 * │ Revenue + AR need a real, allocatable CustomerInvoice — F2 territory      │
 * │ (finance_customer_invoices has no operational integration of its own; a   │
 * │ document this service creates and posts through the UNCHANGED             │
 * │ AccountsReceivableService is the "smallest canonical Finance integration"  │
 * │ TASK-ECOS-FINANCE-FULL-ACCOUNTING-RECONCILIATION-005 anticipated).         │
 * │                                                                            │
 * │ COGS is a bare GL movement with no subledger document at all — exactly     │
 * │ what the F3 rule-driven bridge (FinancialEventProcessor via               │
 * │ FinancialIntegrationService) already exists for, reusing the existing      │
 * │ BusinessEventType::DeliveryConfirmation catalog entry rather than adding   │
 * │ a new one.                                                                 │
 * │                                                                            │
 * │ Each half is independently idempotent through its OWN existing mechanism  │
 * │ (a source_type/source_id existence guard here for the invoice;             │
 * │ PostingCoordinator's exactly-once receipt for the COGS journal) and        │
 * │ independently callable, so a caller can retry either half in isolation     │
 * │ without one half's failure blocking the other (TASK §30/§31).             │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class CommercialAccountingService
{
    private const COGS_SOURCE_MODULE = 'operations.fulfillment';

    public function __construct(
        private readonly AccountsReceivableService $ar,
        private readonly AccountRoleResolver $roles,
        private readonly FinancialIntegrationService $integration,
        private readonly AllocationEngine $allocations,
    ) {}

    /**
     * Recognise revenue (+ AR, + output tax where present) for a commercially
     * delivered order. Idempotent: a second call for the same order returns
     * without creating a second invoice — replaying a Delivered event never
     * double-books revenue.
     *
     * $grossRevenue is the order's own final total (tax-inclusive); $taxTotal
     * is the order's own precomputed tax figure (0.0 when the order carries
     * none). Neither is recalculated here — Finance consumes the commercial
     * snapshot as-is (TASK §8).
     */
    public function recognizeRevenue(
        string $companyId,
        string $orderId,
        string $orderNumber,
        string $customerId,
        float $grossRevenue,
        float $taxTotal,
        Carbon $recognizedAt,
        ?int $actorId,
        ?string $profitCenterId = null,
    ): ?CustomerInvoice {
        if ($grossRevenue <= 0.0) {
            return null; // nothing to recognise — a zero/free order has no AR
        }

        $invoice = $this->findOrderInvoice($companyId, $orderId);

        if ($invoice === null) {
            $net = round($grossRevenue - $taxTotal, 4);
            $line = [
                'revenue_account_id' => $this->roles->resolve($companyId, 'sales_revenue'),
                'net_amount' => $net,
                'description' => 'Order '.$orderNumber,
                'profit_center_id' => $profitCenterId,
            ];

            if ($taxTotal > 0.0) {
                $line['tax_amount'] = round($taxTotal, 4);
                $line['tax_account_id'] = $this->roles->resolve($companyId, 'vat_output');
            }

            $invoice = $this->ar->createDocument(
                companyId: $companyId,
                customerId: $customerId,
                number: 'INV-'.$orderNumber,
                documentDate: $recognizedAt,
                lines: [$line],
                dueDate: $recognizedAt,
                description: 'Revenue recognition — Order '.$orderNumber,
                createdBy: $actorId,
                sourceType: 'order',
                sourceId: $orderId,
            );
        }

        return $invoice->isPosted() ? $invoice : $this->ar->postDocument($invoice, $actorId);
    }

    /**
     * Recognise COGS for a commercially delivered order — a pure GL movement
     * (Dr cost_of_goods_sold, Cr finished_goods), posted through the existing
     * F3 rule-driven bridge. $cogsAmount is the order's own precomputed,
     * historical cost basis (e.g. FIFO layer cost at ship time); it is never
     * recalculated here, so a later product-cost change cannot rewrite it.
     * Queued (async) — the same posting path every other high-volume
     * operational stream (POS, inventory) already uses.
     */
    public function recognizeCogs(
        string $companyId,
        string $orderId,
        string $orderNumber,
        float $cogsAmount,
        Carbon $recognizedAt,
        ?int $actorId,
        ?string $profitCenterId = null,
    ): void {
        if ($cogsAmount <= 0.0) {
            return; // no cost basis to relieve
        }

        $event = new FinancialEvent(
            companyId: $companyId,
            eventType: BusinessEventType::DeliveryConfirmation,
            sourceModule: self::COGS_SOURCE_MODULE,
            entityType: 'order',
            entityId: $orderId,
            amounts: ['cogs' => round($cogsAmount, 4)],
            occurredAt: $recognizedAt,
            idempotencyKey: 'order_delivered_cogs:'.$orderId,
            dimensions: $profitCenterId !== null ? ['profit_center_id' => $profitCenterId] : [],
            actorId: $actorId,
            reference: $orderNumber,
            description: 'COGS — Order '.$orderNumber,
        );

        $this->integration->recordAsync($event);
    }

    /**
     * Reverse a commercially recognised order's revenue posting — the
     * customer-facing correction path for a post-recognition cancellation or
     * return. Reuses the unchanged AccountsReceivableService reversal (Task
     * 5); COGS is not reversed here (there is no COGS subledger document to
     * correct in the same way — a COGS reversal, if ever required, is a
     * distinct new journal against the same rule, out of this task's scope).
     */
    public function reverseRevenue(string $companyId, string $orderId, string $reason, ?int $actorId = null): ?JournalEntry
    {
        $invoice = $this->findOrderInvoice($companyId, $orderId);

        if ($invoice === null || $invoice->journal_entry_id === null) {
            return null;
        }

        return $this->ar->reverseDocumentPosting($invoice, $reason, $actorId);
    }

    /**
     * Settle COD cash physically collected by a driver — DISTINCT from
     * delivery/revenue recognition (TASK §16/§17): Delivered already created
     * the receivable via recognizeRevenue(); this settles it once the cash
     * actually changes hands, landing in a "Cash in Transit" clearing account
     * (the driver has not yet banked it — Task 7 owns that reconciliation)
     * rather than being booked as bank/cash received at delivery time.
     *
     * Idempotent on $codRecordId: a redelivered CodCollected event never
     * creates a second receipt. Allocates against the order's invoice only
     * when one exists and has outstanding balance — a receipt that arrives
     * before or without a posted invoice still posts (a legitimate
     * on-account state) and simply waits to be allocated.
     */
    public function recognizeCodCollection(
        string $companyId,
        string $orderId,
        string $customerId,
        string $codRecordId,
        float $amountCollected,
        Carbon $collectedAt,
        ?int $actorId,
    ): ?CustomerReceipt {
        if ($amountCollected <= 0.0) {
            return null;
        }

        $receipt = CustomerReceipt::query()
            ->where('company_id', $companyId)
            ->where('source_type', 'cod_record')
            ->where('source_id', $codRecordId)
            ->first();

        if ($receipt === null) {
            $receipt = $this->ar->createReceipt(
                companyId: $companyId,
                customerId: $customerId,
                number: 'COD-'.substr(str_replace('-', '', $codRecordId), 0, 12),
                receiptDate: $collectedAt,
                amount: $amountCollected,
                depositAccountId: $this->roles->resolve($companyId, 'cod_clearing'),
                description: 'COD collection — Order '.$orderId,
                createdBy: $actorId,
                sourceType: 'cod_record',
                sourceId: $codRecordId,
            );
        }

        if (! $receipt->isPosted()) {
            $receipt = $this->ar->postReceipt($receipt, $actorId);
        }

        $invoice = $this->findOrderInvoice($companyId, $orderId);
        if ($invoice !== null && $invoice->isPosted() && $invoice->outstanding() > 0.0) {
            $this->allocations->allocateReceipt(
                $receipt,
                $invoice,
                min($amountCollected, $invoice->outstanding()),
                $actorId,
            );
        }

        return $receipt->refresh();
    }

    public function findOrderInvoice(string $companyId, string $orderId): ?CustomerInvoice
    {
        return CustomerInvoice::query()
            ->where('company_id', $companyId)
            ->where('source_type', 'order')
            ->where('source_id', $orderId)
            ->first();
    }
}
