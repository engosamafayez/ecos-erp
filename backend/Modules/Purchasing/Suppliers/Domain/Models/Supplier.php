<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Domain\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
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
        'city',
        'address',
        'notes',
        'is_active',
    ];

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', static function (Builder $query): void {
            if (! Auth::check()) {
                return;
            }
            $companyId = Auth::user()?->company_id;
            if ($companyId === null) {
                return; // super-admin sees all suppliers
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

    protected static function newFactory(): SupplierFactory
    {
        return SupplierFactory::new();
    }
}
