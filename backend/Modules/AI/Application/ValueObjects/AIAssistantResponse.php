<?php

declare(strict_types=1);

namespace Modules\AI\Application\ValueObjects;

use Modules\AI\Domain\ValueObjects\AIEntityReference;

/**
 * The bounded shape returned to the HTTP layer (§25). `status` lets the
 * frontend distinguish a normal answer from a safely-stopped one — never a
 * silent, unexplained truncation.
 */
final class AIAssistantResponse
{
    /**
     * @param  list<AIEntityReference>  $references
     */
    public function __construct(
        public readonly string $status,
        public readonly ?string $message,
        public readonly array $references = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'message' => $this->message,
            'references' => array_map(static fn (AIEntityReference $r): array => $r->toArray(), $this->references),
        ];
    }
}
