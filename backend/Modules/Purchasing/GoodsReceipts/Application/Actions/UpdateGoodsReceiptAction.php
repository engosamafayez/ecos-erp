<?php

declare(strict_types=1);

namespace Modules\Purchasing\GoodsReceipts\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use InvalidArgumentException;
use Modules\Purchasing\GoodsReceipts\Application\DTO\GoodsReceiptDTO;
use Modules\Purchasing\GoodsReceipts\Application\DTO\GoodsReceiptLineDTO;
use Modules\Purchasing\GoodsReceipts\Domain\Contracts\GoodsReceiptRepositoryInterface;
use Modules\Purchasing\GoodsReceipts\Domain\Exceptions\GoodsReceiptNotFoundException;
use Modules\Purchasing\GoodsReceipts\Domain\Exceptions\GoodsReceiptNotEditableException;

final class UpdateGoodsReceiptAction extends BaseAction
{
    public function __construct(private readonly GoodsReceiptRepositoryInterface $receipts) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $id = (string) ($arguments[0] ?? '');
        $dto = $arguments[1] ?? null;

        if (! $dto instanceof GoodsReceiptDTO) {
            throw new InvalidArgumentException('UpdateGoodsReceiptAction::execute expects a GoodsReceiptDTO as second argument.');
        }

        $receipt = $this->receipts->findById($id);

        if ($receipt === null) {
            throw new GoodsReceiptNotFoundException($id);
        }

        if (! $receipt->status->isEditable()) {
            throw new GoodsReceiptNotEditableException($receipt->receipt_number);
        }

        $attributes = [
            'purchase_order_id' => $dto->purchase_order_id,
            'warehouse_id' => $dto->warehouse_id,
            'receipt_date' => $dto->receipt_date,
            'notes' => $dto->notes,
        ];

        $lines = array_map(fn (GoodsReceiptLineDTO $line): array => [
            'purchase_order_line_id' => $line->purchase_order_line_id,
            'product_id' => $line->product_id,
            'ordered_quantity' => $line->ordered_quantity,
            'received_quantity' => $line->received_quantity,
        ], $dto->lines);

        $updated = $this->receipts->update($receipt, $attributes, $lines);

        return OperationResult::success($updated, 'Goods receipt updated successfully.');
    }
}
