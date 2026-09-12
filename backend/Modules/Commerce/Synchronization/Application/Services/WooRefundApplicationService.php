<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\Services;

use Illuminate\Database\QueryException;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Commerce\Synchronization\Application\DTO\WooRefundOutcome;
use Modules\Commerce\Synchronization\Domain\Enums\SyncDirection;
use Modules\Commerce\Synchronization\Domain\Enums\SyncEntityType;
use Modules\Commerce\Synchronization\Domain\Enums\SyncStatus;
use Modules\Finance\Integration\Domain\Services\CommercialAccountingService;
use Modules\Finance\Receivables\Domain\Enums\CustomerDocumentType;
use Modules\Finance\Receivables\Domain\Models\CustomerInvoice;
use Modules\Finance\Receivables\Domain\Services\AccountsReceivableService;

/**
 * TASK-ECOS-V1.1-WOOCOMMERCE-WOO-01-REFUND-FINANCE-INTEGRATION-043
 * (revised under TASK-ECOS-V1.1-WOO-01-VERIFICATION-REMEDIATION-CHECKPOINT-043-R1 §5).
 *
 * Applies ONE WooCommerce refund event (an entry from the order's own `refunds`
 * summary array, as delivered on the existing `order.updated` webhook payload —
 * no new webhook topic is registered for this) to the canonical Finance
 * authority. **Financial-only.**
 *
 * ┌─ WHY THIS IS FINANCIAL-ONLY — NOT "MONEY AND GOODS, DECIDED HERE" ──────┐
 * │ An earlier revision of this class also inspected Woo's refund line_items  │
 * │ and, when a non-zero quantity was present, invoked ReturnOrderWorkflow.    │
 * │ That was wrong and has been removed. A WooCommerce refund's line_items    │
 * │ and their quantities are a COMMERCIAL/ACCOUNTING ALLOCATION — which order  │
 * │ line, and how much of its price, the refund amount is attributed to —     │
 * │ for pricing, tax and bookkeeping purposes. WooCommerce does not require,   │
 * │ and most stores do not practice, that a line-item quantity on a refund     │
 * │ means the physical goods were shipped back and received: a merchant       │
 * │ routinely refunds a damaged, wrong, or low-value item "amount + line      │
 * │ reference" without ever taking it back. Nothing in WooCommerce's standard  │
 * │ REST/webhook payload distinguishes "refunded and returned" from "refunded, │
 * │ customer keeps the item" — there is no warehouse-receipt field to read.   │
 * │                                                                            │
 * │ Treating a refunded quantity as return evidence would let an external,     │
 * │ unverified, commercial-intent signal drive a physical inventory mutation —  │
 * │ exactly the kind of fabricated evidence this integration must not produce.  │
 * │ Physical return remains EXCLUSIVELY the canonical ECOS warehouse/driver     │
 * │ flow (ReturnOrderWorkflow + ReceiveReturnWorkflow) — already built,        │
 * │ already correct, triggered only by ECOS's own operational events (a        │
 * │ driver-reported delivery rejection, a warehouse-initiated intake) — and is  │
 * │ never triggered by, or inferred from, a Woo webhook in this class.         │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * ┌─ WHY A CREDIT NOTE, NOT CommercialAccountingService::reverseRevenue() ──┐
 * │ reverseRevenue() reverses an invoice's ENTIRE posted journal — it takes    │
 * │ no amount and cannot represent a partial refund, and a second call         │
 * │ against an already-reversed journal has no defined meaning. A              │
 * │ CustomerDocumentType::CreditNote posts the correct, opposite-signed GL      │
 * │ movement (Dr revenue / Cr AR control) for ANY amount up to the invoice's    │
 * │ outstanding total, so the exact same mechanism composes correctly across   │
 * │ any number of separate partial refunds. This is also the literal            │
 * │ instruction in this task's architecture authority: "use the canonical      │
 * │ Credit Note authority."                                                    │
 * └──────────────────────────────────────────────────────────────────────────┘
 *
 * Idempotency: `finance_customer_invoices.source_type = 'woo_refund'`,
 * `source_id = "{channel}:{external_order_id}:{woo_refund_id}"` — one Woo
 * refund id can never post more than one Credit Note, DB-enforced by the
 * unique index added alongside this class (migration
 * 2026_09_12_150000_add_unique_source_reference_to_finance_customer_invoices_table).
 * A later, separate Woo refund id is a separate legitimate key and therefore a
 * separate Credit Note — this is how partial refunds accumulate correctly.
 *
 * Money arithmetic: every comparison and every derived amount (the over-refund
 * ceiling, the already-refunded sum, the proportional line split) is computed
 * with bcmath over the DECIMAL(*, 4) string values the database itself already
 * returns — never via float addition/subtraction/comparison. A float value is
 * produced only once, at the very end, to satisfy AccountsReceivableService's
 * existing float-typed parameters (its own established contract, unchanged by
 * this task) — by which point it is an exact string-rounded amount, not an
 * accumulation of float arithmetic.
 */
