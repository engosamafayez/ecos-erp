<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Modules\Collaboration\Application\Actions\GetConversationMediaAction;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Collaboration\Presentation\Http\Resources\ConversationMediaItemResource;

/**
 * Conversation participation is the whole gate here (ConversationPolicy::view,
 * exactly like MessageController::index/show) — no separate media
 * permission, matching every other read surface in this module.
 */
final class ConversationMediaController extends Controller
{
    use HasApiResponse;

    public function index(Request $request, Conversation $conversation, GetConversationMediaAction $action): JsonResponse
    {
        $this->authorize('view', $conversation);

        $request->validate([
            'type' => ['required', 'string', Rule::in(['image', 'file', 'link'])],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $results = $action->execute(
            $request->user(),
            $conversation,
            (string) $request->query('type'),
            (int) $request->query('limit', 50),
        );

        return $this->success(ConversationMediaItemResource::collection($results));
    }
}
