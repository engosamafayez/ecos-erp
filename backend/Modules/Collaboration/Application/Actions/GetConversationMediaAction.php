<?php

declare(strict_types=1);

namespace Modules\Collaboration\Application\Actions;

use App\Core\Actions\BaseAction;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use Modules\Collaboration\Domain\Models\Conversation;
use Modules\Collaboration\Domain\Models\ConversationParticipant;
use Modules\Collaboration\Domain\Models\Message;

/**
 * WhatsApp-style conversation "Media"/"Documents"/"Links" tabs (architecture
 * report §20) — a read-only aggregation over the SAME canonical
 * `collaboration_messages` table SearchMessagesAction/GetConversationMessagesAction
 * already query, never a second media index. "Links" has no dedicated
 * message type: it is derived from plain `text` messages whose body contains
 * a URL, via a MySQL REGEXP predicate — no new schema.
 */
final class GetConversationMediaAction extends BaseAction
{
    private const URL_PATTERN = '(https?://|www\\.)[^[:space:]]+';

    /** @param  mixed  ...$arguments  [User $user, Conversation $conversation, string $type, int $limit] */
    public function execute(mixed ...$arguments): Collection
    {
        $user = $arguments[0] ?? null;
        $conversation = $arguments[1] ?? null;
        $type = $arguments[2] ?? null;
        $limit = $arguments[3] ?? 50;

        if (! $user instanceof User || ! $conversation instanceof Conversation || ! is_string($type)) {
            throw new InvalidArgumentException('GetConversationMediaAction::execute expects (User $user, Conversation $conversation, string $type, int $limit).');
        }

        $isParticipant = ConversationParticipant::query()
            ->where('conversation_id', $conversation->id)
            ->where('user_id', $user->id)
            ->whereNull('left_at')
            ->exists();

        if (! $isParticipant) {
            throw new AuthorizationException('You are not a participant of this conversation.');
        }

        $query = Message::query()->where('conversation_id', $conversation->id);

        if ($type === 'link') {
            $query->where('type', 'text')->whereRaw('body REGEXP ?', [self::URL_PATTERN]);
        } else {
            $query->where('type', $type);
        }

        return $query->with('sender')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }
}