final class WooRefundApplicationService
{
    private const SOURCE_TYPE = 'woo_refund';

    private const SCALE = 4;

    public function __construct(
        private readonly CommercialAccountingService $commercialAccounting,
        private readonly AccountsReceivableService $ar,
        private readonly SyncLogService $logService,
    ) {}

    /**
     * @param  array{id: int|string, reason?: string|null, total: string|float, currency?: string|null}  $wooRefundSummary
     *                                                                                                                      One entry from the WooCommerce order's own `refunds` array, exactly as
     *                                                                                                                      WooCommerce's REST API v3 Order resource already represents it, plus
     *                                                                                                                      the parent order's own `currency` (WooCommerce refunds carry no
     *                                                                                                                      separate currency of their own) attached by the caller.
     */
    public function applyRefund(Channel $channel, Order $order, array $wooRefundSummary): WooRefundOutcome
    {
        $wooRefundId = (string) $wooRefundSummary['id'];
        $familyPrefix = $this->refundFamilyPrefix($channel, $order);
        $sourceId = $familyPrefix.$wooRefundId;

        $startedAt = microtime(true);

        $log = $this->logService->createLog(
            $channel,
            SyncEntityType::Order,
            SyncDirection::Inbound,
            'order.refund_processed',
            $order->id,
            SyncStatus::Processing,
            [
                'external_order_id' => $order->external_order_id,
                'woo_refund_id' => $wooRefundId,
                'source_id' => $sourceId,
                'requested_amount' => $wooRefundSummary['total'] ?? null,
            ],
            // NOT $sourceId: correlation_id is varchar(36), sized for exactly one UUID per
            // this codebase's existing Phase-B domain-event convention (see the migration
            // that added it) — the full "{channel}:{external_order_id}:{woo_refund_id}"
            // composite key routinely exceeds that width and was rejected by the database
            // the first time this ran against a real schema. The composite key is already
            // fully recorded, queryable, in request_payload above; only a genuine UUID
            // belongs in correlation_id, so this passes none rather than truncating one.
            correlationId: null,
        );

        $financial = $this->applyFinancial($order, $sourceId, $familyPrefix, $wooRefundId, $wooRefundSummary);

        $outcome = new WooRefundOutcome(
            financialStatus: $financial['status'],
            financialMessage: $financial['message'],
            creditNoteUuid: $financial['credit_note_uuid'] ?? null,
            amount: $financial['amount'] ?? null,
            currency: $financial['currency'] ?? null,
            physicalReturnStatus: 'not_evaluated_no_reliable_evidence',
            physicalReturnMessage: 'WooCommerce\'s standard refund payload carries no trustworthy physical-return '
                .'evidence (a refunded line-item quantity is a commercial/accounting allocation, not a warehouse '
                .'receipt). Physical return is never inferred here; it remains the canonical ECOS warehouse/driver '
                .'flow, triggered only by ECOS\'s own operational events.',
        );

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        // Never a "failed" log purely for reconciliation_required/over_refund_rejected/
        // currency_mismatch — those are expected, explicit, non-exceptional outcomes this
        // method is designed to produce; a real Throwable is the only Failed case, and it
        // is left to propagate uncaught (see class docblock and the job's own try/catch).
        $this->logService->markSuccess($log, $outcome->toLogPayload(), $channel, $durationMs);

        return $outcome;
    }

