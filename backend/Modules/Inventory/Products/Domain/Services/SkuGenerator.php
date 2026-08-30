<?php

declare(strict_types=1);

namespace Modules\Inventory\Products\Domain\Services;

use Modules\Inventory\Products\Domain\Models\Product;
use Modules\Organization\Companies\Domain\Models\Company;

/**
 * Company-scoped SKU generation (Decision 1).
 *
 *   {COMPANY_CODE}-{TYPE_PREFIX}-{NNNNNN}   e.g. ASEEL-FG-000001, AXIE-FOOD-FG-000001
 *
 * - The sequence is scoped to the owning company (a per-company counter that
 *   resets across tenants), so Company A's product count never leaks into
 *   Company B's numbering.
 * - The company code is embedded in the SKU, so company-scoped sequences are
 *   nonetheless GLOBALLY unique — the existing `products_sku_unique(sku)` index
 *   remains the hard, non-advisory guarantee and is never weakened.
 * - Deterministic: the prefix comes from `companies.code` and the product type,
 *   never from the product name and never a UUID.
 * - Concurrency: the max-scan takes `lockForUpdate`, serialising concurrent
 *   generation for the same company+prefix; the global unique index is the final
 *   backstop if two ever collide (fail-loud, not a silent duplicate).
 * - Trashed-aware: soft-deleted SKUs are counted so a number is never reissued.
 */
final class SkuGenerator
{
    /** @var array<string, string> */
    private const TYPE_PREFIXES = [
        Product::TYPE_FINISHED_GOOD => 'FG',
        Product::TYPE_RAW_MATERIAL => 'RM',
        Product::TYPE_PACKAGING_MATERIAL => 'PM',
    ];

    public function generate(string $companyId, string $productType): string
    {
        $base = $this->basePrefix($companyId, $productType);

        // System operation: bypass the tenant read scope and filter company_id
        // explicitly, so the true company maximum is always seen. lockForUpdate +
        // the range predicate serialise concurrent generators for this prefix.
        $last = Product::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('company_id', $companyId)
            ->where('sku', 'like', $base.'-%')
            ->lockForUpdate()
            ->orderByRaw('CAST(SUBSTRING_INDEX(sku, "-", -1) AS UNSIGNED) DESC')
            ->value('sku');

        $next = 1;
        if ($last !== null) {
            $tail = substr((string) strrchr((string) $last, '-'), 1);
            $next = ((int) $tail) + 1;
        }

        return sprintf('%s-%06d', $base, $next);
    }

    /** The company + type portion of the SKU, e.g. "ASEEL-FG". */
    public function basePrefix(string $companyId, string $productType): string
    {
        $code = Company::query()->withoutGlobalScopes()->whereKey($companyId)->value('code');
        $companyCode = $this->sanitizeCompanyCode((string) ($code ?? ''));

        if ($companyCode === '') {
            // Deterministic fallback when a company has no code — derived from the
            // company id, never random and never from the product name.
            $companyCode = 'C'.strtoupper(substr((string) preg_replace('/[^A-Za-z0-9]/', '', $companyId), 0, 8));
        }

        $typePrefix = self::TYPE_PREFIXES[$productType] ?? 'GEN';

        return $companyCode.'-'.$typePrefix;
    }

    private function sanitizeCompanyCode(string $code): string
    {
        // Keep the code recognisable (ASEEL, AXIE-FOOD) but SKU-safe.
        $code = strtoupper(trim($code));

        return trim((string) preg_replace('/[^A-Z0-9-]+/', '', $code), '-');
    }
}
