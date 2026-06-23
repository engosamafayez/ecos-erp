<?php

declare(strict_types=1);

namespace Modules\Manufacturing\BillsOfMaterials\Application\DTO;

use App\Core\DTO\BaseDTO;

final class BomDTO extends BaseDTO
{
    /**
     * @param  list<BomLineDTO>  $lines
     */
    public function __construct(
        public readonly string $product_id,
        public readonly string $version,
        public readonly bool $is_active,
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
            fn (mixed $line): BomLineDTO => BomLineDTO::fromArray((array) $line),
            $rawLines,
        );

        return new self(
            product_id: (string) $data['product_id'],
            version: (string) ($data['version'] ?? '1.0'),
            is_active: (bool) ($data['is_active'] ?? false),
            notes: isset($data['notes']) && $data['notes'] !== '' ? (string) $data['notes'] : null,
            lines: array_values($lines),
        );
    }
}
