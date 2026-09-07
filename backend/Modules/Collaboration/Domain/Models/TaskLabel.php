<?php

declare(strict_types=1);

namespace Modules\Collaboration\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Organization\Companies\Domain\Models\Company;

/**
 * Reusable, company-scoped task label (brief §12). `color` is a small fixed
 * token validated at the request layer — see CreateTaskLabelRequest.
 *
 * @property string $id
 * @property string $company_id
 * @property string $name
 * @property string $color
 */
class TaskLabel extends Model
{
    use HasUuids;

    protected $table = 'collaboration_task_labels';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'name',
        'color',
    ];

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsToMany<InternalTask, $this> */
    public function tasks(): BelongsToMany
    {
        return $this->belongsToMany(InternalTask::class, 'collaboration_task_label_task', 'label_id', 'task_id')
            ->withPivot('created_at');
    }
}
