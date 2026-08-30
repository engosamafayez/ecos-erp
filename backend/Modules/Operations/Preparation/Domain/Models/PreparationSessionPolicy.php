<?php

declare(strict_types=1);

namespace Modules\Operations\Preparation\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;

/**
 * @property string $id
 * @property string $company_id
 * @property string|null $warehouse_id null = applies to all company warehouses
 * @property string $auto_create_time e.g. "06:00:00"
 * @property string|null $freeze_time e.g. "14:00:00"; null = no auto-freeze
 * @property string|null $auto_close_time e.g. "23:59:00"; null = manual close
 * @property array $eligible_order_statuses
 * @property bool $auto_attach_orders
 * @property bool $auto_recalculate_demand
 * @property bool $is_active
 * @property string|null $created_by
 * @property string|null $updated_by
 */
class PreparationSessionPolicy extends Model
{
    use HasUuids;

    protected $table = 'preparation_session_policies';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'warehouse_id',
        'auto_create_time',
        'freeze_time',
        'auto_close_time',
        'eligible_order_statuses',
        'auto_attach_orders',
        'auto_recalculate_demand',
        'is_active',
        'created_by',
        'updated_by',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'eligible_order_statuses' => 'array',
        'auto_attach_orders' => 'boolean',
        'auto_recalculate_demand' => 'boolean',
        'is_active' => 'boolean',
    ];

    /**
     * Returns the default statuses when no policy exists.
     *
     * ADR-042 §7 — an order may enter Preparation only while it is In Progress or
     * Confirmed. `scheduled` and `awaiting_payment` are deliberately excluded: they
     * sit outside fulfilment execution until their own business triggers move them
     * to In Progress.
     *
     * HISTORY, because this list has been wrong twice and both times silently:
     *   - `confirm_order` was a pre-V2 token, renamed to `confirmed` in July 2026
     *     and then MERGED INTO `in_progress` by the V3 consolidation. It matched no
     *     enum case, so the effective policy had collapsed to `in_progress` alone.
     *   - V3 then added `new` here, which ADR-042 removes again.
     * The lesson encoded by ADR-042 §8: a stale status string in a policy list does
     * not fail loudly, it silently shrinks the eligible set. Hence the closed list
     * and the test that asserts it.
     *
     * Deliberately a closed list: an unknown or future status is NOT eligible.
     *
     * @return list<string>
     */
    public static function defaultEligibleStatuses(): array
    {
        return array_map(
            static fn (OrderStatus $s): string => $s->value,
            OrderStatus::fulfilmentEligible(),
        );
    }
}
