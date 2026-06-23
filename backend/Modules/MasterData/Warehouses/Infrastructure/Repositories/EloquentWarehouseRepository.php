<?php

declare(strict_types=1);

namespace Modules\MasterData\Warehouses\Infrastructure\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\MasterData\Warehouses\Domain\Contracts\WarehouseRepositoryInterface;
use Modules\MasterData\Warehouses\Domain\Models\Warehouse;

/**
 * Eloquent implementation of the warehouse repository.
 */
final class EloquentWarehouseRepository implements WarehouseRepositoryInterface
{
    /** Columns that may be sorted on (whitelist). */
    private const SORTABLE = ['code', 'name', 'city', 'is_active', 'created_at'];

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Warehouse::query()->with(['company', 'branch']);

        $companyId = trim((string) ($filters['company_id'] ?? ''));
        if ($companyId !== '') {
            $query->where('company_id', $companyId);
        }

        $branchId = trim((string) ($filters['branch_id'] ?? ''));
        if ($branchId !== '') {
            $query->where('branch_id', $branchId);
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
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

    public function findById(string $id): ?Warehouse
    {
        return Warehouse::query()->with(['company', 'branch'])->find($id);
    }

    public function create(array $attributes): Warehouse
    {
        $warehouse = Warehouse::query()->create($attributes);

        return $warehouse->load(['company', 'branch']);
    }

    public function update(Warehouse $warehouse, array $attributes): Warehouse
    {
        $warehouse->update($attributes);

        return $warehouse->load(['company', 'branch']);
    }

    public function delete(Warehouse $warehouse): void
    {
        $warehouse->delete();
    }
}
