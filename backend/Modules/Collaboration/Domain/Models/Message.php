<?php

declare(strict_types=1);

namespace Modules\Collaboration\Domain\Models;

use App\Core\Documents\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasVersion7Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Collaboration\Domain\Enums\MessageType;
use Modules\Collaboration\Infrastructure\Database\Factories\MessageFactory;

/**
 * Immutable once created (ADR-044 §1.5) — deliberately `$timestamps = false`
 * with a single hand-set `created_at`; there is no `updated_at` column to
 * touch. Uses UUIDv7 (time-ordered) ids purely for index locality — unread
 * derivation itself relies on `created_at`, not on id ordering, so it stays
 * correct regardless.
 *
 * @property string $id
 * @property string $conversation_id
 * @property int $sender_user_id
 * @property MessageType $type
 * @property string|null $body
 * @property string|null $reply_to_message_id
 * @property \Illuminate\Support\Carbon $created_at
 */
class Message extends Model
{
    /** @use HasFactory<MessageFactory> */
    use HasFactory, HasVersion7Uuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'conversation_id',
        'sender_user_id',
        'type',
        'body',
        'reply_to_message_id',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => MessageType::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    /** @return BelongsTo<Message, $this> */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_message_id');
    }

    /** @return HasMany<MessageMention, $this> */
    public function mentions(): HasMany
    {
        return $this->hasMany(MessageMention::class);
    }

    /**
     * Not a real FK relation — `Document.subject_type`/`subject_id` is the
     * generic string-keyed reference the whole platform uses (§17). At most
     * one per message in this schema (one file/image/voice per send).
     */
    public function attachment(): ?Document
    {
        return Document::query()
            ->where('subject_type', 'CollaborationMessage')
            ->where('subject_id', $this->id)
            ->where('is_active', true)
            ->first();
    }

    protected static function newFactory(): MessageFactory
    {
        return MessageFactory::new();
    }
}
