<?php

declare(strict_types=1);

namespace Modules\Commerce\Channels\Domain\Models;

use App\Core\Company\TenantOwnershipResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Commerce\Channels\Domain\Enums\ChannelHealthStatus;
use Modules\Commerce\Channels\Domain\Enums\ChannelLifecycleState;
use Modules\Commerce\Channels\Domain\Enums\ChannelPlatform;
use Modules\Commerce\Channels\Domain\Enums\ConnectionStatus;
use Modules\Commerce\Channels\Infrastructure\Database\Factories\ChannelFactory;
use Modules\Organization\Brands\Domain\Models\Brand;
use Modules\Organization\BusinessAccounts\Domain\Models\BusinessAccount;

/**
 * Commerce channel (WooCommerce store, Shopify shop, etc.).
 *
 * @property string $id
 * @property string $brand_id
 * @property string|null $business_account_id
 * @property string|null $code
 * @property string $name
 * @property string|null $channel_type
 * @property string|null $channel_role
 * @property ChannelPlatform $platform
 * @property string $store_url
 * @property bool $is_active
 * @property bool $sync_products
 * @property bool $sync_prices
 * @property bool $sync_stock
 * @property bool $sync_customers
 * @property bool $sync_orders
 * @property \Illuminate\Support\Carbon|null $orders_sync_watermark_at
 * @property string|null $orders_initial_import_policy
 * @property \Illuminate\Support\Carbon|null $orders_initial_import_cutoff_at
 * @property \Illuminate\Support\Carbon|null $orders_sync_activated_at
 * @property string|null $orders_sync_activated_by
 * @property string|null $external_webhook_order_created_id
 * @property string|null $external_webhook_order_updated_id
 * @property string|null $external_webhook_product_created_id
 * @property string|null $external_webhook_product_updated_id
 * @property string|null $external_webhook_product_deleted_id
 * @property string|null $external_webhook_customer_created_id
 * @property string|null $external_webhook_customer_updated_id
 * @property \Illuminate\Support\Carbon|null $last_sync_at
 * @property \Illuminate\Support\Carbon|null $last_webhook_received_at
 * @property \Illuminate\Support\Carbon|null $last_successful_sync_at
 * @property \Illuminate\Support\Carbon|null $last_error_at
 * @property string|null $last_error_message
 * @property ConnectionStatus $connection_status
 * @property ChannelLifecycleState $lifecycle_state
 * @property string|null $customer_sync_policy
 * @property \Illuminate\Support\Carbon|null $shipping_mapping_reviewed_at
 */
