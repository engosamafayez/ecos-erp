<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Domain\Services;

use Illuminate\Support\Facades\DB;
use Modules\Purchasing\Suppliers\Domain\Models\Supplier;

/**
 * Generates the next canonical Supplier Code, company-scoped. Mirrors
 * `WarehouseCodeGeneratorService` exactly: a locked, transactional count — not
 * a bare `MAX(code) + 1`, which is unsafe under concurrent creation.
 */
final class SupplierCodeGeneratorService
{
    public function next(string $companyId): string
    {
        return DB::transaction(function () use ($companyId): string {
            $count = Supplier::withTrashed()
                ->where('company_id', $companyId)
                ->lockForUpdate()
                ->count();

            return sprintf('SUP-%06d', $count + 1);
        });
    }
}
