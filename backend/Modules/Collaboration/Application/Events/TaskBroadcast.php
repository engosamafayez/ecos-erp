<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Modules\Collaboration\Domain\Models\InternalTask;

/**
 * Task-level realtime notice (brief §25) — a task is not always tied to a
 * conversation, so this uses a per-user private channel
 * (`collaboration.user.{id}`) rather than a conversation channel, unlike
 * MessageBroadcast/ConversationReadStateBroadcast. Same posture as those:
 * core `laravel/framework` broadcasting contracts only, no Reverb-specific
 * import, functions today on the default 'log' driver, requires zero code
 * changes once Reverb is actually installed and activated on the canonical
 * device (deferred per Task 3's CTO ruling — this task does not touch that).
 */
final class TaskBroadcast implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly InternalTask $task,
        public readonly string $eventType,
    ) {}

    /**
     * Queried fresh rather than read off `$this->task`'s in-memory relations
     * (§7 — additional assignees): a queued broadcast job may restore the
     * model without whatever relations happened to be loaded when it was
     * dispatched, so this stays correct regardless of that.
     *
     * @return list<PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $userIds = DB::table('collaboration_task_additional_assignees')
            ->where('task_id', $this->task->id)
            ->pluck('user_id')
            ->push($this->task->assignee_user_id)
            ->push($this->task->creator_user_id)
            ->unique();

        return $userIds->map(fn ($id) => new PrivateChannel("collaboration.user.{$id}"))->values()->all();
    }

    public function broadcastAs(): string
    {
        return 'task.updated';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->task->id,
            'event_type' => $this->eventType,
            'title' => $this->task->title,
            'status' => $this->task->status->value,
            'priority' => $this->task->priority->value,
            'assignee_user_id' => $this->task->assignee_user_id,
            'creator_user_id' => $this->task->creator_user_id,
            'due_at' => $this->task->due_at?->toIso8601String(),
        ];
    }
}
