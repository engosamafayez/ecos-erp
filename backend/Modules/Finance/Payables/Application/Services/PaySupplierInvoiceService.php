<?php

declare(strict_types=1);

namespace Modules\Finance\Payables\Application\Services;

use Illuminate\Support\Carbon;
use Modules\Finance\Allocation\Domain\Services\AllocationEngine;
use Modules\Finance\Ledger\Domain\Exceptions\FinanceException;
use Modules\Finance\Payables\Domain\Models\PaymentAllocation;
use Modules\Finance\Payables\Domain\Models\SupplierBill;
use Modules\Finance\Payables\Domain\Models\SupplierPayment;
use Modules\Finance\Payables\Domain\Services\AccountsPayableService;

/**
 * Pay Supplier Invoice — the Finance-owned orchestration that settles a supplier
 * invoice's canonical payable through the existing Accounts-Payable authorities.
 *
 * WHY THIS EXISTS. Every primitive already lives in Finance:
 * {@see AccountsPayableService} creates, approves and posts the payment;
 * {@see AllocationEngine} matches it to the bill; the Posting Coordinator and the
 * Journal Engine own the journal and the open-period gate; the maker/checker
 * identity gate lives in `approvePayment`. What did NOT exist is the link between
 * "pay THIS supplier invoice" and the payable its posting established — the AP
 * endpoints operate on bills and payments by their own identity, so an
 * invoice-facing caller had no canonical way to resolve the right bill or to
 * validate a payment against the invoice's own remaining. This use case is that
 * missing link, and ONLY that link. It writes no journal, no ledger row, and no
 * stored paid/remaining scalar; every side effect is delegated to the authority
 * that owns it.
 *
 * IT DOES NOT COLLAPSE SEGREGATION OF DUTIES. Money leaving the business is a
 * two-person decision — {@see AccountsPayableService::approvePayment()} refuses an
 * approver who is the maker, as an identity check a system role does not bypass.
 * This service therefore stops at the maker's boundary: it INITIATES a draft
 * payment (maker) and, once a DIFFERENT checker has approved and posted it through
 * the canonical authority, SETTLES it against the invoice. Approve and post are
 * deliberately NOT wrapped here — reusing them behind one call would either
 * duplicate the approval framework or let a single actor drive the whole chain,
 * which the identity gate exists to forbid.
 *
 * THE PAYABLE LINK. A supplier invoice's payable is the {@see SupplierBill} whose
 * number is `'SI-'.<invoice id>`, established once at invoice-post time by
 * `PostSupplierInvoiceService` (the sole payable authority; see Finance decision
 * record §Decision 5). This service resolves the SAME bill by the SAME convention
 * the writer and the read-model ({@see \Modules\Purchasing\SupplierInvoices\Application\Services\SupplierInvoicePaymentSummary})
 * use; it never creates a payable. A commercial invoice whose lines carry no
 * receipt anchor posts NO payable by design — there is then nothing to pay, and
 * this service refuses rather than fabricate one.
 */
final class PaySupplierInvoiceService
{
    private const EPSILON = 0.0001;

    /**
     * The AP-bill naming contract shared with `PostSupplierInvoiceService` (the
     * writer) and `SupplierInvoicePaymentSummary` (the reader). Finance resolves
     * its OWN bill by this number; it never reaches into Purchasing.
     */
    private const PAYABLE_NUMBER_PREFIX = 'SI-';

    public function __construct(
        private readonly AccountsPayableService $ap,
        private readonly AllocationEngine $allocations,
    ) {}

    /**
     * The canonical payable for a supplier invoice, or null when none was ever
     * established (unposted invoice, or a Mode-1 commercial invoice whose payable
     * was skipped for want of a receipt anchor).
     */
    public function resolvePayable(string $companyId, string $invoiceId): ?SupplierBill
    {
        return SupplierBill::query()
            ->where('company_id', $companyId)
            ->where('number', self::PAYABLE_NUMBER_PREFIX.$invoiceId)
            ->first();
    }

