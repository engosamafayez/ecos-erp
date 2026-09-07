<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Collaboration\Application\Actions\ArchiveTaskBoardListAction;
use Modules\Collaboration\Application\Actions\CreateTaskBoardListAction;
use Modules\Collaboration\Application\Actions\EnsureDefaultTaskBoardListsAction;
use Modules\Collaboration\Application\Actions\RenameTaskBoardListAction;
use Modules\Collaboration\Application\Actions\ReorderTaskBoardListsAction;
use Modules\Collaboration\Application\Actions\RestoreTaskBoardListAction;
use Modules\Collaboration\Domain\Models\TaskBoardList;
use Modules\Collaboration\Presentation\Http\Resources\TaskBoardListResource;

/**
 * Board LIST management — company-scoped organizational containers, gated
 * by the same `collaboration.tasks.create` permission task creation already
 * uses (brief §23 — reuse existing authorization, no new permission token
 * for a capability this closely related to task creation).
 */
final class TaskBoardListController extends Controller
{
    use HasApiResponse;

    /** Lazily seeds the four defaults on first load (brief §2). */
    public function index(Request $request, EnsureDefaultTaskBoardListsAction $action): JsonResponse
    {
        $lists = $action->execute($request->user());

        return $this->success(TaskBoardListResource::collection($lists));
    }

    public function store(Request $request, CreateTaskBoardListAction $action): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $list = $action->execute($request->user(), $data['name']);

        return $this->created(new TaskBoardListResource($list));
    }

    public function update(Request $request, TaskBoardList $list, RenameTaskBoardListAction $action): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);

        $list = $action->execute($request->user(), $list, $data['name']);

        return $this->updated(new TaskBoardListResource($list));
    }

    public function reorder(Request $request, ReorderTaskBoardListsAction $action): JsonResponse
    {
        $data = $request->validate([
            'list_ids' => ['required', 'array', 'min:1'],
            'list_ids.*' => ['uuid'],
        ]);

        $action->execute($request->user(), $data['list_ids']);

        return $this->success(null, 'Board lists reordered.');
    }

    public function archive(Request $request, TaskBoardList $list, ArchiveTaskBoardListAction $action): JsonResponse
    {
        $list = $action->execute($request->user(), $list);

        return $this->updated(new TaskBoardListResource($list));
    }

    public function restore(Request $request, TaskBoardList $list, RestoreTaskBoardListAction $action): JsonResponse
    {
        $list = $action->execute($request->user(), $list);

        return $this->updated(new TaskBoardListResource($list));
    }
}
