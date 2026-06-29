<?php

declare(strict_types=1);

namespace Modules\Manufacturing\ManufacturingService\Application\DTOs\Requests;

/**
 * Input DTO for ManufacturingApplicationService::disassembleProduct().
 *
 * Placeholder — disassembly is not yet implemented.
 * Reserved for future manufacturing reverse operations where a finished
 * good is broken down back into its raw material components.
 */
final readonly class DisassembleProductRequest
{
    public function __construct(
        public string $product_id,
        public string $warehouse_id,
        public float $quantity,
        public string $actor_id,
        public array $metadata = [],
    ) {}
}
