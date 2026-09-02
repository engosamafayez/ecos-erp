<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Infrastructure\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Modules\Sales\Customers\Domain\Contracts\CustomerRepositoryInterface;
use Modules\Sales\Customers\Domain\Models\Customer;

final class EloquentCustomerRepository implements CustomerRepositoryInterface
{
    private const SORTABLE = ['code', 'name', 'country', 'city', 'is_active', 'created_at'];

    public function paginate(array $filters): LengthAwarePaginator
    {
        $query = Customer::query();

        $companyId = trim((string) ($filters['company_id'] ?? ''));
        if ($companyId !== '') {
            $query->where('company_id', $companyId);
        }

        $brandId = trim((string) ($filters['brand_id'] ?? ''));
        if ($brandId !== '') {
            $query->whereHas('customerBrands', function (Builder $builder) use ($brandId): void {
                $builder->where('brand_id', $brandId);
            });
        }

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            // Phone/mobile matching is load-bearing for the Phone First workspace (DD-055):
            // the smart search box and CustomerFormDrawer's pre-submit duplicate check both
            // go through this same generic search, not the dedicated /search-by-phone
            // exact-match action. Without it, searching an existing customer's exact phone
            // number always fell through to zero-results/create-new.
            $query->where(function (Builder $builder) use ($search): void {
                $builder
                    ->where('code', 'like', "%{$search}%")
                    ->orWhere('name', 'like', "%{$search}%")
                    ->orWhere('contact_person', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('city', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%");
            });
        }

        $status = (string) ($filters['status'] ?? 'all');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $country = trim((string) ($filters['country'] ?? ''));
        if ($country !== '') {
            $query->where('country', $country);
        }

        $city = trim((string) ($filters['city'] ?? ''));
        if ($city !== '') {
            $query->where('city', $city);
        }

        $sortBy = (string) ($filters['sort_by'] ?? 'created_at');
        if (! in_array($sortBy, self::SORTABLE, true)) {
            $sortBy = 'created_at';
        }

        $sortDir = strtolower((string) ($filters['sort_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = max(1, min((int) ($filters['per_page'] ?? 10), 100));

        // Default address only — ONE extra query for the whole page, never one per row.
        return $query
            ->with(['customerBrands.brand', 'addresses' => fn ($a) => $a->where('is_default', true)])
            ->orderBy($sortBy, $sortDir)
            ->paginate($perPage);
    }

    public function findById(string $id, ?string $companyId): ?Customer
    {
        return Customer::query()
            ->with(['customerBrands.brand', 'addresses' => fn ($a) => $a->where('is_default', true)])
            // Scoped whenever there IS a company context. Null is the documented
            // unrestricted case and matches how paginate() already behaves.
            ->when($companyId !== null && $companyId !== '', fn ($q) => $q->where('company_id', $companyId))
            ->find($id);
    }

    public function create(array $attributes): Customer
    {
        return Customer::query()->create($attributes);
    }

    public function update(Customer $customer, array $attributes): Customer
    {
        $customer->update($attributes);

        return $customer->refresh();
    }

    public function delete(Customer $customer): void
    {
        $customer->delete();
    }

    public function nextCodeNumber(string $companyId): int
    {
        // Lock the count inside the caller's transaction to prevent concurrent duplicates —
        // the exact pattern already used by Brand/BusinessAccount/Team code generation.
        $count = Customer::query()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->lockForUpdate()
            ->count();

        return $count + 1;
    }
}
