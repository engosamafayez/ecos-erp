<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use Modules\Purchasing\Suppliers\Application\DTO\SupplierDTO;
use Modules\Purchasing\Suppliers\Domain\Contracts\SupplierRepositoryInterface;
use Modules\Purchasing\Suppliers\Domain\Services\SupplierCodeSequenceService;

/**
 * Creates a new supplier, auto-generating its code if none was supplied.
 */
final class CreateSupplierAction extends BaseAction
{
    public function __construct(
        private readonly SupplierRepositoryInterface $suppliers,
        private readonly SupplierCodeSequenceService $codeGenerator,
    ) {}

    /**
     * @param  mixed  ...$arguments  Expects a single {@see SupplierDTO}.
     */
    public function execute(mixed ...$arguments): OperationResult
    {
        $dto = $arguments[0] ?? null;

        if (! $dto instanceof SupplierDTO) {
            throw new InvalidArgumentException('CreateSupplierAction::execute expects a SupplierDTO.');
        }

        $attributes = $dto->toArray();
        $companyId = $attributes['company_id'] ?? Auth::user()?->company_id;
        $attributes['company_id'] = $companyId;
        $attributes['code'] = $dto->code ?? $this->codeGenerator->next((string) $companyId);

        $supplier = $this->suppliers->create($attributes);

        return OperationResult::success($supplier, 'Supplier created successfully.');
    }
}
