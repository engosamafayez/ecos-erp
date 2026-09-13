<?php

declare(strict_types=1);

namespace Modules\Inventory\Transfer\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Modules\Inventory\Transfer\Application\Actions\TransferStockAction;
use Modules\Inventory\Transfer\Application\DTO\TransferStockDTO;
use Modules\Inventory\Transfer\Presentation\Http\Requests\StoreWarehouseTransferRequest;
use Modules\Inventory\Transfer\Presentation\Http\Resources\WarehouseTransferResource;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;

/**
 * HTTP wiring for the canonical warehouse-to-warehouse stock transfer.
 *
 * Thin by design: all business logic (locking, FIFO-layer preservation, the
 * cross-company guard, the ledger entries, the audit record) lives entirely in
 * TransferStockAction — reused as-is, not duplicated.
 */
final class WarehouseTransferController extends Controller
{
    use HasApiResponse;

    public function store(StoreWarehouseTransferRequest $request, TransferStockAction $action): JsonResponse
    {
        // The Eloquent tenant scope on Warehouse already limited which warehouse
        // this request could even reference (see StoreWarehouseTransferRequest);
        // its company is the correct company for the transfer regardless of
        // whether the acting user has one of their own (an unrestricted
        // system-role actor may not).
        $sourceWarehouse = Warehouse::query()->findOrFail($request->validated('source_warehouse_id'));

        $result = $action->execute(TransferStockDTO::fromArray([
            'source_warehouse_id' => $request->validated('source_warehouse_id'),
            'destination_warehouse_id' => $request->validated('destination_warehouse_id'),
            'product_id' => $request->validated('product_id'),
            'company_id' => $sourceWarehouse->company_id,
            'quantity' => $request->validated('quantity'),
            'reference' => $request->validated('reference'),
            'notes' => $request->validated('notes'),
            'actor_id' => (string) Auth::id(),
        ]));

        return $this->created(new WarehouseTransferResource($result->data()), $result->message());
    }
}
