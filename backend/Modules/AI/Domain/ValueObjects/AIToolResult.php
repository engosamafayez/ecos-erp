<?php

declare(strict_types=1);

namespace Modules\AI\Domain\ValueObjects;

use Modules\AI\Domain\Enums\AIToolStatus;

/**
 * §21 — the bounded, predictable shape every tool returns. `data` must already
 * be a hand-projected array of primitives (never an Eloquent model or resource
 * graph — §21/§30: explicit projection, not "serialize then redact"). `message`
 * is a short, model-safe natural-language explanation used when `status` isn't
 * Success (e.g. "Order not found in your company.").
 */
final class AIToolResult
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<AIEntityReference>  $references
     * @param  array<string, mixed>  $metadata
     */
    private function __construct(
        public readonly AIToolStatus $status,
        public readonly array $data,
        public readonly array $references = [],
        public readonly array $metadata = [],
        public readonly ?string $message = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @param  list<AIEntityReference>  $references
     */
    public static function success(array $data, array $references = [], array $metadata = []): self
    {
        return new self(AIToolStatus::Success, $data, $references, $metadata);
    }

    public static function notFound(string $message): self
    {
        return new self(AIToolStatus::NotFound, [], message: $message);
    }

    public static function unavailable(string $message): self
    {
        return new self(AIToolStatus::Unavailable, [], message: $message);
    }

    public static function denied(string $message): self
    {
        return new self(AIToolStatus::Denied, [], message: $message);
    }

    public static function invalidInput(string $message): self
    {
        return new self(AIToolStatus::InvalidInput, [], message: $message);
    }

    public static function error(string $message): self
    {
        return new self(AIToolStatus::Error, [], message: $message);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status->value,
            'data' => $this->data,
            'references' => array_map(static fn (AIEntityReference $r): array => $r->toArray(), $this->references),
            'metadata' => $this->metadata,
            'message' => $this->message,
        ];
    }
}
