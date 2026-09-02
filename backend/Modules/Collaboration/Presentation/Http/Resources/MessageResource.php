<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Collaboration\Domain\Enums\MessageType;
use Modules\Collaboration\Domain\Models\VoiceMetadata;

/**
 * @mixin \Modules\Collaboration\Domain\Models\Message
 */
final class MessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_user_id' => $this->sender_user_id,
            'sender_name' => $this->whenLoaded('sender', fn () => $this->sender?->name),
            'type' => $this->type->value,
            'body' => $this->body,
            'reply_to_message_id' => $this->reply_to_message_id,
            'mentioned_user_ids' => $this->whenLoaded('mentions', fn () => $this->mentions->pluck('mentioned_user_id')->values()),
            // Additive alongside `mentioned_user_ids` (unchanged, for backward compatibility)
            // — requires `mentions.mentionedUser` eager-loaded. Resolving a mentioned user's
            // name here leaks nothing new: they were already named in this message's body by
            // the sender, to every participant who can already read this message.
            'mentioned_users' => $this->whenLoaded('mentions', fn () => $this->mentions
                ->map(fn ($mention) => ['id' => $mention->mentioned_user_id, 'name' => $mention->mentionedUser?->name])
                ->values()),
            'attachment' => $this->attachmentPayload(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Filename/mime/size and (for voice) duration only — never `file_path`
     * or a storage URL (brief §14/§17). A client fetches the actual bytes
     * through the authorized MessageAttachmentController endpoint, keyed by
     * message id alone.
     *
     * @return array<string, mixed>|null
     */
    private function attachmentPayload(): ?array
    {
        if (! in_array($this->type, [MessageType::Image, MessageType::File, MessageType::Voice], true)) {
            return null;
        }

        $document = $this->attachment();

        if ($document === null) {
            return null;
        }

        $payload = [
            'name' => $document->name,
            'mime_type' => $document->mime_type,
            'file_size' => $document->file_size !== null ? (int) $document->file_size : null,
        ];

        if ($this->type === MessageType::Voice) {
            $voice = VoiceMetadata::query()->where('document_id', $document->id)->first();
            $payload['duration_seconds'] = $voice?->duration_seconds;
        }

        return $payload;
    }
}
