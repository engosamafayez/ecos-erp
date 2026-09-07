<?php

declare(strict_types=1);

namespace Modules\Collaboration\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A watcher, DISTINCT from the primary assignee (brief §5/§16). IAM User
 * identities only — never a Collaboration-owned user record.
 *
 * @property string $id
 * @property string $task_id
 * @property int $user_id
 */
class TaskFollower extends Model
{
    use HasUuids;

    protected $table = 'collaboration_task_followers';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'task_id',
        'user_id',
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
