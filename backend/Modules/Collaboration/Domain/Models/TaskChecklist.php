<?php

declare(strict_types=1);

namespace Modules\Collaboration\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Checklist is organizational content, NOT task status (brief §13).
 *
 * @property string $id
 * @property string $task_id
 * @property string $title
 * @property int $position
 */
class TaskChecklist extends Model
{
    use HasUuids;

    protected $table = 'collaboration_task_checklists';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'task_id',
        'title',
        'position',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<InternalTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(InternalTask::class, 'task_id');
    }

    /** @return HasMany<TaskChecklistItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(TaskChecklistItem::class, 'checklist_id')->orderBy('position');
    }
}
