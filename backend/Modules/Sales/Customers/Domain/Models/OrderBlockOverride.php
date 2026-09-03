<?php

declare(strict_types=1);

namespace Modules\Sales\Customers\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * TASK-ECOS-COMMERCE-CUSTOMERS-BATCH-02-BLOCKED-CUSTOMERS-009.
 *
 * The one-order override grant (§25-§27). `order_id` is UNIQUE at the database
 * level — this row IS the "does this Order have an approved active override"
 * read authority (§32) and the audit record of the grant, in one place. Never
 * updated after creation; an override is never revoked, only superseded by the
 * Customer/phone remaining blocked for every OTHER order (§26).
 *
 * @property string $id
 * @property string $order_id
 * @property string $company_id
 * @property string|null $customer_block_id
 * @property string|null $granted_by
 * @property string $reason
 * @property \Illuminate\Support\Carbon $granted_at
 */
class OrderBlockOverride extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'order_id',
        'company_id',
        'customer_block_id',
        'granted_by',
        'reason',
        'granted_at',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
