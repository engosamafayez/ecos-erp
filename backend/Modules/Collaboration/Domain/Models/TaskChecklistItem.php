<?php

declare(strict_types=1);

namespace Modules\Collaboration\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $checklist_id
 * @property string $title
 * @property bool $is_completed
 * @property int $position
 */
class TaskChecklistItem extends Model
{
    use HasUuids;

    protected $table = 'collaboration_task_checklist_items';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'checklist_id',
        'title',
        'is_completed',
        'position',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_completed' => 'boolean',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<TaskChecklist, $this> */
    public function checklist(): BelongsTo
    {
        return $this->belongsTo(TaskChecklist::class, 'checklist_id');
    }
}
