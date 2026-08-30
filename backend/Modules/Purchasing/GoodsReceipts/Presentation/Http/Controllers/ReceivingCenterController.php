<?php

declare(strict_types=1);

namespace Modules\Purchasing\GoodsReceipts\Presentation\Http\Controllers;

use App\Core\Company\CurrentCompanyService;
use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\StockSync\Application\Actions\SyncStockAction;
use Modules\Purchasing\GoodsReceipts\Application\Actions\ReceiveAgainstPurchaseOrderAction;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceipt;
use Modules\Purchasing\GoodsReceipts\Presentation\Http\Requests\ReceiveAgainstPurchaseOrderRequest;
use Modules\Purchasing\PurchaseOrders\Domain\Enums\PurchaseOrderStatus;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrder;
use RuntimeException;
use Throwable;

/**
 * TASK-PROCUREMENT-PO-DRIVEN-RECEIVING-CENTER-001 — the Receiving Center as a PURCHASE-ORDER-driven
 * work queue.
 *
 * This controller only READS receivable Purchase Orders and DELEGATES the physical receipt to the
 * certified {@see ReceiveAgainstPurchaseOrderAction} (Create + Post). It creates no receiving model,
 * no inventory movement, and no inbound mode — `goods_inward_mode` and the Supplier Invoice's
 * downstream financial role are untouched. Tenancy is the company of the acting user; Purchase
 * Orders carry no global scope, so the explicit `company_id` filter is the boundary (the same one
 * the certified PO list uses).
 */
final class ReceivingCenterController extends Controller
{
    use HasApiResponse;

    /** Receivable now — the canonical PurchaseOrderStatus::canReceive() states. */
    private const ACTIVE_STATES = [
        PurchaseOrderStatus::Approved->value,
        PurchaseOrderStatus::PartiallyReceived->value,
    ];

    /** Completed receiving work — kept browsable in History. */
    private const HISTORY_STATES = [
        PurchaseOrderStatus::Received->value,
        PurchaseOrderStatus::Closed->value,
    ];

    public function __construct(private readonly CurrentCompanyService $currentCompany) {}

