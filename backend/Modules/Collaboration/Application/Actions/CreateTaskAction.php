<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Modules\Collaboration\Application\Actions\Concerns\LogsTaskActivity;
use Modules\Collaboration\Application\DTO\CreateTaskData;
use Modules\Collaboration\Application\Events\TaskBroadcast;
use Modules\Collaboration\Application\Notifications\TaskAssignedNotification;
use Modules\Collaboration\Domain\Enums\MessageType;
use Modules\Collaboration\Domain\Enums\TaskStatus;
use Modules\Collaboration\Domain\Models\ConversationParticipant;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Domain\Models\Message;
use Modules\Collaboration\Domain\Models\TaskBoardList;
use Modules\Collaboration\Domain\Services\DriverMessagingAuthorizer;
use Modules\IAM\Domain\Contracts\AuthorizationGatewayInterface;
use Modules\Organization\Teams\Domain\Models\Team;

/**
 * Handles both plain task creation and Message -> Create Task (an optional
 * `sourceMessageId` on the same DTO — brief §12/§13, one action, not two).
 * The source message stays immutable; only a read-only snapshot + pointer
 * are copied onto the task (architecture report §12).
 */
final class CreateTaskAction extends BaseAction
{
    use LogsTaskActivity;

    public function __construct(
        private readonly AuthorizationGatewayInterface $authorizationGateway,
        private readonly DriverMessagingAuthorizer $driverMessagingAuthorizer,
        private readonly EnsureDefaultTaskBoardListsAction $ensureDefaultLists,
    ) {}

    /** @param  mixed  ...$arguments  [User $actor, CreateTaskData $data] */
    public function execute(mixed ...$arguments): InternalTask
    {
        $actor = $arguments[0] ?? null;
        $data = $arguments[1] ?? null;

        if (! $actor instanceof User || ! $data instanceof CreateTaskData) {
            throw new InvalidArgumentException('CreateTaskAction::execute expects (User $actor, CreateTaskData $data).');
        }

        // inspect() (not authorize()): authorize()/can() do not carry the
        // platform's is_system bypass, only inspect()/decision() do (ADR-038
        // Part 1) — without this, an is_system actor is wrongly denied here.
        if ($this->authorizationGateway->inspect($actor, 'collaboration.tasks.create')->isDenied()) {
            throw new AuthorizationException('This action is unauthorized (collaboration.tasks.create).');
        }

        // Defaults to self-assign (architecture report §12) when no assignee given.
        $assignee = User::query()
            ->where('id', $data->assigneeUserId ?? $actor->id)
            ->where('company_id', $actor->company_id)
            ->firstOrFail();

        if ($assignee->id !== $actor->id) {
            $this->driverMessagingAuthorizer->assertCanAssign($actor, $assignee);
        }

        $teamId = null;
        if ($data->teamId !== null) {
            $teamId = Team::query()->where('company_id', $actor->company_id)->findOrFail($data->teamId)->id;
        }

        $sourceConversationId = null;
        $sourceMessageId = null;
        $sourceSnapshot = null;

        if ($data->sourceMessageId !== null) {
            $sourceMessage = Message::query()->findOrFail($data->sourceMessageId);

            // Source-message security (brief §14): creating the task requires
            // access to the source conversation, checked independently of
            // whatever access the eventual task viewer will or won't have.
            $isSourceParticipant = ConversationParticipant::query()
                ->where('conversation_id', $sourceMessage->conversation_id)
                ->where('user_id', $actor->id)
                ->whereNull('left_at')
                ->exists();

            if (! $isSourceParticipant) {
                throw new AuthorizationException('You are not a participant of the source conversation.');
            }

            $sourceConversationId = $sourceMessage->conversation_id;
            $sourceMessageId = $sourceMessage->id;
            $sourceSnapshot = $this->snapshotOf($sourceMessage);
        }

        // TASK-ECOS-INTERNAL-COLLABORATION-TASKS-TRELLO-FINAL-CLOSURE-002 §3:
        // every task must have a board placement so it renders on the board
        // immediately. New cards always land in the first (lowest-position,
        // non-archived) list — simple and stable regardless of whether that
        // list is still named "To Do" or has been renamed/reordered by the
        // company (brief §2 lists are freely renamable, so placement can no
        // longer assume a fixed name<->status mapping the way the one-time
        // backfill migration did).
        $defaultList = $this->ensureDefaultLists->execute($actor)->first();

        $task = DB::transaction(function () use ($actor, $data, $assignee, $teamId, $sourceConversationId, $sourceMessageId, $sourceSnapshot, $defaultList): InternalTask {
            /** @var TaskBoardList|null $list */
            $list = TaskBoardList::query()
                ->where('id', $defaultList->id)
                ->lockForUpdate()
                ->first();

            $boardPosition = 1 + (int) (InternalTask::query()
                ->where('task_list_id', $list?->id)
                ->max('board_position') ?? -1);

            $task = InternalTask::query()->create([
                'company_id' => $actor->company_id,
                'title' => $data->title,
                'description' => $data->description,
                'creator_user_id' => $actor->id,
                'assignee_user_id' => $assignee->id,
                'team_id' => $teamId,
                'priority' => $data->priority,
                // Matches the column's DB default ('todo') explicitly: create()
                // only populates the in-memory model from what is passed here —
                // relying on the DB-level default left $task->status null on the
                // freshly-created instance (no DB round-trip refresh happens
                // before the controller serializes it), crashing TaskResource's
                // `$this->status->value` on every task creation.
                'status' => TaskStatus::Todo,
                'due_at' => $data->dueAt,
                'source_conversation_id' => $sourceConversationId,
                'source_message_id' => $sourceMessageId,
                'source_message_snapshot' => $sourceSnapshot,
                'task_list_id' => $list?->id,
                'board_position' => $boardPosition,
            ]);

            $this->logActivity($task, $actor->id, 'created');

            return $task;
        });

        if ($task->assignee_user_id !== $actor->id) {
            Notification::send($assignee, new TaskAssignedNotification($task));
        }

        TaskBroadcast::dispatch($task, 'created');

        return $task;
    }

    /**
     * Never a raw storage path (same rule as MessageBroadcast/
     * NewMessageNotification) — a fixed, safe label per type for anything
     * that isn't plain text.
     */
    private function snapshotOf(Message $message): string
    {
        return match ($message->type) {
            MessageType::Text, MessageType::System => (string) $message->body,
            MessageType::Image => '[Image]',
            MessageType::File => '[File]',
            MessageType::Voice => '[Voice message]',
        };
    }
}
