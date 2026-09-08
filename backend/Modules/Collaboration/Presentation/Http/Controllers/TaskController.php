<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Collaboration\Application\Actions\CreateTaskAction;
use Modules\Collaboration\Application\Actions\ListMyTasksAction;
use Modules\Collaboration\Application\Actions\UpdateTaskAction;
use Modules\Collaboration\Application\DTO\CreateTaskData;
use Modules\Collaboration\Domain\Enums\TaskPriority;
use Modules\Collaboration\Domain\Enums\TaskStatus;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Presentation\Http\Requests\CreateTaskRequest;
use Modules\Collaboration\Presentation\Http\Requests\UpdateTaskRequest;
use Modules\Collaboration\Presentation\Http\Resources\TaskResource;

final class TaskController extends Controller
{
    use HasApiResponse;

    /** "My tasks" — created by me, assigned to me, or either (brief §22). */
    public function index(Request $request, ListMyTasksAction $action): JsonResponse
    {
        $filters = [
            'scope' => $request->query('scope', 'mine'),
            'overdue' => $request->boolean('overdue'),
            'team_id' => $request->query('team_id'),
        ];

        if ($request->filled('status')) {
            $filters['status'] = TaskStatus::from($request->query('status'));
        }

        if ($request->filled('priority')) {
            $filters['priority'] = TaskPriority::from($request->query('priority'));
        }

        $tasks = $action->execute($request->user(), $filters);

        return $this->success(TaskResource::collection($tasks));
    }

    /** Handles both plain creation and Message -> Create Task (optional `source_message_id`). */
    public function store(CreateTaskRequest $request, CreateTaskAction $action): JsonResponse
    {
        $data = new CreateTaskData(
            creatorUserId: $request->user()->id,
            title: (string) $request->validated('title'),
            description: $request->validated('description'),
            assigneeUserId: $request->validated('assignee_user_id'),
            priority: TaskPriority::from($request->validated('priority', 'normal')),
            dueAt: $request->validated('due_at'),
            teamId: $request->validated('team_id'),
            sourceMessageId: $request->validated('source_message_id'),
        );

        $task = $action->execute($request->user(), $data);

        return $this->created(new TaskResource($task->load(self::DETAIL_RELATIONS)));
    }

    public function show(Request $request, InternalTask $task): JsonResponse
    {
        $this->authorize('view', $task);

        return $this->success(new TaskResource($task->load(self::DETAIL_RELATIONS)));
    }

    public function update(UpdateTaskRequest $request, InternalTask $task, UpdateTaskAction $action): JsonResponse
    {
        $this->authorize('update', $task);

        $changes = $request->validated();

        if (isset($changes['priority'])) {
            $changes['priority'] = TaskPriority::from($changes['priority']);
        }

        $task = $action->execute($request->user(), $task, $changes);

        return $this->updated(new TaskResource($task->load(self::DETAIL_RELATIONS)));
    }

    /** @var list<string> */
    private const DETAIL_RELATIONS = [
        'activity.actor', 'creator', 'assignee', 'additionalAssignees', 'list', 'labels',
        'checklists.items', 'followers.user',
    ];
}
