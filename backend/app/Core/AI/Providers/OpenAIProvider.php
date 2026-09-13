<?php

declare(strict_types=1);

namespace App\Core\AI\Providers;

use App\Core\AI\Contracts\AIProviderInterface;
use App\Core\AI\Exceptions\AIProviderUnavailableException;
use App\Core\AI\ValueObjects\AIProviderMessage;
use App\Core\AI\ValueObjects\AIProviderResponse;
use App\Core\AI\ValueObjects\AIProviderToolCall;
use App\Core\AI\ValueObjects\AIProviderToolDefinition;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The initial V1 provider (§3), using Laravel's own Http facade — no new HTTP
 * client dependency. Talks to the OpenAI Chat Completions "tools" API and never
 * lets that vendor-specific response shape leak past this class.
 */
final class OpenAIProvider implements AIProviderInterface
{
    public function __construct(
        private readonly ?string $apiKey,
        private readonly string $model,
        private readonly string $baseUrl,
        private readonly int $timeoutSeconds,
        private readonly int $maxResponseTokens,
    ) {}

    public function respond(string $systemPrompt, array $messages, array $tools): AIProviderResponse
    {
        if ($this->apiKey === null || trim($this->apiKey) === '') {
            throw AIProviderUnavailableException::misconfigured('OPENAI_API_KEY is not set.');
        }

        $payload = [
            'model' => $this->model,
            'max_tokens' => $this->maxResponseTokens,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ...array_map($this->toOpenAiMessage(...), $messages),
            ],
        ];

        if ($tools !== []) {
            $payload['tools'] = array_map(
                static fn (AIProviderToolDefinition $tool): array => ['type' => 'function', 'function' => $tool->toArray()],
                $tools,
            );
        }

        try {
            $response = Http::withToken($this->apiKey)
                ->timeout($this->timeoutSeconds)
                ->baseUrl($this->baseUrl)
                ->post('/chat/completions', $payload);
        } catch (ConnectionException) {
            throw AIProviderUnavailableException::timedOut();
        } catch (Throwable $e) {
            throw AIProviderUnavailableException::requestFailed($e->getMessage());
        }

        if ($response->failed()) {
            throw AIProviderUnavailableException::requestFailed(
                'HTTP '.$response->status().': '.$response->body(),
            );
        }

        $message = $response->json('choices.0.message');

        if (! is_array($message)) {
            throw AIProviderUnavailableException::malformedResponse('missing choices[0].message');
        }

        return new AIProviderResponse(
            text: is_string($message['content'] ?? null) ? $message['content'] : null,
            toolCalls: $this->parseToolCalls($message['tool_calls'] ?? []),
            metadata: [
                'model' => $response->json('model'),
                'usage' => $response->json('usage'),
                'finish_reason' => $response->json('choices.0.finish_reason'),
            ],
        );
    }

    private function toOpenAiMessage(AIProviderMessage $message): array
    {
        if ($message->role === 'tool') {
            return [
                'role' => 'tool',
                'tool_call_id' => $message->toolCallId,
                'content' => $message->content,
            ];
        }

        return ['role' => $message->role, 'content' => $message->content];
    }

    /**
     * @return list<AIProviderToolCall>
     */
    private function parseToolCalls(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $calls = [];

        foreach ($raw as $call) {
            if (! is_array($call) || ! isset($call['id'], $call['function']['name'])) {
                throw AIProviderUnavailableException::malformedResponse('tool_calls entry missing id/function.name');
            }

            $argumentsJson = $call['function']['arguments'] ?? '{}';
            $arguments = is_string($argumentsJson) ? json_decode($argumentsJson, true) : null;

            if (! is_array($arguments)) {
                throw AIProviderUnavailableException::malformedResponse('tool_calls entry has non-JSON-object arguments');
            }

            $calls[] = new AIProviderToolCall((string) $call['id'], (string) $call['function']['name'], $arguments);
        }

        return $calls;
    }
}
