<?php

declare(strict_types=1);

namespace Modules\Inventory\Products\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Inventory\Products\Application\Actions\CreateProductAction;
use Modules\Inventory\Products\Application\Actions\DeleteProductAction;
use Modules\Inventory\Products\Application\Actions\GetProductAction;
use Modules\Inventory\Products\Application\Actions\ListProductsAction;
use Modules\Inventory\Products\Application\Actions\UpdateProductAction;
use Modules\Inventory\Products\Application\DTO\ProductDTO;
use Modules\Inventory\Products\Presentation\Http\Requests\StoreProductRequest;
use Modules\Inventory\Products\Presentation\Http\Requests\UpdateProductRequest;
use Modules\Inventory\Products\Presentation\Http\Resources\ProductResource;

/**
 * Products CRUD endpoints. Controllers stay thin — behavior lives in actions,
 * validation in form requests, output shaping in resources.
 */
final class ProductController extends Controller
{
    use HasApiResponse;

    public function index(Request $request, ListProductsAction $action): JsonResponse
    {
        $filters = [
            'search' => $request->query('search'),
            'category_id' => $request->query('category_id'),
            'unit_id' => $request->query('unit_id'),
            'product_type' => $request->query('product_type'),
            'status' => $request->query('status', 'all'),
            'sort_by' => $request->query('sort_by', 'created_at'),
            'sort_dir' => $request->query('sort_dir', 'desc'),
            'per_page' => $request->query('per_page', 10),
        ];

        $paginator = $action->execute($filters)->data();

        return $this->success([
            'items' => ProductResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(string $product, GetProductAction $action): JsonResponse
    {
        $model = $action->execute($product)->data();

        return $this->success(new ProductResource($model));
    }

    public function store(StoreProductRequest $request, CreateProductAction $action): JsonResponse
    {
        $result = $action->execute(ProductDTO::fromArray($request->validated()));

        return $this->created(new ProductResource($result->data()), $result->message());
    }

    public function update(
        UpdateProductRequest $request,
        string $product,
        UpdateProductAction $action,
    ): JsonResponse {
        $result = $action->execute($product, ProductDTO::fromArray($request->validated()));

        return $this->updated(new ProductResource($result->data()), $result->message());
    }

    public function destroy(string $product, DeleteProductAction $action): JsonResponse
    {
        $result = $action->execute($product);

        return $this->deleted($result->message() ?? 'Product deleted successfully.');
    }
}
