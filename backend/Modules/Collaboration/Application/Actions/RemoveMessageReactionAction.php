<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Modules\Collaboration\Domain\Models\ConversationParticipant;
use Modules\Collaboration\Domain\Models\Message;

final class RemoveMessageReactionAction extends BaseAction
{
    /** @param  mixed  ...$arguments  [User $actor, Message $message] */
    public function execute(mixed ...$arguments): Message
    {
        $actor = $arguments[0] ?? null;
        $message = $arguments[1] ?? null;

        if (! $actor instanceof User || ! $message instanceof Message) {
            throw new InvalidArgumentException('RemoveMessageReactionAction::execute expects (User $actor, Message $message).');
        }

        $isParticipant = ConversationParticipant::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('user_id', $actor->id)
            ->whereNull('left_at')
            ->exists();

        if (! $isParticipant) {
            throw new AuthorizationException('You are not a participant of this conversation.');
        }

        // A no-op (never an error) when the actor has no reaction to remove —
        // symmetric with how unfollow/detach-label already behave in this module.
        $message->reactions()->where('user_id', $actor->id)->delete();

        return $message;
    }
}
