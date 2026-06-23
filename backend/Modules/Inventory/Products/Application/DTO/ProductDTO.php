<?php

declare(strict_types=1);

namespace Modules\Inventory\Products\Application\DTO;

use App\Core\DTO\BaseDTO;

/**
 * Immutable input for creating/updating a product. Property names map directly
 * to the `products` table columns.
 */
final class ProductDTO extends BaseDTO
{
    public function __construct(
        public readonly string $sku,
        public readonly string $name,
        public readonly string $category_id,
        public readonly string $unit_id,
        public readonly string $product_type,
        public readonly ?string $barcode = null,
        public readonly ?string $description = null,
        public readonly bool $is_active = true,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            sku: (string) $data['sku'],
            name: (string) $data['name'],
            category_id: (string) $data['category_id'],
            unit_id: (string) $data['unit_id'],
            product_type: (string) $data['product_type'],
            barcode: self::nullableString($data, 'barcode'),
            description: self::nullableString($data, 'description'),
            is_active: (bool) ($data['is_active'] ?? true),
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
