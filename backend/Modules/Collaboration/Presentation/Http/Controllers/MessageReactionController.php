<?php

declare(strict_types=1);

namespace Modules\Collaboration\Presentation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Traits\HasApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Collaboration\Application\Actions\RemoveMessageReactionAction;
use Modules\Collaboration\Application\Actions\SetMessageReactionAction;
use Modules\Collaboration\Domain\Models\Message;
use Modules\Collaboration\Presentation\Http\Resources\MessageResource;

/** Authorization enforced inside the actions themselves (conversation participation), same convention as MessageController. */
final class MessageReactionController extends Controller
{
    use HasApiResponse;

    public function store(Request $request, Message $message, SetMessageReactionAction $action): JsonResponse
    {
        $data = $request->validate(['emoji' => ['required', 'string', 'max:32']]);

        $message = $action->execute($request->user(), $message, $data['emoji']);

        return $this->updated(new MessageResource($message->load('reactions')));
    }

    public function destroy(Request $request, Message $message, RemoveMessageReactionAction $action): JsonResponse
    {
        $message = $action->execute($request->user(), $message);

        return $this->updated(new MessageResource($message->load('reactions')));
    }
}
