<?php

declare(strict_types=1);

namespace Modules\Purchasing\GoodsReceipts\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Support\Facades\DB;
use Modules\Inventory\StockLedger\Domain\Contracts\StockMovementRepositoryInterface;
use Modules\Inventory\StockLedger\Domain\Enums\MovementType;
use Modules\Purchasing\GoodsReceipts\Domain\Contracts\GoodsReceiptRepositoryInterface;
use Modules\Purchasing\GoodsReceipts\Domain\Enums\GoodsReceiptStatus;
use Modules\Purchasing\GoodsReceipts\Domain\Exceptions\GoodsReceiptNotFoundException;
use Modules\Purchasing\GoodsReceipts\Domain\Exceptions\GoodsReceiptNotEditableException;
use Modules\Purchasing\GoodsReceipts\Domain\Models\GoodsReceiptLine;
use Modules\Purchasing\GoodsReceipts\Domain\Models\StockBalance;

final class PostGoodsReceiptAction extends BaseAction
{
    public function __construct(
        private readonly GoodsReceiptRepositoryInterface $receipts,
        private readonly StockMovementRepositoryInterface $movements,
    ) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $id = (string) ($arguments[0] ?? '');
        $receipt = $this->receipts->findById($id);

        if ($receipt === null) {
            throw new GoodsReceiptNotFoundException($id);
        }

        if (! $receipt->status->isEditable()) {
            throw new GoodsReceiptNotEditableException($receipt->receipt_number);
        }

        DB::transaction(function () use ($receipt): void {
            /** @var GoodsReceiptLine $line */
            foreach ($receipt->lines as $line) {
                $received = (float) $line->received_quantity;

                if ($received <= 0) {
                    continue;
                }

                $balance = StockBalance::query()
                    ->where('warehouse_id', $receipt->warehouse_id)
                    ->where('product_id', $line->product_id)
                    ->lockForUpdate()
                    ->first();

                $balanceBefore = $balance instanceof StockBalance ? (float) $balance->quantity : 0.0;
                $balanceAfter = $balanceBefore + $received;

                if ($balance instanceof StockBalance) {
                    $balance->update(['quantity' => $balanceAfter]);
                } else {
                    StockBalance::query()->create([
                        'warehouse_id' => $receipt->warehouse_id,
                        'product_id' => $line->product_id,
                        'quantity' => $balanceAfter,
                    ]);
                }

                $this->movements->record([
                    'warehouse_id' => $receipt->warehouse_id,
                    'product_id' => $line->product_id,
                    'movement_type' => MovementType::PurchaseReceipt->value,
                    'quantity' => $received,
                    'balance_before' => $balanceBefore,
                    'balance_after' => $balanceAfter,
                    'reference_type' => 'goods_receipt',
                    'reference_id' => $receipt->id,
                    'movement_date' => $receipt->receipt_date->toDateString(),
                    'notes' => null,
                ]);
            }

            $receipt->update(['status' => GoodsReceiptStatus::Posted->value]);
        });

        return OperationResult::success($this->receipts->findById($id), 'Goods receipt posted. Stock updated.');
    }
}
