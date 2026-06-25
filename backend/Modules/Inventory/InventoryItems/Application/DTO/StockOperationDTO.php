<?php

declare(strict_types=1);

namespace Modules\Inventory\InventoryItems\Application\DTO;

use App\Core\DTO\BaseDTO;

/**
 * Shared input for all stock-movement actions (Receive, Reserve, Release, Ship).
 */
final class StockOperationDTO extends BaseDTO
{
    public function __construct(
        public readonly string $warehouse_id,
        public readonly string $product_id,
        public readonly string $company_id,
        public readonly float $quantity,
        public readonly ?string $reference_type = null,
        public readonly ?string $reference_id   = null,
        public readonly ?string $notes          = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            warehouse_id:   (string) $data['warehouse_id'],
            product_id:     (string) $data['product_id'],
            company_id:     (string) $data['company_id'],
            quantity:       (float)  $data['quantity'],
            reference_type: isset($data['reference_type']) && $data['reference_type'] !== ''
                                ? (string) $data['reference_type'] : null,
            reference_id:   isset($data['reference_id'])   && $data['reference_id']   !== ''
                                ? (string) $data['reference_id']   : null,
            notes:          isset($data['notes'])           && $data['notes']           !== ''
                                ? (string) $data['notes']           : null,
        );
    }
}
