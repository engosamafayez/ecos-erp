<?php

declare(strict_types=1);

namespace Modules\Commerce\Channels\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Commerce\Channels\Domain\Enums\ChannelPlatform;
use Modules\Commerce\Channels\Domain\Enums\ConnectionStatus;
use Modules\Commerce\Channels\Infrastructure\Database\Factories\ChannelFactory;
use Modules\Organization\Companies\Domain\Models\Company;

/**
 * Commerce channel (WooCommerce store, Shopify shop, etc.).
 *
 * @property string $id
 * @property string $company_id
 * @property string $name
 * @property ChannelPlatform $platform
 * @property string $store_url
 * @property bool $is_active
 * @property bool $sync_products
 * @property bool $sync_prices
 * @property bool $sync_stock
 * @property \Illuminate\Support\Carbon|null $last_sync_at
 * @property ConnectionStatus $connection_status
 */
class Channel extends Model
{
    /** @use HasFactory<ChannelFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'name',
        'platform',
        'store_url',
        'is_active',
        'sync_products',
        'sync_prices',
        'sync_stock',
        'last_sync_at',
        'connection_status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'platform' => ChannelPlatform::class,
            'is_active' => 'boolean',
            'sync_products' => 'boolean',
            'sync_prices' => 'boolean',
            'sync_stock' => 'boolean',
            'last_sync_at' => 'datetime',
            'connection_status' => ConnectionStatus::class,
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return HasOne<ChannelCredential, $this>
     */
    public function credential(): HasOne
    {
        return $this->hasOne(ChannelCredential::class);
    }

    protected static function newFactory(): ChannelFactory
    {
        return ChannelFactory::new();
    }
}
