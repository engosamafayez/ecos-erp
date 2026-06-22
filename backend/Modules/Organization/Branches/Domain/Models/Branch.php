<?php

declare(strict_types=1);

namespace Modules\Organization\Branches\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Organization\Branches\Infrastructure\Database\Factories\BranchFactory;
use Modules\Organization\Companies\Domain\Models\Company;

/**
 * Branch entity (UUID primary key, soft-deletable) belonging to a company.
 *
 * @property string $id
 * @property string $company_id
 * @property string $code
 * @property string $name
 * @property bool $is_head_office
 * @property bool $is_active
 */
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
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
        'phone',
        'email',
        'manager_name',
        'address',
        'city',
        'country',
        'is_head_office',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_head_office' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    protected static function newFactory(): BranchFactory
    {
        return BranchFactory::new();
    }
}
