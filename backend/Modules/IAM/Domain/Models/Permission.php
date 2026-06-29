<?php

declare(strict_types=1);

namespace Modules\IAM\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * RBAC Permission entity.
 *
 * Permissions follow the convention: {module}.{action}
 * e.g. "products.view", "orders.fulfill", "roles.assign"
 *
 * @property string      $id
 * @property string      $name
 * @property string      $module
 * @property string      $action
 * @property string|null $description
 */
class Permission extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'module',
        'action',
        'description',
    ];

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(
            Role::class,
            'role_permission',
            'permission_id',
            'role_id',
        );
    }
}
