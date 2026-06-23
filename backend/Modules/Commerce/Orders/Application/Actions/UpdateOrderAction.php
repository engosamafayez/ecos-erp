<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Modules\Commerce\Orders\Application\DTO\OrderDTO;
use Modules\Commerce\Orders\Domain\Contracts\OrderRepositoryInterface;
use Modules\Commerce\Orders\Domain\Exceptions\OrderNotFoundException;

final class UpdateOrderAction extends BaseAction
{
    public function __construct(private readonly OrderRepositoryInterface $orders) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $id = (string) ($arguments[0] ?? '');

        /** @var OrderDTO $dto */
        $dto = $arguments[1];

        $order = $this->orders->findById($id);

        if ($order === null) {
            throw new OrderNotFoundException($id);
        }

        $attributes = $dto->orderAttributes();

        $subtotal = array_sum(array_column($dto->lineAttributes(), 'line_total'));
        $attributes['subtotal'] = $subtotal;
        $attributes['total'] = $subtotal;

        $updated = $this->orders->update($order, $attributes, $dto->lineAttributes());

        return OperationResult::success($updated, 'Order updated successfully.');
    }
}
