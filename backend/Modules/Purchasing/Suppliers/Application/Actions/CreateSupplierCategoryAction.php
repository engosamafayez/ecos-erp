<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Modules\Purchasing\Suppliers\Application\DTO\SupplierCategoryDTO;
use Modules\Purchasing\Suppliers\Domain\Contracts\SupplierCategoryRepositoryInterface;

final class CreateSupplierCategoryAction extends BaseAction
{
    public function __construct(private readonly SupplierCategoryRepositoryInterface $categories) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $dto = $arguments[0] ?? null;

        if (! $dto instanceof SupplierCategoryDTO) {
            throw new InvalidArgumentException('CreateSupplierCategoryAction::execute expects a SupplierCategoryDTO.');
        }

        $attributes = $dto->toArray();
        $attributes['company_id'] ??= Auth::user()?->company_id;

        $category = $this->categories->create($attributes);

        return OperationResult::success($category, 'Supplier category created successfully.');
    }
}
