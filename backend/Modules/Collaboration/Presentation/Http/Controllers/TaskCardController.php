<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Collaboration\Application\Actions\MoveTaskCardAction;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Domain\Models\TaskBoardList;
use Modules\Collaboration\Presentation\Http\Resources\TaskResource;

/** Card move/reorder — authorization enforced inside MoveTaskCardAction itself (creator/assignee, same as status transitions). */
final class TaskCardController extends Controller
{
    use HasApiResponse;

    public function move(Request $request, InternalTask $task, MoveTaskCardAction $action): JsonResponse
    {
        $data = $request->validate([
            'task_list_id' => ['required', 'uuid'],
            'position' => ['required', 'integer', 'min:0'],
        ]);

        $list = TaskBoardList::query()->findOrFail($data['task_list_id']);

        $task = $action->execute($request->user(), $task, $list, (int) $data['position']);

        return $this->updated(new TaskResource($task->load(['creator', 'assignee', 'list', 'labels'])));
    }
}
