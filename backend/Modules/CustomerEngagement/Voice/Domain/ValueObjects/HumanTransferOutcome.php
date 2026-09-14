<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Voice\Domain\ValueObjects;

final class HumanTransferOutcome
{
    private function __construct(
        public readonly string $result,
        public readonly ?string $taskId = null,
        public readonly ?string $failureReason = null,
    ) {}

    public static function bridged(): self
    {
        return new self('bridged');
    }

    public static function fallbackCallback(string $taskId): self
    {
        return new self('fallback_callback', taskId: $taskId);
    }

    public static function failed(string $reason): self
    {
        return new self('failed', failureReason: $reason);
    }

    /**
     * TASK-ECOS-V1.1-CRM-03-VOICE-UX-AND-FINAL-SOURCE-CLOSURE-016 §3 — Gap A: no valid,
     * active, dialable destination could be resolved (never "bridged", never silently a bare
     * fallback_callback that hides WHY). A callback task is still scheduled — see
     * HumanTransferService — this only names the reason honestly.
     */
    public static function transferUnavailable(string $taskId, string $reason): self
    {
        return new self('transfer_unavailable', taskId: $taskId, failureReason: $reason);
    }
}
