<?php

declare(strict_types=1);

namespace Modules\Inventory\Products\Infrastructure\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Inventory\Products\Domain\Contracts\ProductRepositoryInterface;
use Modules\Inventory\Products\Domain\Models\Product;

/**
 * Eloquent implementation of the product repository.
 */
final class EloquentProductRepository implements ProductRepositoryInterface
{
    /** Columns that may be sorted on (whitelist). */
    private const SORTABLE = ['sku', 'name', 'product_type', 'is_active', 'created_at'];

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Product::query()->with(['category', 'unit']);

        $categoryId = trim((string) ($filters['category_id'] ?? ''));
        if ($categoryId !== '') {
            $query->where('category_id', $categoryId);
        }

        $unitId = trim((string) ($filters['unit_id'] ?? ''));
        if ($unitId !== '') {
            $query->where('unit_id', $unitId);
        }

        $productType = trim((string) ($filters['product_type'] ?? ''));
        if (in_array($productType, Product::TYPES, true)) {
            $query->where('product_type', $productType);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('sku', 'like', "%{$search}%")
                    ->orWhere('barcode', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%");
            });
        }

        $status = (string) ($filters['status'] ?? 'all');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $sortBy = (string) ($filters['sort_by'] ?? 'created_at');
        if (! in_array($sortBy, self::SORTABLE, true)) {
            $sortBy = 'created_at';
        }

        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $perPage = (int) ($filters['per_page'] ?? 10);
        $perPage = max(1, min($perPage, 100));

        return $query->orderBy($sortBy, $sortDir)->paginate($perPage);
    }

    public function findById(string $id): ?Product
    {
        return Product::query()->with(['category', 'unit'])->find($id);
    }

    public function create(array $attributes): Product
    {
        $product = Product::query()->create($attributes);

        return $product->load(['category', 'unit']);
    }

    public function update(Product $product, array $attributes): Product
    {
        $product->update($attributes);

        return $product->load(['category', 'unit']);
    }

    public function delete(Product $product): void
    {
        $product->delete();
    }
}
