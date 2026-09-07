<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Light projection for the Media/Documents/Links aggregation tabs — never
 * `file_path`/a storage URL (same rule as MessageResource::attachmentPayload()).
 * A link item has no attachment; its `url` is the first URL found in the
 * message's own text body, already visible to every participant in the
 * conversation thread itself.
 *
 * @mixin \Modules\Collaboration\Domain\Models\Message
 */
final class ConversationMediaItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isMediaType = in_array($this->type->value, ['image', 'file'], true);
        $document = $isMediaType ? $this->attachment() : null;

        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'sender_name' => $this->whenLoaded('sender', fn () => $this->sender?->name),
            'created_at' => $this->created_at?->toIso8601String(),
            'attachment' => $document === null ? null : [
                'name' => $document->name,
                'mime_type' => $document->mime_type,
                'file_size' => $document->file_size !== null ? (int) $document->file_size : null,
            ],
            'url' => $this->type->value === 'text' ? $this->firstUrlInBody() : null,
            'body' => $this->type->value === 'text' ? $this->body : null,
        ];
    }

    private function firstUrlInBody(): ?string
    {
        if ($this->body === null) {
            return null;
        }

        return preg_match('/(https?:\/\/|www\.)[^\s]+/i', $this->body, $matches) === 1 ? $matches[0] : null;
    }
}
