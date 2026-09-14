<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Application\Services;

use App\Core\Audit\AuditService;
use Modules\CustomerEngagement\Voice\Domain\Models\Call;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §19 — thin, Voice-specific
 * facade over the generic App\Core\Audit\AuditService, mirroring AIAuditService's exact pattern
 * (CORE-03). Records against entity_type='voice_call'/'voice_tool_call' — never a second,
 * disconnected audit table. Never logs a raw provider payload or a secret.
 */
final class VoiceAuditService
{
    public const ENTITY_CALL = 'voice_call';

    public const ENTITY_TOOL_CALL = 'voice_tool_call';

    public function __construct(private readonly AuditService $audit) {}

    public function callInitiated(string $companyId, ?int $userId, string $callId, array $metadata = []): void
    {
        $this->record('voice.call_initiated', $companyId, $userId, $callId, $metadata);
    }

    public function callReceived(string $companyId, string $callId, array $metadata = []): void
    {
        $this->record('voice.call_received', $companyId, null, $callId, $metadata);
    }

    public function identityResolved(string $companyId, string $callId, array $metadata = []): void
    {
        $this->record('voice.identity_resolved', $companyId, null, $callId, $metadata);
    }

    public function leadCreated(string $companyId, string $callId, string $leadId): void
    {
        $this->record('voice.lead_created', $companyId, null, $callId, ['lead_id' => $leadId]);
    }

    public function verificationChanged(string $companyId, string $callId, string $level): void
    {
        $this->record('voice.verification_changed', $companyId, null, $callId, ['level' => $level]);
    }

    public function sessionStarted(string $companyId, string $callId): void
    {
        $this->record('voice.session_started', $companyId, null, $callId);
    }

    public function sessionEnded(string $companyId, string $callId, string $reason): void
    {
        $this->record('voice.session_ended', $companyId, null, $callId, ['reason' => $reason]);
    }

    public function toolRequested(string $companyId, string $callId, string $toolName): void
    {
        $this->recordToolCall('voice.tool_requested', $companyId, $callId, $toolName);
    }

    public function toolAllowed(string $companyId, string $callId, string $toolName): void
    {
        $this->recordToolCall('voice.tool_allowed', $companyId, $callId, $toolName);
    }

    public function toolDenied(string $companyId, string $callId, string $toolName, string $reason): void
    {
        $this->recordToolCall('voice.tool_denied', $companyId, $callId, $toolName, ['reason' => $reason]);
    }

    public function transferRequested(string $companyId, string $callId, string $reason): void
    {
        $this->record('voice.transfer_requested', $companyId, null, $callId, ['reason' => $reason]);
    }

    public function transferOutcome(string $companyId, string $callId, string $result): void
    {
        $this->record('voice.transfer_outcome', $companyId, null, $callId, ['result' => $result]);
    }

    public function callCompleted(string $companyId, Call $call): void
    {
        $this->record('voice.call_completed', $companyId, null, $call->id, [
            'canonical_state' => $call->canonical_state->value,
            'duration_seconds' => $call->duration_seconds,
        ]);
    }

    public function transcriptAccessed(string $companyId, int $userId, string $callId): void
    {
        $this->record('voice.transcript_accessed', $companyId, $userId, $callId);
    }

    public function recordingAccessed(string $companyId, int $userId, string $callId): void
    {
        $this->record('voice.recording_accessed', $companyId, $userId, $callId);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(string $action, string $companyId, ?int $userId, string $callId, array $metadata = []): void
    {
        $this->audit->record(
            action: $action,
            entityType: self::ENTITY_CALL,
            entityId: $callId,
            companyId: $companyId,
            userId: $userId,
            metadata: $metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function recordToolCall(string $action, string $companyId, string $callId, string $toolName, array $metadata = []): void
    {
        $this->audit->record(
            action: $action,
            entityType: self::ENTITY_TOOL_CALL,
            entityId: $callId,
            companyId: $companyId,
            metadata: [...$metadata, 'tool_name' => $toolName],
        );
    }
}
