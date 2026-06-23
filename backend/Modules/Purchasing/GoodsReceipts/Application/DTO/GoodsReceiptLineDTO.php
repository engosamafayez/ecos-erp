<?php

declare(strict_types=1);

namespace Modules\Purchasing\GoodsReceipts\Application\DTO;

use App\Core\DTO\BaseDTO;

/**
 * Immutable input for a single goods receipt line.
 */
final class GoodsReceiptLineDTO extends BaseDTO
{
    public function __construct(
        public readonly string $purchase_order_line_id,
        public readonly string $product_id,
        public readonly float $ordered_quantity,
        public readonly float $received_quantity,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            purchase_order_line_id: (string) $data['purchase_order_line_id'],
            product_id: (string) $data['product_id'],
            ordered_quantity: (float) $data['ordered_quantity'],
            received_quantity: (float) $data['received_quantity'],
        );
    }
}
