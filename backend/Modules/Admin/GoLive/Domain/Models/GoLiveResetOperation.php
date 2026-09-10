<?php

declare(strict_types=1);

namespace Modules\Admin\GoLive\Domain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Admin\GoLive\Domain\Enums\ResetOperationStatus;

/**
 * TASK-...-026 §7 — the auditable record of one reset execution attempt. See the migration's
 * docblock for why `status` can never read "completed" after a partial failure.
 *
 * @property string $id
 * @property string $company_id
 * @property string|null $actor_id
 * @property string $idempotency_key
 * @property ResetOperationStatus $status
 * @property array<int, string> $selected_domains
 * @property array<int, string>|null $preserved_domains
 * @property array<string, mixed>|null $preview_counts
 * @property array<string, mixed>|null $execution_counts
 * @property string|null $reason
 * @property string|null $failure_stage
 * @property string|null $failure_message
 * @property string|null $lifecycle_state_at_run
 * @property \Illuminate\Support\Carbon|null $completed_at
 */
class GoLiveResetOperation extends Model
{
    use HasUuids;

    protected $table = 'golive_reset_operations';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * TASK-...-026 §18 — tenant isolation. Same shape as Channel::booted() (TASK-...-024/025):
     * no actor (console/queue) -> no filter; is_system -> no filter; unprivileged with no
     * company -> closes the query; otherwise scoped to the actor's own company_id directly
     * (this table, unlike Channel, DOES carry its own company_id column).
     */
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', static function (Builder $query): void {
            $tenant = app(\App\Core\Company\TenantOwnershipResolver::class);

            if (! $tenant->appliesTo() || $tenant->isUnrestricted()) {
                return;
            }

            $companyId = $tenant->companyId();

            if ($companyId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where('company_id', $companyId);
        });
    }

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'actor_id',
        'idempotency_key',
        'status',
        'selected_domains',
        'preserved_domains',
        'preview_counts',
        'execution_counts',
        'reason',
        'failure_stage',
        'failure_message',
        'lifecycle_state_at_run',
        'completed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ResetOperationStatus::class,
            'selected_domains' => 'array',
            'preserved_domains' => 'array',
            'preview_counts' => 'array',
            'execution_counts' => 'array',
            'completed_at' => 'datetime',
        ];
    }
}
