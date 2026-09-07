<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Collaboration\Application\Actions\AddTaskChecklistItemAction;
use Modules\Collaboration\Application\Actions\DeleteTaskChecklistItemAction;
use Modules\Collaboration\Application\Actions\ReorderTaskChecklistItemsAction;
use Modules\Collaboration\Application\Actions\UpdateTaskChecklistItemAction;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Domain\Models\TaskChecklist;
use Modules\Collaboration\Domain\Models\TaskChecklistItem;
use Modules\Collaboration\Presentation\Http\Resources\TaskChecklistItemResource;

final class TaskChecklistItemController extends Controller
{
    use HasApiResponse;

    public function store(Request $request, InternalTask $task, TaskChecklist $checklist, AddTaskChecklistItemAction $action): JsonResponse
    {
        $data = $request->validate(['title' => ['required', 'string', 'max:255']]);

        $item = $action->execute($request->user(), $task, $checklist, $data['title']);

        return $this->created(new TaskChecklistItemResource($item));
    }

    public function update(Request $request, InternalTask $task, TaskChecklist $checklist, TaskChecklistItem $item, UpdateTaskChecklistItemAction $action): JsonResponse
    {
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'is_completed' => ['sometimes', 'boolean'],
        ]);

        $item = $action->execute($request->user(), $task, $item, $data);

        return $this->updated(new TaskChecklistItemResource($item));
    }

    public function destroy(Request $request, InternalTask $task, TaskChecklist $checklist, TaskChecklistItem $item, DeleteTaskChecklistItemAction $action): JsonResponse
    {
        $action->execute($request->user(), $task, $item);

        return $this->deleted('Checklist item removed.');
    }

    public function reorder(Request $request, InternalTask $task, TaskChecklist $checklist, ReorderTaskChecklistItemsAction $action): JsonResponse
    {
        $data = $request->validate([
            'item_ids' => ['required', 'array', 'min:1'],
            'item_ids.*' => ['uuid'],
        ]);

        $action->execute($request->user(), $task, $checklist, $data['item_ids']);

        return $this->success(null, 'Checklist items reordered.');
    }
}
