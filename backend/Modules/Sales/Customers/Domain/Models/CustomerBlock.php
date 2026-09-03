<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-CUSTOMERS-009.
 *
 * One block EPISODE — phone-first (§4): `customer_id` is nullable so a phone can
 * be blocked before any Customer exists for it. A re-block after an unblock is a
 * NEW row, so the full history for a company+phone is every row for that pair
 * ordered by `blocked_at` — see BlockedCustomerPolicy.
 *
 * `active_phone_key` is a DB-generated column (see the creating migration) and is
 * intentionally not fillable — it derives from `is_active`/`normalized_phone` and
 * must never be written directly.
 *
 * @property string $id
 * @property string $company_id
 * @property string|null $customer_id
 * @property string $normalized_phone
 * @property bool $is_active
 * @property string $block_reason
 * @property string|null $blocked_by
 * @property \Illuminate\Support\Carbon $blocked_at
 * @property string|null $unblock_reason
 * @property string|null $unblocked_by
 * @property \Illuminate\Support\Carbon|null $unblocked_at
 */
class CustomerBlock extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'company_id',
        'customer_id',
        'normalized_phone',
        'is_active',
        'block_reason',
        'blocked_by',
        'blocked_at',
        'unblock_reason',
        'unblocked_by',
        'unblocked_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'blocked_at' => 'datetime',
            'unblocked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
