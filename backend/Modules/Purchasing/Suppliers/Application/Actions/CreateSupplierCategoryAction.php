<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Modules\Purchasing\Suppliers\Application\DTO\SupplierCategoryDTO;
use Modules\Purchasing\Suppliers\Domain\Contracts\SupplierCategoryRepositoryInterface;
use Modules\Purchasing\Suppliers\Domain\Services\SupplierCategoryCodeGeneratorService;

final class CreateSupplierCategoryAction extends BaseAction
{
    public function __construct(
        private readonly SupplierCategoryRepositoryInterface $categories,
        private readonly SupplierCategoryCodeGeneratorService $codeGenerator,
    ) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $dto = $arguments[0] ?? null;

        if (! $dto instanceof SupplierCategoryDTO) {
            throw new InvalidArgumentException('CreateSupplierCategoryAction::execute expects a SupplierCategoryDTO.');
        }

        $attributes = $dto->toArray();
        $attributes['company_id'] ??= Auth::user()?->company_id;
        // §3 — always server-generated on create; a client-submitted code (there shouldn't be
        // one any more, but an old/API caller might still send one) is never trusted.
        $attributes['code'] = $this->codeGenerator->next((string) $attributes['company_id']);

        $category = $this->categories->create($attributes);

        return OperationResult::success($category, 'Supplier category created successfully.');
    }
}
