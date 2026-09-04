<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Collaboration\Application\Actions\ReassignTaskAction;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Presentation\Http\Requests\ReassignTaskRequest;
use Modules\Collaboration\Presentation\Http\Resources\TaskResource;

/**
 * Authorization is enforced inside ReassignTaskAction itself (creator-only)
 * — no separate policy call here would add anything the action doesn't
 * already check, same reasoning as ConversationParticipantController (Task 2).
 */
final class TaskAssignmentController extends Controller
{
    use HasApiResponse;

    public function update(ReassignTaskRequest $request, InternalTask $task, ReassignTaskAction $action): JsonResponse
    {
        $task = $action->execute($request->user(), $task, (int) $request->validated('assignee_user_id'));

        return $this->updated(new TaskResource($task->load(['creator', 'assignee'])), 'Task reassigned.');
    }
}
