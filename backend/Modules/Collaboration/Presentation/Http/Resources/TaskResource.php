<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Collaboration\Domain\Models\ConversationParticipant;

/**
 * @mixin \Modules\Collaboration\Domain\Models\InternalTask
 */
final class TaskResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'company_id' => $this->company_id,
            'title' => $this->title,
            'description' => $this->description,
            'creator_user_id' => $this->creator_user_id,
            'assignee_user_id' => $this->assignee_user_id,
            'team_id' => $this->team_id,
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'due_at' => $this->due_at?->toIso8601String(),
            'is_overdue' => $this->isOverdue(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'source_conversation_id' => $this->source_conversation_id,
            'source_message_id' => $this->source_message_id,
            'source_message_snapshot' => $this->sourceSnapshotFor($request),
            'activity' => $this->whenLoaded('activity', fn () => TaskActivityResource::collection($this->activity)),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Task access alone must never leak source-message content to a viewer
     * who cannot independently access the source conversation (brief §14 —
     * "authorize BOTH task access AND conversation/message access... fail
     * closed... preserve the task without leaking message content"). Having
     * task access (creator/assignee, already checked by TaskPolicy before
     * this resource is ever built) is necessary but not sufficient to see
     * the snapshot text — a second, independent check runs here against the
     * *current viewer*, not the task's creator.
     */
    private function sourceSnapshotFor(Request $request): ?string
    {
        if ($this->source_conversation_id === null) {
            return null;
        }

        $viewer = $request->user();

        $canAccessSource = $viewer !== null && ConversationParticipant::query()
            ->where('conversation_id', $this->source_conversation_id)
            ->where('user_id', $viewer->id)
            ->whereNull('left_at')
            ->exists();

        return $canAccessSource ? $this->source_message_snapshot : null;
    }
}
