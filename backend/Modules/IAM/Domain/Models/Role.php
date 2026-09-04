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
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
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
