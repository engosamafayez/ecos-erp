<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;
use Modules\Collaboration\Domain\Models\ConversationParticipant;
use Modules\Collaboration\Domain\Models\Message;

/**
 * Upsert, not insert (§13 — "one user may add/remove their reaction"): a
 * user has at most one reaction per message (DB-unique-constrained);
 * reacting again with a different emoji replaces it rather than adding a
 * second row. Participation-gated exactly like SendMessageAction/
 * GetConversationMessagesAction — never a permission, and never leaks
 * across conversations the actor isn't in.
 */
final class SetMessageReactionAction extends BaseAction
{
    /** @param  mixed  ...$arguments  [User $actor, Message $message, string $emoji] */
    public function execute(mixed ...$arguments): Message
    {
        $actor = $arguments[0] ?? null;
        $message = $arguments[1] ?? null;
        $emoji = $arguments[2] ?? null;

        if (! $actor instanceof User || ! $message instanceof Message || ! is_string($emoji) || $emoji === '') {
            throw new InvalidArgumentException('SetMessageReactionAction::execute expects (User $actor, Message $message, string $emoji).');
        }

        $this->assertParticipant($actor, $message);

        $message->reactions()->updateOrCreate(
            ['user_id' => $actor->id],
            ['emoji' => $emoji, 'created_at' => now()],
        );

        return $message;
    }

    private function assertParticipant(User $actor, Message $message): void
    {
        $isParticipant = ConversationParticipant::query()
            ->where('conversation_id', $message->conversation_id)
            ->where('user_id', $actor->id)
            ->whereNull('left_at')
            ->exists();

        if (! $isParticipant) {
            throw new AuthorizationException('You are not a participant of this conversation.');
        }
    }
}
