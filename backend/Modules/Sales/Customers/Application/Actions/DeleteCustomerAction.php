<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Company\CurrentCompanyService;
use App\Core\Responses\OperationResult;
use Modules\Sales\Customers\Domain\Contracts\CustomerRepositoryInterface;
use Modules\Sales\Customers\Domain\Exceptions\CustomerNotFoundException;

final class DeleteCustomerAction extends BaseAction
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
        // Resolved here, not threaded through the controller: the action itself cannot
        // then be invoked without a tenant boundary.
        private readonly CurrentCompanyService $currentCompany,
    ) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $id = (string) ($arguments[0] ?? '');
        $customer = $this->customers->findById($id, $this->currentCompany->id());

        if ($customer === null) {
            throw new CustomerNotFoundException($id);
        }

        $this->customers->delete($customer);

        return OperationResult::success(null, 'Customer deleted successfully.');
    }
}
