<?php

declare(strict_types=1);

namespace Modules\AI\Application\Services;

use App\Core\Audit\AuditService;
use Modules\AI\Domain\ValueObjects\AIRequestContext;

/**
 * Thin, Resident-AI-specific facade over the generic App\Core\Audit\AuditService
 * (§29, mirrors Modules\IAM\Application\Services\UserAuditService's exact
 * pattern). Records every assistant/tool event against entity_type = 'ai_tool_call'
 * or 'ai_session'. Never throws (the underlying service swallows failures) and
 * never stores full prompt/response bodies — only structured, minimal metadata
 * (§29: "Do NOT store full prompts/responses by default").
 */
final class AIAuditService
{
    public const ENTITY_TOOL_CALL = 'ai_tool_call';

    public const ENTITY_SESSION = 'ai_session';

    public function __construct(private readonly AuditService $audit) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function toolRequested(AIRequestContext $context, string $toolName, array $metadata = []): void
    {
        $this->record('ai.tool_requested', $context, $toolName, $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function toolAllowed(AIRequestContext $context, string $toolName, array $metadata = []): void
    {
        $this->record('ai.tool_allowed', $context, $toolName, $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function toolDenied(AIRequestContext $context, string $toolName, string $reason, array $metadata = []): void
    {
        $this->record('ai.tool_denied', $context, $toolName, [...$metadata, 'reason' => $reason]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function toolExecuted(AIRequestContext $context, string $toolName, string $status, array $metadata = []): void
    {
        $this->record('ai.tool_executed', $context, $toolName, [...$metadata, 'result_status' => $status]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function sessionRequest(AIRequestContext $context, array $metadata = []): void
    {
        $this->record('ai.assistant_request', $context, self::ENTITY_SESSION, $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function providerFailure(AIRequestContext $context, string $reason, array $metadata = []): void
    {
        $this->record('ai.provider_failure', $context, self::ENTITY_SESSION, [...$metadata, 'reason' => $reason]);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(string $action, AIRequestContext $context, string $entityId, array $metadata): void
    {
        $this->audit->record(
            action: $action,
            entityType: self::ENTITY_TOOL_CALL,
            entityId: $entityId,
            companyId: $context->companyId,
            userId: $context->userId,
            metadata: $metadata,
        );
    }
}
