<?php

declare(strict_types=1);

namespace Modules\POS\Returns\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\POS\Returns\Domain\Exceptions\InvalidReturnTransitionException;
use Modules\POS\Returns\Domain\ValueObjects\ReturnLine;
use Modules\POS\Shared\Domain\Enums\PaymentMethodType;
use Modules\POS\Shared\Domain\Enums\ReturnStatus;
use Modules\POS\Shared\Domain\ValueObjects\Money;

/**
 * SaleReturn Aggregate Root.
 *
 * Records a customer return against a previously completed Sale.
 * The aggregate holds a snapshot of which items are being returned;
 * it never reads from the live Sale after initiation.
 *
 * State machine:
 *   Pending ──process()──▶ Processed  (terminal)
 *   Pending ──cancel()───▶ Cancelled  (terminal)
 */
final class SaleReturn extends Model
{
    use HasUuids;

    protected $table = 'pos_returns';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status'        => ReturnStatus::class,
            'refund_method' => PaymentMethodType::class,
            'lines'         => 'array',
            'refund_total'  => 'array',
            'metadata'      => 'array',
            'processed_at'  => 'datetime',
            'cancelled_at'  => 'datetime',
        ];
    }

    // ── Factory ───────────────────────────────────────────────────────────────

    /**
     * Initiate a return against a previously completed Sale.
     *
     * @param ReturnLine[] $lines
     */
    public static function initiate(
        string            $saleId,
        string            $originalReceiptNumber,
        string            $sessionId,
        string            $shiftId,
        string            $terminalId,
        string            $cashierId,
        ?string           $customerId,
        string            $currency,
        string            $returnNumber,
        array             $lines,
        Money             $refundTotal,
        PaymentMethodType $refundMethod,
        ?string           $notes = null,
    ): self {
        if (trim($saleId) === '') {
            throw new \InvalidArgumentException('Sale ID cannot be empty.');
        }
        if (trim($originalReceiptNumber) === '') {
            throw new \InvalidArgumentException('Original receipt number cannot be empty.');
        }
        if (trim($sessionId) === '') {
            throw new \InvalidArgumentException('Session ID cannot be empty.');
        }
        if (trim($shiftId) === '') {
            throw new \InvalidArgumentException('Shift ID cannot be empty.');
        }
        if (trim($terminalId) === '') {
            throw new \InvalidArgumentException('Terminal ID cannot be empty.');
        }
        if (trim($cashierId) === '') {
            throw new \InvalidArgumentException('Cashier ID cannot be empty.');
        }
        if (trim($returnNumber) === '') {
            throw new \InvalidArgumentException('Return number cannot be empty.');
        }
        if (empty($lines)) {
            throw new \InvalidArgumentException('Return must have at least one line item.');
        }
        if (!$refundTotal->isPositive()) {
            throw new \InvalidArgumentException('Refund total must be positive.');
        }

        $saleReturn                         = new self();
        $saleReturn->sale_id                = $saleId;
        $saleReturn->original_receipt_number = trim($originalReceiptNumber);
        $saleReturn->session_id             = $sessionId;
        $saleReturn->shift_id               = $shiftId;
        $saleReturn->terminal_id            = $terminalId;
        $saleReturn->cashier_id             = $cashierId;
        $saleReturn->customer_id            = $customerId;
        $saleReturn->currency               = strtoupper(trim($currency));
        $saleReturn->return_number          = trim($returnNumber);
        $saleReturn->status                 = ReturnStatus::Pending;
        $saleReturn->lines                  = array_values(
            array_map(fn(ReturnLine $l) => $l->toArray(), $lines)
        );
        $saleReturn->refund_total           = $refundTotal->toArray();
        $saleReturn->refund_method          = $refundMethod;
        $saleReturn->notes                  = $notes;
        $saleReturn->processed_at           = null;
        $saleReturn->cancelled_at           = null;
        $saleReturn->cancelled_reason       = null;

        return $saleReturn;
    }

    // ── State transitions ─────────────────────────────────────────────────────

    /** Mark the return as processed: items received, refund issued. Pending → Processed. */
    public function process(): void
    {
        if (!$this->status->canBeProcessed()) {
            throw InvalidReturnTransitionException::cannotProcess($this->id ?? '', $this->status);
        }

        $this->status       = ReturnStatus::Processed;
        $this->processed_at = now();
    }

    /** Cancel a pending return before it is processed. Pending → Cancelled. */
    public function cancel(string $reason = ''): void
    {
        if (!$this->status->canBeCancelled()) {
            throw InvalidReturnTransitionException::cannotCancel($this->id ?? '', $this->status);
        }

        $this->status           = ReturnStatus::Cancelled;
        $this->cancelled_at     = now();
        $this->cancelled_reason = $reason;
    }

    // ── Getters ───────────────────────────────────────────────────────────────

    /** @return ReturnLine[] */
    public function getLines(): array
    {
        return array_map(
            fn(array $d) => ReturnLine::fromArray($d),
            $this->lines ?? [],
        );
    }

    public function getRefundTotal(): Money
    {
        return Money::fromArray($this->refund_total);
    }

    public function getLineCount(): int
    {
        return count($this->lines ?? []);
    }

    public function isPending(): bool   { return $this->status === ReturnStatus::Pending; }
    public function isProcessed(): bool { return $this->status === ReturnStatus::Processed; }
    public function isCancelled(): bool { return $this->status === ReturnStatus::Cancelled; }
}
