<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \Modules\Collaboration\Domain\Models\InternalTaskActivity
 */
final class TaskActivityResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'actor_user_id' => $this->actor_user_id,
            'actor_name' => $this->whenLoaded('actor', fn () => $this->actor?->name),
            'event_type' => $this->event_type,
            'from_value' => $this->from_value,
            'to_value' => $this->to_value,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
