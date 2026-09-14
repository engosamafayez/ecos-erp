<?php

declare(strict_types=1);

namespace Modules\AI\Application\Services;

use App\Core\Company\TenantOwnershipResolver;
use App\Models\User;
use Modules\AI\Domain\Enums\AIToolScope;
use Modules\AI\Domain\ValueObjects\AIRequestContext;
use Modules\AI\Domain\ValueObjects\AIToolResult;
use Modules\IAM\Domain\Contracts\AuthorizationGatewayInterface;
use Throwable;

/**
 * §11 — the ONE choke point every proposed tool call passes through. The model
 * never bypasses this: {@see AIAssistantService}
 * calls only this class, never a tool directly.
 *
 * Required invariant enforced here: AI capability <= the user's ordinary ECOS
 * capability — every check below is the exact same authority (AuthorizationGateway,
 * TenantOwnershipResolver) every non-AI controller in this codebase already uses,
 * never a parallel AI-specific authorization model.
 */
class AIToolInvoker
{
    /** Blanket guard against a grossly oversized argument; per-tool schemas narrow further. */
    private const MAX_INPUT_STRING_LENGTH = 500;

    /**
     * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §10 — $entryPermission
     * was a hardcoded 'ai.assistant.use' literal; CRM-03's architecture report explicitly
     * forbids Voice reusing that permission as its own customer-call gate (§35), so this is now
     * the smallest safe parameterization that lets both surfaces share this exact class rather
     * than duplicating its 9-step choke point. CORE-03's own binding (AIServiceProvider) passes
     * 'ai.assistant.use' explicitly — its behavior is unchanged. Not `final` for the same reason
     * — {@see \Modules\CustomerEngagement\Voice\Application\Services\VoiceAIToolInvoker} exists
     * purely to give Voice's invoker its own container-resolvable type, no override.
     */
    public function __construct(
        private readonly AIToolRegistry $registry,
        private readonly AuthorizationGatewayInterface $authorization,
        private readonly TenantOwnershipResolver $tenant,
        private readonly AIAuditService $audit,
        private readonly string $entryPermission,
    ) {}

    /**
     * @param  array<string, mixed>  $rawInput  Untrusted, model-supplied.
     */
    public function invoke(AIRequestContext $context, User $user, string $toolName, array $rawInput): AIToolResult
    {
        $this->audit->toolRequested($context, $toolName, ['input_keys' => array_keys($rawInput)]);

        // 2. Entry gate — required even here, in addition to the assistant API's own
        // check, so a future second caller of this invoker can never skip it.
        if ($this->authorization->decision($user, $this->entryPermission)->isDenied()) {
            $this->audit->toolDenied($context, $toolName, "missing {$this->entryPermission}");

            return AIToolResult::denied('AI assistant access is not enabled for this account.');
        }

        // 3. Explicit registry lookup — an unknown name is denied, never resolved by
        // reflection or class-name guessing (§10).
        if (! $this->registry->has($toolName)) {
            $this->audit->toolDenied($context, $toolName, 'unknown tool');

            return AIToolResult::denied('Unknown tool.');
        }

        $tool = $this->registry->resolve($toolName);

        // 4. The tool's OWN existing domain permission — never substituted by
        // ai.assistant.use, which only gates the entry point (§8/§6).
        if ($this->authorization->decision($user, $tool->permission())->isDenied()) {
            $this->audit->toolDenied($context, $toolName, "missing {$tool->permission()}");

            return AIToolResult::denied("This action requires the \"{$tool->permission()}\" permission.");
        }

        // 5. Company scope. context->companyId is always server-resolved from the
        // authenticated user (never client input), so this is defense in depth against
        // a future context-building bug, not a live gate against a real request.
        if ($tool->scope() !== AIToolScope::None && ! $this->tenant->owns($context->companyId)) {
            $this->audit->toolDenied($context, $toolName, 'company scope check failed');

            return AIToolResult::denied('Company scope could not be verified.');
        }

        // 6. Brand/entity scope: no central BrandOwnershipResolver exists in this
        // codebase (§13) — each Brand-scoped tool re-validates brand_id/entity_id
        // against the current company inside its own execute(), using the exact same
        // check its owning module's controller already applies. Never trusted here.

        // 7. Blanket input-size guard; each tool's execute() still validates shape/type.
        foreach ($rawInput as $value) {
            if (is_string($value) && strlen($value) > self::MAX_INPUT_STRING_LENGTH) {
                $this->audit->toolDenied($context, $toolName, 'input value too large');

                return AIToolResult::invalidInput('Input value too large.');
            }
        }

        // 8. Audit the allow decision before executing.
        $this->audit->toolAllowed($context, $toolName);

        // 9. Execute — a tool throwing is treated as a bounded error, never a 500 that
        // could leak a stack trace back through the assistant response (§21).
        try {
            $result = $tool->execute($context, $user, $rawInput);
        } catch (Throwable $e) {
            $this->audit->toolExecuted($context, $toolName, 'error', ['exception' => $e::class]);

            return AIToolResult::error('This tool could not complete the request.');
        }

        // 10/11. Output is already minimized by the tool itself (explicit projection,
        // §21/§30); audit the outcome.
        $this->audit->toolExecuted($context, $toolName, $result->status->value);

        return $result;
    }
}
