<?php

declare(strict_types=1);

namespace App\Core\Audit;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AuditLog extends Model
{
    public $incrementing = false;

    public $timestamps = false;

    protected $keyType = 'string';

    protected $table = 'audit_logs';

    protected $fillable = [
        'id',
        'company_id',
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'old_values',
        'new_values',
        'metadata',
        'ip_address',
        'user_agent',
        'config_version_id',
        'policy_version',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    /**
     * CORE-02 Task 2 — read-only convenience for the Audit workspace. `user_id` has no
     * foreign key constraint (a deliberate, pre-existing choice: an audit row must survive
     * the actor's own deletion), so this relation may resolve to null on an already-deleted
     * user; callers must handle that, never assume presence.
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
