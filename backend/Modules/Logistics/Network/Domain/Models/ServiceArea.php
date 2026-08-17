<?php

declare(strict_types=1);

namespace Modules\Logistics\Network\Domain\Models;

use App\Core\Company\TenantOwnershipResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Modules\Logistics\Network\Domain\Enums\ServiceAreaStatus;

/**
 * A commercial service region.
 *
 * Composed of geography that already exists — it stores no place of its own.
 * See ServiceAreaMember, which is the anti-duplication seam (Directive 8).
 */
class ServiceArea extends Model
{
    protected $table = 'network_service_areas';

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => ServiceAreaStatus::Draft->value,
        'default_lead_time_hours' => 24,
        'priority' => 0,
    ];

    protected $fillable = [
        'uuid', 'company_id', 'dispatch_region_id',
        'code', 'name', 'description', 'status', 'status_reason',
        'default_lead_time_hours', 'priority', 'color', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ServiceAreaStatus::class,
            'default_lead_time_hours' => 'integer',
            'priority' => 'integer',
        ];
    }

    /**
     * T-01 — tenant isolation.
     *
     * This model carried no tenant scope. `NetworkController::area()` resolves by
     * `where('uuid', $id)` alone, so a Company A actor holding `network.view` read a
     * Company B service area over HTTP and received 200. The defect is pre-existing
     * (introduced with the Phase 2 Network foundation) and was latent only because
     * `network.view` did not exist in the environment; restoring that permission made
     * it reachable.
     *
     * Verbatim the scope the already-scoped models use (Order, Warehouse, Supplier,
     * ShippingPricingRule, GoodsReceipt, SupplierInvoice) — a foreign row becomes
     * invisible, `firstOrFail()` raises the existing not-found exception, and the
     * response is the 404 the certified ECOS tenant contract expects. No new tenant
     * mechanism is introduced, and no endpoint is redesigned.
     */
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', static function (Builder $query): void {
            $tenant = app(TenantOwnershipResolver::class);

            // Console, queue workers, seeders and migrations run with no actor.
            if (! $tenant->appliesTo()) {
                return;
            }

            // Cross-company access is granted by an is_system role, never by the
            // mere absence of a company. See TenantOwnershipResolver.
            if ($tenant->isUnrestricted()) {
                return;
            }

            $companyId = $tenant->companyId();

            // RC-6: a null company must close the query, not remove the filter.
            if ($companyId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where('company_id', $companyId);
        });

        static::creating(function (self $area): void {
            if ($area->uuid === null) {
                $area->uuid = (string) Str::uuid();
            }
        });
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(DispatchRegion::class, 'dispatch_region_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ServiceAreaMember::class, 'service_area_id');
    }

    public function coverageRules(): HasMany
    {
        return $this->hasMany(CoverageRule::class, 'service_area_id');
    }

    public function capacityPlans(): HasMany
    {
        return $this->hasMany(CapacityPlan::class, 'service_area_id')->latest('plan_date');
    }

    public function acceptsCommitments(): bool
    {
        return $this->status->acceptsCommitments();
    }

    public function isServing(): bool
    {
        return $this->status->isServing();
    }

    /** Members that grant coverage, ignoring the carve-outs. */
    public function includedMembers(): HasMany
    {
        return $this->members()->where('is_excluded', false);
    }

    public function excludedMembers(): HasMany
    {
        return $this->members()->where('is_excluded', true);
    }

    /**
     * An area with no included member covers nothing. Worth surfacing rather
     * than letting it silently never match an address.
     */
    public function hasCoverage(): bool
    {
        return $this->includedMembers()->exists();
    }
}
