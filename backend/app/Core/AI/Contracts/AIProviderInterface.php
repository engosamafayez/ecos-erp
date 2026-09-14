<?php

declare(strict_types=1);

namespace App\Core\AI\Contracts;

use App\Core\AI\Exceptions\AIProviderUnavailableException;
use App\Core\AI\ValueObjects\AIProviderMessage;
use App\Core\AI\ValueObjects\AIProviderResponse;
use App\Core\AI\ValueObjects\AIProviderToolDefinition;

/**
 * The single seam between Resident AI and any model vendor (§2). Nothing outside
 * a concrete provider class may know it is talking to OpenAI, or any other
 * vendor — callers depend on this interface only.
 */
interface AIProviderInterface
{
    /**
     * @param  list<AIProviderMessage>  $messages
     * @param  list<AIProviderToolDefinition>  $tools
     *
     * @throws AIProviderUnavailableException
     */
    public function respond(string $systemPrompt, array $messages, array $tools): AIProviderResponse;
}
