<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Collaboration\Application\Actions\AttachTaskLabelAction;
use Modules\Collaboration\Application\Actions\CreateTaskLabelAction;
use Modules\Collaboration\Application\Actions\DetachTaskLabelAction;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Domain\Models\TaskLabel;
use Modules\Collaboration\Presentation\Http\Resources\TaskLabelResource;
use Modules\Collaboration\Presentation\Http\Resources\TaskResource;

/** Colors form a small closed palette (brief §12) — the frontend badge set stays finite and theme-consistent. */
final class TaskLabelController extends Controller
{
    use HasApiResponse;

    private const COLORS = ['gray', 'red', 'orange', 'yellow', 'green', 'blue', 'purple'];

    public function index(Request $request): JsonResponse
    {
        $labels = TaskLabel::query()->where('company_id', $request->user()->company_id)->orderBy('name')->get();

        return $this->success(TaskLabelResource::collection($labels));
    }

    public function store(Request $request, CreateTaskLabelAction $action): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'color' => ['required', Rule::in(self::COLORS)],
        ]);

        $label = $action->execute($request->user(), $data['name'], $data['color']);

        return $this->created(new TaskLabelResource($label));
    }

    public function attach(Request $request, InternalTask $task, TaskLabel $label, AttachTaskLabelAction $action): JsonResponse
    {
        $task = $action->execute($request->user(), $task, $label);

        return $this->updated(new TaskResource($task->load('labels')));
    }

    public function detach(Request $request, InternalTask $task, TaskLabel $label, DetachTaskLabelAction $action): JsonResponse
    {
        $action->execute($request->user(), $task, $label);

        return $this->deleted('Label removed from task.');
    }
}
