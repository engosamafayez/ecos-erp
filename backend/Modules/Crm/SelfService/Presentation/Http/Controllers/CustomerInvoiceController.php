<?php

declare(strict_types=1);

namespace Modules\Crm\SelfService\Presentation\Http\Controllers;

use App\Core\Audit\AuditService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Modules\Commerce\Orders\Domain\Models\Order;
use Modules\Crm\SelfService\Domain\Models\CustomerTrackingToken;
use Modules\Finance\Receivables\Domain\Models\CustomerInvoice;
use Symfony\Component\HttpFoundation\Response;

/**
 * §11/§12 — a customer-scoped read wrapper over Finance's own canonical CustomerInvoice. No
 * second invoice model, no CRM-owned copy. `source_type`/`source_id` (already a real, unique-
 * constrained column pair on finance_customer_invoices) is the existing linkage from an invoice
 * back to the Order it was raised for — reused exactly as-is, not duplicated.
 *
 * Never exposes journal_entry_id, ar_control_account_id, approved_by, or any other posting/GL
 * internal.
 *
 * TASK-ECOS-V1.1-CRM-04-CUSTOMER-SELF-SERVICE-UX-AND-FINAL-CLOSURE-020 §15 — pdf() now renders
 * a REAL PDF via barryvdh/laravel-dompdf (installed this task; see composer.json/lock), from the
 * exact same customer-safe payload() this class's own show() returns — never a second, richer
 * projection. Same ownership/company/customer checks as show(); no public filesystem URL is ever
 * returned, only a streamed application/pdf response gated by the same tracking token.
 */
final class CustomerInvoiceController extends Controller
{
    public function __construct(private readonly AuditService $audit) {}

    public function show(Request $request): JsonResponse
    {
        $invoice = $this->resolveOwnedInvoice($request);

        if ($invoice === null) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        /** @var CustomerTrackingToken $token */
        $token = $request->attributes->get('customer_tracking_token');
        $this->audit->record(
            action: 'customer_self_service.invoice_viewed',
            entityType: 'finance_customer_invoice',
            entityId: $invoice->uuid,
            companyId: $invoice->company_id,
            metadata: ['customer_id' => $token->customer_id],
        );

        return response()->json(['data' => $this->payload($invoice->load('lines'))]);
    }

    public function pdf(Request $request): Response|JsonResponse
    {
        $order = $this->resolveOwnedOrder($request);

        if ($order === null) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        $invoice = $this->invoiceForOrder($order)?->load('lines');

        if ($invoice === null) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        /** @var CustomerTrackingToken $token */
        $token = $request->attributes->get('customer_tracking_token');
        $this->audit->record(
            action: 'customer_self_service.invoice_pdf_viewed',
            entityType: 'finance_customer_invoice',
            entityId: $invoice->uuid,
            companyId: $invoice->company_id,
            metadata: ['customer_id' => $token->customer_id],
        );

        $lang = $request->query('lang') === 'ar' ? 'ar' : 'en';

        return Pdf::loadView('crm.self-service.invoice-pdf', [
            'data' => $this->payload($invoice),
            'brandName' => $order->channel?->brand?->name,
            'orderNumber' => $order->order_number,
            'lang' => $lang,
        ])
            ->setPaper('a4')
            ->stream('invoice-'.Str::slug($invoice->number).'.pdf');
    }

    private function resolveOwnedOrder(Request $request): ?Order
    {
        /** @var CustomerTrackingToken $token */
        $token = $request->attributes->get('customer_tracking_token');

        if ($token->order_id === null) {
            return null;
        }

        $order = Order::query()->with('channel.brand')->find($token->order_id);

        if ($order === null || $order->customer_id !== $token->customer_id || $order->company_id !== $token->company_id) {
            return null;
        }

        return $order;
    }

    private function invoiceForOrder(Order $order): ?CustomerInvoice
    {
        return CustomerInvoice::query()
            ->where('company_id', $order->company_id)
            ->where('customer_id', $order->customer_id)
            ->where('source_type', 'order')
            ->where('source_id', $order->id)
            ->first();
    }

    private function resolveOwnedInvoice(Request $request): ?CustomerInvoice
    {
        $order = $this->resolveOwnedOrder($request);

        return $order === null ? null : $this->invoiceForOrder($order);
    }

    /** @return array<string, mixed> */
    private function payload(CustomerInvoice $invoice): array
    {
        return [
            'id' => $invoice->uuid,
            'document_type' => $invoice->document_type->value,
            'number' => $invoice->number,
            'invoice_date' => $invoice->invoice_date?->toDateString(),
            'due_date' => $invoice->due_date?->toDateString(),
            'currency' => $invoice->currency,
            'subtotal' => (float) $invoice->subtotal,
            'tax_total' => (float) $invoice->tax_total,
            'total' => (float) $invoice->total,
            'status' => $invoice->status->value,
            'outstanding' => $invoice->isPosted() ? $invoice->outstanding() : null,
            'lines' => $invoice->relationLoaded('lines') ? $invoice->lines->map(fn ($l) => [
                'description' => $l->description,
                'quantity' => (float) $l->quantity,
                'unit_price' => (float) $l->unit_price,
                'net_amount' => (float) $l->net_amount,
                'tax_amount' => (float) $l->tax_amount,
            ])->values()->all() : [],
        ];
    }
}
