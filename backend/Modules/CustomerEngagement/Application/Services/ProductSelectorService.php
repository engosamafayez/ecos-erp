<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Application\Services;

use Illuminate\Support\Facades\DB;

class ProductSelectorService
{
    public function search(string $term, ?string $companyId = null, int $limit = 20): array
    {
        $q = DB::table('products')
            ->select([
                'products.id',
                'products.name',
                'products.sku',
                'products.selling_price',
                'products.final_selling_price',
            ])
            ->where('products.is_active', true)
            ->where(function ($sq) use ($term) {
                $sq->where('products.name', 'like', "%{$term}%")
                    ->orWhere('products.sku', 'like', "%{$term}%");
            });

        if ($companyId) {
            // Filter by brand ownership — join brands
            $q->join('brands', 'products.brand_id', '=', 'brands.id')
                ->where('brands.company_id', $companyId);
        }

        return $q->limit($limit)->get()->toArray();
    }

    public function getProductDetails(string $productId): ?object
    {
        return DB::table('products')
            ->select([
                'products.id',
                'products.name',
                'products.sku',
                'products.selling_price',
                'products.final_selling_price',
                'products.stock_quantity',
            ])
            ->where('products.id', $productId)
            ->first();
    }
}
