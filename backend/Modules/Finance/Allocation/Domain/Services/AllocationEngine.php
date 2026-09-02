<?php

declare(strict_types=1);

namespace Modules\Finance\Allocation\Domain\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Ledger\Domain\Exceptions\FinanceException;
use Modules\Finance\Payables\Domain\Models\PaymentAllocation;
use Modules\Finance\Payables\Domain\Models\SupplierBill;
use Modules\Finance\Payables\Domain\Models\SupplierPayment;
use Modules\Finance\Receivables\Domain\Models\CustomerInvoice;
use Modules\Finance\Receivables\Domain\Models\CustomerReceipt;
use Modules\Finance\Receivables\Domain\Models\ReceiptAllocation;

/**
 * The Allocation Engine — matches money to the documents it settles.
 *
 * ┌─ APPEND-ONLY MATCHING · DERIVED OUTSTANDING · NO GL WRITES ──────────────┐
 * │ Allocation is a pure subledger relationship: it never posts a journal.    │
 * │ The GL already moved when the receipt/payment and the invoice/bill posted; │
 * │ allocation only records WHICH open document a receipt settles. Every       │
 * │ balance — receipt remainder, invoice outstanding, on-account — is the SUM  │
 * │ of these rows, computed on read. Partial, full and multiple-document       │
 * │ matching all fall out of one guarded primitive.                           │
 * └──────────────────────────────────────────────────────────────────────────┘
 */
final class AllocationEngine
{
    // ── Accounts Receivable ─────────────────────────────────────────────────────

    /** Apply part (or all) of a posted receipt to one posted invoice. */
    public function allocateReceipt(
        CustomerReceipt $receipt,
        CustomerInvoice $invoice,
        float $amount,
        ?int $actorId = null,
    ): ReceiptAllocation {
        $amount = round($amount, 4);

        $this->assertPositive($amount);
        $this->assertPosted('Receipt', $receipt->number, $receipt->isPosted());
        $this->assertPosted($invoice->document_type->label(), $invoice->number, $invoice->isPosted());

        if ($receipt->customer_id !== $invoice->customer_id) {
            throw FinanceException::allocationPartyMismatch();
        }

        return DB::transaction(function () use ($receipt, $invoice, $amount, $actorId): ReceiptAllocation {
            // CONCURRENCY: re-derive availability under a PESSIMISTIC lock, not a plain
            // ->fresh(). A non-locking re-read only re-reads this transaction's snapshot,
            // so two receipts could each read outstanding = 500 and both allocate 400 —
            // 800 against a 500 receivable (write skew). Locking the two aggregate rows the
            // constraints derive from — the receipt (its unallocated balance) and the
            // invoice (its outstanding) — FOR UPDATE makes a second allocator touching the
            // same receipt or invoice block until the first commits, then observe its
            // allocation. Both sums are read AFTER the locks are held, so they reflect
            // committed state. The receipt is locked before the invoice on every path, so
            // the order is consistent and cannot deadlock against itself. (Mirrors the
            // approved AP allocatePayment fix — the source is locked before the document.)
            $receipt = CustomerReceipt::query()->whereKey($receipt->id)->lockForUpdate()->firstOrFail();
            $invoice = CustomerInvoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            $available = $receipt->unallocatedAmount();
            if ($amount > $available) {
                throw FinanceException::allocationExceedsSource('receipt', (string) $available);
            }

            $outstanding = $invoice->outstanding();
            if ($amount > $outstanding) {
                throw FinanceException::allocationExceedsDocument($invoice->document_type->label().' '.$invoice->number, (string) $outstanding);
            }

            return ReceiptAllocation::create([
                'company_id' => $receipt->company_id,
                'receipt_id' => $receipt->id,
                'customer_invoice_id' => $invoice->id,
                'amount' => $amount,
                'allocated_at' => Carbon::now(),
                'allocated_by' => $actorId,
            ]);
        });
    }

    /**
     * Auto-allocate a receipt across a customer's open invoices, oldest first
     * (FIFO). Stops when the receipt is exhausted; any remainder stays on
     * account. Returns the allocations created.
     *
     * @return list<ReceiptAllocation>
     */
    public function autoAllocateReceipt(CustomerReceipt $receipt, ?int $actorId = null): array
    {
        $receipt = $receipt->fresh();
        $remaining = $receipt->unallocatedAmount();
        if ($remaining <= 0.0) {
            return [];
        }

        $invoices = CustomerInvoice::query()
            ->where('company_id', $receipt->company_id)
            ->where('customer_id', $receipt->customer_id)
            ->where('status', 'posted')
            ->where('document_type', 'invoice')
            ->orderBy('due_date')
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get();

        $created = [];
        foreach ($invoices as $invoice) {
            if ($remaining <= 0.0) {
                break;
            }

            $outstanding = $invoice->outstanding();
            if ($outstanding <= 0.0) {
                continue;
            }

            $apply = round(min($remaining, $outstanding), 4);
            $created[] = $this->allocateReceipt($receipt, $invoice, $apply, $actorId);
            $remaining = round($remaining - $apply, 4);
        }

        return $created;
    }