class Channel extends Model
{
    /** @use HasFactory<ChannelFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * CD-03 (TASK-ECOS-COMMERCE-PRE-USER-REVIEW-REMEDIATION-002 §4) — tenant isolation.
     *
     * Channels had NO tenant scope of any kind, so `Channel::find($id)` (via
     * EloquentChannelRepository::findById(), reached by GET /api/channels/{id}) returned any
     * company's channel, and GET /api/channels returned every company's channels whenever the
     * caller simply omitted the `company_id` query parameter — a parameter that was the only
     * thing narrowing the list, and that came from caller input rather than from authenticated
     * context.
     *
     * Same contract as Order and Warehouse (TASK-GOLIVE-RC6-REPAIR-001), resolved through the
     * one canonical authority, TenantOwnershipResolver — no new tenancy mechanism:
     *
     *   - no actor (console, queue workers, seeders, migrations) → no filter. This is what
     *     keeps the PUBLIC, signature-verified WooCommerce webhook routes working: they carry
     *     no `auth:sanctum` actor, so `appliesTo()` is false and route-model binding still
     *     resolves the channel. Tenancy for that path is derived from the channel itself
     *     (WooCommerceOrderImporter::resolveCompanyId(), which throws rather than importing an
     *     untenanted order), so nothing is loosened by not filtering here.
     *   - `isUnrestricted()` (an is_system role, the platform's documented privilege flag) →
     *     no filter. Super-admin cross-company semantics come from existing IAM authority and
     *     nowhere else.
     *   - a null company for an unprivileged actor CLOSES the query (`1 = 0`) rather than
     *     removing the filter — the RC-6 fail-open lesson.
     *
     * Channels carry no `company_id` column; ownership is `channels.brand_id → brands.company_id`,
     * the platform's existing convention for channel tenancy (already relied on by
     * WooCommerceOrderImporter::resolveCompanyId() and by the repository's own brand filter).
     * The scope is therefore a `whereExists` against `brands` rather than a column predicate.
     * A raw subquery is used deliberately in preference to `whereHas('brand', …)`: it cannot
     * re-enter another model's global scopes from inside this one, and it joins on the `brands`
     * primary key.
     *
     * A channel whose `brand_id` is NULL resolves to no company and is therefore invisible to
     * every tenant. That is intentional and fail-closed, matching the importer's stance that an
     * un-owned integration row is not a lesser row but one no tenant control can see.
     * `channels.brand_id` is `required` in both the create and update requests, so no HTTP path
     * produces such a row.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', static function (Builder $query): void {
            $tenant = app(TenantOwnershipResolver::class);

            if (! $tenant->appliesTo()) {
                return;
            }

            if ($tenant->isUnrestricted()) {
                return;
            }

            $companyId = $tenant->companyId();

            if ($companyId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $table = $query->getModel()->getTable();

            $query->whereExists(static function ($sub) use ($companyId, $table): void {
                $sub->selectRaw('1')
                    ->from('brands')
                    ->whereColumn('brands.id', "{$table}.brand_id")
                    ->where('brands.company_id', $companyId);
            });
        });
    }

    /**
     * @var list<string>
     */
    protected $fillable = [
        'brand_id',
        'business_account_id',
        'code',
        'name',
        'channel_type',
        'channel_role',
        'platform',
        'store_url',
        'is_active',
        'sync_products',
        'sync_prices',
        'sync_stock',
        'sync_customers',
        'sync_orders',
        'orders_sync_watermark_at',
        'orders_initial_import_policy',
        'orders_initial_import_cutoff_at',
        'orders_sync_activated_at',
        'orders_sync_activated_by',
        'external_webhook_order_created_id',
        'external_webhook_order_updated_id',
        'external_webhook_product_created_id',
        'external_webhook_product_updated_id',
        'external_webhook_product_deleted_id',
        'external_webhook_customer_created_id',
        'external_webhook_customer_updated_id',
        'last_sync_at',
        'last_webhook_received_at',
        'last_successful_sync_at',
        'last_error_at',
        'last_error_message',
        'connection_status',
        'lifecycle_state',
        'customer_sync_policy',
        'shipping_mapping_reviewed_at',
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
            'sync_customers' => 'boolean',
            'sync_orders' => 'boolean',
            'orders_sync_watermark_at' => 'datetime',
            'orders_initial_import_cutoff_at' => 'datetime',
            'orders_sync_activated_at' => 'datetime',
            'last_sync_at' => 'datetime',
            'last_webhook_received_at' => 'datetime',
            'last_successful_sync_at' => 'datetime',
            'last_error_at' => 'datetime',
            'connection_status' => ConnectionStatus::class,
            'lifecycle_state' => ChannelLifecycleState::class,
            'shipping_mapping_reviewed_at' => 'datetime',
        ];
    }

    /**
     * The ONE canonical check for "is this channel actually live" (TASK-...-WOO-04, 042A-R1
     * §5). Every outbound dispatch point and inbound webhook job gates on this — not on
     * is_active/sync_* flags alone, and not by re-deriving the enum comparison inline at each
     * call site. `lifecycle_state` only ever becomes Live via TransitionChannelToLiveAction.
     */
    public function isLive(): bool
    {
        return $this->lifecycle_state === ChannelLifecycleState::Live;
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<BusinessAccount, $this>
     */
    public function businessAccount(): BelongsTo
    {
        return $this->belongsTo(BusinessAccount::class);
    }

    /**
     * @return HasOne<ChannelCredential, $this>
     */
    public function credential(): HasOne
    {
        return $this->hasOne(ChannelCredential::class);
    }

    public function healthStatus(): ChannelHealthStatus
    {
        $lastSuccess = $this->last_successful_sync_at ?? $this->last_sync_at;

        if ($this->last_error_at !== null) {
            $erroredAfterSuccess = $lastSuccess === null || $this->last_error_at->gt($lastSuccess);

            if ($erroredAfterSuccess) {
                return ChannelHealthStatus::Error;
            }
        }

        if ($lastSuccess !== null && $lastSuccess->diffInHours(now()) > 24) {
            return ChannelHealthStatus::Warning;
        }

        if ($this->connection_status !== ConnectionStatus::Connected) {
            return ChannelHealthStatus::Warning;
        }

        return ChannelHealthStatus::Healthy;
    }

    protected static function newFactory(): ChannelFactory
    {
        return ChannelFactory::new();
    }
}
