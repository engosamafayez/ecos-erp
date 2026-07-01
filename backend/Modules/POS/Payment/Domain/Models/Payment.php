<?php

declare(strict_types=1);

namespace Modules\POS\Payment\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\POS\Payment\Domain\Enums\PaymentStatus;
use Modules\POS\Payment\Domain\Exceptions\InsufficientPaymentException;
use Modules\POS\Payment\Domain\Exceptions\InvalidPaymentStateException;
use Modules\POS\Payment\Domain\ValueObjects\PaymentTender;
use Modules\POS\Shared\Domain\Enums\PaymentMethodType;
use Modules\POS\Shared\Domain\ValueObjects\Money;

final class Payment extends Model
{
    use HasUuids;

    protected $table = 'pos_payments';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'status'          => PaymentStatus::class,
            'cart_total'      => 'array',
            'tenders'         => 'array',
            'amount_tendered' => 'array',
            'change_due'      => 'array',
            'metadata'        => 'array',
            'captured_at'     => 'datetime',
        ];
    }

    // ── Factory ───────────────────────────────────────────────────────────────

    public static function initiate(
        string $cartId,
        string $sessionId,
        string $shiftId,
        string $terminalId,
        string $cashierId,
        Money  $cartTotal,
    ): self {
        if (trim($cartId) === '') {
            throw new \InvalidArgumentException('Cart ID cannot be empty.');
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

        $currency = $cartTotal->currency;

        $payment                  = new self();
        $payment->cart_id         = $cartId;
        $payment->session_id      = $sessionId;
        $payment->shift_id        = $shiftId;
        $payment->terminal_id     = $terminalId;
        $payment->cashier_id      = $cashierId;
        $payment->status          = PaymentStatus::Pending;
        $payment->currency        = $currency;
        $payment->cart_total      = $cartTotal->toArray();
        $payment->tenders         = [];
        $payment->amount_tendered = Money::zero($currency)->toArray();
        $payment->change_due      = Money::zero($currency)->subtract($cartTotal)->toArray();

        return $payment;
    }

    // ── Tender Management ─────────────────────────────────────────────────────

    public function addTender(
        PaymentMethodType $type,
        Money             $amount,
        ?string           $reference = null,
        array             $metadata  = [],
    ): string {
        $this->guardPending();
        $this->guardSameCurrency($amount);

        $tender    = PaymentTender::create($type, $amount, $reference, $metadata);
        $tenders   = $this->getTenders();
        $tenders[] = $tender;
        $this->setTenders($tenders);
        $this->recalculateAmounts();

        return $tender->id;
    }

    public function removeTender(string $tenderId): void
    {
        $this->guardPending();

        $tenders  = $this->getTenders();
        $filtered = array_values(
            array_filter($tenders, fn(PaymentTender $t) => $t->id !== $tenderId)
        );

        if (count($filtered) === count($tenders)) {
            throw InvalidPaymentStateException::tenderNotFound($this->id ?? '', $tenderId);
        }

        $this->setTenders($filtered);
        $this->recalculateAmounts();
    }

    // ── State Transition ──────────────────────────────────────────────────────

    public function capture(): void
    {
        if ($this->status !== PaymentStatus::Pending) {
            throw InvalidPaymentStateException::alreadyCaptured($this->id ?? '');
        }

        if (!$this->isFullyPaid()) {
            throw InsufficientPaymentException::forCapture(
                $this->id ?? '',
                $this->getCartTotal(),
                $this->getAmountTendered(),
            );
        }

        $this->status      = PaymentStatus::Captured;
        $this->captured_at = now();
    }

    // ── Getters ───────────────────────────────────────────────────────────────

    /** @return PaymentTender[] */
    public function getTenders(): array
    {
        return array_map(
            fn(array $d) => PaymentTender::fromArray($d),
            $this->tenders ?? [],
        );
    }

    public function getCartTotal(): Money
    {
        return Money::fromArray($this->cart_total);
    }

    public function getAmountTendered(): Money
    {
        return Money::fromArray($this->amount_tendered);
    }

    public function getChangeDue(): Money
    {
        return Money::fromArray($this->change_due);
    }

    public function getRemainingBalance(): Money
    {
        return $this->getCartTotal()->subtract($this->getAmountTendered());
    }

    public function isFullyPaid(): bool
    {
        return $this->getAmountTendered()->isGreaterThanOrEqual($this->getCartTotal());
    }

    public function getTenderCount(): int
    {
        return count($this->tenders ?? []);
    }

    public function hasTenders(): bool
    {
        return $this->getTenderCount() > 0;
    }

    public function isPending(): bool
    {
        return $this->status === PaymentStatus::Pending;
    }

    public function isCaptured(): bool
    {
        return $this->status === PaymentStatus::Captured;
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function setTenders(array $paymentTenders): void
    {
        $this->tenders = array_values(
            array_map(fn(PaymentTender $t) => $t->toArray(), $paymentTenders),
        );
    }

    private function recalculateAmounts(): void
    {
        $tendered  = Money::zero($this->currency);
        $cartTotal = $this->getCartTotal();

        foreach ($this->getTenders() as $tender) {
            $tendered = $tendered->add($tender->amount);
        }

        $this->amount_tendered = $tendered->toArray();
        $this->change_due      = $tendered->subtract($cartTotal)->toArray();
    }

    private function guardPending(): void
    {
        if ($this->status !== PaymentStatus::Pending) {
            throw InvalidPaymentStateException::cannotModifyTenders($this->id ?? '', $this->status);
        }
    }

    private function guardSameCurrency(Money $money): void
    {
        if ($money->currency !== $this->currency) {
            throw new \InvalidArgumentException(
                "Currency mismatch: payment uses \"{$this->currency}\" but received \"{$money->currency}\"."
            );
        }
    }
}
