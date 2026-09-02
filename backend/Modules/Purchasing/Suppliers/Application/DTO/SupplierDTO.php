<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Application\DTO;

use App\Core\DTO\BaseDTO;

/**
 * Immutable input for creating/updating a supplier. Property names map directly
 * to the `suppliers` table columns.
 */
final class SupplierDTO extends BaseDTO
{
    public function __construct(
        public readonly string $name,
        // Backend-owned (SupplierCodeGeneratorService) — null means "generate on create";
        // ignored entirely on update (TASK-ECOS-PROCUREMENT-SUPPLIERS-BATCH-01-MASTER-DATA-002).
        public readonly ?string $code = null,
        public readonly ?string $supplier_category_id = null,
        public readonly ?string $contact_person = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $mobile = null,
        public readonly ?string $country = null,
        public readonly ?string $city = null,
        public readonly ?string $state = null,
        public readonly ?string $district = null,
        public readonly ?string $address = null,
        public readonly ?string $google_maps_url = null,
        public readonly ?string $notes = null,
        public readonly bool $is_active = true,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            name: (string) $data['name'],
            code: self::nullableString($data, 'code'),
            supplier_category_id: self::nullableString($data, 'supplier_category_id'),
            contact_person: self::nullableString($data, 'contact_person'),
            email: self::nullableString($data, 'email'),
            phone: self::nullableString($data, 'phone'),
            mobile: self::nullableString($data, 'mobile'),
            country: self::nullableString($data, 'country'),
            city: self::nullableString($data, 'city'),
            state: self::nullableString($data, 'state'),
            district: self::nullableString($data, 'district'),
            address: self::nullableString($data, 'address'),
            google_maps_url: self::nullableString($data, 'google_maps_url'),
            notes: self::nullableString($data, 'notes'),
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
