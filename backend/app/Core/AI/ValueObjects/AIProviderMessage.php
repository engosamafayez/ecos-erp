<?php

declare(strict_types=1);

namespace App\Core\AI\ValueObjects;

/**
 * One turn in the conversation handed to a provider. `role` is one of
 * 'system' | 'user' | 'assistant' | 'tool'. A 'tool' message carries the
 * server-computed {@see AIToolResult} for a prior tool call back to the model —
 * never the model's own unverified claim about what a tool returned.
 */
final class AIProviderMessage
{
    public function __construct(
        public readonly string $role,
        public readonly string $content,
        public readonly ?string $toolCallId = null,
        public readonly ?string $toolName = null,
    ) {}

    public static function system(string $content): self
    {
        return new self('system', $content);
    }

    public static function user(string $content): self
    {
        return new self('user', $content);
    }

    public static function assistant(string $content): self
    {
        return new self('assistant', $content);
    }

    public static function tool(string $toolCallId, string $toolName, string $content): self
    {
        return new self('tool', $content, $toolCallId, $toolName);
    }
}
