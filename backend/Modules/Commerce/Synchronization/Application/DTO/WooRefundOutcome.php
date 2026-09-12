<?php

declare(strict_types=1);

namespace Modules\Commerce\Synchronization\Application\DTO;

/**
 * Result of applying one WooCommerce refund event. Financial-only — see
 * WooRefundApplicationService's class docblock for why physical return is
 * never decided here: WooCommerce's standard refund payload carries no
 * trustworthy warehouse-receipt evidence, only a commercial/accounting
 * allocation. `physicalReturnStatus` is therefore always
 * `not_evaluated_no_reliable_evidence` today — the field is kept (rather than
 * removed) so a future channel/plugin capability that DOES supply trustworthy
 * evidence has a place to report through, without changing this DTO's shape.
 */
final class WooRefundOutcome
{
    public function __construct(
        /** posted | idempotent_replay | reconciliation_required | over_refund_rejected | currency_mismatch */
        public readonly string $financialStatus,
        public readonly string $financialMessage,
        public readonly ?string $creditNoteUuid,
        public readonly ?float $amount,
        public readonly ?string $currency,
        /** always 'not_evaluated_no_reliable_evidence' today — see class docblock above */
        public readonly string $physicalReturnStatus,
        public readonly string $physicalReturnMessage,
    ) {}

    public function financiallyMutated(): bool
    {
        return $this->financialStatus === 'posted';
    }

    /** @return array<string, mixed> */
    public function toLogPayload(): array
    {
        return [
            'financial_status' => $this->financialStatus,
            'financial_message' => $this->financialMessage,
            'credit_note_uuid' => $this->creditNoteUuid,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'physical_return_status' => $this->physicalReturnStatus,
            'physical_return_message' => $this->physicalReturnMessage,
        ];
    }
}
