<?php

declare(strict_types=1);

namespace Modules\AI\Application\Services;

use App\Core\AI\Contracts\AIProviderInterface;
use App\Core\AI\Exceptions\AIProviderUnavailableException;
use App\Core\AI\ValueObjects\AIProviderMessage;
use App\Core\AI\ValueObjects\AIProviderToolDefinition;
use App\Models\User;
use Modules\AI\Application\ValueObjects\AIAssistantResponse;
use Modules\AI\Domain\Contracts\AIToolInterface;
use Modules\AI\Domain\ValueObjects\AIRequestContext;
use Modules\IAM\Domain\Contracts\AuthorizationGatewayInterface;

/**
 * §25 — the single orchestrator behind the assistant HTTP API. Builds the
 * system policy, offers the model only the tools this SPECIFIC user could pass
 * (a UX/efficiency filter, not the real gate — AIToolInvoker re-checks every
 * one regardless), loops proposed tool calls through AIToolInvoker up to a hard
 * bound, and returns a bounded response. Never streams (§17/§34).
 */
final class AIAssistantService
{
    public function __construct(
        private readonly AIProviderInterface $provider,
        private readonly AIToolRegistry $registry,
        private readonly AIToolInvoker $invoker,
        private readonly SystemPolicyBuilder $policy,
        private readonly AIAuditService $audit,
        private readonly AuthorizationGatewayInterface $authorization,
        private readonly int $maxToolCalls,
    ) {}

    /**
     * @param  list<AIProviderMessage>  $recentHistory  Already length/count-bounded by the caller (§26).
     */
    public function handle(AIRequestContext $context, User $user, string $userMessage, array $recentHistory): AIAssistantResponse
    {
        $this->audit->sessionRequest($context, ['module' => $context->module, 'page' => $context->page]);

        if ($this->authorization->decision($user, 'ai.assistant.use')->isDenied()) {
            return new AIAssistantResponse('denied', 'AI assistant access is not enabled for this account.');
        }

        $systemPrompt = $this->policy->build($context);
        $messages = [...$recentHistory, AIProviderMessage::user($userMessage)];
        $toolDefinitions = $this->offeredToolDefinitions($user);

        $references = [];
        $toolCallsUsed = 0;

        while (true) {
            try {
                $response = $this->provider->respond($systemPrompt, $messages, $toolDefinitions);
            } catch (AIProviderUnavailableException $e) {
                $this->audit->providerFailure($context, $e->getMessage());

                return new AIAssistantResponse('unavailable', null, $references);
            }

            if (! $response->hasToolCalls()) {
                return new AIAssistantResponse('ok', $response->text, $references);
            }

            if ($toolCallsUsed >= $this->maxToolCalls) {
                // §27 — stop safely rather than loop forever; the user still gets
                // whatever references earlier rounds already collected.
                return new AIAssistantResponse('tool_limit_reached', $response->text, $references);
            }

            $messages[] = AIProviderMessage::assistant($response->text ?? '');

            foreach ($response->toolCalls as $call) {
                if ($toolCallsUsed >= $this->maxToolCalls) {
                    break;
                }

                $toolCallsUsed++;
                $result = $this->invoker->invoke($context, $user, $call->name, $call->arguments);
                $references = [...$references, ...$result->references];

                $messages[] = AIProviderMessage::tool($call->id, $call->name, json_encode($result->toArray(), JSON_THROW_ON_ERROR));
            }
        }
    }

    /** @return list<AIProviderToolDefinition> */
    private function offeredToolDefinitions(User $user): array
    {
        return array_values(array_filter(array_map(
            function (AIToolInterface $tool) use ($user): ?AIProviderToolDefinition {
                if ($this->authorization->decision($user, $tool->permission())->isDenied()) {
                    return null;
                }

                return new AIProviderToolDefinition($tool->name(), $tool->description(), $tool->inputSchema());
            },
            $this->registry->all(),
        )));
    }
}
