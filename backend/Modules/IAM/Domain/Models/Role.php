<?php

declare(strict_types=1);

namespace Modules\IAM\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * RBAC Role entity.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property bool $is_system When true, the role bypasses all permission checks.
 *                           Never hardcode role slugs for the bypass — check is_system.
 * @property \Illuminate\Support\Carbon|null $archived_at Management state only
 *           (TASK-ECOS-IAM-FINAL-REMEDIATION-DIRECT-DEV-001 §11/§12): an archived role is
 *           withdrawn from the assignable catalogue but keeps resolving for any user who
 *           still holds it. Archival never silently revokes access.
 * @property string|null $archived_reason
 * @property int|null $archived_by
 * @property array<string,string>|null $navigation_overrides UX-only nav item visibility
 *           overrides (User-review remediation, Batch 02, item I) — `key => 'visible' |
 *           'hidden'`. Never consulted by any authorization check; a null map means every
 *           item inherits the permission-gated default, unchanged from before this existed.
 */
class Role extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'slug',
        'description',
        'is_system',
        'archived_at',
        'archived_reason',
        'archived_by',
        'navigation_overrides',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'archived_at' => 'datetime',
            'navigation_overrides' => 'array',
        ];
    }

    /** Withdrawn from the assignable catalogue (§11) — still valid for current holders. */
    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            Permission::class,
            'role_permissions',
            'role_id',
            'permission_id',
        )->using(RolePermission::class)->withPivot('effect', 'conditions', 'expires_at');
    }

    /** @return BelongsToMany<\App\Models\User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            \App\Models\User::class,
            'user_roles',
            'role_id',
            'user_id',
        )->using(UserRole::class)->withTimestamps();
    }

    /**
     * The Role Template that compiled this role, if any (inverse of RoleTemplate::role()).
     * Added by TASK-ECOS-IAM-SECURE-ADMIN-API-002 for the read-only Roles Admin API (§7) —
     * a role that came from a template links back to it; a role predating the template system
     * (e.g. 'super-admin') has none.
     *
     * @return HasOne<RoleTemplate, $this>
     */
    public function roleTemplate(): HasOne
    {
        return $this->hasOne(RoleTemplate::class, 'role_id');
    }
}
