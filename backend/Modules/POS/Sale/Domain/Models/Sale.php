<?php

declare(strict_types=1);

namespace Modules\POS\Sale\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\POS\Sale\Domain\Exceptions\InvalidSaleTransitionException;
use Modules\POS\Sale\Domain\ValueObjects\PaymentSummaryLine;
use Modules\POS\Sale\Domain\ValueObjects\SaleLine;
use Modules\POS\Shared\Domain\Enums\SaleStatus;
use Modules\POS\Shared\Domain\ValueObjects\Money;

final class Sale extends Model
{
    use HasUuids;

    protected $table = 'pos_sales';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status'            => SaleStatus::class,
            'lines'             => 'array',
            'subtotal'          => 'array',
            'discount_total'    => 'array',
            'total'             => 'array',
            'amount_paid'       => 'array',
            'change_given'      => 'array',
            'payment_summaries' => 'array',
            'metadata'          => 'array',
            'completed_at'      => 'datetime',
            'voided_at'         => 'datetime',
        ];
    }

    // ── Factory ───────────────────────────────────────────────────────────────

    /**
     * Record a permanent transaction snapshot from a completed Cart + captured Payment.
     * Created in Pending state; call complete() to confirm the record.
     *
     * @param SaleLine[]          $lines
     * @param PaymentSummaryLine[] $paymentSummaries
     */
    public static function record(
        string  $cartId,
        string  $paymentId,
        string  $sessionId,
        string  $shiftId,
        string  $terminalId,
        string  $cashierId,
        ?string $customerId,
        string  $currency,
        string  $receiptNumber,
        array   $lines,
        Money   $subtotal,
        Money   $discountTotal,
        Money   $total,
        Money   $amountPaid,
        Money   $changeGiven,
        array   $paymentSummaries,
    ): self {
        if (trim($cartId) === '') {
            throw new \InvalidArgumentException('Cart ID cannot be empty.');
        }
        if (trim($paymentId) === '') {
            throw new \InvalidArgumentException('Payment ID cannot be empty.');
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
        if (trim($receiptNumber) === '') {
            throw new \InvalidArgumentException('Receipt number cannot be empty.');
        }
        if (empty($lines)) {
            throw new \InvalidArgumentException('Sale must have at least one line item.');
        }
        if (empty($paymentSummaries)) {
            throw new \InvalidArgumentException('Sale must have at least one payment summary.');
        }
        if ($changeGiven->isNegative()) {
            throw new \InvalidArgumentException('Change given cannot be negative.');
        }

        $sale                   = new self();
        $sale->cart_id          = $cartId;
        $sale->payment_id       = $paymentId;
        $sale->session_id       = $sessionId;
        $sale->shift_id         = $shiftId;
        $sale->terminal_id      = $terminalId;
        $sale->cashier_id       = $cashierId;
        $sale->customer_id      = $customerId;
        $sale->status           = SaleStatus::Pending;
        $sale->currency         = strtoupper(trim($currency));
        $sale->receipt_number   = trim($receiptNumber);
        $sale->subtotal         = $subtotal->toArray();
        $sale->discount_total   = $discountTotal->toArray();
        $sale->total            = $total->toArray();
        $sale->amount_paid      = $amountPaid->toArray();
        $sale->change_given     = $changeGiven->toArray();
        $sale->completed_at     = null;
        $sale->voided_at        = null;
        $sale->voided_reason    = null;
        $sale->lines            = array_values(
            array_map(fn(SaleLine $l) => $l->toArray(), $lines)
        );
        $sale->payment_summaries = array_values(
            array_map(fn(PaymentSummaryLine $p) => $p->toArray(), $paymentSummaries)
        );

        return $sale;
    }

    // ── State Transitions ─────────────────────────────────────────────────────

    public function complete(): void
    {
        if ($this->status !== SaleStatus::Pending) {
            throw InvalidSaleTransitionException::notPending($this->id ?? '', $this->status);
        }

        $this->status       = SaleStatus::Completed;
        $this->completed_at = now();
    }

    public function void(string $reason = ''): void
    {
        if (!$this->status->canBeVoided()) {
            throw InvalidSaleTransitionException::cannotVoid($this->id ?? '', $this->status);
        }

        $this->status       = SaleStatus::Voided;
        $this->voided_at    = now();
        $this->voided_reason = $reason;
    }

    public function markRefunded(): void
    {
        if (!$this->status->canBeRefunded()) {
            throw InvalidSaleTransitionException::cannotRefund($this->id ?? '', $this->status);
        }

        $this->status = SaleStatus::Refunded;
    }

    public function markPartiallyRefunded(): void
    {
        if ($this->status !== SaleStatus::Completed) {
            throw InvalidSaleTransitionException::cannotPartiallyRefund($this->id ?? '', $this->status);
        }

        $this->status = SaleStatus::PartiallyRefunded;
    }

    // ── Getters ───────────────────────────────────────────────────────────────

    /** @return SaleLine[] */
    public function getLines(): array
    {
        return array_map(
            fn(array $d) => SaleLine::fromArray($d),
            $this->lines ?? [],
        );
    }

    /** @return PaymentSummaryLine[] */
    public function getPaymentSummaries(): array
    {
        return array_map(
            fn(array $d) => PaymentSummaryLine::fromArray($d),
            $this->payment_summaries ?? [],
        );
    }

    public function getSubtotal(): Money
    {
        return Money::fromArray($this->subtotal);
    }

    public function getDiscountTotal(): Money
    {
        return Money::fromArray($this->discount_total);
    }

    public function getTotal(): Money
    {
        return Money::fromArray($this->total);
    }

    public function getAmountPaid(): Money
    {
        return Money::fromArray($this->amount_paid);
    }

    public function getChangeGiven(): Money
    {
        return Money::fromArray($this->change_given);
    }

    public function getLineCount(): int
    {
        return count($this->lines ?? []);
    }

    public function isPending(): bool          { return $this->status === SaleStatus::Pending; }
    public function isCompleted(): bool        { return $this->status === SaleStatus::Completed; }
    public function isVoided(): bool           { return $this->status === SaleStatus::Voided; }
    public function isRefunded(): bool         { return $this->status === SaleStatus::Refunded; }
    public function isPartiallyRefunded(): bool { return $this->status === SaleStatus::PartiallyRefunded; }
}
