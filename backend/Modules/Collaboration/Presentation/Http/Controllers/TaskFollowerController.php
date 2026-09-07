<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Collaboration\Application\Actions\FollowTaskAction;
use Modules\Collaboration\Application\Actions\UnfollowTaskAction;
use Modules\Collaboration\Domain\Models\InternalTask;
use Modules\Collaboration\Presentation\Http\Resources\TaskResource;

/** Self-follow, or (creator only) add/remove someone else — TaskPolicy::manageFollower tells the two apart. */
final class TaskFollowerController extends Controller
{
    use HasApiResponse;

    public function store(Request $request, InternalTask $task, FollowTaskAction $action): JsonResponse
    {
        $data = $request->validate(['user_id' => ['sometimes', 'integer', Rule::exists('users', 'id')]]);
        $target = isset($data['user_id']) ? User::query()->findOrFail($data['user_id']) : $request->user();

        $task = $action->execute($request->user(), $task, $target);

        return $this->updated(new TaskResource($task->load('followers.user')));
    }

    public function destroy(Request $request, InternalTask $task, int $userId, UnfollowTaskAction $action): JsonResponse
    {
        $target = User::query()->findOrFail($userId);

        $action->execute($request->user(), $task, $target);

        return $this->deleted('Follower removed.');
    }
}
