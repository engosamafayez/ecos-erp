<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Collaboration\Application\Actions\SearchAddressableUsersAction;
use Modules\Collaboration\Application\Actions\SearchMessagesAction;
use Modules\Collaboration\Application\Actions\SearchTasksAction;
use Modules\Collaboration\Presentation\Http\Resources\AddressableUserResource;
use Modules\Collaboration\Presentation\Http\Resources\MessageResource;
use Modules\Collaboration\Presentation\Http\Resources\TaskResource;

final class CollaborationSearchController extends Controller
{
    use HasApiResponse;

    /**
     * "Somebody to newly address" — new direct conversation, new group member, task
     * assignee/reassignment. Not a general company directory: see
     * SearchAddressableUsersAction's docblock for the tenant + driver-scope rules
     * applied before a candidate is ever returned.
     */
    public function users(Request $request, SearchAddressableUsersAction $action): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        $results = $action->execute(
            $request->user(),
            (string) $request->query('q'),
            (int) $request->query('limit', 20),
        );

        return $this->success(AddressableUserResource::collection($results));
    }

    public function messages(Request $request, SearchMessagesAction $action): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $results = $action->execute(
            $request->user(),
            (string) $request->query('q'),
            (int) $request->query('limit', 20),
        );

        return $this->success(MessageResource::collection($results));
    }

    /** Its own index, deliberately not mixed into message search (brief §23). */
    public function tasks(Request $request, SearchTasksAction $action): JsonResponse
    {
        $request->validate([
            'q' => ['required', 'string', 'min:1', 'max:200'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $results = $action->execute(
            $request->user(),
            (string) $request->query('q'),
            (int) $request->query('limit', 20),
        );

        return $this->success(TaskResource::collection($results));
    }
}
