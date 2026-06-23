<?php

declare(strict_types=1);

namespace Modules\MasterData\Warehouses\Application\DTO;

use App\Core\DTO\BaseDTO;

/**
 * Immutable input for creating/updating a warehouse. Property names map directly
 * to the `warehouses` table columns.
 */
final class WarehouseDTO extends BaseDTO
{
    public function __construct(
        public readonly string $company_id,
        public readonly string $branch_id,
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $address = null,
        public readonly ?string $city = null,
        public readonly ?string $country = null,
        public readonly bool $is_active = true,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            company_id: (string) $data['company_id'],
            branch_id: (string) $data['branch_id'],
            code: (string) $data['code'],
            name: (string) $data['name'],
            address: self::nullableString($data, 'address'),
            city: self::nullableString($data, 'city'),
            country: self::nullableString($data, 'country'),
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
