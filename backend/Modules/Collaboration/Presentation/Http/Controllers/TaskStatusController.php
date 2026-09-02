<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Collaboration\Application\Actions\TransitionTaskStatusAction;
use Modules\Collaboration\Domain\Enums\TaskStatus;
use Modules\Collaboration\Domain\Exceptions\CollaborationException;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Presentation\Http\Requests\TransitionTaskStatusRequest;
use Modules\Collaboration\Presentation\Http\Resources\TaskResource;

/**
 * This is also the driver self-progression endpoint (brief §10) — a driver
 * assignee hits the exact same route/action as any employee assignee;
 * TransitionTaskStatusAction does not branch on participant type at all.
 */
final class TaskStatusController extends Controller
{
    use HasApiResponse;

    public function update(TransitionTaskStatusRequest $request, InternalTask $task, TransitionTaskStatusAction $action): JsonResponse
    {
        try {
            $task = $action->execute($request->user(), $task, TaskStatus::from($request->validated('status')));
        } catch (CollaborationException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->updated(new TaskResource($task->load(['creator', 'assignee'])), 'Task status updated.');
    }
}
