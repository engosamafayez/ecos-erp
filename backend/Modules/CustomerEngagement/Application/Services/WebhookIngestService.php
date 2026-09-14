<?php

declare(strict_types=1);

namespace Modules\CustomerEngagement\Application\Services;

use Illuminate\Support\Str;
use Modules\CustomerEngagement\Domain\Enums\ConversationStatus;
use Modules\CustomerEngagement\Domain\Enums\MessageDeliveryStatus;
use Modules\CustomerEngagement\Domain\Models\ChannelProvider;
use Modules\CustomerEngagement\Domain\Models\Conversation;
use Modules\CustomerEngagement\Domain\Models\Message;

class WebhookIngestService
{
    public function __construct(
        private readonly ConversationService $conversationService,
        private readonly AttributionCaptureService $attributionService,
        private readonly RoutingService $routingService,
    ) {}

    /**
     * Process a batch of normalized events from a provider's webhook.
     *
     * TASK-...-CRM-03-...-015 §3A — takes the full resolved ChannelProvider config (not just
     * channel/company strings) so Brand context threads onto every Conversation this creates.
     * Brand comes ONLY from this canonical, already-authenticated config row — never inferred
     * from message content, the customer record, or client input (architecture report, "Brand
     * ownership" gap #1).
     */
    public function processBatch(ChannelProvider $config, array $normalizedEvents): void
    {
        foreach ($normalizedEvents as $event) {
            if (! empty($event['__status_update__'])) {
                $this->handleStatusUpdate($event);

                continue;
            }
            $this->processInboundMessage($config, $event);
        }
    }

    private function processInboundMessage(ChannelProvider $config, array $event): void
    {
        $externalConvId = $event['conversation_id'];

        // Find or create conversation
        $conversation = Conversation::query()
            ->where('provider', $config->channel)
            ->where('external_conversation_id', $externalConvId)
            ->where('company_id', $config->company_id)
            ->first();

        if (! $conversation) {
            $conversation = $this->conversationService->create([
                'company_id' => $config->company_id,
                'brand_id' => $config->brand_id,
                'channel_id' => $config->id,
                'provider' => $config->channel,
                'external_conversation_id' => $externalConvId,
                'conversation_uuid' => Str::uuid()->toString(),
                'customer_name' => $event['sender_name'],
                'customer_phone' => $event['sender_phone'],
                'status' => ConversationStatus::Open->value,
                'started_at' => now(),
            ]);

            // Auto-route new conversation
            $this->routingService->autoRoute($conversation);

            // Capture attribution if present
            if (! empty($event['attribution'])) {
                $this->attributionService->capture($conversation, $event['attribution']);
            }
        }

        // Persist message (immutable)
        $sentAt = isset($event['timestamp'])
            ? \Carbon\Carbon::createFromTimestamp($event['timestamp'])
            : now();

        Message::create([
            'conversation_id' => $conversation->id,
            'external_message_id' => $event['message_id'],
            'direction' => 'inbound',
            'message_type' => $event['message_type'] ?? 'text',
            'content' => $event['content'],
            'media_url' => $event['media_url'],
            'media_type' => $event['media_type'],
            'sender_type' => 'customer',
            'sender_id' => $event['sender_id'],
            'sender_name' => $event['sender_name'],
            'reply_to_message_id' => $event['reply_to_message_id'] ?? null,
            'reaction_emoji' => $event['reaction_emoji'] ?? null,
            'delivery_status' => MessageDeliveryStatus::DELIVERED->value,
            'sent_at' => $sentAt,
            'delivered_at' => $sentAt,
        ]);

        // Update conversation counters
        $conversation->increment('messages_count');
        $conversation->increment('unread_count');
        $conversation->update(['last_message_at' => $sentAt]);
    }

    private function handleStatusUpdate(array $event): void
    {
        $status = match ($event['delivery_status']) {
            'delivered' => MessageDeliveryStatus::DELIVERED->value,
            'read' => MessageDeliveryStatus::READ->value,
            'failed' => MessageDeliveryStatus::FAILED->value,
            default => null,
        };

        if (! $status) {
            return;
        }

        Message::where('external_message_id', $event['message_id'])
            ->update(['delivery_status' => $status]);
    }
}
