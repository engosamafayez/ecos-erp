<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Collaboration\Application\Actions\MuteConversationAction;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Collaboration\Presentation\Http\Requests\MuteConversationRequest;
use Modules\Collaboration\Presentation\Http\Resources\ConversationParticipantResource;

final class ConversationMuteController extends Controller
{
    use HasApiResponse;

    public function update(MuteConversationRequest $request, Conversation $conversation, MuteConversationAction $action): JsonResponse
    {
        $participant = $action->execute($request->user(), $conversation, (bool) $request->validated('muted'));

        return $this->updated(new ConversationParticipantResource($participant->load('user')), 'Conversation mute preference updated.');
    }
}
