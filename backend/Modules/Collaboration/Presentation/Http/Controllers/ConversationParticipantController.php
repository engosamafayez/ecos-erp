<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Collaboration\Application\Actions\AddGroupParticipantAction;
use Modules\Collaboration\Application\Actions\RemoveGroupParticipantAction;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Collaboration\Presentation\Http\Requests\AddParticipantRequest;
use Modules\Collaboration\Presentation\Http\Resources\ConversationParticipantResource;

/**
 * Authorization is enforced inside the actions themselves (group-owner-only
 * for add/remove-another, self-service for leaving) — see
 * AddGroupParticipantAction / RemoveGroupParticipantAction. No separate
 * Gate::authorize() call here would add anything the action doesn't already
 * check.
 */
final class ConversationParticipantController extends Controller
{
    use HasApiResponse;

    public function store(AddParticipantRequest $request, Conversation $conversation, AddGroupParticipantAction $action): JsonResponse
    {
        $participant = $action->execute($request->user(), $conversation, (int) $request->validated('user_id'));

        return $this->created(new ConversationParticipantResource($participant));
    }

    public function destroy(Request $request, Conversation $conversation, int $user, RemoveGroupParticipantAction $action): JsonResponse
    {
        $action->execute($request->user(), $conversation, $user);

        return $this->deleted('Participant removed.');
    }
}
