<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Application\Services;

use Modules\CustomerEngagement\Domain\Models\Conversation;
use Modules\CustomerEngagement\Domain\Models\Message;
use Modules\CustomerEngagement\Domain\ValueObjects\EngagementTimelineItem;
use Modules\CustomerEngagement\Voice\Domain\Models\Call;

/**
 * TASK-ECOS-V1.1-CRM-03-OMNICHANNEL-VOICE-BACKEND-IMPLEMENTATION-015 §3D — closes the "no
 * cross-channel merged timeline per customer" gap at the read-model level only (no inbox UI
 * change in this task). Reads across every Conversation a customer has, regardless of provider,
 * projecting Messages and Calls as their own distinct item types — never coercing a Call into a
 * fake Message.
 */
final class EngagementTimelineService
{
    /**
     * @return list<EngagementTimelineItem>
     */
    public function forCustomer(string $companyId, string $customerId, int $limit = 50): array
    {
        $conversationIds = Conversation::query()
            ->where('company_id', $companyId)
            ->where('customer_id', $customerId)
            ->pluck('id');

        if ($conversationIds->isEmpty()) {
            return [];
        }

        $providerByConversationId = Conversation::query()
            ->whereIn('id', $conversationIds)
            ->pluck('provider', 'id');

        $messageItems = Message::query()
            ->whereIn('conversation_id', $conversationIds)
            ->where('is_deleted', false)
            ->latest('sent_at')
            ->limit($limit)
            ->get()
            ->map(fn (Message $m): EngagementTimelineItem => new EngagementTimelineItem(
                type: 'message',
                conversationId: $m->conversation_id,
                provider: (string) ($providerByConversationId[$m->conversation_id] ?? ''),
                occurredAt: $m->sent_at,
                data: [
                    'id' => $m->id,
                    'direction' => $m->direction->value,
                    'sender_type' => $m->sender_type,
                    'sender_name' => $m->sender_name,
                    'message_type' => $m->message_type->value,
                    'content' => $m->content,
                ],
            ));

        $callItems = Call::query()
            ->whereIn('conversation_id', $conversationIds)
            ->latest('started_at')
            ->limit($limit)
            ->get()
            ->map(fn (Call $c): EngagementTimelineItem => new EngagementTimelineItem(
                type: 'call',
                conversationId: $c->conversation_id,
                provider: $c->provider->value,
                occurredAt: $c->started_at ?? $c->created_at,
                data: [
                    'id' => $c->id,
                    'direction' => $c->direction->value,
                    'canonical_state' => $c->canonical_state->value,
                    'duration_seconds' => $c->duration_seconds,
                    'handled_by' => $c->handled_by?->value,
                    'outcome' => $c->outcome,
                ],
            ));

        return $messageItems->concat($callItems)
            ->sortByDesc(fn (EngagementTimelineItem $item) => $item->occurredAt->getTimestamp())
            ->take($limit)
            ->values()
            ->all();
    }
}
