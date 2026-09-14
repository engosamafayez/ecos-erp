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
}
