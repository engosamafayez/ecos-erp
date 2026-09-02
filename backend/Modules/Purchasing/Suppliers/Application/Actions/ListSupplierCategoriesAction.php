<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Modules\Purchasing\Suppliers\Domain\Contracts\SupplierCategoryRepositoryInterface;

final class ListSupplierCategoriesAction extends BaseAction
{
    public function __construct(private readonly SupplierCategoryRepositoryInterface $categories) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $activeOnly = (bool) ($arguments[0] ?? false);

        return OperationResult::success($this->categories->all($activeOnly));
    }
}