    /**
     * Maker step: create a DRAFT payment for a supplier invoice's payable.
     *
     * Validates that the invoice has a canonical, POSTED payable, and that the
     * amount is positive and within the payable's remaining (the invoice's own
     * outstanding). Supplier identity is guaranteed by construction — the payment
     * is created for the bill's own supplier, which is what lets the later
     * allocation pass the same-party guard. Funding-account eligibility (a
     * company-owned cash/bank source) is enforced by the canonical
     * {@see AccountsPayableService::createPayment()} through
     * {@see \Modules\Finance\Shared\Domain\Services\FundingAccountPolicy} — not
     * duplicated here.
     *
     * Delegates creation to {@see AccountsPayableService::createPayment()}; this
     * method never writes a payment row itself and never approves or posts — that
     * is a different person's authority.
     */
    public function initiatePayment(
        string $companyId,
        string $invoiceId,
        string $number,
        Carbon $paymentDate,
        float $amount,
        int $fundingAccountId,
        string $currency = 'EGP',
        ?string $description = null,
        ?int $createdBy = null,
    ): SupplierPayment {
        $bill = $this->requirePostedPayable($companyId, $invoiceId);

        $this->assertPositive($amount);

        $remaining = $bill->outstanding();
        if ($amount > $remaining + self::EPSILON) {
            throw FinanceException::allocationExceedsDocument(
                $bill->document_type->label().' '.$bill->number,
                (string) $remaining,
            );
        }

        // Funding-account eligibility (company-owned cash/bank source) is enforced
        // canonically by AccountsPayableService::createPayment via FundingAccountPolicy —
        // this use case does not re-implement it, so all supplier-payment paths share
        // one rule.
        return $this->ap->createPayment(
            companyId: $companyId,
            supplierId: (string) $bill->supplier_id,
            number: $number,
            paymentDate: $paymentDate,
            amount: $amount,
            fundingAccountId: $fundingAccountId,
            currency: $currency,
            description: $description,
            createdBy: $createdBy,
        );
    }

    /**
     * Settle step: apply an already-POSTED payment to the supplier invoice's
     * payable, so the invoice's derived Paid/Remaining move.
     *
     * The payment must already be posted — a DIFFERENT checker approved it and it
     * posted through the canonical authority. {@see AllocationEngine::allocatePayment()}
     * enforces that, the same-supplier rule and the outstanding cap; this method
     * only resolves the invoice's bill so the caller need not know its identity.
     * The resulting {@see PaymentAllocation} is append-only and immutable, and the
     * invoice's Paid/Remaining fall out of it on read — nothing is stored here.
     */
    public function settleInvoice(
        SupplierPayment $payment,
        string $invoiceId,
        float $amount,
        ?int $actorId = null,
    ): PaymentAllocation {
        $bill = $this->requirePostedPayable((string) $payment->company_id, $invoiceId);

        return $this->allocations->allocatePayment($payment, $bill, $amount, $actorId);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function requirePostedPayable(string $companyId, string $invoiceId): SupplierBill
    {
        $bill = $this->resolvePayable($companyId, $invoiceId);

        if ($bill === null) {
            throw FinanceException::noPayableForSupplierInvoice(self::PAYABLE_NUMBER_PREFIX.$invoiceId);
        }

        // A payable can only be settled once posted; the AllocationEngine enforces
        // this again at the write boundary. Checking here turns a Mode-1 commercial
        // invoice with no established payable, or a still-draft bill, into a precise
        // refusal instead of a late allocation failure.
        if (! $bill->isPosted()) {
            throw FinanceException::documentNotPosted($bill->document_type->label(), $bill->number);
        }

        return $bill;
    }

    private function assertPositive(float $amount): void
    {
        if ($amount <= 0.0) {
            throw FinanceException::allocationMustBePositive();
        }
    }
}
