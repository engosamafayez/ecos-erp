<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Purchasing\Suppliers\Application\Queries\GetSupplierAnalyticsQuery;
use Modules\Purchasing\Suppliers\Application\Queries\GetSupplierInventoryBreakdownQuery;
use Modules\Purchasing\Suppliers\Presentation\Http\Resources\SupplierAnalyticsResource;
use Modules\Purchasing\Suppliers\Presentation\Http\Resources\SupplierInventoryProductResource;

final class SupplierAnalyticsController extends Controller
{
    use HasApiResponse;

    public function analytics(
        string $supplier,
        GetSupplierAnalyticsQuery $query,
    ): JsonResponse {
        $data = $query->execute($supplier);

        return $this->success(new SupplierAnalyticsResource($data));
    }

    public function inventoryBreakdown(
        string $supplier,
        GetSupplierInventoryBreakdownQuery $query,
    ): JsonResponse {
        $products = $query->execute($supplier);

        return $this->success(
            $products->map(fn (array $p) => new SupplierInventoryProductResource($p))->values()
        );
    }
}
