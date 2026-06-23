<?php

declare(strict_types=1);

namespace Modules\Purchasing\GoodsReceipts\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Purchasing\GoodsReceipts\Application\Actions\CreateGoodsReceiptAction;
use Modules\Purchasing\GoodsReceipts\Application\Actions\DeleteGoodsReceiptAction;
use Modules\Purchasing\GoodsReceipts\Application\Actions\GetGoodsReceiptAction;
use Modules\Purchasing\GoodsReceipts\Application\Actions\ListGoodsReceiptsAction;
use Modules\Purchasing\GoodsReceipts\Application\Actions\PostGoodsReceiptAction;
use Modules\Purchasing\GoodsReceipts\Application\Actions\UpdateGoodsReceiptAction;
use Modules\Purchasing\GoodsReceipts\Application\DTO\GoodsReceiptDTO;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\StockSync\Application\Actions\SyncStockAction;
use Modules\Purchasing\GoodsReceipts\Presentation\Http\Requests\StoreGoodsReceiptRequest;
use Modules\Purchasing\GoodsReceipts\Presentation\Http\Requests\UpdateGoodsReceiptRequest;
use Modules\Purchasing\GoodsReceipts\Presentation\Http\Resources\GoodsReceiptResource;

final class GoodsReceiptController extends Controller
{
    use HasApiResponse;

    public function index(Request $request, ListGoodsReceiptsAction $action): JsonResponse
    {
        $filters = [
            'search' => $request->query('search'),
            'purchase_order_id' => $request->query('purchase_order_id'),
            'warehouse_id' => $request->query('warehouse_id'),
            'status' => $request->query('status', 'all'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'sort_by' => $request->query('sort_by', 'created_at'),
            'sort_dir' => $request->query('sort_dir', 'desc'),
            'per_page' => $request->query('per_page', 10),
        ];

        $paginator = $action->execute($filters)->data();

        return $this->success([
            'items' => GoodsReceiptResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(string $goodsReceipt, GetGoodsReceiptAction $action): JsonResponse
    {
        $model = $action->execute($goodsReceipt)->data();

        return $this->success(new GoodsReceiptResource($model));
    }

    public function store(StoreGoodsReceiptRequest $request, CreateGoodsReceiptAction $action): JsonResponse
    {
        $result = $action->execute(GoodsReceiptDTO::fromArray($request->validated()));

        return $this->created(new GoodsReceiptResource($result->data()), $result->message());
    }

    public function update(
        UpdateGoodsReceiptRequest $request,
        string $goodsReceipt,
        UpdateGoodsReceiptAction $action,
    ): JsonResponse {
        $result = $action->execute($goodsReceipt, GoodsReceiptDTO::fromArray($request->validated()));

        return $this->updated(new GoodsReceiptResource($result->data()), $result->message());
    }

    public function destroy(string $goodsReceipt, DeleteGoodsReceiptAction $action): JsonResponse
    {
        $result = $action->execute($goodsReceipt);

        return $this->deleted($result->message() ?? 'Goods receipt deleted successfully.');
    }

    public function post(string $goodsReceipt, PostGoodsReceiptAction $postAction, SyncStockAction $syncAction): JsonResponse
    {
        $result = $postAction->execute($goodsReceipt);

        $receipt = $result->data();
        $productIds = $receipt->lines->pluck('product_id')->all();

        Channel::query()
            ->where('is_active', true)
            ->where('sync_stock', true)
            ->get()
            ->each(function (Channel $channel) use ($syncAction, $productIds): void {
                try {
                    $syncAction->execute($channel->id, $productIds);
                } catch (\Throwable) {
                    // Swallow sync errors — stock posting succeeded
                }
            });

        return $this->updated(new GoodsReceiptResource($result->data()), $result->message());
    }
}