    /**
     * Reverse part (or all) of a posted receipt allocation with a new,
     * negative, append-only row against the SAME receipt+invoice pair — the
     * original row is never edited or deleted (see ReceiptAllocation::booted()).
     * A row that is itself a reversal cannot be reversed again; correct it
     * with a fresh ordinary allocation instead (one-step correction only).
     */
    public function reverseReceiptAllocation(
        ReceiptAllocation $allocation,
        float $amount,
        string $reason,
        ?int $actorId = null,
    ): ReceiptAllocation {
        $amount = round($amount, 4);

        $this->assertPositive($amount);
        $this->assertReasonGiven($reason);
        $this->assertNotAlreadyAReversal($allocation->reverses_allocation_id !== null);

        return DB::transaction(function () use ($allocation, $amount, $reason, $actorId): ReceiptAllocation {
            // Same lock order as allocateReceipt: receipt before invoice — a
            // correction racing a fresh allocation on the same pair serializes
            // on the identical row locks, so neither can over-allocate or
            // over-reverse the same available amount.
            $receipt = CustomerReceipt::query()->whereKey($allocation->receipt_id)->lockForUpdate()->firstOrFail();
            $invoice = CustomerInvoice::query()->whereKey($allocation->customer_invoice_id)->lockForUpdate()->firstOrFail();

            // Re-derive under the lock: how much of THIS specific original
            // allocation remains unreversed. Read after the lock is held, so it
            // reflects committed state even under concurrent reversal attempts.
            $alreadyReversed = round((float) $allocation->reversals()->sum('amount') * -1, 4);
            $reversible = round((float) $allocation->amount - $alreadyReversed, 4);
            if ($amount > $reversible) {
                throw FinanceException::reversalExceedsAllocation((string) $reversible);
            }

            return ReceiptAllocation::create([
                'company_id' => $receipt->company_id,
                'receipt_id' => $receipt->id,
                'customer_invoice_id' => $invoice->id,
                'amount' => -$amount,
                'reverses_allocation_id' => $allocation->id,
                'reversal_reason' => $reason,
                'allocated_at' => Carbon::now(),
                'allocated_by' => $actorId,
            ]);
        });
    }

    // ── Accounts Payable ────────────────────────────────────────────────────────

    /** Apply part (or all) of a posted payment to one posted bill. */
    public function allocatePayment(
        SupplierPayment $payment,
        SupplierBill $bill,
        float $amount,
        ?int $actorId = null,
    ): PaymentAllocation {
        $amount = round($amount, 4);

        $this->assertPositive($amount);
        $this->assertPosted('Payment', $payment->number, $payment->isPosted());
        $this->assertPosted($bill->document_type->label(), $bill->number, $bill->isPosted());

        if ($payment->supplier_id !== $bill->supplier_id) {
            throw FinanceException::allocationPartyMismatch();
        }

        return DB::transaction(function () use ($payment, $bill, $amount, $actorId): PaymentAllocation {
            // CONCURRENCY: re-derive availability under a PESSIMISTIC lock, not a plain
            // ->fresh(). A non-locking re-read only re-reads this transaction's snapshot,
            // so two payments could each read outstanding = 500 and both allocate 400 —
            // 800 against a 500 payable (write skew). Locking the two aggregate rows the
            // constraints derive from — the payment (its unallocated balance) and the bill
            // (its outstanding) — FOR UPDATE makes a second allocator touching the same
            // payment or bill block until the first commits, then observe its allocation.
            // Both sums are read AFTER the locks are held, so they reflect committed state.
            // The payment is locked before the bill on every path, so the order is
            // consistent and cannot deadlock against itself.
            $payment = SupplierPayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $bill = SupplierBill::query()->whereKey($bill->id)->lockForUpdate()->firstOrFail();

            $available = $payment->unallocatedAmount();
            if ($amount > $available) {
                throw FinanceException::allocationExceedsSource('payment', (string) $available);
            }

            $outstanding = $bill->outstanding();
            if ($amount > $outstanding) {
                throw FinanceException::allocationExceedsDocument($bill->document_type->label().' '.$bill->number, (string) $outstanding);
            }

            return PaymentAllocation::create([
                'company_id' => $payment->company_id,
                'payment_id' => $payment->id,
                'supplier_bill_id' => $bill->id,
                'amount' => $amount,
                'allocated_at' => Carbon::now(),
                'allocated_by' => $actorId,
            ]);
        });
    }

