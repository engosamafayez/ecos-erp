<?php

declare(strict_types=1);

namespace Modules\IAM\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Permission grant entity.
 *
 * Replaces the old `role_permission` composite-PK pivot. The extra columns
 * (effect, conditions, expires_at) are architectural scaffolding for the future
 * rule engine — no business logic reads them yet.
 *
 * @property string $id
 * @property string $role_id
 * @property string $permission_id
 * @property string $effect 'allow' | 'deny'  (default: 'allow')
 * @property array|null $conditions JSON rule bag
 * @property string|null $expires_at
 * @property string $data_scope Data Scope Engine — self|team|branch|…|all (default: 'all')  [TASK-IAM-002]
 * @property array|null $scope_descriptor Descriptor bag for exotic scopes (channel/region/custom)  [TASK-IAM-002]
 */
class RolePermission extends Pivot
{
    use HasUuids;

    protected $table = 'role_permissions';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001: was `false`, matching the table's
     * original `created_at`-only shape. `role_permissions` now has `updated_at` too (see
     * migration 2026_12_28_000002) — added because Laravel's own pivot hydration
     * (AsPivot::hasTimestampAttributes()) forces timestamps on for ANY fetched row that
     * has `created_at`, regardless of this property, so declaring `false` was already not
     * honoured on the update path. Declaring `true` now just matches what the framework
     * does in practice, with a schema that actually supports it.
     */
    public $timestamps = true;

    /** @var list<string> */
    protected $fillable = [
        'role_id',
        'permission_id',
        'effect',
        'conditions',
        'expires_at',
        'data_scope',
        'scope_descriptor',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'conditions' => 'array',
            'expires_at' => 'datetime',
            'scope_descriptor' => 'array',
        ];
    }
}
