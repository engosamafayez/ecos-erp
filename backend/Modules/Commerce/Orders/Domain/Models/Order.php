<?php

declare(strict_types=1);

namespace Modules\Commerce\Orders\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Commerce\Channels\Domain\Models\Channel;
use Modules\Commerce\Orders\Domain\Enums\OrderStatus;
use Modules\Sales\Customers\Domain\Models\Customer;

/**
 * Commerce order — internal order entity.
 *
 * @property string $id
 * @property string|null $channel_id
 * @property string $customer_id
 * @property string|null $external_order_id
 * @property string $order_number
 * @property string $order_date
 * @property OrderStatus $status
 * @property float $subtotal
 * @property float $total
 * @property string|null $notes
 * @property string|null $billing_first_name
 * @property string|null $billing_last_name
 * @property string|null $shipping_first_name
 * @property string|null $shipping_last_name
 * @property string|null $shipping_company
 * @property string|null $shipping_country
 * @property string|null $shipping_state
 * @property string|null $shipping_city
 * @property string|null $shipping_address_1
 * @property string|null $shipping_address_2
 * @property string|null $shipping_postcode
 * @property string|null $customer_note
 * @property string|null $payment_method
 * @property string|null $payment_method_title
 * @property string|null $transaction_id
 * @property \Illuminate\Support\Carbon|null $date_paid
 * @property string|null $shipping_method
 * @property float $shipping_total
 * @property float $discount_total
 */
class Order extends Model
{
    use HasUuids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'channel_id',
        'customer_id',
        'external_order_id',
        'order_number',
        'order_date',
        'status',
        'subtotal',
        'total',
        'notes',
        'billing_first_name',
        'billing_last_name',
        'shipping_first_name',
        'shipping_last_name',
        'shipping_company',
        'shipping_country',
        'shipping_state',
        'shipping_city',
        'shipping_address_1',
        'shipping_address_2',
        'shipping_postcode',
        'customer_note',
        'payment_method',
        'payment_method_title',
        'transaction_id',
        'date_paid',
        'shipping_method',
        'shipping_total',
        'discount_total',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'subtotal' => 'float',
            'total' => 'float',
            'shipping_total' => 'float',
            'discount_total' => 'float',
            'order_date' => 'date:Y-m-d',
            'date_paid' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Channel, $this>
     */
    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return HasMany<OrderLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class);
    }
}
