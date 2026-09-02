<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Purchasing\Suppliers\Application\Actions\CreateSupplierCategoryAction;
use Modules\Purchasing\Suppliers\Application\Actions\DeleteSupplierCategoryAction;
use Modules\Purchasing\Suppliers\Application\Actions\ListSupplierCategoriesAction;
use Modules\Purchasing\Suppliers\Application\Actions\UpdateSupplierCategoryAction;
use Modules\Purchasing\Suppliers\Application\DTO\SupplierCategoryDTO;
use Modules\Purchasing\Suppliers\Presentation\Http\Requests\StoreSupplierCategoryRequest;
use Modules\Purchasing\Suppliers\Presentation\Http\Requests\UpdateSupplierCategoryRequest;
use Modules\Purchasing\Suppliers\Presentation\Http\Resources\SupplierCategoryResource;

/**
 * Supplier Category CRUD — a small, company-scoped lookup. Thin controller,
 * mirroring SupplierController's layering.
 */
final class SupplierCategoryController extends Controller
{
    use HasApiResponse;

    public function index(Request $request, ListSupplierCategoriesAction $action): JsonResponse
    {
        $activeOnly = $request->boolean('active_only');
        $categories = $action->execute($activeOnly)->data();

        return $this->success(SupplierCategoryResource::collection($categories));
    }

    public function store(StoreSupplierCategoryRequest $request, CreateSupplierCategoryAction $action): JsonResponse
    {
        $result = $action->execute(SupplierCategoryDTO::fromArray($request->validated()));

        return $this->created(new SupplierCategoryResource($result->data()), $result->message());
    }

    public function update(
        UpdateSupplierCategoryRequest $request,
        string $supplier_category,
        UpdateSupplierCategoryAction $action,
    ): JsonResponse {
        $result = $action->execute($supplier_category, SupplierCategoryDTO::fromArray($request->validated()));

        return $this->updated(new SupplierCategoryResource($result->data()), $result->message());
    }

    public function destroy(string $supplier_category, DeleteSupplierCategoryAction $action): JsonResponse
    {
        $result = $action->execute($supplier_category);

        return $this->deleted($result->message() ?? 'Supplier category deleted successfully.');
    }
}
