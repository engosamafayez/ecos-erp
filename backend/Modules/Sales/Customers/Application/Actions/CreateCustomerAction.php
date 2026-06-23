<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Modules\Sales\Customers\Application\DTO\CustomerDTO;
use Modules\Sales\Customers\Domain\Contracts\CustomerRepositoryInterface;

final class CreateCustomerAction extends BaseAction
{
    public function __construct(private readonly CustomerRepositoryInterface $customers) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        /** @var CustomerDTO $dto */
        $dto = $arguments[0];

        $customer = $this->customers->create([
            'code' => $dto->code,
            'name' => $dto->name,
            'contact_person' => $dto->contact_person,
            'email' => $dto->email,
            'phone' => $dto->phone,
            'mobile' => $dto->mobile,
            'country' => $dto->country,
            'city' => $dto->city,
            'address' => $dto->address,
            'notes' => $dto->notes,
            'is_active' => $dto->is_active,
        ]);

        return OperationResult::success($customer, 'Customer created successfully.');
    }
}
