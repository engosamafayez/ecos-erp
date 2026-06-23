<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Infrastructure\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Purchasing\Suppliers\Domain\Contracts\SupplierRepositoryInterface;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;

/**
 * Eloquent implementation of the supplier repository.
 */
final class EloquentSupplierRepository implements SupplierRepositoryInterface
{
    /** Columns that may be sorted on (whitelist). */
    private const SORTABLE = ['code', 'name', 'country', 'city', 'is_active', 'created_at'];

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Supplier::query();

        $country = trim((string) ($filters['country'] ?? ''));
        if ($country !== '') {
            $query->where('country', $country);
        }

        $city = trim((string) ($filters['city'] ?? ''));
        if ($city !== '') {
            $query->where('city', $city);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%");
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

    public function findById(string $id): ?Supplier
    {
        return Supplier::query()->find($id);
    }

    public function create(array $attributes): Supplier
    {
        return Supplier::query()->create($attributes);
    }

    public function update(Supplier $supplier, array $attributes): Supplier
    {
        $supplier->update($attributes);

        return $supplier->refresh();
    }

    public function delete(Supplier $supplier): void
    {
        $supplier->delete();
    }
}
