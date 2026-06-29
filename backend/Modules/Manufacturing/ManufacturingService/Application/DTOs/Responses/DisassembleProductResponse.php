<?php

declare(strict_types=1);

namespace Modules\Manufacturing\ManufacturingService\Application\DTOs\Responses;

/**
 * Placeholder response from ManufacturingApplicationService::disassembleProduct().
 *
 * Disassembly is a future manufacturing reverse operation: a finished good
 * is broken down back into its raw material components. It is the inverse
 * of manufactureProduct().
 *
 * This placeholder exists to establish the public API surface now so callers
 * can integrate against it before the implementation is ready. Check
 * implemented = false to detect the placeholder at runtime.
 */
final readonly class DisassembleProductResponse
{
    public function __construct(
        public bool $implemented = false,
        public string $message = 'Disassembly is not yet implemented. '
            . 'This operation will reverse a manufacturing run by returning finished '
            . 'goods to raw material components. It is planned for a future package.',
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'implemented' => $this->implemented,
            'message'     => $this->message,
        ];
    }
}
