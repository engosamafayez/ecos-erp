<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Modules\Purchasing\Suppliers\Domain\Contracts\SupplierCategoryRepositoryInterface;
use Modules\Purchasing\Suppliers\Domain\Exceptions\SupplierCategoryNotFoundException;

final class DeleteSupplierCategoryAction extends BaseAction
{
    public function __construct(private readonly SupplierCategoryRepositoryInterface $categories) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $id = (string) ($arguments[0] ?? '');

        $category = $this->categories->findById($id);

        if ($category === null) {
            throw new SupplierCategoryNotFoundException;
        }

        $this->categories->delete($category);

        return OperationResult::success(null, 'Supplier category deleted successfully.');
    }
}
