<?php

declare(strict_types=1);

namespace Modules\Purchasing\Suppliers\Domain\Services;

use Illuminate\Support\Facades\DB;
use Modules\Purchasing\Suppliers\Domain\Models\SupplierCategory;

/**
 * TASK-ECOS-PROCUREMENT-SUPPLIERS-FINAL-USER-REVIEW-REMEDIATION-004 §3 — Supplier Category
 * codes are generated here, server-side, so the UI never asks a user to type one.
 *
 * `SupplierCategory` is a hard-delete model (no `SoftDeletes`), so the simple locked-count
 * sequence the established ECOS convention otherwise uses (e.g. {@see
 * \Modules\Commerce\Channels\Domain\Services\SalesChannelCodeGeneratorService}, which relies on
 * `withTrashed()`) is not collision-safe here: deleting a middle category and re-counting would
 * reissue a code still held by a later, undeleted one. Instead this takes the highest existing
 * `SC-####` sequence number for the tenant and advances past it — safe against gaps left by any
 * past hard delete, and against the `(company_id, code)` unique index this column already
 * carries. Locked within a transaction so two concurrent creates for the same company still
 * serialise on the same next number rather than racing to the same one.
 */
final class SupplierCategoryCodeGeneratorService
{
    private const PREFIX = 'SC-';

    public function next(string $companyId): string
    {
        return DB::transaction(function () use ($companyId): string {
            $max = SupplierCategory::query()
                ->where('company_id', $companyId)
                ->where('code', 'like', self::PREFIX.'%')
                ->lockForUpdate()
                ->get(['code'])
                ->map(fn (SupplierCategory $c): int => (int) substr($c->code, strlen(self::PREFIX)))
                ->max() ?? 0;

            return sprintf('%s%04d', self::PREFIX, $max + 1);
        });
    }
}
