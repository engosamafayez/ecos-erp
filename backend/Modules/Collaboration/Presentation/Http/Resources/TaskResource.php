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
            'creator_name' => $this->whenLoaded('creator', fn () => $this->creator?->name),
            'assignee_user_id' => $this->assignee_user_id,
            'assignee_name' => $this->whenLoaded('assignee', fn () => $this->assignee?->name),
            // Additional assignees (§7) — supplementary to, never a
            // replacement for, the primary assignee fields above.
            'additional_assignees' => $this->whenLoaded('additionalAssignees', fn () => $this->additionalAssignees
                ->map(fn ($user) => ['id' => $user->id, 'name' => $user->name, 'job_title' => $user->job_title])
                ->all()),
            'team_id' => $this->team_id,
            'priority' => $this->priority->value,
            'status' => $this->status->value,
            'due_at' => $this->due_at?->toIso8601String(),
            'is_overdue' => $this->isOverdue(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'source_conversation_id' => $this->source_conversation_id,
            'source_message_id' => $this->source_message_id,
            'source_message_snapshot' => $this->sourceSnapshotFor($request),
            'activity' => $this->whenLoaded('activity', fn () => TaskActivityResource::collection($this->activity)),
            // Board placement (organizational only, TASK-ECOS-INTERNAL-
            // COLLABORATION-TASKS-TRELLO-FINAL-CLOSURE-002 §1) — never a
            // second status authority; `status` above remains canonical.
            'task_list_id' => $this->task_list_id,
            'board_position' => $this->board_position,
            'list_name' => $this->whenLoaded('list', fn () => $this->list?->name),
            'labels' => $this->whenLoaded('labels', fn () => TaskLabelResource::collection($this->labels)),
            'checklists' => $this->whenLoaded('checklists', fn () => TaskChecklistResource::collection($this->checklists)),
            'checklist_progress' => $this->checklistProgress(),
            'followers' => $this->whenLoaded('followers', fn () => TaskFollowerResource::collection($this->followers)),
            'followers_count' => $this->whenCounted('followers'),
            'comments_count' => $this->whenCounted('comments'),
            'attachments_count' => $this->whenCounted('attachments'),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Prefers the cheap `withCount` aggregates the list endpoint already
     * loads; falls back to counting loaded `checklists.items` on the detail
     * endpoint, which eager-loads the full checklist tree instead. Never
     * both loaded and counted redundantly.
     *
     * @return array{completed: int, total: int}|null
     */
    private function checklistProgress(): ?array
    {
        if ($this->checklist_items_total !== null) {
            return ['completed' => (int) $this->checklist_items_completed, 'total' => (int) $this->checklist_items_total];
        }

        if ($this->relationLoaded('checklists')) {
            $items = $this->checklists->flatMap(fn ($checklist) => $checklist->items);

            return ['completed' => $items->where('is_completed', true)->count(), 'total' => $items->count()];
        }

        return null;
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
