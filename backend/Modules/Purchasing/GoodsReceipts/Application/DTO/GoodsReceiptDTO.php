<?php

declare(strict_types=1);

namespace Modules\Purchasing\GoodsReceipts\Application\DTO;

use App\Core\DTO\BaseDTO;

/**
 * Immutable input for creating / updating a goods receipt.
 */
final class GoodsReceiptDTO extends BaseDTO
{
    /**
     * @param  list<GoodsReceiptLineDTO>  $lines
     */
    public function __construct(
        public readonly string $purchase_order_id,
        public readonly string $warehouse_id,
        public readonly string $receipt_date,
        public readonly ?string $notes,
        public readonly array $lines,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $rawLines = is_array($data['lines'] ?? null) ? $data['lines'] : [];

        $lines = array_map(
            fn (mixed $line): GoodsReceiptLineDTO => GoodsReceiptLineDTO::fromArray((array) $line),
            $rawLines,
        );

        return new self(
            purchase_order_id: (string) $data['purchase_order_id'],
            warehouse_id: (string) $data['warehouse_id'],
            receipt_date: (string) $data['receipt_date'],
            notes: self::nullableString($data, 'notes'),
            lines: array_values($lines),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function nullableString(array $data, string $key): ?string
    {
        $value = $data[$key] ?? null;

        return $value === null || $value === '' ? null : (string) $value;
    }
}
