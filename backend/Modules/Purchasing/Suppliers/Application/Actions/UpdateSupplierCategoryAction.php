<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use InvalidArgumentException;
use Modules\Purchasing\Suppliers\Application\DTO\SupplierCategoryDTO;
use Modules\Purchasing\Suppliers\Domain\Contracts\SupplierCategoryRepositoryInterface;
use Modules\Purchasing\Suppliers\Domain\Exceptions\SupplierCategoryNotFoundException;

final class UpdateSupplierCategoryAction extends BaseAction
{
    public function __construct(private readonly SupplierCategoryRepositoryInterface $categories) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $id = (string) ($arguments[0] ?? '');
        $dto = $arguments[1] ?? null;

        if (! $dto instanceof SupplierCategoryDTO) {
            throw new InvalidArgumentException('UpdateSupplierCategoryAction::execute expects a SupplierCategoryDTO.');
        }

        $category = $this->categories->findById($id);

        if ($category === null) {
            throw new SupplierCategoryNotFoundException;
        }

        $category = $this->categories->update($category, $dto->toArray());

        return OperationResult::success($category, 'Supplier category updated successfully.');
    }
}
