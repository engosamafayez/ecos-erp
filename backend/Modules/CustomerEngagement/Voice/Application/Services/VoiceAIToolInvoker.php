<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Application\Services;

use App\Core\Company\TenantOwnershipResolver;
use App\Models\User;
use Modules\AI\Application\Services\AIAuditService;
use Modules\AI\Application\Services\AIToolInvoker;
use Modules\AI\Application\Services\AIToolRegistry;
use Modules\AI\Domain\ValueObjects\AIRequestContext;
use Modules\AI\Domain\ValueObjects\AIToolResult;
use Modules\CustomerEngagement\Voice\Domain\Enums\CallerVerificationLevel;
use Modules\CustomerEngagement\Voice\Domain\Models\Call;
use Modules\IAM\Domain\Contracts\AuthorizationGatewayInterface;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §10 introduced this as a
 * zero-override subclass; TASK-ECOS-V1.1-CRM-03-VOICE-UX-AND-FINAL-SOURCE-CLOSURE-016 §5 (Gap
 * B) adds the ONE thing it was missing: caller verification is now re-checked HERE, at the
 * final execution choke point, independently of whatever a realtime session was configured to
 * offer.
 *
 * "Tool visible to model AND tool requested does NOT imply tool executable" (§5): this override
 * runs BEFORE parent::invoke() and reads verification from the CURRENT canonical Call row,
 * fetched fresh from the database and re-scoped to the context's own company_id — never from
 * $rawInput (model-supplied), never from any value the frontend/session could have supplied,
 * never from a cached/stale snapshot. A call that was verified when the session started but is
 * (hypothetically) un-verified by the time a later tool call arrives is re-evaluated correctly
 * because nothing is cached between calls — each invoke() re-reads the Call row.
 *
 * Everything else about the parent's 9-step choke point (entry permission, registry lookup,
 * domain permission, tenant scope, audit) is untouched and still runs via parent::invoke() —
 * this is additive, not a replacement.
 */
final class VoiceAIToolInvoker extends AIToolInvoker
{
    public function __construct(
        AIToolRegistry $registry,
        AuthorizationGatewayInterface $authorization,
        TenantOwnershipResolver $tenant,
        AIAuditService $audit,
        string $entryPermission,
        private readonly VoiceToolCatalogService $catalog,
        private readonly CallerVerificationService $verification,
        private readonly AIAuditService $verificationAudit,
    ) {
        parent::__construct($registry, $authorization, $tenant, $audit, $entryPermission);
    }

    public function invoke(AIRequestContext $context, User $user, string $toolName, array $rawInput): AIToolResult
    {
        if ($this->catalog->requiresVerification($toolName)) {
            $call = $this->resolveCall($context);
            $level = $call !== null ? $this->verification->levelOf($call) : CallerVerificationLevel::Unverified;

            if ($level !== CallerVerificationLevel::OrderCorroborated) {
                $this->verificationAudit->toolDenied($context, $toolName, 'caller not verified');

                return AIToolResult::denied('This information requires verifying the caller first.');
            }
        }

        return parent::invoke($context, $user, $toolName, $rawInput);
    }

    /**
     * Resolves the Call the CURRENT invocation belongs to strictly server-side — via the
     * company-scoped entityId Voice itself put in the context when building it (never trusting
     * $rawInput, which is model-supplied and therefore untrusted per §5/§8).
     */
    private function resolveCall(AIRequestContext $context): ?Call
    {
        if ($context->entityType !== 'cep_call' || $context->entityId === null) {
            return null;
        }

        return Call::query()->where('company_id', $context->companyId)->find($context->entityId);
    }
}
