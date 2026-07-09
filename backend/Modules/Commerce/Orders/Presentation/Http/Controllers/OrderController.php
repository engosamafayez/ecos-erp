<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Presentation\Http\Controllers;

use App\Core\Company\CurrentCompanyService;
use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Commerce\Orders\Application\Actions\CreateManualOrderAction;
use Modules\Commerce\Orders\Application\Actions\CreateOrderAction;
use Modules\Commerce\Orders\Application\Actions\DeleteOrderAction;
use Modules\Commerce\Orders\Application\Actions\GetOrderAction;
use Modules\Commerce\Orders\Application\Actions\ListOrdersAction;
use Modules\Commerce\Orders\Application\Actions\PatchOrderAction;
use Modules\Commerce\Orders\Application\Actions\PrepareOrderAction;
use Modules\Commerce\Orders\Application\Actions\ResolveProductPricingAction;
use Modules\Commerce\Orders\Application\Actions\UpdateOrderAction;
use Modules\Commerce\Orders\Application\DTO\OrderDTO;
use Modules\Commerce\Orders\Application\Services\CreateOrderSnapshotService;
use Modules\Commerce\Orders\Domain\Models\OrderBusinessContextSnapshot;
use Modules\Commerce\Orders\Domain\Models\OrderFinancialSnapshot;
use Modules\Commerce\Orders\Presentation\Http\Requests\PatchOrderRequest;
use Modules\Commerce\Orders\Presentation\Http\Requests\StoreManualOrderRequest;
use Modules\Commerce\Orders\Presentation\Http\Requests\StoreOrderRequest;
use Modules\Commerce\Orders\Presentation\Http\Requests\UpdateOrderRequest;
use Modules\Commerce\Orders\Presentation\Http\Resources\OrderFinancialSnapshotResource;
use Modules\Commerce\Orders\Presentation\Http\Resources\OrderResource;

final class OrderController extends Controller
{
    use HasApiResponse;

    public function __construct(private readonly CurrentCompanyService $currentCompany) {}

    public function index(Request $request, ListOrdersAction $action): JsonResponse
    {
        $filters = [
            'search'     => $request->query('search'),
            'status'     => $request->query('status', 'all'),
            'channel_id' => $request->query('channel_id'),
            'customer_id' => $request->query('customer_id'),
            'sort_by'    => $request->query('sort_by', 'created_at'),
            'sort_dir'   => $request->query('sort_dir', 'desc'),
            'per_page'   => $request->query('per_page', 10),
            'company_id' => $this->currentCompany->id(),
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

    public function storeManual(StoreManualOrderRequest $request, CreateManualOrderAction $action): JsonResponse
    {
        $result = $action->execute($request->validated());

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

    public function quickUpdate(
        PatchOrderRequest $request,
        string $order,
        PatchOrderAction $action,
    ): JsonResponse {
        $result = $action->execute($order, $request->validated());

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

    /**
     * Returns the immutable financial snapshot for an order, or null if not yet created.
     * Includes the business context snapshot (TASK-ORDER-006C PART 10).
     * Automatically verifies the SHA-256 integrity hash and exposes hash_verified on the response.
     */
    public function financialSnapshot(string $order, CreateOrderSnapshotService $snapshotService): JsonResponse
    {
        $snapshot = OrderFinancialSnapshot::with('lines')
            ->where('order_id', $order)
            ->first();

        if ($snapshot === null) {
            return $this->success(null);
        }

        // Attach hash_verified as a transient attribute before serialization.
        $snapshot->setAttribute('hash_verified', $snapshotService->verifyIntegrityHash($snapshot));

        // Attach business context snapshot as a transient attribute (PART 10).
        $businessContext = OrderBusinessContextSnapshot::where('order_id', $order)->first();
        $snapshot->setAttribute('business_context', $businessContext);

        return $this->success(new OrderFinancialSnapshotResource($snapshot));
    }

    /**
     * Returns the approved selling price and pending-review status for a product.
     * Used by the Manual Order form to auto-populate unit prices on product selection.
     */
    public function productPricing(
        Request $request,
        string $productId,
        ResolveProductPricingAction $action,
    ): JsonResponse {
        $companyId = $request->user()?->company_id;
        $data = $action->execute($productId, $companyId);

        return $this->success($data);
    }
}
