<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Modules\Collaboration\Application\Actions\MarkConversationReadAction;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Collaboration\Presentation\Http\Requests\MarkConversationReadRequest;
use Modules\Collaboration\Presentation\Http\Resources\ConversationParticipantResource;

final class ConversationReadStateController extends Controller
{
    use HasApiResponse;

    public function update(MarkConversationReadRequest $request, Conversation $conversation, MarkConversationReadAction $action): JsonResponse
    {
        $participant = $action->execute($request->user(), $conversation, $request->validated('last_read_message_id'));

        return $this->updated(new ConversationParticipantResource($participant), 'Conversation marked read.');
    }
}
