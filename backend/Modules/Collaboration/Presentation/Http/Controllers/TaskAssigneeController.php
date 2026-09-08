<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Collaboration\Application\Actions\AddTaskAssigneeAction;
use Modules\Collaboration\Application\Actions\RemoveTaskAssigneeAction;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Presentation\Http\Resources\TaskResource;

/**
 * Additional assignees, ALONGSIDE (never replacing) the primary assignee —
 * see AddTaskAssigneeAction. Authorization enforced inside the actions
 * themselves (TaskPolicy::manageAssignees), same convention as
 * TaskFollowerController.
 */
final class TaskAssigneeController extends Controller
{
    use HasApiResponse;

    public function store(Request $request, InternalTask $task, AddTaskAssigneeAction $action): JsonResponse
    {
        $data = $request->validate(['user_id' => ['required', 'integer', 'exists:users,id']]);
        $target = User::query()->findOrFail($data['user_id']);

        $task = $action->execute($request->user(), $task, $target);

        return $this->updated(new TaskResource($task->load('additionalAssignees')));
    }

    public function destroy(Request $request, InternalTask $task, int $userId, RemoveTaskAssigneeAction $action): JsonResponse
    {
        $target = User::query()->findOrFail($userId);

        $action->execute($request->user(), $task, $target);

        return $this->deleted('Assignee removed.');
    }
}
