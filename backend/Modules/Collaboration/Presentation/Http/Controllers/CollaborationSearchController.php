<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Collaboration\Application\Actions\SearchMessagesAction;
use Modules\Collaboration\Presentation\Http\Resources\MessageResource;

final class CollaborationSearchController extends Controller
{
    use HasApiResponse;

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
}
