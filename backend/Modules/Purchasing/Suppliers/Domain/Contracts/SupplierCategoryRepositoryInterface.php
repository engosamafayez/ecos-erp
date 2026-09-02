<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Domain\Contracts;

use Illuminate\Support\Collection;
use Modules\Purchasing\Suppliers\Domain\Models\SupplierCategory;

interface SupplierCategoryRepositoryInterface
{
    /**
     * @return Collection<int, SupplierCategory>
     */
    public function all(bool $activeOnly = false): Collection;

    public function findById(string $id): ?SupplierCategory;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): SupplierCategory;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(SupplierCategory $category, array $attributes): SupplierCategory;

    public function delete(SupplierCategory $category): void;
}
