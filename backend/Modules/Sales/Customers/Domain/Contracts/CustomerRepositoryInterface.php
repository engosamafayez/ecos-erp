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

    /**
     * The next sequential number for this company's Customer Code, backed by a dedicated
     * per-company sequence row (customer_code_sequences) — gap/legacy-tolerant and safe
     * under concurrent first-use for the same company. MUST be called inside the same DB
     * transaction that inserts the new Customer: the exclusive row lock taken by the
     * increment is only held for that transaction's duration, and the implementation
     * relies on it still being held when it reads the value back. See
     * EloquentCustomerRepository::nextCodeNumber() and
     * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-TASK-2-REMEDIATION-007-R1 for the full
     * correctness/concurrency proof.
     */
    public function nextCodeNumber(string $companyId): int;
}
