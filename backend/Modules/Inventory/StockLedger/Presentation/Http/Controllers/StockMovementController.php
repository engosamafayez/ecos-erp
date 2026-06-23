<?php

declare(strict_types=1);

namespace Modules\Inventory\StockLedger\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\StockLedger\Application\Actions\GetStockMovementAction;
use Modules\Inventory\StockLedger\Application\Actions\ListStockMovementsAction;
use Modules\Inventory\StockLedger\Presentation\Http\Resources\StockMovementResource;

final class StockMovementController extends Controller
{
    use HasApiResponse;

    public function index(Request $request, ListStockMovementsAction $action): JsonResponse
    {
        $filters = [
            'search' => $request->query('search'),
            'product_id' => $request->query('product_id'),
            'warehouse_id' => $request->query('warehouse_id'),
            'movement_type' => $request->query('movement_type', 'all'),
            'date_from' => $request->query('date_from'),
            'date_to' => $request->query('date_to'),
            'sort_by' => $request->query('sort_by', 'created_at'),
            'sort_dir' => $request->query('sort_dir', 'desc'),
            'per_page' => $request->query('per_page', 10),
        ];

        $paginator = $action->execute($filters)->data();

        return $this->success([
            'items' => StockMovementResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(string $stockMovement, GetStockMovementAction $action): JsonResponse
    {
        $model = $action->execute($stockMovement)->data();

        return $this->success(new StockMovementResource($model));
    }
}
