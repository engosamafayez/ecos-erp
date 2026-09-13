<?php

declare(strict_types=1);

namespace App\Core\AI\Providers;

use App\Core\AI\Contracts\AIProviderInterface;
use App\Core\AI\Exceptions\AIProviderUnavailableException;
use App\Core\AI\ValueObjects\AIProviderResponse;

/**
 * Bound in place of a real provider whenever `config('ai.enabled')` is false
 * (§4). Fails closed on every call rather than the caller having to remember
 * to check the flag itself.
 */
final class DisabledAIProvider implements AIProviderInterface
{
    public function respond(string $systemPrompt, array $messages, array $tools): AIProviderResponse
    {
        throw AIProviderUnavailableException::disabled();
    }
}
