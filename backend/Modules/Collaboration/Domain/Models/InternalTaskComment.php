<?php

declare(strict_types=1);

namespace Modules\Collaboration\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A Task Comment, never a Conversation Message (brief §16) — its own table,
 * immutable once posted (no updated_at), same posture as Message (Task 2 §9).
 *
 * @property string $id
 * @property string $task_id
 * @property int $author_user_id
 * @property string $body
 * @property \Illuminate\Support\Carbon $created_at
 */
class InternalTaskComment extends Model
{
    use HasUuids;

    protected $table = 'collaboration_internal_task_comments';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'task_id',
        'author_user_id',
        'body',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<InternalTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(InternalTask::class, 'task_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_user_id');
    }
}
