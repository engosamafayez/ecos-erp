<?php

declare(strict_types=1);

namespace Modules\Collaboration\Domain\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Collaboration\Domain\Enums\AttachedToType;
use Modules\Collaboration\Domain\Enums\OperationalContextType;

/**
 * Foundation only (architecture report §13). A string-keyed reference, not a
 * hard FK to Order/Trip/Driver/etc. — Collaboration never owns or joins
 * those tables directly; rendering the referenced entity's details still
 * goes through that entity's own authorization.
 *
 * @property string $id
 * @property OperationalContextType $context_type
 * @property string $context_id
 * @property AttachedToType $attached_to_type
 * @property string $attached_to_id
 * @property int $created_by_user_id
 * @property \Illuminate\Support\Carbon $created_at
 */
class OperationalContextLink extends Model
{
    use HasUuids;

    protected $table = 'collaboration_operational_context_links';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'context_type',
        'context_id',
        'attached_to_type',
        'attached_to_id',
        'created_by_user_id',
        'created_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'context_type' => OperationalContextType::class,
            'attached_to_type' => AttachedToType::class,
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
