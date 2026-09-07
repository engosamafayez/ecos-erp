<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Application\DTO;

use App\Core\DTO\BaseDTO;

final class SupplierCategoryDTO extends BaseDTO
{
    public function __construct(
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $name_ar = null,
        public readonly bool $is_active = true,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            // §3 — no longer accepted from the client on create (CreateSupplierCategoryAction
            // always overwrites it via SupplierCategoryCodeGeneratorService); still readable
            // here for update, where the caller sends the category's own existing code back.
            code: (string) ($data['code'] ?? ''),
            name: (string) $data['name'],
            name_ar: self::nullableString($data, 'name_ar'),
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
