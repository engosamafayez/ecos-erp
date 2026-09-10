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
        // Legacy single-category field (Task 2) — accepted for backward compatibility with
        // any caller that has not moved to `supplier_category_ids` yet, but no longer trusted
        // directly: CreateSupplierAction/UpdateSupplierAction derive the real column from
        // `supplier_category_ids[0]`, falling back to this only when that array is empty
        // (TASK-...-SUPPLIER-MASTER-AND-RETURNS-FINAL-018 §A.1).
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
        // Supply Capabilities (TASK-...-SUPPLY-CAPABILITIES-003) — NOT `suppliers`
        // columns; CreateSupplierAction/UpdateSupplierAction strip these before
        // writing Supplier attributes and use them to sync the two pivot tables
        // instead. Full-replace semantics (sync), matching a multi-select UI.
        /** @var list<string> */
        public readonly array $raw_material_ids = [],
        /** @var list<string> */
        public readonly array $product_category_ids = [],
        // Supplier Category classification (TASK-...-SUPPLIER-MASTER-AND-RETURNS-FINAL-018
        // §A.1) — the canonical many-to-many replacement for `supplier_category_id`. NOT a
        // `suppliers` column; the Actions strip this before writing Supplier attributes and
        // use it to sync `supplier_category_assignments` instead. Full-replace semantics.
        /** @var list<string> */
        public readonly array $supplier_category_ids = [],
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
            raw_material_ids: self::stringList($data, 'raw_material_ids'),
            product_category_ids: self::stringList($data, 'product_category_ids'),
            supplier_category_ids: self::stringList($data, 'supplier_category_ids'),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private static function stringList(array $data, string $key): array
    {
        $value = $data[$key] ?? [];

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_map('strval', $value)));
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
