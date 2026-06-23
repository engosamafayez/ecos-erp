<?php

declare(strict_types=1);

namespace Modules\MasterData\Warehouses\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use InvalidArgumentException;
use Modules\MasterData\Warehouses\Application\DTO\WarehouseDTO;
use Modules\MasterData\Warehouses\Domain\Contracts\WarehouseRepositoryInterface;

/**
 * Creates a new warehouse.
 */
final class CreateWarehouseAction extends BaseAction
{
    public function __construct(private readonly WarehouseRepositoryInterface $warehouses) {}

    /**
     * @param  mixed  ...$arguments  Expects a single {@see WarehouseDTO}.
     */
    public function execute(mixed ...$arguments): OperationResult
    {
        $dto = $arguments[0] ?? null;

        if (! $dto instanceof WarehouseDTO) {
            throw new InvalidArgumentException('CreateWarehouseAction::execute expects a WarehouseDTO.');
        }

        $warehouse = $this->warehouses->create($dto->toArray());

        return OperationResult::success($warehouse, 'Warehouse created successfully.');
    }
}
