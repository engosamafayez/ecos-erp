<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Collaboration\Application\Actions\CreateGroupConversationAction;
use Modules\Collaboration\Application\Actions\GetOrCreateDirectConversationAction;
use Modules\Collaboration\Application\Actions\ListConversationsForUserAction;
use Modules\Collaboration\Domain\Exceptions\CollaborationException;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Collaboration\Presentation\Http\Requests\StoreDirectConversationRequest;
use Modules\Collaboration\Presentation\Http\Requests\StoreGroupConversationRequest;
use Modules\Collaboration\Presentation\Http\Resources\ConversationResource;

final class ConversationController extends Controller
{
    use HasApiResponse;

    public function index(Request $request, ListConversationsForUserAction $action): JsonResponse
    {
        $conversations = $action->execute($request->user());

        return $this->success(ConversationResource::collection($conversations));
    }

    /** Direct-conversation get-or-create: see GetOrCreateDirectConversationAction. */
    public function storeDirect(StoreDirectConversationRequest $request, GetOrCreateDirectConversationAction $action): JsonResponse
    {
        try {
            $conversation = $action->execute($request->user(), (int) $request->validated('target_user_id'));
        } catch (CollaborationException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->created(new ConversationResource($conversation->load('activeParticipants.user')));
    }

    public function storeGroup(StoreGroupConversationRequest $request, CreateGroupConversationAction $action): JsonResponse
    {
        try {
            $conversation = $action->execute(
                $request->user(),
                (string) $request->validated('title'),
                array_map('intval', $request->validated('participant_user_ids')),
                $request->validated('team_id'),
            );
        } catch (CollaborationException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->created(new ConversationResource($conversation->load('activeParticipants.user')));
    }

    public function show(Request $request, Conversation $conversation): JsonResponse
    {
        $this->authorize('view', $conversation);

        return $this->success(new ConversationResource($conversation->load('activeParticipants.user')));
    }
}
