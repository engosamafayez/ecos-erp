<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Collaboration\Application\Actions\GetConversationMessagesAction;
use Modules\Collaboration\Application\Actions\SendMessageAction;
use Modules\Collaboration\Application\DTO\SendMessageData;
use Modules\Collaboration\Domain\Enums\MessageType;
use Modules\Collaboration\Domain\Exceptions\CollaborationException;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Collaboration\Presentation\Http\Requests\SendMessageRequest;
use Modules\Collaboration\Presentation\Http\Resources\MessageResource;

final class MessageController extends Controller
{
    use HasApiResponse;

    public function index(Request $request, Conversation $conversation, GetConversationMessagesAction $action): JsonResponse
    {
        $messages = $action->execute(
            $request->user(),
            $conversation,
            $request->query('before_message_id'),
            (int) $request->query('limit', 50),
        )->load('mentions');

        return $this->success(MessageResource::collection($messages));
    }

    public function store(SendMessageRequest $request, Conversation $conversation, SendMessageAction $action): JsonResponse
    {
        $data = new SendMessageData(
            conversationId: $conversation->id,
            senderUserId: $request->user()->id,
            type: MessageType::Text,
            body: (string) $request->validated('body'),
            replyToMessageId: $request->validated('reply_to_message_id'),
            mentionedUserIds: array_map('intval', $request->validated('mentioned_user_ids', [])),
        );

        try {
            $message = $action->execute($data);
        } catch (CollaborationException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->created(new MessageResource($message->load('mentions')));
    }
}
