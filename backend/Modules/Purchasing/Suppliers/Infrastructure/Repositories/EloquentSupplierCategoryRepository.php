<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Infrastructure\Repositories;

use Illuminate\Support\Collection;
use Modules\Purchasing\Suppliers\Domain\Contracts\SupplierCategoryRepositoryInterface;
use Modules\Purchasing\Suppliers\Domain\Models\SupplierCategory;

final class EloquentSupplierCategoryRepository implements SupplierCategoryRepositoryInterface
{
    public function all(bool $activeOnly = false): Collection
    {
        $query = SupplierCategory::query()->orderBy('name');

        if ($activeOnly) {
            $query->where('is_active', true);
        }

        return $query->get();
    }

    public function findById(string $id): ?SupplierCategory
    {
        return SupplierCategory::query()->find($id);
    }

    public function create(array $attributes): SupplierCategory
    {
        return SupplierCategory::query()->create($attributes);
    }

    public function update(SupplierCategory $category, array $attributes): SupplierCategory
    {
        $category->update($attributes);

        return $category->refresh();
    }

    public function delete(SupplierCategory $category): void
    {
        $category->delete();
    }
}
