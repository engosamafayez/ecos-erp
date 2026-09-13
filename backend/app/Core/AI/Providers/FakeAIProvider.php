<?php

declare(strict_types=1);

namespace App\Core\AI\Providers;

use App\Core\AI\Contracts\AIProviderInterface;
use App\Core\AI\Exceptions\AIProviderUnavailableException;
use App\Core\AI\ValueObjects\AIProviderResponse;
use App\Core\AI\ValueObjects\AIProviderToolCall;

/**
 * Deterministic provider for automated tests (§5). Never makes a network call.
 * Programmed with an explicit queue of responses (or an exception to throw),
 * consumed one per {@see respond()} call — the last entry repeats once the
 * queue is exhausted, so a test doesn't have to script every round exactly.
 */
final class FakeAIProvider implements AIProviderInterface
{
    /** @var list<AIProviderResponse|AIProviderUnavailableException> */
    private array $queue;

    /** @var list<array{systemPrompt: string, messages: array, tools: array}> */
    private array $calls = [];

    /**
     * @param  list<AIProviderResponse|AIProviderUnavailableException>  $queue
     */
    public function __construct(array $queue = [])
    {
        $this->queue = $queue !== [] ? $queue : [new AIProviderResponse('OK', [])];
    }

    public static function withText(string $text): self
    {
        return new self([new AIProviderResponse($text, [])]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public static function withToolCall(string $toolName, array $arguments, string $callId = 'call_1'): self
    {
        return new self([new AIProviderResponse(null, [new AIProviderToolCall($callId, $toolName, $arguments)])]);
    }

    public static function unavailable(): self
    {
        return new self([AIProviderUnavailableException::requestFailed('simulated provider failure')]);
    }

    public function respond(string $systemPrompt, array $messages, array $tools): AIProviderResponse
    {
        $this->calls[] = ['systemPrompt' => $systemPrompt, 'messages' => $messages, 'tools' => $tools];

        $next = $this->queue[count($this->calls) - 1] ?? $this->queue[array_key_last($this->queue)];

        if ($next instanceof AIProviderUnavailableException) {
            throw $next;
        }

        return $next;
    }

    /** @return list<array{systemPrompt: string, messages: array, tools: array}> */
    public function recordedCalls(): array
    {
        return $this->calls;
    }
}
