<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Company\CurrentCompanyService;
use App\Core\Responses\OperationResult;
use Modules\Sales\Customers\Domain\Contracts\CustomerRepositoryInterface;
use Modules\Sales\Customers\Domain\Exceptions\CustomerNotFoundException;

/**
 * The single write path for `customers.sales_owner_id`/`sales_owner_name` —
 * the CTO-ratified CRM/Commercial Customer owner authority (TASK-ECOS-CRM-
 * CUSTOMER-PORTFOLIO-AND-FOLLOWUP-003). The field existed, readable-only,
 * since Batch 02's operational read model; this closes that exact gap with
 * one controlled operation instead of a second ownership authority.
 *
 * Owner validity (must belong to the same company) and actor authorization
 * are the caller's responsibility — see CustomerController::assignOwner(),
 * which resolves the target user through the existing IAM/user authority
 * before this action ever runs, matching the same division of concerns
 * UpdateCustomerAction already uses for company-scoped lookups.
 */
final class AssignSalesOwnerAction extends BaseAction
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
        private readonly CurrentCompanyService $currentCompany,
    ) {}

    /**
     * @param  mixed  ...$arguments  [string $customerId, ?int $ownerId, ?string $ownerName]
     */
    public function execute(mixed ...$arguments): OperationResult
    {
        $customerId = (string) ($arguments[0] ?? '');
        $ownerId = $arguments[1] ?? null;
        $ownerName = $arguments[2] ?? null;

        $customer = $this->customers->findById($customerId, $this->currentCompany->id());

        if ($customer === null) {
            throw new CustomerNotFoundException($customerId);
        }

        $updated = $this->customers->update($customer, [
            'sales_owner_id' => $ownerId,
            'sales_owner_name' => $ownerName,
        ]);

        return OperationResult::success(
            $updated,
            $ownerId === null ? 'Sales owner unassigned successfully.' : 'Sales owner assigned successfully.',
        );
    }
}
