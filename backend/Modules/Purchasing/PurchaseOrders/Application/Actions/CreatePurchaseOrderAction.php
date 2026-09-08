<?php

declare(strict_types=1);

namespace Modules\Purchasing\PurchaseOrders\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Core\Responses\OperationResult;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Purchasing\PurchaseOrders\Application\DTO\PurchaseOrderDTO;
use Modules\Purchasing\PurchaseOrders\Application\DTO\PurchaseOrderLineDTO;
use Modules\Purchasing\PurchaseOrders\Domain\Contracts\PurchaseOrderRepositoryInterface;
use Modules\Purchasing\PurchaseOrders\Domain\Enums\PurchaseOrderStatus;
use Modules\Purchasing\PurchaseOrders\Domain\Models\PurchaseOrder;

final class CreatePurchaseOrderAction extends BaseAction
{
    /** Bounded retries for the po_number collision handled in {@see createWithUniqueNumber()}. */
    private const MAX_NUMBER_ATTEMPTS = 3;

    public function __construct(private readonly PurchaseOrderRepositoryInterface $orders) {}

    public function execute(mixed ...$arguments): OperationResult
    {
        $dto = $arguments[0] ?? null;

        if (! $dto instanceof PurchaseOrderDTO) {
            throw new InvalidArgumentException('CreatePurchaseOrderAction::execute expects a PurchaseOrderDTO.');
        }

        $subtotal = $dto->subtotal();

        $baseAttributes = [
            'supplier_id' => $dto->supplier_id,
            'order_date' => $dto->order_date,
            'expected_date' => $dto->expected_date,
            'status' => PurchaseOrderStatus::Draft->value,
            'notes' => $dto->notes,
            'subtotal' => $subtotal,
            'total' => $subtotal,
        ];

        $lines = array_map(fn (PurchaseOrderLineDTO $line): array => [
            'product_id' => $line->product_id,
            'quantity' => $line->quantity,
            'unit_price' => $line->unit_price,
            'line_total' => $line->lineTotal(),
        ], $dto->lines);

        $order = $this->createWithUniqueNumber($baseAttributes, $lines);

        return OperationResult::success($order, 'Purchase order created successfully.');
    }

    /**
     * po_number is a MAX+1 read (EloquentPurchaseOrderRepository::nextPoNumber()) against a
     * DB-level unique index, so two concurrent creates that both read the same "last" number
     * would otherwise both attempt the same next number — the second insert fails the unique
     * constraint. Wrapping generation + insert in one transaction makes the read take a real
     * row lock (see nextPoNumber()'s lockForUpdate()), serializing concurrent callers. The
     * bounded retry covers the one window locking cannot: the very first row ever.
     */
    private function createWithUniqueNumber(array $baseAttributes, array $lines, int $attempt = 1): PurchaseOrder
    {
        try {
            return DB::transaction(function () use ($baseAttributes, $lines) {
                $attributes = ['po_number' => $this->orders->nextPoNumber()] + $baseAttributes;

                return $this->orders->create($attributes, $lines);
            });
        } catch (QueryException $e) {
            $isDuplicatePoNumber = (string) $e->getCode() === '23000'
                && str_contains($e->getMessage(), 'purchase_orders_po_number_unique');

            if ($isDuplicatePoNumber && $attempt < self::MAX_NUMBER_ATTEMPTS) {
                return $this->createWithUniqueNumber($baseAttributes, $lines, $attempt + 1);
            }

            throw $e;
        }
    }
}
