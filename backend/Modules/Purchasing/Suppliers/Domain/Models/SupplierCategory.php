<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Domain\Models;

use App\Core\Company\TenantOwnershipResolver;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A small, company-scoped, flat Supplier classification (e.g. "Raw Material
 * Vendor", "Service Provider"). Mirrors `TaxCategory`'s shape.
 *
 * @property string $id
 * @property string $code
 * @property string $name
 */
class SupplierCategory extends Model
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'company_id',
        'code',
        'name',
        'name_ar',
        'is_active',
    ];

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
        ];
    }

    public function suppliers(): HasMany
    {
        return $this->hasMany(Supplier::class, 'supplier_category_id');
    }
}
