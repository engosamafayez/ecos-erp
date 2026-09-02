<?php

declare(strict_types=1);

namespace Modules\Collaboration\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Collaboration\Domain\Enums\ConversationType;
use Modules\Collaboration\Infrastructure\Database\Factories\ConversationFactory;
use Modules\Organization\Companies\Domain\Models\Company;
use Modules\Organization\Teams\Domain\Models\Team;

/**
 * A Collaboration Group is a Conversation with type=group. It is an ad-hoc,
 * Collaboration-owned entity — never to be confused with, or presented as,
 * an Organizational Team (`Organization\Teams\Team`). See ADR-044 §7.
 *
 * @property string $id
 * @property string $company_id
 * @property ConversationType $type
 * @property string|null $title
 * @property int $created_by_user_id
 * @property string|null $team_id
 * @property string|null $direct_pair_key
 * @property \Illuminate\Support\Carbon|null $last_message_at
 */
class Conversation extends Model
{
    /** @use HasFactory<ConversationFactory> */
    use HasFactory, HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'type',
        'title',
        'created_by_user_id',
        'team_id',
        'direct_pair_key',
        'last_message_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => ConversationType::class,
            'last_message_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<Team, $this> */
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    /** @return HasMany<ConversationParticipant, $this> */
    public function participants(): HasMany
    {
        return $this->hasMany(ConversationParticipant::class);
    }

    /** @return HasMany<ConversationParticipant, $this> */
    public function activeParticipants(): HasMany
    {
        return $this->participants()->whereNull('left_at');
    }

    /** @return HasMany<Message, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * Order-independent key for the direct-conversation uniqueness constraint
     * (architecture report §8). Not meaningful for group conversations.
     */
    public static function directPairKey(int $userIdA, int $userIdB): string
    {
        $pair = [$userIdA, $userIdB];
        sort($pair);

        return implode(':', $pair);
    }

    protected static function newFactory(): ConversationFactory
    {
        return ConversationFactory::new();
    }
}