    /**
     * @return array{status: string, message: string, credit_note_uuid?: string, amount?: float, currency?: string}
     */
    private function applyFinancial(Order $order, string $sourceId, string $familyPrefix, string $wooRefundId, array $wooRefundSummary): array
    {
        $companyId = (string) $order->company_id;

        $existing = CustomerInvoice::query()
            ->where('company_id', $companyId)
            ->where('document_type', CustomerDocumentType::CreditNote->value)
            ->where('source_type', self::SOURCE_TYPE)
            ->where('source_id', $sourceId)
            ->first();

        if ($existing !== null && $existing->isPosted()) {
            return [
                'status' => 'idempotent_replay',
                'message' => "Woo refund [{$wooRefundId}] was already applied as Credit Note {$existing->number}; no new financial effect.",
                'credit_note_uuid' => $existing->uuid,
                'amount' => (float) $existing->total,
                'currency' => $existing->currency,
            ];
        }

        // A Draft row with this exact key already exists: createDocument() succeeded on a
        // prior attempt but postDocument() then failed (e.g. a control account was missing
        // at that moment) — the durable, visible recovery state this design commits to (see
        // class docblock). Resume by posting the SAME row rather than creating a second one
        // for the same Woo refund id, so a retry after the underlying issue is fixed actually
        // completes instead of being permanently reported as "already applied".
        if ($existing !== null) {
            $posted = $this->ar->postDocument($existing);

            return [
                'status' => 'posted',
                'message' => "Credit Note {$posted->number} (retry) posted for {$posted->total} {$posted->currency} — resumed from a prior draft.",
                'credit_note_uuid' => $posted->uuid,
                'amount' => (float) $posted->total,
                'currency' => $posted->currency,
            ];
        }

        $invoice = $this->commercialAccounting->findOrderInvoice($companyId, $order->id);

        if ($invoice === null || ! $invoice->isPosted()) {
            return [
                'status' => 'reconciliation_required',
                'message' => "Order [{$order->id}] has no posted CustomerInvoice to reverse. Woo refund [{$wooRefundId}] cannot be posted to Finance automatically; requires manual reconciliation.",
            ];
        }

        $invoice->loadMissing('lines');
        $primaryLine = $invoice->lines->first();

        if ($primaryLine === null) {
            return [
                'status' => 'reconciliation_required',
                'message' => "Invoice {$invoice->number} has no lines to proportion a refund against. Woo refund [{$wooRefundId}] requires manual reconciliation.",
            ];
        }

        $wooCurrency = isset($wooRefundSummary['currency']) ? (string) $wooRefundSummary['currency'] : null;

        if ($wooCurrency !== null && $wooCurrency !== '' && strcasecmp($wooCurrency, (string) $invoice->currency) !== 0) {
            return [
                'status' => 'currency_mismatch',
                'message' => "Woo refund [{$wooRefundId}] is denominated in [{$wooCurrency}] but invoice {$invoice->number} is [{$invoice->currency}]. Refusing to post — currency is never silently converted.",
            ];
        }

        // ── Exact decimal arithmetic from here on (bcmath, scale=4) — no float comparisons. ──
        $requestedStr = $this->decimalString($wooRefundSummary['total']);

        if (bccomp($requestedStr, '0', self::SCALE) <= 0) {
            return [
                'status' => 'reconciliation_required',
                'message' => "Woo refund [{$wooRefundId}] carries a non-positive amount ({$requestedStr}); refusing to post.",
            ];
        }

        $invoiceTotalStr = $this->decimalString($invoice->total);

        $alreadyRefundedRaw = CustomerInvoice::query()
            ->where('company_id', $companyId)
            ->where('document_type', CustomerDocumentType::CreditNote->value)
            ->where('source_type', self::SOURCE_TYPE)
            ->where('source_id', 'like', $familyPrefix.'%')
            ->sum('total');
        $alreadyRefundedStr = $this->decimalString($alreadyRefundedRaw);

        $refundableCeilingStr = bcsub($invoiceTotalStr, $alreadyRefundedStr, self::SCALE);

        if (bccomp($requestedStr, $refundableCeilingStr, self::SCALE) > 0) {
            return [
                'status' => 'over_refund_rejected',
                'message' => "Woo refund [{$wooRefundId}] requests {$requestedStr} but only {$refundableCeilingStr} remains refundable on invoice {$invoice->number} (already refunded: {$alreadyRefundedStr}). No Finance mutation applied.",
            ];
        }

        // Proportional split, exact: ratio = requested / invoice.total (bc division at
        // extra precision, then the line amounts rounded to SCALE only at the final step).
        $primaryNetStr = $this->decimalString($primaryLine->net_amount);
        $primaryTaxStr = $this->decimalString($primaryLine->tax_amount);

        if (bccomp($invoiceTotalStr, '0', self::SCALE) > 0) {
            $ratio = bcdiv($requestedStr, $invoiceTotalStr, self::SCALE + 6);
            $netPortionStr = bcmul($primaryNetStr, $ratio, self::SCALE + 6);
            $taxPortionStr = bcmul($primaryTaxStr, $ratio, self::SCALE + 6);
        } else {
            $netPortionStr = '0';
            $taxPortionStr = '0';
        }

        $netPortion = round((float) $netPortionStr, self::SCALE);
        $taxPortion = round((float) $taxPortionStr, self::SCALE);

        $line = [
            'revenue_account_id' => $primaryLine->revenue_account_id,
            'description' => "WooCommerce refund #{$wooRefundId} — Order {$order->order_number}",
            'net_amount' => $netPortion,
        ];

        if ($taxPortion > 0.0 && $primaryLine->tax_account_id !== null) {
            $line['tax_amount'] = $taxPortion;
            $line['tax_account_id'] = $primaryLine->tax_account_id;
        }

        $reason = trim((string) ($wooRefundSummary['reason'] ?? ''));
        $number = 'CN-WOO-'.$order->order_number.'-'.$wooRefundId;

        try {
            $creditNote = $this->ar->createDocument(
                companyId: $companyId,
                customerId: (string) $order->customer_id,
                number: $number,
                documentDate: now(),
                lines: [$line],
                type: CustomerDocumentType::CreditNote,
                currency: $invoice->currency,
                description: $reason !== '' ? "WooCommerce refund: {$reason}" : 'WooCommerce refund',
                sourceType: self::SOURCE_TYPE,
                sourceId: $sourceId,
            );
        } catch (QueryException $e) {
            // Lost a race to another delivery of the same refund event — the unique
            // constraint on (company_id, source_type, source_id) is what actually
            // guarantees "same event twice -> one financial effect"; this check-then-
            // create is only the fast path. Re-fetch and report the winner's result.
            $winner = CustomerInvoice::query()
                ->where('company_id', $companyId)
                ->where('document_type', CustomerDocumentType::CreditNote->value)
                ->where('source_type', self::SOURCE_TYPE)
                ->where('source_id', $sourceId)
                ->first();

            if ($winner === null) {
                throw $e; // a genuinely different failure — do not swallow it
            }

            return [
                'status' => 'idempotent_replay',
                'message' => "Woo refund [{$wooRefundId}] was concurrently applied as Credit Note {$winner->number}; no new financial effect.",
                'credit_note_uuid' => $winner->uuid,
                'amount' => (float) $winner->total,
                'currency' => $winner->currency,
            ];
        }

        // Not wrapped in a single transaction with createDocument() above: postDocument()
        // has its own existing transaction boundary (unchanged, per AccountsReceivableService).
        // If this throws, the Draft Credit Note remains — a visible, queryable state
        // (finance_customer_invoices.status='draft'), not a silent half-applied one, and the
        // "$existing !== null" branch above resumes posting the SAME row on the next retry
        // once the underlying issue is fixed, rather than leaving it stuck or duplicating it.
        $posted = $this->ar->postDocument($creditNote);

        return [
            'status' => 'posted',
            'message' => "Credit Note {$posted->number} posted for {$requestedStr} {$posted->currency} against invoice {$invoice->number}.",
            'credit_note_uuid' => $posted->uuid,
            'amount' => (float) $posted->total,
            'currency' => $posted->currency,
        ];
    }

    /**
     * Render any numeric-ish value (a DECIMAL-cast Eloquent attribute, a raw
     * SQL sum() result, or a WooCommerce REST payload figure) as a plain
     * decimal string bcmath can consume exactly — never through a float.
     */
    private function decimalString(mixed $value): string
    {
        if ($value === null) {
            return '0';
        }

        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? '0' : $trimmed;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        // Only reached for a value that arrived as a PHP float/double already
        // (e.g. a test fixture) — number_format at the model's own SCALE avoids
        // scientific notation and matches the DB column's own precision.
        return number_format((float) $value, self::SCALE, '.', '');
    }

    /** The stable "this order's Woo refunds" family key — a specific refund id is appended to it. */
    private function refundFamilyPrefix(Channel $channel, Order $order): string
    {
        return "{$channel->id}:{$order->external_order_id}:";
    }
}
