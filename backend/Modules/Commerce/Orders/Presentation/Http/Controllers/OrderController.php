<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Commerce\Orders\Application\Actions\CreateOrderAction;
use Modules\Commerce\Orders\Application\Actions\DeleteOrderAction;
use Modules\Commerce\Orders\Application\Actions\GetOrderAction;
use Modules\Commerce\Orders\Application\Actions\ListOrdersAction;
use Modules\Commerce\Orders\Application\Actions\PrepareOrderAction;
use Modules\Commerce\Orders\Application\Actions\UpdateOrderAction;
use Modules\Commerce\Orders\Application\DTO\OrderDTO;
use Modules\Commerce\Orders\Presentation\Http\Requests\StoreOrderRequest;
use Modules\Commerce\Orders\Presentation\Http\Requests\UpdateOrderRequest;
use Modules\Commerce\Orders\Presentation\Http\Resources\OrderResource;

final class OrderController extends Controller
{
    use HasApiResponse;

    public function index(Request $request, ListOrdersAction $action): JsonResponse
    {
        $filters = [
            'search' => $request->query('search'),
            'status' => $request->query('status', 'all'),
            'channel_id' => $request->query('channel_id'),
            'customer_id' => $request->query('customer_id'),
            'sort_by' => $request->query('sort_by', 'created_at'),
            'sort_dir' => $request->query('sort_dir', 'desc'),
            'per_page' => $request->query('per_page', 10),
        ];

        $paginator = $action->execute($filters)->data();

        return $this->success([
            'items' => OrderResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(string $order, GetOrderAction $action): JsonResponse
    {
        $model = $action->execute($order)->data();

        return $this->success(new OrderResource($model));
    }

    public function store(StoreOrderRequest $request, CreateOrderAction $action): JsonResponse
    {
        $result = $action->execute(OrderDTO::fromArray($request->validated()));

        return $this->created(new OrderResource($result->data()), $result->message());
    }

    public function update(
        UpdateOrderRequest $request,
        string $order,
        UpdateOrderAction $action,
    ): JsonResponse {
        $result = $action->execute($order, OrderDTO::fromArray($request->validated()));

        return $this->updated(new OrderResource($result->data()), $result->message());
    }

    public function prepare(string $order, PrepareOrderAction $action): JsonResponse
    {
        $result = $action->execute($order);

        return $this->success(new OrderResource($result->data()), $result->message());
    }

    public function destroy(string $order, DeleteOrderAction $action): JsonResponse
    {
        $result = $action->execute($order);

        return $this->deleted($result->message() ?? 'Order deleted successfully.');
    }
}
