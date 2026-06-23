<?php

declare(strict_types=1);

namespace Modules\Purchasing\GoodsReceipts\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use InvalidArgumentException;
use Modules\Purchasing\GoodsReceipts\Application\DTO\GoodsReceiptDTO;
use Modules\Purchasing\GoodsReceipts\Application\DTO\GoodsReceiptLineDTO;
use Modules\Purchasing\GoodsReceipts\Domain\Contracts\GoodsReceiptRepositoryInterface;
use Modules\Purchasing\GoodsReceipts\Domain\Enums\GoodsReceiptStatus;
use Modules\Purchasing\GoodsReceipts\Domain\Exceptions\PurchaseOrderNotApprovedException;
use Modules\Purchasing\PurchaseOrders\Domain\Enums\PurchaseOrderStatus;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrder;

final class CreateGoodsReceiptAction extends BaseAction
{
    public function __construct(private readonly GoodsReceiptRepositoryInterface $receipts) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $dto = $arguments[0] ?? null;

        if (! $dto instanceof GoodsReceiptDTO) {
            throw new InvalidArgumentException('CreateGoodsReceiptAction::execute expects a GoodsReceiptDTO.');
        }

        $po = PurchaseOrder::query()->find($dto->purchase_order_id);

        if (! $po instanceof PurchaseOrder || $po->status !== PurchaseOrderStatus::Approved) {
            throw new PurchaseOrderNotApprovedException($po?->po_number ?? $dto->purchase_order_id);
        }

        $attributes = [
            'receipt_number' => $this->receipts->nextReceiptNumber(),
            'purchase_order_id' => $dto->purchase_order_id,
            'warehouse_id' => $dto->warehouse_id,
            'receipt_date' => $dto->receipt_date,
            'status' => GoodsReceiptStatus::Draft->value,
            'notes' => $dto->notes,
        ];

        $lines = array_map(fn (GoodsReceiptLineDTO $line): array => [
            'purchase_order_line_id' => $line->purchase_order_line_id,
            'product_id' => $line->product_id,
            'ordered_quantity' => $line->ordered_quantity,
            'received_quantity' => $line->received_quantity,
        ], $dto->lines);

        $receipt = $this->receipts->create($attributes, $lines);

        return OperationResult::success($receipt, 'Goods receipt created successfully.');
    }
}
