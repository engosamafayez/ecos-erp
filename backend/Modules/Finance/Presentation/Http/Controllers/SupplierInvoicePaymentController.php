<?php

declare(strict_types=1);

namespace Modules\Finance\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Modules\Finance\Payables\Application\Services\PaySupplierInvoiceService;
use Modules\Finance\Payables\Domain\Models\SupplierPayment;
use Modules\Finance\Presentation\Http\Controllers\Concerns\ResolvesFinanceContext;

/**
 * Invoice-anchored supplier payments — the canonical Finance entry point a
 * Procurement surface deep-links into for "Pay Supplier Invoice".
 *
 * It resolves the invoice's canonical payable and drives the existing AP
 * authorities through {@see PaySupplierInvoiceService}. Approve and post are
 * deliberately NOT exposed here: they remain the distinct segregation-of-duties
 * endpoints on {@see SupplierPaymentController} (`finance.ap.payment.approve`), so
 * the maker who initiates a payment can never approve or post it from this surface.
 */
class SupplierInvoicePaymentController extends Controller
{
    use ResolvesFinanceContext;

    public function __construct(private readonly PaySupplierInvoiceService $payInvoice) {}

    /** Maker: create a draft payment for the invoice's payable (does NOT approve or post). */
    public function initiate(Request $request, string $invoiceId): JsonResponse
    {
        $validated = $request->validate([
            'number' => ['required', 'string', 'max:60'],
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'funding_account_id' => ['required', 'string'], // account uuid
            'currency' => ['nullable', 'string', 'size:3'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $payment = $this->payInvoice->initiatePayment(
            companyId: $this->companyId($request),
            invoiceId: $invoiceId,
            number: $validated['number'],
            paymentDate: Carbon::parse($validated['payment_date']),
            amount: (float) $validated['amount'],
            fundingAccountId: $this->accountId($request, $validated['funding_account_id']),
            currency: $validated['currency'] ?? 'EGP',
            description: $validated['description'] ?? null,
            createdBy: $this->actorId($request),
        );

        return response()->json(['data' => $this->payload($payment, $invoiceId)], 201);
    }

    /** Settle: allocate an already-posted payment to the invoice's payable. */
    public function settle(Request $request, string $invoiceId, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $payment = SupplierPayment::query()
            ->where('company_id', $this->companyId($request))
            ->where('uuid', $uuid)
            ->firstOrFail();

        $allocation = $this->payInvoice->settleInvoice(
            payment: $payment,
            invoiceId: $invoiceId,
            amount: (float) $validated['amount'],
            actorId: $this->actorId($request),
        );

        return response()->json(['data' => [
            'id' => $allocation->uuid,
            'payment_id' => $payment->uuid,
            'invoice_id' => $invoiceId,
            'amount' => (float) $allocation->amount,
            'payment_unallocated' => $payment->fresh()->unallocatedAmount(),
        ]], 201);
    }

    /** @return array<string, mixed> */
    private function payload(SupplierPayment $p, string $invoiceId): array
    {
        return [
            'id' => $p->uuid,
            'invoice_id' => $invoiceId,
            'supplier_id' => $p->supplier_id,
            'number' => $p->number,
            'payment_date' => $p->payment_date?->toDateString(),
            'amount' => (float) $p->amount,
            'currency' => $p->currency,
            'status' => $p->status->value,
            'created_by' => $p->created_by,
        ];
    }
}
