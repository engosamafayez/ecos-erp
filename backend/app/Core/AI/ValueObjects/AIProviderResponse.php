<?php

declare(strict_types=1);

namespace App\Core\AI\ValueObjects;

/**
 * What a provider returned for one turn: optional assistant text, zero or more
 * proposed tool calls, and provider metadata (model/token usage) — never a
 * provider-specific SDK object (§2: "Do not expose provider-specific response
 * objects outside the provider adapter").
 */
final class AIProviderResponse
{
    /**
     * @param  list<AIProviderToolCall>  $toolCalls
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly ?string $text,
        public readonly array $toolCalls,
        public readonly array $metadata = [],
    ) {}

    public function hasToolCalls(): bool
    {
        return $this->toolCalls !== [];
    }
}
