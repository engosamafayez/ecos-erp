<?php

declare(strict_types=1);

namespace Modules\Purchasing\SupplierInvoices\Presentation\Http\Controllers;

use App\Core\Company\CurrentCompanyService;
use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\InventoryItems\Domain\Services\GoodsInwardAuthority;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;
use Modules\Purchasing\SupplierInvoices\Application\Services\InvoiceReceivingLinkService;
use Modules\Purchasing\SupplierInvoices\Application\Services\PostSupplierInvoiceService;
use Modules\Purchasing\SupplierInvoices\Application\Services\SupplierInvoicePaymentSummary;
use Modules\Purchasing\SupplierInvoices\Application\Services\SupplierInvoiceReceivingSummary;
use Modules\Purchasing\SupplierInvoices\Domain\Enums\SupplierInvoiceStatus;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoice;
use Modules\Purchasing\SupplierInvoices\Domain\Models\SupplierInvoiceLine;
use Modules\Purchasing\SupplierInvoices\Domain\Services\InvoiceReceiptAnchorService;
use Modules\Purchasing\SupplierInvoices\Presentation\Http\Requests\StoreSupplierInvoiceRequest;
use Modules\Purchasing\SupplierInvoices\Presentation\Http\Resources\SupplierInvoiceResource;
use Throwable;

final class SupplierInvoiceController extends Controller
{
    use HasApiResponse;

    public function __construct(
        private readonly PostSupplierInvoiceService $postService,
        private readonly CurrentCompanyService $currentCompany,
        private readonly InvoiceReceiptAnchorService $anchors,
        private readonly GoodsInwardAuthority $inwardAuthority,
        private readonly InvoiceReceivingLinkService $receivingLink,
    ) {}

    /**
     * §9 (remediation-004) — Goods Receipt Lines this supplier+product may anchor to, for the
     * invoice line editor's explicit-link picker. `exclude_invoice_id` lets re-editing a saved
     * invoice see its own already-anchored lines' full remaining quantity (excluding its own
     * prior consumption), exactly as {@see InvoiceReceiptAnchorService::invoiceable()} does.
     */
    public function eligibleReceiptLines(Request $request): JsonResponse
    {
        $companyId = $this->currentCompany->id() ?? '';
        $supplierId = (string) $request->query('supplier_id', '');
        $productId = (string) $request->query('product_id', '');

        if ($companyId === '' || $supplierId === '' || $productId === '') {
            return $this->success([]);
        }

        return $this->success($this->anchors->eligibleFor(
            $companyId,
            $supplierId,
            $productId,
            $request->query('exclude_invoice_id') !== null ? (string) $request->query('exclude_invoice_id') : null,
        ));
    }

    public function index(Request $request): JsonResponse
    {
        $query = SupplierInvoice::query()
            ->with(['supplier', 'warehouse'])
            ->latest('invoice_date');

        // Company isolation: scope to the authenticated user's company via warehouse
        if ($companyId = $this->currentCompany->id()) {
            $query->whereHas('warehouse', fn ($q) => $q->where('company_id', $companyId));
        }

        if ($request->filled('search')) {
            $q = $request->query('search');
            $query->where(function ($sub) use ($q): void {
                $sub->where('invoice_number', 'like', "%{$q}%")
                    ->orWhere('supplier_invoice_ref', 'like', "%{$q}%");
            });
        }

        if ($request->filled('status') && $request->query('status') !== 'all') {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->query('supplier_id'));
        }

        if ($request->filled('date_from')) {
            $query->whereDate('invoice_date', '>=', $request->query('date_from'));
        }

        if ($request->filled('date_to')) {
            $query->whereDate('invoice_date', '<=', $request->query('date_to'));
        }

        $perPage = (int) $request->query('per_page', 15);
        $paginator = $query->paginate($perPage);

