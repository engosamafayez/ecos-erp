<?php

declare(strict_types=1);

namespace Modules\Collaboration\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Collaboration\Domain\Enums\ParticipantRole;
use Modules\Collaboration\Infrastructure\Database\Factories\ConversationParticipantFactory;

/**
 * @property string $id
 * @property string $conversation_id
 * @property int $user_id
 * @property ParticipantRole $role
 * @property \Illuminate\Support\Carbon $joined_at
 * @property \Illuminate\Support\Carbon|null $left_at
 * @property \Illuminate\Support\Carbon|null $last_read_at
 * @property string|null $last_read_message_id
 * @property \Illuminate\Support\Carbon|null $muted_at
 */
class ConversationParticipant extends Model
{
    /** @use HasFactory<ConversationParticipantFactory> */
    use HasFactory, HasUuids;

    protected $table = 'collaboration_conversation_participants';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'conversation_id',
        'user_id',
        'role',
        'joined_at',
        'left_at',
        'last_read_at',
        'last_read_message_id',
        'muted_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => ParticipantRole::class,
            'joined_at' => 'datetime',
            'left_at' => 'datetime',
            'last_read_at' => 'datetime',
            'muted_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Message, $this> */
    public function lastReadMessage(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'last_read_message_id');
    }

    public function isActive(): bool
    {
        return $this->left_at === null;
    }

    public function isMuted(): bool
    {
        return $this->muted_at !== null;
    }

    protected static function newFactory(): ConversationParticipantFactory
    {
        return ConversationParticipantFactory::new();
    }
}
