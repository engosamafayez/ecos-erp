<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Domain\ValueObjects;

use DateTimeInterface;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §3D — the minimal backend
 * read-model foundation for a cross-channel customer timeline (architecture report gap #4:
 * "the unified inbox does not merge channels per customer"). One item per Message OR per Call —
 * never a Call flattened into a fake Message record (§3D: "do not flatten fundamentally
 * different entities"). Task 2 owns rendering this; this task only owns producing it.
 */
final class EngagementTimelineItem
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        public readonly string $type,
        public readonly string $conversationId,
        public readonly string $provider,
        public readonly DateTimeInterface $occurredAt,
        public readonly array $data,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'conversation_id' => $this->conversationId,
            'provider' => $this->provider,
            'occurred_at' => $this->occurredAt->format(DATE_ATOM),
            'data' => $this->data,
        ];
    }
}