        return $this->success([
            'items' => SupplierInvoiceResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(StoreSupplierInvoiceRequest $request): JsonResponse
    {
        // The receiving warehouse is now needed unconditionally — it is the ownership source
        // for `company_id` as well as the currency fallback — so it is resolved once here
        // rather than only inside the currency branch.
        $warehouse = Warehouse::query()
            ->with('company:id,currency')
            ->find($request->input('warehouse_id'));

        // Resolve currency from company settings; fall back to null (DB stores null when not configured)
        $currency = $request->input('currency') ?? $warehouse?->company?->currency;

        $invoice = SupplierInvoice::query()->create(
            array_merge($request->safe()->except('lines'), [
                'invoice_number' => (new SupplierInvoice)->generateInvoiceNumber(),
                // B-2. Nullable, backfilled once, never written since — so every invoice
                // created through the API carried NULL and the column could not scope access.
                // Derived from the warehouse, exactly as the backfill migration defines it.
                'company_id' => $warehouse?->company_id,
                'status' => SupplierInvoiceStatus::Draft,
                'created_by' => auth()->id(),
                'currency' => $currency,
                'exchange_rate' => $request->input('exchange_rate', 1),
                'freight_amount' => $request->input('freight_amount', 0),
                'additional_costs' => $request->input('additional_costs', 0),
                'discount_amount' => $request->input('discount_amount', 0),
            ]),
        );

        $this->syncLines($invoice, $request->validated('lines'));
        $invoice->recalculateTotals();
        $invoice->save();

        $this->syncLinkedReceipt($invoice);

        $invoice->load(['supplier', 'warehouse', 'lines.product']);

        return $this->success(new SupplierInvoiceResource($invoice), 'Supplier invoice created', 201);
    }

    /**
     * §2–§6 — invoice-first flow: creating (or, while still safe, editing) a Supplier Invoice
     * automatically creates/syncs its linked Goods Receipt work item. Company scope mirrors
     * validate()'s own: only where Goods Receipt is this company's goods-inward authority
     * (Mode 1) does an invoice need a receipt to settle against at all — a Mode 3 company's
     * invoice IS the inbound authority and needs none, exactly as before this task.
     */
    private function syncLinkedReceipt(SupplierInvoice $invoice): void
    {
        $invoice->loadMissing('warehouse');
        $companyId = (string) ($invoice->company_id ?? $invoice->warehouse?->company_id ?? '');

        if ($companyId === '' || ! $this->inwardAuthority->receiptMayPost($companyId)) {
            return;
        }

        $this->receivingLink->sync($invoice);
    }

    public function show(
        SupplierInvoice $supplierInvoice,
        SupplierInvoicePaymentSummary $payments,
        SupplierInvoiceReceivingSummary $receiving,
    ): JsonResponse {
        $supplierInvoice->load([
            'supplier', 'warehouse', 'lines.product',
            'lines.goodsReceiptLine.goodsReceipt.purchaseOrder',
        ]);

        $data = (new SupplierInvoiceResource($supplierInvoice))->toArray(request());
        // §9–§12 — payment read-model DERIVED from the canonical AP allocations (never a stored column).
        $data['payment'] = $payments->for($supplierInvoice);
        // §15–§17 — read-only ordered → received → invoiced linkage where the V-5 anchor exists.
        $data['receipt_links'] = $this->receiptLinks($supplierInvoice);
        // TASK-...-014 §7/§10/§20 — the invoice-first receiving/reconciliation read-model.
        $data['receiving'] = $receiving->for($supplierInvoice);

        return $this->success($data);
    }

    /**
     * §15–§17 — each anchored invoice line's link to its physical receipt and purchase order, with
     * Ordered / Received / Invoiced as DISTINCT facts. Read-only: a quantity mismatch is surfaced,
     * never silently reconciled, and neither document is rewritten.
     *
     * @return array<int, array<string, mixed>>
     */
    private function receiptLinks(SupplierInvoice $supplierInvoice): array
    {
        return $supplierInvoice->lines
            ->filter(fn (SupplierInvoiceLine $line): bool => $line->goods_receipt_line_id !== null)
            ->map(function (SupplierInvoiceLine $line): array {
                $receiptLine = $line->goodsReceiptLine;
                $receipt = $receiptLine?->goodsReceipt;
                $purchaseOrder = $receipt?->purchaseOrder;

                return [
                    'line_id' => $line->id,
                    'product' => $line->product?->name,
                    'goods_receipt_line_id' => $line->goods_receipt_line_id,
                    'receipt_number' => $receipt?->receipt_number,
                    'po_number' => $purchaseOrder?->po_number,
                    'ordered_qty' => $receiptLine !== null ? (float) $receiptLine->ordered_quantity : null,
                    'received_qty' => $receiptLine?->effectiveReceivedQty(),
                    'invoiced_qty' => (float) $line->quantity,
                ];
            })
            ->values()
            ->all();
    }

    public function update(StoreSupplierInvoiceRequest $request, SupplierInvoice $supplierInvoice): JsonResponse
    {
        if (! in_array($supplierInvoice->status, [SupplierInvoiceStatus::Draft, SupplierInvoiceStatus::Failed])) {
            return $this->error('Only draft or failed invoices can be edited', 422);
        }

        $supplierInvoice->update($request->safe()->except('lines'));
        $this->syncLines($supplierInvoice, $request->validated('lines'));
        $supplierInvoice->recalculateTotals();
        $supplierInvoice->save();

        $this->syncLinkedReceipt($supplierInvoice);

        $supplierInvoice->load(['supplier', 'warehouse', 'lines.product']);

        return $this->success(new SupplierInvoiceResource($supplierInvoice));
    }

    public function validate(SupplierInvoice $supplierInvoice): JsonResponse
    {
        if ($supplierInvoice->status !== SupplierInvoiceStatus::Draft) {
            return $this->error('Only draft invoices can be validated', 422);
        }

        if ($supplierInvoice->lines->isEmpty()) {
            return $this->error('Invoice must have at least one line', 422);
        }

        // GR-ANCHOR-REMEDIATION-004-R1 §7 — this UI's own copy already treats "Validated" as
        // "ready to post" (see the frontend success toast), and PostSupplierInvoiceService
        // independently refuses any Mode-1 line with no stated receipt anchor. Checking the
        // SAME thing here — via the same InvoiceReceiptAnchorService::resolve() Post itself
        // calls, never a re-implemented guard — is what makes "validated, then refused at
        // Post" impossible. Mode 3 companies never anchor to a receipt at all (see that
        // service's own Mode-3 payable path), so this is skipped there exactly as Post skips it.
        $supplierInvoice->loadMissing(['lines.product', 'warehouse']);
        $companyId = (string) ($supplierInvoice->company_id ?? $supplierInvoice->warehouse?->company_id ?? '');

        if ($this->inwardAuthority->receiptMayPost($companyId)) {
            $problems = [];

            foreach ($supplierInvoice->lines as $index => $line) {
                if ((float) $line->quantity <= 0) {
                    continue;
                }

                try {
                    $this->anchors->resolve($supplierInvoice, $line, (string) $supplierInvoice->id);
                } catch (Throwable $e) {
                    // §8 — business-readable, never a bare UUID, for the common "nobody picked
                    // one yet" case. Other guards (wrong supplier/product/quantity) are already
                    // specific and out of this ticket's scope; their existing message is kept.
                    $problems[] = $line->goods_receipt_line_id === null
                        ? sprintf('Line %d (%s) has no goods receipt line selected.', $index + 1, $this->lineLabel($line))
                        : sprintf('Line %d (%s): %s', $index + 1, $this->lineLabel($line), $e->getMessage());
                }
            }

            if ($problems !== []) {
                return $this->error('Invoice is not ready to post — '.implode(' ', $problems), 422);
            }
        }

        $supplierInvoice->update(['status' => SupplierInvoiceStatus::Validated]);

        return $this->success(new SupplierInvoiceResource($supplierInvoice->fresh()));
    }

    /** Business-readable line identity for actionable validation errors — never a bare UUID. */
    private function lineLabel(SupplierInvoiceLine $line): string
    {
        $product = $line->product;

        if ($product?->sku) {
            return $product->sku.' — '.$product->name;
        }

        return $product?->name ?? $line->description ?? ('line '.$line->id);
    }

    public function post(SupplierInvoice $supplierInvoice): JsonResponse
    {
        // Idempotent-retry short-circuit: a client that retries a Post click after the FIRST
        // attempt already succeeded (slow response, double-click, network hiccup) would
        // otherwise hit PostSupplierInvoiceService's canPost() guard and get back a confusing
        // 422 "cannot be posted (status: posted)" for what is, from the user's point of view,
        // a successful retry. Recognising that specific case here and returning the SAME
        // success shape makes retries safe and legible without weakening anything: no new
        // posting attempt is made, no financial entry is touched.
        if ($supplierInvoice->status === SupplierInvoiceStatus::Posted) {
            return $this->success(
                new SupplierInvoiceResource($supplierInvoice->fresh(['supplier', 'warehouse', 'lines.product'])),
                'Invoice already posted',
            );
        }

        try {
            $this->postService->execute($supplierInvoice);
        } catch (Throwable $e) {
            return $this->error('Posting failed: '.$e->getMessage(), 422);
        }

        return $this->success(
            new SupplierInvoiceResource($supplierInvoice->fresh(['supplier', 'warehouse', 'lines.product'])),
            'Invoice posted successfully',
        );
    }

    public function cancel(SupplierInvoice $supplierInvoice): JsonResponse
    {
        if (! $supplierInvoice->status->canCancel()) {
            return $this->error('Invoice cannot be cancelled in its current state', 422);
        }

        $supplierInvoice->update(['status' => SupplierInvoiceStatus::Cancelled]);

        return $this->success(new SupplierInvoiceResource($supplierInvoice->fresh()));
    }

    public function destroy(SupplierInvoice $supplierInvoice): JsonResponse
    {
        if ($supplierInvoice->status !== SupplierInvoiceStatus::Draft) {
            return $this->error('Only draft invoices can be deleted', 422);
        }

        $supplierInvoice->delete();

        return $this->success(null, 'Invoice deleted');
    }

    public function stats(): JsonResponse
    {
        $companyId = $this->currentCompany->id();
        $scope = function ($q) use ($companyId): void {
            if ($companyId) {
                $q->whereHas('warehouse', fn ($wq) => $wq->where('company_id', $companyId));
            }
        };

        $stats = [
            'total' => SupplierInvoice::query()->tap($scope)->count(),
            'draft' => SupplierInvoice::query()->tap($scope)->where('status', 'draft')->count(),
            'validated' => SupplierInvoice::query()->tap($scope)->where('status', 'validated')->count(),
            'posted' => SupplierInvoice::query()->tap($scope)->where('status', 'posted')->count(),
            'failed' => SupplierInvoice::query()->tap($scope)->where('status', 'failed')->count(),
            'total_value' => (float) SupplierInvoice::query()->tap($scope)->where('status', 'posted')->sum('grand_total'),
            'pending_value' => (float) SupplierInvoice::query()->tap($scope)->whereIn('status', ['draft', 'validated'])->sum('grand_total'),
        ];

        return $this->success($stats);
    }

    /**
     * Callers pass VALIDATED lines, never `$request->input('lines')`.
     *
     * The raw input was mass-assigned straight into a fillable model, so any fillable key a
     * client chose to send was persisted without ever being validated — including the V-5
     * anchor `goods_receipt_line_id`, which would have been stored with no existence check and
     * no tenant scope. Taking the validated set means a key must be declared in the request
     * rules before it can ever reach a column.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function syncLines(SupplierInvoice $invoice, array $lines): void
    {
        $invoice->lines()->delete();

        foreach ($lines as $lineData) {
            $qty = (float) $lineData['quantity'];
            $price = (float) $lineData['unit_price'];
            $taxRate = (float) ($lineData['tax_rate'] ?? 0);
            $discount = (float) ($lineData['discount_amount'] ?? 0);
            $subtotal = round($qty * $price, 4);
            $taxAmt = round($subtotal * $taxRate / 100, 4);
            $total = round($subtotal + $taxAmt - $discount, 4);

            SupplierInvoiceLine::query()->create(array_merge($lineData, [
                'supplier_invoice_id' => $invoice->id,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmt,
                'discount_amount' => $discount,
                'line_total' => $total,
            ]));
        }
    }
}
