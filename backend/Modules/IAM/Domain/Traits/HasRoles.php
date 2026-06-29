<?php

declare(strict_types=1);

namespace Modules\IAM\Domain\Traits;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\IAM\Domain\Models\Role;
use Modules\IAM\Domain\Models\UserRole;

/**
 * Adds role-awareness to any Eloquent model (intended for User).
 *
 * Keeps the base User model clean; all RBAC behaviour lives in the IAM module.
 */
trait HasRoles
{
    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'user_roles',
            'user_id',
            'role_id',
        )->using(UserRole::class)->withTimestamps();
    }
}
