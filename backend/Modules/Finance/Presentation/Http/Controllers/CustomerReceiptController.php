<?php

declare(strict_types=1);

namespace Modules\Finance\Presentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Modules\Finance\Allocation\Domain\Services\AllocationEngine;
use Modules\Finance\Presentation\Http\Controllers\Concerns\ResolvesFinanceContext;
use Modules\Finance\Receivables\Domain\Models\CustomerInvoice;
use Modules\Finance\Receivables\Domain\Models\CustomerReceipt;
use Modules\Finance\Receivables\Domain\Models\ReceiptAllocation;
use Modules\Finance\Receivables\Domain\Services\AccountsReceivableService;
use Modules\Finance\Shared\Domain\Services\CommandIdempotencyGuard;

/**
 * Customer receipts (money in), their posting, allocation to invoices, and the
 * write-off workflow. Allocation is a pure subledger relationship — it never
 * posts a journal.
 */
class CustomerReceiptController extends Controller
{
    use ResolvesFinanceContext;

    public function __construct(
        private readonly AccountsReceivableService $ar,
        private readonly AllocationEngine $allocations,
        private readonly CommandIdempotencyGuard $idempotency,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $receipts = CustomerReceipt::query()
            ->where('company_id', $this->companyId($request))
            ->when($request->filled('customer_id'), fn ($q) => $q->where('customer_id', $request->string('customer_id')))
            ->latest('id')
            ->limit(100)
            ->get()
            ->map(fn (CustomerReceipt $r) => $this->payload($r));

        return response()->json(['data' => $receipts]);
    }

    /**
     * An `Idempotency-Key` header is honoured when present — same key + same
     * payload replays the original result (200); same key + a materially
     * different payload conflicts (422); no key runs uncoordinated, exactly
     * as before this existed, so no existing caller breaks. Requiring the
     * header is a deliberate future tightening, not made here
     * (TASK-ECOS-FINANCE-AP-AR-GL-WIRING-003 §8).
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'customer_id' => ['required', 'string'],
            'number' => ['required', 'string', 'max:60'],
            'receipt_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'deposit_account_id' => ['required', 'string'], // uuid
            'currency' => ['nullable', 'string', 'size:3'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $companyId = $this->companyId($request);

        $result = $this->idempotency->execute(
            companyId: $companyId,
            commandType: 'ar.receipt.create',
            idempotencyKey: $request->header('Idempotency-Key'),
            payload: $validated,
            command: fn () => $this->ar->createReceipt(
                companyId: $companyId,
                customerId: $validated['customer_id'],
                number: $validated['number'],
                receiptDate: Carbon::parse($validated['receipt_date']),
                amount: (float) $validated['amount'],
                depositAccountId: $this->accountId($request, $validated['deposit_account_id']),
                currency: $validated['currency'] ?? 'EGP',
                description: $validated['description'] ?? null,
                createdBy: $this->actorId($request),
            ),
            actorId: $this->actorId($request),
        );

        /** @var CustomerReceipt $receipt */
        $receipt = $result->result;

