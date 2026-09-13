<?php

declare(strict_types=1);

namespace App\Core\AI\Exceptions;

use RuntimeException;

/**
 * Thrown by a provider (never by the caller) whenever it cannot honestly answer:
 * disabled, misconfigured, missing credentials, timeout, or a malformed upstream
 * response (§4: fail closed, never fabricate an assistant answer).
 */
final class AIProviderUnavailableException extends RuntimeException
{
    public static function disabled(): self
    {
        return new self('AI assistant is currently disabled.');
    }

    public static function misconfigured(string $detail): self
    {
        return new self("AI provider is misconfigured: {$detail}");
    }

    public static function timedOut(): self
    {
        return new self('AI provider request timed out.');
    }

    public static function malformedResponse(string $detail): self
    {
        return new self("AI provider returned a malformed response: {$detail}");
    }

    public static function requestFailed(string $detail): self
    {
        return new self("AI provider request failed: {$detail}");
    }
}
