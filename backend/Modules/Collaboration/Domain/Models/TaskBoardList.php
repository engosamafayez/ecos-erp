<?php

declare(strict_types=1);

namespace Modules\Collaboration\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Organization\Companies\Domain\Models\Company;

/**
 * A Trello-style board LIST — an organizational container a task card sits
 * in. Never a second task-status/workflow engine (TASK-ECOS-INTERNAL-
 * COLLABORATION-TASKS-TRELLO-FINAL-CLOSURE-002 §1); canonical TaskStatus on
 * InternalTask remains the sole lifecycle authority.
 *
 * @property string $id
 * @property string $company_id
 * @property string $name
 * @property int $position
 * @property \Illuminate\Support\Carbon|null $archived_at
 * @property int $created_by_user_id
 */
class TaskBoardList extends Model
{
    use HasUuids;

    protected $table = 'collaboration_task_board_lists';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'name',
        'position',
        'archived_at',
        'created_by_user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<InternalTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(InternalTask::class, 'task_list_id')->orderBy('board_position');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }
}
