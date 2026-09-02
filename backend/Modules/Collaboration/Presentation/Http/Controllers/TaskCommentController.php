<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Collaboration\Application\Actions\AddTaskCommentAction;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Presentation\Http\Requests\AddTaskCommentRequest;
use Modules\Collaboration\Presentation\Http\Resources\TaskCommentResource;

final class TaskCommentController extends Controller
{
    use HasApiResponse;

    public function index(Request $request, InternalTask $task): JsonResponse
    {
        $this->authorize('view', $task);

        $comments = $task->comments()->orderBy('created_at')->get();

        return $this->success(TaskCommentResource::collection($comments));
    }

    public function store(AddTaskCommentRequest $request, InternalTask $task, AddTaskCommentAction $action): JsonResponse
    {
        $comment = $action->execute($request->user(), $task, (string) $request->validated('body'));

        return $this->created(new TaskCommentResource($comment));
    }
}
