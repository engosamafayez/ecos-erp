<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use InvalidArgumentException;
use Modules\Purchasing\Suppliers\Application\DTO\SupplierDTO;
use Modules\Purchasing\Suppliers\Domain\Contracts\SupplierRepositoryInterface;
use Modules\Purchasing\Suppliers\Domain\Exceptions\SupplierNotFoundException;

/**
 * Updates an existing supplier.
 */
final class UpdateSupplierAction extends BaseAction
{
    public function __construct(private readonly SupplierRepositoryInterface $suppliers) {}

    /**
     * @param  mixed  ...$arguments  Expects (string $id, SupplierDTO $dto).
     *
     * @throws SupplierNotFoundException
     */
    public function execute(mixed ...$arguments): OperationResult
    {
        $id = (string) ($arguments[0] ?? '');
        $dto = $arguments[1] ?? null;

        if (! $dto instanceof SupplierDTO) {
            throw new InvalidArgumentException('UpdateSupplierAction::execute expects a SupplierDTO.');
        }

        $supplier = $this->suppliers->findById($id);

        if ($supplier === null) {
            throw new SupplierNotFoundException;
        }

        // Code is backend-owned and assigned once at creation — an ordinary edit must never
        // regenerate or overwrite it, regardless of what the client sends.
        $attributes = $dto->toArray();
        unset($attributes['code']);

        $supplier = $this->suppliers->update($supplier, $attributes);

        return OperationResult::success($supplier, 'Supplier updated successfully.');
    }
}