    /**
     * GET /receiving/queue — receivable Purchase Orders, aggregated for the work queue.
     *
     * scope=active → Awaiting + Partially Received; scope=history → Received + Closed. Expected /
     * received / remaining are summed from the canonical PO line quantities (`received_qty` is the
     * cumulative figure PostGoodsReceiptAction maintains), never recomputed from receipts here.
     */
    public function queue(Request $request): JsonResponse
    {
        $companyId = $this->currentCompany->id();
        $scope = $request->query('scope') === 'history' ? 'history' : 'active';
        $states = $scope === 'history' ? self::HISTORY_STATES : self::ACTIVE_STATES;

        $base = fn () => PurchaseOrder::query()
            ->where('company_id', $companyId)
            ->when($request->query('supplier_id'), fn ($q, $v) => $q->where('supplier_id', $v))
            ->when($request->query('warehouse_id'), fn ($q, $v) => $q->where('warehouse_id', $v))
            ->when($request->query('search'), fn ($q, $v) => $q->where('po_number', 'like', "%{$v}%"))
            ->when($request->query('date_from'), fn ($q, $v) => $q->whereDate('order_date', '>=', $v))
            ->when($request->query('date_to'), fn ($q, $v) => $q->whereDate('order_date', '<=', $v));

        $sortable = ['po_number', 'order_date', 'expected_date', 'status', 'created_at'];
        $sortBy = in_array($request->query('sort_by'), $sortable, true) ? $request->query('sort_by') : 'order_date';
        $sortDir = strtolower((string) $request->query('sort_dir')) === 'asc' ? 'asc' : 'desc';
        $perPage = (int) $request->query('per_page', 15);

        $paginator = $base()
            ->whereIn('status', $states)
            ->withCount('lines')
            ->withSum('lines', 'quantity')
            ->withSum('lines', 'received_qty')
            ->with(['supplier:id,code,name', 'warehouse:id,code,name'])
            ->orderBy($sortBy, $sortDir)
            ->paginate($perPage);

        return $this->success([
            'scope' => $scope,
            // KPIs over the full company set (unaffected by the active/history scope or paging),
            // but honouring the same narrowing filters so the numbers match what is listed.
            'kpis' => [
                'awaiting' => (clone $base())->where('status', PurchaseOrderStatus::Approved->value)->count(),
                'partial' => (clone $base())->where('status', PurchaseOrderStatus::PartiallyReceived->value)->count(),
                'received' => (clone $base())->whereIn('status', self::HISTORY_STATES)->count(),
            ],
            'items' => array_map(fn (PurchaseOrder $po): array => $this->presentQueueRow($po), $paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    /**
     * GET /receiving/purchase-orders/{purchaseOrder} — the per-line receive detail.
     *
     * Ordered / previously-received / remaining per line, so the Warehouse edits only "receive now".
     */
    public function show(Request $request, string $purchaseOrder): JsonResponse
    {
        $po = PurchaseOrder::query()
            ->where('company_id', $this->currentCompany->id())
            ->with(['supplier:id,code,name', 'warehouse:id,code,name', 'lines.product:id,name,sku'])
            ->find($purchaseOrder);

        if ($po === null) {
            return $this->error('Purchase order not found.', 404);
        }

        return $this->success([
            'id' => $po->id,
            'po_number' => $po->po_number,
            'supplier' => $po->supplier ? ['id' => $po->supplier->id, 'code' => $po->supplier->code, 'name' => $po->supplier->name] : null,
            'warehouse' => $po->warehouse ? ['id' => $po->warehouse->id, 'code' => $po->warehouse->code, 'name' => $po->warehouse->name] : null,
            'order_date' => $po->order_date?->toDateString(),
            'status' => $po->status->value,
            'status_label' => $po->status->label(),
            'can_receive' => $po->status->canReceive(),
            'lines' => $po->lines->map(fn ($line): array => [
                'id' => $line->id,
                'product_id' => $line->product_id,
                'product_name' => $line->product?->name,
                'product_sku' => $line->product?->sku,
                'ordered_qty' => (float) $line->quantity,
                'received_qty' => (float) $line->received_qty,
                'remaining_qty' => $line->remainingQty(),
            ])->values()->all(),
        ]);
    }

    /**
     * POST /receiving/purchase-orders/{purchaseOrder}/receive — record actual received quantities.
     *
     * Delegates to the certified Create + Post actions (one transaction). Inventory increases only
     * by the actual accepted quantity; over-receipt is rejected by PostGoodsReceiptAction. Channel
     * stock sync mirrors the normal post path (best-effort).
     */
    public function receive(
        ReceiveAgainstPurchaseOrderRequest $request,
        string $purchaseOrder,
        ReceiveAgainstPurchaseOrderAction $action,
        SyncStockAction $syncAction,
    ): JsonResponse {
        $po = PurchaseOrder::query()
            ->where('company_id', $this->currentCompany->id())
            ->with('lines')
            ->find($purchaseOrder);

        if ($po === null) {
            return $this->error('Purchase order not found.', 404);
        }

        try {
            /** @var GoodsReceipt $receipt */
            $receipt = $action->execute($po, $request->validated()['lines'])->data();
        } catch (RuntimeException $e) {
            // Not-receivable status, over-receipt, no-warehouse, empty submission — a client error,
            // not a 500. The transaction inside the action has already rolled back.
            return $this->error($e->getMessage(), 422);
        }

        // Mirror GoodsReceiptController::post — best-effort channel stock sync, errors swallowed.
        $productIds = $receipt->lines->pluck('product_id')->all();
        Channel::query()->where('is_active', true)->where('sync_stock', true)->get()
            ->each(function (Channel $channel) use ($syncAction, $productIds): void {
                try {
                    $syncAction->execute($channel->id, $productIds);
                } catch (Throwable) {
                    // stock posting already succeeded
                }
            });

        return $this->success([
            'goods_receipt_id' => $receipt->id,
            'purchase_order_id' => $po->id,
            'status' => $po->fresh()?->status->value,
        ], 'Goods received against purchase order.');
    }

    /**
     * @return array<string, mixed>
     */
    private function presentQueueRow(PurchaseOrder $po): array
    {
        $expected = (float) ($po->lines_sum_quantity ?? 0);
        $received = (float) ($po->lines_sum_received_qty ?? 0);
        $remaining = max(0.0, round($expected - $received, 4));

        return [
            'id' => $po->id,
            'po_number' => $po->po_number,
            'supplier' => $po->supplier ? ['id' => $po->supplier->id, 'code' => $po->supplier->code, 'name' => $po->supplier->name] : null,
            'warehouse' => $po->warehouse ? ['id' => $po->warehouse->id, 'code' => $po->warehouse->code, 'name' => $po->warehouse->name] : null,
            'order_date' => $po->order_date?->toDateString(),
            'expected_date' => $po->expected_date?->toDateString(),
            'product_count' => (int) ($po->lines_count ?? 0),
            'expected_qty' => round($expected, 4),
            'received_qty' => round($received, 4),
            'remaining_qty' => $remaining,
            'received_pct' => $expected > 0 ? (int) round($received / $expected * 100) : 0,
            'status' => $po->status->value,
            'status_label' => $po->status->label(),
        ];
    }
}
