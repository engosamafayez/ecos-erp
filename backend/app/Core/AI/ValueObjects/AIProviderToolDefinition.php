<?php

declare(strict_types=1);

namespace App\Core\AI\ValueObjects;

/**
 * The provider-facing description of one callable tool — name, description and
 * JSON Schema input shape. This is the ONLY thing a provider ever learns about a
 * tool; it never sees the tool's permission, scope, or implementation.
 */
final class AIProviderToolDefinition
{
    /**
     * @param  array<string, mixed>  $inputSchema  JSON Schema (type: object, properties, required).
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly array $inputSchema,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'description' => $this->description,
            'parameters' => $this->inputSchema,
        ];
    }
}
