<?php

declare(strict_types=1);

namespace Modules\Organization\Branches\Application\DTO;

use App\Core\DTO\BaseDTO;

/**
 * Immutable input for creating/updating a branch. Property names map directly
 * to the `branches` table columns.
 */
final class BranchDTO extends BaseDTO
{
    public function __construct(
        public readonly string $company_id,
        public readonly string $code,
        public readonly string $name,
        public readonly ?string $phone = null,
        public readonly ?string $email = null,
        public readonly ?string $manager_name = null,
        public readonly ?string $address = null,
        public readonly ?string $city = null,
        public readonly ?string $country = null,
        public readonly bool $is_head_office = false,
        public readonly bool $is_active = true,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            company_id: (string) $data['company_id'],
            code: (string) $data['code'],
            name: (string) $data['name'],
            phone: self::nullableString($data, 'phone'),
            email: self::nullableString($data, 'email'),
            manager_name: self::nullableString($data, 'manager_name'),
            address: self::nullableString($data, 'address'),
            city: self::nullableString($data, 'city'),
            country: self::nullableString($data, 'country'),
            is_head_office: (bool) ($data['is_head_office'] ?? false),
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
