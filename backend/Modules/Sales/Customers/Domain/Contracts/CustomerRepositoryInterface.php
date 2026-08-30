<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Domain\Contracts;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Sales\Customers\Domain\Models\Customer;

interface CustomerRepositoryInterface
{
    public function paginate(array $filters): LengthAwarePaginator;

    /**
     * Tenant-aware lookup. $companyId is REQUIRED — there is deliberately no unscoped
     * variant, so a caller cannot forget the boundary. Pass null ONLY for the documented
     * unrestricted context (super-admin / no company affiliation), which is exactly what
     * {@see \App\Core\Company\CurrentCompanyService::id()} returns for those users.
     */
    public function findById(string $id, ?string $companyId): ?Customer;

    public function create(array $attributes): Customer;

    public function update(Customer $customer, array $attributes): Customer;

    public function delete(Customer $customer): void;
}
