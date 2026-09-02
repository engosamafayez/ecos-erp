<?php

declare(strict_types=1);

namespace Modules\Collaboration\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only (brief §18). `event_type` is a short code
 * (created/assigned/priority_changed/due_date_changed/status_changed/
 * comment_added/attachment_added/cancelled/completed) with optional
 * from/to values for display — not an event-sourcing platform, just a
 * readable audit trail.
 *
 * @property string $id
 * @property string $task_id
 * @property int $actor_user_id
 * @property string $event_type
 * @property string|null $from_value
 * @property string|null $to_value
 * @property \Illuminate\Support\Carbon $created_at
 */
class InternalTaskActivity extends Model
{
    use HasUuids;

    protected $table = 'collaboration_internal_task_activity';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'task_id',
        'actor_user_id',
        'event_type',
        'from_value',
        'to_value',
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
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