        return response()
            ->json(['data' => $this->payload($receipt)], $result->wasReplayed ? 200 : 201)
            ->header('Idempotent-Replay', $result->wasReplayed ? 'true' : 'false');
    }

    public function post(Request $request, string $uuid): JsonResponse
    {
        $receipt = $this->ar->postReceipt($this->find($request, $uuid), $this->actorId($request));

        return response()->json(['data' => $this->payload($receipt)]);
    }

    /** Allocate part of a receipt to one invoice. */
    public function allocate(Request $request, string $uuid): JsonResponse
    {
        $validated = $request->validate([
            'invoice_id' => ['required', 'string'], // invoice uuid
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        $receipt = $this->find($request, $uuid);
        $invoice = $this->findInvoice($request, $validated['invoice_id']);

        $allocation = $this->allocations->allocateReceipt($receipt, $invoice, (float) $validated['amount'], $this->actorId($request));

        return response()->json(['data' => [
            'id' => $allocation->uuid,
            'receipt_id' => $receipt->uuid,
            'invoice_id' => $invoice->uuid,
            'amount' => (float) $allocation->amount,
            'receipt_unallocated' => $receipt->fresh()->unallocatedAmount(),
            'invoice_outstanding' => $invoice->fresh()->outstanding(),
        ]], 201);
    }

    /** Auto-allocate a receipt across the customer's open invoices (FIFO). */
    public function autoAllocate(Request $request, string $uuid): JsonResponse
    {
        $receipt = $this->find($request, $uuid);
        $created = $this->allocations->autoAllocateReceipt($receipt, $this->actorId($request));

        return response()->json(['data' => [
            'allocations' => count($created),
            'receipt_unallocated' => $receipt->fresh()->unallocatedAmount(),
        ]]);
    }

    /**
     * Reverse part (or all) of a posted allocation with a new, append-only,
     * negative contra-allocation — the original row is never edited or
     * deleted. Full, partial, and repeated sequential partial reversal are
     * all the same call with a smaller amount each time; the engine caps at
     * whatever remains reversible on that specific original allocation.
     * Scoped to THIS receipt by construction (the allocation is looked up as
     * a child of the already company-scoped receipt, never by its uuid
     * alone) — a foreign-company or cross-receipt allocation id 404s here,
     * it is never reachable to authorize against.
     */
    public function reverseAllocation(Request $request, string $uuid, string $allocationUuid): JsonResponse
    {
        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $receipt = $this->find($request, $uuid);
        $allocation = $this->findAllocation($receipt, $allocationUuid);

        $reversal = $this->allocations->reverseReceiptAllocation(
            $allocation,
            (float) $validated['amount'],
            $validated['reason'],
            $this->actorId($request),
        );

        return response()->json(['data' => [
            'id' => $reversal->uuid,
            'reverses_allocation_id' => $allocation->uuid,
            'receipt_id' => $receipt->uuid,
            'customer_invoice_id' => $allocation->customer_invoice_id !== null
                ? CustomerInvoice::query()->whereKey($allocation->customer_invoice_id)->value('uuid')
                : null,
            'amount' => (float) $reversal->amount,
            'reason' => $reversal->reversal_reason,
            'receipt_unallocated' => $receipt->fresh()->unallocatedAmount(),
        ]], 201);
    }

    /** Write off the outstanding balance of a posted invoice to a bad-debt account. */
    public function writeOff(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'invoice_id' => ['required', 'string'],
            'bad_debt_account_id' => ['required', 'string'], // uuid
            'amount' => ['nullable', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $invoice = $this->findInvoice($request, $validated['invoice_id']);

        $receipt = $this->ar->writeOff(
            invoice: $invoice,
            badDebtAccountId: $this->accountId($request, $validated['bad_debt_account_id']),
            allocations: $this->allocations,
            amount: isset($validated['amount']) ? (float) $validated['amount'] : null,
            actorId: $this->actorId($request),
            reason: $validated['reason'] ?? null,
        );

        return response()->json(['data' => [
            'write_off_receipt' => $receipt->uuid,
            'invoice_outstanding' => $invoice->fresh()->outstanding(),
        ]], 201);
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function find(Request $request, string $uuid): CustomerReceipt
    {
        return CustomerReceipt::query()
            ->where('company_id', $this->companyId($request))
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function findInvoice(Request $request, string $uuid): CustomerInvoice
    {
        return CustomerInvoice::query()
            ->where('company_id', $this->companyId($request))
            ->where('uuid', $uuid)
            ->firstOrFail();
    }

    private function findAllocation(CustomerReceipt $receipt, string $allocationUuid): ReceiptAllocation
    {
        return ReceiptAllocation::query()
            ->where('receipt_id', $receipt->id)
            ->where('uuid', $allocationUuid)
            ->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(CustomerReceipt $r): array
    {
        return [
            'id' => $r->uuid,
            'customer_id' => $r->customer_id,
            'number' => $r->number,
            'receipt_date' => $r->receipt_date?->toDateString(),
            'amount' => (float) $r->amount,
            'currency' => $r->currency,
            'status' => $r->status->value,
            'unallocated' => $r->isPosted() ? $r->unallocatedAmount() : null,
            'journal_entry_id' => $r->journal_entry_id,
            'posted_at' => $r->posted_at?->toIso8601String(),
        ];
    }
}
