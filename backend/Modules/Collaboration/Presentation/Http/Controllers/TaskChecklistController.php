<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Collaboration\Application\Actions\CreateTaskChecklistAction;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Presentation\Http\Resources\TaskChecklistResource;

final class TaskChecklistController extends Controller
{
    use HasApiResponse;

    public function index(Request $request, InternalTask $task): JsonResponse
    {
        $this->authorize('view', $task);

        return $this->success(TaskChecklistResource::collection($task->checklists()->with('items')->get()));
    }

    public function store(Request $request, InternalTask $task, CreateTaskChecklistAction $action): JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:100']]);

        $checklist = $action->execute($request->user(), $task, $data['title']);

        return $this->created(new TaskChecklistResource($checklist->load('items')));
    }
}
