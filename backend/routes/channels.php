<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Broadcast;
use Modules\Collaboration\Domain\Models\ConversationParticipant;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Collaboration (ADR-044 §1.8 — TASK-ECOS-COLLABORATION-MEDIA-VOICE-REALTIME-
| NOTIFICATIONS-SEARCH-003): a user may subscribe to a conversation's private
| channel only if they hold an active ConversationParticipant row for it —
| knowing the conversation id alone grants nothing (brief §13). This is the
| exact same participation check every Collaboration REST endpoint already
| enforces (ConversationPolicy / the actions themselves) — one source of
| truth (collaboration_conversation_participants) either way.
|
| This file did not exist before this task — see the engineering report for
| the composer.json / config/broadcasting.php / bootstrap/app.php changes
| that wire it in, and the Reverb runtime-dependency status.
*/
Broadcast::channel('collaboration.conversation.{conversationId}', function ($user, string $conversationId): bool {
    return ConversationParticipant::query()
        ->where('conversation_id', $conversationId)
        ->where('user_id', $user->id)
        ->whereNull('left_at')
        ->exists();
});
