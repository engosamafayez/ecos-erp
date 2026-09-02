<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\DB;
use Modules\Sales\Customers\Application\DTO\CustomerDTO;
use Modules\Sales\Customers\Domain\Contracts\CustomerRepositoryInterface;
use Modules\Sales\Customers\Domain\Models\Customer;
use Modules\Sales\Customers\Domain\Models\CustomerBrand;
use Modules\Sales\Customers\Domain\Services\CustomerCodeGeneratorService;

final class CreateCustomerAction extends BaseAction
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
        private readonly CustomerCodeGeneratorService $codeGenerator,
    ) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        /** @var CustomerDTO $dto */
        $dto = $arguments[0];

        /** @var Customer $customer */
        $customer = DB::transaction(function () use ($dto): Customer {
            // Backend-generated unless the caller explicitly supplied one — never
            // typed by the create-customer UI, per TASK-...-OPERATIONAL-READ-MODEL-007.
            // Generated INSIDE this same transaction: nextCodeNumber()'s row lock only
            // holds for the transaction's duration, exactly like Brand/Team/BusinessAccount.
            $code = $dto->code ?? $this->codeGenerator->next((string) $dto->company_id);

            $customer = $this->customers->create([
                'company_id' => $dto->company_id,
                'code' => $code,
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

            if ($dto->brand_id !== null) {
                CustomerBrand::create([
                    'customer_id' => $customer->id,
                    'brand_id' => $dto->brand_id,
                    'is_primary' => true,
                    'status' => 'active',
                ]);
            }

            return $customer;
        });

        return OperationResult::success(
            $customer->load('customerBrands'),
            'Customer created successfully.',
        );
    }
}