    /**
     * Auto-allocate a payment across a supplier's open bills, oldest first.
     *
     * @return list<PaymentAllocation>
     */
    public function autoAllocatePayment(SupplierPayment $payment, ?int $actorId = null): array
    {
        $payment = $payment->fresh();
        $remaining = $payment->unallocatedAmount();
        if ($remaining <= 0.0) {
            return [];
        }

        $bills = SupplierBill::query()
            ->where('company_id', $payment->company_id)
            ->where('supplier_id', $payment->supplier_id)
            ->where('status', 'posted')
            ->where('document_type', 'bill')
            ->orderBy('due_date')
            ->orderBy('bill_date')
            ->orderBy('id')
            ->get();

        $created = [];
        foreach ($bills as $bill) {
            if ($remaining <= 0.0) {
                break;
            }

            $outstanding = $bill->outstanding();
            if ($outstanding <= 0.0) {
                continue;
            }

            $apply = round(min($remaining, $outstanding), 4);
            $created[] = $this->allocatePayment($payment, $bill, $apply, $actorId);
            $remaining = round($remaining - $apply, 4);
        }

        return $created;
    }

    /**
     * Reverse part (or all) of a posted payment allocation with a new,
     * negative, append-only row against the SAME payment+bill pair — the
     * original row is never edited or deleted (see PaymentAllocation::booted()).
     * A row that is itself a reversal cannot be reversed again; correct it
     * with a fresh ordinary allocation instead (one-step correction only).
     */
    public function reversePaymentAllocation(
        PaymentAllocation $allocation,
        float $amount,
        string $reason,
        ?int $actorId = null,
    ): PaymentAllocation {
        $amount = round($amount, 4);

        $this->assertPositive($amount);
        $this->assertReasonGiven($reason);
        $this->assertNotAlreadyAReversal($allocation->reverses_allocation_id !== null);

        return DB::transaction(function () use ($allocation, $amount, $reason, $actorId): PaymentAllocation {
            // Same lock order as allocatePayment: payment before bill — a
            // correction racing a fresh allocation on the same pair serializes
            // on the identical row locks, so neither can over-allocate or
            // over-reverse the same available amount.
            $payment = SupplierPayment::query()->whereKey($allocation->payment_id)->lockForUpdate()->firstOrFail();
            $bill = SupplierBill::query()->whereKey($allocation->supplier_bill_id)->lockForUpdate()->firstOrFail();

            // Re-derive under the lock: how much of THIS specific original
            // allocation remains unreversed. Read after the lock is held, so it
            // reflects committed state even under concurrent reversal attempts.
            $alreadyReversed = round((float) $allocation->reversals()->sum('amount') * -1, 4);
            $reversible = round((float) $allocation->amount - $alreadyReversed, 4);
            if ($amount > $reversible) {
                throw FinanceException::reversalExceedsAllocation((string) $reversible);
            }

            return PaymentAllocation::create([
                'company_id' => $payment->company_id,
                'payment_id' => $payment->id,
                'supplier_bill_id' => $bill->id,
                'amount' => -$amount,
                'reverses_allocation_id' => $allocation->id,
                'reversal_reason' => $reason,
                'allocated_at' => Carbon::now(),
                'allocated_by' => $actorId,
            ]);
        });
    }

    // ── Guards ──────────────────────────────────────────────────────────────────

    private function assertPositive(float $amount): void
    {
        if ($amount <= 0.0) {
            throw FinanceException::allocationMustBePositive();
        }
    }

    private function assertPosted(string $kind, string $number, bool $isPosted): void
    {
        if (! $isPosted) {
            throw FinanceException::documentNotPosted($kind, $number);
        }
    }

    private function assertReasonGiven(string $reason): void
    {
        if (trim($reason) === '') {
            throw FinanceException::reversalReasonRequired();
        }
    }

    private function assertNotAlreadyAReversal(bool $isAlreadyAReversal): void
    {
        if ($isAlreadyAReversal) {
            throw FinanceException::cannotReverseAReversal();
        }
    }
}
