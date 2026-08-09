<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Domain\Models;

use App\Core\Company\TenantOwnershipResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Purchasing\Suppliers\Infrastructure\Database\Factories\SupplierFactory;

/**
 * Supplier entity (UUID primary key, soft-deletable).
 *
 * @property string $id
 * @property string $code
 * @property string $name
 * @property bool $is_active
 */
class Supplier extends Model
{
    /** @use HasFactory<SupplierFactory> */
    use HasFactory, HasUuids, SoftDeletes;

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'company_id',
        'code',
        'name',
        'contact_person',
        'email',
        'phone',
        'mobile',
        'country',
        'state',
        'city',
        'district',
        'address',
        'google_maps_url',
        'opening_balance_amount',
        'opening_balance_type',
        'notes',
        'is_active',
    ];

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

            // D-8: a null company must close the query, not remove the filter.
            if ($companyId === null) {
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where('company_id', $companyId);
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'opening_balance_amount' => 'decimal:2',
        ];
    }

    protected static function newFactory(): SupplierFactory
    {
        return SupplierFactory::new();
    }
}
