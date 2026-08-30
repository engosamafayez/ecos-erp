<?php

declare(strict_types=1);

namespace Modules\Logistics\Drivers\Domain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Modules\Logistics\ShippingCompanies\Domain\Models\ShippingCompany;

/**
 * Delivery driver — master data owned by Logistics OS.
 *
 * Business rules:
 *  - driver_code, mobile and national_id are unique   (BR-1, BR-2, BR-3)
 *  - drivers are never deleted, only archived         (BR-4, BR-5)
 *  - at most one active vehicle assignment            (BR-6)
 *  - a driver with an expired licence may not start deliveries (BR-8)
 */
class Driver extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_ARCHIVED = 'archived';

    public const STATUSES = [self::STATUS_ACTIVE, self::STATUS_INACTIVE, self::STATUS_ARCHIVED];

    /** Licence states derived from license_expiry_date. */
    public const LICENSE_MISSING = 'missing';

    public const LICENSE_VALID = 'valid';

    public const LICENSE_EXPIRING = 'expiring_soon';

    public const LICENSE_EXPIRED = 'expired';

    /** A licence within this many days of expiry raises a warning in the UI. */
    public const LICENSE_WARNING_DAYS = 30;

    protected $table = 'logistics_drivers';

    /**
     * Domain default declared here as well as in the DB so the value is present
     * on the freshly created instance the API serialises back to the client.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_ACTIVE,
    ];

    protected $fillable = [
        'driver_code',
        // company_id is deliberately ABSENT from $fillable: ownership is stamped
        // from the acting operator in booted(), never accepted from a client.
        // Letting it be mass-assigned would hand the caller the ability to file a
        // driver under another tenant — the exact hole D2 closes.
        'uuid',
        'user_id',
        'full_name',
        'mobile',
        'national_id',
        'date_of_birth',
        'address',
        'employment_date',
        'shipping_company_id',
        'license_number',
        'license_type',
        'license_issue_date',
        'license_expiry_date',
        'license_issuing_authority',
        'status',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date:Y-m-d',
            'employment_date' => 'date:Y-m-d',
            'license_issue_date' => 'date:Y-m-d',
            'license_expiry_date' => 'date:Y-m-d',
        ];
    }

    /**
     * VP-1 / D2 — tenant isolation and cross-module identity.
     *
     * Deliberately identical in shape to Vehicle::booted() (LOG-003), because the
     * two halves of a driver↔vehicle pairing must agree about what "my company"
     * means. Any divergence here would let one half of a pairing be visible while
     * the other is not.
     */
    protected static function booted(): void
    {
        // TENANT ISOLATION. An authenticated operator sees only their own
        // company's drivers, plus the shared/unowned pool (company_id IS NULL).
        // It is a NO-OP when unauthenticated (console/seeders/queue) and for a
        // global user with no company (super-admin), so those flows are
        // unaffected. Uniqueness checks (driver_code, mobile, national_id) run on
        // the raw builder via Rule::unique and are intentionally NOT scoped, so
        // those identifiers stay globally unique as BR-1..BR-3 require.
        static::addGlobalScope('tenant', function (Builder $query): void {
            $user = Auth::user();
            if ($user === null) {
                return;
            }

            $companyId = $user->company_id ?? null;
            if ($companyId === null) {
                return;
            }

            $query->where(function (Builder $q) use ($companyId): void {
                $q->whereNull($q->getModel()->getTable().'.company_id')
                    ->orWhere($q->getModel()->getTable().'.company_id', $companyId);
            });
        });

        static::creating(function (self $driver): void {
            // Every row carries a stable external identifier from birth, so the
            // char(36) driver_id contract in Operations/Loading is satisfiable
            // without a second identity source.
            if ($driver->uuid === null) {
                $driver->uuid = (string) Str::uuid();
            }

            // Ownership is stamped from the creating operator so a driver is
            // never born unscoped. An explicit company_id set on the model (a
            // deliberate shared-pool row created by a super-admin) is kept.
            if ($driver->company_id === null) {
                $driver->company_id = Auth::user()?->company_id;
            }
        });
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    /**
     * The auth user this driver logs in as, if any (G10). The driver runtime
     * resolves the current driver through the inverse of this relation
     * (User::driver) and scopes every trip read/write to it.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'user_id');
    }

    public function shippingCompany(): BelongsTo
    {
        return $this->belongsTo(ShippingCompany::class, 'shipping_company_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(DriverDocument::class, 'driver_id')->latest('created_at');
    }

    /** Full assignment history, newest first. */
    public function assignments(): HasMany
    {
        return $this->hasMany(DriverVehicleAssignment::class, 'driver_id')
            ->orderByDesc('assigned_at');
    }

    /** The single live assignment, if any (BR-6). */
    public function activeAssignment(): HasOne
    {
        return $this->hasOne(DriverVehicleAssignment::class, 'driver_id')
            ->whereNotNull('active_flag');
    }

    // ── Domain logic ──────────────────────────────────────────────────────────

    public function isArchived(): bool
    {
        return $this->status === self::STATUS_ARCHIVED;
    }

    /**
     * Licence state used for the expiry warning and the dashboard counter.
     */
    public function licenseStatus(): string
    {
        if ($this->license_expiry_date === null) {
            return self::LICENSE_MISSING;
        }

        $days = $this->licenseDaysRemaining();

        if ($days === null) {
            return self::LICENSE_MISSING;
        }

        if ($days < 0) {
            return self::LICENSE_EXPIRED;
        }

        return $days <= self::LICENSE_WARNING_DAYS
            ? self::LICENSE_EXPIRING
            : self::LICENSE_VALID;
    }

    /** Whole days until the licence expires; negative once expired. */
    public function licenseDaysRemaining(): ?int
    {
        if ($this->license_expiry_date === null) {
            return null;
        }

        return (int) Carbon::today()->diffInDays($this->license_expiry_date, false);
    }

    public function hasExpiredLicense(): bool
    {
        return $this->licenseStatus() === self::LICENSE_EXPIRED;
    }

    /**
     * BR-8 — gate for the future Distribution module.
     *
     * A driver may only start deliveries when active, holding a valid licence
     * and sitting behind an assigned vehicle. Trips are out of scope for
     * TASK-LOG-002, so this predicate is the integration point that
     * Distribution will call rather than re-deriving the rule.
     */
    public function canStartDeliveries(): bool
    {
        return $this->status === self::STATUS_ACTIVE
            && ! $this->hasExpiredLicense()
            && $this->licenseStatus() !== self::LICENSE_MISSING
            && $this->activeAssignment()->exists();
    }

    /**
     * BR-4 — a driver who has taken part in deliveries must never be deleted.
     *
     * Deliveries live in the Distribution context, which is not active yet, so
     * this reports false today. The module exposes no destroy endpoint at all,
     * so BR-4 and BR-5 hold regardless; this method is the seam Distribution
     * will implement against.
     */
    public function hasDeliveries(): bool
    {
        return false;
    }
}
